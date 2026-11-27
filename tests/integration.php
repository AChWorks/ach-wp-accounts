<?php
use ACh\WPAccounts\Mobile;
use ACh\WPAccounts\Schema;
use ACh\WPAccounts\SmsSender;
use ACh\WPAccounts\SendResult;
use ACh\WPAccounts\Verification;
use ACh\WPAccounts\VerificationError;
use ACh\WPAccounts\VerificationPolicy;

$root = getenv('ACH_TEST_WP');
$dir = getenv('ACH_TEST_DIR');
if (!$root || !$dir) {
    throw new RuntimeException('Run through scripts/test-integration.sh.');
}
require $root . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
if (get_option('siteurl') !== 'https://accounts.test' || wp_get_environment_type() !== 'local' || DB_NAME !== 'accounts_fixture') {
    throw new RuntimeException('Not an isolated test fixture.');
}
if (!is_plugin_active('ach-wp-accounts/ach-wp-accounts.php')) {
    $result = activate_plugin('ach-wp-accounts/ach-wp-accounts.php');
    if (is_wp_error($result)) {
        throw new RuntimeException($result->get_error_message());
    }
}
require_once WP_PLUGIN_DIR . '/ach-wp-accounts/ach-wp-accounts.php';

final class FixtureSender implements SmsSender
{
    public function __construct(private string $file, private string $state = 'accepted') {}
    public function id(): string { return 'fixture'; }
    public function send(string $mobile, string $code, string $purpose): SendResult
    {
        file_put_contents($this->file, "$mobile $code $purpose\n", FILE_APPEND | LOCK_EX);
        usleep(150000); // Overlap real independent database connections.
        if ($this->state === 'exception') {
            throw new RuntimeException('Synthetic secret must not be persisted.');
        }
        return new SendResult($this->state, 'fixture_status', '123');
    }
}

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function denied(callable $action, string $expected): void
{
    try {
        $action();
    } catch (VerificationError $error) {
        check($error->getMessage() === $expected, 'Unexpected error: ' . $error->getMessage());
        return;
    }
    throw new RuntimeException('Expected rejection: ' . $expected);
}

function requestId(): string { return bin2hex(random_bytes(16)); }
function flowId(): string { return bin2hex(random_bytes(32)); }

function codeFrom(string $file): string
{
    $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    check(count($lines) === 1, 'Expected exactly one fixture send');
    return explode(' ', $lines[0])[1];
}

function resetFixture(): void
{
    global $wpdb;
    foreach (['challenges', 'limits'] as $name) {
        check($wpdb->query('TRUNCATE TABLE ' . Schema::table($name)) !== false, 'Fixture reset failed');
    }
}

/** Spawn separate PHP/WP processes, coordinate start after DB connections are ready. */
function race(array $jobs): array
{
    global $dir;
    $barrier = $dir . '/race-' . requestId();
    mkdir($barrier, 0700);
    $children = [];
    try {
        foreach ($jobs as $i => $job) {
            $command = [PHP_BINARY, __FILE__, 'worker', $barrier, (string) $i, json_encode($job, JSON_THROW_ON_ERROR)];
            $proc = proc_open($command, [0 => ['pipe','r'], 1 => ['pipe','w'], 2 => ['pipe','w']], $pipes);
            check(is_resource($proc), 'Cannot spawn integration worker');
            fclose($pipes[0]);
            $children[] = [$proc, $pipes];
        }
        $deadline = microtime(true) + 20;
        while (count(glob($barrier . '/ready-*')) !== count($jobs)) {
            check(microtime(true) < $deadline, 'Workers did not reach barrier');
            usleep(20000);
        }
        touch($barrier . '/go');
        $results = [];
        foreach ($children as $i => [$proc, $pipes]) {
            stream_set_timeout($pipes[1], 25);
            $out = stream_get_contents($pipes[1]);
            $err = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exit = proc_close($proc);
            unset($children[$i]);
            check($exit === 0, "Worker failed: $err $out");
            $results[] = json_decode($out, true, 512, JSON_THROW_ON_ERROR);
        }
        return $results;
    } finally {
        foreach ($children as [$proc, $pipes]) {
            proc_terminate($proc);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($proc);
        }
    }
}

if (($argv[1] ?? '') === 'worker') {
    $job = json_decode($argv[4], true, 512, JSON_THROW_ON_ERROR);
    $service = new Verification(new VerificationPolicy(...($job['policy'] ?? [])));
    touch($argv[2] . '/ready-' . $argv[3]);
    $deadline = microtime(true) + 20;
    while (!is_file($argv[2] . '/go')) {
        check(microtime(true) < $deadline, 'Worker start timeout');
        usleep(10000);
    }
    try {
        $value = match ($job['action']) {
            'issue' => $service->issue($job['id'], $job['mobile'], $job['purpose'], $job['flow'], $job['network'], new FixtureSender($job['file'])),
            'verify' => $service->verify($job['id'], $job['purpose'], $job['flow'], $job['code']),
            'consume' => $service->consume($job['id'], $job['purpose'], $job['flow'], $job['proof']),
            'identity' => $GLOBALS['wpdb']->query($GLOBALS['wpdb']->prepare(
                'INSERT IGNORE INTO ' . Schema::table('identities') . ' (user_id,mobile,revision,verified_at) VALUES (%d,%s,%s,%d)',
                $job['user'], $job['mobile'], requestId(), time()
            )),
        };
        echo json_encode(['ok' => true, 'value' => $value], JSON_THROW_ON_ERROR);
    } catch (VerificationError $error) {
        echo json_encode(['ok' => false, 'error' => $error->getMessage()], JSON_THROW_ON_ERROR);
    }
    exit;
}

check(get_bloginfo('version') === getenv('ACH_TEST_WP_VERSION'), 'Unexpected WordPress version');
check((int) get_option('ach_wp_accounts_schema_version') === Schema::VERSION, 'Schema activation failed');
check(wp_next_scheduled('ach_wp_accounts_cleanup') !== false, 'Cleanup not scheduled');

foreach (['09123456789', '+989123456789', '00989123456789', '۹۱۲۳۴۵۶۷۸۹', '٠٩١٢٣٤٥٦٧٨٩', '+98 (912) 345-6789'] as $mobile) {
    check(Mobile::normalize($mobile) === '+989123456789', 'Normalization mismatch');
}
foreach (['1234567890', '+199123456789', '0912abc3456789', '091234567890', '0912.3456789', ''] as $mobile) {
    try {
        Mobile::normalize($mobile);
        throw new RuntimeException('Invalid number accepted');
    } catch (InvalidArgumentException $expected) {}
}

$service = new Verification();
$id = requestId();
$flow = flowId();
$file = "$dir/same-request";
$job = ['action'=>'issue','id'=>$id,'mobile'=>'09123456789','purpose'=>'login','flow'=>$flow,'network'=>'192.0.2.1','file'=>$file];
$results = race(array_fill(0, 8, $job));
check(count(array_filter($results, static fn ($r) => $r['ok'])) === 8, 'Exact replay failed');
$code = codeFrom($file);
check($service->issue($id, '09123456789', 'login', $flow, '192.0.2.1', new FixtureSender($file))['state'] === 'accepted', 'Replay did not adopt final status');
denied(fn () => $service->issue($id, '09123456780', 'login', $flow, '192.0.2.1', new FixtureSender($file)), 'request_conflict');
denied(fn () => $service->verify($id, 'link', $flow, $code), 'invalid_proof');
denied(fn () => $service->verify($id, 'login', flowId(), $code), 'invalid_proof');

$challengeTable = Schema::table('challenges');
$row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $challengeTable WHERE id=%s", $id));
check(strlen($row->code_hash) === 64 && $row->code_hash !== $code, 'Code stored unsafely');
check($row->flow_hash !== $flow && $row->attempts == 0, 'Wrong-flow attempts altered state');
$verify = array_merge($job, ['action'=>'verify','code'=>$code]);
$results = race(array_fill(0, 8, $verify));
$success = array_values(array_filter($results, static fn ($r) => $r['ok']));
check(count($success) === 1, 'OTP did not verify exactly once');
$proof = $success[0]['value'];
$row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $challengeTable WHERE id=%s", $id));
check($row->code_hash === '' && $row->proof_hash !== $proof && strlen($row->proof_hash) === 64, 'Proof storage invalid');
denied(fn () => $service->consume($id, 'link', $flow, $proof), 'invalid_proof');
denied(fn () => $service->consume($id, 'login', flowId(), $proof), 'invalid_proof');
$results = race(array_fill(0, 8, array_merge($job, ['action'=>'consume','proof'=>$proof])));
$success = array_values(array_filter($results, static fn ($r) => $r['ok']));
check(count($success) === 1 && $success[0]['value'] === '+989123456789', 'Proof did not consume exactly once');
echo "PASS: concurrent exact request, OTP verification and proof consumption\n";

resetFixture();
$file = "$dir/cooldown";
$jobs = [];
for ($i=0; $i<8; ++$i) {
    $jobs[] = array_merge($job, ['id'=>requestId(),'file'=>$file,'flow'=>flowId()]);
}
$results = race($jobs);
$success = array_filter($results, static fn ($r) => $r['ok']);
check(count($success) === 1, 'Concurrent cooldown bypass');
codeFrom($file);
foreach ($results as $result) {
    check($result['ok'] || $result['error'] === 'rate_limited', 'Unexpected cooldown race outcome');
}
echo "PASS: concurrent distinct request cooldown\n";

resetFixture();
$file = "$dir/budget";
$jobs = [];
for ($i=0; $i<8; ++$i) {
    $jobs[] = array_merge($job, ['id'=>requestId(),'mobile'=>'0912345678'.$i,'network'=>'192.0.2.'.($i+1),'file'=>$file,'policy'=>['siteHourly'=>2]]);
}
$results = race($jobs);
check(count(array_filter($results, static fn ($r) => $r['ok'])) === 2, 'Concurrent site budget bypass');
check(count(file($file)) === 2, 'Site budget send count mismatch');
echo "PASS: concurrent site quota\n";

resetFixture();
$id = requestId();
$file = "$dir/attempts";
$service->issue($id, '09123456789', 'login', $flow, '192.0.2.1', new FixtureSender($file));
$code = codeFrom($file);
$bad = $code === '000000' ? '000001' : '000000';
$results = race(array_fill(0, 10, array_merge($verify, ['id'=>$id,'code'=>$bad])));
check(count(array_filter($results, static fn ($r) => $r['ok'])) === 0, 'Invalid OTP verified');
check((int) $wpdb->get_var($wpdb->prepare("SELECT attempts FROM $challengeTable WHERE id=%s", $id)) === 5, 'Concurrent attempt limit bypass');
denied(fn () => $service->verify($id, 'login', $flow, $code), 'invalid_proof');
echo "PASS: concurrent wrong-code attempt limit\n";

// The verification budget is shared across different challenges for one
// mobile, not reset by a successful new SMS issuance.
resetFixture();
$bounded = new Verification(new VerificationPolicy(cooldown: 1, verifyHourly: 2));
$first = requestId();
$file = "$dir/aggregate-first";
$bounded->issue($first, '09123456789', 'login', $flow, '192.0.2.1', new FixtureSender($file));
$actual = codeFrom($file);
$wrong = $actual === '000000' ? '000001' : '000000';
denied(fn()=> $bounded->verify($first,'login',$flow,$wrong),'invalid_proof');
$cooldownKey = hash_hmac('sha256', "cooldown\0+989123456789", wp_salt('auth'));
$wpdb->update(Schema::table('limits'), ['next_at'=>time()-1], ['bucket_key'=>$cooldownKey]);
$second = requestId();
$file = "$dir/aggregate-second";
$bounded->issue($second,'09123456789','login',$flow,'192.0.2.1',new FixtureSender($file));
$actual = codeFrom($file);
$wrong = $actual === '000000' ? '000001' : '000000';
denied(fn()=> $bounded->verify($second,'login',$flow,$wrong),'invalid_proof');
denied(fn()=> $bounded->verify($second,'login',$flow,$actual),'rate_limited');
echo "PASS: verification budget across renewed challenges\n";


foreach (['failed','uncertain','exception'] as $state) {
    resetFixture();
    $id = requestId();
    $file = "$dir/$state";
    $out = $service->issue($id, '09123456789', 'login', $flow, '192.0.2.1', new FixtureSender($file, $state));
    check($out['state'] === ($state === 'exception' ? 'uncertain' : $state), 'Send outcome classification');
    $service->issue($id, '09123456789', 'login', $flow, '192.0.2.1', new FixtureSender($file));
    $code = codeFrom($file);
    if ($state === 'failed') {
        denied(fn () => $service->verify($id, 'login', $flow, $code), 'invalid_proof');
    } else {
        $proof = $service->verify($id, 'login', $flow, $code);
        check($service->consume($id, 'login', $flow, $proof) === '+989123456789', 'Uncertain delivery proof failed');
    }
    $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $challengeTable WHERE id=%s", $id));
    check(!str_contains(json_encode($row), 'Synthetic secret'), 'Exception leaked');
}
echo "PASS: definite failure, uncertain outcome, no blind resend\n";

resetFixture();
$id = requestId();
$file = "$dir/expired-otp";
$service->issue($id, '09123456789', 'login', $flow, '192.0.2.1', new FixtureSender($file));
$wpdb->query($wpdb->prepare("UPDATE $challengeTable SET expires_at=%d WHERE id=%s", time()-1, $id));
denied(fn () => $service->verify($id, 'login', $flow, codeFrom($file)), 'invalid_proof');
resetFixture();
$id = requestId();
$file = "$dir/expired-proof";
$service->issue($id, '09123456789', 'login', $flow, '192.0.2.1', new FixtureSender($file));
$proof = $service->verify($id, 'login', $flow, codeFrom($file));
$wpdb->query($wpdb->prepare("UPDATE $challengeTable SET proof_expires_at=%d WHERE id=%s", time()-1, $id));
denied(fn () => $service->consume($id, 'login', $flow, $proof), 'invalid_proof');
echo "PASS: OTP and continuation expiry\n";

$jobs = [];
for ($i=0; $i<8; ++$i) {
    $jobs[] = ['action'=>'identity','user'=>100+$i,'mobile'=>'+989123456789'];
}
$results = race($jobs);
check(count(array_filter($results, static fn ($r) => $r['value'] === 1)) === 1, 'Mobile identity uniqueness failed');
$identityTable = Schema::table('identities');
$user = (int) $wpdb->get_var("SELECT user_id FROM $identityTable");
check($wpdb->query($wpdb->prepare("INSERT IGNORE INTO $identityTable (user_id,mobile,revision,verified_at) VALUES (%d,%s,%s,%d)", $user, '+989123456780', requestId(), time())) === 0, 'Per-user identity uniqueness failed');

$wpdb->query($wpdb->prepare("UPDATE $challengeTable SET retire_at=%d WHERE id=%s", time()-1, $id));
Schema::cleanup();
check($wpdb->get_var($wpdb->prepare("SELECT id FROM $challengeTable WHERE id=%s", $id)) === null, 'Expired challenge cleanup failed');
deactivate_plugins('ach-wp-accounts/ach-wp-accounts.php');
check(wp_next_scheduled('ach_wp_accounts_cleanup') === false, 'Cleanup left scheduled on deactivate');
check((int) $wpdb->get_var("SELECT COUNT(*) FROM $identityTable") === 1, 'Deactivation destroyed identity data');
$result = activate_plugin('ach-wp-accounts/ach-wp-accounts.php');
check(!is_wp_error($result) && (int) $wpdb->get_var("SELECT COUNT(*) FROM $identityTable") === 1, 'Reactivation lost identity data');
echo "PASS: real unique constraints, cleanup, state-preserving activation lifecycle\n";
