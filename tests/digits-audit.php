<?php
use ACh\WPAccounts\{Identity, Migration, Schema, SettingsError};
use ACh\WPAccounts\Tools\DigitsAudit;

$root=getenv('ACH_TEST_WP'); $dir=getenv('ACH_TEST_DIR');
if (!$root || !$dir) { throw new RuntimeException('Run isolated fixture.'); }
require $root.'/wp-load.php';
require_once ABSPATH.'wp-admin/includes/user.php';
require_once dirname(__DIR__).'/scripts/audit-digits.php';
if (wp_get_environment_type()!=='local' || DB_NAME!=='accounts_fixture' || get_option('siteurl')!=='https://accounts.test') {
    throw new RuntimeException('Not isolated.');
}
function auditCheck(bool $ok,string $message):void { if (!$ok) { throw new RuntimeException($message); } }
function auditUser(string $name,string $email=''):int {
    $id=wp_insert_user(['user_login'=>$name,'user_pass'=>wp_generate_password(32),'user_email'=>$email,'role'=>'customer']);
    auditCheck(is_int($id),'Fixture user creation failed');
    return $id;
}
function auditMeta(int $id,string $main,string $cc,string $local):void {
    add_user_meta($id,'digits_phone',$main);
    add_user_meta($id,'digt_countrycode',$cc);
    add_user_meta($id,'digits_phone_no',$local);
}
global $wpdb;
$admin=get_user_by('login','fixture_admin');
wp_set_current_user($admin->ID);
$empty=Migration::run(['source'=>'digits']);
auditCheck($empty['digest']==='' && $empty['total']===0 && !$empty['source_report']['draft_available'],'Empty source preview not distinguishable');
try { Migration::run(['source'=>'digits','digest'=>'','confirm'=>true],true); throw new LogicException('Empty source was executable'); }
catch (SettingsError $error) { auditCheck($error->getMessage()==='source_not_ready','Wrong empty-source error'); }
$a=auditUser('fixture_digits_a');
$b=auditUser('fixture_digits_b','digits-b@example.invalid');
$c=auditUser('fixture_digits_c');
$orphan=99999999;
auditCheck(!get_userdata($orphan),'Expected absent fixture user');
auditMeta($a,'+989125550001','+98','9125550001');
auditMeta($b,'+989125550002','+98912','5550002'); // unusual but matching split
auditMeta($c,'09125550001','+98','9125550001'); // same canonical number, different user
auditMeta($admin->ID,'+989125550003','+98','9125550003');
auditMeta($orphan,'+989125550004','+98','9125550004');
add_user_meta($a,'digits_phone_verification_skipped','pending');
$sourceBefore=hash('sha256',serialize($wpdb->get_results("SELECT * FROM $wpdb->usermeta WHERE meta_key IN ('digits_phone','digt_countrycode','digits_phone_no','digits_phone_verification_skipped') ORDER BY umeta_id")));
$table=Schema::table('identities');
$identitiesBefore=(int)$wpdb->get_var("SELECT COUNT(*) FROM $table");
$nativeBefore=(int)$wpdb->get_var("SELECT COUNT(*) FROM $wpdb->users");
wp_set_current_user($a);
try { DigitsAudit::inspect(); throw new RuntimeException('Unauthorized audit allowed'); }
catch (SettingsError $e) { auditCheck($e->getMessage()==='forbidden','Unexpected authorization failure'); }
try { Migration::run(['source'=>'digits']); throw new LogicException('Unauthorized direct migration preview'); }
catch (SettingsError $error) { auditCheck($error->getMessage()==='forbidden','Unexpected direct-source authorization failure'); }
wp_set_current_user($admin->ID);
$path=$dir.'/digits-review-draft.json';
try {
    $report=DigitsAudit::inspect($path);
    auditCheck($report['source_users']===5 && $report['matching_primary_and_split']===5 &&
        $report['nonstandard_matching_split']===1 && $report['shared_numbers_among_resolved_candidates']===1 &&
        $report['missing_native_accounts']===1 && $report['ineligible_native_accounts']===1 &&
        $report['eligible_native_accounts']===3 && $report['eligible_accounts_without_email']===2 &&
        $report['source_rows']['digits_phone_verification_skipped']===1 && $report['historical_verification_assertions']===0 &&
        $report['draft_written'] && $report['draft_rows']===5,'Unexpected source audit classification');
    auditCheck(!str_contains(wp_json_encode($report),'+98912555') && !str_contains(wp_json_encode($report),'digits-b@example.invalid'),'Aggregate exposed private fields');
    clearstatcache(true,$path);
    auditCheck((fileperms($path)&0777)===0600,'Draft is not owner-only');
    $draft=json_decode(file_get_contents($path),true,512,JSON_THROW_ON_ERROR);
    auditCheck(count($draft['rows'])===5 && array_column($draft['rows'],'selected')===array_fill(0,5,true) && !array_key_exists('verified',$draft['rows'][0]),'Draft misrepresented selection or asserted proof');
    $preview=Migration::run($draft);
    $states=array_count_values(array_column($preview['items'],'state'));
    auditCheck(($states['source_conflict']??0)===2 && ($states['ready']??0)===1 && ($states['ineligible_account']??0)===2 && count($states)===3,'Draft mapping classification incorrect');
    $direct=Migration::run(['source'=>'digits']);
    auditCheck($direct['digest']!==$preview['digest'] && $direct['items']===$preview['items'] &&
        $direct['source_report']['draft_available'] && !str_contains(wp_json_encode($direct),'+98912555') &&
        !isset($direct['source_report']['native_users']),'Direct source did not match the private draft or exposed data');
    if (function_exists('wp_get_ability')) {
        $actual=wp_get_ability('ach-wp-accounts/migration-preview')->execute(['source'=>'digits']);
        auditCheck(is_array($actual) && $actual===$direct,'Direct source native Ability schema mismatch');
    }
    $directPage=Migration::run(['source'=>'digits','limit'=>1,'offset'=>1]);
    auditCheck($directPage['digest']===$direct['digest'] && $directPage['items'][0]===$direct['items'][1],
        'Direct source page digest changed');
    try { Migration::run(['source'=>'digits']+$draft); throw new LogicException('Mixed sources accepted'); }
    catch (SettingsError $error) { auditCheck($error->getMessage()==='invalid_source','Unexpected mixed-source error'); }
    try { Migration::run(['source'=>'digits','digest'=>$preview['digest'],'confirm'=>true],true); throw new LogicException('Cross-source digest accepted'); }
    catch (SettingsError $error) { auditCheck($error->getMessage()==='confirmation_required','Unexpected cross-source error'); }
    // A complete, coherent source change must invalidate the prior digest.
    update_user_meta($b,'digits_phone','+989125550005');
    update_user_meta($b,'digits_phone_no','5550005');
    $changed=Migration::run(['source'=>'digits']);
    auditCheck($changed['digest']!==$direct['digest'] && $changed['source_report']['draft_available'],'Source change not detected');
    try { Migration::run(['source'=>'digits','digest'=>$direct['digest'],'confirm'=>true],true); throw new LogicException('Stale direct digest accepted'); }
    catch (SettingsError $error) { auditCheck($error->getMessage()==='confirmation_required','Unexpected stale source error'); }
    update_user_meta($b,'digits_phone','+989125550002');
    update_user_meta($b,'digits_phone_no','5550002');
    $original=file_get_contents($path);
    try { DigitsAudit::inspect($path); throw new LogicException('Existing draft overwritten'); }
    catch (RuntimeException $e) { auditCheck($e->getMessage()==='new_private_file_required','Unexpected overwrite rejection'); }
    auditCheck(file_get_contents($path)===$original,'Existing draft changed');
    try { DigitsAudit::inspect($root.'/private-digits.json'); throw new LogicException('Webroot draft allowed'); }
    catch (RuntimeException $e) { auditCheck($e->getMessage()==='private_path_required','Unexpected private-path rejection'); }

    // A live source change or ambiguous split must not generate an apparently
    // complete draft. The source is retained for manual reconciliation.
    add_user_meta($b,'digits_phone','not-a-mobile');
    add_user_meta($b,'digits_phone_no','9125559999');
    $badPath=$dir.'/digits-incomplete.json';
    $bad=DigitsAudit::inspect($badPath);
    auditCheck(!$bad['draft_available'] && !$bad['draft_written'] && !file_exists($badPath) &&
        $bad['incomplete_or_conflicting_source_users']===1 && $bad['duplicate_primary_users']===1 &&
        $bad['invalid_primary_rows']===1,'Ambiguous source produced an automatic draft');
    $blocked=Migration::run(['source'=>'digits']);
    auditCheck($blocked['digest']==='' && $blocked['items']===[] &&
        $blocked['source_report']['incomplete_or_conflicting_source_users']===1,'Incomplete source omitted possible conflict');
    try { Migration::run(['source'=>'digits','digest'=>$direct['digest'],'confirm'=>true],true); throw new LogicException('Incomplete source imported'); }
    catch (SettingsError $error) { auditCheck($error->getMessage()==='source_not_ready','Wrong incomplete-source error'); }
    delete_user_meta($b,'digits_phone','not-a-mobile');
    delete_user_meta($b,'digits_phone_no','9125559999');
    auditCheck(hash_equals($sourceBefore,hash('sha256',serialize($wpdb->get_results("SELECT * FROM $wpdb->usermeta WHERE meta_key IN ('digits_phone','digt_countrycode','digits_phone_no','digits_phone_verification_skipped') ORDER BY umeta_id")))),'Source data changed during audit');
    auditCheck((int)$wpdb->get_var("SELECT COUNT(*) FROM $table")===$identitiesBefore &&
        (int)$wpdb->get_var("SELECT COUNT(*) FROM $wpdb->users")===$nativeBefore,'Audit changed identities or native users');
} finally { if (is_file($path)) { unlink($path); } }

// Rehearse the observed site scale without copying any live customer data.
// Include a distant excluded conflicting row and exactly one eligible
// selected row, then resume/replay every page of the exact manifest.
$rows=[];
for ($i=0;$i<3791;$i++) {
    $rows[]=['user_id'=>1000000+$i,'mobile'=>'+9891205'.str_pad((string)$i,5,'0',STR_PAD_LEFT),'selected'=>false];
}
$rows[0]['user_id']=$a; $rows[0]['selected']=true;
$rows[1]['user_id']=$b; $rows[1]['selected']=true;
$rows[3790]['mobile']=$rows[0]['mobile'];
$manifest=['evidence'=>'Synthetic scale rehearsal; not actual source evidence','rows'=>$rows];
$offset=0; $digest=null; $states=[]; $pages=0;
do {
    $page=Migration::run($manifest+['offset'=>$offset,'limit'=>100]);
    $digest??=$page['digest'];
    auditCheck($digest===$page['digest'] && $page['total']===3791,'Manifest identity drift');
    foreach ($page['items'] as $item) { $states[$item['state']]=($states[$item['state']]??0)+1; }
    $offset=$page['next_offset']; $pages++;
} while ($offset!==null);
auditCheck($pages===38 && ($states['ready']??0)===1 && ($states['source_conflict']??0)===2 &&
    ($states['excluded']??0)===3788 && array_sum($states)===3791,'Full scale preview misclassified candidates');
$offset=0; $states=[];
do {
    $page=Migration::run($manifest+['offset'=>$offset,'limit'=>100,'digest'=>$digest,'confirm'=>true],true);
    foreach ($page['items'] as $item) { $states[$item['state']]=($states[$item['state']]??0)+1; }
    $offset=$page['next_offset'];
} while ($offset!==null);
$identity=Identity::byUser($b);
auditCheck(($states['imported']??0)===1 && ($states['source_conflict']??0)===2 && ($states['excluded']??0)===3788 &&
    $identity && $identity->source==='digits' && $identity->source_ref===$digest && (int)$identity->verified_at===0 &&
    Identity::byUser($a)===null && (int)$wpdb->get_var("SELECT COUNT(*) FROM $wpdb->users")===$nativeBefore &&
    (int)$wpdb->get_var("SELECT COUNT(*) FROM $table")===$identitiesBefore+1,'Scale import did not preserve native accounts or conflicts');
$again=Migration::run($manifest+['offset'=>0,'limit'=>100,'digest'=>$digest,'confirm'=>true],true);
auditCheck($again['items'][0]['state']==='source_conflict' && $again['items'][1]['state']==='already_bound' &&
    Identity::byUser($b)->revision===$identity->revision,'Scale replay rewrote a binding');
// Direct site-local apply, with a separate native user and no manifest transfer.
$local=auditUser('fixture_digits_direct');
auditMeta($local,'+989125550006','+98','9125550006');
$count=(int)$wpdb->get_var("SELECT COUNT(*) FROM $wpdb->users");
$adminBefore=Identity::byUser($admin->ID);
$preview=Migration::run(['source'=>'digits']);
auditCheck($preview['total']===6 && $preview['source_report']['draft_available'],'Direct source inventory incomplete');
$input=['source'=>'digits','digest'=>$preview['digest'],'confirm'=>true];
$apply=function_exists('wp_get_ability') ?
    wp_get_ability('ach-wp-accounts/migration-execute')->execute($input) : Migration::run($input,true);
auditCheck(is_array($apply) && $apply['source_report']===$preview['source_report'] &&
    array_count_values(array_column($apply['items'],'state'))==array_count_values(['source_conflict','source_conflict',
        'ineligible_account','ineligible_account','identity_conflict','imported']), 'Direct source apply misclassified accounts');
$binding=Identity::byUser($local);
auditCheck($binding && $binding->source_ref===$preview['digest'] && (int)$binding->verified_at===0 &&
    (int)$wpdb->get_var("SELECT COUNT(*) FROM $wpdb->users")===$count && Identity::byUser($a)===null &&
    Identity::byUser($c)===null && Identity::byUser($admin->ID)==$adminBefore,'Direct source wrote an unsafe binding');
$replay=Migration::run($input,true);
auditCheck(in_array('already_bound',array_column($replay['items'],'state'),true) &&
    Identity::byUser($local)->revision===$binding->revision,'Direct source replay changed binding');
echo "PASS: direct Digits source preview/apply, no raw export, complete conflicts, stale/mixed/empty source refusal and idempotent replay\n";
echo "PASS: read-only Digits audit, private candidate draft, split/conflict/role classification, 3,791-row reviewed manifest preview/apply/resume/replay\n";
