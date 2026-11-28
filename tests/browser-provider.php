<?php
/** Synthetic provider, isolated local fixture only. */
if (!getenv('ACH_BROWSER_URL') || !getenv('ACH_TEST_DIR') || DB_NAME!=='accounts_fixture' || wp_get_environment_type()!=='local') {
    return;
}
add_filter('pre_wp_mail','__return_true',PHP_INT_MAX);
add_filter('pre_http_request',static function($pre,$args,$url) {
    if (str_ends_with($url,'/verify/lookup.json') && str_contains($url,'fixture-only-key')) {
        $body=$args['body'];
        if (is_string($body)) { parse_str($body,$body); }
        file_put_contents(getenv('ACH_TEST_DIR').'/browser-otp.json',wp_json_encode([
            'mobile'=>$body['receptor'],'code'=>$body['token'],
        ]),LOCK_EX);
        return ['response'=>['code'=>200],'body'=>wp_json_encode(['return'=>['status'=>200],'entries'=>[
            ['messageid'=>123456,'receptor'=>$body['receptor'],'status'=>5],
        ]])];
    }
    return new WP_Error('fixture_no_network');
},PHP_INT_MAX,3);

// A synthetic express adapter runs WooCommerce's real express registration,
// event and Store API flow without contacting a payment provider.
add_action('woocommerce_blocks_payment_method_type_registration',static function($registry) {
    $registry->register(new class extends \Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType {
        protected $name='fixture_express';
        public function initialize() {}
        public function get_payment_method_script_handles() {
            wp_register_script('fixture-express',WPMU_PLUGIN_URL.'/fixture-express.js',['wc-blocks-registry','wp-element'],'1',true);
            return ['fixture-express'];
        }
        public function get_payment_method_data() { return ['supports'=>['products']]; }
    });
});
