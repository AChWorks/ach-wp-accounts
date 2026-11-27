<?php
use ACh\WPAccounts\{Identity,Settings,SessionProof,CheckoutGate};
$root=getenv('ACH_TEST_WP');
$dir=getenv('ACH_TEST_DIR');
if (!$root || !$dir) { throw new RuntimeException('Run isolated fixture.'); }
$mode=$argv[1] ?? 'parent';
if ($mode==='classic') { define('DOING_AJAX',true); }
$_SERVER['REQUEST_METHOD']='POST';
require $root.'/wp-load.php';
if (wp_get_environment_type()!=='local' || DB_NAME!=='accounts_fixture' || get_option('siteurl')!=='https://accounts.test' || !function_exists('WC')) {
    throw new RuntimeException('Not isolated commerce fixture.');
}
function purchaseCheck(bool $ok,string $message):void { if (!$ok) {throw new RuntimeException($message);} }
add_filter('pre_wp_mail','__return_true');
add_filter('pre_http_request',static fn()=>new WP_Error('fixture_no_network'),PHP_INT_MAX);
final class PurchaseFixtureGateway extends WC_Payment_Gateway {
    public function __construct() {
        $this->id='ach_fixture_purchase';
        $this->title='Fixture purchase';
        $this->enabled='yes';
        $this->supports=['products'];
    }
    public function process_payment($orderId) {
        $order=wc_get_order($orderId);
        if (!$order || CheckoutGate::blocked()) { throw new RuntimeException('Unqualified native payment'); }
        file_put_contents(getenv('ACH_TEST_DIR').'/fixture-paid-orders',((int)$orderId).PHP_EOL,FILE_APPEND|LOCK_EX);
        $order->payment_complete('fixture-'.$orderId);
        WC()->cart->empty_cart();
        return ['result'=>'success','redirect'=>$order->get_checkout_order_received_url()];
    }
}
add_filter('woocommerce_payment_gateways',static fn($gateways)=>[PurchaseFixtureGateway::class]);
if ($mode!=='parent') {
    $userId=(int)($argv[2] ?? 0);
    $productId=(int)($argv[3] ?? 0);
    $qualified=($argv[4] ?? '')==='qualified';
    wp_set_current_user($userId);
    add_action('set_logged_in_cookie',static function($cookie):void {$_COOKIE[LOGGED_IN_COOKIE]=$cookie;});
    wp_set_auth_cookie($userId,false,true);
    $token=SessionProof::currentToken($userId);
    purchaseCheck($token!=='' && Identity::byUser($userId),'Native checkout session fixture failed');
    if ($qualified) { SessionProof::record($userId,$token,Identity::byUser($userId)); }
    wc_load_cart();
    WC()->payment_gateways()->init();
    WC()->cart->empty_cart();
    purchaseCheck((bool)WC()->cart->add_to_cart($productId,1),'Native cart add failed');
    WC()->cart->calculate_totals();
    wc_clear_notices();
    $billing=['first_name'=>'Fixture','last_name'=>'Customer','company'=>'',
        'address_1'=>'100 Fixture Avenue','address_2'=>'','city'=>'Los Angeles','state'=>'CA',
        'postcode'=>'90001','country'=>'US','phone'=>'2025550100','email'=>'fixture-order-billing@example.invalid'];
    register_shutdown_function(static function () use ($mode,$qualified,$userId):void {
        file_put_contents(getenv('ACH_TEST_DIR').'/purchase-state.json',wp_json_encode([
            'mode'=>$mode,'qualified'=>$qualified,'cart_count'=>WC()->cart ? WC()->cart->get_cart_contents_count() : -1,
            'email'=>get_userdata($userId)->user_email,
        ]),LOCK_EX);
    });
    if ($mode==='classic') {
        $_POST=['payment_method'=>'ach_fixture_purchase','woocommerce-process-checkout-nonce'=>wp_create_nonce('woocommerce-process_checkout'),
            'terms'=>'1','terms-field'=>'1'];
        foreach ($billing as $key=>$value) { $_POST['billing_'.$key]=$value; }
        $_REQUEST=$_POST;
        WC()->checkout()->process_checkout();
        exit;
    }
    if ($mode==='store') {
        $request=new WP_REST_Request('POST','/wc/store/v1/checkout');
        $request->set_header('Nonce',wp_create_nonce('wc_store_api'));
        $request->set_param('billing_address',$billing);
        $request->set_param('payment_method','ach_fixture_purchase');
        $request->set_param('payment_data',[]);
        $request->set_param('create_account',false);
        $response=rest_do_request($request);
        echo wp_json_encode(['status'=>$response->get_status(),'data'=>$response->get_data()]);
        exit;
    }
    throw new RuntimeException('Unknown fixture mode');
}
$old=Settings::current();
$s=$old;$s['checkout_required']=true;update_option(Settings::OPTION,$s,false);
update_option('woocommerce_default_country','US:CA');
update_option('woocommerce_enable_guest_checkout','yes');
update_option('woocommerce_calc_taxes','no');
update_option('woocommerce_currency','USD');
$product=new WC_Product_Simple();
$product->set_name('Fixture virtual item');
$product->set_status('publish');
$product->set_virtual(true);
$product->set_regular_price('10.00');
$productId=$product->save();
purchaseCheck($productId>0,'Product fixture failed');
$paid=$dir.'/fixture-paid-orders';
$stateFile=$dir.'/purchase-state.json';
function childPurchase(string $mode,int $id,int $product,bool $qualified):array {
    global $stateFile;
    if (is_file($stateFile)) {unlink($stateFile);}
    $proc=proc_open([PHP_BINARY,__FILE__,$mode,(string)$id,(string)$product,$qualified?'qualified':'unqualified'],
        [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    purchaseCheck(is_resource($proc),'Checkout child failed to start');
    fclose($pipes[0]);$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
    $exit=proc_close($proc);
    purchaseCheck($exit===0 && is_file($stateFile),'Checkout child exit '.$exit);
    $response=json_decode($out,true);
    purchaseCheck(is_array($response),'Invalid fixture checkout response');
    return [$response,json_decode(file_get_contents($stateFile),true,512,JSON_THROW_ON_ERROR)];
}
foreach (['classic','store'] as $i=>$mode) {
    $id=wp_insert_user(['user_login'=>'fixture_purchase_'.$mode,'user_pass'=>wp_generate_password(32),
        'role'=>'customer','user_email'=>'','meta_input'=>['_ach_accounts_registration'=>bin2hex(random_bytes(16))]]);
    purchaseCheck(is_int($id),'Phone-only customer fixture failed');
    Identity::bind($id,'0912444000'.($i+2),'absent');
    $before=is_file($paid)?count(file($paid,FILE_IGNORE_NEW_LINES)):0;
    [$negative,$state]=childPurchase($mode,$id,$productId,false);
    $denied=$mode==='classic' ? ($negative['result'] ?? '')==='failure' && str_contains($negative['messages'] ?? '',CheckoutGate::message()) :
        ($negative['status'] ?? 0)===403 && ($negative['data']['code'] ?? '')==='ach_wp_accounts_mobile_required';
    purchaseCheck($denied && $state['cart_count']===1 && $state['email']==='' &&
        (is_file($paid)?count(file($paid,FILE_IGNORE_NEW_LINES)):0)===$before,'Unqualified native checkout failed gate/cart assertion: '.$mode);
    [$positive,$state]=childPurchase($mode,$id,$productId,true);
    $success=$mode==='classic' ? ($positive['result'] ?? '')==='success' : ($positive['status'] ?? 0)===200 &&
        ($positive['data']['payment_result']['payment_status'] ?? '')==='success';
    if (!$success) {
        $summary=$mode==='classic' ? ($positive['messages'] ?? 'unknown') : ($positive['data']['code'] ?? $positive['status'] ?? 'unknown').' '.($positive['data']['message'] ?? '');
        throw new RuntimeException('Qualified '.$mode.' checkout fixture failed: '.wp_strip_all_tags((string)$summary));
    }
    $orders=file($paid,FILE_IGNORE_NEW_LINES);
    purchaseCheck(count($orders)===$before+1 && $state['email']==='' && $state['cart_count']===0,'Qualified checkout duplicated payment or changed account email/cart');
    $order=wc_get_order((int)end($orders));
    purchaseCheck($order && $order->is_paid() && $order->get_customer_id()===$id &&
        $order->get_billing_email()==='fixture-order-billing@example.invalid' &&
        (float)$order->get_total()===10.0 && get_userdata($id)->user_email==='',
        'Native order ownership, billing contact or payment result invalid');
}
update_option(Settings::OPTION,$old,false);
echo "PASS: real native Classic and Store API checkout to isolated successful fixture gateway, negative gate/cart preservation, native order ownership and billing email separation\n";
