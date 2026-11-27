<?php
$root = getenv('ACH_TEST_WP');
if (!$root) { throw new RuntimeException('Run through isolated fixture.'); }
require $root . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
if (wp_get_environment_type() !== 'local' || DB_NAME !== 'accounts_fixture' || get_option('siteurl') !== 'https://accounts.test') {
    throw new RuntimeException('Not an isolated fixture.');
}
function checkWC(bool $ok, string $message): void {
    if (!$ok) { throw new RuntimeException($message); }
}
add_filter('pre_wp_mail', '__return_true');
add_filter('pre_http_request', static fn () => new WP_Error('fixture_no_network'));
if (!is_plugin_active('woocommerce/woocommerce.php')) {
    $activated = activate_plugin('woocommerce/woocommerce.php');
    checkWC(!is_wp_error($activated), is_wp_error($activated) ? $activated->get_error_message() : 'WooCommerce activation');
}
checkWC(defined('WC_VERSION') && WC_VERSION === getenv('ACH_TEST_WC_VERSION'), 'Unexpected WooCommerce version');
checkWC(is_plugin_active('ach-wp-accounts/ach-wp-accounts.php'), 'Accounts foundation inactive');
checkWC(function_exists('wc_create_new_customer'), 'WooCommerce customer API not loaded');
$result = wc_create_new_customer('', 'wc_fixture_blank', wp_generate_password(32));
checkWC(is_wp_error($result) && $result->get_error_code() === 'registration-error-invalid-email', 'Expected native WC email requirement changed');
foreach (['native_fixture_one','native_fixture_two'] as $login) {
    $id = wp_insert_user(['user_login'=>$login,'user_pass'=>wp_generate_password(32),'role'=>'customer']);
    checkWC(is_int($id) && get_userdata($id)->user_email === '', 'Native WP account without email failed');
}
echo "PASS: WordPress / WooCommerce " . WC_VERSION . " activation and native email-contract comparison (not checkout acceptance)\n";
