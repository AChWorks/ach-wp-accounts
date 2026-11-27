<?php
use ACh\WPAccounts\Abilities;
use ACh\WPAccounts\Diagnostics;
use ACh\WPAccounts\Operations;
use ACh\WPAccounts\Schema;
use ACh\WPAccounts\Settings;
use ACh\WPAccounts\SettingsError;
use ACh\WPAccounts\Verification;
use ACh\WPAccounts\VerificationError;

$root = getenv('ACH_TEST_WP');
if (!$root) { throw new RuntimeException('Run through isolated fixture.'); }
require $root . '/wp-load.php';
if (wp_get_environment_type() !== 'local' || DB_NAME !== 'accounts_fixture' || get_option('siteurl') !== 'https://accounts.test' || !defined('WC_VERSION')) {
    throw new RuntimeException('Not isolated.');
}
function opsCheck(bool $condition, string $message): void {
    if (!$condition) { throw new RuntimeException($message); }
}
function opsError(callable $fn, string $code): void {
    try { $fn(); } catch (SettingsError|VerificationError $error) {
        opsCheck($error->getMessage() === $code, 'Unexpected error: ' . $error->getMessage());
        return;
    }
    throw new RuntimeException('Expected ' . $code);
}
function saveSettings(array $patch, bool $credentials = false): array {
    return Settings::update($patch, Settings::read()['revision'], $credentials);
}
// Same option revision, independent PHP processes and the same native DB.
if (($argv[1] ?? '') === 'race') {
    $admin=get_user_by('login','fixture_admin');
    wp_set_current_user($admin->ID);
    touch($argv[2].'/ready-'.$argv[3]);
    $deadline=microtime(true)+20;
    while (!is_file($argv[2].'/go')) {
        opsCheck(microtime(true)<$deadline,'Settings race barrier timed out');
        usleep(10000);
    }
    try {
        Settings::update(['verification'=>['networkHourly'=>61+(int)$argv[3]]],$argv[4]);
        echo json_encode(['ok'=>true]);
    } catch (SettingsError $error) {
        echo json_encode(['ok'=>false,'code'=>$error->getMessage()]);
    }
    exit;
}
function raceSettings(string $revision): void {
    $dir=getenv('ACH_TEST_DIR').'/settings-race-'.bin2hex(random_bytes(6));
    mkdir($dir,0700);
    $workers=[];
    try {
        for ($i=0;$i<4;$i++) {
            $proc=proc_open([PHP_BINARY,__FILE__,'race',$dir,(string)$i,$revision],[
                0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']
            ],$pipes);
            opsCheck(is_resource($proc),'Could not launch settings worker');
            fclose($pipes[0]);
            $workers[]=[$proc,$pipes];
        }
        $deadline=microtime(true)+20;
        while (count(glob($dir.'/ready-*'))!==4) {
            opsCheck(microtime(true)<$deadline,'Settings workers did not reach barrier');
            usleep(10000);
        }
        touch($dir.'/go');
        $results=[];
        foreach ($workers as $i=>[$proc,$pipes]) {
            $out=stream_get_contents($pipes[1]);
            $err=stream_get_contents($pipes[2]);
            fclose($pipes[1]); fclose($pipes[2]);
            $status=proc_close($proc); unset($workers[$i]);
            opsCheck($status===0,'Settings worker failed: '.$err);
            $results[]=json_decode($out,true,16,JSON_THROW_ON_ERROR);
        }
        opsCheck(count(array_filter($results,static fn($r)=>$r['ok']))===1,'CAS accepted multiple writers');
        foreach ($results as $result) {
            opsCheck($result['ok'] || $result['code']==='settings_conflict','Unexpected CAS result');
        }
    } finally {
        foreach ($workers as [$proc,$pipes]) {
            proc_terminate($proc); fclose($pipes[1]); fclose($pipes[2]); proc_close($proc);
        }
    }
}
global $wpdb;
update_option(Settings::OPTION, Settings::defaults(), false);
foreach (['challenges','events','limits'] as $table) {
    $wpdb->query('TRUNCATE TABLE ' . Schema::table($table));
}
wp_set_current_user(0);
opsError(fn()=>Settings::read(), 'forbidden');
opsError(fn()=>Diagnostics::query(), 'forbidden');
opsError(fn()=>Operations::connection(), 'forbidden');
opsError(fn()=>Operations::testSend('09121111111','login',str_repeat('a',32),true), 'forbidden');
$admin = get_user_by('login','fixture_admin');
wp_set_current_user($admin->ID);
$default = Settings::read();
opsCheck($default['auth_enabled'] === false && $default['checkout_required'] === false, 'Unsafe default');
$autoload = $wpdb->get_var($wpdb->prepare("SELECT autoload FROM $wpdb->options WHERE option_name=%s", Settings::OPTION));
opsCheck(in_array($autoload,['no','off','auto-off'],true), 'Credentials option autoloaded');
$calls=0;
$mode='accepted';
$mock = static function ($pre,$args,$url) use (&$calls,&$mode) {
    $calls++;
    parse_str($args['body'],$data);
    if (str_ends_with($url,'/account/info.json')) {
        return ['response'=>['code'=>200],'body'=>json_encode(['return'=>['status'=>200],'entries'=>['remaincredit'=>1000]])];
    }
    if (str_ends_with($url,'/verify/lookup.json')) {
        opsCheck($data['template']==='fixture-template' && preg_match('/^[0-9]{6}$/D',$data['token'])===1,'Wrong test template');
        if ($mode==='uncertain') { return new WP_Error('fixture_timeout','Confidential fixture details'); }
        return ['response'=>['code'=>200],'body'=>json_encode(['return'=>['status'=>200],'entries'=>[
            ['messageid'=>'12345678901234567890','receptor'=>$data['receptor'],'status'=>5,'message'=>'Confidential fixture details']
        ]])];
    }
    throw new RuntimeException('Unexpected fixture endpoint');
};
add_filter('pre_http_request',$mock,10,3);

opsError(fn()=>saveSettings(['auth_enabled'=>true]),'provider_unconfigured');
opsError(fn()=>saveSettings(['checkout_required'=>true]),'authentication_required');
opsError(fn()=>saveSettings(['verification'=>['codeLifetime'=>601]]),'invalid_settings');
opsError(fn()=>saveSettings(['verification'=>['mobileHourly'=>0]]),'invalid_settings');
opsError(fn()=>saveSettings(['providers'=>['kavenegar'=>['credentials'=>['api_key'=>'fixture-secret']]]]),'credential_write_forbidden');
opsError(fn()=>saveSettings(['providers'=>['kavenegar'=>['credentials'=>['api_key'=>'fixture-secret'],
    'default'=>['mode'=>'text','sender'=>'10001','message'=>'No placeholder']]]],true),'provider_invalid');
opsCheck($calls===0 && Settings::read()['revision']===$default['revision'], 'Validation changed stored state or sent a request');

$saved=saveSettings([
    'providers'=>[
        'kavenegar'=>[
            'credentials'=>['api_key'=>'fixture-secret'],
            'default'=>['mode'=>'pattern','template'=>'fixture-template','tokens'=>['token10'=>'Fixture site']],
            'purposes'=>['link'=>['mode'=>'text','sender'=>'10001','message'=>'Code {code}']],
        ],
        'melipayamak'=>[
            'credentials'=>['username'=>'fixture-name','api_key'=>'fixture-other-secret'],
            'default'=>['mode'=>'pattern','body_id'=>123,'values'=>['{code}']],
        ],
    ],
    'selected_provider'=>'kavenegar',
    'verification'=>['codeLifetime'=>180,'cooldown'=>1,'siteHourly'=>4],
    'auth_enabled'=>true,
    'checkout_required'=>true,
    'allow_registration'=>true,
],true);
opsCheck($calls===0 && $saved['auth_enabled'] && $saved['checkout_required'] && Settings::policy()->codeLifetime===180 &&
    Settings::policy()->siteHourly===4, 'Saved settings not applied');
opsCheck(!str_contains(json_encode($saved),'fixture-secret') && !str_contains(json_encode($saved),'fixture-other-secret') &&
    !str_contains(json_encode($saved),'fixture-name') &&
    $saved['providers']->kavenegar['credentials_set']===['api_key'], 'Secret output leakage');
opsCheck(Abilities::status()['provider_configured'] && Abilities::status()['compatible_origin'], 'Readiness status incorrect');
opsError(fn()=>Settings::update(['checkout_required'=>false],$default['revision']),'settings_conflict');
opsError(fn()=>saveSettings(['auth_enabled'=>false]),'authentication_required');
opsCheck(Settings::read()['checkout_required']===true,'Failed update changed checkout');
$old=Settings::current()['providers']['kavenegar']['credentials'];
$updated=saveSettings(['providers'=>['kavenegar'=>['purposes'=>[]]]]);
opsCheck(Settings::current()['providers']['kavenegar']['credentials']===$old && $updated['revision']!==$saved['revision'], 'Credential preservation or revision failed');
opsError(fn()=>saveSettings(['providers'=>['kavenegar'=>null]],true),'provider_unconfigured');

$switch=saveSettings(['selected_provider'=>'melipayamak']);
opsCheck(Settings::sender()->id()==='melipayamak' && $calls===0,'Provider switching unexpectedly contacted network');
saveSettings(['selected_provider'=>'kavenegar']);
raceSettings(Settings::read()['revision']);
// CLI fixture has no persistent object cache; drop this request's cached
// option to observe the exact revision written by a separate process.
wp_cache_delete(Settings::OPTION,'options');
opsCheck(Settings::current()['providers']['kavenegar']['credentials']===$old &&
    Settings::policy()->networkHourly>=61 && Settings::policy()->networkHourly<=64,
    'Concurrent update lost saved credentials or accepted policy');

// Native ability discovery, permissions, schemas, and shared operations.
if (function_exists('wp_get_ability')) {
    foreach (['status','settings-read','settings-update','provider-connection','provider-test-send','diagnostics-query'] as $name) {
        $ability=wp_get_ability('ach-wp-accounts/'.$name);
        opsCheck($ability instanceof WP_Ability,'Missing ability '.$name);
        wp_set_current_user(0);
        $result=$ability->execute();
        opsCheck(is_wp_error($result),'Guest could execute '.$name);
        wp_set_current_user($admin->ID);
    }
    $subscriber=wp_insert_user(['user_login'=>'fixture_operations_subscriber','user_pass'=>wp_generate_password(32),'role'=>'subscriber']);
    wp_set_current_user($subscriber);
    $unauthorized=wp_get_ability('ach-wp-accounts/settings-update')->execute([
        'revision'=>'initial','patch'=>['checkout_required'=>false],
    ]);
    opsCheck(is_wp_error($unauthorized),'Subscriber could mutate settings');
    $unauthorized=wp_get_ability('ach-wp-accounts/provider-test-send')->execute([
        'provider'=>'kavenegar','mobile'=>'09121111111','purpose'=>'login',
        'request_id'=>str_repeat('b',32),'confirm_send'=>true,
    ]);
    opsCheck(is_wp_error($unauthorized) && $calls===0,'Subscriber could send test SMS');
    wp_set_current_user($admin->ID);
    $read=wp_get_ability('ach-wp-accounts/settings-read')->execute();
    opsCheck(is_array($read),'Native read output schema failed: '.(is_wp_error($read)?$read->get_error_code():'unexpected output'));
    $result=wp_get_ability('ach-wp-accounts/settings-update')->execute([
        'revision'=>$read['revision'],'patch'=>['verification'=>['mobileHourly'=>6]],
    ]);
    opsCheck(is_array($result) && $result['verification']['mobileHourly']===6,'Native settings update failed: '.(is_wp_error($result)?$result->get_error_code():'unexpected output'));
    $result=wp_get_ability('ach-wp-accounts/settings-update')->execute([
        'revision'=>Settings::read()['revision'],'patch'=>['providers'=>['kavenegar'=>['credentials'=>['api_key'=>'not-allowed']]]],
    ]);
    opsCheck(is_wp_error($result),'Native ability accepted credentials');
    $connection=wp_get_ability('ach-wp-accounts/provider-connection')->execute(['provider'=>'kavenegar']);
} else {
    $connection=Operations::connection('kavenegar');
}
opsCheck(is_array($connection) && $connection['state']==='accepted' && $connection['code']==='connected' && $connection['recorded'], 'Connection result or native output schema invalid');
opsCheck($calls===1,'Connection test sent more than one request');
$id=bin2hex(random_bytes(16));
opsError(fn()=>Operations::testSend('09121111111','login',$id,false),'confirmation_required');
opsCheck($calls===1,'Unconfirmed SMS sent');
$_SERVER['REMOTE_ADDR']='192.0.2.1';
$input=['provider'=>'kavenegar','mobile'=>'09121111111','purpose'=>'login','request_id'=>$id,'confirm_send'=>true];
if (function_exists('wp_get_ability')) {
    $sendAbility=wp_get_ability('ach-wp-accounts/provider-test-send');
    $receipt=$sendAbility->execute($input);
    opsCheck(is_array($receipt),'Native test send output schema failed: '.(is_wp_error($receipt)?$receipt->get_error_code():'unexpected output'));
    $replayed=$sendAbility->execute($input);
} else {
    $receipt=Operations::testSend($input['mobile'],'login',$id,true);
    $replayed=Operations::testSend($input['mobile'],'login',$id,true);
}
opsCheck($receipt['state']==='accepted' && $receipt['verification_state']==='test_only' &&
    $receipt['reference']==='12345678901234567890' && $receipt['recipient_tail']==='1111' &&
    $receipt===$replayed && $calls===2,'Test send idempotency or receipt failed');
$row=$wpdb->get_row($wpdb->prepare("SELECT code_hash,proof_hash,verified_at FROM ".Schema::table('challenges')." WHERE id=%s",$receipt['correlation_id']));
opsCheck($row && $row->code_hash==='' && $row->proof_hash==='' && (int)$row->verified_at===0,'Test send stored an authenticating proof');
opsError(fn()=>Operations::testSend('09121111112','login',$id,true),'request_conflict');

$mode='uncertain';
$uncertain=Operations::testSend('09121111112','login',bin2hex(random_bytes(16)),true);
opsCheck($uncertain['state']==='uncertain' && $uncertain['code']==='transport_error' && $calls===3,'Uncertain effect not recorded');
Diagnostics::accountFailure('registration_pending','complete');
Diagnostics::accountFailure('registration_pending','complete');
$all=Diagnostics::query(['limit'=>1]);
$items=$all['items'];
while ($all['next_cursor']!==null) {
    $all=Diagnostics::query(['limit'=>1,'cursor'=>$all['next_cursor']]);
    $items=array_merge($items,$all['items']);
    opsCheck(count($items)<=4,'Pagination repeated rows');
}
opsCheck(count($items)===4 && count(array_unique(array_column($items,'correlation_id')))===4,'Diagnostic pagination/deduplication failed');
$payload=json_encode($items);
foreach (['fixture-secret','Confidential fixture details','09121111111','+989121111111','code_hash','proof_hash','flow_hash'] as $forbidden) {
    opsCheck(!str_contains($payload,$forbidden),'Diagnostic leakage');
}
$filtered=Diagnostics::query(['category'=>'sms','state'=>'uncertain']);
opsCheck(count($filtered['items'])===1 && $filtered['items'][0]['correlation_id']===$uncertain['correlation_id'],'Diagnostic filters failed');
if (function_exists('wp_get_ability')) {
    $result=wp_get_ability('ach-wp-accounts/diagnostics-query')->execute(['category'=>'sms','limit'=>1]);
    opsCheck(is_array($result) && count($result['items'])===1,'Native diagnostic schema failed: '.(is_wp_error($result)?$result->get_error_code():'unexpected output'));
}
// A missing diagnostic snapshot must not hide authoritative live send evidence.
$wpdb->delete(Schema::table('events'),['id'=>$uncertain['correlation_id']]);
$fallback=Diagnostics::query(['correlation_id'=>$uncertain['correlation_id']]);
opsCheck(count($fallback['items'])===1 && $fallback['items'][0]['state']==='uncertain','Missing snapshot hid live evidence');
$wpdb->update(Schema::table('challenges'),['send_state'=>'pending','provider_code'=>'','provider_reference'=>''],['id'=>$uncertain['correlation_id']]);
$pending=Diagnostics::query(['correlation_id'=>$uncertain['correlation_id'],'state'=>'uncertain']);
opsCheck(count($pending['items'])===1 && $pending['items'][0]['code']==='send_pending','Pending send not represented as uncertainty');
opsError(fn()=>(new Verification())->verify($receipt['correlation_id'],'test_login',str_repeat('a',64),'000000'),'invalid_context');

// Longer diagnostic history contains only the projection, never the raw challenge.
$wpdb->delete(Schema::table('challenges'),['id'=>$receipt['correlation_id']]);
$archived=Diagnostics::query(['correlation_id'=>$receipt['correlation_id']]);
opsCheck(count($archived['items'])===1 && $archived['items'][0]['state']==='accepted','Safe diagnostic history was lost with challenge cleanup');
$eventTable=Schema::table('events');
$wpdb->update($eventTable,['created_at'=>time()-7200],['id'=>$receipt['correlation_id']]);
saveSettings(['diagnostics_retention_hours'=>1]);
Schema::cleanup();
opsCheck($wpdb->get_var($wpdb->prepare("SELECT id FROM $eventTable WHERE id=%s",$receipt['correlation_id']))===null,'Reduced retention did not expire old evidence');
// Two further distinct test sends consume the same four-per-hour site
// budget; another distinct request must be rejected without calling provider.
$mode='accepted';
Operations::testSend('09121111113','login',bin2hex(random_bytes(16)),true);
Operations::testSend('09121111114','login',bin2hex(random_bytes(16)),true);
opsError(fn()=>Operations::testSend('09121111115','login',bin2hex(random_bytes(16)),true),'rate_limited');
opsCheck($calls===5,'Site budget did not bound operator test sends');
remove_filter('pre_http_request',$mock,10);
echo "PASS: atomic settings revisions, provider validation/secret preservation, native operator schemas/permissions, deliberate test send, safe receipts and diagnostic retention/pagination\n";
