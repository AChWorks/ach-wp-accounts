<?php
namespace ACh\WPAccounts;

defined('ABSPATH') || exit;

/** Explicit transport selection; the configured mode never falls back automatically. */
abstract class HttpSmsSender implements MobileCodeDelivery
{
    public function __construct(#[\SensitiveParameter] protected readonly array $credentials, protected readonly array $default, protected readonly array $purposes = [])
    {
    }

    protected function config(string $purpose): array
    {
        return $this->purposes[$purpose] ?? $this->default;
    }

    protected function secret(string $key): string
    {
        $value = $this->credentials[$key] ?? '';
        if (!is_string($value) || trim($value) === '' || trim($value) !== $value || preg_match('/[\x00-\x1f\x7f]/', $value)) {
            throw new \InvalidArgumentException('Missing or invalid provider credential.');
        }
        return $value;
    }

    protected function interpolate(mixed $value, string $code): string
    {
        if (!is_string($value) || $value === '' || !preg_match('/\A[0-9]{6}\z/D', $code)) {
            throw new \InvalidArgumentException('Invalid message configuration.');
        }
        return str_replace('{code}', $code, $value);
    }

    protected function text(array $config, string $code): string
    {
        $message = $config['message'] ?? '';
        if (!is_string($message) || !str_contains($message, '{code}')) {
            throw new \InvalidArgumentException('Message must contain the OTP placeholder.');
        }
        return $this->interpolate($message, $code);
    }

    protected function sender(array $config): string
    {
        $sender = $config['sender'] ?? '';
        if (!is_string($sender) || !preg_match('/\A\+?[0-9]+\z/D', $sender)) {
            throw new \InvalidArgumentException('Invalid sender number.');
        }
        return $sender;
    }

    /** Return bounded structured data only; never propagate URLs or raw errors. */
    protected function post(#[\SensitiveParameter] string $url, #[\SensitiveParameter] array $body): array|SendResult
    {
        try {
            $response = wp_remote_post($url, [
                'timeout' => 12,
                'redirection' => 0,
                'sslverify' => true,
                'limit_response_size' => 65536,
                'headers' => ['Accept' => 'application/json', 'Content-Type' => 'application/x-www-form-urlencoded'],
                'body' => http_build_query($body, '', '&', PHP_QUERY_RFC3986),
            ]);
        } catch (\Throwable $error) {
            return new SendResult('uncertain', 'transport_exception');
        }
        if (is_wp_error($response)) {
            return new SendResult('uncertain', 'transport_error');
        }
        $http = (int) wp_remote_retrieve_response_code($response);
        $data = json_decode(wp_remote_retrieve_body($response), true, 32, JSON_BIGINT_AS_STRING);
        if (!is_array($data)) {
            return new SendResult('uncertain', 'http_' . $http . '_invalid_response');
        }
        return [$http, $data];
    }

    protected function status(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }
        return is_string($value) && preg_match('/\A-?[0-9]{1,9}\z/D', $value) ? (int) $value : null;
    }

    protected function reference(mixed $value): string
    {
        return (is_int($value) || is_string($value)) && preg_match('/\A[1-9][0-9]{0,127}\z/D', (string) $value) ? (string) $value : '';
    }

    abstract public function connection(): SendResult;

    final public function send(string $mobile, string $code, string $purpose): SendResult
    {
        try {
            return $this->dispatch(Mobile::local($mobile), $code, $this->config($purpose));
        } catch (\InvalidArgumentException $error) {
            // Configuration was rejected before any external request.
            return new SendResult('failed', 'invalid_configuration');
        }
    }

    /** Validate all configured routes without network I/O or duplicating transport rules. */
    final public function validate(): void
    {
        if (array_diff(array_keys($this->purposes), ['login', 'link', 'step_up', 'password_reset'])) {
            throw new \InvalidArgumentException('Invalid verification purpose.');
        }
        foreach ([$this->default, ...array_values($this->purposes)] as $config) {
            if (!is_array($config)) {
                throw new \InvalidArgumentException('Invalid message configuration.');
            }
            $this->request('09120000000', '000000', $config);
        }
    }

    /** @return array{0:string,1:array} Pure provider request construction. */
    abstract protected function request(string $mobile, string $code, array $config): array;

    abstract protected function dispatch(string $mobile, string $code, array $config): SendResult;
}

final class KavenegarSender extends HttpSmsSender
{
    public function id(): string { return 'kavenegar'; }

    private function url(string $endpoint): string
    {
        return 'https://api.kavenegar.com/v1/' . rawurlencode($this->secret('api_key')) . '/' . $endpoint . '.json';
    }

    private function envelope(array $response): array|SendResult
    {
        [$http, $data] = $response;
        $status = $this->status($data['return']['status'] ?? null);
        if ($http >= 200 && $http < 300 && $status === 200) {
            return $data;
        }
        // Explicit documented request rejection; server/unknown/conflicting outcomes
        // are not evidence that an SMS was not accepted.
        $known = [400,401,403,404,405,406,407,411,412,413,414,416,417,418,419,420,422,424,426,427,428,429,431,432,451,501,607];
        $definite = in_array($status, $known, true) && ($http === $status || $http === 200);
        return new SendResult($definite ? 'failed' : 'uncertain', $status === null ? 'http_' . $http : 'api_' . $status);
    }

    protected function request(string $mobile, string $code, array $config): array
    {
        $mode = $config['mode'] ?? '';
        if ($mode === 'pattern') {
            $template = $config['template'] ?? '';
            if (!is_string($template) || !preg_match('/\A[A-Za-z0-9-]+\z/D', $template)) {
                throw new \InvalidArgumentException('Invalid template name.');
            }
            $body = ['receptor' => $mobile, 'template' => $template, 'token' => $this->interpolate('{code}', $code), 'type' => 'sms'];
            $tokens = $config['tokens'] ?? [];
            if (!is_array($tokens) || array_diff(array_keys($tokens), ['token2','token3','token10','token20'])) {
                throw new \InvalidArgumentException('Unsupported token field.');
            }
            foreach ($tokens as $name => $value) {
                $token = $this->interpolate($value, $code);
                $spaces = ['token2' => 0, 'token3' => 0, 'token10' => 5, 'token20' => 8][$name];
                if (preg_match('/\A.{1,100}\z/usD', $token) !== 1 || substr_count($token, ' ') > $spaces ||
                    preg_match('/[\r\n\t_]/', $token)) {
                    throw new \InvalidArgumentException('Invalid pattern token.');
                }
                $body[$name] = $token;
            }
            $url = $this->url('verify/lookup');
        } elseif ($mode === 'text') {
            $body = ['receptor' => $mobile, 'sender' => $this->sender($config), 'message' => $this->text($config, $code)];
            $url = $this->url('sms/send');
        } else {
            throw new \InvalidArgumentException('Unsupported sending mode.');
        }
        return [$url, $body];
    }

    protected function dispatch(string $mobile, string $code, array $config): SendResult
    {
        [$url, $body] = $this->request($mobile, $code, $config);
        $response = $this->post($url, $body);
        if ($response instanceof SendResult) {
            return $response;
        }
        $data = $this->envelope($response);
        if ($data instanceof SendResult) {
            return $data;
        }
        $entries = $data['entries'] ?? null;
        $entry = is_array($entries) && count($entries) === 1 ? ($entries[0] ?? null) : null;
        if (!is_array($entry)) {
            return new SendResult('uncertain', 'invalid_entries');
        }
        $id = $this->reference($entry['messageid'] ?? null);
        try {
            $receptor = is_string($entry['receptor'] ?? null) ? Mobile::local($entry['receptor']) : '';
        } catch (\InvalidArgumentException $error) {
            $receptor = '';
        }
        $status = $this->status($entry['status'] ?? null);
        if ($id === '' || $receptor !== $mobile || $status === null) {
            return new SendResult('uncertain', 'invalid_receipt');
        }
        // Known terminal non-delivery is distinct from acceptance and delivery.
        $state = in_array($status, [1,2,4,5,10], true) ? 'accepted' :
            (in_array($status, [6,11,13,14], true) ? 'failed' : 'uncertain');
        return new SendResult($state, 'message_' . $status, $id);
    }

    public function connection(): SendResult
    {
        try {
            $response = $this->post($this->url('account/info'), []);
            if ($response instanceof SendResult) {
                return $response;
            }
            $data = $this->envelope($response);
            return $data instanceof SendResult ? $data :
                (isset($data['entries']['remaincredit']) && is_numeric($data['entries']['remaincredit']) ? new SendResult('accepted', 'connected') : new SendResult('uncertain', 'invalid_entries'));
        } catch (\InvalidArgumentException $error) {
            return new SendResult('failed', 'invalid_configuration');
        }
    }
}

final class MelipayamakSender extends HttpSmsSender
{
    public function id(): string { return 'melipayamak'; }

    private function auth(): array
    {
        return ['username' => $this->secret('username'), 'password' => $this->secret('api_key')];
    }

    protected function request(string $mobile, string $code, array $config): array
    {
        $body = $this->auth() + ['to' => $mobile];
        $mode = $config['mode'] ?? '';
        if ($mode === 'pattern') {
            $bodyId = filter_var($config['body_id'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 2147483647]]);
            $values = $config['values'] ?? null;
            if ($bodyId === false || !is_array($values) || !array_is_list($values) || !$values) {
                throw new \InvalidArgumentException('Invalid pattern configuration.');
            }
            $parts = [];
            $hasCode = false;
            foreach ($values as $value) {
                $part = $this->interpolate($value, $code);
                if (str_contains($part, ';')) {
                    throw new \InvalidArgumentException('Pattern separator in a value.');
                }
                $hasCode = $hasCode || str_contains($value, '{code}');
                $parts[] = $part;
            }
            if (!$hasCode) {
                throw new \InvalidArgumentException('Pattern must contain the OTP placeholder.');
            }
            $body += ['bodyId' => $bodyId, 'text' => implode(';', $parts)];
            $endpoint = 'BaseServiceNumber';
        } elseif ($mode === 'text') {
            $body += ['from' => $this->sender($config), 'text' => $this->text($config, $code), 'isflash' => 'false'];
            $endpoint = 'SendSMS';
        } else {
            throw new \InvalidArgumentException('Unsupported sending mode.');
        }
        return ['https://rest.payamak-panel.com/api/SendSMS/' . $endpoint, $body];
    }

    protected function dispatch(string $mobile, string $code, array $config): SendResult
    {
        [$url, $body] = $this->request($mobile, $code, $config);
        $mode = $config['mode'];
        $response = $this->post($url, $body);
        if ($response instanceof SendResult) {
            return $response;
        }
        [$http, $data] = $response;
        $status = $this->status($data['RetStatus'] ?? null);
        $value = $data['Value'] ?? null;
        $id = $this->reference($value);
        if ($http >= 200 && $http < 300 && $status === 1 && strlen($id) > 15) {
            return new SendResult('accepted', 'receipt', $id);
        }
        $code = $this->status($value);
        // Small documented rejection codes are not receipt IDs; unknown/internal
        // failures or inconsistent envelopes require reconciliation, not retry.
        $known = $mode === 'pattern' ? [-111,-110,-109,-108,-10,-7,-5,-4,-3,-2,-1,0,2,7,10,11,12,16,17,18,19,35] : [-110,-109,-108,0,2,3,4,5,7,9,10,11,12,14,15,16,17,18,35];
        $definite = $http === 200 && in_array($status, [0,1], true) && in_array($code, $known, true);
        return new SendResult($definite ? 'failed' : 'uncertain', $code === null ? 'http_' . $http . '_invalid_receipt' : 'api_' . $code);
    }

    public function connection(): SendResult
    {
        try {
            $response = $this->post('https://rest.payamak-panel.com/api/SendSMS/GetCredit', $this->auth());
            if ($response instanceof SendResult) {
                return $response;
            }
            [$http, $data] = $response;
            $value = $data['Value'] ?? null;
            $valid = (is_int($value) || is_float($value) || is_string($value)) &&
                is_numeric($value) && is_finite((float) $value) && (float) $value >= 0;
            if ($http === 200 && $this->status($data['RetStatus'] ?? null) === 1 && $valid) {
                return new SendResult('accepted', 'connected');
            }
            return new SendResult('uncertain', 'credit_unavailable');
        } catch (\InvalidArgumentException $error) {
            return new SendResult('failed', 'invalid_configuration');
        }
    }
}

/** Actual initial providers only; shared by configuration and native discovery. */
final class ProviderRegistry
{
    public static function capabilities(): array
    {
        return [
            ['id' => 'kavenegar', 'modes' => ['pattern', 'text'], 'pattern_parameters' => 'named', 'credential_fields' => ['api_key']],
            ['id' => 'melipayamak', 'modes' => ['pattern', 'text'], 'pattern_parameters' => 'ordered', 'credential_fields' => ['username','api_key']],
        ];
    }

    public static function create(string $id, #[\SensitiveParameter] array $credentials, array $default, array $purposes = []): HttpSmsSender
    {
        return match ($id) {
            'kavenegar' => new KavenegarSender($credentials, $default, $purposes),
            'melipayamak' => new MelipayamakSender($credentials, $default, $purposes),
            default => throw new \InvalidArgumentException('Unknown SMS provider.'),
        };
    }
}
