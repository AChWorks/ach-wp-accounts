<?php
namespace ACh\WPAccounts;

defined('ABSPATH') || exit;

/**
 * A mobile proof qualifies one native WP session and one identity revision.
 * Never modify WP session metadata or recreate a revoked native session.
 */
final class SessionProof
{
    private static function hash(#[\SensitiveParameter] string $token): string
    {
        return hash_hmac('sha256', "ach-wpa-session\0" . Lifecycle::epoch() . "\0" . $token, wp_salt('auth'));
    }

    public static function currentToken(int $userId): string
    {
        $token = wp_get_session_token();
        if ($userId < 1 || get_current_user_id() !== $userId || $token === '' ||
            (int) wp_validate_auth_cookie('', 'logged_in') !== $userId ||
            !\WP_Session_Tokens::get_instance($userId)->verify($token)) {
            return '';
        }
        return $token;
    }

    /**
     * Native login freshness is only sufficient for first linking an ordinary
     * account. A later change of established mobile identity is stronger.
     */
    public static function recentNativeLogin(int $userId, int $maxAge): bool
    {
        $token = self::currentToken($userId);
        if ($token === '') {
            return false;
        }
        $session = \WP_Session_Tokens::get_instance($userId)->get($token);
        $login = (int) ($session['login'] ?? 0);
        return $login <= time() && $login > time() - $maxAge;
    }

    public const LINK_FRESHNESS = 600;

    public static function qualifies(?int $maxAge = null): bool
    {
        if (!Lifecycle::ready()) { return false; }
        $userId = get_current_user_id();
        $token = self::currentToken($userId);
        if ($token === '') {
            return false;
        }
        try {
            global $wpdb;
            $identity = Identity::byUser($userId);
            if (!$identity) {
                return false;
            }
            $table = Schema::table('session_proofs');
            $previous = $wpdb->suppress_errors(true);
            try {
                $row = $wpdb->get_row($wpdb->prepare(
                    "SELECT revision,verified_at,retire_at FROM $table WHERE token_hash=%s AND user_id=%d",
                    self::hash($token), $userId
                ));
                return $row && (int) $row->retire_at > time() &&
                    ($maxAge === null || (int) $row->verified_at > time() - $maxAge) &&
                    hash_equals($identity->revision, $row->revision);
            } finally {
                $wpdb->suppress_errors($previous);
            }
        } catch (VerificationError $error) {
            return false;
        }
    }

    /** Internal only: caller has just consumed purpose-bound OTP proof. */
    public static function record(int $userId, #[\SensitiveParameter] string $token, object $identity): void
    {
        if (!Lifecycle::ready()) { throw new VerificationError('storage_unavailable'); }
        global $wpdb;
        $native = $token !== '' ? \WP_Session_Tokens::get_instance($userId)->get($token) : null;
        $current = Identity::byUser($userId);
        if (!$native || !$current || (int) $native['expiration'] <= time() ||
            (int) $identity->user_id !== $userId || !hash_equals($identity->revision, $current->revision)) {
            throw new VerificationError('invalid_context');
        }
        $table = Schema::table('session_proofs');
        $previous = $wpdb->suppress_errors(true);
        try {
            $result = $wpdb->query($wpdb->prepare(
                "INSERT INTO $table (token_hash,user_id,revision,verified_at,retire_at) VALUES (%s,%d,%s,%d,%d)
                 ON DUPLICATE KEY UPDATE revision=VALUES(revision),verified_at=VALUES(verified_at),retire_at=VALUES(retire_at)",
                self::hash($token), $userId, $identity->revision, time(), (int) $native['expiration']
            ));
            if ($result === false) {
                throw new VerificationError('storage_unavailable');
            }
        } finally {
            $wpdb->suppress_errors($previous);
        }
    }

    public static function forget(#[\SensitiveParameter] string $token): void
    {
        if ($token === '' || !Lifecycle::ready()) {
            return;
        }
        global $wpdb;
        $wpdb->delete(Schema::table('session_proofs'), ['token_hash' => self::hash($token)], ['%s']);
    }

    public static function logout(): void
    {
        self::forget(wp_get_session_token());
    }
}
