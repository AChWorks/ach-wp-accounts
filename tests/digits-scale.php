<?php
/** Restored-site-scale migration rehearsal; synthetic isolated native data only. */
use ACh\WPAccounts\{Identity, Migration, Schema};

$root=getenv('ACH_TEST_WP');
if (!$root) { throw new RuntimeException('Run isolated fixture.'); }
require $root.'/wp-load.php';
require_once ABSPATH.'wp-admin/includes/user.php';
if (wp_get_environment_type()!=='local' || DB_NAME!=='accounts_fixture' || get_option('siteurl')!=='https://accounts.test') {
    throw new RuntimeException('Not isolated.');
}
function scaleCheck(bool $condition,string $message):void {
    if (!$condition) { throw new RuntimeException($message); }
}
global $wpdb;
$admin=get_user_by('login','fixture_admin');
wp_set_current_user($admin->ID);
$baseline=Migration::run(['source'=>'digits']);
scaleCheck($baseline['total']===6 && $baseline['source_report']['draft_available'],'Unexpected direct fixture baseline');
$nativeBefore=(int)$wpdb->get_var("SELECT COUNT(*) FROM $wpdb->users");
$prefix='fixture_digits_scale_';
$count=3791;
$started=microtime(true);

// Seed a synthetic restored WordPress snapshot in batches. This is fixture
// setup, not plugin migration code or production account creation.
$hash=wp_hash_password(wp_generate_password(64));
$now=gmdate('Y-m-d H:i:s');
for ($start=0;$start<$count;$start+=200) {
    $values=[];
    for ($i=$start;$i<min($start+200,$count);$i++) {
        $login=$prefix.str_pad((string)$i,5,'0',STR_PAD_LEFT);
        $values[]=$wpdb->prepare("(%s,%s,%s,%s,%s)",$login,$hash,$login,$now,$login);
    }
    scaleCheck($wpdb->query("INSERT INTO $wpdb->users (user_login,user_pass,user_nicename,user_registered,display_name) VALUES ".implode(',',$values))===count($values),'Synthetic native snapshot seed failed');
}
$users=$wpdb->get_results($wpdb->prepare("SELECT ID,user_login FROM $wpdb->users WHERE user_login LIKE %s ORDER BY user_login",$wpdb->esc_like($prefix).'%'));
scaleCheck(count($users)===$count,'Synthetic snapshot user count mismatch');
foreach (array_chunk($users,200,true) as $group) {
    $values=[];
    foreach ($group as $i=>$user) {
        $suffix=str_pad((string)$i,5,'0',STR_PAD_LEFT);
        scaleCheck($user->user_login===$prefix.$suffix,'Synthetic native ID ordering mismatch');
        $mobile='91206'.$suffix;
        foreach ([$wpdb->prefix.'capabilities'=>maybe_serialize(['customer'=>true]),
            'digits_phone'=>'+98'.$mobile,'digt_countrycode'=>'+98','digits_phone_no'=>$mobile] as $key=>$value) {
            $values[]=$wpdb->prepare("(%d,%s,%s)",(int)$user->ID,$key,$value);
        }
    }
    scaleCheck($wpdb->query("INSERT INTO $wpdb->usermeta (user_id,meta_key,meta_value) VALUES ".implode(',',$values))===count($values),'Synthetic Digits snapshot seed failed');
}
unset($hash,$users,$values);
$nativeTotal=$nativeBefore+$count;
scaleCheck((int)$wpdb->get_var("SELECT COUNT(*) FROM $wpdb->users")===$nativeTotal,'Unexpected native snapshot size');
$offset=0;$digest=null;$states=[];$pages=0;
$scanStarted=microtime(true);
do {
    $page=Migration::run(['source'=>'digits','offset'=>$offset,'limit'=>100]);
    $digest??=$page['digest'];
    scaleCheck($page['digest']===$digest && $page['total']===$count+6 &&
        $page['source_report']['draft_rows']===$count+6,'Site-local scale digest or source coverage drift');
    foreach ($page['items'] as $item) { $states[$item['state']]=($states[$item['state']]??0)+1; }
    $offset=$page['next_offset'];$pages++;
} while ($offset!==null);
scaleCheck($pages===38 && ($states['ready']??0)===$count && ($states['source_conflict']??0)===2 &&
    ($states['ineligible_account']??0)===2 && ($states['identity_conflict']??0)===1 &&
    ($states['already_bound']??0)===1 && array_sum($states)===$count+6,'Direct full-size preview misclassified source: '.wp_json_encode(['pages'=>$pages,'states'=>$states,'baseline'=>array_count_values(array_column($baseline['items'],'state'))]));
$previewSeconds=microtime(true)-$scanStarted;
$applyStarted=microtime(true);
$offset=0;$states=[];
do {
    $page=Migration::run(['source'=>'digits','offset'=>$offset,'limit'=>100,'digest'=>$digest,'confirm'=>true],true);
    foreach ($page['items'] as $item) { $states[$item['state']]=($states[$item['state']]??0)+1; }
    $offset=$page['next_offset'];
} while ($offset!==null);
$table=Schema::table('identities');
$matched=(int)$wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM $table i INNER JOIN $wpdb->users u ON u.ID=i.user_id
     INNER JOIN $wpdb->usermeta m ON m.user_id=u.ID AND m.meta_key='digits_phone' AND m.meta_value=i.mobile
     WHERE u.user_login LIKE %s AND i.source='digits' AND i.source_ref=%s AND i.verified_at=0",
    $wpdb->esc_like($prefix).'%',$digest));
scaleCheck(($states['imported']??0)===$count && ($states['already_bound']??0)===1 &&
    ($states['source_conflict']??0)===2 && ($states['ineligible_account']??0)===2 &&
    ($states['identity_conflict']??0)===1 && array_sum($states)===$count+6 &&
    $matched===$count && (int)$wpdb->get_var("SELECT COUNT(*) FROM $wpdb->users")===$nativeTotal,
    'Direct site-scale import did not preserve native accounts, mapping or pending verification');
$applySeconds=microtime(true)-$applyStarted;
$first=Migration::run(['source'=>'digits','offset'=>0,'limit'=>100,'digest'=>$digest,'confirm'=>true],true);
$last=Migration::run(['source'=>'digits','offset'=>3700,'limit'=>100,'digest'=>$digest,'confirm'=>true],true);
scaleCheck(!in_array('imported',array_column($first['items'],'state'),true) &&
    !in_array('write_unconfirmed',array_column($first['items'],'state'),true) &&
    !in_array('imported',array_column($last['items'],'state'),true) &&
    !in_array('write_unconfirmed',array_column($last['items'],'state'),true) &&
    (int)$wpdb->get_var("SELECT COUNT(*) FROM $wpdb->users")===$nativeTotal,'Scale page replay changed native accounts');
printf("PASS: direct site-local preview/import of %d actual synthetic native accounts, 38 pages, exact read-back, no asserted possession or new users; preview %.2fs, apply %.2fs, total %.2fs\n",
    $count,$previewSeconds,$applySeconds,microtime(true)-$started);
