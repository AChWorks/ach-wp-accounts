<?php
$root=getenv('ACH_TEST_WP');$dir=getenv('ACH_TEST_DIR');$phar=getenv('WP_CLI_PHAR');
if (!$root || !$dir || !$phar || !is_file($phar)) { throw new RuntimeException('Run isolated fixture with WP_CLI_PHAR.'); }
require $root.'/wp-load.php';
if (wp_get_environment_type()!=='local' || DB_NAME!=='accounts_fixture' || get_option('siteurl')!=='https://accounts.test') {
    throw new RuntimeException('Not isolated.');
}
function cliCheck(bool $ok,string $message):void { if (!$ok) {throw new RuntimeException($message);} }
function cliRun(array $args,string $user='fixture_admin',bool $success=true):?array {
    global $root,$phar;
    $proc=proc_open([PHP_BINARY,$phar,'--path='.$root,'--user='.$user,'--skip-themes',...$args],
        [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    cliCheck(is_resource($proc),'WP-CLI start failed');
    fclose($pipes[0]);$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
    $exit=proc_close($proc);
    cliCheck($success ? $exit===0 : $exit!==0,'WP-CLI exit '.$exit.' for '.$args[1].($exit!==0&&$success ? ': '.trim($err) : ''));
    return $success ? json_decode($out,true,512,JSON_THROW_ON_ERROR) : null;
}
$native=get_user_by('login','fixture_migration_b');
cliCheck($native instanceof WP_User,'CLI fixture user missing');
$file=$dir.'/private-migration-cli.json';
file_put_contents($file,wp_json_encode(['evidence'=>'Synthetic CLI reviewed fixture','rows'=>[
    ['user_id'=>$native->ID,'mobile'=>'09124441111','verified'=>true]
]]),LOCK_EX);
chmod($file,0600);
try {
    cliRun(['ach-accounts','migration-preview',$file],$native->user_login,false);
    $preview=cliRun(['ach-accounts','migration-preview',$file,'--limit=1']);
    cliCheck($preview['items'][0]['state']==='ready','CLI preview failed');
    $result=cliRun(['ach-accounts','migration-execute',$file,'--digest='.$preview['digest'],'--confirm=import-reviewed-identities']);
    cliCheck($result['items'][0]['state']==='imported','CLI import failed');
    $export=cliRun(['ach-accounts','privacy-export','--user-id='.$native->ID,'--page=1']);
    cliCheck(str_contains(wp_json_encode($export),'+989124441111'),'CLI private export failed');
    $erase=cliRun(['ach-accounts','privacy-erase','--user-id='.$native->ID,'--confirm=erase-account-data']);
    cliCheck($erase['items_retained'],'CLI phone-only privacy safety failed');
    cliRun(['ach-accounts','purge','--confirm=wrong'],'fixture_admin',false);
} finally {unlink($file);}
echo "PASS: actual WP-CLI preview/import, capability, privacy export/erase and explicit purge confirmation\n";
