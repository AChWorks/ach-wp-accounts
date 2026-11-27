<?php
namespace ACh\WPAccounts;

defined('ABSPATH') || exit;

/** Internal domain error codes; presentation adapters translate safe messages. */
final class VerificationError extends \RuntimeException
{
}

/** Security/traffic policy defaults; settings adapter will validate operator input. */
final class VerificationPolicy
{
    public function __construct(
        public readonly int $codeLifetime = 300,
        public readonly int $proofLifetime = 600,
        public readonly int $maxAttempts = 5,
        public readonly int $cooldown = 60,
        public readonly int $mobileHourly = 5,
        public readonly int $networkHourly = 60,
        public readonly int $siteHourly = 300,
        public readonly int $siteDaily = 2000,
    ) {
        if ($codeLifetime < 1 || $proofLifetime < 1 || $maxAttempts < 1 || $maxAttempts > 65535 ||
            $cooldown < 1 || min($mobileHourly, $networkHourly, $siteHourly, $siteDaily) < 1 ||
            max($mobileHourly, $networkHourly, $siteHourly, $siteDaily) > 4294967295) {
            throw new \InvalidArgumentException('Invalid verification policy.');
        }
    }
}

/**
 * Internal OTP operations. No HTTP routes, account creation or login authority here.
 * The application adapter must derive flow secrets from its own same-origin state
 * (including account/session binding for authenticated purposes) and authorize each operation.
 * Issue owns its transaction; do not call from an existing database transaction.
 */
final class Verification
{
    private const PURPOSES = ['login', 'link', 'step_up'];

    public function __construct(private readonly VerificationPolicy $policy = new VerificationPolicy())
    {
    }

    public function codeLifetime(): int
    {
        return $this->policy->codeLifetime;
    }

    private function digest(string $kind, #[\SensitiveParameter] string ...$parts): string
    {
        return hash_hmac('sha256', $kind . "\0" . implode("\0", $parts), wp_salt('auth'));
    }

    private function validateContext(string $id, string $purpose, #[\SensitiveParameter] string $flow): void
    {
        if (!preg_match('/\A[0-9a-f]{32}\z/D', $id) || !preg_match('/\A[0-9a-f]{64}\z/D', $flow) ||
            !in_array($purpose, self::PURPOSES, true)) {
            throw new VerificationError('invalid_context');
        }
    }

    private function query(#[\SensitiveParameter] string $sql): int
    {
        global $wpdb;
        $previous = $wpdb->suppress_errors(true);
        try {
            $result = $wpdb->query($sql);
            if ($result === false) {
                throw new VerificationError('storage_unavailable');
            }
            return (int) $result;
        } finally {
            $wpdb->suppress_errors($previous);
        }
    }

    private function row(#[\SensitiveParameter] string $sql): ?object
    {
        global $wpdb;
        $previous = $wpdb->suppress_errors(true);
        try {
            $row = $wpdb->get_row($sql);
            if ($wpdb->last_error !== '') {
                throw new VerificationError('storage_unavailable');
            }
            return $row;
        } finally {
            $wpdb->suppress_errors($previous);
        }
    }

    private function connection(): string
    {
        $row = $this->row('SELECT CONNECTION_ID() AS connection_id');
        if (!$row) {
            throw new VerificationError('storage_unavailable');
        }
        return (string) $row->connection_id;
    }

    private function challenge(string $id, bool $lock = false): ?object
    {
        global $wpdb;
        $table = Schema::table('challenges');
        return $this->row($wpdb->prepare("SELECT * FROM `$table` WHERE id = %s" . ($lock ? ' FOR UPDATE' : ''), $id));
    }

    private function matches(object $row, string $purpose, #[\SensitiveParameter] string $flow): bool
    {
        return $row->purpose === $purpose && hash_equals($row->flow_hash, $this->digest('flow', $flow));
    }

    private function publicState(object $row): array
    {
        return [
            'id' => $row->id,
            'state' => $row->send_state === 'pending' ? 'uncertain' : $row->send_state,
            'expires_at' => (int) $row->expires_at,
        ];
    }

    private function reserve(string $mobile, string $network, int $now): void
    {
        global $wpdb;
        $table = Schema::table('limits');
        $hour = intdiv($now, 3600) * 3600;
        $day = intdiv($now, 86400) * 86400;
        // Stable cooldown across fixed window boundaries; hashed identity/network keys.
        $buckets = [
            $this->digest('cooldown', $mobile) => [0, $now + $this->policy->cooldown, true],
            $this->digest('mobile-hour', $mobile, (string) $hour) => [$this->policy->mobileHourly, $hour + 3600, false],
            $this->digest('network-hour', $network, (string) $hour) => [$this->policy->networkHourly, $hour + 3600, false],
            $this->digest('site-hour', (string) $hour) => [$this->policy->siteHourly, $hour + 3600, false],
            $this->digest('site-day', (string) $day) => [$this->policy->siteDaily, $day + 86400, false],
        ];
        // All requests acquire shared rate bucket locks in one order.
        ksort($buckets, SORT_STRING);
        foreach ($buckets as $key => [$limit, $retire, $cooldown]) {
            $this->query($wpdb->prepare("INSERT INTO `$table` (bucket_key,retire_at) VALUES (%s,%d) ON DUPLICATE KEY UPDATE bucket_key=bucket_key", $key, $retire));
            $row = $this->row($wpdb->prepare("SELECT hits,next_at FROM `$table` WHERE bucket_key=%s FOR UPDATE", $key));
            if (!$row || ($cooldown ? (int) $row->next_at > $now : (int) $row->hits >= $limit)) {
                throw new VerificationError('rate_limited');
            }
            if ($cooldown) {
                $this->query($wpdb->prepare("UPDATE `$table` SET next_at=%d,retire_at=%d WHERE bucket_key=%s", $retire, $retire, $key));
            } else {
                $this->query($wpdb->prepare("UPDATE `$table` SET hits=hits+1 WHERE bucket_key=%s", $key));
            }
        }
    }

    /**
     * Exact request replay never sends again. A different id is an explicit new
     * request subject to the same quotas. Network must be derived from trusted
     * server request context (not a caller-controlled forwarding header).
     */
    public function issue(string $id, string $mobile, string $purpose, #[\SensitiveParameter] string $flow, string $network, SmsSender $sender): array
    {
        global $wpdb;
        $this->validateContext($id, $purpose, $flow);
        $mobile = Mobile::normalize($mobile);
        $ip = inet_pton($network);
        $provider = $sender->id();
        if ($ip === false || !preg_match('/\A[a-z0-9_-]{1,32}\z/D', $provider)) {
            throw new VerificationError('invalid_context');
        }
        // Aggregate IPv6 addresses by /64; do not trust spoofable HTTP headers.
        if (strlen($ip) === 16 && substr($ip, 0, 12) === str_repeat(chr(0), 10) . chr(255) . chr(255)) {
            $ip = substr($ip, 12);
        }
        $network = bin2hex(strlen($ip) === 16 ? substr($ip, 0, 8) : $ip);
        $table = Schema::table('challenges');
        $now = time();
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $flowHash = $this->digest('flow', $flow);
        $codeHash = $this->digest('code', $id, $mobile, $purpose, $flow, $code);
        $connection = $this->connection();

        $this->query('START TRANSACTION');
        try {
            // A unique insert serializes concurrent replays without a gap lock.
            // A no-op duplicate update can report 1 with CLIENT_FOUND_ROWS,
            // so identify ownership with the freshly generated reservation identifier.
            $reservation = bin2hex(random_bytes(16));
            $this->query($wpdb->prepare(
                "INSERT INTO `$table` (id,reservation_id,mobile,purpose,flow_hash,code_hash,provider,send_state,max_attempts,created_at,expires_at,retire_at)
                 VALUES (%s,%s,%s,%s,%s,%s,%s,'pending',%d,%d,%d,%d) ON DUPLICATE KEY UPDATE id=id",
                $id, $reservation, $mobile, $purpose, $flowHash, $codeHash, $provider, $this->policy->maxAttempts, $now, $now + $this->policy->codeLifetime,
                $now + max(86400, $this->policy->codeLifetime + $this->policy->proofLifetime)
            ));
            $row = $this->challenge($id, true);
            if (!$row) {
                throw new VerificationError('storage_unavailable');
            }
            $owned = hash_equals($row->reservation_id, $reservation);
            if (!$owned) {
                if (!$this->matches($row, $purpose, $flow) || $row->mobile !== $mobile) {
                    throw new VerificationError('request_conflict');
                }
            } else {
                $this->reserve($mobile, $network, $now);
            }
            // WordPress may reconnect after a lost DB connection. Never enter
            // the external boundary unless the original transaction committed.
            if ($this->connection() !== $connection) {
                throw new VerificationError('storage_unavailable');
            }
            $this->query('COMMIT');
            if ($this->connection() !== $connection) {
                throw new VerificationError('storage_unavailable');
            }
        } catch (\Throwable $error) {
            try {
                $this->query('ROLLBACK');
            } catch (\Throwable $ignored) {
                // No external effect is entered on this failure path.
            }
            throw $error;
        }
        if (!$owned) {
            return $this->publicState($row);
        }

        $start = hrtime(true);
        try {
            $result = $sender->send($mobile, $code, $purpose);
        } catch (\Throwable $error) {
            // Exception text may include secrets; uncertainty is not a retry.
            $result = new SendResult('uncertain', 'transport_exception');
        }
        $duration = (int) min(4294967295, (hrtime(true) - $start) / 1000000);
        $this->query($wpdb->prepare(
            "UPDATE `$table` SET send_state=%s,provider_code=%s,provider_reference=%s,duration_ms=%d,
             code_hash=IF(%s='failed','',code_hash) WHERE id=%s AND send_state='pending'",
            $result->state, $result->code, $result->reference, $duration, $result->state, $id
        ));
        $row = $this->challenge($id);
        if (!$row) {
            throw new VerificationError('storage_unavailable');
        }
        return $this->publicState($row);
    }

    /** OTP is single-use; proof is a separate random single-use continuation. */
    public function verify(string $id, string $purpose, #[\SensitiveParameter] string $flow, #[\SensitiveParameter] string $code): string
    {
        global $wpdb;
        $this->validateContext($id, $purpose, $flow);
        $row = $this->challenge($id);
        $now = time();
        if (!$row || !$this->matches($row, $purpose, $flow)) {
            throw new VerificationError('invalid_proof');
        }
        $code = Mobile::asciiDigits($code);
        $correct = preg_match('/\A[0-9]{6}\z/D', $code) &&
            hash_equals($row->code_hash, $this->digest('code', $id, $row->mobile, $purpose, $flow, $code));
        $proof = bin2hex(random_bytes(32));
        $proofHash = $this->digest('proof', $id, $purpose, $flow, $proof);
        $table = Schema::table('challenges');
        $where = $wpdb->prepare(
            " WHERE id=%s AND flow_hash=%s AND purpose=%s AND send_state IN ('accepted','uncertain','pending')
              AND expires_at>%d AND verified_at=0 AND attempts<max_attempts AND code_hash=%s AND code_hash<>''",
            $id, $row->flow_hash, $purpose, $now, $row->code_hash
        );
        // A compare-and-set, not a read-modify-write attempt counter.
        if ($correct) {
            $expires = $now + $this->policy->proofLifetime;
            $changed = $this->query($wpdb->prepare(
                "UPDATE `$table` SET attempts=attempts+1,verified_at=%d,proof_hash=%s,proof_expires_at=%d,
                 retire_at=GREATEST(retire_at,%d),code_hash=''",
                $now, $proofHash, $expires, $expires
            ) . $where);
            if ($changed === 1) {
                return $proof;
            }
        } else {
            $this->query("UPDATE `$table` SET attempts=attempts+1" . $where);
        }
        throw new VerificationError('invalid_proof');
    }

    /** Consume exactly once; account policy/authorization is a separate boundary. */
    public function consume(string $id, string $purpose, #[\SensitiveParameter] string $flow, #[\SensitiveParameter] string $proof): string
    {
        global $wpdb;
        $this->validateContext($id, $purpose, $flow);
        $row = $this->challenge($id);
        $now = time();
        if (!$row || !$this->matches($row, $purpose, $flow) ||
            !preg_match('/\A[0-9a-f]{64}\z/D', $proof) ||
            !hash_equals($row->proof_hash, $this->digest('proof', $id, $purpose, $flow, $proof))) {
            throw new VerificationError('invalid_proof');
        }
        $table = Schema::table('challenges');
        $changed = $this->query($wpdb->prepare(
            "UPDATE `$table` SET consumed_at=%d,proof_hash='' WHERE id=%s AND flow_hash=%s AND purpose=%s
             AND proof_hash=%s AND verified_at>0 AND consumed_at=0 AND proof_expires_at>%d",
            $now, $id, $row->flow_hash, $purpose, $row->proof_hash, $now
        ));
        if ($changed !== 1) {
            throw new VerificationError('invalid_proof');
        }
        return $row->mobile;
    }
}
