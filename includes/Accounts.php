<?php
namespace ACh\WPAccounts;

defined('ABSPATH') || exit;

final class AccountPolicy
{
    /** Deny unknown or elevated role/capability combinations by default. */
    public static function ordinary(\WP_User $user): bool
    {
        if (!$user->exists() || !$user->roles || array_diff($user->roles, ['subscriber', 'customer'])) {
            return false;
        }
        $allowed = ['read', 'level_0', 'subscriber', 'customer'];
        return !array_diff(array_keys(array_filter($user->allcaps)), $allowed);
    }

    public static function registrationRole(string $context): string
    {
        if (is_multisite() || !BrowserApi::flag('ach_wp_accounts_allow_registration')) {
            throw new VerificationError('registration_disabled');
        }
        if ($context === 'wp' && get_option('users_can_register')) {
            $role = (string) get_option('default_role', 'subscriber');
        } elseif (function_exists('WC') && (
            ($context === 'account' && get_option('woocommerce_enable_myaccount_registration') === 'yes') ||
            ($context === 'checkout' && WC()->cart && !WC()->cart->is_empty() &&
                apply_filters('woocommerce_checkout_registration_enabled', get_option('woocommerce_enable_signup_and_login_from_checkout') === 'yes'))
        )) {
            $role = 'customer';
        } else {
            throw new VerificationError('registration_disabled');
        }
        $definition = get_role($role);
        if (!$definition || !in_array($role, ['subscriber','customer'], true) ||
            array_diff(array_keys(array_filter($definition->capabilities)), ['read','level_0'])) {
            throw new VerificationError('registration_disabled');
        }
        return $role;
    }

    public static function authenticateMobile(\WP_User $user): \WP_User
    {
        if (!self::ordinary($user)) {
            throw new VerificationError('native_login_required');
        }
        // Core's password handlers accept an already-proven WP_User. Preserve
        // both account-policy hooks and the authenticate filter chain; any
        // denied/replaced user fails closed. Never fabricate a password.
        $login = $user->user_login;
        $password = '';
        do_action_ref_array('wp_authenticate', [&$login, &$password]);
        if ($login !== $user->user_login || $password !== '') {
            throw new VerificationError('native_login_required');
        }
        $checked = apply_filters('wp_authenticate_user', $user, '');
        if (!$checked instanceof \WP_User || $checked->ID !== $user->ID) {
            throw new VerificationError('native_login_required');
        }
        $checked = apply_filters('authenticate', $checked, $user->user_login, '');
        $fresh = get_userdata($user->ID);
        if (!$checked instanceof \WP_User || $checked->ID !== $user->ID ||
            !$fresh instanceof \WP_User || !self::ordinary($fresh)) {
            throw new VerificationError('native_login_required');
        }
        return $fresh;
    }

    /** Bounded native reauthentication of the current authenticated account. */
    public static function reauthenticate(int $userId, #[\SensitiveParameter] string $password): void
    {
        global $wpdb;
        $user = get_userdata($userId);
        if (!$user || SessionProof::currentToken($userId) === '' || $password === '') {
            throw new VerificationError('reauthentication_required');
        }
        $hour = intdiv(time(), 3600) * 3600;
        $key = hash_hmac('sha256', "ach-wpa-reauth\0$userId\0$hour", wp_salt('auth'));
        $table = Schema::table('limits');
        $previous = $wpdb->suppress_errors(true);
        try {
            $created = $wpdb->query($wpdb->prepare(
                "INSERT INTO $table (bucket_key,retire_at) VALUES (%s,%d) ON DUPLICATE KEY UPDATE bucket_key=bucket_key",
                $key, $hour + 3600
            ));
            $reserved = $wpdb->query($wpdb->prepare("UPDATE $table SET hits=hits+1 WHERE bucket_key=%s AND hits<10", $key));
            if ($created === false || $reserved !== 1) {
                throw new VerificationError('reauthentication_unavailable');
            }
        } finally {
            $wpdb->suppress_errors($previous);
        }
        $verified = wp_authenticate($user->user_login, $password);
        if (!$verified instanceof \WP_User || $verified->ID !== $userId) {
            throw new VerificationError('reauthentication_required');
        }
    }
}

/**
 * Phone-only accounts do not grant password reset authority to an email
 * merely because a profile/checkout extension wrote it. Adopt the supported
 * native confirmation events; existing accounts' recovery is untouched.
 */
final class EmailRecovery
{
    private const MARKER = '_ach_accounts_registration';
    private const VERIFIED = '_ach_accounts_verified_email';

    private static function fingerprint(string $email): string
    {
        return hash_hmac('sha256', strtolower(trim($email)), wp_salt('auth'));
    }

    public static function allow(mixed $allow, int $userId): mixed
    {
        if ($allow !== true || !get_user_meta($userId, self::MARKER, true)) {
            return $allow;
        }
        $user = get_userdata($userId);
        $saved = get_user_meta($userId, self::VERIFIED, true);
        if (!$user || !is_email($user->user_email)) {
            return false;
        }
        if (is_string($saved) && hash_equals($saved, self::fingerprint($user->user_email))) {
            return true;
        }
        // WC 11+ owns its native verification state, including address changes.
        // This optional internal service is feature-detected, not a dependency.
        $service = 'Automattic\\WooCommerce\\Internal\\CustomerEmailVerification\\EmailVerificationService';
        if (function_exists('wc_get_container') && class_exists($service)) {
            try {
                $verification = wc_get_container()->get($service);
                return is_callable([$verification, 'is_verified']) && $verification->is_verified($userId) === true;
            } catch (\Throwable $error) {
                return false;
            }
        }
        return false;
    }

    private static function confirmed(int $userId): void
    {
        $user = get_userdata($userId);
        if ($user && get_user_meta($userId, self::MARKER, true) && is_email($user->user_email)) {
            update_user_meta($userId, self::VERIFIED, self::fingerprint($user->user_email));
        }
    }

    public static function profileUpdated(int $userId, \WP_User $old): void
    {
        $user = get_userdata($userId);
        if (!$user || !get_user_meta($userId, self::MARKER, true) || $old->user_email === $user->user_email) {
            return;
        }
        delete_user_meta($userId, self::VERIFIED);
        // Core profile.php consumes this exact native email-confirmation record
        // after wp_update_user returns. A plain email update is not confirmation.
        if (defined('IS_PROFILE_PAGE') && IS_PROFILE_PAGE && get_current_user_id() === $userId &&
            is_string($_GET['newuseremail'] ?? null)) {
            $pending = get_user_meta($userId, '_new_email', true);
            if (is_array($pending) && is_string($pending['hash'] ?? null) &&
                ($pending['newemail'] ?? null) === $user->user_email &&
                hash_equals($pending['hash'], wp_unslash($_GET['newuseremail']))) {
                self::confirmed($userId);
            }
        }
    }

    public static function hooks(): void
    {
        add_filter('allow_password_reset', [self::class, 'allow'], PHP_INT_MAX, 2);
        add_action('profile_update', [self::class, 'profileUpdated'], 10, 2);
    }
}

/** Internal native account operations, called only after consuming OTP proof. */
final class Accounts
{
    public static function register(string $mobile, string $context): object
    {
        $role = AccountPolicy::registrationRole($context);
        $reservation = Identity::reserveRegistration($mobile);
        $login = $reservation->registration_login;
        $data = [
            'user_login' => $login,
            'user_pass' => wp_generate_password(48, true, true),
            'user_email' => '',
            'role' => $role,
            'display_name' => __('Customer', 'ach-wp-accounts'),
            'meta_input' => ['_ach_accounts_registration' => $reservation->revision],
        ];
        $commerce = $context !== 'wp';
        try {
            $errors = new \WP_Error();
            if ($commerce) {
                do_action('woocommerce_register_post', $login, '', $errors);
                $errors = apply_filters('woocommerce_registration_errors', $errors, $login, '');
                $data = apply_filters('woocommerce_new_customer_data', $data + [
                    'first_name' => '', 'last_name' => '', 'source' => $context,
                ]);
            } else {
                do_action('register_post', $login, '', $errors);
                $errors = apply_filters('registration_errors', $errors, $login, '');
            }
            // Do not allow native registration extensions to accidentally change
            // the immutable phone-only identity or supply a known password.
            if (!$errors instanceof \WP_Error || $errors->has_errors() ||
                !is_array($data) || ($data['user_login'] ?? '') !== $login ||
                ($data['role'] ?? '') !== $role || ($data['user_email'] ?? null) !== '' ||
                !is_array($data['meta_input'] ?? null) ||
                ($data['meta_input']['_ach_accounts_registration'] ?? '') !== $reservation->revision ||
                username_exists($login) || !validate_username($login)) {
                throw new VerificationError('registration_denied');
            }
            unset($data['ID']);
            $data['user_pass'] = wp_generate_password(48, true, true);
        } catch (\Throwable $error) {
            Identity::abandonUnstartedRegistration($reservation);
            throw new VerificationError('registration_denied');
        }

        // Once the native call starts its hooks may create other durable state.
        // An uncertain outcome must retain the reservation, not create again.
        try {
            $userId = wp_insert_user($data);
            if (is_wp_error($userId) || !is_int($userId)) {
                throw new VerificationError('registration_pending');
            }
            $user = get_userdata($userId);
            if (!$user || $user->user_email !== '' || !AccountPolicy::ordinary($user)) {
                throw new VerificationError('registration_pending');
            }
            if ($commerce) {
                // Preserve WC customer lifecycle without its mandatory-email
                // constructor or claiming guest orders by unverified email.
                $publicData = $data;
                unset($publicData['user_pass'], $publicData['meta_input']);
                do_action('woocommerce_created_customer', $userId, $publicData, false);
            }
            $user = get_userdata($userId);
            if (!$user || $user->user_email !== '' || !AccountPolicy::ordinary($user)) {
                throw new VerificationError('registration_pending');
            }
            return Identity::finishRegistration($reservation, $userId);
        } catch (\Throwable $error) {
            throw new VerificationError('registration_pending');
        }
    }

    public static function login(object $identity): int
    {
        if (get_current_user_id() !== 0 || !$identity->user_id) {
            throw new VerificationError('invalid_context');
        }
        $user = get_userdata((int) $identity->user_id);
        if (!$user) {
            throw new VerificationError('native_login_required');
        }
        $user = AccountPolicy::authenticateMobile($user);
        $token = '';
        $allowed = false;
        $capture = static function ($cookie, $expire, $expiration, $userId, $scheme, $value) use ($identity, $user, &$token): void {
            if ((int) $userId !== $user->ID) {
                throw new VerificationError('invalid_context');
            }
            $token = $value;
            SessionProof::record($user->ID, $token, $identity);
        };
        $observe = static function ($send, $expire, $expiration, $userId, $scheme, $value) use (&$allowed, &$token, $user) {
            if ((int) $userId === $user->ID && $value === $token && $token !== '') {
                $allowed = (bool) $send;
            }
            return $send;
        };
        add_action('set_logged_in_cookie', $capture, PHP_INT_MAX, 6);
        add_filter('send_auth_cookies', $observe, PHP_INT_MAX, 6);
        try {
            // Use native cookie/session construction and honor extensions'
            // cookie-delivery policy. Browser transport requires HTTPS.
            wp_set_auth_cookie($user->ID, false, true);
            if (!$allowed || $token === '') {
                throw new VerificationError('native_login_required');
            }
            wp_set_current_user($user->ID);
            do_action('wp_login', $user->user_login, $user);
            if (function_exists('WC') && WC()->session && is_callable([WC()->session, 'init_session_cookie'])) {
                WC()->session->init_session_cookie();
            }
        } catch (\Throwable $error) {
            if ($token !== '') {
                \WP_Session_Tokens::get_instance($user->ID)->destroy($token);
                SessionProof::forget($token);
            }
            wp_set_current_user(0);
            throw new VerificationError('native_login_required');
        } finally {
            remove_action('set_logged_in_cookie', $capture, PHP_INT_MAX);
            remove_filter('send_auth_cookies', $observe, PHP_INT_MAX);
        }
        return $user->ID;
    }
}
