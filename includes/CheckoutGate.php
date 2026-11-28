<?php
namespace ACh\WPAccounts;

defined('ABSPATH') || exit;

/** Customer checkout policy, never a global payment or webhook interceptor. */
final class CheckoutGate
{
    private static bool $orderPayBlocked = false;
    private static bool $storeOrderPay = false;

    public static function enabled(): bool
    {
        return Settings::flag('checkout_required');
    }

    public static function matchesMobile(string $mobile): bool
    {
        try {
            $identity = Identity::byUser(get_current_user_id());
            return $identity && hash_equals($identity->mobile, Mobile::normalize($mobile));
        } catch (\InvalidArgumentException|VerificationError $error) {
            return false;
        }
    }

    public static function qualified(?string $mobile = null): bool
    {
        // Payment policy is separate from fresh OTP authority for sensitive
        // account changes. Only a valid native browser session and an actual
        // verified identity can use the no-repeat policy.
        if (!Lifecycle::ready()) { return false; }
        $userId = get_current_user_id();
        if (SessionProof::currentToken($userId) === '') { return false; }
        try {
            $identity = Identity::byUser($userId);
            if (!$identity || (int)$identity->verified_at <= 0) { return false; }
            if ($mobile !== null && $mobile !== '' && !hash_equals($identity->mobile, Mobile::normalize($mobile))) {
                return false;
            }
            return !Settings::flag('checkout_reverify') || SessionProof::qualifies();
        } catch (\InvalidArgumentException|VerificationError $error) {
            return false;
        }
    }

    public static function blocked(?string $mobile = null): bool
    {
        return self::enabled() && !self::qualified($mobile);
    }

    public static function message(): string
    {
        return __('Please sign in and verify your mobile number before continuing to payment.', 'ach-wp-accounts');
    }

    public static function classicValidation(array $data, \WP_Error $errors): void
    {
        if (self::blocked(is_string($data['billing_phone'] ?? null) ? $data['billing_phone'] : null)) {
            $errors->add('ach_wp_accounts_mobile_required', self::message());
        }
    }

    public static function classicBoundary(mixed $orderId = null, array $data = []): void
    {
        $order = is_numeric($orderId) ? wc_get_order((int)$orderId) : null;
        $mobile = is_string($data['billing_phone'] ?? null) ? $data['billing_phone'] : null;
        if (self::blocked($mobile) || ($order instanceof \WC_Order && self::blocked($order->get_billing_phone()))) {
            // Native WC_Checkout::process_checkout catches this before payment.
            throw new \Exception(self::message());
        }
    }

    public static function storeRequest(mixed $response, array $handler, \WP_REST_Request $request): mixed
    {
        if (is_wp_error($response) || $request->get_method() !== 'POST' ||
            !preg_match('~\A/wc/store/v[0-9]+/checkout(?:/([0-9]+))?/?\z~Di', $request->get_route(), $matches)) {
            return $response;
        }
        // Existing-order payment keeps historical billing contact data.
        // New checkout requires exact proof of the submitted mobile.
        self::$storeOrderPay = !empty($matches[1]);
        $address = $request->get_param('billing_address');
        $mobile = !self::$storeOrderPay && is_array($address) && is_string($address['phone'] ?? null)
            ? $address['phone'] : null;
        if (!self::blocked($mobile)) { return $response; }
        return new \WP_Error('ach_wp_accounts_mobile_required', self::message(), ['status' => 403]);
    }

    public static function storeBoundary(mixed $subject = null): void
    {
        $order = $subject instanceof \WC_Order ? $subject : ($subject->order ?? null);
        $mobile = $order instanceof \WC_Order && !self::$storeOrderPay
            ? $order->get_billing_phone() : null;
        if (self::blocked($mobile)) {
            throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException(
                'ach_wp_accounts_mobile_required', self::message(), 403
            );
        }
    }

    public static function endStoreRequest(mixed $response, array $handler, \WP_REST_Request $request): mixed
    {
        if (preg_match('~\A/wc/store/v[0-9]+/checkout(?:/[0-9]+)?/?\z~Di', $request->get_route())) {
            self::$storeOrderPay = false;
        }
        return $response;
    }

    public static function orderPay(): void
    {
        self::$orderPayBlocked = self::blocked();
        if (self::$orderPayBlocked) {
            // The native order-pay action executes outside its exception catch.
            // Add a notice AND remove all payable methods for this request only.
            wc_add_notice(self::message(), 'error');
        }
    }

    public static function gateways(array $gateways): array
    {
        return self::$orderPayBlocked ? [] : $gateways;
    }

    public static function hooks(): void
    {
        add_action('woocommerce_after_checkout_validation', [self::class, 'classicValidation'], PHP_INT_MAX, 2);
        add_action('woocommerce_checkout_order_processed', [self::class, 'classicBoundary'], PHP_INT_MIN, 2);
        add_filter('rest_request_before_callbacks', [self::class, 'storeRequest'], PHP_INT_MIN, 3);
        add_filter('rest_request_after_callbacks', [self::class, 'endStoreRequest'], PHP_INT_MAX, 3);
        add_action('woocommerce_store_api_checkout_order_processed', [self::class, 'storeBoundary'], PHP_INT_MIN);
        add_action('woocommerce_rest_checkout_process_payment_with_context', [self::class, 'storeBoundary'], PHP_INT_MIN);
        add_action('woocommerce_before_pay_action', [self::class, 'orderPay'], PHP_INT_MIN);
        add_filter('woocommerce_available_payment_gateways', [self::class, 'gateways'], PHP_INT_MAX);
    }
}
