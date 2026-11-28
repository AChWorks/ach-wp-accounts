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
        return hash_hmac('sha256', "ach-wpa-ticket\0" . Lifecycle::epoch() . "\0$browser\0$payload", wp_salt('auth'));
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
        #[\SensitiveParameter] string $password = '',
        string $login = '',
        string $contactEmail = ''
    ): string {
        $mobile = Mobile::normalize($mobile);
        if (!in_array($context, ['wp','account','checkout'], true)) {
            throw new VerificationError('invalid_context');
        }
        $userId = get_current_user_id();
        $actor = self::actor();
        $own = Identity::byUser($userId);
        $target = Identity::byMobile($mobile);
        $recovery = null;
        if ($purpose === 'recover') {
            $recovery = AccountPolicy::recoveryUser($login, $password);
            // First linking only. Do not authorize ownership by billing data
            // or silently replace an established identity.
            if ($target !== null) { throw new VerificationError('identity_conflict'); }
            $purpose = 'link';
        } elseif ($purpose === 'password_reset') {
            if ($userId!==0 || $context==='checkout' || !Settings::flag('allow_mobile_password_reset')) {
                throw new VerificationError('method_disabled');
            }
        } elseif ($purpose === 'login') {
            if ($userId !== 0) {
                throw new VerificationError('invalid_context');
            }
        } elseif ($purpose === 'step_up') {
            if (!$own || $own->mobile !== $mobile) {
                throw new VerificationError('invalid_context');
            }
        } elseif ($purpose === 'link') {
            if ($userId < 1 || ($context === 'checkout' && $own !== null)) {
                throw new VerificationError('invalid_context');
            }
            if ($password !== '' || !AccountPolicy::canLinkWithoutPassword($userId, $own)) {
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
            'recovery_user' => $recovery?->ID ?? 0,
            'recovery_credential' => $recovery ? self::recoveryCredential($recovery) : '',
            'recovery_email_identifier' => $recovery && is_email($login) && strcasecmp($recovery->user_login,$login)!==0,
            'reset_authority' => $purpose==='password_reset' ? self::resetSnapshot($target,$id) : '',
            // Only a contact supplied by this browser; never account proof.
            'contact_email' => $context === 'checkout' && $purpose === 'login' ? sanitize_email($contactEmail) : '',
        ];
        self::checkSnapshots($data);
        $payload = self::encode($data);
        return $payload . '.' . self::sign($payload, $browser);
    }

    private static function snapshot(?object $identity, string $id, string $kind): string
    {
        return hash_hmac('sha256', "ach-wpa-binding\0$id\0$kind\0" . Identity::revision($identity), wp_salt('auth'));
    }

    private static function recoveryCredential(\WP_User $user): string
    {
        return hash_hmac('sha256', "ach-wpa-recovery\0".$user->ID."\0".$user->user_pass, wp_salt('auth'));
    }

    private static function checkRecovery(array $data): ?\WP_User
    {
        $id = (int)($data['recovery_user'] ?? 0);
        if (!$id) { return null; }
        $user = get_userdata($id);
        if (!Settings::flag('allow_first_link') || (!empty($data['recovery_email_identifier']) && !Settings::flag('allow_email_login')) ||
            get_current_user_id() !== 0 || $data['purpose'] !== 'link' || !$user instanceof \WP_User ||
            !AccountPolicy::ordinary($user) || Identity::byUser($id) ||
            !hash_equals($data['recovery_credential'], self::recoveryCredential($user))) {
            throw new VerificationError('identity_changed');
        }
        return $user;
    }

    private static function resetUser(?object $identity): ?\WP_User
    {
        if (!$identity || (int)$identity->verified_at<=0 || !(int)$identity->user_id) { return null; }
        $user = get_userdata((int)$identity->user_id);
        return $user instanceof \WP_User && AccountPolicy::ordinary($user) &&
            NativeAccess::mobileResetAllowed($user) ? $user : null;
    }

    /** Same opaque ticket shape whether or not the supplied phone is eligible. */
    private static function resetSnapshot(?object $identity, string $id): string
    {
        $user = self::resetUser($identity);
        return hash_hmac('sha256', "ach-wpa-password-reset\0".$id."\0".
            ($user ? self::recoveryCredential($user) : 'unavailable'), wp_salt('auth'));
    }

    private static function checkSnapshots(array $data): void
    {
        self::checkRecovery($data);
        $own = Identity::byUser(get_current_user_id());
        $target = Identity::byMobile($data['mobile']);
        if ($data['purpose']==='password_reset' &&
            (!Settings::flag('allow_mobile_password_reset') || !hash_equals($data['reset_authority']??'',self::resetSnapshot($target,$data['id'])))) {
            throw new VerificationError('identity_changed');
        }
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

    public function issue(#[\SensitiveParameter] string $browser, #[\SensitiveParameter] string $ticket, string $network, MobileCodeDelivery $sender): array
    {
        $data = self::open($browser, $ticket);
        if ($data['expires'] - time() < $this->verification->codeLifetime()) {
            throw new VerificationError('invalid_context');
        }
        // Capture the validated target binding for privacy attribution. A later
        // owner of the number must never inherit the previous binding's history.
        $target = Identity::byMobile($data['mobile']);
        if (!hash_equals($data['target'], self::snapshot($target, $data['id'], 'target'))) {
            throw new VerificationError('identity_changed');
        }
        $revision = $target && (int)$target->user_id > 0 &&
            ($data['purpose'] !== 'link' || (int)$target->user_id === get_current_user_id()) ? $target->revision : '';
        return $this->verification->issue(
            $data['id'], $data['mobile'], $data['purpose'],
            self::challengeFlow($ticket, $browser), $network, $sender, $revision
        );
    }

    public function complete(#[\SensitiveParameter] string $browser, #[\SensitiveParameter] string $ticket, #[\SensitiveParameter] string $code): array
    {
        $data = self::open($browser, $ticket);
        $flow = self::challengeFlow($ticket, $browser);
        $proof = $this->verification->verify($data['id'], $data['purpose'], $flow, $code);
        if ($data['purpose']==='password_reset') {
            $user = self::resetUser(Identity::byMobile($data['mobile']));
            self::checkSnapshots($data);
            if (!$user) { throw new VerificationError('reset_unavailable'); }
            return ['outcome'=>'password_required','proof'=>$proof];
        }
        if ($data['purpose']==='login' && !Identity::byMobile($data['mobile']) && Capabilities::registrationFields()) {
            self::checkSnapshots($data);
            self::checkNewRegistration($data);
            return ['outcome'=>'profile_required','proof'=>$proof,'fields'=>Capabilities::registrationFields()];
        }
        return $this->finish($data,$flow,$proof);
    }

    private static function checkNewRegistration(array $data): void
    {
        if (!AccountPolicy::registrationAllowed($data['context'])) {
            throw new VerificationError('registration_disabled');
        }
        if (($data['contact_email']??'')!=='' && email_exists($data['contact_email'])) {
            throw new VerificationError('existing_account_required');
        }
    }

    public function continueRegistration(#[\SensitiveParameter] string $browser, #[\SensitiveParameter] string $ticket,
        #[\SensitiveParameter] string $proof,array $names): array
    {
        $data = self::open($browser,$ticket);
        if ($data['purpose']!=='login' || Identity::byMobile($data['mobile'])) {
            throw new VerificationError('invalid_context');
        }
        self::checkNewRegistration($data);
        return $this->finish($data,self::challengeFlow($ticket,$browser),$proof,Capabilities::validateNames($names));
    }

    public function resetPassword(#[\SensitiveParameter] string $browser, #[\SensitiveParameter] string $ticket,
        #[\SensitiveParameter] string $proof, #[\SensitiveParameter] string $password,
        #[\SensitiveParameter] string $confirmation): array
    {
        $data = self::open($browser,$ticket);
        if ($data['purpose']!=='password_reset') { throw new VerificationError('invalid_context'); }
        if (preg_match_all('/./us',$password)<15 || strlen($password)>4096 || trim($password)!==$password ||
            $password!==$confirmation) { throw new VerificationError('invalid_password'); }
        $user = self::resetUser(Identity::byMobile($data['mobile']));
        if (!$user) { throw new VerificationError('reset_unavailable'); }
        return DatabaseLock::run('mobile-reset-'.$user->ID,function () use ($data,$browser,$ticket,$proof,$password,$user): array {
            self::checkSnapshots($data);
            $fresh = self::resetUser(Identity::byMobile($data['mobile']));
            if (!$fresh || $fresh->ID!==$user->ID) { throw new VerificationError('reset_unavailable'); }
            $errors = new \WP_Error();
            do_action('validate_password_reset',$errors,$fresh);
            if ($errors->has_errors()) { throw new VerificationError('invalid_password'); }
            $mobile = $this->verification->consume($data['id'],$data['purpose'],self::challengeFlow($ticket,$browser),$proof);
            if ($mobile!==$data['mobile']) { throw new VerificationError('invalid_context'); }
            self::checkSnapshots($data);
            // Native reset owns credential mutation and notifications. It is
            // deliberately not a sign-in; invalidate existing native sessions.
            if ($data['context']==='account' && class_exists('WC_Shortcode_My_Account')) {
                \WC_Shortcode_My_Account::reset_password($fresh,$password);
                do_action('woocommerce_customer_reset_password',$fresh);
            } else { reset_password($fresh,$password); }
            \WP_Session_Tokens::get_instance($fresh->ID)->destroy_all();
            $updated = get_userdata($fresh->ID);
            if (!$updated || !wp_check_password($password,$updated->user_pass,$updated->ID)) {
                throw new VerificationError('password_update_failed');
            }
            return ['outcome'=>'password_reset'];
        },'reset_unavailable');
    }

    private function finish(array $data,string $flow,#[\SensitiveParameter] string $proof,array $names=[]): array
    {
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
            if ($created && ($data['contact_email'] ?? '') !== '' && email_exists($data['contact_email'])) {
                // An existing email is not sufficient authority to link an
                // account. Offer the explicit credential + OTP recovery path.
                throw new VerificationError('existing_account_required');
            }
            $identity = $identity ?? Accounts::register($mobile, $data['context'], $names);
            Accounts::login($identity);
            return ['outcome' => 'authenticated', 'created' => $created];
        }

        $recovery = self::checkRecovery($data);
        if ($recovery) {
            $identity = Identity::bind($recovery->ID, $mobile, 'absent');
            Accounts::login($identity);
            return ['outcome' => 'authenticated', 'created' => false];
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
