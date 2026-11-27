<?php
use ACh\WPAccounts\{AccountFlow,AccountPolicy,BrowserApi,CheckoutGate,EmailRecovery,Identity,Lifecycle,Migration,Privacy,RegistrationRecovery,Schema,SessionProof,Settings,SettingsError,VerificationError,SmsSender,SendResult};

$root=getenv('ACH_TEST_WP');
$dir=getenv('ACH_TEST_DIR');
if (!$root || !$dir) { throw new RuntimeException('Run isolated fixture.'); }
require $root.'/wp-load.php';
require_once ABSPATH.'wp-admin/includes/plugin.php';
require_once ABSPATH.'wp-admin/includes/user.php';
if (wp_get_environment_type()!=='local' || DB_NAME!=='accounts_fixture' || get_option('siteurl')!=='https://accounts.test') {
    throw new RuntimeException('Not isolated.');
}
function lifeCheck(bool $ok,string $message): void {
    if (!$ok) { throw new RuntimeException($message); }
}
function lifeError(callable $operation,string $code): void {
    try { $operation(); } catch (SettingsError|VerificationError $error) {
        lifeCheck($error->getMessage()===$code,'Unexpected rejection: '.$error->getMessage());
        return;
    }
    throw new RuntimeException('Expected rejection: '.$code);
}
function fixtureUser(string $name,string $email='',bool $managed=false): int {
    $args=['user_login'=>$name,'user_pass'=>wp_generate_password(32),'user_email'=>$email,'role'=>'customer'];
    if ($managed) { $args['meta_input']=['_ach_accounts_registration'=>bin2hex(random_bytes(16))]; }
    $id=wp_insert_user($args);
    lifeCheck(is_int($id),'Native fixture user failed');
    return $id;
}
global $wpdb;
$admin=get_user_by('login','fixture_admin');
wp_set_current_user($admin->ID);
add_filter('pre_http_request',static fn()=>new WP_Error('fixture_no_network'),PHP_INT_MAX);
add_filter('pre_wp_mail','__return_true');
$plugin='ach-wp-accounts/ach-wp-accounts.php';
$table=Schema::table('identities');

// Preview is read-only and checks conflicts across pages of the same manifest.
$a=fixtureUser('fixture_migration_a','mig-a@example.invalid');
$b=fixtureUser('fixture_migration_b');
$c=fixtureUser('fixture_migration_c');
$d=fixtureUser('fixture_migration_d');
$e=fixtureUser('fixture_migration_e');
$f=fixtureUser('fixture_migration_f');
$g=fixtureUser('fixture_migration_g');
$h=fixtureUser('fixture_migration_h');
$i=fixtureUser('fixture_migration_i');
$j=fixtureUser('fixture_migration_j');
// Existing native sessions predate the migration; importing a mapping
// must not confer new possession/session authority.
$oldSessions=WP_Session_Tokens::get_instance($a);
$oldToken=$oldSessions->create(time()+3600);
$adminSessions=WP_Session_Tokens::get_instance($admin->ID);
$adminToken=$adminSessions->create(time()+3600);
Identity::bind($f,'09123330006','absent');
Identity::bind($g,'09123330007','absent');
$reserved=Identity::reserveRegistration('09123330008');
$manifest=['evidence'=>'Synthetic reviewed source fixture; not a live Digits export','rows'=>[
    ['user_id'=>$a,'mobile'=>'۰۹۱۲۳۳۳۰۰۰۱','selected'=>true],
    ['user_id'=>$b,'mobile'=>'09123330002','selected'=>true],
    ['user_id'=>$c,'mobile'=>'+989123330002','selected'=>false],
    ['user_id'=>$d,'mobile'=>'09123330004','selected'=>false],
    ['user_id'=>$e,'mobile'=>'invalid','selected'=>true],
    ['user_id'=>$admin->ID,'mobile'=>'09123330005','selected'=>true],
    ['user_id'=>$h,'mobile'=>'09123330006','selected'=>true],
    ['user_id'=>$g,'mobile'=>'09123330009','selected'=>true],
    ['user_id'=>$i,'mobile'=>'09123330008','selected'=>true],
    ['user_id'=>$j,'mobile'=>'09123330010','selected'=>true],
]];
$nativeCount=(int)$wpdb->get_var("SELECT COUNT(*) FROM $wpdb->users");
$identityCount=(int)$wpdb->get_var("SELECT COUNT(*) FROM $table");
wp_set_current_user($a);
lifeError(fn()=>Migration::run($manifest),'forbidden');
lifeError(fn()=>RegistrationRecovery::inspect(),'forbidden');
wp_set_current_user($admin->ID);
$preview=Migration::run($manifest+['limit'=>2]);
lifeCheck(array_column($preview['items'],'state')===['ready','source_conflict'] && $preview['next_offset']===2 &&
    (int)$wpdb->get_var("SELECT COUNT(*) FROM $table")===$identityCount,'Preview mutated state or missed cross-page collision');
// A review digest belongs to this site, not merely the same copied data.
$alternateHome=static fn()=> 'https://other-accounts.example.invalid';
add_filter('pre_option_home',$alternateHome);
lifeError(fn()=>Migration::run($manifest+['confirm'=>true,'digest'=>$preview['digest']],true),'confirmation_required');
remove_filter('pre_option_home',$alternateHome);
$states=Migration::run($manifest)['items'];
lifeCheck(array_column($states,'state')===['ready','source_conflict','source_conflict','excluded','invalid_mobile',
    'ineligible_account','identity_conflict','identity_conflict','identity_conflict','ready'],'Migration classification incorrect');
lifeCheck(!str_contains(wp_json_encode($preview),'+989123330001') && !str_contains(wp_json_encode($preview),'Synthetic reviewed'),'Preview exposed raw manifest');
lifeError(fn()=>Migration::run($manifest+['confirm'=>false,'digest'=>$preview['digest']],true),'confirmation_required');
$changed=$manifest;
$changed['evidence']='changed evidence';
lifeError(fn()=>Migration::run($changed+['confirm'=>true,'digest'=>$preview['digest']],true),'confirmation_required');
if (function_exists('wp_get_ability')) {
    $p=wp_get_ability('ach-wp-accounts/migration-preview')->execute($manifest+['limit'=>2]);
    lifeCheck(is_array($p) && $p===$preview,'Native migration preview schema mismatch');
    $applied=wp_get_ability('ach-wp-accounts/migration-execute')->execute($manifest+['limit'=>2,'digest'=>$preview['digest'],'confirm'=>true]);
} else {
    $applied=Migration::run($manifest+['limit'=>2,'digest'=>$preview['digest'],'confirm'=>true],true);
}
lifeCheck(is_array($applied) && array_column($applied['items'],'state')===['imported','source_conflict'],'Exact confirmed page did not import');
$imported=Identity::byUser($a);
$privateBefore=Privacy::exportUser($a);
lifeCheck(!str_contains(wp_json_encode($privateBefore),'Verified mobile number') && !str_contains(wp_json_encode($privateBefore),'Verification time (UTC)'), 'Privacy export fabricated proof');
lifeCheck($imported && $imported->source==='digits' && $imported->source_ref===$preview['digest'] &&
    (int)$imported->verified_at===0,'Migration asserted possession or lost provenance');
$again=Migration::run($manifest+['limit'=>2,'confirm'=>true,'digest'=>$preview['digest']],true);
lifeCheck($again['items'][0]['state']==='already_bound' && Identity::byUser($a)->revision===$imported->revision,'Import retry rewrote identity');
Identity::bind($j,'09123330011','absent'); // live change after preview
$rest=Migration::run($manifest+['offset'=>2,'digest'=>$preview['digest'],'confirm'=>true],true);
lifeCheck($rest['next_offset']===null && end($rest['items'])['state']==='identity_conflict' &&
    (int)$wpdb->get_var("SELECT COUNT(*) FROM $wpdb->users")===$nativeCount,'Migration overwrote a new binding or created users');

// Rehearse the approved cutover: preserve the existing account and native
// administrator, revoke only the migrated customer's old native sessions,
// and require real OTP before checkout qualification.
$settingsBefore=Settings::current();
$settings=$settingsBefore; $settings['checkout_required']=true;
update_option(Settings::OPTION,$settings,false);
wp_set_current_user($a);
$_COOKIE[LOGGED_IN_COOKIE]=wp_generate_auth_cookie($a,time()+3600,'logged_in',$oldToken);
lifeCheck(SessionProof::currentToken($a)===$oldToken && !SessionProof::qualifies() &&
    CheckoutGate::blocked() && (int)Identity::byUser($a)->verified_at===0,'Migration conferred possession on an old session');
$oldSessions->destroy_all();
lifeCheck(SessionProof::currentToken($a)==='' && $adminSessions->verify($adminToken),'Scoped native session revocation failed');
wp_set_current_user(0);
unset($_COOKIE[LOGGED_IN_COOKIE]);
$oldNative=get_userdata($a);
$otpCapture=static function($cookie):void { $_COOKIE[LOGGED_IN_COOKIE]=$cookie; };
add_action('set_logged_in_cookie',$otpCapture);
$wpdb->query("TRUNCATE TABLE ".Schema::table('limits')); // isolated fixture only
$legacyFlow=new AccountFlow();
$legacyBrowser=bin2hex(random_bytes(32));
$legacyTicket=$legacyFlow->start($legacyBrowser,$imported->mobile,'login','account');
$legacySender=new class implements SmsSender {
    public string $code='';
    public function id():string{return 'fixture';}
    public function send(string $mobile,string $code,string $purpose):SendResult{
        $this->code=$code;return new SendResult('accepted','fixture','legacy');
    }
};
$legacyFlow->issue($legacyBrowser,$legacyTicket,'192.0.2.30',$legacySender);
$wrong=$legacySender->code===str_repeat('0',strlen($legacySender->code)) ?
    str_repeat('1',strlen($legacySender->code)) : str_repeat('0',strlen($legacySender->code));
lifeError(fn()=> $legacyFlow->complete($legacyBrowser,$legacyTicket,$wrong),'invalid_proof');
lifeCheck((int)Identity::byUser($a)->verified_at===0 && get_current_user_id()===0 && CheckoutGate::blocked(),
    'Wrong OTP verified imported identity');
$result=$legacyFlow->complete($legacyBrowser,$legacyTicket,$legacySender->code);
$current=Identity::byUser($a);
lifeCheck($result===['outcome'=>'authenticated','created'=>false] && get_current_user_id()===$a &&
    SessionProof::qualifies() && !CheckoutGate::blocked() && $current->revision===$imported->revision &&
    $current->source==='digits' && $current->source_ref===$preview['digest'] && (int)$current->verified_at>0 &&
    get_userdata($a)->user_email===$oldNative->user_email && get_userdata($a)->user_pass===$oldNative->user_pass &&
    get_userdata($a)->roles===$oldNative->roles &&
    (int)$wpdb->get_var("SELECT COUNT(*) FROM $wpdb->users")===$nativeCount,
    'First fresh OTP failed to preserve the legacy account and provenance');
// Replaying the manifest must not erase newly established possession.
wp_set_current_user($admin->ID);
$afterOtp=Migration::run($manifest+['limit'=>2,'digest'=>$preview['digest'],'confirm'=>true],true);
lifeCheck($afterOtp['items'][0]['state']==='already_bound' &&
    (int)Identity::byUser($a)->verified_at===(int)$current->verified_at,'Import replay erased fresh verification');

// An existing native password login on an imported phone-only account may
// step up in-session, but never qualifies simply because the mapping exists.
$phoneManifest=['evidence'=>'Synthetic existing phone-only mapping','rows'=>[
    ['user_id'=>$d,'mobile'=>'09123330004','selected'=>true]
]];
$phonePreview=Migration::run($phoneManifest);
$phoneApply=Migration::run($phoneManifest+['digest'=>$phonePreview['digest'],'confirm'=>true],true);
lifeCheck($phoneApply['items'][0]['state']==='imported','Phone-only mapping failed');
wp_set_current_user($d);
wp_set_auth_cookie($d,false,true);
$stepToken=SessionProof::currentToken($d);
$pendingIdentity=Identity::byUser($d);
lifeCheck($stepToken!=='' && (int)$pendingIdentity->verified_at===0 && CheckoutGate::blocked(),
    'Imported phone-only mapping qualified before OTP');
$stepBrowser=bin2hex(random_bytes(32));
$stepTicket=$legacyFlow->start($stepBrowser,$pendingIdentity->mobile,'step_up','account');
$legacyFlow->issue($stepBrowser,$stepTicket,'192.0.2.31',$legacySender);
lifeCheck($legacyFlow->complete($stepBrowser,$stepTicket,$legacySender->code)===['outcome'=>'verified','created'=>false] &&
    SessionProof::currentToken($d)===$stepToken && SessionProof::qualifies() &&
    (int)Identity::byUser($d)->verified_at>0 && get_userdata($d)->user_email==='',
    'Fresh step-up failed for imported phone-only account');
remove_action('set_logged_in_cookie',$otpCapture);
unset($_COOKIE[LOGGED_IN_COOKIE]);
wp_set_current_user($admin->ID);
update_option(Settings::OPTION,$settingsBefore,false);

// Native account recovery never repeats an uncertain registration.
$pending=Identity::byMobile('+989121111116');
lifeCheck($pending && (int)$pending->registration_started===1,'Expected interrupted-registration fixture absent');
$native=get_user_by('login',$pending->registration_login);
lifeCheck($native instanceof WP_User,'Expected existing native account');
$inspect=RegistrationRecovery::inspect();
$byId=array_column($inspect['items'],null,'reservation_id');
lifeCheck($byId[$pending->revision]['state']==='matching_native_account' &&
    $byId[$reserved->revision]['state']==='unstarted','Registration classification incorrect');
lifeError(fn()=>RegistrationRecovery::reconcile($pending->revision,$native->ID,false),'confirmation_required');
lifeError(fn()=>RegistrationRecovery::reconcile($pending->revision,$a,true),'identity_conflict');
lifeError(fn()=>RegistrationRecovery::release($pending->revision,true),'registration_pending');
$reconciled=RegistrationRecovery::reconcile($pending->revision,$native->ID,true);
lifeCheck($reconciled['user_id']===$native->ID && (int)Identity::byMobile($pending->mobile)->user_id===$native->ID &&
    (int)$wpdb->get_var("SELECT COUNT(*) FROM $wpdb->users")===$nativeCount,'Reconciliation created a new user');
lifeError(fn()=>RegistrationRecovery::reconcile($pending->revision,$native->ID,true),'registration_pending');
lifeError(fn()=>RegistrationRecovery::release($reserved->revision,false),'confirmation_required');
lifeCheck(RegistrationRecovery::release($reserved->revision,true)['outcome']==='released' &&
    Identity::byMobile($reserved->mobile)===null,'Unstarted reservation not released');
$ambiguous=Identity::reserveRegistration('09123330012');
Identity::markRegistrationStarted($ambiguous);
lifeError(fn()=>RegistrationRecovery::release($ambiguous->revision,true),'registration_pending');
lifeCheck(Identity::byMobile($ambiguous->mobile)!==null,'Ambiguous reservation erased');

// Schema marker is committed only after actual installation succeeds.
$identityHash=hash('sha256',serialize($wpdb->get_results("SELECT * FROM $table ORDER BY mobile")));
update_option(Lifecycle::VERSION_OPTION,0,false);
$recoveryTable=Schema::table('recovery_emails');
$wpdb->query("ALTER TABLE $recoveryTable DROP INDEX retire_at");
$denyDdl=static fn($sql)=>str_starts_with($sql,"ALTER TABLE $recoveryTable") ? 'SELECT 1' : $sql;
add_filter('query',$denyDdl,PHP_INT_MAX);
$failed=false;
try { Lifecycle::install(); } catch (Throwable $error) { $failed=true; }
remove_filter('query',$denyDdl,PHP_INT_MAX);
lifeCheck($failed && (int)get_option(Lifecycle::VERSION_OPTION)===0 && !Lifecycle::ready(),'Incomplete schema marked ready');
Lifecycle::install();
lifeCheck(Lifecycle::ready() && hash_equals($identityHash,hash('sha256',serialize($wpdb->get_results("SELECT * FROM $table ORDER BY mobile")))),'Resumed schema install changed identities');
update_option(Lifecycle::VERSION_OPTION,Schema::VERSION+1,false);
lifeError(fn()=>Lifecycle::install(true),'schema_unsupported');
lifeCheck(!Lifecycle::ready() && Lifecycle::error()==='schema_unsupported' && !BrowserApi::enabled() && !SessionProof::qualifies(),'Unsupported newer schema allowed authority');
update_option(Lifecycle::VERSION_OPTION,Schema::VERSION,false);

// Native transitions discard browser flow authority, including other tabs.
$_COOKIE[BrowserApi::cookieName()]=bin2hex(random_bytes(32));
do_action('wp_login',$admin->user_login,$admin);
lifeCheck(!isset($_COOKIE[BrowserApi::cookieName()]),'Native login retained browser flow cookie');
$_COOKIE[BrowserApi::cookieName()]=bin2hex(random_bytes(32));
do_action('clear_auth_cookie');
lifeCheck(!isset($_COOKIE[BrowserApi::cookieName()]),'Native cookie clear retained browser flow cookie');

// Explicit deactivation/reactivation invalidates old OTP/session/email proofs,
// but native account and credentials remain intact.
$phoneOnly=fixtureUser('fixture_lifecycle_phone','',true);
$phoneIdentity=Identity::bind($phoneOnly,'09123330013','absent');
$cookieCapture=static function($cookie):void { $_COOKIE[LOGGED_IN_COOKIE]=$cookie; };
add_action('set_logged_in_cookie',$cookieCapture);
wp_set_current_user($phoneOnly);
wp_set_auth_cookie($phoneOnly,false,true);
$nativeToken=SessionProof::currentToken($phoneOnly);
lifeCheck($nativeToken!=='','Native session fixture missing');
SessionProof::record($phoneOnly,$nativeToken,$phoneIdentity);
$token='';
$mail=static function($pre,$args) use (&$token) {
    if (preg_match('/ach_confirm_email=([a-f0-9]{64})/',$args['message'],$m)) { $token=$m[1]; }
    return true;
};
add_filter('pre_wp_mail',$mail,99,2);
EmailRecovery::request('life-confirm@example.invalid');
lifeCheck($token!=='' && SessionProof::qualifies(),'Email/session proof setup failed');
$browser=bin2hex(random_bytes(32));
$flow=new AccountFlow();
$ticket=$flow->start($browser,$phoneIdentity->mobile,'step_up','account');
$epoch=Lifecycle::epoch();
wp_set_current_user($admin->ID);
deactivate_plugins($plugin);
lifeCheck(!is_plugin_active($plugin) && !wp_next_scheduled('ach_wp_accounts_cleanup') && Lifecycle::epoch()!==$epoch &&
    Identity::byUser($phoneOnly)!==null,'Explicit deactivation lost identity or left epoch/schedule');
$result=activate_plugin($plugin);
lifeCheck(!is_wp_error($result) && Lifecycle::ready() && is_plugin_active($plugin),'Reactivation failed');
wp_set_current_user($phoneOnly);
lifeCheck(SessionProof::currentToken($phoneOnly)===$nativeToken && !SessionProof::qualifies(),'Reactivation revived old proof or revoked unrelated native session');
lifeError(fn()=>EmailRecovery::confirm($token),'invalid_context');
$sender=new class implements SmsSender {
    public function id():string{return 'fixture';}
    public function send(string $mobile,string $code,string $purpose):SendResult{throw new RuntimeException('Old ticket sent SMS');}
};
lifeError(fn()=> $flow->issue($browser,$ticket,'192.0.2.1',$sender),'invalid_context');

// Actual wp-admin/profile.php dispatcher (in isolated child process) confirms
// a fresh email; use native authenticated cookies, never expose token output.
SessionProof::record($phoneOnly,$nativeToken,$phoneIdentity);
$wpdb->query("TRUNCATE TABLE ".Schema::table('limits'));
EmailRecovery::request('life-confirm@example.invalid');
$session=WP_Session_Tokens::get_instance($phoneOnly)->get($nativeToken);
$auth=wp_generate_auth_cookie($phoneOnly,$session['expiration'],'secure_auth',$nativeToken);
$fixture=$dir.'/private-email-confirm.json';
file_put_contents($fixture,wp_json_encode(['token'=>$token,'logged_in'=>$_COOKIE[LOGGED_IN_COOKIE],'secure_auth'=>$auth]),LOCK_EX);
chmod($fixture,0600);
$proc=proc_open([PHP_BINARY,__DIR__.'/recovery-confirm.php',$fixture],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
lifeCheck(is_resource($proc),'Could not launch native confirmation child');
fclose($pipes[0]);$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
$exit=proc_close($proc);unlink($fixture);
clean_user_cache($phoneOnly);
wp_cache_delete($phoneOnly,'user_meta');
lifeCheck($exit===0 && str_contains($out,'confirmed_profile_redirect') &&
    get_userdata($phoneOnly)->user_email==='life-confirm@example.invalid' && EmailRecovery::allow(true,$phoneOnly),
    'Native profile confirmation dispatch failed (exit '.$exit.')');
remove_filter('pre_wp_mail',$mail,99);
unset($token,$auth,$nativeToken,$ticket);

// Native privacy integration and phone-only safety.
// Reassignment must not transfer the former binding's challenge history.
$former=fixtureUser('fixture_privacy_former','former@example.invalid');
$later=fixtureUser('fixture_privacy_later','later@example.invalid');
$formerBinding=Identity::bind($former,'09123330017','absent');
$testSender=new class implements SmsSender {
    public string $reference='1717';
    public function id():string{return 'fixture';}
    public function send(string $mobile,string $code,string $purpose):SendResult {
        return new SendResult('accepted','fixture',$this->reference);
    }
};
wp_set_current_user(0);
unset($_COOKIE[LOGGED_IN_COOKIE]);
$wpdb->query("TRUNCATE TABLE ".Schema::table('limits'));
$browser=bin2hex(random_bytes(32));
$first=$flow->issue($browser,$flow->start($browser,$formerBinding->mobile,'login','wp'),'192.0.2.1',$testSender);
lifeCheck(str_contains(wp_json_encode(Privacy::exportUser($former)),$first['id']),'Original binding challenge not exported');
Identity::bind($former,'09123330018',$formerBinding->revision);
Identity::bind($later,'09123330017','absent');
$wpdb->query("TRUNCATE TABLE ".Schema::table('limits'));
$testSender->reference='1818';
$second=$flow->issue($browser,$flow->start($browser,'09123330017','login','wp'),'192.0.2.1',$testSender);
$exportJson=wp_json_encode(Privacy::exportUser($later));
lifeCheck(str_contains($exportJson,$second['id']) && !str_contains($exportJson,$first['id']),'Reassigned number inherited challenge history');
$erase=Privacy::eraseUser($later);
$challengeTable=Schema::table('challenges');
lifeCheck($erase['items_removed'] && (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $challengeTable WHERE id=%s",$first['id']))===1 &&
    (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $challengeTable WHERE id=%s",$second['id']))===0,
    'Privacy erase affected a previous binding or retained current attributable challenge');
wp_set_current_user($admin->ID);
$exporters=apply_filters('wp_privacy_personal_data_exporters',[]);
$erasers=apply_filters('wp_privacy_personal_data_erasers',[]);
$export=($exporters['ach-wp-accounts']['callback'])('life-confirm@example.invalid',1);
lifeCheck(is_array($export) && str_contains(wp_json_encode($export),'+989123330013') &&
    !str_contains(wp_json_encode($export),'token_hash'),'Native privacy export missing or unsafe');
$erased=($erasers['ach-wp-accounts']['callback'])('life-confirm@example.invalid',1);
lifeCheck($erased['items_removed'] && Identity::byUser($phoneOnly)===null && get_userdata($phoneOnly)->user_email==='life-confirm@example.invalid' &&
    EmailRecovery::allow(true,$phoneOnly),'Privacy erase removed native recovery or failed to unlink');
$noEmail=fixtureUser('fixture_privacy_no_email','',true);
$noEmailIdentity=Identity::bind($noEmail,'09123330014','absent');
$kept=Privacy::eraseUser($noEmail);
lifeCheck($kept['items_retained'] && Identity::byUser($noEmail)->revision===$noEmailIdentity->revision,'Privacy erase locked out a phone-only account');
$nativeBilling='fixture-billing@example.invalid';
update_user_meta($a,'billing_email',$nativeBilling);
$erased=Privacy::eraseUser($a);
lifeCheck($erased['items_removed'] && Identity::byUser($a)===null && get_userdata($a) &&
    get_user_meta($a,'billing_email',true)===$nativeBilling,'Privacy erase affected unrelated native data');
wp_delete_user($noEmail);
lifeCheck(Identity::byUser($noEmail)===null,'Native user deletion retained verified identity');
echo "PASS: native migration preview/apply/resume and conflicts, interrupted-registration reconciliation, failed schema resume and newer-schema guard, deactivation proof revocation, native profile confirmation and privacy lifecycle\n";
