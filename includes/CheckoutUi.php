<?php
namespace ACh\WPAccounts;

defined('ABSPATH') || exit;

/**
 * Native checkout UI adapters. Presentational filters apply only to the
 * document render; the native registration policy and payment guards still
 * run on actual checkout, Store API and OTP requests.
 */
final class CheckoutUi
{
    public static function hooks(): void
    {
        add_action('template_redirect',[self::class,'prepare'],2);
        add_action('woocommerce_checkout_after_order_review',[self::class,'classic']);
        add_action('woocommerce_pay_order_after_submit',[self::class,'orderPay']);
        add_filter('render_block_woocommerce/checkout',[self::class,'blocks']);
    }

    public static function prepare(): void
    {
        if (!Frontend::checkoutSurface() || is_checkout_pay_page() ||
            ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') { return; }
        // The native checkout form must remain accessible to guests, even
        // when guest payment is disabled. Auth at payment is still mandatory.
        add_filter('woocommerce_checkout_registration_required','__return_false',PHP_INT_MAX);
        add_filter('woocommerce_checkout_registration_enabled','__return_false',PHP_INT_MAX);
        remove_action('woocommerce_before_checkout_form','woocommerce_checkout_login_form',10);
    }

    public static function html(string $kind): string
    {
        return Frontend::render('checkout','checkout',Frontend::currentUrl(),
            AuthPage::secondaryUrl(Frontend::currentUrl()),$kind);
    }

    public static function classic(): void
    {
        if (Frontend::checkoutSurface()) { echo self::html('classic'); }
    }

    public static function orderPay(): void
    {
        if (CheckoutGate::enabled() && is_checkout_pay_page() && !is_order_received_page()) {
            echo self::html('order-pay');
        }
    }

    public static function blocks(string $content): string
    {
        if (!Frontend::checkoutSurface() || is_checkout_pay_page()) { return $content; }
        // Supported validation observer is registered by WooCommerce. Retain
        // the original block markup, including any gateway extensions.
        if (wp_script_is('wc-blocks-checkout-events','registered')) {
            wp_enqueue_script('wc-blocks-checkout-events');
            wp_enqueue_script('wc-blocks-data-store');
        }
        return $content . self::html('blocks');
    }
}
