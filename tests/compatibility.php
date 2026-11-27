<?php
/** Actual native core minimum / downgrade fixture, without WooCommerce. */
$root = getenv('ACH_TEST_WP');
if (!$root || !getenv('ACH_TEST_DIR')) { throw new RuntimeException('Run isolated fixture.'); }
require $root.'/wp-load.php';
require_once ABSPATH.'wp-admin/includes/plugin.php';
if (wp_get_environment_type() !== 'local' || DB_NAME !== 'accounts_fixture' ||
    get_option('siteurl') !== 'https://accounts.test' || get_bloginfo('version') !== getenv('ACH_TEST_WP_VERSION')) {
    throw new RuntimeException('Unexpected fixture.');
}
function compatCheck(bool $condition,string $message): void {
    if (!$condition) { throw new RuntimeException($message); }
}
add_filter('pre_wp_mail','__return_true');
add_filter('pre_http_request',static fn()=>new WP_Error('fixture_no_network'));
$file='ach-wp-accounts/ach-wp-accounts.php';
$header=get_plugin_data(WP_PLUGIN_DIR.'/'.$file,false,false);
compatCheck($header['RequiresWP']==='6.3' && $header['RequiresPHP']==='8.1','Inconsistent native headers');
$compatible=version_compare(get_bloginfo('version'),'6.3','>=');
if (($argv[1] ?? '')==='forced-active') {
    compatCheck(!$compatible && is_plugin_active($file) && !class_exists('ACh\\WPAccounts\\Lifecycle',false) &&
        !has_action('wp_ajax_ach_wp_accounts') && !wp_next_scheduled('ach_wp_accounts_cleanup'),
        'Unsupported manually activated plugin loaded runtime');
} else {
    $result=activate_plugin($file);
    if (!$compatible) {
        compatCheck(is_wp_error($result) && !is_plugin_active($file) &&
            !class_exists('ACh\\WPAccounts\\Lifecycle',false),'Unsupported core permitted activation');
        // Simulate the persisted active-plugin entry after a manual downgrade.
        update_option('active_plugins',[$file]);
    } else {
        compatCheck(!is_wp_error($result) && is_plugin_active($file) &&
            \ACh\WPAccounts\Lifecycle::ready() && function_exists('wp_is_password_reset_allowed_for_user'),
            'Supported baseline activation failed');
        $settings=\ACh\WPAccounts\Settings::current();
        compatCheck(!$settings['auth_enabled'] && !$settings['allow_registration'] && !$settings['checkout_required'],
            'Customer policy not disabled by default');
    }
}
$password=wp_generate_password(32);
$id=wp_insert_user(['user_login'=>'compat_'.bin2hex(random_bytes(4)),'user_pass'=>$password,
    'user_email'=>bin2hex(random_bytes(6)).'@example.invalid','role'=>'subscriber']);
compatCheck(is_int($id) && wp_authenticate(get_userdata($id)->user_login,$password) instanceof WP_User,
    'Native account creation/password login failed');
compatCheck(!is_wp_error(wp_update_user(['ID'=>$id,'display_name'=>'Compatibility fixture'])),
    'Native profile update failed');
compatCheck(is_string(get_password_reset_key(get_userdata($id))),'Native email recovery failed');
echo 'PASS: actual WordPress '.get_bloginfo('version').' / PHP '.PHP_VERSION.
    ($compatible?' activation and disabled-policy native access':(($argv[1]??'')==='forced-active'?' forced-active inert runtime and native access':' native rejection and native access')).PHP_EOL;
