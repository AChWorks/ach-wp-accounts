<?php
/**
 * Plugin Name: ACh WP Accounts
 * Description: Verified mobile identity and native WordPress account integration.
 * Version: 0.1.0-dev
 * Requires PHP: 8.1
 * Author: ACh
 * Author URI: https://ach.li/
 * Text Domain: ach-wp-accounts
 * Domain Path: /languages
 * License: GPL-2.0-or-later
 */

defined('ABSPATH') || exit;

require_once __DIR__ . '/includes/Schema.php';
require_once __DIR__ . '/includes/Mobile.php';
require_once __DIR__ . '/includes/Messaging.php';
require_once __DIR__ . '/includes/Providers.php';
require_once __DIR__ . '/includes/Verification.php';
require_once __DIR__ . '/includes/Abilities.php';
\ACh\WPAccounts\Abilities::hooks();

register_activation_hook(__FILE__, static function (bool $networkWide): void {
    if (is_multisite()) {
        // Initial implementation has no network-wide identity policy.
        wp_die(esc_html__('Multisite account identity is not supported by this development version.', 'ach-wp-accounts'));
    }
    try {
        \ACh\WPAccounts\Schema::install();
        if (!wp_next_scheduled('ach_wp_accounts_cleanup')) {
            $scheduled = wp_schedule_event(time() + HOUR_IN_SECONDS, 'hourly', 'ach_wp_accounts_cleanup', [], true);
            if ($scheduled !== true) {
                throw new \RuntimeException('Could not schedule verification data cleanup.');
            }
        }
    } catch (\RuntimeException $exception) {
        wp_die(esc_html__('ACh WP Accounts could not initialize its database or scheduled cleanup. Check site health and database permissions.', 'ach-wp-accounts'));
    }
});
register_deactivation_hook(__FILE__, static function (): void {
    wp_clear_scheduled_hook('ach_wp_accounts_cleanup');
    wp_clear_scheduled_hook('ach_wp_accounts_cleanup_continue');
});
add_action('ach_wp_accounts_cleanup', [\ACh\WPAccounts\Schema::class, 'cleanup']);
add_action('ach_wp_accounts_cleanup_continue', [\ACh\WPAccounts\Schema::class, 'cleanup']);
