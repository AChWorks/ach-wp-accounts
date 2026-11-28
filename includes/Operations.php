<?php
namespace ACh\WPAccounts;

defined('ABSPATH') || exit;

/** Shared operator operations for native admin and Abilities. */
final class Operations
{
    private static function snapshot(?string $revision): array
    {
        $settings = Settings::current();
        if ($revision !== null && $settings['revision'] !== $revision) {
            throw new SettingsError('revision','settings_conflict');
        }
        return $settings;
    }

    public static function connection(?string $provider = null, ?string $revision = null): array
    {
        Settings::requireOperator();
        $settings = self::snapshot($revision);
        $sender = Settings::sender($provider,$settings);
        $start = hrtime(true);
        try {
            $result = $sender->connection();
        } catch (\Throwable $error) {
            $result = new SendResult('uncertain', 'transport_exception');
        }
        return Diagnostics::connection($sender->id(), $result, (int)min(4294967295, (hrtime(true) - $start) / 1000000));
    }

    /**
     * One deliberate provider test using the real configured template and the
     * same durable issuance budgets. Its generated code has no auth authority.
     * Exact request replay is safe within the challenge retention horizon.
     */
    public static function testSend(string $mobile, string $purpose, string $requestId, bool $confirmed, ?string $provider = null, ?string $revision = null): array
    {
        Settings::requireOperator();
        if (!Lifecycle::ready()) {
            throw new SettingsError('schema','storage_unavailable');
        }
        if (!$confirmed) {
            throw new SettingsError('confirm_send', 'confirmation_required');
        }
        if (!preg_match('/\A[0-9a-f]{32}\z/D', $requestId) || !in_array($purpose, ['login','link','step_up','password_reset'], true)) {
            throw new SettingsError('request_id');
        }
        try {
            $mobile = Mobile::normalize($mobile);
        } catch (\InvalidArgumentException $error) {
            throw new SettingsError('mobile');
        }
        $settings = self::snapshot($revision);
        $sender = Settings::sender($provider,$settings);
        $actor = get_current_user_id();
        $id = substr(hash_hmac('sha256', "ach-accounts-test-id\0$actor\0$requestId", wp_salt('auth')), 0, 32);
        $flow = hash_hmac('sha256', "ach-accounts-test-flow\0$actor\0$requestId\0" . $sender->id(), wp_salt('auth'));
        $service = new Verification(Settings::policy($settings));
        // CLI/admin jobs without a network request share the local budget.
        $network = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $service->issue($id, $mobile, 'test_' . $purpose, $flow, $network, $sender);
        // The receipt is authoritative even if the best-effort event copy failed.
        return Diagnostics::testReceipt($id);
    }
}
