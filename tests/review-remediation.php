<?php
use ACh\WPAccounts\{AccountFlow,AccountPolicy,EmailRecovery,Identity,Migration,Privacy,Schema,SessionProof,Settings,VerificationError,MobileCodeDelivery,SendResult};
$root=getenv('ACH_TEST_WP');
$dir=getenv('ACH_TEST_DIR');
if (!$root || !$dir) { throw new RuntimeException('Run isolated fixture.'); }
$_SERVER['REQUEST_METHOD']='POST';
require $root.'/wp-load.php';
require_once ABSPATH.'wp-admin/includes/user.php';
if (wp_get_environment_type()!=='local' || DB_NAME!=='accounts_fixture' ||
    get_option('siteurl')!=='https://accounts.test' || !function_exists('WC')) {
    throw new RuntimeException('Not isolated.');
}
function reviewCheck(bool $ok,string $message):void {
    if (!$ok) { throw new RuntimeException($message); }
}
function reviewDenied(callable $call,string $code):void {
    try { $call(); } catch (VerificationError $error) {
        reviewCheck($error->getMessage()===$code,'Unexpected rejection: '.$error->getMessage());
        return;
    }
    throw new RuntimeException('Expected rejection: '.$code);
}
function reviewUser(string $login,string $password,string $email='',string $role='customer'):int {
    $id=wp_insert_user(['user_login'=>$login,'user_pass'=>$password,'user_email'=>$email,'role'=>$role]);
    reviewCheck(is_int($id),'Native fixture user failed');
    return $id;
}
function reviewGuest():void {
    wp_set_current_user(0);
    unset($_COOKIE[LOGGED_IN_COOKIE]);
}
function reviewOtp(string $phone,string $purpose,?string $error=null):array {
    global $wpdb;
    $wpdb->query("TRUNCATE TABLE ".Schema::table('limits')); // disposable fixture
    $flow=new AccountFlow();
    $browser=bin2hex(random_bytes(32));
    $ticket=$flow->start($browser,$phone,$purpose,'account');
    $sender=new class implements MobileCodeDelivery {
        public string $code='';
        public function id():string{return 'fixture';}
        public function send(string $mobile,string $code,string $purpose):SendResult {
            $this->code=$code;return new SendResult('accepted','fixture');
        }
    };
    $flow->issue($browser,$ticket,'192.0.2.63',$sender);
    if ($error!==null) {
        reviewDenied(fn()=> $flow->complete($browser,$ticket,$sender->code),$error);
        return [];
    }
    return $flow->complete($browser,$ticket,$sender->code);
}
function reviewNative(string $login,string $password,int $id):void {
    reviewGuest();
    $user=wp_signon(['user_login'=>$login,'user_password'=>$password],true);
    reviewCheck($user instanceof WP_User && $user->ID===$id,'Native password login failed');
    wp_set_current_user($id);
    reviewCheck(SessionProof::currentToken($id)!=='','Native session missing');
}
add_filter('pre_http_request',static fn()=>new WP_Error('fixture_no_network'),PHP_INT_MAX);
add_filter('pre_wp_mail','__return_true');
add_action('set_logged_in_cookie',static function($cookie):void {$_COOKIE[LOGGED_IN_COOKIE]=$cookie;});
$admin=get_user_by('login','fixture_admin');
$password=wp_generate_password(32);
$id=reviewUser('fixture_review_dynamic',$password,'review-dynamic@example.invalid');
$subscriber=reviewUser('fixture_review_subscriber',$password,'','subscriber');
$customRole=add_role('fixture_review_manager','Fixture manager',['read'=>true,'fixture_review_cap'=>true]);
$custom=reviewUser('fixture_review_custom',$password,'','fixture_review_manager');
$badProbe=static function($function):void {
    if ($function==='map_meta_cap') { throw new RuntimeException('Object capability checked without its context'); }
};
add_action('doing_it_wrong_run',$badProbe);
reviewCheck(AccountPolicy::ordinary(get_userdata($id)) && AccountPolicy::ordinary(get_userdata($subscriber)) &&
    !AccountPolicy::ordinary($admin) && !AccountPolicy::ordinary(get_userdata($custom)),
    'Stored ordinary/privileged role policy changed');
remove_action('doing_it_wrong_run',$badProbe);
$subject=get_userdata($id);
$subject->add_cap('edit_posts');
reviewCheck(!AccountPolicy::ordinary(get_userdata($id)),'Stored elevated cap ignored');
$subject->remove_cap('edit_posts');

$grant=static function(array $caps,array $required,array $args,WP_User $user) use ($id):array {
    // Context-specific custom site capability is registered by a role.
    if ($user->ID===$id && ($args[0]??'')==='fixture_review_cap') { $caps['fixture_review_cap']=true; }
    return $caps;
};
$map=static function(array $caps,string $cap,int $userId) use ($id):array {
    return $userId===$id && $cap==='manage_options' ? ['read'] : $caps;
};
$unregistered=static function(array $caps,array $required,array $args,WP_User $user) use ($id):array {
    if ($user->ID===$id) { $caps['fixture_dynamic_global']=true; }
    return $caps;
};
foreach ([['user_has_cap',$grant,4],['map_meta_cap',$map,3],['user_has_cap',$unregistered,4]] as [$hook,$filter,$args]) {
    add_filter($hook,$filter,99,$args);
    reviewCheck(!AccountPolicy::ordinary(get_userdata($id)),'Native effective grant ignored: '.$hook);
    remove_filter($hook,$filter,99);
}
wp_set_current_user($admin->ID);
$manifest=['evidence'=>'Synthetic effective authorization fixture','rows'=>[
    ['user_id'=>$id,'mobile'=>'09126630001','selected'=>true]
]];
$preview=Migration::run($manifest);
reviewCheck($preview['items'][0]['state']==='ready','Ordinary mapping rejected');
add_filter('user_has_cap',$grant,99,4);
reviewCheck(Migration::run($manifest)['items'][0]['state']==='ineligible_account' &&
    Migration::run($manifest+['confirm'=>true,'digest'=>$preview['digest']],true)['items'][0]['state']==='ineligible_account' &&
    Identity::byUser($id)===null,'Effective elevated account migrated');
remove_filter('user_has_cap',$grant,99);
reviewCheck(Migration::run($manifest+['confirm'=>true,'digest'=>$preview['digest']],true)['items'][0]['state']==='imported','Ordinary migration failed');
$binding=Identity::byUser($id);
reviewGuest();
add_filter('user_has_cap',$grant,99,4);
reviewOtp($binding->mobile,'login','native_login_required');
reviewCheck(get_current_user_id()===0 && (int)Identity::byUser($id)->verified_at===0,'Denied SMS-only login established authority');
reviewNative('fixture_review_dynamic',$password,$id);
$native=SessionProof::currentToken($id);
reviewCheck(!SessionProof::qualifies() && reviewOtp($binding->mobile,'step_up')['outcome']==='verified' &&
    SessionProof::qualifies() && SessionProof::currentToken($id)===$native,'Native elevated password/step-up path changed');
remove_filter('user_has_cap',$grant,99);
reviewGuest();
// The native current-user context can change effective grants after cookie creation.
$actorGrant=static function(array $caps,array $required,array $args,WP_User $user) use ($id):array {
    if ($user->ID===$id && get_current_user_id()===$id) { $caps['manage_options']=true; }
    return $caps;
};
add_filter('user_has_cap',$actorGrant,99,4);
reviewOtp($binding->mobile,'login','native_login_required');
reviewCheck(get_current_user_id()===0 && !wp_validate_auth_cookie('', 'logged_in'),'Post-authentication privilege left valid native cookie');
remove_filter('user_has_cap',$actorGrant,99);
reviewGuest();
$hookGrant=static function($checked) use ($grant) {
    add_filter('user_has_cap',$grant,99,4);
    return $checked;
};
add_filter('authenticate',$hookGrant,99);
reviewOtp($binding->mobile,'login','native_login_required');
reviewCheck(get_current_user_id()===0,'Authentication hook elevation was not denied');
remove_filter('authenticate',$hookGrant,99);
remove_filter('user_has_cap',$grant,99);
reviewGuest();
reviewCheck(reviewOtp($binding->mobile,'login')['outcome']==='authenticated' && get_current_user_id()===$id &&
    SessionProof::qualifies() && !EmailRecovery::managed($id),'Ordinary email-bearing legacy login/recovery changed');
reviewGuest();
remove_role('fixture_review_manager');
echo "PASS: native effective capabilities, migration reclassification, guest and authenticated-context denial, ordinary login and independent native step-up\n";

// Imported phone-only recovery uses a separate policy from registration.
$phone=reviewUser('fixture_review_phone',$password);
$oldUser=get_userdata($phone);
$other=reviewUser('fixture_review_unrelated',$password);
wp_set_current_user($admin->ID);
$phoneManifest=['evidence'=>'Synthetic legacy phone-only recovery','rows'=>[
    ['user_id'=>$phone,'mobile'=>'09126630002','selected'=>true]
]];
$preview=Migration::run($phoneManifest);
$denyMeta=static function($check,int $userId,string $key) use ($phone) {
    return $userId===$phone && $key===EmailRecovery::POLICY ? false : $check;
};
add_filter('update_user_metadata',$denyMeta,99,3);
add_filter('add_user_metadata',$denyMeta,99,3);
$applied=Migration::run($phoneManifest+['confirm'=>true,'digest'=>$preview['digest']],true);
reviewCheck($applied['items'][0]['state']==='write_unconfirmed' && EmailRecovery::managed($phone) &&
    !EmailRecovery::registeredHere($phone) && get_user_meta($phone,EmailRecovery::POLICY,true)==='',
    'Interrupted import did not retain recovery protection');
reviewCheck(is_wp_error(wp_update_user(['ID'=>$phone,'user_email'=>'unconfirmed@example.invalid'])) &&
    get_userdata($phone)->user_email==='','Partial import established unconfirmed email');
remove_filter('update_user_metadata',$denyMeta,99);
remove_filter('add_user_metadata',$denyMeta,99);
$before=Identity::byUser($phone);
$applied=Migration::run($phoneManifest+['confirm'=>true,'digest'=>$preview['digest']],true);
reviewCheck($applied['items'][0]['state']==='already_bound' && get_user_meta($phone,EmailRecovery::POLICY,true)==='1' &&
    $before->revision===Identity::byUser($phone)->revision,'Exact replay failed to reconcile recovery policy');
reviewCheck((int)$before->verified_at===0 && !EmailRecovery::registeredHere($phone) &&
    !EmailRecovery::managed($other) && !str_contains(wp_json_encode(Privacy::exportUser($phone)),'Mobile-created account'),
    'Import fabricated registration or possession');
reviewCheck(Privacy::eraseUser($phone)['items_retained'] && Identity::byUser($phone)->revision===$before->revision,
    'Privacy removed the only usable identity');
reviewNative('fixture_review_phone',$password,$phone);
reviewDenied(fn()=>EmailRecovery::request('review-confirm@example.invalid'),'reauthentication_required');
reviewDenied(fn()=>EmailRecovery::password($password,$password),'reauthentication_required');
reviewCheck(reviewOtp($before->mobile,'step_up')['outcome']==='verified' &&
    SessionProof::qualifies(SessionProof::LINK_FRESHNESS) && Identity::byUser($phone)->source_ref===$preview['digest'] &&
    get_userdata($phone)->user_pass===$oldUser->user_pass && get_userdata($phone)->roles===$oldUser->roles &&
    !EmailRecovery::registeredHere($phone),'Fresh OTP changed legacy credentials/provenance');
// The policy must survive replacement of the original Digits binding, even
// if only the identity fallback remains after a partial metadata write.
delete_user_meta($phone,EmailRecovery::POLICY);
reviewCheck(EmailRecovery::managed($phone) &&
    reviewOtp('09126630003','link')['outcome']==='linked' &&
    Identity::byUser($phone)->mobile===\ACh\WPAccounts\Mobile::normalize('09126630003') &&
    get_user_meta($phone,EmailRecovery::POLICY,true)==='1' &&
    !EmailRecovery::registeredHere($phone) && SessionProof::qualifies(SessionProof::LINK_FRESHNESS),
    'Changing the imported identity discarded its recovery protection');

wc_load_cart();
function reviewCommerce(string $email,string $first):bool {
    wc_clear_notices();
    $_POST=['action'=>'save_account_details','account_first_name'=>$first,'account_last_name'=>'Fixture',
        'account_display_name'=>'Fixture review','account_email'=>$email];
    $_REQUEST=$_POST+['save-account-details-nonce'=>wp_create_nonce('save_account_details')];
    $redirect=static function($location) { throw new RuntimeException('fixture_wc_saved'); };
    add_filter('wp_redirect',$redirect,PHP_INT_MAX);
    try {
        WC_Form_Handler::save_account_details();
        return false;
    } catch (RuntimeException $error) {
        if ($error->getMessage()!=='fixture_wc_saved') { throw $error; }
        return true;
    } finally {
        remove_filter('wp_redirect',$redirect,PHP_INT_MAX);
        $_POST=$_REQUEST=[];
    }
}
reviewCheck(reviewCommerce('','Allowed') && get_userdata($phone)->first_name==='Allowed' &&
    get_userdata($phone)->user_email==='','Actual WC profile required optional email or did not save details');
reviewCheck(!reviewCommerce('unconfirmed@example.invalid','Denied') && wc_notice_count('error')>0 &&
    get_userdata($phone)->first_name==='Allowed' && get_userdata($phone)->user_email==='',
    'Actual WC profile stored unconfirmed recovery address');
wc_clear_notices();

$token='';
$mail=static function($pre,$args) use (&$token) {
    if (preg_match('/ach_confirm_email=([a-f0-9]{64})/',$args['message'],$matches)) { $token=$matches[1]; }
    return true;
};
add_filter('pre_wp_mail',$mail,99,2);
reviewCheck(EmailRecovery::request('review-confirm@example.invalid')['outcome']==='confirmation_requested' &&
    $token!=='' && get_userdata($phone)->user_email==='' && !wp_is_password_reset_allowed_for_user($phone),
    'Recovery email became authoritative before confirmation');
$native=SessionProof::currentToken($phone);
$session=WP_Session_Tokens::get_instance($phone)->get($native);
$fixture=$dir.'/private-email-confirm.json';
$auth=wp_generate_auth_cookie($phone,$session['expiration'],'secure_auth',$native);
file_put_contents($fixture,wp_json_encode(['token'=>$token,'logged_in'=>$_COOKIE[LOGGED_IN_COOKIE],'secure_auth'=>$auth]),LOCK_EX);
chmod($fixture,0600);
$proc=proc_open([PHP_BINARY,__DIR__.'/recovery-confirm.php',$fixture],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
reviewCheck(is_resource($proc),'Could not launch native confirmation child');
fclose($pipes[0]);$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
$exit=proc_close($proc);unlink($fixture);
clean_user_cache($phone);
wp_cache_delete($phone,'user_meta');
reviewCheck($exit===0 && str_contains($out,'confirmed_profile_redirect') &&
    get_userdata($phone)->user_email==='review-confirm@example.invalid' && wp_is_password_reset_allowed_for_user($phone)===true,
    'Native profile confirmation failed for imported account (exit '.$exit.')');
remove_filter('pre_wp_mail',$mail,99);
reviewDenied(fn()=>EmailRecovery::confirm($token),'invalid_context');
$newPassword=wp_generate_password(32);
reviewCheck(EmailRecovery::password($newPassword,$newPassword)['outcome']==='password_saved' &&
    wp_check_password($newPassword,get_userdata($phone)->user_pass,$phone) &&
    is_string(get_password_reset_key(get_userdata($phone))),'Imported account native password enrollment/reset failed');
reviewNative('fixture_review_phone',$newPassword,$phone);
reviewCheck(Privacy::eraseUser($phone)['items_removed'] && Identity::byUser($phone)===null &&
    EmailRecovery::managed($phone) && wp_is_password_reset_allowed_for_user($phone)===true &&
    !EmailRecovery::registeredHere($phone),'Privacy unlink lost verified native recovery/provenance');
reviewCheck(is_wp_error(wp_update_user(['ID'=>$phone,'user_email'=>'unconfirmed@example.invalid'])) &&
    !is_wp_error(wp_update_user(['ID'=>$other,'user_email'=>'unrelated@example.invalid'])),
    'Managed guard after unlink or unrelated native account behavior changed');
echo "PASS: imported phone-only recovery, partial-write replay, fresh OTP, actual WooCommerce profile, native confirmation and password recovery, privacy retention/unlink\n";
