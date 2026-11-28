<?php
namespace ACh\WPAccounts;

defined('ABSPATH') || exit;

/** Public native account-method policy, independent of the presentation adapter. */
final class NativeAccess
{
    private static bool $reauthenticating = false;

    public static function hooks(): void
    {
        add_filter('authenticate', [self::class, 'password'], PHP_INT_MAX, 3);
        add_filter('registration_errors', [self::class, 'registration'], PHP_INT_MAX, 3);
        add_filter('woocommerce_registration_errors', [self::class, 'registration'], PHP_INT_MAX, 3);
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
        if (self::$reauthenticating || self::passwordAllowed() || !is_string($password) || $password === '' ||
            !$result instanceof \WP_User || !AccountPolicy::ordinary($result) ||
            (defined('XMLRPC_REQUEST') && XMLRPC_REQUEST) ||
            (defined('WP_CLI') && WP_CLI)) {
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
            return wp_authenticate($login, $password);
        } finally {
            self::$reauthenticating = $previous;
        }
    }

    public static function registration(mixed $errors, string $login, string $email): mixed
    {
        // The shared mobile registration engine calls the native validation
        // hooks with an empty email. Core/WC themselves require a valid email
        // for native public registration, and do not use this exception.
        if ($errors instanceof \WP_Error && $email !== '' && !self::emailAllowed()) {
            $errors->add('ach_accounts_email_registration_disabled',
                __('Registration with email is disabled. Register with your mobile number.', 'ach-wp-accounts'));
        }
        return $errors;
    }

    public static function commerceRegistration(mixed $errors, string $login, #[\SensitiveParameter] string $password, string $email): mixed
    {
        return self::registration($errors, $login, $email);
    }
}
