<?php
use ACh\WPAccounts\NativeAccess;
use ACh\WPAccounts\Settings;

$root=getenv('ACH_TEST_WP');
if (!$root) { throw new RuntimeException('Run through isolated fixture.'); }
define('REST_REQUEST',true);
$_SERVER['HTTPS']='on';
require $root.'/wp-load.php';
if (wp_get_environment_type()!=='local' || DB_NAME!=='accounts_fixture' || get_option('siteurl')!=='https://accounts.test') {
    throw new RuntimeException('Not isolated.');
}
function machineCheck(bool $ok,string $message):void {
    if (!$ok) { throw new RuntimeException($message); }
}
add_filter('pre_wp_mail','__return_true');
add_filter('pre_http_request',static fn()=>new WP_Error('fixture_no_network'));
$original=Settings::current();
$settings=$original;
$settings['allow_password_login']=false;
$settings['allow_email_registration']=false;
update_option(Settings::OPTION,$settings,false);
$nativePassword=wp_generate_password(32);
$id=wp_insert_user(['user_login'=>'fixture_machine','user_email'=>'machine@example.invalid','user_pass'=>$nativePassword,'role'=>'subscriber']);
machineCheck(is_int($id),'Machine fixture not created');
$application=WP_Application_Passwords::create_new_application_password($id,['name'=>'Fixture integration']);
machineCheck(is_array($application),'Application password not created');
[$credential]=$application;
$authenticated=wp_authenticate('fixture_machine',$credential);
machineCheck($authenticated instanceof WP_User && $authenticated->ID===$id,'Valid application password denied');
$denied=wp_authenticate('fixture_machine',$nativePassword);
machineCheck(is_wp_error($denied) && $denied->get_error_code()==='ach_accounts_password_disabled','REST flag bypassed ordinary password policy');
machineCheck(is_wp_error(NativeAccess::reauthenticate('fixture_machine',$credential)),'Application password became first-link credential');
$admin=get_user_by('login','fixture_admin');
$password=wp_generate_password(32);
wp_set_password($password,$admin->ID);
$authenticated=wp_authenticate($admin->user_login,$password);
machineCheck($authenticated instanceof WP_User && $authenticated->ID===$admin->ID,'Administrator native access lost');
update_option(Settings::OPTION,$original,false);
echo "PASS: actual application-password authentication, no REST ordinary-password bypass, application credential cannot authorize linking, privileged native password access\n";
