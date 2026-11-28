<?php
/** Real core, My Account, Classic and Store API registration boundaries. */
use ACh\WPAccounts\{AccountFlow,Identity,Settings,SmsSender,SendResult};
$root=getenv('ACH_TEST_WP');$dir=getenv('ACH_TEST_DIR');
if (!$root || !$dir) throw new RuntimeException('Run isolated fixture');
$case=$argv[1]??'';
if (str_starts_with($case,'classic')) define('DOING_AJAX',true);
$_SERVER['REQUEST_METHOD']='POST';
require $root.'/wp-load.php';
if (wp_get_environment_type()!=='local'||DB_NAME!=='accounts_fixture'||get_option('siteurl')!=='https://accounts.test'||!defined('WC_VERSION')) throw new RuntimeException('Not isolated');
function registrationCheck(bool $ok,string $message):void {if (!$ok) throw new RuntimeException($message);}
add_filter('pre_wp_mail','__return_true');
add_filter('pre_http_request',static fn()=>new WP_Error('fixture_no_network'),PHP_INT_MAX);
add_action('set_logged_in_cookie',static function($cookie):void {$_COOKIE[LOGGED_IN_COOKIE]=$cookie;});
final class RegistrationFixtureSender implements SmsSender {
    public string $code='';
    public function id():string{return 'fixture';}
    public function send(string $mobile,string $code,string $purpose):SendResult{$this->code=$code;return new SendResult('accepted','fixture','1');}
}
if ($case!=='') {
    [$route,$credential,$reset,$mobile,$login,$link,$allowed]=explode(':',$case);
    $sequence=(int)$argv[2];$product=(int)$argv[3];
    $email="native-registration-$sequence@example.invalid";
    $username="native_registration_$sequence";
    $password='fixture-native-registration-password-123';
    $settings=Settings::current();
    foreach (['auth_enabled'=>$mobile,'allow_password_login'=>$login,'allow_first_link'=>$link,'allow_email_password_reset'=>$reset,
        'allow_email_registration'=>'1','allow_email_login'=>'0','checkout_required'=>'0'] as $key=>$flag) $settings[$key]=$flag==='1';
    update_option(Settings::OPTION,$settings,false);
    update_option('users_can_register',1);
    update_option('default_role','subscriber');
    update_option('woocommerce_enable_myaccount_registration','yes');
    update_option('woocommerce_enable_signup_and_login_from_checkout','yes');
    update_option('woocommerce_enable_guest_checkout','yes');
    update_option('woocommerce_registration_generate_username','yes');
    update_option('woocommerce_registration_generate_password',in_array($credential,['generated','explicit-option-yes'],true)?'yes':'no');
    update_option('woocommerce_default_country','US:CA');
    update_option('woocommerce_calc_taxes','no');
    update_option('woocommerce_bacs_settings',['enabled'=>'yes','title'=>'Fixture bank transfer']);
    wp_set_current_user(0);
    // Each child observes creation at the actual native endpoint, even when
    // WooCommerce exits via its native AJAX response.
    register_shutdown_function(static function() use($email,$password,$credential,$reset,$mobile,$login,$link,$allowed,$sequence,$route,$dir):void {
        try {
            $id=email_exists($email);
            registrationCheck((bool)$id===($allowed==='1'),'Unexpected account creation: '.$route);
            if ($id) {
                wp_set_current_user(0);unset($_COOKIE[LOGGED_IN_COOKIE]);
                $user=get_userdata($id);
                registrationCheck($user instanceof WP_User && !Identity::byUser($id),'Unexpected identity ownership');
                if (!str_starts_with($credential,'explicit') || $route==='wp') {
                    registrationCheck($reset==='1','Generated password without authorized setup');
                    // Capture native reset-mail key, then use the actual core
                    // key verification and native password mutation.
                    $key='';
                    add_filter('retrieve_password_message',static function($message,$resetKey)use(&$key){$key=$resetKey;return $message;},10,2);
                    registrationCheck(retrieve_password($user->user_login)===true && $key!=='','Native setup mail unavailable');
                    $checked=check_password_reset_key($key,$user->user_login);
                    registrationCheck($checked instanceof WP_User && $checked->ID===$id,'Native setup key invalid');
                    reset_password($checked,$password);
                }
                if ($login==='1') {
                    $authenticated=wp_authenticate($user->user_login,$password);
                    registrationCheck($authenticated instanceof WP_User&&$authenticated->ID===$id,'Subsequent native login failed');
                } else {
                    registrationCheck($mobile==='1'&&$link==='1','No subsequent access method');
                    $flow=new AccountFlow();$browser=bin2hex(random_bytes(32));
                    $phone='+989124888'.str_pad((string)$sequence,3,'0',STR_PAD_LEFT);
                    $ticket=$flow->start($browser,$phone,'recover','account',$password,$user->user_login);
                    $sender=new RegistrationFixtureSender();$flow->issue($browser,$ticket,'192.0.2.'.($sequence+10),$sender);
                    $result=$flow->complete($browser,$ticket,$sender->code);
                    registrationCheck($result['created']===false && get_current_user_id()===$id && (int)Identity::byMobile($phone)->user_id===$id,'Subsequent first-link failed');
                }
            }
            file_put_contents($dir.'/registration-result.json',wp_json_encode(['ok'=>true,'created'=>(bool)$id]),LOCK_EX);
        } catch(Throwable $error) {
            file_put_contents($dir.'/registration-result.json',wp_json_encode(['ok'=>false,'error'=>$error->getMessage()]),LOCK_EX);
        }
    });
    if ($route==='wp') {
        $result=register_new_user($username,$email);
        registrationCheck(($allowed==='1'&&is_int($result))||($allowed==='0'&&is_wp_error($result)&&$result->get_error_code()==='ach_accounts_email_registration_disabled'),'Native WP registration policy result');
        exit;
    }
    wc_load_cart();
    wc_clear_notices();
    if ($route==='account') {
        add_filter('woocommerce_registration_auth_new_customer','__return_false');
        $_POST=['register'=>'1','email'=>$email,'username'=>$username,'woocommerce-register-nonce'=>wp_create_nonce('woocommerce-register')];
        if ($credential==='explicit') $_POST['password']=$password;
        $_REQUEST=$_POST;
        WC_Form_Handler::process_registration();
        if ($allowed==='0') registrationCheck(str_contains(wp_json_encode(wc_get_notices('error')),'Registration with email is disabled.'),'Native My Account policy denial missing');
        exit;
    }
    WC()->cart->empty_cart();
    registrationCheck((bool)WC()->cart->add_to_cart($product,1),'Native cart add failed');
    WC()->cart->calculate_totals();
    $billing=['first_name'=>'Fixture','last_name'=>'Customer','company'=>'','address_1'=>'100 Fixture Avenue','address_2'=>'',
        'city'=>'Los Angeles','state'=>'CA','postcode'=>'90001','country'=>'US','phone'=>'09124440001','email'=>$email];
    if ($route==='classic') {
        // A native checkout-field extension can remove the password input
        // even if My Account's generate-password option says "no".
        if ($credential==='missing') add_filter('woocommerce_checkout_fields',static function($fields){unset($fields['account']['account_password']);return $fields;});
        $_POST=['payment_method'=>'bacs','woocommerce-process-checkout-nonce'=>wp_create_nonce('woocommerce-process_checkout'),
            'terms'=>'1','terms-field'=>'1','createaccount'=>'1'];
        if ($credential==='explicit') $_POST['account_password']=$password;
        foreach($billing as $key=>$value) $_POST['billing_'.$key]=$value;
        $_REQUEST=$_POST;
        WC()->checkout()->process_checkout();
        exit;
    }
    registrationCheck($route==='store','Unknown registration route');
    $request=new WP_REST_Request('POST','/wc/store/v1/checkout');
    $request->set_header('Nonce',wp_create_nonce('wc_store_api'));
    $request->set_param('billing_address',$billing);
    $request->set_param('payment_method','bacs');
    $request->set_param('payment_data',[]);
    $request->set_param('create_account',true);
    if (str_starts_with($credential,'explicit')) $request->set_param('customer_password',$password);
    if ($credential==='zero') $request->set_param('customer_password','0');
    $response=rest_do_request($request);
    if ($allowed==='0') registrationCheck($response->get_status()===400 && ($response->get_data()['code']??'')==='ach_accounts_email_registration_disabled','Native Store API policy denial missing');
    else registrationCheck($response->get_status()===200,'Native Store API successful checkout missing');
    exit;
}
$original=Settings::current();
$options=[];
foreach(['users_can_register','default_role','woocommerce_enable_myaccount_registration','woocommerce_enable_signup_and_login_from_checkout','woocommerce_enable_guest_checkout',
    'woocommerce_registration_generate_username','woocommerce_registration_generate_password','woocommerce_default_country','woocommerce_calc_taxes','woocommerce_bacs_settings'] as $key) $options[$key]=get_option($key);
$product=new WC_Product_Simple();
$product->set_name('Isolated registration item');$product->set_status('publish');$product->set_virtual(true);$product->set_regular_price('1.00');
$productId=$product->save();
$cases=[
    // route, credential contract, email reset, mobile auth, password login, first-link, allowed
    'wp:generated:0:0:1:0:0','wp:generated:1:0:1:0:1','wp:generated:1:1:0:1:1','wp:generated:1:0:0:1:0',
    'account:explicit:0:0:1:0:1','account:generated:0:0:1:0:0','account:missing:0:0:1:0:0','account:generated:1:0:1:0:1',
    'account:explicit:0:1:0:1:1','account:explicit:0:1:0:0:0',
    'classic:explicit:0:0:1:0:1','classic:generated:0:0:1:0:0','classic:missing:0:0:1:0:0','classic:generated:1:0:1:0:1',
    'store:explicit:0:0:1:0:1','store:missing:0:0:1:0:0','store:missing:1:0:1:0:1','store:explicit:0:1:0:1:1',
    'store:missing:1:1:0:0:0','store:explicit-option-yes:0:0:1:0:1','store:zero:0:0:1:0:0',
];
try {
    foreach($cases as $i=>$case) {
        $resultFile=$dir.'/registration-result.json';
        if(is_file($resultFile))unlink($resultFile);
        $process=proc_open([PHP_BINARY,__FILE__,$case,(string)($i+1),(string)$productId],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
        registrationCheck(is_resource($process),'Native registration child start failed');
        fclose($pipes[0]);$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
        $exit=proc_close($process);
        $result=is_file($resultFile)?json_decode(file_get_contents($resultFile),true):[];
        if (str_starts_with($case,'classic:')) {
            if (str_ends_with($case,':0')) registrationCheck(str_contains($out,'Registration with email is disabled.'),'Native Classic policy denial missing: '.$out);
            else registrationCheck((json_decode($out,true)['result']??'')==='success','Native Classic successful checkout missing: '.$out);
        }
        registrationCheck($exit===0&&!empty($result['ok']),'Native registration '.$case.': '.($result['error']??'child exit '.$exit).' '.wp_strip_all_tags($out).' '.$err);
    }
    echo 'PASS: '.count($cases)." actual core, My Account, Classic and Store API email-registration cases; denial before user creation and subsequent native password setup/sign-in or first-link\n";
} finally {
    wp_cache_delete('alloptions','options');wp_cache_delete('notoptions','options');
    wp_cache_delete(Settings::OPTION,'options');
    foreach(array_keys($options) as $key) wp_cache_delete($key,'options');
    update_option(Settings::OPTION,$original,false);
    foreach($options as $key=>$value){if($value===false)delete_option($key);else update_option($key,$value);}
    $product->delete(true);
}
