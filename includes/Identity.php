<?php
namespace ACh\WPAccounts;

defined('ABSPATH') || exit;

/**
 * Owns unique mobile reservations and bindings, never native user creation.
 * A NULL user_id is an incomplete registration, not an available phone.
 */
final class Identity
{
    public static function byMobile(string $mobile): ?object
    {
        return self::find('mobile', Mobile::normalize($mobile));
    }

    public static function byUser(int $userId): ?object
    {
        return $userId > 0 ? self::find('user_id', $userId) : null;
    }

    private static function find(string $field, int|string $value): ?object
    {
        global $wpdb;
        $table = Schema::table('identities');
        $previous = $wpdb->suppress_errors(true);
        try {
            $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE $field=%s", (string) $value));
            if ($wpdb->last_error !== '') {
                throw new VerificationError('storage_unavailable');
            }
            return $row;
        } finally {
            $wpdb->suppress_errors($previous);
        }
    }

    /** Stable snapshot of a binding, including the absence of a binding. */
    public static function revision(?object $identity): string
    {
        return $identity === null ? 'absent' : (string) $identity->revision;
    }

    private static function write(string $sql): int
    {
        global $wpdb;
        $previous = $wpdb->suppress_errors(true);
        try {
            $result = $wpdb->query($sql);
            if ($result === false) {
                throw new VerificationError('identity_conflict');
            }
            return (int) $result;
        } finally {
            $wpdb->suppress_errors($previous);
        }
    }

    /**
     * Claim a durable unique slot BEFORE native registration hooks run.
     * Never repeat a native account creation after an uncertain interruption.
     */
    public static function reserveRegistration(string $mobile): object
    {
        global $wpdb;
        $mobile = Mobile::normalize($mobile);
        $revision = bin2hex(random_bytes(16));
        $login = 'mobile_' . bin2hex(random_bytes(16));
        $table = Schema::table('identities');
        self::write($wpdb->prepare(
            "INSERT INTO $table (mobile,user_id,revision,verified_at,registration_login)
             VALUES (%s,NULL,%s,0,%s)",
            $mobile, $revision, $login
        ));
        return (object) ['mobile' => $mobile, 'revision' => $revision, 'registration_login' => $login];
    }

    /** Durable fence: an operator cannot release a reservation after this. */
    public static function markRegistrationStarted(object $reservation): void
    {
        global $wpdb;
        $table = Schema::table('identities');
        if (self::write($wpdb->prepare(
            "UPDATE $table SET registration_started=1 WHERE mobile=%s AND revision=%s AND registration_login=%s
             AND user_id IS NULL AND registration_started=0",
            $reservation->mobile,$reservation->revision,$reservation->registration_login
        ))!==1) {
            throw new VerificationError('registration_pending');
        }
    }

    public static function finishRegistration(object $reservation, int $userId): object
    {
        global $wpdb;
        $table = Schema::table('identities');
        $user = get_userdata($userId);
        if (!$user || !AccountPolicy::ordinary($user) || $user->user_email!=='' || $user->user_login !== $reservation->registration_login ||
            get_user_meta($userId, '_ach_accounts_registration', true) !== $reservation->revision) {
            throw new VerificationError('registration_pending');
        }
        $revision = bin2hex(random_bytes(16));
        $changed = self::write($wpdb->prepare(
            "UPDATE $table SET user_id=%d,revision=%s,verified_at=%d,registration_login='',registration_started=0
             WHERE mobile=%s AND revision=%s AND registration_login=%s AND user_id IS NULL AND registration_started=1",
            $userId, $revision, time(), $reservation->mobile, $reservation->revision, $reservation->registration_login
        ));
        if ($changed !== 1) {
            throw new VerificationError('identity_conflict');
        }
        $identity = self::byUser($userId);
        if (!$identity || $identity->revision !== $revision) {
            throw new VerificationError('identity_conflict');
        }
        return $identity;
    }

    /** Release a reservation only before any native user creation has started. */
    public static function abandonUnstartedRegistration(object $reservation): bool
    {
        global $wpdb;
        $table = Schema::table('identities');
        return self::write($wpdb->prepare(
            "DELETE FROM $table WHERE mobile=%s AND revision=%s AND registration_login=%s AND user_id IS NULL AND registration_started=0",
            $reservation->mobile, $reservation->revision, $reservation->registration_login
        ))===1;
    }

    /**
     * Bind a freshly proven phone to the authenticated account. A changed
     * revision or another user's (including a pending) mobile loses the CAS.
     */
    public static function bind(int $userId, string $mobile, string $expectedRevision): object
    {
        global $wpdb;
        $mobile = Mobile::normalize($mobile);
        if ($userId < 1 || !get_userdata($userId)) {
            throw new VerificationError('invalid_context');
        }
        $table = Schema::table('identities');
        $revision = bin2hex(random_bytes(16));
        if ($expectedRevision === 'absent') {
            $changed = self::write($wpdb->prepare(
                "INSERT INTO $table (mobile,user_id,revision,verified_at) VALUES (%s,%d,%s,%d)",
                $mobile, $userId, $revision, time()
            ));
        } else {
            $changed = self::write($wpdb->prepare(
                "UPDATE $table SET mobile=%s,revision=%s,verified_at=%d,source='otp',source_ref=''
                 WHERE user_id=%d AND revision=%s",
                $mobile, $revision, time(), $userId, $expectedRevision
            ));
        }
        if ($changed !== 1) {
            throw new VerificationError('identity_conflict');
        }
        $identity = self::byUser($userId);
        if (!$identity || $identity->revision !== $revision) {
            throw new VerificationError('identity_conflict');
        }
        return $identity;
    }

    /**
     * A reviewed legacy mapping can reserve the existing account without
     * claiming possession. Record the first actual local OTP on that exact
     * binding; preserve import provenance and never rotate its revision here.
     * Internal caller must have consumed OTP proof for this binding.
     */
    public static function confirmPossession(object $identity): void
    {
        if ((int)$identity->verified_at > 0) { return; }
        global $wpdb;
        $table = Schema::table('identities');
        $changed = self::write($wpdb->prepare(
            "UPDATE $table SET verified_at=%d WHERE user_id=%d AND mobile=%s AND revision=%s AND verified_at=0",
            time(), (int)$identity->user_id, $identity->mobile, $identity->revision
        ));
        if ($changed === 1) { return; }
        $current = self::byUser((int)$identity->user_id);
        // Another valid OTP may have confirmed the same binding concurrently.
        if (!$current || $current->revision !== $identity->revision ||
            $current->mobile !== $identity->mobile || (int)$current->verified_at <= 0) {
            throw new VerificationError('identity_changed');
        }
    }

    /** Only the explicit reviewed migration service may call this. */
    public static function importReviewedMapping(int $userId,string $mobile,string $digest): void
    {
        global $wpdb;
        $mobile = Mobile::normalize($mobile);
        if (!Lifecycle::ready() || $userId<1 ||
            !preg_match('/\\A[0-9a-f]{64}\\z/D',$digest)) {
            throw new VerificationError('invalid_context');
        }
        $user = get_userdata($userId);
        if (!$user || !AccountPolicy::ordinary($user)) {
            throw new VerificationError('identity_conflict');
        }
        $table = Schema::table('identities');
        $revision = bin2hex(random_bytes(16));
        if (self::write($wpdb->prepare(
            "INSERT INTO $table (mobile,user_id,revision,verified_at,source,source_ref)
             SELECT %s,ID,%s,0,'digits',%s FROM $wpdb->users WHERE ID=%d",
            $mobile,$revision,$digest,$userId
        ))!==1) {
            throw new VerificationError('identity_conflict');
        }
    }

}
