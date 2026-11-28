<?php
/** Extra UI/interaction qualification on a real, isolated WordPress installation. */
use ACh\WPAccounts\{Identity,Settings,Mobile};
$root=getenv('ACH_TEST_WP');$dir=getenv('ACH_TEST_DIR');$url=getenv('ACH_BROWSER_URL');
if (!$root||!$dir||!preg_match('~^https://127\.0\.0\.1:[0-9]+$~D',(string)$url)) throw new RuntimeException('Run in the private browser fixture');
require $root.'/wp-load.php';
if (DB_NAME!=='accounts_fixture'||wp_get_environment_type()!=='local'||get_option('siteurl')!==$url) throw new RuntimeException('Not isolated');
$mode=$argv[1]??'';
$settings=Settings::current();
switch($mode){
    case 'prepare':
        // Separate synthetic data from browser-checkout; allow repeat runs
        // against a retained test installation without deleting old accounts.
        update_option('WPLANG','');
        $settings['auth_enabled']=true;
        $settings['allow_registration']=true;
        $settings['allow_password_login']=true;
        $settings['allow_email_login']=true;
        $settings['allow_email_registration']=true;
        $settings['allow_first_link']=true;
        $settings['allow_email_password_reset']=true;
        $settings['allow_mobile_password_reset']=true;
        $settings['allow_recovery_email_enrollment']=true;
        $settings['allow_recovery_password_enrollment']=true;
        $settings['checkout_required']=true;
        $settings['registration_fields']=['first_name'=>'off','last_name'=>'off'];
        $settings['verification']['cooldown']=2;
        $settings['verification']['codeLifetime']=15;
        $settings['verification']['mobileHourly']=30;
        $settings['verification']['networkHourly']=300;
        $settings['selected_provider']='kavenegar';
        update_option(Settings::OPTION,$settings,false);
        update_option('woocommerce_enable_myaccount_registration','yes');
        update_option('woocommerce_registration_generate_password','no');
        update_option('woocommerce_registration_generate_username','yes');
        update_option('fixture_ux_sms_state','accepted');
        do { $prefix='091288'.random_int(100,899); } while (Identity::byMobile(Mobile::normalize($prefix.'01')));
        // Prefix has 9 digits; append two for each distinct scenario.
        $phone=static fn($n)=>$prefix.str_pad((string)$n,2,'0',STR_PAD_LEFT);
        $password='fixture-ui-profile-password-123';
        $login='fixture_ux_'.bin2hex(random_bytes(6));
        $id=wp_insert_user(['user_login'=>$login,'user_email'=>$login.'@example.invalid','user_pass'=>$password,'role'=>'customer']);
        if (!is_int($id)||$id<=0)throw new RuntimeException('Profile fixture account failed');
        Identity::bind($id,Mobile::normalize($phone(1)),'absent');
        $post=wp_insert_post(['post_type'=>'page','post_status'=>'publish','post_title'=>'Fixture inline and popup access',
            'post_name'=>'fixture-ui-'.bin2hex(random_bytes(4)),
            'post_content'=>'[ach_wp_accounts] [ach_wp_accounts display="popup"] [ach_wp_accounts]']);
        echo wp_json_encode(['user'=>$id,'login'=>$login,'password'=>$password,'phones'=>array_map($phone,range(1,12)),'shortcode'=>get_permalink($post),
            'account'=>wc_get_page_permalink('myaccount'),'profile'=>wc_get_account_endpoint_url('edit-account')]);break;
    case 'sms':
        $state=$argv[2]??'';
        if(!in_array($state,['accepted','failed','uncertain'],true))throw new RuntimeException('Unknown fixture delivery state');
        update_option('fixture_ux_sms_state',$state);break;
    case 'policy':
        $policy=$argv[2]??'';
        if($policy==='all-recovery-off'){
            $settings['allow_first_link']=false;$settings['allow_mobile_password_reset']=false;$settings['allow_email_password_reset']=false;
        }elseif($policy==='mobile-only-reset'){$settings['allow_mobile_password_reset']=true;$settings['allow_email_password_reset']=false;}
        elseif($policy==='email-only-reset'){$settings['allow_mobile_password_reset']=false;$settings['allow_email_password_reset']=true;}
        elseif($policy==='restore'){
            $settings['allow_first_link']=true;
            $settings['allow_email_password_reset']=true;
            $settings['allow_mobile_password_reset']=true;
        }
        else throw new RuntimeException('Unknown fixture policy');
        update_option(Settings::OPTION,$settings,false);break;
    case 'locale':
        $locale=$argv[2]??'';
        if(!in_array($locale,['','fa_IR'],true))throw new RuntimeException('Unknown locale');
        update_option('WPLANG',$locale);break;
    default:throw new RuntimeException('Unknown fixture command');
}
