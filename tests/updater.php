<?php
use ACh\WPAccounts\Updater;
use ACh\WPAccounts\Settings;

$root=getenv('ACH_TEST_WP');
if (!$root) { throw new RuntimeException('Run through isolated fixture.'); }
require $root.'/wp-load.php';
require_once ABSPATH.'wp-admin/includes/plugin.php';
require_once ABSPATH.'wp-admin/includes/file.php';
require_once ABSPATH.'wp-admin/includes/class-wp-upgrader.php';
if (wp_get_environment_type()!=='local' || DB_NAME!=='accounts_fixture' || get_option('siteurl')!=='https://accounts.test') {
    throw new RuntimeException('Not isolated.');
}
function checkUpdate(bool $ok, string $message): void {
    if (!$ok) { throw new RuntimeException($message); }
}
$base='https://github.com/AChWorks/ach-wp-accounts';
$api='https://api.github.com/repos/AChWorks/ach-wp-accounts/releases/';
$file='ach-wp-accounts/ach-wp-accounts.php';
$dir=getenv('ACH_TEST_DIR').'/release-fixture';
$archive=$dir.'/ach-wp-accounts.zip';
$manifest=json_decode(file_get_contents($dir.'/ach-wp-accounts-release.json'),true,512,JSON_THROW_ON_ERROR);
$version=$manifest['version'];
$tag='v'.$version;
$package=$base.'/releases/download/'.$tag.'/ach-wp-accounts.zip';
$metaUrl=$base.'/releases/download/'.$tag.'/ach-wp-accounts-release.json';
$release=[
    'tag_name'=>$tag,'draft'=>false,'prerelease'=>false,'body'=>'Fixture changes <script>untrusted text</script>',
    'assets'=>[
        ['name'=>'ach-wp-accounts.zip','state'=>'uploaded','browser_download_url'=>$package,
         'size'=>$manifest['size'],'digest'=>'sha256:'.$manifest['sha256']],
        ['name'=>'ach-wp-accounts-release.json','state'=>'uploaded','browser_download_url'=>$metaUrl,'size'=>100],
    ],
];
$mode='ok';
$requests=[];
$mock=static function($pre,$args,$url) use (&$requests,&$mode,&$release,&$manifest,$api,$package,$metaUrl,$archive,$tag,$version) {
    $requests[]=$url;
    $json=static fn($data,$status=200)=>['response'=>['code'=>$status],'body'=>wp_json_encode($data),'headers'=>[],'cookies'=>[]];
    if (str_starts_with($url,'https://api.wordpress.org/plugins/update-check/')) {
        return $json(['plugins'=>[],'translations'=>[],'no_update'=>[]]);
    }
    if ($url===$api.'latest' || $url===$api.'tags/'.$tag) {
        if ($mode==='offline') return new WP_Error('fixture_offline');
        if ($mode==='no_release') return $json([],404);
        $r=$release;
        if ($mode==='prerelease') $r['prerelease']=true;
        if ($mode==='draft') $r['draft']=true;
        if ($mode==='missing') unset($r['assets'][0]);
        if ($mode==='wrong_url') $r['assets'][0]['browser_download_url']='https://elsewhere.invalid/download.zip';
        if ($mode==='bad_digest') $r['assets'][0]['digest']='sha256:'.str_repeat('0',64);
        if ($mode==='changed') $r['assets'][0]['digest']='sha256:'.str_repeat('1',64);
        return $json($r);
    }
    if ($url===$metaUrl) {
        $m=$manifest;
        if ($mode==='incompatible') $m['requires_php']='99.0';
        if ($mode==='wp_incompatible') $m['requires_wp']='99.0';
        if ($mode==='wp_missing') unset($m['requires_wp']);
        if ($mode==='wp_empty') $m['requires_wp']='';
        if ($mode==='wp_invalid') $m['requires_wp']='invalid';
        if ($mode==='wrong_version') $m['version']='999.0.0';
        if ($mode==='changed') $m['sha256']=str_repeat('1',64);
        return $json($m);
    }
    if ($url===$package && !empty($args['stream'])) {
        if ($mode==='corrupt') {
            file_put_contents($args['filename'],str_repeat('x',$manifest['size']));
        } else {
            copy($archive,$args['filename']);
        }
        return ['response'=>['code'=>200],'headers'=>[],'body'=>'','cookies'=>[]];
    }
    return new WP_Error('fixture_no_network');
};
add_filter('pre_http_request',$mock,PHP_INT_MAX,3);
$pluginData=get_plugin_data(WP_PLUGIN_DIR.'/'.$file,false,false);
checkUpdate($pluginData['UpdateURI']===$base && $pluginData['RequiresWP']==='6.3' && $pluginData['RequiresPHP']==='8.1','Native package header mismatch');
checkUpdate(Updater::update(false,$pluginData,'unrelated/other.php',[])===false && !$requests,'Unrelated plugin update touched network');
foreach (['offline','no_release','draft','prerelease','missing','wrong_url','bad_digest','wrong_version','wp_missing','wp_empty','wp_invalid'] as $mode) {
    checkUpdate(Updater::update(false,$pluginData,$file,[])===false,'Invalid release accepted: '.$mode);
}
$mode='ok';
delete_site_transient('update_plugins');
wp_update_plugins();
$updates=get_site_transient('update_plugins');
$item=$updates->response[$file] ?? null;
checkUpdate(is_object($item) && $item->new_version===$version && $item->package===$package &&
    $item->ach_sha256===$manifest['sha256'] && $item->requires_php==='8.1' && $item->requires==='6.3','Native plugin update transient missing correct release');
$details=apply_filters('plugins_api',false,'plugin_information',(object)['slug'=>'ach-wp-accounts']);
checkUpdate(is_object($details) && $details->version===$version &&
    !str_contains($details->sections['changelog'],'<script>'),'Unsafe or missing release details');
$extra=['plugin'=>$file,'type'=>'plugin','action'=>'update'];
$before=count($requests);
checkUpdate(Updater::download(false,$package,null,['plugin'=>'other/file.php'])===false &&
    Updater::download(false,$archive,null,$extra)===false &&
    Updater::download(false,null,null,$extra)===false && count($requests)===$before,'Unrelated/manual update intercepted');
foreach (['changed'=>'ach_accounts_update_changed','incompatible'=>'ach_accounts_update_compatibility','wp_incompatible'=>'ach_accounts_update_compatibility','corrupt'=>'ach_accounts_update_integrity'] as $mode=>$code) {
    $result=Updater::download(false,$package,null,$extra);
    checkUpdate(is_wp_error($result) && $result->get_error_code()===$code,'Update rejection failed: '.$mode);
}
$mode='ok';
$result=Updater::download(false,$package,null,$extra);
checkUpdate(is_string($result) && is_file($result) && hash_file('sha256',$result)===$manifest['sha256'],'Verified download failed');
wp_delete_file($result);

// Exercise actual WordPress Plugin_Upgrader on the isolated site's real files.
// This is a same-schema update fixture, not a future migration/rollback claim.
define('FS_METHOD','direct');
// Exercise the active-plugin path without the UI's separate reactivation
// step. WordPress preserves active plugins during background upgrades.
define('DOING_CRON',true);
$admin=get_user_by('login','fixture_admin');
wp_set_current_user($admin->ID);
$settings=Settings::update([
    'providers'=>['kavenegar'=>[
        'credentials'=>['api_key'=>'fixture-update-secret'],
        'default'=>['mode'=>'pattern','template'=>'fixture-update-template'],
        'purposes'=>[],
    ]],
    'selected_provider'=>'kavenegar','auth_enabled'=>true,'checkout_required'=>true,
],Settings::read()['revision'],true);
$settings=Settings::current();
$cleanup=wp_next_scheduled('ach_wp_accounts_cleanup');
checkUpdate($cleanup!==false && is_plugin_active($file),'Expected an active fixture and cleanup schedule');
checkUpdate(version_compare($version,$pluginData['Version'],'>'),'Fixture release is not newer than installed version');
global $wpdb;
$identityTable=\ACh\WPAccounts\Schema::table('identities');
$identityHash=hash('sha256',serialize($wpdb->get_results("SELECT * FROM $identityTable ORDER BY mobile")));
checkUpdate((int)$wpdb->get_var("SELECT COUNT(*) FROM $identityTable")>0,'Identity fixture is empty');
$skin=new Automatic_Upgrader_Skin();
$upgrader=new Plugin_Upgrader($skin);
$success=$upgrader->upgrade($file,['clear_update_cache'=>false]);
checkUpdate($success===true,'Native Plugin_Upgrader did not succeed: '.(is_wp_error($success)?$success->get_error_code():gettype($success)));
$new=get_plugin_data(WP_PLUGIN_DIR.'/'.$file,false,false);
checkUpdate($new['Version']===$version && is_file(WP_PLUGIN_DIR.'/ach-wp-accounts/includes/Updater.php') &&
    Settings::current()===$settings &&
    hash_equals($identityHash,hash('sha256',serialize($wpdb->get_results("SELECT * FROM $identityTable ORDER BY mobile")))) &&
    is_plugin_active($file) && wp_next_scheduled('ach_wp_accounts_cleanup')===$cleanup,
    'Installed version/layout or state preservation failed');
echo "PASS: native update discovery, stable-release filters, exact asset/digest, compatibility, tamper rejection and real state-preserving WordPress plugin upgrade\n";
