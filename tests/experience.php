<?php
use ACh\WPAccounts\{AccountFlow,Capabilities,Identity,NativeAccess,Settings,VerificationError,SmsSender,SendResult};
$root=getenv('ACH_TEST_WP');
if (!$root) throw new RuntimeException('Missing fixture');
require $root.'/wp-load.php';
if (wp_get_environment_type()!=='local'||DB_NAME!=='accounts_fixture'||get_option('siteurl')!=='https://accounts.test'||!defined('WC_VERSION')) throw new RuntimeException('Not isolated');
add_filter('pre_wp_mail','__return_true');
add_filter('pre_http_request',static fn()=>new WP_Error('fixture_no_network'));
function check(bool $ok,string $label):void { if(!$ok)throw new RuntimeException($label); }
function reject(callable $call,string ...$expected):void {
    try {$call();} catch(VerificationError $e){check(in_array($e->getMessage(),$expected,true),'Unexpected: '.$e->getMessage());return;}
    throw new RuntimeException('Expected denial: '.implode(',',$expected));
}
function flags(array $changes):void {update_option(Settings::OPTION,array_replace(Settings::current(),$changes),false);}
class ExperienceSender implements SmsSender {
    public string $code='';
    public function id():string{return 'fixture';}
    public function send(string $mobile,string $code,string $purpose):SendResult{$this->code=$code;return new SendResult('accepted','fixture','1');}
}
function challenge(AccountFlow $flow,string $browser,string $phone,string $purpose):array {
    $ticket=$flow->start($browser,$phone,$purpose,'account');
    $sender=new ExperienceSender();
    $flow->issue($browser,$ticket,'192.0.2.90',$sender);
    return [$ticket,$sender->code];
}
$original=Settings::current();
try {
    update_option('woocommerce_enable_myaccount_registration','yes');
    update_option('users_can_register',1);
    flags(['auth_enabled'=>true,'allow_registration'=>true,'allow_password_login'=>true,'allow_email_registration'=>true,'allow_first_link'=>true,'allow_email_password_reset'=>true,'allow_mobile_password_reset'=>false,'registration_fields'=>['first_name'=>'off','last_name'=>'off']]);
    $keys=['allow_registration','allow_password_login','allow_email_login','allow_email_registration','allow_first_link','allow_email_password_reset','allow_mobile_password_reset','allow_recovery_email_enrollment','allow_recovery_password_enrollment'];
    for($mask=0;$mask<(1<<count($keys));$mask++){
        $v=[];foreach($keys as $i=>$key)$v[$key]=(bool)($mask&(1<<$i));
        flags($v);
        $c=Capabilities::forContext('wp');
        check($c['mobile_registration']===$v['allow_registration'],'Registration matrix');
        check($c['email_login']===($v['allow_password_login']&&$v['allow_email_login']),'Email login matrix');
        check($c['email_registration']===($v['allow_email_registration']&&$v['allow_email_password_reset']&&($v['allow_password_login']||$v['allow_first_link'])),'Core signup matrix');
        check($c['first_link']===$v['allow_first_link']&&$c['email_reset']===$v['allow_email_password_reset']&&$c['mobile_reset']===$v['allow_mobile_password_reset'],'Recovery matrix');
        check($c['email_enrollment']===$v['allow_recovery_email_enrollment']&&$c['password_enrollment']===$v['allow_recovery_password_enrollment'],'Enrollment matrix');
    }
    flags(array_fill_keys($keys,true));
    $oldPass=wp_generate_password(32);$newPass=wp_generate_password(32);
    $id=wp_insert_user(['user_login'=>'experience_fixture','user_email'=>'experience@example.invalid','user_pass'=>$oldPass,'role'=>'customer']);
    check(is_int($id),'Fixture user');
    Identity::bind($id,'+989122299901','absent');
    flags(['allow_email_login'=>false,'allow_email_password_reset'=>false,'allow_mobile_password_reset'=>false]);
    check(wp_authenticate('experience_fixture',$oldPass) instanceof WP_User,'Username login blocked');
    check(is_wp_error(wp_authenticate('experience@example.invalid',$oldPass)),'Email login policy bypassed');
    check(!wp_is_password_reset_allowed_for_user($id),'Native email recovery bypassed');
    $flow=new AccountFlow();$browser=bin2hex(random_bytes(32));
    reject(fn()=>$flow->start($browser,'+989122299901','password_reset','account'),'method_disabled');
    flags(['allow_mobile_password_reset'=>true]);
    [$ticket,$code]=challenge($flow,$browser,'+989122299901','password_reset');
    $proof=$flow->complete($browser,$ticket,$code);
    check($proof['outcome']==='password_required'&&get_current_user_id()===0,'Reset signed in early');
    $tokens=WP_Session_Tokens::get_instance($id);$token=$tokens->create(time()+3600);
    reject(fn()=>$flow->resetPassword($browser,$ticket,$proof['proof'],'short','short'),'invalid_password');
    reject(fn()=>$flow->resetPassword(bin2hex(random_bytes(32)),$ticket,$proof['proof'],$newPass,$newPass),'invalid_context');
    check($flow->resetPassword($browser,$ticket,$proof['proof'],$newPass,$newPass)['outcome']==='password_reset','Reset failed');
    check(wp_check_password($newPass,get_userdata($id)->user_pass,$id)&&!$tokens->verify($token)&&get_current_user_id()===0,'Reset failed native password/session semantics');
    reject(fn()=>$flow->resetPassword($browser,$ticket,$proof['proof'],$newPass,$newPass),'identity_changed','invalid_proof');
    $unverified=wp_insert_user(['user_login'=>'experience_imported','user_pass'=>$oldPass,'role'=>'customer']);
    check(is_int($unverified),'Imported user fixture');
    Identity::importReviewedMapping($unverified,'+989122299902',hash('sha256','fixture-imported'));
    [$ticket,$code]=challenge($flow,$browser,'+989122299902','password_reset');
    reject(fn()=>$flow->complete($browser,$ticket,$code),'reset_unavailable');
    [$ticket,$code]=challenge($flow,$browser,'+989122299903','password_reset');
    reject(fn()=>$flow->complete($browser,$ticket,$code),'reset_unavailable');
    flags(['registration_fields'=>['first_name'=>'required','last_name'=>'optional']]);
    [$ticket,$code]=challenge($flow,$browser,'+989122299904','login');
    $profile=$flow->complete($browser,$ticket,$code);
    check($profile['outcome']==='profile_required'&&!Identity::byMobile('+989122299904'),'Account created before required fields');
    reject(fn()=>$flow->continueRegistration($browser,$ticket,$profile['proof'],['first_name'=>'']),'invalid_profile_fields');
    reject(fn()=>$flow->continueRegistration(bin2hex(random_bytes(32)),$ticket,$profile['proof'],['first_name'=>'Fixture']),'invalid_context');
    $result=$flow->continueRegistration($browser,$ticket,$profile['proof'],['first_name'=>'Fixture','last_name'=>'Customer']);
    $created=Identity::byMobile('+989122299904');
    check($result['created']===true&&$created&&get_user_meta($created->user_id,'first_name',true)==='Fixture'&&get_user_meta($created->user_id,'last_name',true)==='Customer','Native profile fields not persisted');
    echo "PASS: 512 capability combinations, independent native email/password/reset controls, browser-bound one-use mobile reset, native session invalidation, unverified/unknown rejection, proof-bound name fields\n";
} finally {wp_set_current_user(0);update_option(Settings::OPTION,$original,false);}
