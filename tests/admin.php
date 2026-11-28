<?php
use ACh\WPAccounts\Admin;
use ACh\WPAccounts\AdminView;
use ACh\WPAccounts\Settings;
use ACh\WPAccounts\SettingsError;
use ACh\WPAccounts\Schema;
use ACh\WPAccounts\VerificationError;

$root=getenv('ACH_TEST_WP');
if (!$root) { throw new RuntimeException('Run through isolated fixture.'); }
require $root.'/wp-load.php';
require_once ABSPATH.'wp-admin/includes/plugin.php';
require_once ABSPATH.'wp-admin/includes/template.php';
if (wp_get_environment_type()!=='local' || DB_NAME!=='accounts_fixture' || get_option('siteurl')!=='https://accounts.test') {
    throw new RuntimeException('Not isolated.');
}
function adminCheck(bool $ok,string $message): void {
    if (!$ok) { throw new RuntimeException($message); }
}
function adminError(callable $fn,string $code): void {
    try { $fn(); } catch (SettingsError|VerificationError $error) {
        adminCheck($error->getMessage()===$code,'Unexpected admin error: '.$error->getMessage());
        return;
    }
    throw new RuntimeException('Expected admin error: '.$code);
}
function postAdmin(string $action,array $data=[]): array {
    if ($action==='general') { $data += ['allow_email_registration'=>'1','allow_password_login'=>'1']; }
    return $data+['ach_action'=>$action,'revision'=>Settings::read()['revision'],
        '_ach_nonce'=>wp_create_nonce('ach_accounts_admin_'.$action)];
}
function renderAdmin(string $tab,array $result=[]): string {
    ob_start();
    try {
        AdminView::render($tab,Settings::read(),$result);
        return (string)ob_get_contents();
    } finally { ob_end_clean(); }
}
$admin=get_user_by('login','fixture_admin');
wp_set_current_user($admin->ID);
$_SERVER['HTTPS']='on';
global $wpdb;
update_option(Settings::OPTION,Settings::defaults(),false);
foreach (['challenges','events','limits'] as $table) {
    $wpdb->query('TRUNCATE TABLE '.Schema::table($table));
}
$default=Settings::read();
$input=postAdmin('general',['auth_enabled'=>'1']);
adminError(fn()=>Admin::process('general',$input),'provider_unconfigured');
$input['_ach_nonce']='invalid';
adminError(fn()=>Admin::process('general',$input),'invalid_nonce');
adminError(fn()=>Admin::process('diagnostics',$input),'invalid_settings');
$subscriber=wp_insert_user(['user_login'=>'fixture_admin_subscriber','user_pass'=>wp_generate_password(32),'role'=>'subscriber']);
wp_set_current_user($subscriber);
adminError(fn()=>Admin::process('general',$input),'forbidden');
adminError(fn()=>renderAdmin('general'),'forbidden');
wp_set_current_user($admin->ID);
$calls=0;
add_filter('pre_http_request',static function($pre,$args,$url) use (&$calls) {
    $calls++;
    parse_str($args['body'],$body);
    if (str_ends_with($url,'/account/info.json')) {
        return ['response'=>['code'=>200],'body'=>wp_json_encode(['return'=>['status'=>200],'entries'=>['remaincredit'=>1000]])];
    }
    if (str_ends_with($url,'/verify/lookup.json')) {
        return ['response'=>['code'=>200],'body'=>wp_json_encode(['return'=>['status'=>200],'entries'=>[
            ['messageid'=>123456,'receptor'=>$body['receptor'],'status'=>5],
        ]])];
    }
    return new WP_Error('fixture_no_network');
},PHP_INT_MAX,3);
$_GET=['tab'=>'sms','provider'=>'kavenegar'];
$initial=renderAdmin('sms');
adminCheck(str_contains($initial,'data-ach-admin-form="provider"') &&
    !str_contains($initial,'data-ach-admin-form="test_send"'),'Unconfigured provider presented send action');
$provider=postAdmin('provider',[
    'provider'=>'kavenegar','credentials'=>['api_key'=>'fixture-admin-key'],
    'default'=>['mode'=>'pattern','template'=>'fixture-template','token10'=>'Fixture site'],
    'purposes'=>['link'=>['enabled'=>'1','mode'=>'text','sender'=>'۱۰۰۰۱','message'=>'Code {code}']],
]);
$_SERVER['HTTPS']='off';
adminError(fn()=>Admin::process('sms',$provider),'https_required');
$_SERVER['HTTPS']='on';
Admin::process('sms',$provider);
adminCheck($calls===0 && Settings::current()['providers']['kavenegar']['purposes']['link']['sender']==='10001','Provider save or digit normalization failed');
$view=renderAdmin('sms');
preg_match_all('/\\sid="([^"]+)"/',$view,$ids);
adminCheck(count($ids[1])===count(array_unique($ids[1])),'Duplicate admin element IDs');
preg_match_all('/aria-describedby="([^"]+)"/',$view,$descriptions);
foreach ($descriptions[1] as $description) {
    adminCheck(in_array($description,$ids[1],true),'Missing accessible help target');
}
$_SERVER['HTTPS']='off';
$insecure=renderAdmin('sms');
adminCheck(preg_match('/<input type="password"[^>]*disabled>/', $insecure)===1,'Insecure credential field enabled');
$_SERVER['HTTPS']='on';
adminCheck(str_contains($view,'Saved — leave blank to keep') && !str_contains($view,'fixture-admin-key') &&
    str_contains($view,'fixture-template') && str_contains($view,'data-ach-admin-form="test_send"'),'Saved provider view missing or credential exposed');
$bad=postAdmin('provider',[
    'provider'=>'kavenegar','credentials'=>['api_key'=>'fixture-admin-other-key'],
    'default'=>['mode'=>'text','sender'=>'10001','message'=>'missing code'],
]);
adminError(fn()=>Admin::process('sms',$bad),'provider_invalid');
$draftResult=['code'=>'provider_invalid','field'=>'providers.kavenegar','draft'=>[
    'ach_action'=>'provider','provider'=>'kavenegar','revision'=>$bad['revision'],
    'default'=>['mode'=>'text','sender'=>'10001','message'=>'missing code'],
]];
$view=renderAdmin('sms',$draftResult);
adminCheck(str_contains($view,'missing code') && !str_contains($view,'fixture-admin-other-key') &&
    !str_contains($view,'fixture-admin-key'),'Invalid form did not preserve safe values or leaked key');
adminCheck(Settings::current()['providers']['kavenegar']['credentials']['api_key']==='fixture-admin-key','Invalid save replaced credentials');
$second=postAdmin('provider',[
    'provider'=>'melipayamak','credentials'=>['username'=>'fixture-admin-user','api_key'=>'fixture-admin-melipayamak'],
    'default'=>['mode'=>'pattern','body_id'=>'۱۲۳','values'=>"Fixture\n{code}"],
]);
Admin::process('sms',$second);
adminCheck(Settings::current()['providers']['melipayamak']['default']['body_id']===123 &&
    Settings::current()['providers']['melipayamak']['default']['values']===['Fixture','{code}'],'Ordered template values not preserved');
$_GET=['tab'=>'sms','provider'=>'melipayamak'];
$view=renderAdmin('sms');
adminCheck(!str_contains($view,'fixture-admin-user') && !str_contains($view,'fixture-admin-melipayamak') &&
    str_contains($view,'Pattern values in order'),'Second provider UI failed');
Admin::process('sms',postAdmin('selection',['selected_provider'=>'kavenegar']));
$rev=Settings::read()['revision'];
Admin::process('general',postAdmin('general',['auth_enabled'=>'1','allow_registration'=>'1','checkout_required'=>'1']));
adminCheck(Settings::flag('auth_enabled') && Settings::flag('checkout_required') && Settings::flag('allow_registration'),'General policies not saved');
adminCheck(!Settings::flag('checkout_reverify'),'Repeat OTP did not default off');
Admin::process('general',postAdmin('general',['auth_enabled'=>'1','allow_registration'=>'1','checkout_required'=>'1','checkout_reverify'=>'1']));
adminCheck(Settings::flag('checkout_reverify') && Settings::read()['checkout_reverify']===true,'Repeat OTP setting not saved');
Admin::process('general',postAdmin('general',['auth_enabled'=>'1','allow_registration'=>'1','checkout_required'=>'1','checkout_reverify'=>'0']));
adminCheck(!Settings::flag('checkout_reverify'),'Repeat OTP could not be disabled');

$stale=postAdmin('general',['revision'=>$rev,'auth_enabled'=>'0','checkout_required'=>'0']);
adminError(fn()=>Admin::process('general',$stale),'settings_conflict');
$_GET=['tab'=>'general'];
$staleView=renderAdmin('general',['code'=>'settings_conflict','field'=>'revision','draft'=>[
    'ach_action'=>'general','revision'=>$rev,'auth_enabled'=>'0','checkout_required'=>'0','allow_registration'=>'0',
]]);
adminCheck(str_contains($staleView,'name="revision" value="'.$rev.'"') && str_contains($staleView,'Reload saved settings'),'Stale draft silently acquired current revision');
adminError(fn()=>Admin::process('general',postAdmin('general',['auth_enabled'=>'0','checkout_required'=>'1'])),'authentication_required');
adminError(fn()=>Admin::process('sms',postAdmin('remove',['provider'=>'kavenegar','confirm_remove'=>'1'])),'provider_unconfigured');
adminError(fn()=>Admin::process('sms',postAdmin('remove',['provider'=>'melipayamak'])),'confirmation_required');
$connection=Admin::process('sms',postAdmin('connection',['provider'=>'kavenegar']));
adminCheck($connection['receipt']['state']==='accepted' && $calls===1,'Connection did not use saved provider');
$test=postAdmin('test_send',['provider'=>'kavenegar','purpose'=>'login','mobile'=>'۰۹۱۲۱۱۱۱۱۱۱',
    'request_id'=>bin2hex(random_bytes(16)),'confirm_send'=>'0']);
adminError(fn()=>Admin::process('sms',$test),'confirmation_required');
$test['confirm_send']='1';
$receipt=Admin::process('sms',$test);
$repeat=Admin::process('sms',$test);
adminCheck($receipt===$repeat && $calls===2 && $receipt['receipt']['state']==='accepted','Admin duplicate send');
$_GET=['tab'=>'sms','provider'=>'kavenegar'];
$receiptHtml=renderAdmin('sms',$receipt);
adminCheck(str_contains($receiptHtml,'does not confirm delivery') && str_contains($receiptHtml,'123456') &&
    !str_contains($receiptHtml,'09121111111') && !str_contains($receiptHtml,'fixture-admin-key'),'Test receipt UI invalid');
$policy=Settings::read()['verification'];
$policy=array_map('strval',$policy);
$policy['cooldown']='۲۵';
Admin::process('sms',postAdmin('verification',$policy));
adminCheck(Settings::policy()->cooldown===25,'Verification policy input not applied');
adminError(fn()=>Admin::process('sms',$test),'settings_conflict');
adminCheck($calls===2,'Stale test sent SMS');
Admin::process('diagnostics',postAdmin('retention',['diagnostics_retention_hours'=>'۴۸']));
adminCheck(Settings::read()['diagnostics_retention_hours']===48,'Retention not applied');
$_GET=['tab'=>'diagnostics','category'=>'sms','state'=>'accepted'];
$diag=renderAdmin('diagnostics');
adminCheck(str_contains($diag,'123456') && str_contains($diag,'••••1111') &&
    !str_contains($diag,'09121111111') && !str_contains($diag,'fixture-admin-key'),'Diagnostic view did not show safe receipt');
$_GET=['tab'=>'diagnostics','correlation_id'=>str_repeat('f',32)];
adminCheck(str_contains(renderAdmin('diagnostics'),'No matching diagnostic events.'),'Diagnostic empty filter state missing');
Admin::process('sms',postAdmin('remove',['provider'=>'melipayamak','confirm_remove'=>'1']));
adminCheck(!isset(Settings::current()['providers']['melipayamak']) && Settings::flag('auth_enabled'),'Removing inactive provider changed authentication');
Admin::process('general',postAdmin('general',['auth_enabled'=>'0','allow_registration'=>'0','checkout_required'=>'0']));
Admin::process('sms',postAdmin('remove',['provider'=>'kavenegar','confirm_remove'=>'1']));
adminCheck(Settings::current()['providers']===[] && Settings::current()['selected_provider']==='','Disabled provider could not be removed');
echo "PASS: native admin nonce/capability, provider editing and masking, Persian numeric input, stale drafts, shared policy/test receipts, retention and diagnostic rendering\n";
