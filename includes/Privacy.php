<?php
namespace ACh\WPAccounts;

defined('ABSPATH') || exit;

/** Native privacy hooks; never delete native users, orders or recovery credentials. */
final class Privacy
{
    public static function hooks(): void
    {
        add_filter('wp_privacy_personal_data_exporters',static function (array $exporters): array {
            $exporters['ach-wp-accounts'] = [
                'exporter_friendly_name'=>'ACh WP Accounts',
                'callback'=>[self::class,'exportEmail'],
            ];
            return $exporters;
        });
        add_filter('wp_privacy_personal_data_erasers',static function (array $erasers): array {
            $erasers['ach-wp-accounts'] = [
                'eraser_friendly_name'=>'ACh WP Accounts',
                'callback'=>[self::class,'eraseEmail'],
            ];
            return $erasers;
        });
    }

    public static function exportEmail(string $email,int $page=1): array|\WP_Error
    {
        $user = get_user_by('email',$email);
        if (!$user) { return ['data'=>[],'done'=>true]; }
        try {
            return self::exportUser($user->ID,$page);
        } catch (VerificationError $error) {
            return new \WP_Error('ach_accounts_storage_unavailable',__('Account data is temporarily unavailable.','ach-wp-accounts'));
        }
    }

    /** Internal; the WP privacy workflow or protected local CLI authorizes it. */
    public static function exportUser(int $id,int $page=1): array
    {
        if (!Lifecycle::ready() || $page<1) { throw new VerificationError('storage_unavailable'); }
        global $wpdb;
        $identity = Identity::byUser($id);
        $data = [];
        if ($identity) {
            $data[] = ['name'=>((int)$identity->verified_at>0 ? __('Verified mobile number','ach-wp-accounts') : __('Mobile number','ach-wp-accounts')),'value'=>$identity->mobile];
            $data[] = ['name'=>__('Identity source','ach-wp-accounts'),'value'=>$identity->source];
            if ((int)$identity->verified_at>0) {
                $data[] = ['name'=>__('Verification time (UTC)','ach-wp-accounts'),'value'=>gmdate('c',(int)$identity->verified_at)];
            }
        }
        $old = $wpdb->suppress_errors(true);
        try {
            $pending = $wpdb->get_row($wpdb->prepare("SELECT new_email,retire_at FROM ".Schema::table('recovery_emails')." WHERE user_id=%d",$id));
            if ($wpdb->last_error!=='') { throw new VerificationError('storage_unavailable'); }
            if ($pending) {
                $data[] = ['name'=>__('Pending recovery email','ach-wp-accounts'),'value'=>$pending->new_email];
                $data[] = ['name'=>__('Pending email expiry (UTC)','ach-wp-accounts'),'value'=>gmdate('c',(int)$pending->retire_at)];
            }
            $sessions = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM ".Schema::table('session_proofs')." WHERE user_id=%d",$id));
            if ($wpdb->last_error!=='') { throw new VerificationError('storage_unavailable'); }
            if ((int)$sessions>0) {
                $data[] = ['name'=>__('Stored mobile session proofs','ach-wp-accounts'),'value'=>(string)$sessions];
            }
        } finally { $wpdb->suppress_errors($old); }
        if (EmailRecovery::registeredHere($id)) {
            $data[] = ['name'=>__('Mobile-created account','ach-wp-accounts'),'value'=>__('Yes','ach-wp-accounts')];
        }
        $items = $page===1 && $data ? [['group_id'=>'ach-wp-accounts','group_label'=>'ACh WP Accounts',
            'item_id'=>'ach-wp-accounts-'.$id,'data'=>$data]] : [];
        $offset = ($page-1)*100;
        $old = $wpdb->suppress_errors(true);
        try {
            $sessionRows = $wpdb->get_results($wpdb->prepare(
                "SELECT verified_at,retire_at FROM ".Schema::table('session_proofs')." WHERE user_id=%d ORDER BY token_hash LIMIT 100 OFFSET %d",$id,$offset));
            if ($wpdb->last_error!=='') { throw new VerificationError('storage_unavailable'); }
            $challengeRows = [];
            if ($identity) {
                // Match the recorded issue-time binding revision, not merely
                // the number, so reassignment cannot transfer history.
                $challengeRows = $wpdb->get_results($wpdb->prepare(
                    "SELECT c.id,c.purpose,c.provider,c.send_state,c.provider_code,c.provider_reference,c.attempts,c.created_at,c.retire_at
                     FROM ".Schema::table('challenges')." c INNER JOIN ".Schema::table('identities')." b ON b.mobile=c.mobile AND b.revision=c.identity_revision
                     WHERE b.user_id=%d AND b.revision=%s ORDER BY c.created_at,c.id LIMIT 100 OFFSET %d",$id,$identity->revision,$offset));
                if ($wpdb->last_error!=='') { throw new VerificationError('storage_unavailable'); }
            }
        } finally { $wpdb->suppress_errors($old); }
        foreach ($sessionRows as $i=>$row) {
            $items[] = ['group_id'=>'ach-wp-accounts-sessions','group_label'=>__('Mobile session verification','ach-wp-accounts'),
                'item_id'=>'ach-wp-accounts-session-'.$id.'-'.($offset+$i),'data'=>[
                    ['name'=>__('Verification time (UTC)','ach-wp-accounts'),'value'=>gmdate('c',(int)$row->verified_at)],
                    ['name'=>__('Expiry (UTC)','ach-wp-accounts'),'value'=>gmdate('c',(int)$row->retire_at)],
                ]];
        }
        foreach ($challengeRows as $row) {
            $items[] = ['group_id'=>'ach-wp-accounts-challenges','group_label'=>__('Mobile verification requests','ach-wp-accounts'),
                'item_id'=>'ach-wp-accounts-challenge-'.$row->id,'data'=>[
                    ['name'=>__('Time','ach-wp-accounts'),'value'=>gmdate('c',(int)$row->created_at)],
                    ['name'=>__('Expiry (UTC)','ach-wp-accounts'),'value'=>gmdate('c',(int)$row->retire_at)],
                    ['name'=>__('Operation','ach-wp-accounts'),'value'=>$row->purpose],
                    ['name'=>__('Provider','ach-wp-accounts'),'value'=>$row->provider],
                    ['name'=>__('Result','ach-wp-accounts'),'value'=>$row->send_state],
                    ['name'=>__('Provider code','ach-wp-accounts'),'value'=>$row->provider_code],
                    ['name'=>__('Message reference','ach-wp-accounts'),'value'=>$row->provider_reference],
                    ['name'=>__('Attempts:','ach-wp-accounts'),'value'=>(string)$row->attempts],
                ]];
        }
        return ['data'=>$items,'done'=>count($sessionRows)<100 && count($challengeRows)<100];
    }

    public static function eraseEmail(string $email,int $page=1): array
    {
        $user = get_user_by('email',$email);
        return !$user || $page!==1 ? self::result(false,false) : self::eraseUser($user->ID);
    }

    private static function result(bool $removed,bool $retained,array $messages=[]): array
    {
        return ['items_removed'=>$removed,'items_retained'=>$retained,'messages'=>$messages,'done'=>true];
    }

    /** No hidden account lockout: keep an identity when native recovery isn't established. */
    public static function eraseUser(int $id): array
    {
        $user = get_userdata($id);
        if (!$user) { return self::result(false,false); }
        if (!Lifecycle::ready()) {
            return self::result(false,true,[__('Account data is temporarily unavailable.','ach-wp-accounts')]);
        }
        try {
            $identity = Identity::byUser($id);
            $alternative = is_email($user->user_email) && wp_is_password_reset_allowed_for_user($id)===true;
            $removed = self::clearTemporary($id,$identity);
            if ($identity && !$alternative) {
                return self::result($removed,true,[__('The mobile identity was retained because removing it could prevent account recovery. Arrange another native recovery method or delete the account explicitly.','ach-wp-accounts')]);
            }
            if ($identity) {
                self::removeIdentity($id,$identity);
                $removed = true;
            }
            // Native email/password ownership, registration marker and verified
            // email evidence remain intact for the surviving native account.
            $retained = EmailRecovery::managed($id);
            return self::result($removed,$retained,$retained ? [
                __('Native account recovery evidence was retained to preserve access.','ach-wp-accounts')
            ] : []);
        } catch (VerificationError $error) {
            return self::result(false,true,[__('Some account data could not be erased. Review and retry the privacy request.','ach-wp-accounts')]);
        }
    }

    private static function execute(#[\SensitiveParameter] string $sql): int
    {
        global $wpdb;
        $old = $wpdb->suppress_errors(true);
        try {
            $count = $wpdb->query($sql);
            if ($count===false) { throw new VerificationError('storage_unavailable'); }
            return (int)$count;
        } finally { $wpdb->suppress_errors($old); }
    }

    private static function clearTemporary(int $id,?object $identity): bool
    {
        global $wpdb;
        $removed = 0;
        foreach (['session_proofs','recovery_emails'] as $name) {
            $removed += self::execute($wpdb->prepare("DELETE FROM ".Schema::table($name)." WHERE user_id=%d",$id));
        }
        if ($identity) {
            // Only delete exact attributable events, never another subscriber's
            // history based on a matching last-four-digit mask.
            $events = Schema::table('events');
            $challenges = Schema::table('challenges');
            $bindings = Schema::table('identities');
            $removed += self::execute($wpdb->prepare(
                "DELETE e FROM $events e INNER JOIN $challenges c ON e.id=c.id
                 INNER JOIN $bindings b ON b.mobile=c.mobile AND b.revision=c.identity_revision WHERE b.user_id=%d AND b.revision=%s",$id,$identity->revision));
            $removed += self::execute($wpdb->prepare(
                "DELETE c FROM $challenges c INNER JOIN $bindings b ON b.mobile=c.mobile AND b.revision=c.identity_revision
                 WHERE b.user_id=%d AND b.revision=%s",$id,$identity->revision));
        }
        return $removed>0;
    }

    private static function removeIdentity(int $id,object $identity): void
    {
        global $wpdb;
        if (self::execute($wpdb->prepare("DELETE FROM ".Schema::table('identities')." WHERE user_id=%d AND revision=%s",
            $id,$identity->revision))!==1) {
            throw new VerificationError('identity_conflict');
        }
    }

    /** WordPress calls this after the native user has actually been deleted. */
    public static function deletedUser(int $id): void
    {
        if (!Lifecycle::ready()) { return; }
        try {
            $identity = Identity::byUser($id);
            self::clearTemporary($id,$identity);
            if ($identity) { self::removeIdentity($id,$identity); }
        } catch (VerificationError $error) {
            // Preserve the durable uniqueness reservation after uncertain
            // storage failure. Never reassign automatically.
            Diagnostics::accountFailure('storage_unavailable','complete');
        }
    }
}
