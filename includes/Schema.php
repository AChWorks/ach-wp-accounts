<?php
namespace ACh\WPAccounts;

defined('ABSPATH') || exit;

/** Plugin-owned site tables. No destructive deactivation or uninstall. */
final class Schema
{
    public const VERSION = 1;

    public static function table(string $name): string
    {
        global $wpdb;
        if (!in_array($name, ['identities', 'challenges', 'limits', 'session_proofs', 'events'], true)) {
            throw new \InvalidArgumentException('Unknown accounts table.');
        }
        return $wpdb->prefix . 'ach_accounts_' . $name;
    }

    public static function install(): void
    {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $identities = self::table('identities');
        $challenges = self::table('challenges');
        $limits = self::table('limits');
        $collation = $wpdb->get_charset_collate();

        // User IDs remain owned by WordPress. No billing field is an identity.
        dbDelta("CREATE TABLE $identities (
            mobile varchar(16) NOT NULL,
            user_id bigint(20) unsigned DEFAULT NULL,
            revision char(32) NOT NULL,
            verified_at bigint(20) unsigned NOT NULL,
            registration_login varchar(60) NOT NULL DEFAULT '',
            PRIMARY KEY  (mobile),
            UNIQUE KEY user_id (user_id)
        ) ENGINE=InnoDB $collation;");
        dbDelta("CREATE TABLE $challenges (
            id char(32) NOT NULL,
            reservation_id char(32) NOT NULL,
            mobile varchar(16) NOT NULL,
            purpose varchar(32) NOT NULL,
            flow_hash char(64) NOT NULL,
            code_hash char(64) NOT NULL,
            provider varchar(32) NOT NULL,
            send_state varchar(16) NOT NULL,
            provider_code varchar(64) NOT NULL DEFAULT '',
            provider_reference varchar(128) NOT NULL DEFAULT '',
            duration_ms int(10) unsigned NOT NULL DEFAULT 0,
            attempts smallint(5) unsigned NOT NULL DEFAULT 0,
            max_attempts smallint(5) unsigned NOT NULL,
            created_at bigint(20) unsigned NOT NULL,
            expires_at bigint(20) unsigned NOT NULL,
            resend_at bigint(20) unsigned NOT NULL DEFAULT 0,
            verified_at bigint(20) unsigned NOT NULL DEFAULT 0,
            proof_hash char(64) NOT NULL DEFAULT '',
            proof_expires_at bigint(20) unsigned NOT NULL DEFAULT 0,
            consumed_at bigint(20) unsigned NOT NULL DEFAULT 0,
            retire_at bigint(20) unsigned NOT NULL,
            PRIMARY KEY  (id),
            KEY chronology (created_at,id),
            KEY retire_at (retire_at)
        ) ENGINE=InnoDB $collation;");
        dbDelta("CREATE TABLE $limits (
            bucket_key char(64) NOT NULL,
            hits int(10) unsigned NOT NULL DEFAULT 0,
            next_at bigint(20) unsigned NOT NULL DEFAULT 0,
            retire_at bigint(20) unsigned NOT NULL,
            PRIMARY KEY  (bucket_key),
            KEY retire_at (retire_at)
        ) ENGINE=InnoDB $collation;");

        $events = self::table('events');
        dbDelta("CREATE TABLE $events (
            id char(32) NOT NULL,
            category varchar(24) NOT NULL,
            purpose varchar(32) NOT NULL DEFAULT '',
            provider varchar(32) NOT NULL DEFAULT '',
            state varchar(16) NOT NULL,
            event_code varchar(64) NOT NULL,
            provider_reference varchar(128) NOT NULL DEFAULT '',
            recipient_tail char(4) NOT NULL DEFAULT '',
            duration_ms int(10) unsigned NOT NULL DEFAULT 0,
            created_at bigint(20) unsigned NOT NULL,
            retire_at bigint(20) unsigned NOT NULL,
            PRIMARY KEY  (id),
            KEY chronology (created_at,id),
            KEY retire_at (retire_at)
        ) ENGINE=InnoDB $collation;");
        $sessions = self::table('session_proofs');
        dbDelta("CREATE TABLE $sessions (
            token_hash char(64) NOT NULL,
            user_id bigint(20) unsigned NOT NULL,
            revision char(32) NOT NULL,
            verified_at bigint(20) unsigned NOT NULL,
            retire_at bigint(20) unsigned NOT NULL,
            PRIMARY KEY  (token_hash),
            KEY user_id (user_id),
            KEY retire_at (retire_at)
        ) ENGINE=InnoDB $collation;");

        // dbDelta returning a description is not proof of successful DDL.
        foreach ([$identities => ['PRIMARY' => 'mobile', 'user_id' => 'user_id'], $challenges => ['PRIMARY' => 'id'], $limits => ['PRIMARY' => 'bucket_key'], $sessions => ['PRIMARY' => 'token_hash'], $events => ['PRIMARY' => 'id']] as $table => $required) {
            $status = $wpdb->get_row($wpdb->prepare('SHOW TABLE STATUS WHERE Name = %s', $table));
            if (!$status || strcasecmp((string) $status->Engine, 'InnoDB') !== 0) {
                throw new \RuntimeException('Required transactional accounts table unavailable.');
            }
            $indexes = $wpdb->get_results("SHOW INDEX FROM `$table`");
            foreach ($required as $index => $column) {
                $parts = array_values(array_filter($indexes, static fn ($row) => $row->Key_name === $index));
                if (count($parts) !== 1 || $parts[0]->Column_name !== $column || (int) $parts[0]->Non_unique !== 0 || $parts[0]->Sub_part !== null) {
                    throw new \RuntimeException('Required accounts uniqueness constraint unavailable.');
                }
            }
        }
        update_option('ach_wp_accounts_schema_version', self::VERSION, false);
    }

    public static function cleanup(): void
    {
        global $wpdb;
        $more = false;
        $now = time();
        foreach (['challenges', 'limits', 'session_proofs', 'events'] as $name) {
            $table = self::table($name);
            $where = 'retire_at <= %d';
            $args = [$now];
            if ($name === 'events') {
                $where .= ' OR created_at <= %d';
                $args[] = $now - Settings::retentionSeconds();
            }
            $deleted = $wpdb->query($wpdb->prepare("DELETE FROM $table WHERE $where ORDER BY retire_at LIMIT 1000", ...$args));
            $more = $more || $deleted === 1000;
        }
        if ($more && !wp_next_scheduled('ach_wp_accounts_cleanup_continue')) {
            wp_schedule_single_event(time() + 60, 'ach_wp_accounts_cleanup_continue');
        }
    }
}
