<?php
use ACh\WPAccounts\KavenegarSender;
use ACh\WPAccounts\MelipayamakSender;
use ACh\WPAccounts\Mobile;
use ACh\WPAccounts\SendResult;

$root = getenv('ACH_TEST_WP');
if (!$root) {
    throw new RuntimeException('Run through isolated fixture.');
}
require $root . '/wp-load.php';
if (wp_get_environment_type() !== 'local' || DB_NAME !== 'accounts_fixture' || get_option('siteurl') !== 'https://accounts.test') {
    throw new RuntimeException('Not an isolated fixture.');
}
require_once WP_PLUGIN_DIR . '/ach-wp-accounts/ach-wp-accounts.php';

function ensure(bool $condition, string $message): void {
    if (!$condition) { throw new RuntimeException($message); }
}
function mocked(callable $operation, string $endpoint, array $expected, mixed $response, string $state, string $code, string $reference = ''): void {
    $calls = 0;
    $mock = static function ($pre, $args, $url) use (&$calls, $endpoint, $expected, $response) {
        $calls++;
        ensure($url === $endpoint, 'Unexpected endpoint');
        ensure($args['method'] === 'POST' && $args['sslverify'] === true && $args['redirection'] === 0, 'Unsafe HTTP request');
        parse_str($args['body'], $data);
        ensure($data === $expected, 'Unexpected body');
        return $response;
    };
    add_filter('pre_http_request', $mock, 10, 3);
    try {
        $result = $operation();
        ensure($calls === 1, 'Not exactly one HTTP call');
        ensure($result instanceof SendResult && $result->state === $state && $result->code === $code && $result->reference === $reference, 'Unexpected result: ' . json_encode($result));
    } finally {
        remove_filter('pre_http_request', $mock, 10);
    }
}
function response(mixed $body, int $http = 200): array {
    return ['response'=>['code'=>$http], 'body'=>is_string($body) ? $body : json_encode($body, JSON_THROW_ON_ERROR)];
}

foreach (['+989412345678','09612345678','09950123456'] as $bad) {
    try { Mobile::normalize($bad); throw new RuntimeException('Nonmobile accepted'); } catch (InvalidArgumentException $expected) {}
}
$mobile = '+989121234567';
$local = '09121234567';
$otp = '000123';
$k = new KavenegarSender(['api_key'=>'fixture-key'], ['mode'=>'pattern','template'=>'login','tokens'=>['token10'=>'ACh Shop']], ['link'=>['mode'=>'text','sender'=>'10001','message'=>'Code: {code}']]);
$kbase = 'https://api.kavenegar.com/v1/fixture-key/';
$kbody = ['receptor'=>$local,'template'=>'login','token'=>$otp,'type'=>'sms','token10'=>'ACh Shop'];
$kreceipt = ['return'=>['status'=>200,'message'=>'fixture'], 'entries'=>[['messageid'=>'12345678901234567890','receptor'=>$local,'status'=>5,'message'=>$otp]]];
mocked(fn()=> $k->send($mobile,$otp,'login'),$kbase.'verify/lookup.json',$kbody,response($kreceipt),'accepted','message_5','12345678901234567890');
mocked(fn()=> $k->send($mobile,$otp,'link'),$kbase.'sms/send.json',['receptor'=>$local,'sender'=>'10001','message'=>'Code: '.$otp],response($kreceipt),'accepted','message_5','12345678901234567890');
mocked(fn()=> $k->send($mobile,$otp,'login'),$kbase.'verify/lookup.json',$kbody,response(['return'=>['status'=>424]],424),'failed','api_424');
mocked(fn()=> $k->send($mobile,$otp,'login'),$kbase.'verify/lookup.json',$kbody,response(['return'=>['status'=>409]],409),'uncertain','api_409');
mocked(fn()=> $k->send($mobile,$otp,'login'),$kbase.'verify/lookup.json',$kbody,new WP_Error('timeout','fixture'), 'uncertain','transport_error');
$wrong=$kreceipt; $wrong['entries'][0]['receptor']='09120000000';
mocked(fn()=> $k->send($mobile,$otp,'login'),$kbase.'verify/lookup.json',$kbody,response($wrong),'uncertain','invalid_receipt');
$undelivered=$kreceipt; $undelivered['entries'][0]['status']=11;
mocked(fn()=> $k->send($mobile,$otp,'login'),$kbase.'verify/lookup.json',$kbody,response($undelivered),'failed','message_11','12345678901234567890');
mocked(fn()=> $k->connection(),$kbase.'account/info.json',[],response(['return'=>['status'=>200],'entries'=>['remaincredit'=>1000]]),'accepted','connected');

$m = new MelipayamakSender(['username'=>'fixture-user','api_key'=>'fixture-key'], ['mode'=>'pattern','body_id'=>123,'values'=>['ACh Shop','{code}']], ['link'=>['mode'=>'text','sender'=>'50001','message'=>'Code {code}']]);
$mbase = 'https://rest.payamak-panel.com/api/SendSMS/';
$auth=['username'=>'fixture-user','password'=>'fixture-key'];
$mbody=$auth+['to'=>$local,'bodyId'=>'123','text'=>'ACh Shop;'.$otp];
$mreceipt=['RetStatus'=>1,'Value'=>'12345678901234567890','StrRetStatus'=>'fixture'];
mocked(fn()=> $m->send($mobile,$otp,'login'),$mbase.'BaseServiceNumber',$mbody,response($mreceipt),'accepted','receipt','12345678901234567890');
mocked(fn()=> $m->send($mobile,$otp,'link'),$mbase.'SendSMS',$auth+['to'=>$local,'from'=>'50001','text'=>'Code '.$otp,'isflash'=>'false'],response($mreceipt),'accepted','receipt','12345678901234567890');
mocked(fn()=> $m->send($mobile,$otp,'login'),$mbase.'BaseServiceNumber',$mbody,response(['RetStatus'=>1,'Value'=>'-4']),'failed','api_-4');
mocked(fn()=> $m->send($mobile,$otp,'login'),$mbase.'BaseServiceNumber',$mbody,response(['RetStatus'=>1,'Value'=>'35']),'failed','api_35');
mocked(fn()=> $m->send($mobile,$otp,'login'),$mbase.'BaseServiceNumber',$mbody,response(['RetStatus'=>1,'Value'=>'-6']),'uncertain','api_-6');
mocked(fn()=> $m->send($mobile,$otp,'login'),$mbase.'BaseServiceNumber',$mbody,response(['RetStatus'=>1,'Value'=>'123456789012345']),'uncertain','http_200_invalid_receipt');
mocked(fn()=> $m->send($mobile,$otp,'login'),$mbase.'BaseServiceNumber',$mbody,response('invalid',502),'uncertain','http_502_invalid_response');
mocked(fn()=> $m->connection(),$mbase.'GetCredit',$auth,response(['RetStatus'=>1,'Value'=>'0']),'accepted','connected');

$noCalls=0;
$guard=static function() use (&$noCalls) { $noCalls++; return new WP_Error('blocked'); };
add_filter('pre_http_request',$guard);
$bad=new MelipayamakSender(['username'=>'fixture-user','api_key'=>'fixture-key'],['mode'=>'pattern','body_id'=>123,'values'=>['x;y','{code}']]);
ensure($bad->send($mobile,$otp,'login')->state==='failed','Invalid pattern was not rejected');
ensure($noCalls===0,'Invalid pattern made an external call');
remove_filter('pre_http_request',$guard);
echo "PASS: provider request contracts, explicit modes, rejection, uncertainty, receipt and connection parsing\n";
