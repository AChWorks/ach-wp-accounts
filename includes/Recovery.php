<?php
namespace ACh\WPAccounts;

defined('ABSPATH') || exit;

/**
 * Recovery credentials for phone-created accounts only. Native email accounts
 * keep their existing WordPress/WooCommerce registration and profile policy.
 * Never store an unconfirmed address in wp_users.user_email, even temporarily.
 */
final class EmailRecovery
{
    private const MARKER = '_ach_accounts_registration';
    private const VERIFIED = '_ach_accounts_verified_email';
    public const QUERY = 'ach_confirm_email';
    private static ?object $confirming = null;

    public static function managed(int $userId): bool
    {
        return $userId > 0 && (bool) get_user_meta($userId, self::MARKER, true);
    }

    private static function fingerprint(string $email): string
    {
        return hash_hmac('sha256', strtolower(trim($email)), wp_salt('auth'));
    }

    public static function allow(mixed $allow, int $userId): mixed
    {
        if ($allow !== true || !self::managed($userId)) {
            return $allow;
        }
        $user = get_userdata($userId);
        $saved = get_user_meta($userId, self::VERIFIED, true);
        return $user && is_email($user->user_email) && is_string($saved) &&
            hash_equals($saved, self::fingerprint($user->user_email));
    }

    private static function requireAuthority(): \WP_User
    {
        $id = get_current_user_id();
        $user = get_userdata($id);
        if (!self::managed($id) || !$user instanceof \WP_User || !AccountPolicy::ordinary($user) ||
            !SessionProof::qualifies(SessionProof::LINK_FRESHNESS)) {
            throw new VerificationError('reauthentication_required');
        }
        return $user;
    }

    /** Request a native-account email confirmation, never a login token. */
    public static function request(string $email): array
    {
        $user = self::requireAuthority();
        $email = trim($email);
        // Match the actual native users.user_email storage width.
        if (!is_email($email) || sanitize_email($email) !== $email || strlen($email)>100) {
            throw new VerificationError('invalid_email');
        }
        $owner = email_exists($email);
        if ($owner && (int)$owner !== $user->ID) {
            throw new VerificationError('email_unavailable');
        }
        if ($user->user_email === $email && self::allow(true,$user->ID)) {
            return ['outcome'=>'already_confirmed'];
        }
        AccountPolicy::reserveAccountAction('recovery_email',$user->ID,5);
        $token = bin2hex(random_bytes(32));
        DatabaseLock::run('recovery-email-'.$user->ID,static function () use ($user,$email,$token): void {
            global $wpdb;
            $current = self::requireAuthority();
            $identity = Identity::byUser($current->ID);
            if ($current->ID !== $user->ID || !$identity || !Lifecycle::ready()) {
                throw new VerificationError('invalid_context');
            }
            $table = Schema::table('recovery_emails');
            $old = $wpdb->suppress_errors(true);
            try {
                $result = $wpdb->query($wpdb->prepare(
                    "INSERT INTO $table (user_id,token_hash,new_email,previous_email,identity_revision,epoch,retire_at)
                     VALUES (%d,%s,%s,%s,%s,%s,%d)
                     ON DUPLICATE KEY UPDATE token_hash=VALUES(token_hash),new_email=VALUES(new_email),
                     previous_email=VALUES(previous_email),identity_revision=VALUES(identity_revision),
                     epoch=VALUES(epoch),retire_at=VALUES(retire_at)",
                    $current->ID,hash('sha256',$token),$email,$current->user_email,$identity->revision,
                    Lifecycle::epoch(),time()+DAY_IN_SECONDS
                ));
                if ($result === false) {
                    throw new VerificationError('storage_unavailable');
                }
            } finally { $wpdb->suppress_errors($old); }
        });
        $link = add_query_arg(self::QUERY,$token,admin_url('profile.php','https'));
        $name = wp_specialchars_decode(get_option('blogname'), ENT_QUOTES);
        $subject = sprintf(__('Confirm your recovery email for %s', 'ach-wp-accounts'), $name);
        $message = sprintf(
            /* translators: %s: email confirmation link. */
            __("To confirm this email address for your account, open the link below while signed in. The link expires in 24 hours.\n\n%s\n\nIf you did not request this change, ignore this message.", 'ach-wp-accounts'),
            $link
        );
        if (!wp_mail($email,$subject,$message)) {
            // A transport failure may still mean delivery. Never resend blindly.
            throw new VerificationError('email_send_failed');
        }
        return ['outcome'=>'confirmation_requested'];
    }

    /** Verify the actual signed-in account, binding, epoch and one-use token. */
    public static function confirm(#[\SensitiveParameter] string $token): void
    {
        $id = get_current_user_id();
        if (!Lifecycle::ready() || !self::managed($id) || SessionProof::currentToken($id)==='' ||
            !preg_match('/\\A[0-9a-f]{64}\\z/D',$token)) {
            throw new VerificationError('invalid_context');
        }
        DatabaseLock::run('recovery-email-'.$id,static function () use ($id,$token): void {
            global $wpdb;
            $table = Schema::table('recovery_emails');
            $old = $wpdb->suppress_errors(true);
            try {
                $record = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE user_id=%d",$id));
                $identity = Identity::byUser($id);
                $user = get_userdata($id);
                if (!$record || !$identity || !$user || !AccountPolicy::ordinary($user) ||
                    (int)$record->retire_at<=time() || $record->epoch!==Lifecycle::epoch() ||
                    !hash_equals($record->token_hash,hash('sha256',$token)) ||
                    $record->identity_revision!==$identity->revision || $record->previous_email!==$user->user_email ||
                    !is_email($record->new_email) || SessionProof::currentToken($id)==='') {
                    throw new VerificationError('invalid_context');
                }
                $owner = email_exists($record->new_email);
                if ($owner && (int)$owner!==$id) {
                    throw new VerificationError('email_unavailable');
                }
                // Atomically consume BEFORE the native update. If the outcome
                // is uncertain, a new deliberate enrollment is required.
                if ($wpdb->query($wpdb->prepare("DELETE FROM $table WHERE user_id=%d AND token_hash=%s AND retire_at>%d",
                    $id,$record->token_hash,time()))!==1) {
                    throw new VerificationError('invalid_context');
                }
                self::$confirming = $record;
                try {
                    $result = wp_update_user(['ID'=>$id,'user_email'=>$record->new_email]);
                    $fresh = get_userdata($id);
                    if (is_wp_error($result) || (int)$result!==$id || !$fresh || $fresh->user_email!==$record->new_email) {
                        throw new VerificationError('storage_unavailable');
                    }
                    update_user_meta($id,self::VERIFIED,self::fingerprint($fresh->user_email));
                    if (!self::allow(true,$id)) {
                        throw new VerificationError('storage_unavailable');
                    }
                } finally { self::$confirming = null; }
            } finally { $wpdb->suppress_errors($old); }
        });
    }

    /** Keep the existing native profile link/redirect UX, not core pending state. */
    public static function handleConfirmation(): void
    {
        global $pagenow;
        $id = get_current_user_id();
        if (!self::managed($id)) { return; }
        // Core's native email confirmation has no plugin proof/expiry contract.
        remove_action('personal_options_update','send_confirmation_on_profile_email');
        if ($pagenow!=='profile.php' || (!isset($_GET[self::QUERY]) && !isset($_GET['newuseremail']))) {
            return;
        }
        nocache_headers();
        if (!headers_sent()) {
            header('Referrer-Policy: no-referrer',true);
        }
        try {
            $token = $_GET[self::QUERY] ?? null;
            if (!is_string($token) || isset($_GET['newuseremail']) || ($_SERVER['REQUEST_METHOD'] ?? '')!=='GET' || !is_ssl()) {
                throw new VerificationError('invalid_context');
            }
            self::confirm(wp_unslash($token));
            wp_safe_redirect(add_query_arg('updated','true',admin_url('profile.php','https')));
            exit;
        } catch (VerificationError $error) {
            if ($error->getMessage()==='storage_unavailable') {
                Diagnostics::accountFailure('storage_unavailable','recovery_email');
                wp_die(esc_html__('Account data is temporarily unavailable.','ach-wp-accounts'),'', ['response'=>503,'back_link'=>true]);
            }
            wp_die(esc_html__('This email confirmation link is invalid or expired. Return to your account and request a new link.', 'ach-wp-accounts'), '', ['response'=>400,'back_link'=>true]);
        } catch (\Throwable $error) {
            Diagnostics::accountFailure('storage_unavailable','recovery_email');
            wp_die(esc_html__('Account data is temporarily unavailable.','ach-wp-accounts'),'', ['response'=>503,'back_link'=>true]);
        }
    }

    /** Prevent other native profile writes from creating a core pending record. */
    public static function guardNativePending(mixed $check,int $userId,string $key): mixed
    {
        return $key==='_new_email' && self::managed($userId) ? false : $check;
    }

    /**
     * Native account email is a password recovery credential. Block all
     * unconfirmed writes for managed accounts at the native storage boundary.
     * wp_insert_user treats an empty data array as a WP_Error.
     */
    public static function guardEmail(array $data, bool $update, ?int $userId, array $userdata): array
    {
        if (!$update) {
            return isset($userdata['meta_input'][self::MARKER]) && ($data['user_email'] ?? '') !== '' ? [] : $data;
        }
        if (!$userId || !self::managed($userId)) {
            return $data;
        }
        $old = get_userdata($userId);
        $email = $data['user_email'] ?? '';
        if (!$old) {
            return [];
        }
        if ($email === $old->user_email || $email === '') {
            return $data;
        }
        $pending = self::$confirming;
        $identity = $pending ? Identity::byUser($userId) : null;
        return $pending && (int)$pending->user_id===$userId && $identity &&
            $pending->identity_revision===$identity->revision && $pending->epoch===Lifecycle::epoch() &&
            $pending->previous_email===$old->user_email && $email===$pending->new_email ? $data : [];
    }

    public static function profileUpdated(int $userId, \WP_User $old): void
    {
        $user = get_userdata($userId);
        if (!$user || !self::managed($userId) || $old->user_email === $user->user_email) {
            return;
        }
        delete_user_meta($userId, self::VERIFIED);
        // confirm() records the fingerprint only after the native write succeeds.
    }

    public static function optionalEmail(array $fields): array
    {
        if (self::managed(get_current_user_id())) {
            unset($fields['account_email']);
        }
        return $fields;
    }

    public static function validateCommerceProfile(\WP_Error $errors, object $user): void
    {
        $old = get_userdata((int)$user->ID);
        if ($old && self::managed($old->ID) && isset($user->user_email) &&
            $user->user_email !== $old->user_email) {
            $errors->add('ach_accounts_confirm_email', __('Use recovery options to add or change your account email.', 'ach-wp-accounts'));
        }
    }

    /** Set an optional native password with fresh possession proof. */
    public static function password(#[\SensitiveParameter] string $password, #[\SensitiveParameter] string $confirmation): array
    {
        $user = self::requireAuthority();
        // The optional password is a single-factor credential: use a meaningful
        // length floor without composition restrictions (native login stays intact).
        if (preg_match_all('/./us', $password) < 15 || $password !== $confirmation ||
            strlen($password) > 4096 || trim($password) !== $password) {
            throw new VerificationError('invalid_password');
        }
        $errors = new \WP_Error();
        do_action('validate_password_reset', $errors, $user);
        if ($errors->has_errors()) {
            throw new VerificationError('invalid_password');
        }
        $identity = Identity::byUser($user->ID);
        $token = SessionProof::currentToken($user->ID);
        if (!$identity || $token === '') {
            throw new VerificationError('invalid_context');
        }
        // Native wp_update_user refreshes the current auth cookie and clears
        // the previous proof as part of wp_clear_auth_cookie.
        $result = wp_update_user(['ID' => $user->ID, 'user_pass' => $password]);
        if (is_wp_error($result) || (int)$result !== $user->ID ||
            !wp_check_password($password, get_userdata($user->ID)->user_pass, $user->ID)) {
            throw new VerificationError('password_update_failed');
        }
        try {
            SessionProof::record($user->ID, $token, $identity);
        } catch (VerificationError $error) {
            // The native password is already saved. Do not misreport the
            // completed credential change as failed or repeat it automatically.
            return ['outcome' => 'password_saved', 'session_qualified' => false];
        }
        return ['outcome' => 'password_saved', 'session_qualified' => true];
    }

    public static function hooks(): void
    {
        add_filter('allow_password_reset', [self::class, 'allow'], PHP_INT_MAX, 2);
        add_filter('wp_pre_insert_user_data', [self::class, 'guardEmail'], PHP_INT_MAX, 4);
        add_action('profile_update', [self::class, 'profileUpdated'], 10, 2);
        add_action('admin_init',[self::class,'handleConfirmation'],0);
        add_filter('add_user_metadata',[self::class,'guardNativePending'],PHP_INT_MAX,3);
        add_filter('update_user_metadata',[self::class,'guardNativePending'],PHP_INT_MAX,3);
        add_filter('woocommerce_save_account_details_required_fields', [self::class, 'optionalEmail']);
        add_action('woocommerce_save_account_details_errors', [self::class, 'validateCommerceProfile'], 10, 2);
    }
}
