<?php
namespace ACh\WPAccounts;

defined('ABSPATH') || exit;

/** Thin native wp-admin adapter around the shared operator services. */
final class Admin
{
    public const PAGE = 'ach-wp-accounts';
    private static array $result = [];

    public static function hooks(): void
    {
        add_action('admin_menu', [self::class, 'menu']);
    }

    public static function menu(): void
    {
        $hook = add_menu_page('ACh WP Accounts', 'ACh WP Accounts', 'manage_options',
            self::PAGE, [self::class, 'render'], 'dashicons-smartphone');
        if (!$hook) {
            return;
        }
        add_action('load-' . $hook, [self::class, 'load']);
        add_action('admin_enqueue_scripts', static function (string $current) use ($hook): void {
            if ($current !== $hook) {
                return;
            }
            $base = plugin_dir_url(dirname(__DIR__) . '/ach-wp-accounts.php');
            wp_enqueue_style(self::PAGE . '-admin', $base . 'assets/admin.css', [], (string)filemtime(dirname(__DIR__) . '/assets/admin.css'));
            wp_enqueue_script(self::PAGE . '-admin', $base . 'assets/admin.js', [], (string)filemtime(dirname(__DIR__) . '/assets/admin.js'), true);
        });
    }

    public static function url(string $tab, array $args = []): string
    {
        return add_query_arg(['page'=>self::PAGE,'tab'=>$tab] + $args, admin_url('admin.php'));
    }

    public static function tabs(): array
    {
        return [
            'general'=>__('General','ach-wp-accounts'),
            'sms'=>__('SMS & verification','ach-wp-accounts'),
            'diagnostics'=>__('Diagnostics','ach-wp-accounts'),
        ];
    }

    public static function providers(): array
    {
        $names = ['kavenegar'=>'Kavenegar','melipayamak'=>'Melipayamak'];
        $result = [];
        foreach (ProviderRegistry::capabilities() as $provider) {
            $id = $provider['id'];
            $result[$id] = $names[$id] ?? $id;
        }
        return $result;
    }

    public static function purposes(): array
    {
        return [
            'login'=>__('Sign-in and registration','ach-wp-accounts'),
            'link'=>__('Link or change mobile','ach-wp-accounts'),
            'step_up'=>__('Additional mobile verification','ach-wp-accounts'),
        ];
    }

    public static function queryParam(string $key): string
    {
        $value = $_GET[$key] ?? '';
        return is_string($value) ? wp_unslash($value) : '';
    }

    public static function tab(): string
    {
        $tab = self::queryParam('tab');
        return isset(self::tabs()[$tab]) ? $tab : 'general';
    }

    public static function editor(array $settings): string
    {
        $id = self::queryParam('provider');
        if (isset(self::providers()[$id])) {
            return $id;
        }
        return isset(self::providers()[$settings['selected_provider']]) ? $settings['selected_provider'] : array_key_first(self::providers());
    }

    private static function str(#[\SensitiveParameter] array $data, string $key, string $fallback = ''): string
    {
        $value = $data[$key] ?? $fallback;
        if (!is_string($value)) {
            throw new SettingsError($key);
        }
        return $value;
    }

    private static function group(#[\SensitiveParameter] array $data, string $key): array
    {
        $value = $data[$key] ?? [];
        if (!is_array($value)) {
            throw new SettingsError($key);
        }
        return $value;
    }

    private static function boolean(#[\SensitiveParameter] array $data, string $key): bool
    {
        $value = self::str($data, $key, '0');
        if (!in_array($value, ['0','1'], true)) {
            throw new SettingsError($key);
        }
        return $value === '1';
    }

    private static function integer(#[\SensitiveParameter] array $data, string $key): int
    {
        $value = filter_var(Mobile::asciiDigits(trim(self::str($data,$key))), FILTER_VALIDATE_INT);
        if ($value === false) {
            throw new SettingsError($key);
        }
        return $value;
    }

    private static function provider(#[\SensitiveParameter] array $data): string
    {
        $id = self::str($data,'provider');
        if (!isset(self::providers()[$id])) {
            throw new SettingsError('provider');
        }
        return $id;
    }

    private static function route(array $data, string $id): array
    {
        $mode = self::str($data,'mode');
        if ($mode === 'text') {
            return ['mode'=>$mode, 'sender'=>trim(Mobile::asciiDigits(self::str($data,'sender'))),
                'message'=>self::str($data,'message')];
        }
        if ($mode !== 'pattern') {
            throw new SettingsError('mode');
        }
        if ($id === 'kavenegar') {
            $tokens = [];
            foreach (['token2','token3','token10','token20'] as $key) {
                $value = self::str($data,$key);
                if ($value !== '') {
                    $tokens[$key] = $value;
                }
            }
            return ['mode'=>$mode,'template'=>trim(self::str($data,'template')),'tokens'=>$tokens];
        }
        $values = str_replace(["\r\n","\r"],"\n",self::str($data,'values'));
        return ['mode'=>$mode,'body_id'=>self::integer($data,'body_id'),'values'=>explode("\n",rtrim($values,"\n"))];
    }

    public static function process(string $tab, #[\SensitiveParameter] array $post): array
    {
        Settings::requireOperator();
        $action = self::str($post,'ach_action');
        $allowed = [
            'general'=>['general'],
            'sms'=>['selection','provider','remove','verification','connection','test_send'],
            'diagnostics'=>['retention'],
        ];
        if (!in_array($action,$allowed[$tab] ?? [],true)) {
            throw new SettingsError('operation');
        }
        if (!wp_verify_nonce(self::str($post,'_ach_nonce'),'ach_accounts_admin_' . $action)) {
            throw new SettingsError('','invalid_nonce');
        }
        $revision = self::str($post,'revision');
        $patch = [];
        $credentialWrite = false;
        switch ($action) {
            case 'general':
                foreach (['auth_enabled','allow_registration','checkout_required','allow_email_registration','allow_password_login'] as $key) {
                    $patch[$key] = self::boolean($post,$key);
                }
                break;
            case 'selection':
                $patch['selected_provider'] = self::str($post,'selected_provider');
                break;
            case 'provider':
                $id = self::provider($post);
                $credentials = [];
                $input = self::group($post,'credentials');
                foreach (ProviderRegistry::capabilities() as $capability) {
                    if ($capability['id'] === $id) {
                        foreach ($capability['credential_fields'] as $key) {
                            $value = self::str($input,$key);
                            if ($value !== '') {
                                $credentials[$key] = $value;
                            }
                        }
                    }
                }
                if ($credentials && !is_ssl()) {
                    throw new SettingsError('credentials','https_required');
                }
                $config = ['default'=>self::route(self::group($post,'default'),$id),'purposes'=>[]];
                $overrides = self::group($post,'purposes');
                foreach (self::purposes() as $purpose=>$label) {
                    $route = self::group($overrides,$purpose);
                    if (self::boolean($route,'enabled')) {
                        $config['purposes'][$purpose] = self::route($route,$id);
                    }
                }
                if ($credentials) {
                    $config['credentials'] = $credentials;
                }
                $patch = ['providers'=>[$id=>$config]];
                $credentialWrite = true;
                break;
            case 'remove':
                $id = self::provider($post);
                if (!self::boolean($post,'confirm_remove')) {
                    throw new SettingsError('confirm_remove','confirmation_required');
                }
                $patch = ['providers'=>[$id=>null]];
                if (Settings::read()['selected_provider'] === $id) {
                    $patch['selected_provider'] = '';
                }
                $credentialWrite = true;
                break;
            case 'verification':
                foreach (array_keys(Settings::defaults()['verification']) as $key) {
                    $patch['verification'][$key] = self::integer($post,$key);
                }
                break;
            case 'retention':
                $patch['diagnostics_retention_hours'] = self::integer($post,'diagnostics_retention_hours');
                break;
            case 'connection':
            case 'test_send':
                $id = self::provider($post);
                $receipt = $action === 'connection' ? Operations::connection($id,$revision) : Operations::testSend(
                    self::str($post,'mobile'),self::str($post,'purpose'),self::str($post,'request_id'),
                    self::boolean($post,'confirm_send'),$id,$revision
                );
                return ['operation'=>$action,'receipt'=>$receipt];
        }
        $saved = Settings::update($patch,$revision,$credentialWrite);
        return ['operation'=>$action,'revision'=>$saved['revision']];
    }

    public static function load(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to manage these settings.','ach-wp-accounts'),'', ['response'=>403]);
        }
        nocache_headers();
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            return;
        }
        $post = wp_unslash($_POST);
        try {
            $result = self::process(self::tab(),$post);
            if (isset($result['revision'])) {
                $args = ['saved'=>$result['revision']];
                if (self::tab() === 'sms') {
                    $args['provider'] = self::editor(Settings::read());
                }
                if (wp_safe_redirect(self::url(self::tab(),$args),303)) {
                    exit;
                }
            }
            self::$result = $result;
        } catch (SettingsError $error) {
            self::$result = ['code'=>$error->getMessage(),'field'=>$error->field];
        } catch (VerificationError $error) {
            self::$result = ['code'=>$error->getMessage(),'field'=>''];
        } catch (\Throwable $error) {
            self::$result = ['code'=>'operation_failed','field'=>''];
        }
        if (isset(self::$result['code']) && self::$result['code'] !== 'invalid_nonce') {
            self::$result['draft'] = self::draft($post);
        }
    }

    /** Preserve non-secret values after validation errors, never credentials. */
    private static function draft(#[\SensitiveParameter] array $post): array
    {
        $safe = [];
        foreach (['ach_action','revision','provider','selected_provider','auth_enabled','allow_registration','checkout_required','allow_email_registration','allow_password_login',
            'codeLifetime','cooldown','mobileHourly','networkHourly','siteHourly','siteDaily','verifyHourly',
            'diagnostics_retention_hours','purpose','request_id'] as $key) {
            if (is_string($post[$key] ?? null)) {
                $safe[$key] = $post[$key];
            }
        }
        $route = static fn($data): array => is_array($data) ? array_filter(array_intersect_key($data,array_flip(
            ['mode','sender','message','template','token2','token3','token10','token20','body_id','values','enabled']
        )), 'is_string') : [];
        $safe['default'] = $route($post['default'] ?? null);
        $purposes = is_array($post['purposes'] ?? null) ? $post['purposes'] : [];
        foreach (self::purposes() as $key=>$label) {
            $safe['purposes'][$key] = $route($purposes[$key] ?? null);
        }
        return $safe;
    }

    public static function render(): void
    {
        Settings::requireOperator();
        AdminView::render(self::tab(), Settings::read(), self::$result);
    }
}
