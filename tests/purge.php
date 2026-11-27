<?php
use ACh\WPAccounts\{Lifecycle,Schema,Settings,SettingsError,Identity};
$root=getenv('ACH_TEST_WP');
if (!$root) { throw new RuntimeException('Run isolated fixture.'); }
require $root.'/wp-load.php';
require_once ABSPATH.'wp-admin/includes/plugin.php';
if (wp_get_environment_type()!=='local' || DB_NAME!=='accounts_fixture' || get_option('siteurl')!=='https://accounts.test') {
    throw new RuntimeException('Not isolated.');
}
function purgeCheck(bool $ok,string $message):void { if (!$ok) { throw new RuntimeException($message); } }
function purgeDenied(callable $operation):void {
    try { $operation(); } catch (SettingsError $error) { return; }
    throw new RuntimeException('Unexpected purge authority');
}
global $wpdb;
$admin=get_user_by('login','fixture_admin');
wp_set_current_user($admin->ID);
$file=WP_PLUGIN_DIR.'/ach-wp-accounts/ach-wp-accounts.php';
purgeDenied(fn()=>Lifecycle::purge($file,false));
purgeDenied(fn()=>Lifecycle::purge($file,true)); // enabled checkout/auth from upgrade fixture
Settings::update(['auth_enabled'=>false,'checkout_required'=>false],Settings::read()['revision']);
$users=(int)$wpdb->get_var("SELECT COUNT(*) FROM $wpdb->users");
$orders=wc_get_orders(['limit'=>-1,'return'=>'ids']);
sort($orders);
$native=get_user_by('login','fixture_migration_a');
purgeCheck($native && get_user_meta($native->ID,'billing_email',true)==='fixture-billing@example.invalid','Native data fixture missing');
wp_set_current_user($native->ID);
purgeDenied(fn()=>Lifecycle::purge($file,true));
wp_set_current_user($admin->ID);
Lifecycle::purge($file,true);
purgeCheck(!is_plugin_active(plugin_basename($file)) && !wp_next_scheduled('ach_wp_accounts_cleanup') &&
    get_option(Settings::OPTION,null)===null && get_option(Lifecycle::VERSION_OPTION,null)===null && Lifecycle::epoch()==='',
    'Purge left plugin active or options/schedule');
foreach (Schema::TABLES as $name) {
    purgeCheck($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$wpdb->esc_like(Schema::table($name))))===null,'Plugin table retained');
}
$afterOrders=wc_get_orders(['limit'=>-1,'return'=>'ids']);
sort($afterOrders);
purgeCheck((int)$wpdb->get_var("SELECT COUNT(*) FROM $wpdb->users")===$users && $orders===$afterOrders &&
    get_user_meta($native->ID,'billing_email',true)==='fixture-billing@example.invalid','Purge affected native accounts, orders or billing');
echo "PASS: explicit fixture-only purge, capability/confirmation/disabled-policy checks, native users and orders retained\n";
