<?php
use ACh\WPAccounts\{AccountFlow,Identity,SessionProof,Settings,SettingsError,SmsSender,SendResult};
$root=getenv('ACH_TEST_WP');
if (!$root) { throw new RuntimeException('Run isolated fixture.'); }
require $root.'/wp-load.php';
if (wp_get_environment_type()!=='local' || DB_NAME!=='accounts_fixture' || get_option('siteurl')!=='https://accounts.test' || function_exists('WC')) {
    throw new RuntimeException('Expected WordPress-only isolated fixture.');
}
function standaloneCheck(bool $ok,string $message):void {if (!$ok) {throw new RuntimeException($message);}}
add_filter('pre_wp_mail','__return_true');
add_filter('pre_http_request',static fn()=>new WP_Error('fixture_no_network'));
$admin=get_user_by('login','fixture_admin');
wp_set_current_user($admin->ID);
$old=Settings::current();
$settings=Settings::update([
    'providers'=>['kavenegar'=>['credentials'=>['api_key'=>'fixture-only'],'default'=>['mode'=>'pattern','template'=>'fixture'],'purposes'=>[]]],
    'selected_provider'=>'kavenegar','auth_enabled'=>true,'allow_registration'=>true,
],$old['revision'],true);
try {
    Settings::update(['checkout_required'=>true],$settings['revision']);
    throw new RuntimeException('Commerce-only setting accepted without WooCommerce');
} catch (SettingsError $error) {
    standaloneCheck($error->getMessage()==='woocommerce_unavailable','Unexpected commerce setting rejection');
}
$registration=get_option('users_can_register');
update_option('users_can_register',true);
update_option('default_role','subscriber');
wp_set_current_user(0);
add_action('set_logged_in_cookie',static function($cookie):void {$_COOKIE[LOGGED_IN_COOKIE]=$cookie;});
$sender=new class implements SmsSender {
    private string $code='';
    public function id():string{return 'fixture';}
    public function send(string $mobile,string $code,string $purpose):SendResult{$this->code=$code;return new SendResult('accepted','fixture');}
    public function complete(AccountFlow $flow,string $browser,string $ticket):array{return $flow->complete($browser,$ticket,$this->code);}
};
$flow=new AccountFlow();
$browser=bin2hex(random_bytes(32));
$ticket=$flow->start($browser,'09124440001','login','wp');
$flow->issue($browser,$ticket,'192.0.2.2',$sender);
$result=$sender->complete($flow,$browser,$ticket);
$user=get_userdata(get_current_user_id());
standaloneCheck($result['created'] && $user && $user->roles===['subscriber'] && $user->user_email==='' &&
    Identity::byUser($user->ID) && SessionProof::qualifies(),'Native WP-only OTP signup/login failed');
wp_logout();
unset($_COOKIE[LOGGED_IN_COOKIE]);
update_option('users_can_register',$registration);
update_option(Settings::OPTION,$old,false);
echo "PASS: standalone WordPress OTP registration/login and session qualification without WooCommerce\n";
