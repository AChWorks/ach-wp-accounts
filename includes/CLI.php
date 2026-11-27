<?php
namespace ACh\WPAccounts;

defined('ABSPATH') || exit;

/** Local privileged maintenance, not a remote file or arbitrary SQL API. */
final class CLI
{
    public static function hooks(string $file): void
    {
        if (!defined('WP_CLI') || !WP_CLI || !class_exists('\WP_CLI')) { return; }
        \WP_CLI::add_command('ach-accounts privacy-export',static function ($args,$assoc): void {
            self::handle(static function () use ($assoc): array {
                $id = self::user($assoc);
                return Privacy::exportUser($id,self::number($assoc['page'] ?? '1',1));
            });
        },['shortdesc'=>'Export one native account privacy page (private output).',
            'synopsis'=>'--user-id=<id> [--page=<page>]']);
        \WP_CLI::add_command('ach-accounts privacy-erase',static function ($args,$assoc): void {
            self::handle(static function () use ($assoc): array {
                $id = self::user($assoc);
                if (($assoc['confirm'] ?? '')!=='erase-account-data') { throw new SettingsError('confirm','confirmation_required'); }
                return Privacy::eraseUser($id);
            });
        },['shortdesc'=>'Erase account data while preserving native recovery.',
            'synopsis'=>'--user-id=<id> --confirm=<confirmation>']);
        foreach (['migration-preview'=>false,'migration-execute'=>true] as $command=>$apply) {
            \WP_CLI::add_command('ach-accounts '.$command,static function ($args,$assoc) use ($apply): void {
                self::handle(static function () use ($args,$assoc,$apply): array {
                    AccountPolicy::requireAccountOperator();
                    // Private, operator-supplied local JSON manifest. No upload
                    // endpoint or server path accepted by remote Abilities.
                    if (isset($assoc['source'])) {
                        if ($args || $assoc['source']!=='digits') { throw new SettingsError('source','invalid_source'); }
                        $manifest = ['source'=>'digits'];
                    } else {
                        $path = $args[0] ?? '';
                        if (!is_string($path) || !is_file($path) || !is_readable($path)) {
                            throw new SettingsError('manifest');
                        }
                        $manifest = json_decode((string)file_get_contents($path),true,512,JSON_THROW_ON_ERROR);
                        if (!is_array($manifest) || array_key_exists('source',$manifest)) { throw new SettingsError('manifest'); }
                    }
                    $manifest['offset'] = self::number($assoc['offset'] ?? '0',0);
                    $manifest['limit'] = self::number($assoc['limit'] ?? '50',1);
                    $manifest['digest'] = (string)($assoc['digest'] ?? '');
                    $manifest['confirm'] = ($assoc['confirm'] ?? '')==='import-reviewed-identities';
                    return isset($assoc['all']) ? self::migrationAll($manifest,$apply) : Migration::run($manifest,$apply);
                });
            },['shortdesc'=>$apply ? 'Import the exact reviewed mapping page; fresh OTP verifies possession.' : 'Preview a private legacy mapping page.',
                'synopsis'=>'[<manifest>] [--source=<source>] [--all] [--offset=<offset>] [--limit=<limit>]'.($apply ? ' --digest=<digest> --confirm=<confirmation>' : '')]);
        }
        \WP_CLI::add_command('ach-accounts purge',static function ($args,$assoc) use ($file): void {
            self::handle(static function () use ($assoc,$file): array {
                Lifecycle::purge($file,($assoc['confirm'] ?? '')==='purge-accounts-data');
                return ['outcome'=>'purged'];
            });
        },['shortdesc'=>'Deactivate and deliberately purge only this plugin\'s data.',
            'synopsis'=>'--confirm=<confirmation>']);
    }

    /** Local bulk convenience: aggregate only, same digest, bounded write pages. */
    private static function migrationAll(#[\SensitiveParameter] array $manifest,bool $apply): array
    {
        $counts = [];
        $digest = null;
        $pages = 0;
        do {
            $offset = $manifest['offset'];
            $page = Migration::run($manifest,$apply);
            if ($digest !== null && !hash_equals($digest,$page['digest'])) {
                throw new SettingsError('digest','confirmation_required');
            }
            $digest = $page['digest'];
            $pages++;
            foreach ($page['items'] as $item) {
                $counts[$item['state']] = ($counts[$item['state']] ?? 0)+1;
            }
            // Reconcile the exact page on an uncertain write rather than
            // advancing a resume cursor past it.
            $uncertain = $apply && in_array('write_unconfirmed',array_column($page['items'],'state'),true);
            $manifest['offset'] = $uncertain ? $offset : $page['next_offset'];
        } while ($manifest['offset'] !== null && !$uncertain);
        unset($page['items']);
        $page['counts'] = $counts;
        $page['pages'] = $pages;
        $page['next_offset'] = $manifest['offset'];
        return $page;
    }

    private static function number(mixed $input,int $min): int
    {
        $number = filter_var($input,FILTER_VALIDATE_INT,['options'=>['min_range'=>$min]]);
        if ($number===false) { throw new SettingsError('number'); }
        return $number;
    }

    private static function user(array $assoc): int
    {
        AccountPolicy::requireAccountOperator();
        $id = self::number($assoc['user-id'] ?? '',1);
        if (!get_userdata($id) || !current_user_can('edit_user',$id)) { throw new SettingsError('user_id','forbidden'); }
        return $id;
    }

    private static function handle(callable $operation): void
    {
        try {
            \WP_CLI::line(wp_json_encode($operation(),JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
        } catch (SettingsError|VerificationError $error) {
            \WP_CLI::error($error->getMessage());
        } catch (\Throwable $error) {
            \WP_CLI::error('operation_failed');
        }
    }
}
