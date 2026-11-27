<?php
use ACh\WPAccounts\AccountFlow;
use ACh\WPAccounts\AccountPolicy;
use ACh\WPAccounts\Accounts;
use ACh\WPAccounts\Identity;
use ACh\WPAccounts\Schema;
use ACh\WPAccounts\SessionProof;
use ACh\WPAccounts\SmsSender;
use ACh\WPAccounts\SendResult;
use ACh\WPAccounts\Verification;
use ACh\WPAccounts\VerificationError;

$root = getenv('ACH_TEST_WP');
$dir = getenv('ACH_TEST_DIR');
if (!$root || !$dir) {
    throw new RuntimeException('Run through isolated fixture.');
}
$_SERVER['REQUEST_METHOD'] = 'POST';
require $root . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/user.php';
if (wp_get_environment_type() !== 'local' || DB_NAME !== 'accounts_fixture' || get_option('siteurl') !== 'https://accounts.test' || !defined('WC_VERSION')) {
    throw new RuntimeException('Not the isolated WooCommerce fixture.');
}
add_filter('pre_wp_mail', '__return_true');
add_filter('pre_http_request', static fn () => new WP_Error('fixture_no_network'));

function ensureAccount(bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
function expectAccountError(callable $call, string ...$expected): void {
    try {
        $call();
    } catch (VerificationError $error) {
        ensureAccount(in_array($error->getMessage(), $expected, true), 'Unexpected rejection: ' . $error->getMessage());
        return;
    }
    throw new RuntimeException('Expected rejection: ' . implode('/', $expected));
}
final class AccountFixtureSender implements SmsSender {
    public string $code = '';
    public int $calls = 0;
    public function id(): string { return 'fixture'; }
    public function send(string $mobile, string $code, string $purpose): SendResult {
        $this->code = $code;
        $this->calls++;
        return new SendResult('accepted', 'fixture', '1');
    }
}
function browserSecret(): string { return bin2hex(random_bytes(32)); }
function cookieCapture(): void {
    add_action('set_logged_in_cookie', static function ($cookie): void {
        $_COOKIE[LOGGED_IN_COOKIE] = $cookie;
    });
}
function guest(): void {
    wp_set_current_user(0);
    unset($_COOKIE[LOGGED_IN_COOKIE]);
}
function nativeSession(int $id): string {
    wp_set_current_user($id);
    wp_set_auth_cookie($id, false, true);
    ensureAccount(SessionProof::currentToken($id) !== '', 'Native session not established');
    return $_COOKIE[LOGGED_IN_COOKIE];
}
function sendFlow(AccountFlow $flow, string $browser, string $ticket): AccountFixtureSender {
    $sender = new AccountFixtureSender();
    $state = $flow->issue($browser, $ticket, '192.0.2.1', $sender);
    ensureAccount($state['state'] === 'accepted' && $sender->calls === 1, 'Fixture send failed');
    return $sender;
}
function clearLimits(): void {
    global $wpdb;
    ensureAccount($wpdb->query('TRUNCATE TABLE ' . Schema::table('limits')) !== false, 'Fixture reset failed');
}
function concurrentRegistration(string $mobile): array {
    global $dir;
    $barrier = $dir . '/account-race-' . bin2hex(random_bytes(8));
    mkdir($barrier, 0700);
    $children = [];
    try {
        for ($i=0; $i<6; $i++) {
            $proc = proc_open([PHP_BINARY, __FILE__, 'race', $barrier, (string)$i, $mobile], [
                0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w'],
            ], $pipes);
            ensureAccount(is_resource($proc), 'Could not spawn account worker');
            fclose($pipes[0]);
            $children[] = [$proc,$pipes];
        }
        $deadline = microtime(true)+20;
        while (count(glob($barrier.'/ready-*'))!==6) {
            ensureAccount(microtime(true)<$deadline, 'Account race did not reach barrier');
            usleep(20000);
        }
        touch($barrier.'/go');
        $results = [];
        foreach ($children as $i=>[$proc,$pipes]) {
            stream_set_timeout($pipes[1],25);
            $out=stream_get_contents($pipes[1]); $err=stream_get_contents($pipes[2]);
            fclose($pipes[1]); fclose($pipes[2]);
            $status=proc_close($proc);
            unset($children[$i]);
            ensureAccount($status===0, 'Account worker failed: '.$err.' '.$out);
            $results[]=json_decode($out,true,16,JSON_THROW_ON_ERROR);
        }
        return $results;
    } finally {
        foreach ($children as [$proc,$pipes]) {
            proc_terminate($proc); fclose($pipes[1]); fclose($pipes[2]); proc_close($proc);
        }
    }
}
if (($argv[1] ?? '') === 'race') {
    touch($argv[2].'/ready-'.$argv[3]);
    $deadline=microtime(true)+20;
    while (!is_file($argv[2].'/go')) {
        ensureAccount(microtime(true)<$deadline,'Race start timeout');
        usleep(10000);
    }
    try {
        $row=Accounts::register($argv[4],'account');
        echo json_encode(['id'=>(int)$row->user_id]);
    } catch (VerificationError $error) {
        echo json_encode(['error'=>$error->getMessage()]);
    }
    exit;
}

cookieCapture();
guest();
clearLimits();
$flow=new AccountFlow(new Verification());
$browser=browserSecret();
$other=browserSecret();
$mobile='+989121111111';
$ticket=$flow->start($browser,$mobile,'login','account');
expectAccountError(fn()=> $flow->issue($other,$ticket,'192.0.2.1',new AccountFixtureSender()),'invalid_context');
expectAccountError(fn()=> $flow->issue($browser,$ticket.'x','192.0.2.1',new AccountFixtureSender()),'invalid_context');
$sender=sendFlow($flow,$browser,$ticket);
expectAccountError(fn()=> $flow->complete($browser,$ticket,$sender->code),'registration_disabled');
ensureAccount(Identity::byMobile($mobile)===null,'Disabled registration created identity');

update_option('ach_wp_accounts_allow_registration',true);
update_option('woocommerce_enable_myaccount_registration','yes');
clearLimits();
$ticket=$flow->start($browser,$mobile,'login','account');
$ticket2=$flow->start($browser,$mobile,'login','account');
$decode=static fn($ticket)=>json_decode(base64_decode(strtr(explode('.',$ticket)[0],'-_','+/')),true,16,JSON_THROW_ON_ERROR);
ensureAccount($decode($ticket)['target']!==$decode($ticket2)['target'],'Tickets expose comparable identity existence');
$sender=sendFlow($flow,$browser,$ticket);
$replay=$flow->issue($browser,$ticket,'192.0.2.1',$sender);
ensureAccount($replay['state']==='accepted' && $sender->calls===1,'Send replay repeated effect');
$events=0;
$listener=static function() use (&$events): void { $events++; };
add_action('woocommerce_created_customer',$listener);
$result=$flow->complete($browser,$ticket,$sender->code);
remove_action('woocommerce_created_customer',$listener);
$userId=get_current_user_id();
$user=get_userdata($userId);
ensureAccount($result['created']===true && $userId>0 && $events===1,'Native WC registration lifecycle missing');
ensureAccount($user && $user->user_email==='' && in_array('customer',$user->roles,true),'Wrong native customer data');
ensureAccount((int)Identity::byMobile($mobile)->user_id===$userId && SessionProof::qualifies(),'New account not session-qualified');
ensureAccount(wp_is_password_reset_allowed_for_user($userId)===false,'Blank email recovery allowed');
$firstCookie=$_COOKIE[LOGGED_IN_COOKIE];
expectAccountError(fn()=> $flow->complete($browser,$ticket,$sender->code),'invalid_context','identity_changed');
guest();
expectAccountError(fn()=> $flow->issue($browser,$ticket2,'192.0.2.1',new AccountFixtureSender()),'identity_changed');

clearLimits();
$ticket=$flow->start($browser,$mobile,'login','account');
$sender=sendFlow($flow,$browser,$ticket);
$deny=static fn()=>new WP_Error('fixture_denied');
add_filter('wp_authenticate_user',$deny,100);
expectAccountError(fn()=> $flow->complete($browser,$ticket,$sender->code),'native_login_required');
remove_filter('wp_authenticate_user',$deny,100);
ensureAccount(get_current_user_id()===0,'Authentication denial established a session');

clearLimits();
$ticket=$flow->start($browser,$mobile,'login','account');
$sender=sendFlow($flow,$browser,$ticket);
$deny=static fn()=>new WP_Error('fixture_denied');
add_filter('authenticate',$deny,100);
expectAccountError(fn()=> $flow->complete($browser,$ticket,$sender->code),'native_login_required');
remove_filter('authenticate',$deny,100);
ensureAccount(get_current_user_id()===0,'Authenticate filter denial bypassed');

clearLimits();
$ticket=$flow->start($browser,$mobile,'login','account');
$sender=sendFlow($flow,$browser,$ticket);
$stopCookies=static fn()=>false;
add_filter('send_auth_cookies',$stopCookies);
expectAccountError(fn()=> $flow->complete($browser,$ticket,$sender->code),'native_login_required');
remove_filter('send_auth_cookies',$stopCookies);
ensureAccount(get_current_user_id()===0,'Cookie delivery refusal bypassed');

clearLimits();
$ticket=$flow->start($browser,$mobile,'login','account');
$sender=sendFlow($flow,$browser,$ticket);
$result=$flow->complete($browser,$ticket,$sender->code);
ensureAccount(!$result['created'] && get_current_user_id()===$userId && SessionProof::qualifies(),'Existing account ID not preserved');
$verifiedCookie=$_COOKIE[LOGGED_IN_COOKIE];

$admin=get_user_by('login','fixture_admin');
ensureAccount(!AccountPolicy::ordinary($admin),'Privileged account eligible for SMS-only login');
$adminIdentity=Identity::bind($admin->ID,'+989121111112','absent');
guest(); clearLimits();
$ticket=$flow->start($browser,$adminIdentity->mobile,'login','account');
$sender=sendFlow($flow,$browser,$ticket);
expectAccountError(fn()=> $flow->complete($browser,$ticket,$sender->code),'native_login_required');
ensureAccount(get_current_user_id()===0,'Admin OTP login succeeded');

$user=get_userdata($userId);
$user->add_cap('edit_posts');
ensureAccount(!AccountPolicy::ordinary(get_userdata($userId)),'Elevated customer capability ignored');
$user->remove_cap('edit_posts');

$nativeCookie=nativeSession($userId);
ensureAccount(!SessionProof::qualifies(),'Ordinary native session acquired mobile proof');
clearLimits();
$ticket=$flow->start($browser,$mobile,'step_up','checkout');
$sender=sendFlow($flow,$browser,$ticket);
$_COOKIE[LOGGED_IN_COOKIE]=$verifiedCookie;
expectAccountError(fn()=> $flow->complete($browser,$ticket,$sender->code),'invalid_context');
$_COOKIE[LOGGED_IN_COOKIE]=$nativeCookie;
$result=$flow->complete($browser,$ticket,$sender->code);
ensureAccount($result['outcome']==='verified' && SessionProof::qualifies() && get_current_user_id()===$userId,'Password session step-up failed');
ensureAccount($_COOKIE[LOGGED_IN_COOKIE]===$nativeCookie,'Step-up replaced native session');
$proofTable=Schema::table('session_proofs');
$wpdb->query($wpdb->prepare("UPDATE $proofTable SET verified_at=%d WHERE user_id=%d",time()-SessionProof::LINK_FRESHNESS-1,$userId));
ensureAccount(SessionProof::qualifies() && !SessionProof::qualifies(SessionProof::LINK_FRESHNESS),'Checkout proof freshness incorrectly applied to identity change');
expectAccountError(fn()=> $flow->start($browser,'+989121111113','link','account'),'reauthentication_required');
clearLimits();
$ticket=$flow->start($browser,$mobile,'step_up','account');
$sender=sendFlow($flow,$browser,$ticket);
$flow->complete($browser,$ticket,$sender->code);
ensureAccount(SessionProof::qualifies(SessionProof::LINK_FRESHNESS),'Fresh step-up proof unavailable');

clearLimits();
$newMobile='+989121111113';
$ticket=$flow->start($browser,$newMobile,'link','account');
$sender=sendFlow($flow,$browser,$ticket);
$flow->complete($browser,$ticket,$sender->code);
ensureAccount(Identity::byMobile($mobile)===null && (int)Identity::byMobile($newMobile)->user_id===$userId && SessionProof::qualifies(),'Identity change failed');
$_COOKIE[LOGGED_IN_COOKIE]=$verifiedCookie;
ensureAccount(!SessionProof::qualifies(),'Old identity proof remained valid');
$_COOKIE[LOGGED_IN_COOKIE]=$nativeCookie;
ensureAccount(SessionProof::qualifies(),'New identity proof not preserved');
clearLimits();
$ticket=$flow->start($browser,$adminIdentity->mobile,'link','account');
$sender=sendFlow($flow,$browser,$ticket);
expectAccountError(fn()=> $flow->complete($browser,$ticket,$sender->code),'identity_conflict');
ensureAccount(Identity::byUser($userId)->mobile===$newMobile,'Conflict changed identity');

$plainPassword=wp_generate_password(32);
$legacyId=wp_insert_user(['user_login'=>'fixture_legacy_link','user_pass'=>$plainPassword,'user_email'=>'legacy@example.invalid','role'=>'customer']);
ensureAccount(is_int($legacyId),'Legacy fixture creation failed');
nativeSession($legacyId);
clearLimits();
expectAccountError(fn()=> $flow->start($browser,'+989121111114','link','account','wrong'),'reauthentication_required');
$ticket=$flow->start($browser,'+989121111114','link','account',$plainPassword);
$sender=sendFlow($flow,$browser,$ticket);
$flow->complete($browser,$ticket,$sender->code);
ensureAccount((int)Identity::byMobile('+989121111114')->user_id===$legacyId && SessionProof::qualifies(),'Fresh native credential link failed');
ensureAccount(wp_is_password_reset_allowed_for_user($legacyId)===true,'Existing account recovery changed');

$changed=wp_update_user(['ID'=>$userId,'user_email'=>'unverified@example.invalid']);
ensureAccount(!is_wp_error($changed) && wp_is_password_reset_allowed_for_user($userId)===false,'Unverified email gained recovery');
$service=wc_get_container()->get('Automattic\WooCommerce\Internal\CustomerEmailVerification\EmailVerificationService');
$service->mark_verified($userId,'unverified@example.invalid');
ensureAccount(wp_is_password_reset_allowed_for_user($userId)===true,'Native verified email recovery not recognized');
wp_update_user(['ID'=>$userId,'user_email'=>'changed@example.invalid']);
ensureAccount(wp_is_password_reset_allowed_for_user($userId)===false,'Changed email retained recovery proof');

guest();
$raceMobile='+989121111115';
$before=(int)$wpdb->get_var("SELECT COUNT(*) FROM $wpdb->users");
$results=concurrentRegistration($raceMobile);
$success=array_values(array_filter($results,static fn($r)=>isset($r['id'])));
ensureAccount(count($success)===1 && (int)$wpdb->get_var("SELECT COUNT(*) FROM $wpdb->users")===$before+1,'Concurrent registration created duplicate native users');
ensureAccount((int)Identity::byMobile($raceMobile)->user_id===$success[0]['id'],'Registration reservation not finalized');
$pendingMobile='+989121111116';
$interrupt=static function(): void { throw new RuntimeException('fixture interruption after native insert'); };
add_action('user_register',$interrupt,PHP_INT_MAX);
expectAccountError(fn()=> Accounts::register($pendingMobile,'account'),'registration_pending');
remove_action('user_register',$interrupt,PHP_INT_MAX);
$pending=Identity::byMobile($pendingMobile);
ensureAccount($pending && $pending->user_id===null && $pending->registration_login!=='','Uncertain native creation lost reservation');
$before=(int)$wpdb->get_var("SELECT COUNT(*) FROM $wpdb->users");
expectAccountError(fn()=> Accounts::register($pendingMobile,'account'),'identity_conflict');
ensureAccount((int)$wpdb->get_var("SELECT COUNT(*) FROM $wpdb->users")===$before,'Uncertain registration repeated user creation');

nativeSession($legacyId);
$cookie=$_COOKIE[LOGGED_IN_COOKIE];
wp_logout();
$_COOKIE[LOGGED_IN_COOKIE]=$cookie;
wp_set_current_user($legacyId);
ensureAccount(!SessionProof::qualifies(),'Logout did not revoke native session');
guest();
wp_delete_user($legacyId);
ensureAccount(Identity::byUser($legacyId)===null,'Native deletion retained identity');
echo "PASS: browser-bound native customer login/registration, role and auth hooks, session step-up, identity changes, email recovery, concurrent registration and uncertain recovery\n";
