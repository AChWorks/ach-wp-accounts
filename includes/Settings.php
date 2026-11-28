<?php
namespace ACh\WPAccounts;

defined('ABSPATH') || exit;

/** Validation errors identify a field, never its confidential value. */
final class SettingsError extends \RuntimeException
{
    public function __construct(public readonly string $field, string $code = 'invalid_settings')
    {
        parent::__construct($code);
    }
}

/**
 * A single site-owned, non-autoloaded option is the configuration snapshot.
 * A versioned compare-and-swap protects simultaneous admin/Ability updates.
 * No secrets are returned from the operator-facing read operation.
 */
final class Settings
{
    public const OPTION = 'ach_wp_accounts_settings';

    public static function defaults(): array
    {
        return [
            'revision' => 'initial',
            'auth_enabled' => false,
            'allow_registration' => false,
            'allow_email_registration' => true,
            'allow_password_login' => true,
            'allow_email_login' => true,
            'allow_first_link' => true,
            'allow_email_password_reset' => true,
            'allow_mobile_password_reset' => false,
            'allow_recovery_email_enrollment' => true,
            'allow_recovery_password_enrollment' => true,
            'registration_fields' => ['first_name'=>'off','last_name'=>'off'],
            'appearance' => ['logo_mode'=>'inherit','logo_id'=>0,'font_id'=>0,'accent'=>'','background'=>'','radius'=>null],
            'checkout_required' => false,
            'checkout_reverify' => false,
            'selected_provider' => '',
            'providers' => [],
            'verification' => [
                'codeLifetime' => 300, 'cooldown' => 60,
                'mobileHourly' => 5, 'networkHourly' => 60,
                'siteHourly' => 300, 'siteDaily' => 2000, 'verifyHourly' => 25,
            ],
            'diagnostics_retention_hours' => 24,
        ];
    }

    public static function booleanKeys(): array
    {
        return array_keys(array_filter(self::defaults(), 'is_bool'));
    }

    public static function fields(): array
    {
        $stored = self::current()['registration_fields'];
        return self::fieldModes(is_array($stored) ? $stored : [], false);
    }

    private static function fieldModes(array $values, bool $strict = true): array
    {
        $defaults = self::defaults()['registration_fields'];
        if ($strict) { self::keys($values, array_keys($defaults), 'registration_fields'); }
        foreach ($defaults as $key=>$mode) {
            if (array_key_exists($key,$values) && in_array($values[$key],['off','optional','required'],true)) {
                $defaults[$key] = $values[$key];
            } elseif ($strict && array_key_exists($key,$values)) {
                throw new SettingsError('registration_fields.'.$key);
            }
        }
        return $defaults;
    }

    public static function appearance(): array
    {
        $stored = self::current()['appearance'];
        return self::styleValues(is_array($stored) ? $stored : [], false);
    }

    /** Preview uses exactly the same tolerant attachment/fallback rules as runtime. */
    public static function previewAppearance(array $values): array
    {
        return self::styleValues($values,false);
    }

    private static function styleValues(array $values, bool $strict = true): array
    {
        $defaults = self::defaults()['appearance'];
        if ($strict) { self::keys($values,array_keys($defaults),'appearance'); }
        foreach ($defaults as $key=>$fallback) {
            if (!array_key_exists($key,$values)) { continue; }
            $v = $values[$key];
            $valid = match($key) {
                'logo_mode' => in_array($v,['inherit','name','custom'],true),
                'logo_id','font_id' => is_int($v) && $v >= 0,
                'accent','background' => is_string($v) && ($v === '' || (bool)preg_match('/\A#[0-9a-fA-F]{6}\z/D',$v)),
                'radius' => $v === null || (is_int($v) && $v >= 0 && $v <= 64),
            };
            if ($valid && $key === 'logo_id' && $v !== 0) { $valid = wp_attachment_is_image($v); }
            if ($valid && $key === 'font_id' && $v !== 0) {
                $url = wp_get_attachment_url($v);
                $valid = get_post_type($v)==='attachment' && is_string($url) &&
                    Frontend::localUrl($url,'') !== '' &&
                    in_array(get_post_mime_type($v),['font/woff','font/woff2','application/font-woff','application/font-woff2'],true);
            }
            if ($valid) { $defaults[$key] = $v; }
            elseif ($strict) { throw new SettingsError('appearance.'.$key); }
        }
        if ($defaults['logo_mode']==='custom' && !$defaults['logo_id']) {
            if ($strict) { throw new SettingsError('appearance.logo_id'); }
            $defaults['logo_mode']='inherit';
        }
        return $defaults;
    }

    public static function install(): void
    {
        add_option(self::OPTION, self::defaults(), '', false);
        if (!is_array(get_option(self::OPTION))) {
            throw new \RuntimeException('Accounts settings storage unavailable.');
        }
    }

    /** Internal configuration only, never an API output. */
    public static function current(): array
    {
        $value = get_option(self::OPTION, self::defaults());
        return is_array($value) ? array_replace(self::defaults(), $value) : self::defaults();
    }

    public static function flag(string $key): bool
    {
        return (self::current()[$key] ?? false) === true;
    }

    public static function policy(#[\SensitiveParameter] ?array $snapshot = null): VerificationPolicy
    {
        return new VerificationPolicy(...self::validatePolicy(($snapshot ?? self::current())['verification']));
    }

    public static function retentionSeconds(): int
    {
        $hours = self::current()['diagnostics_retention_hours'];
        // Corrupted settings must not prevent expiry cleanup.
        return is_int($hours) && $hours >= 1 && $hours <= 168 ? $hours * HOUR_IN_SECONDS : DAY_IN_SECONDS;
    }

    private static function ids(): array
    {
        return array_column(ProviderRegistry::capabilities(), 'id');
    }

    public static function sender(?string $id = null, #[\SensitiveParameter] ?array $snapshot = null): HttpSmsSender
    {
        $settings = $snapshot ?? self::current();
        $id ??= $settings['selected_provider'];
        if (!is_string($id) || !in_array($id, self::ids(), true)) {
            throw new SettingsError('selected_provider', 'provider_unconfigured');
        }
        $config = $settings['providers'][$id] ?? null;
        if (!is_array($config)) {
            throw new SettingsError('selected_provider', 'provider_unconfigured');
        }
        try {
            $sender = ProviderRegistry::create($id, $config['credentials'], $config['default'], $config['purposes']);
            $sender->validate();
            return $sender;
        } catch (\Throwable $error) {
            throw new SettingsError('providers.' . $id, 'provider_invalid');
        }
    }

    public static function requireOperator(): void
    {
        if (!current_user_can('manage_options')) {
            throw new SettingsError('', 'forbidden');
        }
    }

    /** Deliberately allowlist the entire public projection. No masked secret is a credential. */
    public static function read(): array
    {
        self::requireOperator();
        return self::publicProjection(self::current());
    }

    private static function publicProjection(#[\SensitiveParameter] array $s): array
    {
        $providers = [];
        foreach (self::ids() as $id) {
            $config = $s['providers'][$id] ?? null;
            if (!is_array($config)) {
                continue;
            }
            try {
                $default = self::route($id, $config['default'] ?? null, 'providers.' . $id . '.default');
                $purposes = $config['purposes'] ?? [];
                if (!is_array($purposes)) {
                    throw new SettingsError('providers.' . $id . '.purposes');
                }
                self::keys($purposes, ['login','link','step_up','password_reset'], 'providers.' . $id . '.purposes');
                foreach ($purposes as $purpose => $route) {
                    $purposes[$purpose] = self::route($id, $route, 'providers.' . $id . '.purposes.' . $purpose);
                }
            } catch (SettingsError $error) {
                // The status operation can report invalid configuration.
                // Never echo an unknown stored object or credential field.
                continue;
            }
            $providers[$id] = [
                'configured_inputs' => array_values(array_filter(self::credentialKeys($id),
                    static fn ($key) => is_string($config['credentials'][$key] ?? null) && $config['credentials'][$key] !== '')),
                'default' => $default,
                'purposes' => (object)$purposes,
            ];
        }
        return [
            'revision' => $s['revision'],
            ...array_map(static fn($v)=>$v===true,array_intersect_key($s,array_flip(self::booleanKeys()))),
            'registration_fields' => self::fieldModes(is_array($s['registration_fields'])?$s['registration_fields']:[],false),
            'appearance' => self::styleValues(is_array($s['appearance'])?$s['appearance']:[],false),
            'selected_provider' => $s['selected_provider'],
            'providers' => (object)$providers,
            'verification' => $s['verification'],
            'diagnostics_retention_hours' => $s['diagnostics_retention_hours'],
        ];
    }

    private static function credentialKeys(string $id): array
    {
        foreach (ProviderRegistry::capabilities() as $provider) {
            if ($provider['id'] === $id) {
                return $provider['credential_fields'];
            }
        }
        return [];
    }

    private static function keys(array $data, array $allowed, string $field): void
    {
        if (array_diff(array_keys($data), $allowed)) {
            throw new SettingsError($field);
        }
    }

    private static function integer(mixed $value, int $min, int $max, string $field): int
    {
        if (!is_int($value) || $value < $min || $value > $max) {
            throw new SettingsError($field);
        }
        return $value;
    }

    private static function validatePolicy(mixed $policy): array
    {
        if (!is_array($policy)) {
            throw new SettingsError('verification');
        }
        $default = self::defaults()['verification'];
        self::keys($policy, array_keys($default), 'verification');
        $policy = array_replace($default, $policy);
        // Six-digit OTP and five attempts remain security invariants. Ten
        // minutes is the upper lifetime of an out-of-band OTP (NIST 800-63B).
        $policy['codeLifetime'] = self::integer($policy['codeLifetime'], 1, 600, 'verification.codeLifetime');
        $policy['cooldown'] = self::integer($policy['cooldown'], 1, 86400, 'verification.cooldown');
        $policy['verifyHourly'] = self::integer($policy['verifyHourly'], 1, 100, 'verification.verifyHourly');
        foreach (['mobileHourly', 'networkHourly', 'siteHourly', 'siteDaily'] as $key) {
            $policy[$key] = self::integer($policy[$key], 1, 4294967295, 'verification.' . $key);
        }
        return $policy;
    }

    private static function route(string $id, mixed $route, string $field): array
    {
        if (!is_array($route)) {
            throw new SettingsError($field);
        }
        $mode = $route['mode'] ?? '';
        $allowed = $mode === 'text' ? ['mode','sender','message']
            : ($mode === 'pattern' && $id === 'kavenegar' ? ['mode','template','tokens']
                : ($mode === 'pattern' ? ['mode','body_id','values'] : []));
        if (!$allowed) {
            throw new SettingsError($field . '.mode');
        }
        self::keys($route, $allowed, $field);
        foreach ($route as $key => $value) {
            if ($key === 'body_id') {
                if (!is_int($value) && !is_string($value)) {
                    throw new SettingsError($field . '.' . $key);
                }
            } elseif (in_array($key, ['tokens','values'], true)) {
                if (!is_array($value) || array_filter($value, static fn($item) => !is_string($item))) {
                    throw new SettingsError($field . '.' . $key);
                }
            } elseif (!is_string($value)) {
                throw new SettingsError($field . '.' . $key);
            }
        }
        if ($mode === 'pattern' && $id === 'kavenegar') {
            $route['tokens'] ??= [];
            self::keys($route['tokens'], ['token2','token3','token10','token20'], $field . '.tokens');
        }
        return $route;
    }

    private static function config(string $id, #[\SensitiveParameter] array $old, #[\SensitiveParameter] mixed $patch, bool $writeCredentials): array
    {
        $field = 'providers.' . $id;
        if (!is_array($patch)) {
            throw new SettingsError($field);
        }
        self::keys($patch, ['credentials','default','purposes'], $field);
        $credentials = $old['credentials'] ?? [];
        if (array_key_exists('credentials', $patch)) {
            if (!$writeCredentials || !is_array($patch['credentials'])) {
                throw new SettingsError($field . '.credentials', 'credential_write_forbidden');
            }
            self::keys($patch['credentials'], self::credentialKeys($id), $field . '.credentials');
            foreach ($patch['credentials'] as $key => $secret) {
                if (!is_string($secret) || $secret === '' || trim($secret) !== $secret ||
                    preg_match('/[\x00-\x1f\x7f]/', $secret)) {
                    throw new SettingsError($field . '.credentials.' . $key);
                }
                $credentials[$key] = $secret;
            }
        }
        $default = self::route($id, $patch['default'] ?? $old['default'] ?? null, $field . '.default');
        $purposes = $patch['purposes'] ?? $old['purposes'] ?? [];
        if (!is_array($purposes)) {
            throw new SettingsError($field . '.purposes');
        }
        self::keys($purposes, ['login','link','step_up','password_reset'], $field . '.purposes');
        foreach ($purposes as $purpose => $route) {
            $purposes[$purpose] = self::route($id, $route, $field . '.purposes.' . $purpose);
        }
        try {
            ProviderRegistry::create($id, $credentials, $default, $purposes)->validate();
        } catch (\InvalidArgumentException $error) {
            throw new SettingsError($field, 'provider_invalid');
        }
        return ['credentials' => $credentials, 'default' => $default, 'purposes' => $purposes];
    }

    /**
     * Only the nonce-protected wp-admin adapter may opt into writes of
     * credentials. Abilities always call the non-secret variant.
     * Provider config is a complete saved route or omitted; no half-active config.
     */
    public static function update(#[\SensitiveParameter] array $patch, string $revision, bool $writeCredentials = false): array
    {
        self::requireOperator();
        if ((int)get_option(Lifecycle::VERSION_OPTION,0)>Schema::VERSION) {
            throw new SettingsError('schema','schema_unsupported');
        }
        global $wpdb;
        self::keys($patch, [...self::booleanKeys(),'selected_provider','providers','verification','diagnostics_retention_hours','registration_fields','appearance'], '');
        // Read the exact stored option row rather than a stale object-cache
        // value. WordPress update_option has no expected-value precondition.
        $previous = $wpdb->suppress_errors(true);
        $invalidate = false;
        try {
            $raw = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM $wpdb->options WHERE option_name=%s", self::OPTION));
            if (!is_string($raw) || $wpdb->last_error !== '') {
                throw new SettingsError('', 'storage_unavailable');
            }
            $stored = maybe_unserialize($raw);
            if (!is_array($stored) || ($stored['revision'] ?? null) !== $revision) {
                $invalidate = true;
                throw new SettingsError('revision', 'settings_conflict');
            }
            $next = array_replace(self::defaults(), array_intersect_key($stored, self::defaults()));
            foreach (self::booleanKeys() as $key) {
                if (array_key_exists($key, $patch)) {
                    if (!is_bool($patch[$key])) {
                        throw new SettingsError($key);
                    }
                    $next[$key] = $patch[$key];
                }
            }
            foreach (['registration_fields','appearance'] as $key) {
                if (array_key_exists($key,$patch)) {
                    if (!is_array($patch[$key])) { throw new SettingsError($key); }
                    $existing = is_array($next[$key]) ? $next[$key] : [];
                    $next[$key] = $key==='registration_fields'
                        ? self::fieldModes(array_replace(self::fieldModes($existing,false),$patch[$key]))
                        : self::styleValues(array_replace(self::styleValues($existing,false),$patch[$key]));
                }
            }
            if (array_key_exists('selected_provider', $patch)) {
                if (!is_string($patch['selected_provider']) || !in_array($patch['selected_provider'], ['',...self::ids()], true)) {
                    throw new SettingsError('selected_provider');
                }
                $next['selected_provider'] = $patch['selected_provider'];
            }
            if (array_key_exists('providers', $patch)) {
                if (!is_array($patch['providers'])) {
                    throw new SettingsError('providers');
                }
                self::keys($patch['providers'], self::ids(), 'providers');
                foreach ($patch['providers'] as $id => $providerPatch) {
                    if ($providerPatch === null) {
                        if (!$writeCredentials) {
                            throw new SettingsError('providers.' . $id, 'credential_write_forbidden');
                        }
                        unset($next['providers'][$id]);
                    } else {
                        $next['providers'][$id] = self::config($id, $next['providers'][$id] ?? [], $providerPatch, $writeCredentials);
                    }
                }
            }
            if (array_key_exists('verification', $patch)) {
                if (!is_array($patch['verification'])) {
                    throw new SettingsError('verification');
                }
                $next['verification'] = self::validatePolicy(array_replace($next['verification'], $patch['verification']));
            }
            if (array_key_exists('diagnostics_retention_hours', $patch)) {
                $next['diagnostics_retention_hours'] = self::integer($patch['diagnostics_retention_hours'], 1, 168, 'diagnostics_retention_hours');
            }
            if (!$next['allow_password_login'] && !$next['auth_enabled']) {
                throw new SettingsError('allow_password_login', 'password_login_required');
            }
            if ($next['checkout_required'] && !$next['auth_enabled']) {
                throw new SettingsError('checkout_required', 'authentication_required');
            }
            if ($next['checkout_required'] && !function_exists('WC') && empty($stored['checkout_required'])) {
                throw new SettingsError('checkout_required', 'woocommerce_unavailable');
            }
            $id = $next['selected_provider'];
            if ($next['auth_enabled'] || (array_key_exists('selected_provider',$patch) && $id !== '')) {
                if (!isset($next['providers'][$id])) {
                    throw new SettingsError('selected_provider', 'provider_unconfigured');
                }
                // Revalidate the selected saved config even when the patch
                // only changes an enable flag. No network side effects.
                $next['providers'][$id] = self::config($id, $next['providers'][$id], [], false);
            }
            if ($next['auth_enabled']) {
                // Check actual HTTPS origin compatibility, not connectivity.
                if (!BrowserApi::compatibleOrigin() || is_multisite() ||
                    !Lifecycle::ready()) {
                    throw new SettingsError('auth_enabled', 'environment_unavailable');
                }
            }
            $next['revision'] = bin2hex(random_bytes(16));
            $invalidate = true;
            $changed = $wpdb->query($wpdb->prepare(
                "UPDATE $wpdb->options SET option_value=%s WHERE option_name=%s AND BINARY option_value=BINARY %s",
                maybe_serialize($next), self::OPTION, $raw
            ));
            if ($changed !== 1) {
                throw new SettingsError('revision', $changed === false ? 'storage_unavailable' : 'settings_conflict');
            }
        } finally {
            $wpdb->suppress_errors($previous);
            if ($invalidate) {
                // Even an uncertain DB result or CAS conflict may mean that
                // another revision is now authoritative. Invalidate only
                // the installed non-autoloaded option, not global caches.
                wp_cache_delete(self::OPTION, 'options');
            }
        }
        return self::publicProjection($next);
    }
}
