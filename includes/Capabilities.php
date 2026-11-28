<?php
namespace ACh\WPAccounts;

defined('ABSPATH') || exit;

/** Effective public methods; site policy remains separate from transient delivery health. */
final class Capabilities
{
    public static function emailSignup(string $context, bool $nativePolicy = true, ?bool $generated = null): bool
    {
        if (!NativeAccess::emailAllowed()) { return false; }
        // Core and WC generated-password registration use the native reset
        // key to set the first password. Do not create an inaccessible account.
        // Presentation knows the My Account form contract. Checkout may
        // use a different native handler; explicit credentials are checked
        // again at the executing registration boundary.
        $generated ??= $context!=='account' || get_option('woocommerce_registration_generate_password')!=='no';
        if ($generated && !Settings::flag('allow_email_password_reset')) { return false; }
        if (!NativeAccess::passwordAllowed() &&
            !(BrowserApi::enabled() && Settings::flag('allow_first_link'))) { return false; }
        if (!$nativePolicy) { return true; }
        return match($context) {
            'wp' => !is_multisite() && (bool)get_option('users_can_register'),
            'account' => function_exists('WC') && get_option('woocommerce_enable_myaccount_registration')==='yes',
            'checkout' => function_exists('WC') && get_option('woocommerce_enable_signup_and_login_from_checkout')==='yes',
            default => false,
        };
    }

    public static function forContext(string $context): array
    {
        $mobile = BrowserApi::enabled();
        return [
            'mobile_login'=>$mobile,
            'mobile_registration'=>$mobile && AccountPolicy::registrationAllowed($context),
            'password_login'=>NativeAccess::passwordAllowed(),
            'email_login'=>NativeAccess::passwordAllowed() && Settings::flag('allow_email_login'),
            'email_registration'=>self::emailSignup($context),
            'first_link'=>$mobile && Settings::flag('allow_first_link'),
            'email_reset'=>Settings::flag('allow_email_password_reset'),
            'mobile_reset'=>$mobile && Settings::flag('allow_mobile_password_reset'),
            'email_enrollment'=>$mobile && Settings::flag('allow_recovery_email_enrollment'),
            'password_enrollment'=>$mobile && Settings::flag('allow_recovery_password_enrollment'),
        ];
    }

    /** Only approved native name fields; no email or credential data in a phone-only signup. */
    public static function registrationFields(): array
    {
        return array_filter(Settings::fields(),static fn($mode)=>$mode!=='off');
    }

    public static function validateNames(array $values): array
    {
        $names = [];
        foreach (self::registrationFields() as $name=>$mode) {
            $value = $values[$name] ?? '';
            if (!is_string($value) || strlen($value)>400) {
                throw new VerificationError('invalid_profile_fields');
            }
            $value = sanitize_text_field($value);
            if ($mode==='required' && trim($value)==='') {
                throw new VerificationError('invalid_profile_fields');
            }
            $names[$name] = $value;
        }
        return $names;
    }
}
