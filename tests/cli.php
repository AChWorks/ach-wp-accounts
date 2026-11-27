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
    ['user_id'=>$native->ID,'mobile'=>'09124441111','selected'=>true]
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
    cliRun(['ach-accounts','migration-preview','--source=digits'],$native->user_login,false);
    $direct=cliRun(['ach-accounts','migration-preview','--source=digits','--all','--limit=2']);
    cliCheck($direct['total']===6 && $direct['pages']===3 && $direct['next_offset']===null &&
        ($direct['counts']['already_bound']??0)===1 && ($direct['counts']['source_conflict']??0)===2 &&
        !isset($direct['items']) && !str_contains(wp_json_encode($direct),'+98912555'),'CLI aggregate direct preview failed');
    $again=cliRun(['ach-accounts','migration-execute','--source=digits','--all','--limit=2',
        '--digest='.$direct['digest'],'--confirm=import-reviewed-identities']);
    cliCheck($again['digest']===$direct['digest'] && $again['counts']===$direct['counts'] && $again['next_offset']===null,
        'CLI direct replay failed');
    cliRun(['ach-accounts','migration-preview',$file,'--source=digits'],'fixture_admin',false);
    $draftPath=$dir.'/digits-wp-cli-draft.json';
    $script=dirname(__DIR__).'/scripts/audit-digits.php';
    try {
        cliRun(['eval-file',$script],$native->user_login,false);
        $report=cliRun(['eval-file',$script,$draftPath]);
        cliCheck($report['draft_written'] && $report['draft_rows']===6 && $report['historical_verification_assertions']===0,'WP-CLI Digits audit failed');
        $draft=json_decode(file_get_contents($draftPath),true,512,JSON_THROW_ON_ERROR);
        cliCheck(count($draft['rows'])===6 && array_column($draft['rows'],'selected')===array_fill(0,6,true) && !array_key_exists('verified',$draft['rows'][0]),'WP-CLI draft misrepresented selection or proof');
        cliRun(['eval-file',$script,$draftPath],'fixture_admin',false);
    } finally { if (is_file($draftPath)) { unlink($draftPath); } }

} finally {unlink($file);}
echo "PASS: actual WP-CLI preview/import, capability, privacy export/erase, private Digits audit and explicit purge confirmation\n";
