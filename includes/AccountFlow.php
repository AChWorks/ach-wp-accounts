<?php
namespace ACh\WPAccounts;

defined('ABSPATH') || exit;

/**
 * Signed, browser-bound, short-lived flow tickets. A ticket is not OTP proof.
 * Each ticket has one request identity and binds both the current account
 * session and the exact phone identity revision observed before issuance.
 */
final class AccountFlow
{
    public function __construct(private readonly Verification $verification = new Verification())
    {
    }

    private static function actor(): string
    {
        $id = get_current_user_id();
        if ($id === 0) {
            return 'guest';
        }
        $token = SessionProof::currentToken($id);
        if ($token === '') {
            throw new VerificationError('invalid_context');
        }
        return hash_hmac('sha256', "ach-wpa-actor\0$id\0$token", wp_salt('auth'));
    }

    private static function sign(string $payload, #[\SensitiveParameter] string $browser): string
    {
        if (!preg_match('/\A[0-9a-f]{64}\z/D', $browser)) {
            throw new VerificationError('invalid_context');
        }
        return hash_hmac('sha256', "ach-wpa-ticket\0$browser\0$payload", wp_salt('auth'));
    }

    private static function encode(array $data): string
    {
        return rtrim(strtr(base64_encode(json_encode($data, JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
    }

    public function start(
        #[\SensitiveParameter] string $browser,
        string $mobile,
        string $purpose,
        string $context,
        #[\SensitiveParameter] string $password = ''
    ): string {
        $mobile = Mobile::normalize($mobile);
        if (!in_array($context, ['wp','account','checkout'], true)) {
            throw new VerificationError('invalid_context');
        }
        $userId = get_current_user_id();
        $actor = self::actor();
        $own = Identity::byUser($userId);
        $target = Identity::byMobile($mobile);
        if ($purpose === 'login') {
            if ($userId !== 0) {
                throw new VerificationError('invalid_context');
            }
        } elseif ($purpose === 'step_up') {
            if (!$own || $own->mobile !== $mobile) {
                throw new VerificationError('invalid_context');
            }
        } elseif ($purpose === 'link') {
            if ($userId < 1) {
                throw new VerificationError('invalid_context');
            }
            if (!SessionProof::qualifies(SessionProof::LINK_FRESHNESS)) {
                AccountPolicy::reauthenticate($userId, $password);
            }
        } else {
            throw new VerificationError('invalid_context');
        }
        // Do not expose login identity existence or the stored revision to the
        // browser. Only hashes of snapshot values are included in this ticket.
        $id = bin2hex(random_bytes(16));
        $data = [
            'id' => $id,
            'mobile' => $mobile,
            'purpose' => $purpose,
            'context' => $context,
            'actor' => $actor,
            'own' => self::snapshot($own, $id, 'own'),
            'target' => self::snapshot($target, $id, 'target'),
            'expires' => time() + 600 + $this->verification->codeLifetime(),
        ];
        self::checkSnapshots($data);
        $payload = self::encode($data);
        return $payload . '.' . self::sign($payload, $browser);
    }

    private static function snapshot(?object $identity, string $id, string $kind): string
    {
        return hash_hmac('sha256', "ach-wpa-binding\0$id\0$kind\0" . Identity::revision($identity), wp_salt('auth'));
    }

    private static function checkSnapshots(array $data): void
    {
        $own = Identity::byUser(get_current_user_id());
        $target = Identity::byMobile($data['mobile']);
        if (!hash_equals($data['own'], self::snapshot($own, $data['id'], 'own')) ||
            !hash_equals($data['target'], self::snapshot($target, $data['id'], 'target'))) {
            throw new VerificationError('identity_changed');
        }
    }

    private static function open(#[\SensitiveParameter] string $browser, #[\SensitiveParameter] string $ticket): array
    {
        if (strlen($ticket) > 2048 || !preg_match('/\A([A-Za-z0-9_-]+)\.([0-9a-f]{64})\z/D', $ticket, $parts) ||
            !hash_equals(self::sign($parts[1], $browser), $parts[2])) {
            throw new VerificationError('invalid_context');
        }
        $json = base64_decode(strtr($parts[1], '-_', '+/'), true);
        $data = $json !== false ? json_decode($json, true, 16) : null;
        if (!is_array($data) || ($data['expires'] ?? 0) <= time() ||
            !hash_equals($data['actor'], self::actor())) {
            throw new VerificationError('invalid_context');
        }
        self::checkSnapshots($data);
        return $data;
    }

    private static function challengeFlow(#[\SensitiveParameter] string $ticket, #[\SensitiveParameter] string $browser): string
    {
        return hash_hmac('sha256', "ach-wpa-flow\0$browser\0$ticket", wp_salt('auth'));
    }

    public function issue(#[\SensitiveParameter] string $browser, #[\SensitiveParameter] string $ticket, string $network, SmsSender $sender): array
    {
        $data = self::open($browser, $ticket);
        if ($data['expires'] - time() < $this->verification->codeLifetime()) {
            throw new VerificationError('invalid_context');
        }
        return $this->verification->issue(
            $data['id'], $data['mobile'], $data['purpose'],
            self::challengeFlow($ticket, $browser), $network, $sender
        );
    }

    public function complete(#[\SensitiveParameter] string $browser, #[\SensitiveParameter] string $ticket, #[\SensitiveParameter] string $code): array
    {
        $data = self::open($browser, $ticket);
        $flow = self::challengeFlow($ticket, $browser);
        $proof = $this->verification->verify($data['id'], $data['purpose'], $flow, $code);
        $mobile = $this->verification->consume($data['id'], $data['purpose'], $flow, $proof);
        if ($mobile !== $data['mobile'] || !hash_equals($data['actor'], self::actor())) {
            throw new VerificationError('invalid_context');
        }
        self::checkSnapshots($data);
        if ($data['purpose'] === 'login') {
            $identity = Identity::byMobile($mobile);
            if (!hash_equals($data['target'], self::snapshot($identity, $data['id'], 'target'))) {
                throw new VerificationError('identity_changed');
            }
            if ($identity && !$identity->user_id) {
                throw new VerificationError('registration_pending');
            }
            $created = $identity === null;
            $identity = $identity ?? Accounts::register($mobile, $data['context']);
            Accounts::login($identity);
            return ['outcome' => 'authenticated', 'created' => $created];
        }

        $userId = get_current_user_id();
        $token = SessionProof::currentToken($userId);
        if ($token === '') {
            throw new VerificationError('invalid_context');
        }
        $identity = Identity::byUser($userId);
        if (!hash_equals($data['own'], self::snapshot($identity, $data['id'], 'own'))) {
            throw new VerificationError('identity_changed');
        }
        if ($data['purpose'] === 'link') {
            $target = Identity::byMobile($mobile);
            if (!hash_equals($data['target'], self::snapshot($target, $data['id'], 'target')) ||
                ($target && (int) $target->user_id !== $userId)) {
                throw new VerificationError('identity_conflict');
            }
            $identity = Identity::bind($userId, $mobile, Identity::revision($identity));
        } elseif (!$identity || $identity->mobile !== $mobile) {
            throw new VerificationError('identity_changed');
        }
        SessionProof::record($userId, $token, $identity);
        return ['outcome' => $data['purpose'] === 'link' ? 'linked' : 'verified', 'created' => false];
    }
}
