<?php
/** Exact stable v0.2.0 native updater regression, only in disposable fixture. */
use ACh\WPAccounts\{Identity,Settings,Schema};
$root=getenv('ACH_TEST_WP');$dir=getenv('ACH_TEST_DIR');
if (!$root||!$dir||getenv('ACH_BASELINE_UPGRADE')!=='1') throw new RuntimeException('Run exact baseline fixture');
require $root.'/wp-load.php';
require_once ABSPATH.'wp-admin/includes/plugin.php';
if (wp_get_environment_type()!=='local'||DB_NAME!=='accounts_fixture'||get_option('siteurl')!=='https://accounts.test') throw new RuntimeException('Not isolated');
function baselineCheck(bool $ok,string $message):void {if (!$ok) throw new RuntimeException($message);}
add_filter('pre_wp_mail','__return_true');
add_filter('pre_http_request',static fn()=>new WP_Error('fixture_no_network'),PHP_INT_MAX);
$file='ach-wp-accounts/ach-wp-accounts.php';
$version=get_plugin_data(WP_PLUGIN_DIR.'/'.$file,false,false)['Version'];
if (($argv[1]??'')==='prepare') {
    baselineCheck($version==='0.2.0','Wrong stable baseline');
    foreach ([$file,'woocommerce/woocommerce.php'] as $plugin) {
        $result=activate_plugin($plugin);
        baselineCheck(!is_wp_error($result),'Native activation failed');
    }
    wp_set_current_user(get_user_by('login','fixture_admin')->ID);
    $id=wp_insert_user(['user_login'=>'upgrade_fixture_customer','user_email'=>'upgrade-fixture@example.invalid','user_pass'=>'fixture-upgrade-password-123','role'=>'customer']);
    baselineCheck(is_int($id),'Native user fixture failed');
    $identity=Identity::bind($id,'+989121234567','absent');
    $order=wc_create_order(['customer_id'=>$id]);
    $order->set_billing_phone('09129999999');$order->set_billing_email('historical@example.invalid');$order->save();
    Settings::update([
        'providers'=>['kavenegar'=>['credentials'=>['api_key'=>'fixture-update-secret'],'default'=>['mode'=>'pattern','template'=>'fixture-update-template'],'purposes'=>[]]],
        'selected_provider'=>'kavenegar','auth_enabled'=>true,'checkout_required'=>true,'checkout_reverify'=>true,
        'allow_password_login'=>false,'allow_email_registration'=>false,
    ],Settings::read()['revision'],true);
    file_put_contents($dir.'/baseline-state.json',wp_json_encode(['id'=>$id,'order'=>$order->get_id(),'identity'=>get_object_vars($identity),
        'schema'=>(int)get_option(\ACh\WPAccounts\Lifecycle::VERSION_OPTION)]));
    echo "Prepared exact v0.2.0 native user, verified identity, order and explicit settings.\n";
    exit;
}
baselineCheck(($argv[1]??'')==='verify'&&$version==='0.3.0','Wrong candidate');
$before=json_decode(file_get_contents($dir.'/baseline-state.json'),true,512,JSON_THROW_ON_ERROR);
$user=get_userdata($before['id']);
$order=wc_get_order($before['order']);
$identity=Identity::byUser($before['id']);
$s=Settings::current();
baselineCheck($user instanceof WP_User && $user->user_email==='upgrade-fixture@example.invalid' && wp_check_password('fixture-upgrade-password-123',$user->user_pass,$user->ID),'Native account changed');
baselineCheck($identity && get_object_vars($identity)===$before['identity'],'Identity changed');
baselineCheck($order && $order->get_customer_id()===$user->ID && $order->get_billing_phone()==='09129999999'&&$order->get_billing_email()==='historical@example.invalid','Native order changed');
baselineCheck($s['checkout_reverify']===true && $s['checkout_required']===true && $s['auth_enabled']===true &&
    $s['allow_password_login']===false && $s['allow_email_registration']===false &&
    $s['allow_mobile_password_reset']===false && $s['allow_email_password_reset']===true && $s['allow_first_link']===true &&
    $s['providers']['kavenegar']['credentials']['api_key']==='fixture-update-secret' &&
    $s['appearance']===Settings::defaults()['appearance'],'Existing settings or new conservative defaults changed');
baselineCheck(is_plugin_active($file) && (int)get_option(\ACh\WPAccounts\Lifecycle::VERSION_OPTION)===$before['schema'],'Lifecycle or schema changed');
echo "PASS: exact v0.2.0 -> v0.3.0 native ZIP update; new process, preserved native user, password, order, identity, settings and opt-in defaults.\n";
