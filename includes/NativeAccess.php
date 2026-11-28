<?php
namespace ACh\WPAccounts;

defined('ABSPATH') || exit;

/** Public native account-method policy, independent of the presentation adapter. */
final class NativeAccess
{
    private static bool $reauthenticating = false;
    private static int $applicationUser = 0;
    private static bool $mobileReset = false;

    public static function checkingMobileReset(): bool
    {
        return self::$mobileReset;
    }

    /** Check native policy without conflating the independent email channel. */
    public static function mobileResetAllowed(\WP_User $user): bool
    {
        $previous = self::$mobileReset;
        self::$mobileReset = true;
        try { return wp_is_password_reset_allowed_for_user($user)===true; }
        finally { self::$mobileReset = $previous; }
    }

    public static function emailResetAllowed(mixed $allow, int $id): mixed
    {
        if ($allow!==true || self::$mobileReset || Settings::flag('allow_email_password_reset') ||
            AccountPolicy::accountOperator()) { return $allow; }
        $user = get_userdata($id);
        return $user instanceof \WP_User && AccountPolicy::ordinary($user) ? false : $allow;
    }

    public static function validateNativeReset(\WP_Error $errors, mixed $user): void
    {
        // Native reset keys issued before a policy change must not bypass it.
        // Plugin mobile reset/enrollment and administrative profile changes
        // are distinct, explicitly authorized operations.
        $native = did_action('login_form_rp') || did_action('login_form_resetpass') ||
            isset($_POST['wc_reset_password'],$_POST['reset_key']);
        if ($native && $user instanceof \WP_User &&
            (self::emailResetAllowed(true,$user->ID)!==true || EmailRecovery::allow(true,$user->ID)!==true)) {
            $errors->add('ach_accounts_reset_disabled',__('Password reset by email is unavailable for this account.','ach-wp-accounts'));
        }
    }

    public static function emailIdentifierAllowed(string $login, \WP_User $user): bool
    {
        if (Settings::flag('allow_email_login') || !is_email($login)) { return true; }
        // An email-shaped exact native username is still a username.
        $byLogin = get_user_by('login',$login);
        return $byLogin instanceof \WP_User && $byLogin->ID===$user->ID;
    }

    public static function hooks(): void
    {
        add_filter('authenticate', static function ($result) {
            self::$applicationUser = 0;
            return $result;
        }, PHP_INT_MIN);
        add_action('application_password_did_authenticate', static function (\WP_User $user): void {
            self::$applicationUser = $user->ID;
        });
        add_filter('authenticate', [self::class, 'password'], PHP_INT_MAX, 3);
        add_filter('allow_password_reset',[self::class,'emailResetAllowed'],PHP_INT_MAX,2);
        add_action('validate_password_reset',[self::class,'validateNativeReset'],PHP_INT_MAX,2);
        add_filter('registration_errors', [self::class, 'registration'], PHP_INT_MAX, 3);
        add_filter('woocommerce_registration_errors', [self::class, 'customerRegistration'], PHP_INT_MAX, 3);
        add_filter('woocommerce_process_registration_errors', [self::class, 'commerceRegistration'], PHP_INT_MAX, 4);
    }

    public static function passwordAllowed(): bool
    {
        return Settings::flag('allow_password_login');
    }

    public static function emailAllowed(): bool
    {
        return Settings::flag('allow_email_registration');
    }

    /** Do not alter application-password, XML-RPC, CLI or existing-session authority. */
    public static function password(mixed $result, mixed $login, #[\SensitiveParameter] mixed $password): mixed
    {
        $machine = $result instanceof \WP_User && self::$applicationUser === $result->ID;
        self::$applicationUser = 0;
        if (self::$reauthenticating && $machine) {
            return new \WP_Error('reauthentication_required');
        }
        if ($machine || self::$reauthenticating || !is_string($password) || $password === '' ||
            !$result instanceof \WP_User || !AccountPolicy::ordinary($result) ||
            (defined('XMLRPC_REQUEST') && XMLRPC_REQUEST) ||
            (defined('WP_CLI') && WP_CLI)) {
            return $result;
        }
        if (self::passwordAllowed() && is_string($login) && self::emailIdentifierAllowed($login,$result)) {
            return $result;
        }
        return new \WP_Error('ach_accounts_password_disabled',
            __('Password sign-in is unavailable for this account. Use your verified mobile number or contact support.', 'ach-wp-accounts'));
    }

    /** This is proof of existing authenticated account authority, not a new public login. */
    public static function reauthenticate(string $login, #[\SensitiveParameter] string $password): \WP_User|\WP_Error
    {
        $previous = self::$reauthenticating;
        self::$reauthenticating = true;
        try {
            do_action_ref_array('wp_authenticate', [&$login, &$password]);
            return wp_authenticate($login, $password);
        } finally {
            self::$reauthenticating = $previous;
        }
    }

    public static function registration(mixed $errors, string $login, string $email, string $context = 'wp'): mixed
    {
        // The shared mobile registration engine calls the native validation
        // hooks with an empty email. Core/WC themselves require a valid email
        // for native public registration, and do not use this exception.
        if ($errors instanceof \WP_Error && $email !== '' && !Capabilities::emailSignup($context,false)) {
            $errors->add('ach_accounts_email_registration_disabled',
                __('Registration with email is disabled. Register with your mobile number.', 'ach-wp-accounts'));
        }
        return $errors;
    }

    public static function customerRegistration(mixed $errors, string $login, string $email): mixed
    {
        // Native authorized provisioning is not public account signup. Never
        // infer privilege from is_admin(), which also covers anonymous AJAX.
        if (current_user_can('create_users') ||
            (defined('REST_REQUEST') && REST_REQUEST && current_user_can('manage_woocommerce'))) {
            return $errors;
        }
        return self::registration($errors,$login,$email,'account');
    }

    public static function commerceRegistration(mixed $errors, string $login, #[\SensitiveParameter] string $password, string $email): mixed
    {
        return self::registration($errors, $login, $email,'account');
    }
}
