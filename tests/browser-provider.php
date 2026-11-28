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

// Real native token markup, Woo token IDs and Store API payment dispatch;
// the fixture records only synthetic instrument selection and never charges.
add_filter('woocommerce_payment_gateways',static function(array $gateways): array {
    if (!get_option('fixture_token_enabled')) { return $gateways; }
    $gateways[]=new class extends \WC_Payment_Gateway {
        public function __construct() {
            $this->id='fixture_token';
            $this->title='Fixture saved instrument';
            $this->enabled='yes';
            $this->has_fields=true;
            $this->supports=['products','tokenization','add_payment_method'];
        }
        public function payment_fields() {
            $this->saved_payment_methods();
            $this->save_payment_method_checkbox();
        }
        public function process_payment($id) {
            $order=wc_get_order($id);
            $token=(string)($_POST['wc-fixture_token-payment-token'] ?? 'new');
            if ($token!=='new') {
                $stored=WC_Payment_Tokens::get((int)$token);
                if (!$stored || $stored->get_user_id()!==$order->get_customer_id() ||
                    $stored->get_gateway_id()!==$this->id) {
                    wc_add_notice('Invalid synthetic instrument','error');
                    return ['result'=>'failure'];
                }
            }
            $order->update_meta_data('_fixture_token',$token);
            $order->update_meta_data('_fixture_save',wc_string_to_bool($_POST['wc-fixture_token-new-payment-method'] ?? 'false'));
            $order->update_meta_data('_fixture_payment_calls',1+(int)$order->get_meta('_fixture_payment_calls'));
            $order->save();
            $order->update_status('on-hold');
            if (WC()->cart) { WC()->cart->empty_cart(); }
            return ['result'=>'success','redirect'=>$this->get_return_url($order)];
        }
    };
    return $gateways;
});
add_action('woocommerce_blocks_payment_method_type_registration',static function($registry) {
    $registry->register(new class extends \Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType {
        protected $name='fixture_token';
        public function initialize() {}
        public function is_active() { return (bool)get_option('fixture_token_enabled'); }
        public function get_payment_method_script_handles() {
            wp_register_script('fixture-token',WPMU_PLUGIN_URL.'/fixture-token.js',['wc-blocks-registry','wp-element'],'1',true);
            return ['fixture-token'];
        }
        public function get_payment_method_data() { return ['supports'=>['products','tokenization']]; }
    });
});
