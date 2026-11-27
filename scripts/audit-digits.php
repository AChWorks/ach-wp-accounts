<?php
namespace ACh\WPAccounts\Tools;

use ACh\WPAccounts\DigitsSource;

defined('ABSPATH') || exit;

/**
 * Local migration preparation only; never part of the installable plugin.
 * The draft is a candidate inventory, not evidence of historical verification.
 */
final class DigitsAudit
{
    /** Call on an isolated restored/quiescent site. Return aggregates only. */
    public static function inspect(?string $draftPath = null): array
    {
        $scan = DigitsSource::scan(true);
        $report = $scan['report'];
        $candidates = $scan['rows'];
        $report['draft_written'] = false;
        if ($draftPath !== null && $report['draft_available']) {
            self::writePrivateDraft($draftPath, [
                'evidence'=>'Digits candidate mapping inventory; NOT possession proof or an approved import.',
                'rows'=>$candidates,
            ]);
            $report['draft_written'] = true;
        }
        return $report;
    }

    /** Never overwrite a prior review file or put customer data in the webroot. */
    private static function writePrivateDraft(string $path, #[\SensitiveParameter] array $draft): void
    {
        $parent = realpath(dirname($path));
        $root = realpath(ABSPATH);
        $repo = realpath(__DIR__.'/..');
        if (!str_starts_with($path, '/') || !$parent || !$root || !$repo ||
            $parent === $root || str_starts_with($parent, $root.DIRECTORY_SEPARATOR) ||
            $parent === $repo || str_starts_with($parent, $repo.DIRECTORY_SEPARATOR)) {
            throw new \RuntimeException('private_path_required');
        }
        $path = $parent.DIRECTORY_SEPARATOR.basename($path);
        $data = wp_json_encode($draft, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n";
        $old = umask(0077);
        try { $file = @fopen($path, 'xb'); }
        finally { umask($old); }
        if (!$file) { throw new \RuntimeException('new_private_file_required'); }
        $complete = false;
        try {
            $stat = fstat($file);
            if (!$stat || ($stat['mode'] & 0777) !== 0600 || fwrite($file, $data) !== strlen($data) || !fflush($file)) {
                throw new \RuntimeException('draft_write_failed');
            }
            $complete = true;
        } finally {
            fclose($file);
            if (!$complete) { unlink($path); }
        }
    }
}

// Only invoked directly by the local WP-CLI eval-file command.
if (defined('WP_CLI') && WP_CLI) {
    try {
        if (count($args ?? []) > 1) { throw new \RuntimeException('expected_optional_private_path'); }
        \WP_CLI::line(wp_json_encode(DigitsAudit::inspect($args[0] ?? null), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    } catch (\Throwable $error) {
        // Do not include paths, SQL, source values or exception arguments.
        \WP_CLI::error('Digits audit failed. Check the authorized site, administrator, source and new private output path.');
    }
}
