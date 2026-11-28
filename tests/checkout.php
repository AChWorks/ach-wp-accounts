<?php
use ACh\WPAccounts\BrowserApi;
use ACh\WPAccounts\CheckoutGate;
use ACh\WPAccounts\Identity;
use ACh\WPAccounts\SessionProof;
use ACh\WPAccounts\VerificationError;

$root=getenv('ACH_TEST_WP');
if (!$root) { throw new RuntimeException('Run through isolated fixture.'); }
$_SERVER['REQUEST_METHOD'] = 'POST';
require $root.'/wp-load.php';
if (wp_get_environment_type()!=='local' || DB_NAME!=='accounts_fixture' || get_option('siteurl')!=='https://accounts.test' || !defined('WC_VERSION')) {
    throw new RuntimeException('Not the isolated WooCommerce fixture.');
}
function fixtureFlag(string $key, bool $value): void {
    $s=\ACh\WPAccounts\Settings::current();
    $s[$key]=$value;
    update_option(\ACh\WPAccounts\Settings::OPTION,$s,false);
}
add_filter('pre_wp_mail','__return_true');
add_filter('pre_http_request',static fn()=>new WP_Error('fixture_no_network'));
function assertCheckout(bool $ok,string $message):void {
    if (!$ok) { throw new RuntimeException($message); }
}
function restCheckout(string $route): WP_REST_Response {
    $request=new WP_REST_Request('POST',$route);
    $request->set_param('billing_address', [
        'first_name'=>'Test','last_name'=>'Customer','company'=>'',
        'address_1'=>'100 Fixture Avenue','address_2'=>'','city'=>'Los Angeles',
        'state'=>'CA','postcode'=>'90001','country'=>'US','phone'=>'2025550100',
        'email'=>'fixture@example.invalid',
    ]);
    $request->set_param('payment_method','bacs');
    return rest_do_request($request);
}
function rejectsClassic(): bool {
    $errors=new WP_Error();
    do_action('woocommerce_after_checkout_validation',[], $errors);
    return in_array('ach_wp_accounts_mobile_required',$errors->get_error_codes(),true);
}
function nativeBoundary(callable $fn, bool $blocked): void {
    try {
        $fn();
        assertCheckout(!$blocked,'Expected payment boundary refusal');
    } catch (Automattic\WooCommerce\StoreApi\Exceptions\RouteException $error) {
        assertCheckout($blocked && $error->getErrorCode()==='ach_wp_accounts_mobile_required','Unexpected Store API refusal');
    } catch (Exception $error) {
        assertCheckout($blocked && $error->getMessage()===CheckoutGate::message(),'Unexpected classic refusal: '.$error->getMessage());
    }
}

assertCheckout(!BrowserApi::enabled(),'Authentication unexpectedly active by default');
assertCheckout(!CheckoutGate::enabled() && !rejectsClassic(),'Checkout unexpectedly restricted by default');
fixtureFlag('checkout_required',true);
assertCheckout(!\ACh\WPAccounts\Settings::flag('checkout_reverify'),'Repeat OTP must default off');
fixtureFlag('checkout_reverify',true);
wp_set_current_user(0);
assertCheckout(rejectsClassic(),'Guest classic checkout accepted');
nativeBoundary(fn()=>do_action('woocommerce_checkout_order_processed',1,[],null),true);
nativeBoundary(fn()=>do_action('woocommerce_store_api_checkout_order_processed',null),true);
nativeBoundary(fn()=>do_action('woocommerce_rest_checkout_process_payment_with_context',null,null),true);
foreach (['/wc/store/v1/checkout','/wc/store/v1/checkout/123'] as $route) {
    $response=restCheckout($route);
    assertCheckout($response->get_status()===403 && $response->get_data()['code']==='ach_wp_accounts_mobile_required','Native REST guard did not handle '.$route.': '.json_encode($response->get_data()));
}
// Existing error, cart/product requests, REST admin and callbacks stay untouched.
$sentinel=new WP_Error('fixture');
assertCheckout(CheckoutGate::storeRequest($sentinel,[],new WP_REST_Request('POST','/wc/store/v1/checkout'))===$sentinel,'Overrode native error');
foreach ([
    ['GET','/wc/store/v1/checkout'],
    ['POST','/wc/store/v1/cart/add-item'],
    ['POST','/wc/v3/orders'],
    ['POST','/wc/store/v1/checkout-other'],
    ['POST','/wc/store/v1/checkout/12/other'],
] as [$method,$path]) {
    assertCheckout(CheckoutGate::storeRequest(null,[],new WP_REST_Request($method,$path))===null,'Unrelated REST route intercepted: '.$path);
}

$id=wp_insert_user(['user_login'=>'fixture_checkout_user','user_pass'=>wp_generate_password(32),'user_email'=>'checkout@example.invalid','role'=>'customer']);
assertCheckout(is_int($id),'Could not create checkout fixture user');
$identity=Identity::bind($id,'+989121111119','absent');
add_action('set_logged_in_cookie',static function($cookie):void { $_COOKIE[LOGGED_IN_COOKIE]=$cookie; });
wp_set_current_user($id);
wp_set_auth_cookie($id,false,true);
assertCheckout(!SessionProof::qualifies() && rejectsClassic(),'Password-only account bypassed gate');
$token=SessionProof::currentToken($id);
assertCheckout($token!=='','Native WP cookie not valid');
// Imported mappings do not assert possession, even with repeat OTP off.
$pendingId=wp_insert_user(['user_login'=>'fixture_checkout_pending','user_pass'=>wp_generate_password(32),'role'=>'customer']);
assertCheckout(is_int($pendingId),'Pending customer fixture failed');
Identity::importReviewedMapping($pendingId,'09121111135',str_repeat('b',64));
$previousCookie=$_COOKIE[LOGGED_IN_COOKIE];
wp_set_current_user($pendingId);
wp_set_auth_cookie($pendingId,false,true);
fixtureFlag('checkout_reverify',false);
assertCheckout(CheckoutGate::blocked() && rejectsClassic(),'Imported unverified mapping bypassed default checkout verification');
$_COOKIE[LOGGED_IN_COOKIE]=$previousCookie;
wp_set_current_user($id);

fixtureFlag('checkout_reverify',false);
assertCheckout(!SessionProof::qualifies() && !rejectsClassic() && CheckoutGate::qualified('۰۹۱۲۱۱۱۱۱۱۹'),
    'Previously verified customer unnecessarily needs OTP');
assertCheckout(CheckoutGate::blocked('09121111118'),'Different checkout mobile bypassed matching policy');
assertCheckout(BrowserApi::status('checkout')['qualified'] && !BrowserApi::status('account')['qualified'] &&
    !BrowserApi::status('checkout')['recent_proof'],'Relaxed checkout granted sensitive account authority');
nativeBoundary(fn()=>do_action('woocommerce_checkout_order_processed',1,[],null),false);
nativeBoundary(fn()=>do_action('woocommerce_store_api_checkout_order_processed',null),false);
assertCheckout(CheckoutGate::storeRequest(null,[],new WP_REST_Request('POST','/wc/store/v1/checkout'))===null,
    'Relaxed policy rejected verified native Store API session');
fixtureFlag('checkout_reverify',true);
assertCheckout(rejectsClassic(),'Enabling repeat OTP did not require session proof');

foreach (['/wc/store/v1/checkout','/wc/store/v1/checkout/123'] as $route) {
    $response=restCheckout($route);
    assertCheckout($response->get_status()===403 && $response->get_data()['code']==='ach_wp_accounts_mobile_required','Unverified native account bypassed Store API');
}

// Exercise real WC_Form_Handler::pay_action, not only our callback.
final class AccountsFixtureGateway extends WC_Payment_Gateway {
    public static int $calls=0;
    public function __construct() {
        $this->id='ach_accounts_fixture';
        $this->title='Fixture payment';
        $this->enabled='yes';
    }
    public function process_payment($orderId) {
        self::$calls++;
        return ['result'=>'failure'];
    }
}
add_filter('woocommerce_payment_gateways',static fn($methods)=>[AccountsFixtureGateway::class]);
WC()->payment_gateways()->init();
wc_load_cart();
assertCheckout(isset(WC()->payment_gateways()->payment_gateways()['ach_accounts_fixture']), 'Fixture gateway not registered');
$order=wc_create_order(['customer_id'=>$id]);
assertCheckout($order instanceof WC_Order,'Could not create native order fixture');
$order->set_billing_country('US');
$order->set_billing_email('checkout@example.invalid');
$order->set_total(25);
$order->set_status('pending');
$order->save();
global $wp;
$wp->query_vars['order-pay']=$order->get_id();
$_GET=['key'=>$order->get_order_key()];
$_POST=['woocommerce_pay'=>'1','payment_method'=>'ach_accounts_fixture'];
$_REQUEST=$_GET+$_POST+['woocommerce-pay-nonce'=>wp_create_nonce('woocommerce-pay')];
$ran=0;
add_action('woocommerce_before_pay_action',static function()use(&$ran):void { $ran++; },0);
$level=ob_get_level();
WC_Form_Handler::pay_action();
while (ob_get_level()>$level) { ob_end_clean(); }
assertCheckout($ran===1 && AccountsFixtureGateway::$calls===0 && wc_notice_count('error')>0,'Native unverified order-pay was not blocked');
wc_clear_notices();

SessionProof::record($id,$token,$identity);
assertCheckout(SessionProof::qualifies() && !rejectsClassic(),'Verified native account blocked');
nativeBoundary(fn()=>do_action('woocommerce_checkout_order_processed',1,[],null),false);
nativeBoundary(fn()=>do_action('woocommerce_store_api_checkout_order_processed',null),false);
assertCheckout(CheckoutGate::storeRequest(null,[],new WP_REST_Request('POST','/wc/store/v1/checkout'))===null,'Verified account rejected at REST entry');
$level=ob_get_level();
WC_Form_Handler::pay_action();
while (ob_get_level()>$level) { ob_end_clean(); }
assertCheckout($ran===2 && AccountsFixtureGateway::$calls===1,'Verified order-pay did not reach native gateway: attempts='.$ran.' gateway_calls='.AccountsFixtureGateway::$calls.' notices='.json_encode(wc_get_notices('error')));
wc_clear_notices();

// A different identity revision and a revoked session cannot reuse proof.
Identity::bind($id,'+989121111120',$identity->revision);
assertCheckout(!SessionProof::qualifies() && rejectsClassic(),'Identity change retained checkout access');
$identity=Identity::byUser($id);
SessionProof::record($id,$token,$identity);
fixtureFlag('checkout_reverify',false);
assertCheckout(CheckoutGate::qualified(),'Verified changed identity should use current account identity');
WP_Session_Tokens::get_instance($id)->destroy($token);
assertCheckout(!SessionProof::qualifies() && rejectsClassic(),'Revoked session retained checkout access');
fixtureFlag('checkout_required',false);
fixtureFlag('checkout_reverify',false);
assertCheckout(!rejectsClassic(),'Disabled policy continued to reject checkout');

// Validate the request boundary independently of an HTTP server. Actual
// browser-cookie handling is exercised separately by the HTTP fixture.
$_SERVER['REQUEST_METHOD']='POST';
$_SERVER['HTTPS']='on';
$_SERVER['HTTP_X_ACH_ACCOUNTS']='1';
$_SERVER['HTTP_ORIGIN']='https://accounts.test';
try { BrowserApi::authorizeRequest(); throw new RuntimeException('Disabled endpoint accepted'); }
catch (VerificationError $error) { assertCheckout($error->getMessage()==='authentication_unavailable','Unexpected disabled response'); }
fixtureFlag('auth_enabled',true);
BrowserApi::authorizeRequest();
foreach (['https://elsewhere.test','null','https://accounts.test.evil.test','http://accounts.test',''] as $origin) {
    $_SERVER['HTTP_ORIGIN']=$origin;
    try { BrowserApi::authorizeRequest(); throw new RuntimeException('Cross-origin request accepted'); }
    catch (VerificationError $error) { assertCheckout($error->getMessage()==='invalid_context','Unexpected request boundary error'); }
}
$_SERVER['HTTP_ORIGIN']='https://accounts.test';
unset($_SERVER['HTTP_X_ACH_ACCOUNTS']);
try { BrowserApi::authorizeRequest(); throw new RuntimeException('Simple form POST accepted'); }
catch (VerificationError $error) { assertCheckout($error->getMessage()==='invalid_context','Unexpected simple form rejection'); }
fixtureFlag('auth_enabled',false);
echo "PASS: optional repeat-OTP policy and separate sensitive-account proof, native REST checkout refusal, classic validation/boundary, actual order-pay gateway refusal/allow, session revocation and same-origin request policy\n";
