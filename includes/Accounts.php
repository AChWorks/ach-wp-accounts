<?php
namespace ACh\WPAccounts;

defined('ABSPATH') || exit;

final class AccountPolicy
{
    public static function accountOperator(): bool
    {
        return current_user_can('manage_options') && current_user_can('edit_users');
    }

    public static function requireAccountOperator(): void
    {
        if (!self::accountOperator()) {
            throw new SettingsError('','forbidden');
        }
    }

    /** Deny unknown roles, stored elevation and effective site-wide privilege. */
    public static function ordinary(\WP_User $user): bool
    {
        if (!$user->exists() || !$user->roles || array_diff($user->roles, ['subscriber', 'customer'])) {
            return false;
        }
        $allowed = ['read', 'level_0', 'subscriber', 'customer'];
        if (array_diff(array_keys(array_filter($user->allcaps)), $allowed)) {
            return false;
        }

        // WordPress has no exhaustive capability registry. Probe the actual
        // site role catalog plus Core's sensitive site-wide administration
        // capabilities even if an extension removed them from every role.
        // Object-scoped customer operations (e.g. pay_for_order) are not
        // administrative capabilities and must not be probed without context.
        $known = array_fill_keys([
            'manage_options','edit_users','create_users','promote_users','delete_users','remove_users','list_users',
            'install_plugins','activate_plugins','update_plugins','delete_plugins','edit_plugins',
            'install_themes','switch_themes','update_themes','delete_themes','edit_themes','edit_theme_options',
            'update_core','edit_files','unfiltered_html','unfiltered_upload','import','export',
            'upload_plugins','upload_themes','install_languages','update_languages','deactivate_plugins',
            'update_php','update_https','manage_privacy_options','export_others_personal_data','erase_others_personal_data',
            'edit_posts','publish_posts','edit_others_posts','delete_others_posts','manage_categories','moderate_comments',
            'manage_woocommerce','view_woocommerce_reports',
        ], true);
        foreach (wp_roles()->roles as $role) {
            foreach ($role['capabilities'] as $cap => $_grant) {
                $known[$cap] = true;
            }
        }
        // Role catalogs can contain post-type meta capabilities as well as
        // primitive grants (WooCommerce does this). Do not ask questions
        // that native authorization requires an object to answer.
        $objectCaps = ['edit_post','read_post','delete_post','edit_page','read_page','delete_page','publish_post',
            'edit_comment','edit_term','delete_term','assign_term','edit_user','remove_user',
            'pay_for_order','view_order','cancel_order','order_again'];
        foreach (['post','comment','term','user'] as $kind) {
            foreach (['edit','add','delete'] as $action) {
                $objectCaps[] = $action.'_'.$kind.'_meta';
            }
        }
        foreach (get_post_types([], 'objects') as $type) {
            foreach (['edit_post','read_post','delete_post'] as $action) {
                if (isset($type->cap->$action)) { $objectCaps[] = $type->cap->$action; }
            }
        }
        foreach ([...$allowed,'exist','do_not_allow',...$objectCaps] as $cap) { unset($known[$cap]); }

        // Observe effective grants produced during native has_cap checks,
        // including dynamically introduced names absent from the role catalog.
        // Do not modify the capabilities, invoke filters with invented context,
        // cache a result across authorization contexts, or ban extensions.
        $elevated = false;
        $probe = 'read';
        $observe = static function (array $caps, array $required, array $args, \WP_User $subject) use ($user,$allowed,&$elevated,&$probe): array {
            if ($subject->ID === $user->ID && ($args[0] ?? '') === $probe &&
                array_diff(array_keys(array_filter($caps)), [...$allowed,'exist','do_not_allow'])) {
                $elevated = true;
            }
            return $caps;
        };
        add_filter('user_has_cap',$observe,PHP_INT_MAX,4);
        try {
            $user->has_cap('read');
            if ($elevated) { return false; }
            foreach (array_keys($known) as $probe) {
                if ($user->has_cap($probe) || $elevated) { return false; }
            }
            return true;
        } catch (\Throwable $error) {
            // A failed authorization check must not confer SMS-only access.
            return false;
        } finally {
            remove_filter('user_has_cap',$observe,PHP_INT_MAX);
        }
    }

    public static function registrationRole(string $context): string
    {
        if (is_multisite() || !Settings::flag('allow_registration')) {
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

    public static function canLinkWithoutPassword(int $userId, ?object $identity): bool
    {
        if (SessionProof::qualifies(SessionProof::LINK_FRESHNESS)) {
            return true;
        }
        $user = get_userdata($userId);
        return $identity === null && $user instanceof \WP_User && self::ordinary($user) &&
            SessionProof::recentNativeLogin($userId, SessionProof::LINK_FRESHNESS);
    }

    public static function registrationAllowed(string $context): bool
    {
        try {
            self::registrationRole($context);
            return true;
        } catch (VerificationError $error) {
            return false;
        }
    }

    /** Per-account budget for authenticated credential/security operations. */
    public static function reserveAccountAction(string $action, int $userId, int $limit, string $errorCode = 'rate_limited'): void
    {
        global $wpdb;
        $hour = intdiv(time(), 3600) * 3600;
        $key = hash_hmac('sha256', "ach-wpa-account-action\0$action\0$userId\0$hour", wp_salt('auth'));
        $table = Schema::table('limits');
        $previous = $wpdb->suppress_errors(true);
        try {
            $created = $wpdb->query($wpdb->prepare(
                "INSERT INTO $table (bucket_key,retire_at) VALUES (%s,%d) ON DUPLICATE KEY UPDATE bucket_key=bucket_key",
                $key, $hour + 3600
            ));
            $reserved = $wpdb->query($wpdb->prepare("UPDATE $table SET hits=hits+1 WHERE bucket_key=%s AND hits<%d", $key, $limit));
            if ($created === false || $reserved !== 1) {
                throw new VerificationError($errorCode);
            }
        } finally {
            $wpdb->suppress_errors($previous);
        }
    }

    /** Bounded native reauthentication of the current authenticated account. */
    public static function reauthenticate(int $userId, #[\SensitiveParameter] string $password): void
    {
        $user = get_userdata($userId);
        if (!$user || SessionProof::currentToken($userId) === '' || $password === '') {
            throw new VerificationError('reauthentication_required');
        }
        self::reserveAccountAction('reauth', $userId, 10, 'reauthentication_unavailable');
        $verified = NativeAccess::reauthenticate($user->user_login, $password);
        if (!$verified instanceof \WP_User || $verified->ID !== $userId) {
            throw new VerificationError('reauthentication_required');
        }
    }
}

/** Internal native account operations, called only after consuming OTP proof. */
final class Accounts
{
    public static function register(string $mobile, string $context): object
    {
        $mobile = Mobile::normalize($mobile);
        return DatabaseLock::run('registration-'.$mobile,
            static fn()=>self::registerLocked($mobile,$context),'registration_pending');
    }

    private static function registerLocked(string $mobile,string $context): object
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
                $errors = apply_filters('woocommerce_process_registration_errors', $errors, $login, $data['user_pass'], '');
                if (!$errors instanceof \WP_Error) {
                    throw new VerificationError('registration_denied');
                }
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
            Identity::markRegistrationStarted($reservation);
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
            // Some native capability filters depend on the current actor.
            // Re-evaluate in the actual authenticated context; a rejected
            // cookie's native token is destroyed by the failure path below.
            if (!AccountPolicy::ordinary(wp_get_current_user())) {
                throw new VerificationError('native_login_required');
            }
            do_action('wp_login', $user->user_login, $user);
            $fresh = get_userdata($user->ID);
            if (!$fresh || get_current_user_id() !== $user->ID || !AccountPolicy::ordinary($fresh)) {
                throw new VerificationError('native_login_required');
            }
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
