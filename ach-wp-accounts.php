<?php
/**
 * Plugin Name: ACh WP Accounts
 * Description: Verified mobile identity and native WordPress account integration.
 * Version: 0.1.0
 * Update URI: https://github.com/AChWorks/ach-wp-accounts
 * Requires at least: 6.3
 * Requires PHP: 8.1
 * Author: ACh
 * Author URI: https://ach.li/
 * Text Domain: ach-wp-accounts
 * Domain Path: /languages
 * License: GPL-2.0-or-later
 */

defined('ABSPATH') || exit;

// Core's installer uses the native headers. An already-active plugin after
// a manual downgrade must also stay inert before loading incompatible code.
global $wp_version;
if (PHP_VERSION_ID < 80100 || version_compare($wp_version,'6.3','<')) {
    return;
}

require_once __DIR__ . '/includes/Schema.php';
require_once __DIR__ . '/includes/Lifecycle.php';
require_once __DIR__ . '/includes/Mobile.php';
require_once __DIR__ . '/includes/Messaging.php';
require_once __DIR__ . '/includes/Providers.php';
require_once __DIR__ . '/includes/Verification.php';
require_once __DIR__ . '/includes/Settings.php';
require_once __DIR__ . '/includes/Diagnostics.php';
require_once __DIR__ . '/includes/Operations.php';
require_once __DIR__ . '/includes/Identity.php';
require_once __DIR__ . '/includes/SessionProof.php';
require_once __DIR__ . '/includes/Accounts.php';
require_once __DIR__ . '/includes/RegistrationRecovery.php';
require_once __DIR__ . '/includes/DigitsSource.php';
require_once __DIR__ . '/includes/Migration.php';
require_once __DIR__ . '/includes/Privacy.php';
require_once __DIR__ . '/includes/CLI.php';
require_once __DIR__ . '/includes/Recovery.php';
require_once __DIR__ . '/includes/AccountFlow.php';
require_once __DIR__ . '/includes/NativeAccess.php';
require_once __DIR__ . '/includes/BrowserApi.php';
require_once __DIR__ . '/includes/CheckoutGate.php';
require_once __DIR__ . '/includes/CheckoutUi.php';
require_once __DIR__ . '/includes/Frontend.php';
require_once __DIR__ . '/includes/AuthPage.php';
require_once __DIR__ . '/includes/Abilities.php';
require_once __DIR__ . '/includes/Updater.php';
require_once __DIR__ . '/includes/Admin.php';
require_once __DIR__ . '/includes/AdminView.php';
\ACh\WPAccounts\EmailRecovery::hooks();
add_action('deleted_user', [\ACh\WPAccounts\Privacy::class, 'deletedUser']);
\ACh\WPAccounts\Privacy::hooks();
\ACh\WPAccounts\CLI::hooks(__FILE__);
add_action('clear_auth_cookie', [\ACh\WPAccounts\SessionProof::class, 'logout']);
\ACh\WPAccounts\Abilities::hooks();
\ACh\WPAccounts\Updater::hooks(__FILE__);
\ACh\WPAccounts\Admin::hooks();
\ACh\WPAccounts\BrowserApi::hooks();
\ACh\WPAccounts\CheckoutGate::hooks();
\ACh\WPAccounts\CheckoutUi::hooks();
\ACh\WPAccounts\Frontend::hooks();
\ACh\WPAccounts\NativeAccess::hooks();
\ACh\WPAccounts\AuthPage::hooks();

\ACh\WPAccounts\Lifecycle::hooks(__FILE__);
add_action('ach_wp_accounts_cleanup', [\ACh\WPAccounts\Schema::class, 'cleanup']);
add_action('ach_wp_accounts_cleanup_continue', [\ACh\WPAccounts\Schema::class, 'cleanup']);
