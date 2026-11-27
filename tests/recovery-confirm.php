<?php
// Private child fixture: dispatch the actual native profile controller.
$root=getenv('ACH_TEST_WP');
$dir=getenv('ACH_TEST_DIR');
$file=$argv[1] ?? '';
if (!$root || !$dir || $file!==$dir.'/private-email-confirm.json' || !is_file($file)) {
    throw new RuntimeException('Run isolated lifecycle fixture.');
}
$input=json_decode(file_get_contents($file),true,512,JSON_THROW_ON_ERROR);
$_SERVER['HTTPS']='on';
$_SERVER['REQUEST_METHOD']='GET';
$_SERVER['HTTP_HOST']='accounts.test';
$_SERVER['SERVER_NAME']='accounts.test';
$_SERVER['SERVER_PORT']='443';
$_SERVER['REQUEST_URI']='/wp-admin/profile.php?ach_confirm_email='.$input['token'];
$_SERVER['PHP_SELF']='/wp-admin/profile.php';
$_SERVER['SCRIPT_NAME']='/wp-admin/profile.php';
$_GET=['ach_confirm_email'=>$input['token']];
$_REQUEST=$_GET;
// These names are derived from the fixture's exact WordPress site URL.
$_COOKIE['wordpress_logged_in_'.md5('https://accounts.test')]=$input['logged_in'];
$_COOKIE['wordpress_sec_'.md5('https://accounts.test')]=$input['secure_auth'];
define('WP_ADMIN',true);
require $root.'/wp-load.php';
if (wp_get_environment_type()!=='local' || DB_NAME!=='accounts_fixture' || get_option('siteurl')!=='https://accounts.test') {
    throw new RuntimeException('Not isolated.');
}
add_filter('pre_wp_mail','__return_true');
add_filter('pre_http_request',static fn()=>new WP_Error('fixture_no_network'));
add_filter('wp_redirect',static function($location) {
    if (str_contains($location,'profile.php') && str_contains($location,'updated=true')) {
        echo 'confirmed_profile_redirect';
    }
    return $location;
});
require $root.'/wp-admin/profile.php';
