<?php
use ACh\WPAccounts\Frontend;
use ACh\WPAccounts\Identity;
use ACh\WPAccounts\SessionProof;

$root=getenv('ACH_TEST_WP');
if (!$root) { throw new RuntimeException('Run through isolated fixture.'); }
$_SERVER['REQUEST_METHOD']='GET';
require $root.'/wp-load.php';
if (wp_get_environment_type()!=='local' || DB_NAME!=='accounts_fixture' || get_option('siteurl')!=='https://accounts.test' || !defined('WC_VERSION')) {
    throw new RuntimeException('Not isolated.');
}
add_filter('pre_wp_mail','__return_true');
add_filter('pre_http_request',static fn()=>new WP_Error('fixture_no_network'));
function frontCheck(bool $value,string $message): void {
    if (!$value) { throw new RuntimeException($message); }
}
update_option('ach_wp_accounts_auth_enabled',true);
update_option('ach_wp_accounts_checkout_required',true);
update_option('woocommerce_enable_myaccount_registration','yes');
$account=wp_insert_post(['post_type'=>'page','post_status'=>'publish','post_title'=>'Fixture account','post_content'=>'[woocommerce_my_account]']);
update_option('woocommerce_myaccount_page_id',$account);
$_SERVER['REQUEST_URI']='/?page_id=123';
$_GET=[];
$_REQUEST=[];
wp_set_current_user(0);
add_filter('woocommerce_is_checkout','__return_true');
wc_load_cart();
$product=new WC_Product_Simple();
$product->set_name('Frontend fixture virtual product');
$product->set_regular_price('12.00');
$product->set_virtual(true);
$product->set_status('publish');
$productId=$product->save();
frontCheck((bool)WC()->cart->add_to_cart($productId),'Could not populate checkout cart');
$gate=Frontend::checkoutShortcode(false,'woocommerce_checkout',[],[]);
frontCheck(is_string($gate) && str_contains($gate,'data-surface="checkout"') &&
    str_contains($gate,'data-ach-native-link') && !str_contains($gate,'id="payment"'),'Classic checkout did not render gate shell');
foreach ([
    ['blockName'=>'woocommerce/checkout'],
    ['blockName'=>'woocommerce/classic-shortcode','attrs'=>['shortcode'=>'checkout']],
] as $block) {
    $html=Frontend::checkoutBlock(null,$block);
    frontCheck(is_string($html) && str_contains($html,'data-surface="checkout"'),'Block checkout gate missing');
}
frontCheck(Frontend::checkoutBlock(null,['blockName'=>'woocommerce/cart'])===null,'Cart block replaced');
frontCheck(Frontend::checkoutShortcode(false,'woocommerce_my_account',[],[])===false,'Account shortcode replaced');

global $wp, $action, $interim_login;
$wp->query_vars['order-received']=123;
frontCheck(Frontend::checkoutShortcode(false,'woocommerce_checkout',[],[])===false,'Order receipt replaced');
unset($wp->query_vars['order-received']);
$action='lostpassword';
frontCheck(Frontend::loginMessage('native')==='native','Password recovery replaced');
$action='login';
$interim_login=false;
$_REQUEST=['reauth'=>'1'];
frontCheck(Frontend::loginMessage('native')==='native','Native reauthentication replaced');
$_REQUEST=[];
$_GET=['ach_accounts_native'=>'1'];
frontCheck(Frontend::loginMessage('native')==='native','Native email/password fallback replaced');
$_GET=[];
frontCheck(str_contains(Frontend::loginMessage('native'),'data-native-target="core"'),'Ordinary native login missing shared form');
frontCheck(str_contains(Frontend::shortcode(['display'=>'popup']),'<dialog'),'Popup does not reuse shared form');
frontCheck(Frontend::localUrl('https://elsewhere.test/path','fallback')==='fallback' &&
    Frontend::localUrl('//elsewhere.test/path','fallback')==='fallback' &&
    Frontend::localUrl('/?page_id=123','fallback')==='https://accounts.test/?page_id=123','Redirect validation mismatch');

$user=wp_insert_user(['user_login'=>'fixture_frontend','user_pass'=>wp_generate_password(32),'user_email'=>'frontend@example.invalid','role'=>'customer']);
frontCheck(is_int($user),'Could not create frontend user');
$identity=Identity::bind($user,'+989121111123','absent');
add_action('set_logged_in_cookie',static function($cookie):void { $_COOKIE[LOGGED_IN_COOKIE]=$cookie; });
wp_set_current_user($user);
wp_set_auth_cookie($user,false,true);
frontCheck(is_string(Frontend::checkoutShortcode(false,'woocommerce_checkout',[],[])),'Native email user bypassed visual checkout gate');
SessionProof::record($user,SessionProof::currentToken($user),$identity);
frontCheck(Frontend::checkoutShortcode(false,'woocommerce_checkout',[],[])===false &&
    Frontend::checkoutBlock(null,['blockName'=>'woocommerce/checkout'])===null,'Qualified session did not restore native checkout renderer');
update_option('ach_wp_accounts_checkout_required',false);
wp_set_current_user(0);
unset($_COOKIE[LOGGED_IN_COOKIE]);
frontCheck(Frontend::checkoutShortcode(false,'woocommerce_checkout',[],[])===false,'Disabled policy changed checkout renderer');
update_option('ach_wp_accounts_auth_enabled',false);
frontCheck(Frontend::shortcode([])==='','Disabled plugin still renders shortcode form');
update_option('ach_wp_accounts_auth_enabled',true);

// Test compiled Persian catalog with native WordPress translation APIs.
add_filter('locale',static fn()=> 'fa_IR');
add_filter('determine_locale',static fn()=> 'fa_IR');
unload_textdomain('ach-wp-accounts',true);
$catalog=WP_PLUGIN_DIR.'/ach-wp-accounts/languages/ach-wp-accounts-fa_IR.mo';
frontCheck(load_textdomain('ach-wp-accounts',$catalog,'fa_IR'),'Persian catalog not loadable');
frontCheck(__('Sign in','ach-wp-accounts')==='ورود','Persian translation not used');
$html=Frontend::render('wp','login','https://accounts.test/','https://accounts.test/wp-login.php');
frontCheck(str_contains($html,'شماره موبایل') && str_contains($html,'autocomplete="one-time-code"') &&
    str_contains($html,'dir="ltr"'),'Localized mobile/OTP form contract failed');
echo "PASS: classic and block presentation gate, native email fallback/actions, qualified and disabled renderers, safe redirects, popup and Persian catalog\n";
