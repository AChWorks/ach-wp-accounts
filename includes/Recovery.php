<?php
namespace ACh\WPAccounts;

defined('ABSPATH') || exit;

/**
 * Recovery credentials for phone-created accounts only. Native email accounts
 * keep their existing WordPress/WooCommerce registration and profile policy.
 * Never store an unconfirmed address in wp_users.user_email, even temporarily.
 */
final class EmailRecovery
{
    private const MARKER = '_ach_accounts_registration';
    private const VERIFIED = '_ach_accounts_verified_email';
    private const PENDING = '_new_email'; // Native WP profile confirmation record.

    public static function managed(int $userId): bool
    {
        return $userId > 0 && (bool) get_user_meta($userId, self::MARKER, true);
    }

    private static function fingerprint(string $email): string
    {
        return hash_hmac('sha256', strtolower(trim($email)), wp_salt('auth'));
    }

    public static function allow(mixed $allow, int $userId): mixed
    {
        if ($allow !== true || !self::managed($userId)) {
            return $allow;
        }
        $user = get_userdata($userId);
        $saved = get_user_meta($userId, self::VERIFIED, true);
        return $user && is_email($user->user_email) && is_string($saved) &&
            hash_equals($saved, self::fingerprint($user->user_email));
    }

    private static function requireAuthority(): \WP_User
    {
        $id = get_current_user_id();
        $user = get_userdata($id);
        if (!self::managed($id) || !$user instanceof \WP_User || !AccountPolicy::ordinary($user) ||
            !SessionProof::qualifies(SessionProof::LINK_FRESHNESS)) {
            throw new VerificationError('reauthentication_required');
        }
        return $user;
    }

    /**
     * Request the native WordPress confirmation link. We use the existing core
     * profile confirmation record/handler, with a bounded lifetime and identity
     * revision for requests from this form, rather than an email OTP/login route.
     */
    public static function request(string $email): array
    {
        $user = self::requireAuthority();
        $email = trim($email);
        if (!is_email($email) || sanitize_email($email) !== $email) {
            throw new VerificationError('invalid_email');
        }
        $owner = email_exists($email);
        if ($owner && (int) $owner !== $user->ID) {
            throw new VerificationError('email_unavailable');
        }
        if ($user->user_email === $email && self::allow(true, $user->ID)) {
            return ['outcome' => 'already_confirmed'];
        }
        AccountPolicy::reserveAccountAction('recovery_email', $user->ID, 5);
        $identity = Identity::byUser($user->ID);
        if (!$identity) {
            throw new VerificationError('invalid_context');
        }
        $record = [
            'hash' => bin2hex(random_bytes(32)),
            'newemail' => $email,
            'ach_revision' => $identity->revision,
            'ach_previous_email' => $user->user_email,
            'ach_expires' => time() + DAY_IN_SECONDS,
        ];
        if (update_user_meta($user->ID, self::PENDING, $record) === false) {
            throw new VerificationError('storage_unavailable');
        }
        $link = add_query_arg('newuseremail', $record['hash'], admin_url('profile.php', 'https'));
        $name = wp_specialchars_decode(get_option('blogname'), ENT_QUOTES);
        $subject = sprintf(__('Confirm your recovery email for %s', 'ach-wp-accounts'), $name);
        $message = sprintf(
            /* translators: %s: native WordPress email confirmation link. */
            __("To confirm this email address for your account, open the link below while signed in. The link expires in 24 hours.\n\n%s\n\nIf you did not request this change, ignore this message.", 'ach-wp-accounts'),
            $link
        );
        if (!wp_mail($email, $subject, $message)) {
            // The mail transport may have accepted a message before reporting
            // failure. Retain the pending record and never automatically retry.
            throw new VerificationError('email_send_failed');
        }
        return ['outcome' => 'confirmation_requested'];
    }

    /** Current user + matching native confirmation record, never arbitrary GET. */
    private static function pendingConfirmation(int $userId, ?string $previousEmail = null): ?array
    {
        global $pagenow;
        $token = $_GET['newuseremail'] ?? null;
        if (!self::managed($userId) || get_current_user_id() !== $userId ||
            ($pagenow !== 'profile.php' && !(defined('IS_PROFILE_PAGE') && IS_PROFILE_PAGE)) ||
            !is_string($token) || SessionProof::currentToken($userId) === '') {
            return null;
        }
        $record = get_user_meta($userId, self::PENDING, true);
        if (!is_array($record) || !is_string($record['hash'] ?? null) ||
            !is_string($record['newemail'] ?? null) || !is_email($record['newemail']) ||
            !hash_equals($record['hash'], wp_unslash($token))) {
            return null;
        }
        if (isset($record['ach_expires'])) {
            $identity = Identity::byUser($userId);
            $user = get_userdata($userId);
            if (!$identity || !$user || (int)$record['ach_expires'] <= time() ||
                ($record['ach_revision'] ?? '') !== $identity->revision ||
                ($record['ach_previous_email'] ?? null) !== ($previousEmail ?? $user->user_email)) {
                return null;
            }
        }
        return $record;
    }

    /** WC normally redirects customers away from wp-admin; exempt only a valid self-confirmation. */
    public static function allowNativeConfirmation(bool $prevent): bool
    {
        return $prevent && self::pendingConfirmation(get_current_user_id()) !== null ? false : $prevent;
    }

    public static function rejectInvalidConfirmation(): void
    {
        global $pagenow;
        $id = get_current_user_id();
        if ($pagenow === 'profile.php' && isset($_GET['newuseremail']) && self::managed($id)) {
            nocache_headers();
            if (!headers_sent()) {
                header('Referrer-Policy: no-referrer', true);
            }
            if (self::pendingConfirmation($id) === null) {
                wp_die(esc_html__('This email confirmation link is invalid or expired. Return to your account and request a new link.', 'ach-wp-accounts'), '', ['response' => 400, 'back_link' => true]);
            }
        }
    }

    /**
     * Native account email is a password recovery credential. Block all
     * unconfirmed writes for managed accounts at the native storage boundary.
     * wp_insert_user treats an empty data array as a WP_Error.
     */
    public static function guardEmail(array $data, bool $update, ?int $userId, array $userdata): array
    {
        if (!$update) {
            return isset($userdata['meta_input'][self::MARKER]) && ($data['user_email'] ?? '') !== '' ? [] : $data;
        }
        if (!$userId || !self::managed($userId)) {
            return $data;
        }
        $old = get_userdata($userId);
        $email = $data['user_email'] ?? '';
        if (!$old) {
            return [];
        }
        if ($email === $old->user_email || $email === '') {
            return $data;
        }
        $pending = self::pendingConfirmation($userId);
        return $pending && $email === $pending['newemail'] ? $data : [];
    }

    public static function profileUpdated(int $userId, \WP_User $old): void
    {
        $user = get_userdata($userId);
        if (!$user || !self::managed($userId) || $old->user_email === $user->user_email) {
            return;
        }
        delete_user_meta($userId, self::VERIFIED);
        // Core consumes the pending confirmation after wp_update_user returns.
        // At this point the previous-email snapshot is in $old, not the user row.
        $pending = self::pendingConfirmation($userId, $old->user_email);
        if ($pending && $user->user_email !== '' && $pending['newemail'] === $user->user_email) {
            update_user_meta($userId, self::VERIFIED, self::fingerprint($user->user_email));
        }
    }

    public static function optionalEmail(array $fields): array
    {
        if (self::managed(get_current_user_id())) {
            unset($fields['account_email']);
        }
        return $fields;
    }

    public static function validateCommerceProfile(\WP_Error $errors, object $user): void
    {
        $old = get_userdata((int)$user->ID);
        if ($old && self::managed($old->ID) && isset($user->user_email) &&
            $user->user_email !== $old->user_email) {
            $errors->add('ach_accounts_confirm_email', __('Use recovery options to add or change your account email.', 'ach-wp-accounts'));
        }
    }

    /** Set an optional native password with fresh possession proof. */
    public static function password(#[\SensitiveParameter] string $password, #[\SensitiveParameter] string $confirmation): array
    {
        $user = self::requireAuthority();
        // The optional password is a single-factor credential: use a meaningful
        // length floor without composition restrictions (native login stays intact).
        if (preg_match_all('/./us', $password) < 15 || $password !== $confirmation ||
            strlen($password) > 4096 || trim($password) !== $password) {
            throw new VerificationError('invalid_password');
        }
        $errors = new \WP_Error();
        do_action('validate_password_reset', $errors, $user);
        if ($errors->has_errors()) {
            throw new VerificationError('invalid_password');
        }
        $identity = Identity::byUser($user->ID);
        $token = SessionProof::currentToken($user->ID);
        if (!$identity || $token === '') {
            throw new VerificationError('invalid_context');
        }
        // Native wp_update_user refreshes the current auth cookie and clears
        // the previous proof as part of wp_clear_auth_cookie.
        $result = wp_update_user(['ID' => $user->ID, 'user_pass' => $password]);
        if (is_wp_error($result) || (int)$result !== $user->ID ||
            !wp_check_password($password, get_userdata($user->ID)->user_pass, $user->ID)) {
            throw new VerificationError('password_update_failed');
        }
        try {
            SessionProof::record($user->ID, $token, $identity);
        } catch (VerificationError $error) {
            // The native password is already saved. Do not misreport the
            // completed credential change as failed or repeat it automatically.
            return ['outcome' => 'password_saved', 'session_qualified' => false];
        }
        return ['outcome' => 'password_saved', 'session_qualified' => true];
    }

    public static function hooks(): void
    {
        add_filter('allow_password_reset', [self::class, 'allow'], PHP_INT_MAX, 2);
        add_filter('wp_pre_insert_user_data', [self::class, 'guardEmail'], PHP_INT_MAX, 4);
        add_action('profile_update', [self::class, 'profileUpdated'], 10, 2);
        add_action('admin_init', [self::class, 'rejectInvalidConfirmation'], 0);
        add_filter('woocommerce_prevent_admin_access', [self::class, 'allowNativeConfirmation'], PHP_INT_MAX);
        add_filter('woocommerce_save_account_details_required_fields', [self::class, 'optionalEmail']);
        add_action('woocommerce_save_account_details_errors', [self::class, 'validateCommerceProfile'], 10, 2);
    }
}
