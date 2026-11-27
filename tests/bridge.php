<?php
/** Optional real Bridge / MCP Adapter fixture, never installed on a live site. */
use WP_AI_Bridge\Auth\OAuth_Server;
use WP_AI_Bridge\Auth\OAuth_Store;
use WP_AI_Bridge\Support\Settings as BridgeSettings;
use ACh\WPAccounts\Settings;
$root=getenv('ACH_TEST_WP');
if (!$root) { throw new RuntimeException('Run through isolated fixture.'); }
$_SERVER['REQUEST_METHOD']='POST';
require $root.'/wp-load.php';
require_once ABSPATH.'wp-admin/includes/plugin.php';
if (wp_get_environment_type()!=='local' || DB_NAME!=='accounts_fixture' || get_option('siteurl')!=='https://accounts.test') {
    throw new RuntimeException('Not isolated.');
}
function bridgeCheck(bool $ok,string $message):void {
    if (!$ok) { throw new RuntimeException($message); }
}
add_filter('pre_wp_mail','__return_true');
add_filter('pre_http_request',static fn()=>new WP_Error('fixture_no_network'),PHP_INT_MAX);
$admin=get_user_by('login','fixture_admin');
wp_set_current_user($admin->ID);
if (($argv[1] ?? '')==='activate') {
    foreach (['mcp-adapter/mcp-adapter.php','wp-ai-bridge/wp-ai-bridge.php'] as $plugin) {
        $result=activate_plugin($plugin);
        bridgeCheck(!is_wp_error($result),'Fixture activation failed: '.$plugin);
    }
    exit;
}
bridgeCheck(defined('WP_AI_BRIDGE_VERSION') && wp_has_ability('wp-ai-bridge/abilities-read'),'Bridge unavailable');
$settings=get_option(BridgeSettings::OPTION_NAME,[]);
$settings[BridgeSettings::GROUP_SITE_READ]=1;
$settings[BridgeSettings::GROUP_NATIVE_ABILITIES]=0;
update_option(BridgeSettings::OPTION_NAME,$settings,false);
$previous=Settings::current();
Settings::update(['providers'=>[
    'kavenegar'=>['credentials'=>['api_key'=>'fixture-bridge-key'],'default'=>['mode'=>'pattern','template'=>'fixture'],'purposes'=>[]],
    'melipayamak'=>['credentials'=>['username'=>'fixture-bridge-name','api_key'=>'fixture-bridge-second'],'default'=>['mode'=>'pattern','body_id'=>123,'values'=>['{code}']],'purposes'=>[]],
]],$previous['revision'],true);
$catalog=wp_get_ability('wp-ai-bridge/abilities-read');
$discovered=$catalog->execute(['action'=>'get','name'=>'ach-wp-accounts/status']);
bridgeCheck(is_array($discovered) && ($discovered['items'][0]['bridge_delegation'] ?? '')==='native_abilities' &&
    ($discovered['items'][0]['annotations']['readonly'] ?? false)===true &&
    ($discovered['execution_permission'] ?? '')==='not_evaluated','Native contract discovery failed');
$store=new OAuth_Store();
$oauth=new OAuth_Server($store);
function fixtureMcpRequest(string $token,array $payload,string $session=''):WP_REST_Response {
    wp_set_current_user(0);
    $request=new WP_REST_Request('POST',OAuth_Server::MCP_REQUEST_ROUTE);
    $request->set_header('Authorization','Bearer '.$token);
    $request->set_header('Accept','application/json, text/event-stream');
    $request->set_header('Content-Type','application/json');
    $request->set_body(wp_json_encode($payload));
    if ($session!=='') {
        $request->set_header('Mcp-Session-Id',$session);
        $request->set_header('MCP-Protocol-Version','2025-11-25');
    }
    return rest_do_request($request);
}
function fixtureMcpSession(int $id):array {
    global $store,$oauth;
    wp_set_current_user($id);
    $token=$store->issue(OAuth_Store::TYPE_ACCESS,[
        'user_id'=>$id,'client_id'=>OAuth_Server::CHATGPT_CLIENT_ID,
        'resource'=>$oauth->mcp_endpoint_url(),'scope'=>OAuth_Server::SCOPE_MCP.' '.OAuth_Server::SCOPE_OFFLINE,
    ],120);
    bridgeCheck(is_string($token),'Fixture token issuance failed');
    $before=\WP\MCP\Transport\Infrastructure\SessionManager::get_all_user_sessions($id);
    $response=fixtureMcpRequest($token,['jsonrpc'=>'2.0','id'=>1,'method'=>'initialize','params'=>[
        'protocolVersion'=>'2025-11-25','capabilities'=>(object)[],
        'clientInfo'=>['name'=>'ach-accounts-fixture','version'=>'1.0.0'],
    ]]);
    bridgeCheck($response->get_status()===200,'MCP initialize failed');
    $session=(string)($response->get_headers()['Mcp-Session-Id'] ?? '');
    if ($session==='') {
        $after=\WP\MCP\Transport\Infrastructure\SessionManager::get_all_user_sessions($id);
        $session=(string)array_key_first(array_diff_key($after,$before));
    }
    bridgeCheck($session!=='','MCP session unavailable');
    bridgeCheck(fixtureMcpRequest($token,['jsonrpc'=>'2.0','method'=>'notifications/initialized'],$session)->get_status()===202,'MCP notification failed');
    return [$token,$session];
}
function fixtureMcpCall(array $session,string $ability,int $id,array $input=[]):array {
    [$token,$sessionId]=$session;
    $response=fixtureMcpRequest($token,['jsonrpc'=>'2.0','id'=>$id,'method'=>'tools/call','params'=>[
        'name'=>'mcp-adapter-execute-ability','arguments'=>['ability_name'=>$ability,'parameters'=>(object)$input],
    ]],$sessionId);
    bridgeCheck($response->get_status()===200,'MCP tool transport failed: '.$ability);
    return json_decode(wp_json_encode($response->get_data()),true,512,JSON_THROW_ON_ERROR);
}
function fixtureMcpData(array $result):array {
    return $result['result']['structuredContent'] ??
        json_decode((string)($result['result']['content'][0]['text'] ?? ''),true) ?? [];
}
$session=fixtureMcpSession($admin->ID);
$denied=fixtureMcpCall($session,'ach-wp-accounts/status',2);
bridgeCheck(($denied['result']['isError'] ?? false)===true,'Bridge native delegation denial bypassed');
wp_set_current_user($admin->ID);
$settings[BridgeSettings::GROUP_NATIVE_ABILITIES]=1;
update_option(BridgeSettings::OPTION_NAME,$settings,false);
$allowed=fixtureMcpCall($session,'ach-wp-accounts/status',3);
$data=fixtureMcpData($allowed);
bridgeCheck(($data['success'] ?? false)===true && ($data['data']['schema_ready'] ?? false)===true &&
    ($data['data']['providers'][0]['required_inputs'] ?? [])===['api_key'],'Authorized native MCP status failed');
$read=fixtureMcpCall($session,'ach-wp-accounts/settings-read',4);
$readData=fixtureMcpData($read);
bridgeCheck(($readData['success'] ?? false)===true && isset($readData['data']['revision']) &&
    ($readData['data']['providers']['kavenegar']['configured_inputs'] ?? [])===['api_key'] &&
    ($readData['data']['providers']['melipayamak']['configured_inputs'] ?? [])===['username','api_key'] &&
    !str_contains(wp_json_encode($read),'fixture-bridge-key') &&
    !str_contains(wp_json_encode($read),'fixture-bridge-name') &&
    !str_contains(wp_json_encode($read),'fixture-bridge-second'),'MCP settings projection denied or exposed credentials');
$invalid=fixtureMcpCall($session,'ach-wp-accounts/settings-update',5,['revision'=>str_repeat('0',32),'patch'=>['auth_enabled'=>false]]);
bridgeCheck(($invalid['result']['isError'] ?? false)===true,'MCP stale settings update accepted');
$change=fixtureMcpCall($session,'ach-wp-accounts/settings-update',6,[
    'revision'=>$readData['data']['revision'],'patch'=>['diagnostics_retention_hours'=>72],
]);
bridgeCheck((fixtureMcpData($change)['data']['diagnostics_retention_hours'] ?? 0)===72,'Authorized MCP settings update failed');
$diagnostics=fixtureMcpCall($session,'ach-wp-accounts/diagnostics-query',7,['limit'=>5]);
bridgeCheck((fixtureMcpData($diagnostics)['success'] ?? false)===true,'Authorized MCP diagnostics query failed');
wp_set_current_user($admin->ID);
update_option(Settings::OPTION,$previous,false);
$subscriber=get_user_by('login','fixture_subscriber');
bridgeCheck($subscriber instanceof WP_User,'Subscriber fixture missing');
// Native authorization remains required even if an authenticated client has
// a valid session; the Bridge may deny session initialization itself.
wp_set_current_user($subscriber->ID);
bridgeCheck(is_wp_error(wp_get_ability('ach-wp-accounts/status')->execute()),'Subscriber native permission bypassed');
echo 'PASS: WP AI Bridge '.WP_AI_BRIDGE_VERSION." + MCP Adapter actual native catalog, OAuth MCP session, delegation denial, authorized status/settings projection, authorized update and diagnostics, stale write rejection and native permission\n";
