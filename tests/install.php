<?php
// This installer is intentionally fixture-only; it is not distributed in the plugin.
$root = getenv('ACH_TEST_WP');
if (!$root || !getenv('ACH_TEST_DIR') || !is_file($root . '/wp-config.php')) {
    throw new RuntimeException('Run through scripts/test-integration.sh.');
}
define('WP_INSTALLING', true);
require $root . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/upgrade.php';
add_filter('pre_wp_mail', '__return_true');
wp_install('Accounts test fixture', 'fixture_admin', 'admin@example.invalid', false, '', wp_generate_password(32));
echo "Installed WordPress " . get_bloginfo('version') . " / PHP " . PHP_VERSION . PHP_EOL;
