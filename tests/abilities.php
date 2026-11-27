<?php
$root = getenv('ACH_TEST_WP');
if (!$root) { throw new RuntimeException('Run through isolated fixture.'); }
require $root . '/wp-load.php';
if (wp_get_environment_type() !== 'local' || DB_NAME !== 'accounts_fixture' || get_option('siteurl') !== 'https://accounts.test') {
    throw new RuntimeException('Not an isolated fixture.');
}
function assertResult(bool $result, string $message): void {
    if (!$result) { throw new RuntimeException($message); }
}
if (!function_exists('wp_get_ability')) {
    echo "SKIP: native Abilities API unavailable on this WordPress version\n";
    exit;
}
$ability = wp_get_ability('ach-wp-accounts/status');
assertResult($ability instanceof WP_Ability, 'Status ability not registered');
wp_set_current_user(0);
assertResult(is_wp_error($ability->execute()), 'Guest was able to execute status');
$user = wp_insert_user(['user_login'=>'fixture_subscriber','user_pass'=>wp_generate_password(32),'role'=>'subscriber']);
assertResult(is_int($user), 'Could not create subscriber fixture');
wp_set_current_user($user);
assertResult(is_wp_error($ability->execute()), 'Subscriber was able to execute status');
$admin = get_user_by('login','fixture_admin');
wp_set_current_user($admin->ID);
$result = $ability->execute();
assertResult(is_array($result) && $result['schema_version'] === 1 && count($result['providers']) === 2 && !$result['authentication_available'] && !$result['checkout_policy_available'], 'Unexpected status output');
assertResult($result['providers'][0]['required_inputs'] === ['api_key'] &&
    $result['providers'][1]['required_inputs'] === ['username','api_key'] &&
    !isset($result['providers'][0]['credential_fields']), 'Operator status must contain only symbolic configuration input names');
assertResult($ability->get_meta_item('annotations')['readonly'] === true, 'Missing readonly annotation');
echo "PASS: native ability registration, status schema and permission checks\n";
