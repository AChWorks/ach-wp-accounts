<?php
namespace ACh\WPAccounts;

defined('ABSPATH') || exit;

/**
 * Supported Digits metadata inventory, on demand only. No Digits runtime
 * dependency, historical proof inference, writes or raw remote export.
 */
final class DigitsSource
{
    private const KEYS = ['digits_phone', 'digt_countrycode', 'digits_phone_no', 'digits_phone_verification_skipped'];

    private static function normalize(string $mobile): ?string
    {
        try { return Mobile::normalize($mobile); }
        catch (\InvalidArgumentException) { return null; }
    }

    /** Internal, read-only scan of the exact legacy mapping keys. Never return this manifest to a remote caller. */
    public static function scan(bool $nativeAudit = false): array
    {
        AccountPolicy::requireAccountOperator();
        if (is_multisite()) {
            throw new SettingsError('source','single_site_required');
        }
        global $wpdb;
        $prior = $wpdb->suppress_errors(true);
        try {
            $native = null;
            if ($nativeAudit) {
                $native = $wpdb->get_row("SELECT COUNT(*) AS total, COUNT(CASE WHEN user_email='' THEN 1 END) AS without_email FROM $wpdb->users", ARRAY_A);
                if (!$native || $wpdb->last_error !== '') {
                    throw new SettingsError('source','native_inventory_failed');
                }
            }
            // Exactly the four source keys. Do not read OTP/session/log payloads,
            // billing contacts, passwords or actual email values.
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT user_id,meta_key,meta_value FROM $wpdb->usermeta
                 WHERE meta_key IN (%s,%s,%s,%s) ORDER BY user_id,umeta_id", ...self::KEYS
            ), ARRAY_A);
            if (!is_array($rows) || $wpdb->last_error !== '') {
                throw new SettingsError('source','legacy_inventory_failed');
            }
        } finally {
            $wpdb->suppress_errors($prior);
        }

        $byUser = [];
        $unexpectedKeys = 0;
        $counts = array_fill_keys(self::KEYS, 0);
        foreach ($rows as $row) {
            $key = $row['meta_key'];
            if (!array_key_exists($key, $counts)) { $unexpectedKeys++; continue; }
            $counts[$key]++;
            $byUser[(int)$row['user_id']][$key][] = (string)$row['meta_value'];
        }
        unset($rows);
        $report = [
            'consistency' => 'requires_quiescent_source',
            'source_rows' => $counts,
            'unexpected_key_rows' => $unexpectedKeys,
            'source_users' => 0,
            'matching_primary_and_split' => 0,
            'nonstandard_matching_split' => 0,
            'incomplete_or_conflicting_source_users' => 0,
            'marker_only_users' => 0,
            'invalid_primary_rows' => 0,
            'duplicate_primary_users' => 0,
            'shared_numbers_among_resolved_candidates' => 0,
            'historical_verification_assertions' => 0,
            'draft_rows' => 0,
        ];
        if ($nativeAudit) {
            $report += [
                'native_users' => (int)$native['total'],
                'native_users_without_email' => (int)$native['without_email'],
                'missing_native_accounts' => 0,
                'ineligible_native_accounts' => 0,
                'eligible_native_accounts' => 0,
                'eligible_accounts_without_email' => 0,
            ];
        }
        $candidates = $phones = [];
        foreach ($byUser as $id => $meta) {
            $main = $meta['digits_phone'] ?? [];
            $cc = $meta['digt_countrycode'] ?? [];
            $local = $meta['digits_phone_no'] ?? [];
            if (!$main && !$cc && !$local) {
                $report['marker_only_users']++;
                continue;
            }
            $report['source_users']++;
            if (count($main) > 1) { $report['duplicate_primary_users']++; }
            $primary = array_map([self::class, 'normalize'], $main);
            $report['invalid_primary_rows'] += count(array_filter($primary, static fn($p) => $p === null));
            foreach ($primary as $phone) {
                if ($phone !== null) { $phones[$phone][$id] = true; }
            }
            $split = count($cc) === 1 && count($local) === 1 ? self::normalize($cc[0].$local[0]) : null;
            if ($split !== null) { $phones[$split][$id] = true; }
            $matched = $id > 0 && count($primary) === 1 && $split !== null && $primary[0] === $split;
            if ($matched) {
                $report['matching_primary_and_split']++;
                if ($cc[0] !== '+98' || $local[0] !== substr($split, 3)) {
                    $report['nonstandard_matching_split']++;
                }
                $candidates[] = ['user_id'=>$id, 'mobile'=>$split, 'selected'=>true];
            } else {
                // Do not invent pairings or omit conflicting candidates in an
                // automatically produced manifest. The operator resolves these
                // against the intact source before preparing a reviewed file.
                $report['incomplete_or_conflicting_source_users']++;
            }
            if ($nativeAudit) {
                $user = $id > 0 ? get_userdata($id) : false;
                if (!$user) {
                    $report['missing_native_accounts']++;
                } elseif (!AccountPolicy::ordinary($user) || !current_user_can('edit_user', $id)) {
                    $report['ineligible_native_accounts']++;
                } else {
                    $report['eligible_native_accounts']++;
                    if ($user->user_email === '') { $report['eligible_accounts_without_email']++; }
                }
            }
        }
        $report['shared_numbers_among_resolved_candidates'] = count(array_filter($phones, static fn($owners) => count($owners) > 1));
        // This draft can include cross-user duplicates, privileged/missing
        // accounts and skipped markers; no row asserts possession or approval.
        $report['draft_rows'] = count($candidates);
        $report['draft_available'] = (bool)$candidates && !$unexpectedKeys && !$report['incomplete_or_conflicting_source_users'];
        return ['report'=>$report,'rows'=>$candidates];
    }
}
