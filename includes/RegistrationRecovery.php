<?php
namespace ACh\WPAccounts;

defined('ABSPATH') || exit;

/** Controlled reconciliation. Never rerun uncertain native creation hooks. */
final class RegistrationRecovery
{
    private static function requireReady(): void
    {
        AccountPolicy::requireAccountOperator();
        if (!Lifecycle::ready()) {
            throw new SettingsError('schema','storage_unavailable');
        }
    }

    private static function classify(object $row): array
    {
        $user = get_user_by('login',$row->registration_login);
        $state = (int)$row->registration_started===0 ? 'unstarted' : 'native_account_missing';
        if (!$user) {
            global $wpdb;
            $old = $wpdb->suppress_errors(true);
            try {
                $marker = $wpdb->get_var($wpdb->prepare("SELECT user_id FROM $wpdb->usermeta WHERE meta_key=%s AND meta_value=%s LIMIT 1",
                    '_ach_accounts_registration',$row->revision));
                if ($wpdb->last_error!=='') { throw new SettingsError('','storage_unavailable'); }
                if ($marker!==null) { $state='native_account_conflict'; }
            } finally { $wpdb->suppress_errors($old); }
        }
        if ($user instanceof \WP_User) {
            $state = (int)$row->registration_started===1 && current_user_can('edit_user',$user->ID) && get_user_meta($user->ID,'_ach_accounts_registration',true)===$row->revision &&
                AccountPolicy::ordinary($user) && $user->user_email==='' && Identity::byUser($user->ID)===null
                ? 'matching_native_account' : 'native_account_conflict';
        }
        return [
            'reservation_id'=>$row->revision,'mobile_tail'=>substr($row->mobile,-4),
            'native_user_id'=>$user instanceof \WP_User ? (int)$user->ID : null,'state'=>$state,
        ];
    }

    public static function inspect(string $after = '',int $limit = 50): array
    {
        self::requireReady();
        if ($limit<1 || $limit>100 || ($after!=='' && !preg_match('/\A[0-9a-f]{32}\z/D',$after))) {
            throw new SettingsError('cursor');
        }
        global $wpdb;
        $table = Schema::table('identities');
        // Keyset uses the reservation revision; no phone number in the cursor.
        $old = $wpdb->suppress_errors(true);
        try {
            $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM $table WHERE user_id IS NULL AND revision>%s ORDER BY revision LIMIT %d",$after,$limit+1));
            if ($wpdb->last_error!=='') {
                throw new SettingsError('','storage_unavailable');
            }
        } finally { $wpdb->suppress_errors($old); }
        $more = count($rows)>$limit;
        $rows = array_slice($rows,0,$limit);
        return ['items'=>array_map([self::class,'classify'],$rows),'next_cursor'=>$more ? end($rows)->revision : null];
    }

    private static function pending(string $reservationId): object
    {
        global $wpdb;
        $table = Schema::table('identities');
        $old = $wpdb->suppress_errors(true);
        try {
            $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE revision=%s AND user_id IS NULL",$reservationId));
            if ($wpdb->last_error!=='') { throw new SettingsError('','storage_unavailable'); }
        } finally { $wpdb->suppress_errors($old); }
        if (!$row) { throw new SettingsError('reservation_id','registration_pending'); }
        return $row;
    }

    /** Release only a durably unstarted reservation, not an ambiguous native call. */
    public static function release(string $reservationId,bool $confirmed): array
    {
        self::requireReady();
        if (!$confirmed || !preg_match('/\\A[0-9a-f]{32}\\z/D',$reservationId)) {
            throw new SettingsError('reservation_id','confirmation_required');
        }
        $row = self::pending($reservationId);
        return DatabaseLock::run('registration-'.$row->mobile,static function () use ($row): array {
            $fresh = Identity::byMobile($row->mobile);
            if (!$fresh || $fresh->revision!==$row->revision || $fresh->user_id!==null ||
                self::classify($fresh)['state']!=='unstarted' || !Identity::abandonUnstartedRegistration($fresh)) {
                throw new SettingsError('reservation_id','registration_pending');
            }
            return ['outcome'=>'released'];
        },'registration_pending');
    }

    /**
     * Confirm only a matching account after an operator reviews native hook
     * side effects. A missing or inconsistent user is not safe to recreate.
     */
    public static function reconcile(string $reservationId,int $userId,bool $confirmed): array
    {
        self::requireReady();
        if (!$confirmed || $userId<1 || !preg_match('/\A[0-9a-f]{32}\z/D',$reservationId)) {
            throw new SettingsError('reservation_id','confirmation_required');
        }
        $row = self::pending($reservationId);
        return DatabaseLock::run('registration-'.$row->mobile,static function () use ($row,$userId): array {
            $fresh = Identity::byMobile($row->mobile);
            if (!$fresh || $fresh->revision!==$row->revision || $fresh->user_id!==null) {
                throw new SettingsError('reservation_id','identity_conflict');
            }
            $state = self::classify($fresh);
            if ($state['state']!=='matching_native_account' || $state['native_user_id']!==$userId) {
                throw new SettingsError('user_id','identity_conflict');
            }
            $bound = Identity::finishRegistration($fresh,$userId);
            return ['user_id'=>(int)$bound->user_id,'mobile_tail'=>substr($bound->mobile,-4),'outcome'=>'reconciled'];
        },'registration_pending');
    }
}
