<?php
use ACh\WPAccounts\CustomerDirectory;
use ACh\WPAccounts\Identity;
use ACh\WPAccounts\Schema;

$root=getenv('ACH_TEST_WP');
if (!$root) { throw new RuntimeException('Run through isolated fixture.'); }
$_SERVER['REQUEST_METHOD']='GET';
require $root.'/wp-load.php';
require_once ABSPATH.'wp-admin/includes/admin.php';
require_once ABSPATH.'wp-admin/includes/class-wp-users-list-table.php';
if (wp_get_environment_type()!=='local' || DB_NAME!=='accounts_fixture' || get_option('siteurl')!=='https://accounts.test' || !defined('WC_VERSION')) {
    throw new RuntimeException('Not isolated.');
}
function directoryCheck(bool $condition,string $message):void {
    if (!$condition) { throw new RuntimeException($message); }
}
add_filter('pre_http_request',static fn()=>new WP_Error('fixture_no_network'));
add_filter('pre_wp_mail','__return_true');
$admin=get_user_by('login','fixture_admin');
wp_set_current_user($admin->ID);
set_current_screen('users');
$users=[];
foreach (['customer','customer','subscriber','customer'] as $i=>$role) {
    $id=wp_insert_user(['user_login'=>'fixture_dir_'.$i,'user_email'=>'directory'.$i.'@example.invalid',
        'user_pass'=>wp_generate_password(32),'role'=>$role,'first_name'=>'Directory '.$i,'last_name'=>'Fixture']);
    directoryCheck(is_int($id),'Fixture user creation failed');
    $users[]=$id;
    if ($i<3) {
        if ($i===1) Identity::importReviewedMapping($id,'0912555666'.$i,str_repeat('a',64));
        else Identity::bind($id,'0912555666'.$i,'absent');
    }
    \Automattic\WooCommerce\Admin\API\Reports\Customers\DataStore::update_registered_customer($id);
}
global $wpdb;
function wpDirectory(string $search,string $role='',int $limit=100,int $offset=0):WP_User_Query {
    return new WP_User_Query(apply_filters('users_list_table_query_args',[
        'search'=>'*'.$search.'*','role'=>$role,'fields'=>'ID','number'=>$limit,'offset'=>$offset,
        'orderby'=>'ID','order'=>'ASC','count_total'=>true,
    ]));
}
foreach (['09125556660','+989125556660','00989125556660','989125556660','۰۹۱۲۵۵۵۶۶۶۰','٠٩١٢٥٥٥٦٦٦٠'] as $search) {
    $q=wpDirectory($search);
    directoryCheck(array_map('intval',$q->get_results())===[$users[0]] && $q->get_total()===1,'WP exact search failed: '.$search.' '.$q->request);
}
$q=wpDirectory('۵۵۵۶۶۶','customer',1);
directoryCheck($q->get_total()===2 && array_map('intval',$q->get_results())===[$users[0]],'WP role/count/page search failed');
directoryCheck(array_map('intval',wpDirectory('555666','customer',1,1)->get_results())===[$users[1]],'WP pagination failed');
directoryCheck(in_array($users[3],array_map('intval',wpDirectory('directory3@example.invalid')->get_results()),true),'WP native email search lost');
directoryCheck(in_array($users[0],array_map('intval',wpDirectory('fixture_dir_0')->get_results()),true),'WP native username search lost');
$columns=CustomerDirectory::columns(['username'=>'Username']);
directoryCheck(isset($columns['username'],$columns['ach_accounts_mobile'],$columns['ach_accounts_verified']),'WP native columns lost');
directoryCheck(str_contains(CustomerDirectory::cell('','ach_accounts_mobile',$users[0]),'09125556660') &&
    str_contains(CustomerDirectory::cell('','ach_accounts_verified',$users[0]),'Verified') &&
    str_contains(CustomerDirectory::cell('','ach_accounts_verified',$users[1]),'Pending') &&
    str_contains(CustomerDirectory::cell('','ach_accounts_verified',$users[3]),'No mobile'),'WP column status failed');
directoryCheck(get_user_meta($users[0],'ach_accounts_mobile',true)==='' && get_user_meta($users[0],'billing_phone',true)==='','Directory mirrored identity');
Identity::bind($users[0],'09125556669',Identity::byUser($users[0])->revision);
directoryCheck(!wpDirectory('09125556660')->get_results() && array_map('intval',wpDirectory('09125556669')->get_results())===[$users[0]],'WP search cached stale identity');
function reportDirectory(string|array $search,int $limit=100,int $page=1,string $route='/wc-analytics/reports/customers'):WP_REST_Response {
    $request=new WP_REST_Request('GET',$route);
    $request->set_query_params(['search'=>$search,'searchby'=>'all','per_page'=>$limit,'page'=>$page,'orderby'=>'name','order'=>'asc']);
    $response=rest_do_request($request);
    return CustomerDirectory::reportProjection($response,rest_get_server(),$request);
}
$r=reportDirectory('09125556669');
directoryCheck($r->get_status()===200,'Woo report failed: '.wp_json_encode($r->get_data()));
$rows=$r->get_data();
directoryCheck(count($rows)===1 && (int)$rows[0]['user_id']===$users[0] &&
    $rows[0]['ach_accounts_identity']['mobile']==='09125556669','Woo exact search/project failed: '.wp_json_encode($rows));
$r=reportDirectory('۵۵۵۶۶۶',1);
directoryCheck(count($r->get_data())===1 && (int)($r->get_headers()['X-WP-Total'] ?? 0)===2,'Woo pagination/count failed: '.wp_json_encode($r->get_headers()));
$r=reportDirectory(['۰۹۱۲۵۵۵۶۶۶۹','directory3@example.invalid']);
directoryCheck($r->get_status()===200 && count($r->get_data())===2,'Woo server-side native token search failed: '.wp_json_encode($r->get_data()));
$r=reportDirectory('09125556669',10,1,'/wc-analytics/customers');
directoryCheck($r->get_status()===200 && count($r->get_data())===1 && (int)$r->get_data()[0]['user_id']===$users[0],'Woo native autocomplete failed');
$r=reportDirectory('directory3@example.invalid');
directoryCheck(count($r->get_data())===1 && (int)$r->get_data()[0]['user_id']===$users[3],'Woo native search lost');
wp_set_current_user($users[0]);
directoryCheck(CustomerDirectory::columns(['username'=>'Username'])===['username'=>'Username'] &&
    CustomerDirectory::cell('','ach_accounts_mobile',$users[1])==='' &&
    !wpDirectory('09125556661')->get_results(),'Unauthorized WP directory access');
$r=reportDirectory('09125556661');
directoryCheck($r->get_status()!==200,'Unauthorized Woo directory access');
echo "PASS: authoritative WP/Woo directory projection, Iranian search, Persian/Arabic digits, native searches, filtering/count/pagination, cache freshness and permissions\n";
