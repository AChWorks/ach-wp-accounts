<?php
namespace ACh\WPAccounts;

defined('ABSPATH') || exit;

/** Customer checkout policy, never a global payment or webhook interceptor. */
final class CheckoutGate
{
    private static bool $orderPayBlocked = false;

    public static function enabled(): bool
    {
        return BrowserApi::flag('ach_wp_accounts_checkout_required');
    }

    public static function blocked(): bool
    {
        return self::enabled() && !SessionProof::qualifies();
    }

    public static function message(): string
    {
        return __('Please sign in and verify your mobile number before continuing to payment.', 'ach-wp-accounts');
    }

    public static function classicValidation(array $data, \WP_Error $errors): void
    {
        if (self::blocked()) {
            $errors->add('ach_wp_accounts_mobile_required', self::message());
        }
    }

    public static function classicBoundary(): void
    {
        if (self::blocked()) {
            // Native WC_Checkout::process_checkout catches this before payment.
            throw new \Exception(self::message());
        }
    }

    public static function storeRequest(mixed $response, array $handler, \WP_REST_Request $request): mixed
    {
        if (is_wp_error($response) || $request->get_method() !== 'POST' ||
            !preg_match('~\A/wc/store/v[0-9]+/checkout(?:/[0-9]+)?/?\z~Di', $request->get_route()) ||
            !self::blocked()) {
            return $response;
        }
        return new \WP_Error('ach_wp_accounts_mobile_required', self::message(), ['status' => 403]);
    }

    public static function storeBoundary(): void
    {
        if (self::blocked()) {
            throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException(
                'ach_wp_accounts_mobile_required', self::message(), 403
            );
        }
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
        add_action('woocommerce_checkout_order_processed', [self::class, 'classicBoundary'], PHP_INT_MIN);
        add_filter('rest_request_before_callbacks', [self::class, 'storeRequest'], PHP_INT_MIN, 3);
        add_action('woocommerce_store_api_checkout_order_processed', [self::class, 'storeBoundary'], PHP_INT_MIN);
        add_action('woocommerce_rest_checkout_process_payment_with_context', [self::class, 'storeBoundary'], PHP_INT_MIN);
        add_action('woocommerce_before_pay_action', [self::class, 'orderPay'], PHP_INT_MIN);
        add_filter('woocommerce_available_payment_gateways', [self::class, 'gateways'], PHP_INT_MAX);
    }
}
