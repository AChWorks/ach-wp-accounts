<?php
namespace ACh\WPAccounts;

defined('ABSPATH') || exit;

/** Plugin-owned site tables. No destructive deactivation or uninstall. */
final class Schema
{
    public const VERSION = 1;
    public const TABLES = ['identities','challenges','limits','session_proofs','events','recovery_emails'];

    public static function table(string $name): string
    {
        global $wpdb;
        if (!in_array($name, self::TABLES, true)) {
            throw new \InvalidArgumentException('Unknown accounts table.');
        }
        return $wpdb->prefix . 'ach_accounts_' . $name;
    }

    public static function install(bool $create = true): void
    {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $identities = self::table('identities');
        $challenges = self::table('challenges');
        $limits = self::table('limits');
        $collation = $wpdb->get_charset_collate();

        // User IDs remain owned by WordPress. No billing field is an identity.
        $definitions[] = "CREATE TABLE $identities (
            mobile varchar(16) NOT NULL,
            user_id bigint(20) unsigned DEFAULT NULL,
            revision char(32) NOT NULL,
            verified_at bigint(20) unsigned NOT NULL,
            registration_login varchar(60) NOT NULL DEFAULT '',
            registration_started smallint(5) unsigned NOT NULL DEFAULT 0,
            source varchar(16) NOT NULL DEFAULT 'otp',
            source_ref char(64) NOT NULL DEFAULT '',
            PRIMARY KEY  (mobile),
            UNIQUE KEY user_id (user_id),
            UNIQUE KEY revision (revision)
        ) ENGINE=InnoDB $collation;";
        $definitions[] = "CREATE TABLE $challenges (
            id char(32) NOT NULL,
            reservation_id char(32) NOT NULL,
            mobile varchar(16) NOT NULL,
            identity_revision char(32) NOT NULL DEFAULT '',
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
            KEY mobile_revision (mobile,identity_revision),
            KEY chronology (created_at,id),
            KEY retire_at (retire_at)
        ) ENGINE=InnoDB $collation;";
        $definitions[] = "CREATE TABLE $limits (
            bucket_key char(64) NOT NULL,
            hits int(10) unsigned NOT NULL DEFAULT 0,
            next_at bigint(20) unsigned NOT NULL DEFAULT 0,
            retire_at bigint(20) unsigned NOT NULL,
            PRIMARY KEY  (bucket_key),
            KEY retire_at (retire_at)
        ) ENGINE=InnoDB $collation;";

        $events = self::table('events');
        $definitions[] = "CREATE TABLE $events (
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
        ) ENGINE=InnoDB $collation;";
        $sessions = self::table('session_proofs');
        $definitions[] = "CREATE TABLE $sessions (
            token_hash char(64) NOT NULL,
            user_id bigint(20) unsigned NOT NULL,
            revision char(32) NOT NULL,
            verified_at bigint(20) unsigned NOT NULL,
            retire_at bigint(20) unsigned NOT NULL,
            PRIMARY KEY  (token_hash),
            KEY user_id (user_id),
            KEY retire_at (retire_at)
        ) ENGINE=InnoDB $collation;";

        $recovery = self::table('recovery_emails');
        $definitions[] = "CREATE TABLE $recovery (
            user_id bigint(20) unsigned NOT NULL,
            token_hash char(64) NOT NULL,
            new_email varchar(100) NOT NULL,
            previous_email varchar(100) NOT NULL,
            identity_revision char(32) NOT NULL,
            epoch char(32) NOT NULL,
            retire_at bigint(20) unsigned NOT NULL,
            PRIMARY KEY  (user_id),
            KEY retire_at (retire_at)
        ) ENGINE=InnoDB $collation;";
        foreach ($definitions as $definition) {
            if ($create) { dbDelta($definition); }
            self::verify($definition);
        }
    }

    /** Inspect the actual columns/indexes; dbDelta's description is not proof. */
    private static function verify(string $definition): void
    {
        global $wpdb;
        if (!preg_match('/CREATE TABLE ([^ ]+) \\(/',$definition,$m)) {
            throw new \RuntimeException('Invalid accounts schema definition.');
        }
        $table = $m[1];
        $status = $wpdb->get_row($wpdb->prepare('SHOW TABLE STATUS WHERE Name = %s',$table));
        if (!$status || strcasecmp((string)$status->Engine,'InnoDB') !== 0) {
            throw new \RuntimeException('Required transactional accounts table unavailable.');
        }
        $columns = [];
        foreach ($wpdb->get_results("SHOW COLUMNS FROM `$table`") as $column) {
            $columns[$column->Field] = $column;
        }
        $indexes = [];
        foreach ($wpdb->get_results("SHOW INDEX FROM `$table`") as $index) {
            $indexes[$index->Key_name][(int)$index->Seq_in_index] = $index;
        }
        // Integer display widths differ between supported MySQL/MariaDB
        // releases but do not affect storage or range.
        $type = static fn(string $value): string => preg_replace('/\\b(bigint|smallint|int)\\([0-9]+\\)/i','$1',strtolower($value));
        foreach (explode("\n",$definition) as $line) {
            $line = trim($line," \t,");
            if (preg_match('/^([a-z_]+) ((?:varchar|char|bigint|smallint|int)\\([0-9]+\\)(?: unsigned)?)(.*)$/i',$line,$m)) {
                $actual = $columns[$m[1]] ?? null;
                if (!$actual || $type($actual->Type) !== $type($m[2]) ||
                    $actual->Null !== (str_contains($m[3],'NOT NULL') ? 'NO' : 'YES')) {
                    throw new \RuntimeException('Required accounts column unavailable: '.$m[1]);
                }
            } elseif (preg_match('/^(PRIMARY KEY|UNIQUE KEY|KEY)\\s*(?:([a-z_]+)\\s*)?\\(([^)]+)\\)$/i',$line,$m)) {
                $name = $m[1] === 'PRIMARY KEY' ? 'PRIMARY' : $m[2];
                $parts = $indexes[$name] ?? [];
                ksort($parts);
                $expected = explode(',',$m[3]);
                if (count($parts)!==count($expected)) {
                    throw new \RuntimeException('Required accounts index unavailable: '.$name);
                }
                foreach (array_values($parts) as $i=>$part) {
                    if ($part->Column_name !== trim($expected[$i]) || $part->Sub_part !== null ||
                        (int)$part->Non_unique !== ($m[1] === 'KEY' ? 1 : 0)) {
                        throw new \RuntimeException('Required accounts index incompatible: '.$name);
                    }
                }
            }
        }
    }

    public static function cleanup(): void
    {
        if (!Lifecycle::ready()) { return; }
        global $wpdb;
        $more = false;
        $now = time();
        foreach (['challenges', 'limits', 'session_proofs', 'events', 'recovery_emails'] as $name) {
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
