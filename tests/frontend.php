<?php
use ACh\WPAccounts\Frontend;
use ACh\WPAccounts\AuthPage;
use ACh\WPAccounts\CheckoutUi;
use ACh\WPAccounts\Identity;
use ACh\WPAccounts\SessionProof;

$root=getenv('ACH_TEST_WP');
if (!$root) { throw new RuntimeException('Run through isolated fixture.'); }
$_SERVER['REQUEST_METHOD']='GET';
require $root.'/wp-load.php';
if (wp_get_environment_type()!=='local' || DB_NAME!=='accounts_fixture' || get_option('siteurl')!=='https://accounts.test' || !defined('WC_VERSION')) {
    throw new RuntimeException('Not isolated.');
}
function fixtureFlag(string $key, bool $value): void {
    $s=\ACh\WPAccounts\Settings::current();
    $s[$key]=$value;
    update_option(\ACh\WPAccounts\Settings::OPTION,$s,false);
}
add_filter('pre_wp_mail','__return_true');
add_filter('pre_http_request',static fn()=>new WP_Error('fixture_no_network'));
function frontCheck(bool $value,string $message): void {
    if (!$value) { throw new RuntimeException($message); }
}
fixtureFlag('auth_enabled',true);
fixtureFlag('checkout_required',true);
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
CheckoutUi::prepare();
frontCheck(!WC()->checkout()->is_registration_required(),'Guest checkout hidden by native registration policy');
$native='<div id="payment">Native payment</div>';
$html=CheckoutUi::blocks($native);
frontCheck(str_starts_with($html,$native) && str_contains($html,'data-checkout-kind="blocks"') &&
    str_contains($html,'data-ach-accounts hidden')===false,'Block checkout native markup lost');
ob_start();
CheckoutUi::classic();
$gate=ob_get_clean();
frontCheck(str_contains($gate,'data-checkout-kind="classic" hidden') &&
    str_contains($gate,'data-ach-form') && !str_contains($gate,'<form '),'Classic inline OTP is missing or nested form');

global $wp, $action;
$wp->query_vars['order-received']=123;
frontCheck(CheckoutUi::blocks($native)===$native,'Order receipt replaced');
unset($wp->query_vars['order-received']);
$_REQUEST=['reauth'=>'1'];
frontCheck(!AuthPage::ordinaryCoreRequest(),'Native reauthentication replaced');
$_REQUEST=['redirect_to'=>admin_url()];
frontCheck(!AuthPage::ordinaryCoreRequest(),'Native administrator login replaced');
$_REQUEST=[];
$_GET=[];
frontCheck(AuthPage::ordinaryCoreRequest(),'Ordinary native login not handled');
ob_start();
AuthPage::document('wp',home_url('/'),'mobile');
$document=ob_get_clean();
frontCheck(str_contains($document,'<!doctype html>') && str_contains($document,'ach-auth-shell') &&
    str_contains($document,'data-ach-accounts') && !str_contains($document,'name="log"'),'Standalone document did not own ordinary login');
fixtureFlag('allow_password_login',false);
fixtureFlag('allow_email_registration',false);
frontCheck(AuthPage::method('account','password')==='mobile' && AuthPage::method('account','email')==='mobile' &&
    AuthPage::secondaryUrl(home_url('/'))==='','Disabled methods remain visible');
fixtureFlag('allow_password_login',true);
fixtureFlag('allow_email_registration',true);
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
frontCheck(str_contains(CheckoutUi::blocks($native),'data-checkout-kind="blocks"'),'Native email customer lost checkout adapter');
SessionProof::record($user,SessionProof::currentToken($user),$identity);
frontCheck(str_contains(CheckoutUi::blocks($native),'data-checkout-kind="blocks"'),'Qualified session lost native inline controller');
fixtureFlag('checkout_required',false);
wp_set_current_user(0);
unset($_COOKIE[LOGGED_IN_COOKIE]);
frontCheck(CheckoutUi::blocks($native)===$native,'Disabled policy changed checkout renderer');
fixtureFlag('auth_enabled',false);
frontCheck(Frontend::shortcode([])==='','Disabled plugin still renders shortcode form');
fixtureFlag('auth_enabled',true);

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
echo "PASS: native checkout markup retained, inline verification shell, standalone authentication document, native privileged paths, disabled methods, safe redirects, popup and Persian catalog\n";
