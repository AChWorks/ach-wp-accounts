<?php
namespace ACh\WPAccounts;

defined('ABSPATH') || exit;

/** Serialize only operations sharing one actual resource on this database. */
final class DatabaseLock
{
    public static function run(string $resource, callable $operation, string $busy = 'storage_unavailable'): mixed
    {
        global $wpdb;
        $name = 'ach-wpa:' . substr(hash('sha256', DB_NAME . "\0" . $wpdb->prefix . "\0" . $resource), 0, 56);
        $old = $wpdb->suppress_errors(true);
        try {
            $acquired = $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,0)', $name));
            if ((string)$acquired !== '1') {
                throw new VerificationError($acquired===null ? 'storage_unavailable' : $busy);
            }
        } finally {
            $wpdb->suppress_errors($old);
        }
        try {
            return $operation();
        } finally {
            $old = $wpdb->suppress_errors(true);
            try {
                $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $name));
            } finally {
                $wpdb->suppress_errors($old);
            }
        }
    }
}

/** Installation and version transitions; never delete native users or orders. */
final class Lifecycle
{
    public const VERSION_OPTION = 'ach_wp_accounts_schema_version';
    private const EPOCH = 'ach_wp_accounts_epoch';
    private const ERROR = 'ach_wp_accounts_schema_error';

    public static function ready(): bool
    {
        return !is_multisite() && (int)get_option(self::VERSION_OPTION, 0) === Schema::VERSION &&
            self::epoch() !== '';
    }

    public static function epoch(): string
    {
        $value = get_option(self::EPOCH, '');
        return is_string($value) && preg_match('/\A[0-9a-f]{32}\z/D', $value) ? $value : '';
    }

    private static function ensureEpoch(): void
    {
        if (self::epoch() === '') {
            update_option(self::EPOCH, bin2hex(random_bytes(16)), false);
        }
        if (self::epoch() === '') {
            throw new VerificationError('storage_unavailable');
        }
    }

    private static function schedule(): void
    {
        if (!wp_next_scheduled('ach_wp_accounts_cleanup') &&
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'hourly', 'ach_wp_accounts_cleanup', [], true) !== true) {
            throw new VerificationError('storage_unavailable');
        }
    }

    public static function install(bool $validate = false): void
    {
        if (is_multisite()) {
            throw new VerificationError('authentication_unavailable');
        }
        DatabaseLock::run('schema', static function () use ($validate): void {
            wp_cache_delete(self::VERSION_OPTION,'options');
            wp_cache_delete(self::EPOCH,'options');
            $version = (int)get_option(self::VERSION_OPTION,0);
            // Never apply an older schema to data written by newer code.
            if ($version > Schema::VERSION || $version < 0) {
                throw new VerificationError('schema_unsupported');
            }
            global $wpdb;
            $old = $wpdb->suppress_errors(true);
            try {
                $target = $version;
                // Every future version must add an explicit transition.
                // Merely incrementing VERSION can never mark it installed.
                while ($target < Schema::VERSION) {
                    switch ($target) {
                        case 0:
                            Schema::install();
                            $target = 1;
                            break;
                        default:
                            throw new VerificationError('schema_unsupported');
                    }
                }
                if ($version===Schema::VERSION && ($validate || self::epoch()==='')) {
                    // Validate installed storage; do not silently recreate a
                    // missing identity table and conceal data loss.
                    Schema::install(false);
                }
            } finally { $wpdb->suppress_errors($old); }
            Settings::install();
            self::ensureEpoch();
            self::schedule();
            // Commit version only after actual schema and prerequisites pass.
            if ($version !== Schema::VERSION) {
                update_option(self::VERSION_OPTION, Schema::VERSION, false);
            }
            if (!self::ready()) {
                throw new VerificationError('storage_unavailable');
            }
            delete_option(self::ERROR);
        }, 'schema_busy');
    }

    public static function bootstrap(): void
    {
        if (self::ready() || is_multisite() ||
            (!(is_admin() && !wp_doing_ajax()) && !wp_doing_cron() && !(defined('WP_CLI') && WP_CLI))) {
            return;
        }
        // A silent native upgrade does not run the activation hook. Perform
        // the pending transition once, before any account flow is available.
        try {
            self::install();
        } catch (\Throwable $error) {
            // Other site functions and native admin recovery remain usable.
            // The schema/epoch mismatch is visible in readiness and blocks OTP.
            $code = $error instanceof VerificationError ? $error->getMessage() : 'schema_install_failed';
            if (in_array($code,['schema_busy','schema_unsupported','schema_install_failed','storage_unavailable'],true)) {
                update_option(self::ERROR,$code,false);
            }
        }
    }

    public static function error(): string
    {
        if ((int)get_option(self::VERSION_OPTION,0)>Schema::VERSION) { return 'schema_unsupported'; }
        if (self::ready()) { return ''; }
        $code = get_option(self::ERROR,'');
        return in_array($code,['schema_busy','schema_unsupported','schema_install_failed','storage_unavailable'],true) ? $code : 'schema_not_ready';
    }

    public static function activate(): void
    {
        if (is_multisite()) {
            wp_die(esc_html__('Multisite account identity is not supported by this development version.', 'ach-wp-accounts'));
        }
        try {
            self::install(true);
            // An explicit reactivation must not revive previously valid proofs.
            $epoch = bin2hex(random_bytes(16));
            update_option(self::EPOCH,$epoch,false);
            if (self::epoch() !== $epoch) {
                throw new VerificationError('storage_unavailable');
            }
        } catch (\Throwable $error) {
            wp_die(esc_html__('ACh WP Accounts could not initialize its database or scheduled cleanup. Check site health and database permissions.', 'ach-wp-accounts'));
        }
    }

    public static function deactivate(): void
    {
        // Explicit deactivation revokes plugin flow/session authority, not
        // identities, native sessions, settings or diagnostic evidence.
        update_option(self::EPOCH, bin2hex(random_bytes(16)), false);
        wp_clear_scheduled_hook('ach_wp_accounts_cleanup');
        wp_clear_scheduled_hook('ach_wp_accounts_cleanup_continue');
    }

    /** Explicit local administrative purge. Ordinary deletion preserves data. */
    public static function purge(string $file,bool $confirmed): void
    {
        AccountPolicy::requireAccountOperator();
        if (!$confirmed || !current_user_can('delete_plugins') || is_multisite() ||
            (int)get_option(self::VERSION_OPTION,0)>Schema::VERSION ||
            Settings::flag('auth_enabled') || Settings::flag('checkout_required')) {
            throw new SettingsError('purge','confirmation_required');
        }
        DatabaseLock::run('schema',static function () use ($file): void {
            global $wpdb;
            require_once ABSPATH.'wp-admin/includes/plugin.php';
            // The only accepted plugin is this installation's own entry file.
            $plugin = plugin_basename($file);
            if ($plugin!==plugin_basename(dirname(__DIR__).'/ach-wp-accounts.php')) {
                throw new SettingsError('purge');
            }
            deactivate_plugins($plugin);
            if (is_plugin_active($plugin)) { throw new SettingsError('purge','storage_unavailable'); }
            $old = $wpdb->suppress_errors(true);
            try {
                foreach (Schema::TABLES as $name) {
                    $table = Schema::table($name);
                    if ($wpdb->query("DROP TABLE IF EXISTS `$table`")===false) {
                        throw new SettingsError('purge','storage_unavailable');
                    }
                }
                foreach (['_ach_accounts_registration','_ach_accounts_verified_email'] as $key) {
                    delete_metadata('user',0,$key,'',true);
                    $left = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $wpdb->usermeta WHERE meta_key=%s",$key));
                    if ($wpdb->last_error!=='' || (int)$left!==0) {
                        throw new SettingsError('purge','storage_unavailable');
                    }
                }
                foreach ([Settings::OPTION,self::VERSION_OPTION,self::EPOCH,self::ERROR] as $option) {
                    delete_option($option);
                    if (get_option($option,null)!==null) { throw new SettingsError('purge','storage_unavailable'); }
                }
                Updater::clear();
                wp_clear_scheduled_hook('ach_wp_accounts_cleanup');
                wp_clear_scheduled_hook('ach_wp_accounts_cleanup_continue');
                if (wp_next_scheduled('ach_wp_accounts_cleanup') || wp_next_scheduled('ach_wp_accounts_cleanup_continue')) {
                    throw new SettingsError('purge','storage_unavailable');
                }
            } finally { $wpdb->suppress_errors($old); }
        },'schema_busy');
    }

    public static function hooks(string $file): void
    {
        add_action('plugins_loaded', [self::class,'bootstrap'], 0);
        register_activation_hook($file,[self::class,'activate']);
        register_deactivation_hook($file,[self::class,'deactivate']);
    }
}
