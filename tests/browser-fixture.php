<?php
/** Disposable loopback fixture, never shipped or usable on an installed site. */
$root=getenv('ACH_TEST_WP');
$dir=getenv('ACH_TEST_DIR');
$url=getenv('ACH_BROWSER_URL');
if (!$root || !$dir || !preg_match('~^https://127\.0\.0\.1:[0-9]+$~D',(string)$url)) {
    throw new RuntimeException('Run via ACH_BROWSER_ONLY fixture.');
}
require $root.'/wp-load.php';
require_once ABSPATH.'wp-admin/includes/plugin.php';
if (DB_NAME!=='accounts_fixture' || wp_get_environment_type()!=='local' || get_option('siteurl')!==$url) {
    throw new RuntimeException('Not an isolated browser fixture.');
}
$mode=$argv[1] ?? 'setup';
if ($mode==='routes') {
    echo wp_json_encode(['account'=>wc_get_page_permalink('myaccount'),
        'orders'=>wc_get_account_endpoint_url('orders'),'downloads'=>wc_get_account_endpoint_url('downloads'),
        'editAccount'=>wc_get_account_endpoint_url('edit-account'),'lostPassword'=>wc_lostpassword_url()]);
    exit;
}
if (in_array($mode,['classic','blocks'],true)) {
    update_option('woocommerce_checkout_page_id',(int)get_option('fixture_'.$mode));
    exit;
}
if ($mode==='default-checkout-policy') {
    $settings=\ACh\WPAccounts\Settings::current();
    $settings['checkout_reverify']=false;
    update_option(\ACh\WPAccounts\Settings::OPTION,$settings,false);
    exit;
}
if ($mode==='persian') {
    update_option('WPLANG','fa_IR');
    exit;
}
if (in_array($mode,['email-confirm-enabled','email-confirm-disabled'],true)) {
    $enabled=$mode==='email-confirm-enabled';
    $settings=\ACh\WPAccounts\Settings::current();
    $settings['allow_password_login']=$enabled;
    update_option(\ACh\WPAccounts\Settings::OPTION,$settings,false);
    $name=$enabled?'fixture_email_enabled':'fixture_email_disabled';
    $mobile=$enabled?'09125557775':'09125557776';
    $password='fixture-only-confirm-password-123';
    $id=wp_insert_user(['user_login'=>$name,'user_email'=>$name.'@example.invalid','user_pass'=>$password,'role'=>'customer']);
    if (is_wp_error($id)) { throw new RuntimeException($id->get_error_message()); }
    \ACh\WPAccounts\Identity::bind($id,'+98'.substr($mobile,1),'absent');
    $link='';
    add_action('woocommerce_customer_verify_email_notification',static function($userId,$url) use (&$link,$id) {
        if ($userId===$id) { $link=$url; }
    },10,2);
    wc_get_container()->get(\Automattic\WooCommerce\Internal\CustomerEmailVerification\VerificationController::class)->send_verification_email($id);
    if (!$link) { throw new RuntimeException('Native synthetic confirmation link missing'); }
    echo wp_json_encode(['url'=>$link,'id'=>$id,'mobile'=>$mobile,'password'=>$password]);
    exit;
}
if ($mode==='email-confirm-state') {
    $service=wc_get_container()->get(\Automattic\WooCommerce\Internal\CustomerEmailVerification\EmailVerificationService::class);
    echo wp_json_encode($service->is_verified((int)($argv[2] ?? 0)));
    exit;
}
if ($mode==='token-fixture') {
    update_option('fixture_token_enabled',true);
    $owner=\ACh\WPAccounts\Identity::byMobile('+989125557771');
    if (!$owner) { throw new RuntimeException('Classic fixture owner missing'); }
    $ids=[];
    foreach (['1111','2222'] as $last4) {
        $token=new WC_Payment_Token_CC();
        $token->set_token('fixture-token-'.$last4);
        $token->set_gateway_id('fixture_token');
        $token->set_user_id((int)$owner->user_id);
        $token->set_card_type('visa');
        $token->set_last4($last4);
        $token->set_expiry_month('12');
        $token->set_expiry_year('2035');
        $token->set_is_default($last4==='1111');
        $ids[]=$token->save();
    }
    echo wp_json_encode($ids);
    exit;
}
if ($mode==='token-remove') {
    $token=WC_Payment_Tokens::get((int)($argv[2] ?? 0));
    if (!$token || $token->get_gateway_id()!=='fixture_token') { throw new RuntimeException('Not a fixture token'); }
    $token->delete(true);
    exit;
}
if ($mode==='token-disable') {
    delete_option('fixture_token_enabled');
    exit;
}
if ($mode==='strict-checkout-policy') {
    $settings=\ACh\WPAccounts\Settings::current();
    $settings['checkout_reverify']=true;
    update_option(\ACh\WPAccounts\Settings::OPTION,$settings,false);
    $owner=\ACh\WPAccounts\Identity::byMobile('+989125557771');
    global $wpdb;
    $wpdb->delete(\ACh\WPAccounts\Schema::table('session_proofs'),['user_id'=>$owner->user_id],['%d']);
    exit;
}
if (in_array($mode,['order-pay','order-pay-strict','order-pay-token'],true)) {
    $settings=\ACh\WPAccounts\Settings::current();
    $settings['checkout_reverify']=$mode!=='order-pay';
    update_option(\ACh\WPAccounts\Settings::OPTION,$settings,false);
    global $wpdb;
    $owner=\ACh\WPAccounts\Identity::byMobile('+989125557771');
    if (!$owner) { throw new RuntimeException('Classic fixture owner missing'); }
    $wpdb->delete(\ACh\WPAccounts\Schema::table('session_proofs'),['user_id'=>$owner->user_id],['%d']);
    $order=wc_create_order(['customer_id'=>$owner->user_id]);
    $order->add_product(wc_get_product((int)json_decode(file_get_contents($dir.'/browser-fixture.json'),true)['product']),1);
    $order->set_billing_first_name('Fixture');
    $order->set_billing_last_name('Customer');
    $order->set_billing_email('historical@example.invalid');
    $order->set_billing_phone('09129999999');
    $order->set_payment_method($mode==='order-pay-token'?'fixture_token':'bacs');
    $order->calculate_totals();
    $order->save();
    echo wp_json_encode(['url'=>$order->get_checkout_payment_url(),'id'=>$order->get_id()]);
    exit;
}
if ($mode==='state') {
    global $wpdb;
    $orders=wc_get_orders(['limit'=>-1,'type'=>'shop_order','status'=>['on-hold','processing','completed','pending','failed','checkout-draft']]);
    echo wp_json_encode(array_map(static fn($o)=>[
        'id'=>$o->get_id(),'customer'=>$o->get_customer_id(),'status'=>$o->get_status(),
        'total'=>$o->get_total(),'items'=>count($o->get_items()),'gateway'=>$o->get_payment_method(),
        'phone'=>$o->get_billing_phone(),'email'=>$o->get_billing_email(),'notes'=>$o->get_customer_note(),
        'token'=>$o->get_meta('_fixture_token'),'save'=>(bool)$o->get_meta('_fixture_save'),'calls'=>(int)$o->get_meta('_fixture_payment_calls'),
    ],$orders));
    exit;
}
foreach (['ach-wp-accounts/ach-wp-accounts.php','woocommerce/woocommerce.php'] as $plugin) {
    $result=activate_plugin($plugin);
    if (is_wp_error($result)) { throw new RuntimeException($result->get_error_message()); }
}
\WC_Install::create_pages();
$blocks=(int)wc_get_page_id('checkout');
$classic=wp_insert_post(['post_type'=>'page','post_status'=>'publish','post_title'=>'Classic checkout','post_name'=>'checkout-classic','post_content'=>'[woocommerce_checkout]']);
update_option('fixture_blocks',$blocks);
update_option('fixture_classic',$classic);
update_option('woocommerce_checkout_page_id',$classic);
update_option('permalink_structure','/%postname%/');
update_option('woocommerce_coming_soon','no');
update_option('woocommerce_store_pages_only','no');
update_option('woocommerce_default_country','US:CA');
update_option('woocommerce_currency','USD');
update_option('woocommerce_enable_guest_checkout','no');
update_option('woocommerce_enable_signup_and_login_from_checkout','yes');
update_option('woocommerce_enable_myaccount_registration','yes');
update_option('woocommerce_enable_checkout_login_reminder','yes');
update_option('users_can_register',true);
update_option('woocommerce_bacs_settings',['enabled'=>'yes','title'=>'Fixture bank transfer','description'=>'Fixture only']);
update_option('woocommerce_enable_coupons','yes');
$product=new WC_Product_Simple();
$product->set_name('Fixture virtual product');
$product->set_status('publish');
$product->set_virtual(true);
$product->set_regular_price('25');
$productId=$product->save();
$coupon=new WC_Coupon();
$coupon->set_code('fixture5');
$coupon->set_discount_type('fixed_cart');
$coupon->set_amount('5');
$coupon->save();
$settings=\ACh\WPAccounts\Settings::defaults();
$settings['auth_enabled']=true;
$settings['allow_registration']=true;
$settings['checkout_required']=true;
$settings['allow_email_registration']=false;
$settings['allow_password_login']=false;
$settings['selected_provider']='kavenegar';
$settings['providers']['kavenegar']=['credentials'=>['api_key'=>'fixture-only-key'],
    'default'=>['mode'=>'pattern','template'=>'fixture-template','tokens'=>[]],'purposes'=>[]];
$settings['verification']['cooldown']=1;
update_option(\ACh\WPAccounts\Settings::OPTION,$settings,false);
\ACh\WPAccounts\Settings::sender();
$legacy=wp_insert_user(['user_login'=>'fixture_browser_legacy','user_email'=>'legacy@example.invalid',
    'user_pass'=>'fixture-only-legacy-password-123','role'=>'customer']);
$legacyAdmin=get_user_by('login','fixture_admin');
wp_set_password('fixture-only-admin-password-123',$legacyAdmin->ID);
flush_rewrite_rules(false);
file_put_contents($dir.'/browser-fixture.json',wp_json_encode([
    'product'=>$productId,'account'=>wc_get_page_permalink('myaccount'),
    'classic'=>get_permalink($classic),'blocks'=>get_permalink($blocks),'legacy'=>$legacy,
]));
