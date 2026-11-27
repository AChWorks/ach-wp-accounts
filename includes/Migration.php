<?php
namespace ACh\WPAccounts;

defined('ABSPATH') || exit;

/**
 * Import only explicitly reviewed legacy account mappings.
 * A mapping is not possession proof; no account creation or merging.
 * The full manifest is checked for duplicate phones/users before each page.
 */
final class Migration
{
    private static function manifest(#[\SensitiveParameter] array $input): array
    {
        AccountPolicy::requireAccountOperator();
        if (!Lifecycle::ready()) {
            throw new SettingsError('schema','storage_unavailable');
        }
        if (array_diff(array_keys($input),['evidence','rows','offset','limit','digest','confirm'])) {
            throw new SettingsError('manifest');
        }
        $evidence = $input['evidence'] ?? '';
        $rows = $input['rows'] ?? null;
        if (!is_string($evidence) || trim($evidence)==='' || !is_array($rows) || !array_is_list($rows) || !$rows) {
            throw new SettingsError('manifest');
        }
        $entries = [];
        $phones = $users = [];
        foreach ($rows as $row) {
            if (!is_array($row) || array_diff(array_keys($row),['user_id','mobile','selected']) ||
                !is_int($row['user_id'] ?? null) || $row['user_id']<1 ||
                !is_string($row['mobile'] ?? null) || !is_bool($row['selected'] ?? null)) {
                throw new SettingsError('rows');
            }
            $mobile = '';
            try { $mobile = Mobile::normalize($row['mobile']); } catch (\InvalidArgumentException $ignored) {}
            $entry = ['user_id'=>$row['user_id'],'mobile'=>$mobile,'selected'=>$row['selected']];
            // Even an excluded candidate is a possible source collision.
            $users[$entry['user_id']] = ($users[$entry['user_id']] ?? 0)+1;
            if ($mobile!=='') {
                $phones[$mobile] = ($phones[$mobile] ?? 0)+1;
            }
            $entries[] = $entry;
        }
        global $wpdb;
        $digest = hash_hmac('sha256',json_encode(['digits',home_url('/'),DB_NAME,$wpdb->prefix,$evidence,$entries],JSON_THROW_ON_ERROR),wp_salt('auth'));
        return [$entries,$phones,$users,$digest];
    }

    private static function classify(array $row,array $phones,array $users): string
    {
        $mobile = $row['mobile'];
        $id = $row['user_id'];
        if ($mobile==='') { return 'invalid_mobile'; }
        if ($phones[$mobile]>1 || $users[$id]>1) { return 'source_conflict'; }
        if (!$row['selected']) { return 'excluded'; }
        $user = get_userdata($id);
        if (!$user || !current_user_can('edit_user',$id) || !AccountPolicy::ordinary($user)) { return 'ineligible_account'; }
        $byMobile = Identity::byMobile($mobile);
        $byUser = Identity::byUser($id);
        if ($byMobile && (int)$byMobile->user_id===$id && $byUser && $byUser->mobile===$mobile) {
            return 'already_bound';
        }
        if ($byMobile || $byUser) { return 'identity_conflict'; }
        return 'ready';
    }

    /** Reuse the same exact manifest and offset for every page/retry. */
    public static function run(#[\SensitiveParameter] array $input,bool $apply = false): array
    {
        [$entries,$phones,$users,$digest] = self::manifest($input);
        $offset = $input['offset'] ?? 0;
        $limit = $input['limit'] ?? 50;
        if (!is_int($offset) || !is_int($limit) || $offset<0 || $offset>count($entries) || $limit<1 || $limit>100) {
            throw new SettingsError('offset');
        }
        if ($apply && (($input['confirm'] ?? false)!==true || !is_string($input['digest'] ?? null) ||
            !hash_equals($digest,$input['digest']))) {
            throw new SettingsError('digest','confirmation_required');
        }
        $results = [];
        foreach (array_slice($entries,$offset,$limit) as $i=>$row) {
            $state = self::classify($row,$phones,$users);
            if ($apply && $state==='ready') {
                try {
                    Identity::importReviewedMapping($row['user_id'],$row['mobile'],$digest);
                    $state = 'imported';
                } catch (VerificationError $error) {
                    // A concurrent native link/registration may have won.
                    // Reconcile exact current binding; never overwrite.
                    $state = self::classify($row,$phones,$users);
                    if ($state==='ready') { $state='write_unconfirmed'; }
                }
            }
            $results[] = ['index'=>$offset+$i,'user_id'=>$row['user_id'],
                'mobile_tail'=>$row['mobile']!=='' ? substr($row['mobile'],-4) : '', 'state'=>$state];
        }
        $next = $offset+count($results);
        return ['digest'=>$digest,'total'=>count($entries),'items'=>$results,'next_offset'=>$next<count($entries) ? $next : null];
    }
}
