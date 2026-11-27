<?php
namespace ACh\WPAccounts;

defined('ABSPATH') || exit;

/** No public authentication is available until explicitly configured by the operator. */
final class BrowserApi
{
    public const ACTION = 'ach_wp_accounts';

    public static function enabled(): bool
    {
        return !is_multisite() && self::flag('ach_wp_accounts_auth_enabled') &&
            (int) get_option('ach_wp_accounts_schema_version', 0) === Schema::VERSION;
    }

    public static function flag(string $option): bool
    {
        return in_array(get_option($option, false), [true, 1, '1'], true);
    }

    public static function hooks(): void
    {
        add_action('wp_ajax_' . self::ACTION, [self::class, 'dispatch']);
        add_action('wp_ajax_nopriv_' . self::ACTION, [self::class, 'dispatch']);
    }

    private static function origin(string $url): string
    {
        $parsed = wp_parse_url($url);
        if (!is_array($parsed) || strtolower($parsed['scheme'] ?? '') !== 'https' ||
            !is_string($parsed['host'] ?? null) || isset($parsed['user']) || isset($parsed['pass'])) {
            return '';
        }
        $port = isset($parsed['port']) && (int)$parsed['port'] !== 443 ? ':' . (int)$parsed['port'] : '';
        return 'https://' . strtolower($parsed['host']) . $port;
    }

    /** Browser POST only. Never trust forwarded headers supplied by a client. */
    public static function authorizeRequest(): void
    {
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        $expected = self::origin(admin_url('admin-ajax.php', 'https'));
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !is_ssl() ||
            ($_SERVER['HTTP_X_ACH_ACCOUNTS'] ?? '') !== '1' ||
            !is_string($origin) || $expected === '' || !hash_equals($expected, $origin)) {
            throw new VerificationError('invalid_context');
        }
        if (!self::enabled()) {
            throw new VerificationError('authentication_unavailable');
        }
    }

    private static function input(string $name, int $max, bool $required = true): string
    {
        $value = $_POST[$name] ?? '';
        if (!is_string($value)) {
            throw new VerificationError('invalid_context');
        }
        $value = wp_unslash($value);
        if (strlen($value) > $max || ($required && $value === '')) {
            throw new VerificationError('invalid_context');
        }
        return $value;
    }

    public static function cookieName(): string
    {
        return '__Host-ach-accounts-' . COOKIEHASH;
    }

    private static function browser(bool $create): string
    {
        $name = self::cookieName();
        $secret = $_COOKIE[$name] ?? '';
        if (is_string($secret) && preg_match('/\A[0-9a-f]{64}\z/D', $secret)) {
            return $secret;
        }
        if (!$create || headers_sent()) {
            throw new VerificationError('invalid_context');
        }
        $secret = bin2hex(random_bytes(32));
        if (!setcookie($name, $secret, [
            'expires' => 0, 'path' => '/', 'secure' => true, 'httponly' => true, 'samesite' => 'Strict',
        ])) {
            throw new VerificationError('invalid_context');
        }
        return $secret;
    }

    /** Internal provider configuration, never included in a browser response. */
    public static function sender(): HttpSmsSender
    {
        $config = get_option('ach_wp_accounts_provider', []);
        if (!is_array($config) || !is_string($config['id'] ?? null) ||
            !is_array($config['credentials'] ?? null) || !is_array($config['default'] ?? null) ||
            !is_array($config['purposes'] ?? [])) {
            throw new VerificationError('authentication_unavailable');
        }
        try {
            return ProviderRegistry::create($config['id'], $config['credentials'], $config['default'], $config['purposes'] ?? []);
        } catch (\InvalidArgumentException $error) {
            throw new VerificationError('authentication_unavailable');
        }
    }

    private static function status(): array
    {
        $id = get_current_user_id();
        $identity = Identity::byUser($id);
        return [
            'authenticated' => $id > 0,
            'qualified' => SessionProof::qualifies(),
            'recent_proof' => SessionProof::qualifies(SessionProof::LINK_FRESHNESS),
            'has_mobile' => $identity !== null,
            'masked_mobile' => $identity ? '+98 ' . substr($identity->mobile, 3, 3) . ' *** ' . substr($identity->mobile, -4) : '',
        ];
    }

    /** Only safe domain errors are translated and returned to customers. */
    private static function error(string $code): array
    {
        return match ($code) {
            'rate_limited', 'reauthentication_unavailable' => [429, __('Too many requests. Please try again later.', 'ach-wp-accounts')],
            'invalid_proof' => [400, __('The code is invalid or has expired. Please check the code or request a new one.', 'ach-wp-accounts')],
            'identity_changed' => [409, __('Your account or mobile number changed. Please start again.', 'ach-wp-accounts')],
            'identity_conflict' => [409, __('This mobile number cannot be linked to this account. Please use your existing account or contact support.', 'ach-wp-accounts')],
            'native_login_required' => [403, __('Please sign in using your existing account credentials.', 'ach-wp-accounts')],
            'registration_disabled', 'registration_denied' => [403, __('Account registration is not available here. Please sign in to your existing account or contact support.', 'ach-wp-accounts')],
            'registration_pending' => [409, __('We could not finish creating your account. Please contact support before trying again.', 'ach-wp-accounts')],
            'reauthentication_required' => [403, __('Please confirm your current account credentials.', 'ach-wp-accounts')],
            'authentication_unavailable', 'storage_unavailable' => [503, __('Mobile verification is temporarily unavailable. Please try again later or contact support.', 'ach-wp-accounts')],
            default => [400, __('The request is invalid. Please start again.', 'ach-wp-accounts')],
        };
    }

    public static function dispatch(): void
    {
        nocache_headers();
        if (!headers_sent()) {
            header('Cache-Control: no-store, private, max-age=0', true);
            header('Referrer-Policy: no-referrer', true);
        }
        try {
            self::authorizeRequest();
            $operation = self::input('operation', 32);
            $flow = new AccountFlow();
            switch ($operation) {
                case 'status':
                    $result = self::status();
                    break;
                case 'start':
                    self::sender(); // Validate configured provider identity before creating a flow.
                    $purpose = self::input('purpose', 32);
                    $mobile = $purpose === 'step_up'
                        ? (Identity::byUser(get_current_user_id())->mobile ?? '')
                        : self::input('mobile', 128);
                    $ticket = $flow->start(
                        self::browser(true), $mobile, $purpose, self::input('context', 32),
                        self::input('password', 4096, false)
                    );
                    $result = ['ticket' => $ticket];
                    break;
                case 'issue':
                    $result = $flow->issue(
                        self::browser(false), self::input('ticket', 2048),
                        (string)($_SERVER['REMOTE_ADDR'] ?? ''), self::sender()
                    );
                    break;
                case 'complete':
                    $result = $flow->complete(
                        self::browser(false), self::input('ticket', 2048), self::input('code', 32)
                    );
                    break;
                default:
                    throw new VerificationError('invalid_context');
            }
            wp_send_json_success($result);
        } catch (VerificationError $error) {
            $code = $error->getMessage();
            [$status, $message] = self::error($code);
            wp_send_json_error(['code' => $code, 'message' => $message], $status);
        } catch (\InvalidArgumentException $error) {
            wp_send_json_error(['code' => 'invalid_input', 'message' => __('Please check the mobile number and try again.', 'ach-wp-accounts')], 400);
        } catch (\Throwable $error) {
            // No raw exception/credentials/OTP/ticket or HTTP payload in responses.
            [$status, $message] = self::error('authentication_unavailable');
            wp_send_json_error(['code' => 'authentication_unavailable', 'message' => $message], $status);
        }
    }
}
