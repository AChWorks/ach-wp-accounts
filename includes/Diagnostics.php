<?php
namespace ACh\WPAccounts;

defined('ABSPATH') || exit;

/**
 * Safe bounded operational evidence. Challenge rows are authoritative for SMS
 * issuance and verification. Copy only their non-secret result projection for
 * longer retention; fallback to live rows if a diagnostic write failed or the
 * provider result remains uncertain. Never log raw HTTP payloads or OTPs.
 */
final class Diagnostics
{
    private static function write(array $record): bool
    {
        global $wpdb;
        $table = Schema::table('events');
        $record['retire_at'] = $record['created_at'] + Settings::retentionSeconds();
        $old = $wpdb->suppress_errors(true);
        try {
            return $wpdb->query($wpdb->prepare(
                "INSERT INTO $table (id,category,purpose,provider,state,event_code,provider_reference,recipient_tail,duration_ms,created_at,retire_at)
                 VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%d,%d,%d) ON DUPLICATE KEY UPDATE id=id",
                $record['id'], $record['category'], $record['purpose'], $record['provider'], $record['state'],
                $record['event_code'], $record['provider_reference'], $record['recipient_tail'],
                $record['duration_ms'], $record['created_at'], $record['retire_at']
            )) !== false;
        } finally {
            $wpdb->suppress_errors($old);
        }
    }

    public static function recordSend(object $row): void
    {
        try {
            self::write([
                'id' => $row->id,
                'category' => 'sms',
                'purpose' => $row->purpose,
                'provider' => $row->provider,
                'state' => $row->send_state,
                'event_code' => $row->provider_code,
                'provider_reference' => $row->provider_reference,
                'recipient_tail' => substr($row->mobile, -4),
                'duration_ms' => (int)$row->duration_ms,
                'created_at' => (int)$row->created_at,
            ]);
        } catch (\Throwable $ignored) {
            // Live challenge evidence remains available. No external retry.
        }
    }

    public static function connection(string $provider, SendResult $result, int $duration): array
    {
        $record = [
            'id' => bin2hex(random_bytes(16)),
            'category' => 'connection',
            'purpose' => '',
            'provider' => $provider,
            'state' => $result->state,
            'event_code' => $result->code,
            'provider_reference' => $result->reference,
            'recipient_tail' => '',
            'duration_ms' => $duration,
            'created_at' => time(),
        ];
        try {
            $stored = self::write($record);
        } catch (\Throwable $ignored) {
            $stored = false;
        }
        return self::project((object)$record) + ['recorded' => $stored];
    }

    /** Capture only exceptional customer flow failures; ordinary wrong codes
     * are represented by the challenge attempt counters, not separate events. */
    public static function accountFailure(string $code, string $operation): void
    {
        if (!in_array($code, ['registration_pending', 'storage_unavailable', 'authentication_unavailable'], true) ||
            !in_array($operation, ['start', 'issue', 'complete', 'continue_registration', 'reset_password', 'recovery_email', 'recovery_password'], true)) {
            return;
        }
        try {
            // Avoid an event flood when the provider is unconfigured: one row
            // per operation/error/minute. The timestamp represents the first.
            $now = time();
            self::write([
                'id' => substr(hash_hmac('sha256', 'ach-accounts-event:' . $operation . ':' . $code . ':' . intdiv($now, 60), wp_salt('auth')), 0, 32),
                'category' => 'account',
                'purpose' => $operation,
                'provider' => '',
                'state' => 'failed',
                'event_code' => $code,
                'provider_reference' => '',
                'recipient_tail' => '',
                'duration_ms' => 0,
                'created_at' => $now,
            ]);
        } catch (\Throwable $ignored) {
            // Storage errors may also prevent logging. Do not replace domain errors.
        }
    }

    /** Live challenge metadata is used only for exact diagnostic projection. */
    private static function project(object $row): array
    {
        $state = $row->state === 'pending' ? 'uncertain' : $row->state;
        return [
            'correlation_id' => $row->id,
            'timestamp' => (int)$row->created_at,
            'category' => $row->category,
            'purpose' => $row->purpose,
            'provider' => $row->provider,
            'state' => $state,
            'level' => $state === 'accepted' ? 'info' : ($state === 'failed' ? 'error' : 'warning'),
            'code' => $row->event_code !== '' ? $row->event_code : ($row->state === 'pending' ? 'send_pending' : ''),
            'reference' => $row->provider_reference,
            'recipient_tail' => $row->recipient_tail,
            'duration_ms' => (int)$row->duration_ms,
            'retry_safety' => $row->category === 'sms' ? 'no_automatic_retry' : 'not_applicable',
            'verification_state' => $row->verification_state ?? '',
            'attempts' => isset($row->attempts) ? (int)$row->attempts : null,
        ];
    }

    /** Exact receipt for a test operation, independent of the history filter. */
    public static function testReceipt(string $id): array
    {
        Settings::requireOperator();
        global $wpdb;
        $table = Schema::table('challenges');
        $old = $wpdb->suppress_errors(true);
        try {
            $row = $wpdb->get_row($wpdb->prepare(
                "SELECT id,'sms' AS category,purpose,provider,send_state AS state,provider_code AS event_code,
                 provider_reference,RIGHT(mobile,4) AS recipient_tail,duration_ms,created_at,
                 'test_only' AS verification_state,attempts FROM $table
                 WHERE id=%s AND LEFT(purpose,5)='test_'", $id
            ));
            if (!$row || $wpdb->last_error !== '') {
                throw new SettingsError('', 'storage_unavailable');
            }
            return self::project($row);
        } finally {
            $wpdb->suppress_errors($old);
        }
    }

    /** Query stable descending keyset pages; no full-table export of secrets. */
    public static function query(array $input = []): array
    {
        Settings::requireOperator();
        global $wpdb;
        $allowed = ['category','provider','state','purpose','from','to','cursor','limit','correlation_id'];
        if (array_diff(array_keys($input), $allowed)) {
            throw new SettingsError('filters');
        }
        $limit = $input['limit'] ?? 25;
        if (!is_int($limit) || $limit < 1 || $limit > 100) {
            throw new SettingsError('limit');
        }
        $now = time();
        $since = $now - Settings::retentionSeconds();
        $from = $input['from'] ?? $since;
        $to = $input['to'] ?? $now;
        if (!is_int($from) || !is_int($to) || $from < 0 || $to < $from) {
            throw new SettingsError('time_range');
        }
        $from = max($from, $since);
        $conditions = ['created_at >= %d', 'created_at <= %d'];
        $args = [$from, $to];
        $enums = [
            'category' => ['sms','connection','account'],
            'provider' => array_column(ProviderRegistry::capabilities(), 'id'),
            'state' => ['accepted','failed','uncertain'],
            'purpose' => ['login','link','step_up','password_reset','test_login','test_link','test_step_up','test_password_reset','start','issue','complete','continue_registration','reset_password','recovery_email','recovery_password'],
        ];
        foreach ($enums as $key => $values) {
            if (isset($input[$key])) {
                if (!is_string($input[$key]) || !in_array($input[$key], $values, true)) {
                    throw new SettingsError($key);
                }
                if ($key === 'state' && $input[$key] === 'uncertain') {
                    $conditions[] = "state IN ('uncertain','pending')";
                } else {
                    $conditions[] = "$key=%s";
                    $args[] = $input[$key];
                }
            }
        }
        if (isset($input['correlation_id'])) {
            if (!is_string($input['correlation_id']) || !preg_match('/\A[0-9a-f]{32}\z/D', $input['correlation_id'])) {
                throw new SettingsError('correlation_id');
            }
            $conditions[] = 'id=%s';
            $args[] = $input['correlation_id'];
        }
        if (isset($input['cursor'])) {
            if (!is_string($input['cursor']) || !preg_match('/\A([0-9]{1,12}):([0-9a-f]{32})\z/D', $input['cursor'], $m)) {
                throw new SettingsError('cursor');
            }
            $conditions[] = '(created_at < %d OR (created_at = %d AND id < %s))';
            array_push($args, (int)$m[1], (int)$m[1], $m[2]);
        }
        $events = Schema::table('events');
        $challenges = Schema::table('challenges');
        // Retained snapshots contain only safe send-result metadata. For a
        // currently live challenge, use the live row instead (one event, not
        // two); for historic records retain its send result after OTP cleanup.
        $sql = "SELECT * FROM (
          SELECT e.id,e.category,e.purpose,e.provider,e.state,e.event_code,e.provider_reference,e.recipient_tail,e.duration_ms,e.created_at,
                 CASE WHEN LEFT(e.purpose,5)='test_' THEN 'test_only' ELSE '' END AS verification_state,NULL AS attempts
          FROM $events e
          WHERE e.retire_at>%d AND e.created_at >= %d AND e.created_at <= %d
            AND NOT EXISTS (SELECT 1 FROM $challenges c WHERE c.id=e.id AND c.retire_at>%d)
          UNION ALL
          SELECT c.id,'sms' AS category,c.purpose,c.provider,c.send_state AS state,c.provider_code AS event_code,
                 c.provider_reference,RIGHT(c.mobile,4) AS recipient_tail,c.duration_ms,c.created_at,
                 CASE WHEN LEFT(c.purpose,5)='test_' THEN 'test_only'
                      WHEN c.consumed_at>0 THEN 'consumed'
                      WHEN c.verified_at>0 THEN 'verified'
                      WHEN c.send_state='failed' THEN 'not_sent'
                      WHEN c.expires_at<=%d THEN 'expired'
                      WHEN c.attempts>=c.max_attempts THEN 'attempts_exhausted'
                      ELSE 'awaiting_code' END AS verification_state,c.attempts
          FROM $challenges c WHERE c.retire_at>%d AND c.created_at >= %d AND c.created_at <= %d
        ) AS evidence WHERE " . implode(' AND ', $conditions) . ' ORDER BY created_at DESC,id DESC LIMIT %d';
        $old = $wpdb->suppress_errors(true);
        try {
            $rows = $wpdb->get_results($wpdb->prepare($sql, ...[$now, $from, $to, $now, $now, $now, $from, $to, ...$args, $limit + 1]));
            if (!is_array($rows) || $wpdb->last_error !== '') {
                throw new SettingsError('', 'storage_unavailable');
            }
        } finally {
            $wpdb->suppress_errors($old);
        }
        $more = count($rows) > $limit;
        $rows = array_slice($rows, 0, $limit);
        $last = $more ? end($rows) : false;
        return [
            'items' => array_map([self::class,'project'], $rows),
            'next_cursor' => $last ? $last->created_at . ':' . $last->id : null,
        ];
    }
}
