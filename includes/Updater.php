<?php
namespace ACh\WPAccounts;

defined('ABSPATH') || exit;

/**
 * Public GitHub stable releases, through the native WordPress update path.
 * The release manifest describes the actual installable ZIP. No tokens or
 * global HTTP-request authentication filters. WordPress owns scheduling, permissions and installation.
 */
final class Updater
{
    private const REPO = 'AChWorks/ach-wp-accounts';
    private const SLUG = 'ach-wp-accounts';
    private const API = 'https://api.github.com/repos/' . self::REPO . '/releases/';
    private const WEB = 'https://github.com/' . self::REPO;
    private const ZIP = 'ach-wp-accounts.zip';
    private const MANIFEST = 'ach-wp-accounts-release.json';
    private const CACHE = 'ach_wp_accounts_release';
    private static string $file;

    public static function hooks(string $file): void
    {
        self::$file = plugin_basename($file);
        add_filter('update_plugins_github.com', [self::class, 'update'], 10, 4);
        add_filter('plugins_api', [self::class, 'details'], 10, 3);
        add_filter('upgrader_pre_download', [self::class, 'download'], 10, 4);
        add_action('delete_site_transient_update_plugins', [self::class, 'clear']);
    }

    public static function clear(): void
    {
        delete_site_transient(self::CACHE);
    }

    private static function stable(string $version): bool
    {
        return preg_match('/\A(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\z/D', $version) === 1;
    }

    private static function packageUrl(string $version, string $name): string
    {
        return self::WEB . '/releases/download/v' . $version . '/' . $name;
    }

    /** Fixed caller-owned endpoints only; discard HTTP bodies and raw errors. */
    private static function json(string $url, int $limit): ?array
    {
        $response = wp_safe_remote_get($url, [
            'timeout'=>12, 'redirection'=>5, 'sslverify'=>true,
            'limit_response_size'=>$limit, 'headers'=>['Accept'=>'application/json'],
        ]);
        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            return null;
        }
        $decoded = json_decode(wp_remote_retrieve_body($response), true);
        return is_array($decoded) ? $decoded : null;
    }

    private static function asset(array $release, string $version, string $name): ?array
    {
        $assets = $release['assets'] ?? [];
        if (!is_array($assets)) {
            return null;
        }
        $matches = array_values(array_filter($assets, static fn($a) => is_array($a) && ($a['name'] ?? null) === $name));
        if (count($matches) !== 1) {
            return null;
        }
        $asset = $matches[0];
        return ($asset['state'] ?? '') === 'uploaded' && ($asset['browser_download_url'] ?? '') === self::packageUrl($version,$name) &&
            is_int($asset['size'] ?? null) && $asset['size'] > 0 ? $asset : null;
    }

    /**
     * Fetch latest, or the exact version requested by the upgrader. A release
     * without both assets is not an installable candidate. A tag moved while
     * updating cannot substitute a different package/version.
     */
    private static function release(?string $requested = null, bool $fresh = false): ?array
    {
        if ($requested !== null && !self::stable($requested)) {
            return null;
        }
        $cached = get_site_transient(self::CACHE);
        if (!$fresh && is_array($cached) && ($requested === null || ($cached['version'] ?? null) === $requested)) {
            return $cached;
        }
        $release = self::json(self::API . ($requested === null ? 'latest' : 'tags/v' . $requested), 1048576);
        if (!$release || ($release['draft'] ?? true) !== false || ($release['prerelease'] ?? true) !== false ||
            !is_string($release['tag_name'] ?? null) || !str_starts_with($release['tag_name'],'v')) {
            return null;
        }
        $version = substr($release['tag_name'],1);
        if (!self::stable($version) || ($requested !== null && $version !== $requested)) {
            return null;
        }
        $zip = self::asset($release,$version,self::ZIP);
        $manifestAsset = self::asset($release,$version,self::MANIFEST);
        if (!$zip || !$manifestAsset) {
            return null;
        }
        $manifest = self::json(self::packageUrl($version,self::MANIFEST),16384);
        if (!$manifest || ($manifest['slug'] ?? null) !== self::SLUG || ($manifest['version'] ?? null) !== $version ||
            ($manifest['package'] ?? null) !== self::ZIP || !is_string($manifest['sha256'] ?? null) ||
            !preg_match('/\A[a-f0-9]{64}\z/D',$manifest['sha256']) ||
            !is_int($manifest['size'] ?? null) || $manifest['size'] !== $zip['size'] ||
            !is_string($manifest['requires_php'] ?? null) || !preg_match('/\A[0-9]+(?:\.[0-9]+){1,2}\z/D',$manifest['requires_php'])) {
            return null;
        }
        foreach (['requires_wp','tested_wp'] as $field) {
            if (!is_string($manifest[$field] ?? null) ||
                (($field==='requires_wp' || $manifest[$field] !== '') && !preg_match('/\A[0-9]+(?:\.[0-9]+){1,2}\z/D',$manifest[$field]))) {
                return null;
            }
        }
        if (isset($zip['digest']) && $zip['digest'] !== '' && $zip['digest'] !== 'sha256:' . $manifest['sha256']) {
            return null;
        }
        $data = [
            'version'=>$version,'sha256'=>$manifest['sha256'],'size'=>$manifest['size'],
            'requires_php'=>$manifest['requires_php'],'requires_wp'=>$manifest['requires_wp'],
            'tested_wp'=>$manifest['tested_wp'], 'package'=>self::packageUrl($version,self::ZIP),
            'url'=>self::WEB . '/releases/tag/v' . $version,
            'notes'=>is_string($release['body'] ?? null) ? $release['body'] : '',
        ];
        // Native WP schedules update checks; the short shared cache avoids
        // repeating the same lookup for plugin details and installation.
        set_site_transient(self::CACHE,$data,15 * MINUTE_IN_SECONDS);
        return $data;
    }

    public static function update(mixed $update, array $pluginData, string $file, array $locales): mixed
    {
        if ($file !== self::$file || ($pluginData['UpdateURI'] ?? '') !== self::WEB) {
            return $update;
        }
        // Each native update check is fresh, including "Check again".
        $release = self::release(null,true);
        if (!$release) {
            self::clear();
            return false;
        }
        return [
            'id'=>self::WEB,'slug'=>self::SLUG,'version'=>$release['version'],
            'url'=>$release['url'],'package'=>$release['package'],'requires_php'=>$release['requires_php'],
            'requires'=>$release['requires_wp'],'tested'=>$release['tested_wp'],
            'ach_sha256'=>$release['sha256'],
        ];
    }

    public static function details(mixed $result, string $action, mixed $args): mixed
    {
        if ($action !== 'plugin_information' || !is_object($args) || ($args->slug ?? null) !== self::SLUG) {
            return $result;
        }
        $release = self::release();
        if (!$release) {
            return $result;
        }
        return (object)[
            'name'=>'ACh WP Accounts','slug'=>self::SLUG,'version'=>$release['version'],
            'author'=>'<a href="https://ach.li/">ACh</a>','homepage'=>self::WEB,
            'download_link'=>$release['package'],'requires_php'=>$release['requires_php'],
            'requires'=>$release['requires_wp'],'tested'=>$release['tested_wp'],
            'sections'=>[
                'description'=>esc_html__('Verified mobile identity and native WordPress account integration.','ach-wp-accounts'),
                // Release notes are untrusted text, not arbitrary HTML.
                'changelog'=>nl2br(esc_html($release['notes'])) . '<p><a href="' . esc_url($release['url']) . '">' .
                    esc_html__('View GitHub release','ach-wp-accounts') . '</a></p>',
            ],
            'external'=>true,
        ];
    }

    /**
     * Let the native upgrader retain all normal capability, filesystem,
     * extraction, installation and rollback behavior. Only intercept the
     * exact GitHub ZIP of this plugin and verify its expected digest.
     */
    public static function download(mixed $reply, mixed $package, mixed $upgrader, array $extra): mixed
    {
        if ($reply !== false || ($extra['plugin'] ?? '') !== self::$file || !is_string($package) ||
            !str_starts_with($package,self::WEB . '/releases/download/')) {
            return $reply;
        }
        $pattern = '~\A' . preg_quote(self::WEB . '/releases/download/v','~') .
            '([0-9]+\.[0-9]+\.[0-9]+)/' . preg_quote(self::ZIP,'~') . '\z~D';
        if (!preg_match($pattern,$package,$m)) {
            return new \WP_Error('ach_accounts_update_package',__('The update package is not a recognized release.','ach-wp-accounts'));
        }
        // Refresh the exact tag's metadata so a cached digest cannot authorize
        // a replaced asset or use metadata of a different, newly published tag.
        $release = self::release($m[1],true);
        if (!$release || $release['package'] !== $package) {
            return new \WP_Error('ach_accounts_update_metadata',__('The release metadata could not be verified. Check GitHub and try again.','ach-wp-accounts'));
        }
        $advertised = get_site_transient('update_plugins');
        $expected = $advertised->response[self::$file] ?? null;
        if (is_object($expected) && ($expected->package ?? null) === $package &&
            isset($expected->ach_sha256) && !hash_equals((string)$expected->ach_sha256,$release['sha256'])) {
            return new \WP_Error('ach_accounts_update_changed',__('The release changed since the update check. Check for updates again before installing.','ach-wp-accounts'));
        }
        if (version_compare(PHP_VERSION,$release['requires_php'],'<') ||
            version_compare(get_bloginfo('version'),$release['requires_wp'],'<')) {
            return new \WP_Error('ach_accounts_update_compatibility',__('This release requires a newer PHP or WordPress version.','ach-wp-accounts'));
        }
        require_once ABSPATH . 'wp-admin/includes/file.php';
        $file = download_url($package,300,false);
        if (is_wp_error($file)) {
            return $file;
        }
        if (filesize($file) !== $release['size'] || !hash_equals($release['sha256'],(string)hash_file('sha256',$file))) {
            wp_delete_file($file);
            return new \WP_Error('ach_accounts_update_integrity',__('The downloaded package failed its integrity check. No update was installed.','ach-wp-accounts'));
        }
        return $file;
    }
}
