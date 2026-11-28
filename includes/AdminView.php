<?php
namespace ACh\WPAccounts;

defined('ABSPATH') || exit;

/** All labels, form semantics and scoped presentation live in this view. */
final class AdminView
{
    private static int $sequence = 0;
    private static array $result = [];

    private static function title(string $text): void
    {
        echo '<h2>' . esc_html($text) . '</h2>';
    }

    private static function paragraph(string $text, string $class = 'description'): void
    {
        echo '<p class="' . esc_attr($class) . '">' . esc_html($text) . '</p>';
    }

    private static function hidden(string $name, string $value): void
    {
        echo '<input type="hidden" name="' . esc_attr($name) . '" value="' . esc_attr($value) . '">';
    }

    private static function startForm(string $tab, string $action, array $settings, ?string $provider = null): void
    {
        $args = $provider === null ? [] : ['provider'=>$provider];
        printf('<form method="post" action="%s" data-ach-admin-form="%s">',esc_url(Admin::url($tab,$args)),esc_attr($action));
        self::hidden('ach_action',$action);
        // Keep the submitted revision after a conflict. A stale draft must
        // never silently acquire permission to overwrite the new settings.
        $draft = self::$result['draft'] ?? [];
        $revision = ($draft['ach_action'] ?? '') === $action && isset($draft['revision'])
            ? $draft['revision'] : $settings['revision'];
        self::hidden('revision',$revision);
        self::hidden('_ach_nonce',wp_create_nonce('ach_accounts_admin_' . $action));
        if ($provider !== null) {
            self::hidden('provider',$provider);
        }
    }

    private static function endForm(?string $button = null, string $class = 'primary'): void
    {
        if ($button !== null) {
            submit_button($button,$class,self::fieldId(),false);
        }
        echo '</form>';
    }

    private static function fieldId(): string
    {
        return 'ach-accounts-field-' . ++self::$sequence;
    }

    private static function input(string $name, string $label, mixed $value = '', string $type = 'text',
        string $description = '', bool $ltr = false, bool $required = false): void
    {
        $id = self::fieldId();
        $descriptionAttr = $description !== '' ? ' aria-describedby="' . esc_attr($id . '-help') . '"' : '';
        echo '<div class="ach-admin-field">';
        printf('<label for="%s">%s</label>',esc_attr($id),esc_html($label));
        printf('<input type="%s" id="%s" name="%s" value="%s"%s%s%s%s%s>',
            esc_attr($type === 'number' ? 'text' : $type),esc_attr($id),esc_attr($name),esc_attr((string)$value),
            $ltr ? ' dir="ltr"' : '',
            $required ? ' required' : '',
            $type === 'password' ? ' autocomplete="new-password" spellcheck="false"' :
            ($type === 'tel' ? ' inputmode="tel" autocomplete="tel"' : ($type === 'number' ? ' inputmode="numeric"' : '')),
            $descriptionAttr,
            $type === 'password' && !is_ssl() ? ' disabled' : '');
        self::help($description,$id);
        echo '</div>';
    }

    private static function help(string $description,string $id): void
    {
        if ($description !== '') {
            printf('<p class="description" id="%s">%s</p>',esc_attr($id . '-help'),esc_html($description));
        }
    }

    private static function select(string $name, string $label, array $options, string $value, bool $mode = false): void
    {
        $id = self::fieldId();
        printf('<div class="ach-admin-field"><label for="%s">%s</label><select id="%s" name="%s"%s>',
            esc_attr($id),esc_html($label),esc_attr($id),esc_attr($name),$mode ? ' data-ach-mode' : '');
        foreach ($options as $key=>$title) {
            printf('<option value="%s"%s>%s</option>',esc_attr((string)$key),selected($value,(string)$key,false),esc_html($title));
        }
        echo '</select></div>';
    }

    private static function checkbox(string $name, string $label, bool $checked, string $description = '', bool $toggle = false): void
    {
        // Explicit hidden 0 preserves disabled flags on validation failures.
        self::hidden($name,'0');
        $id = self::fieldId();
        printf('<label class="ach-admin-check"><input type="checkbox" id="%s" name="%s" value="1"%s%s%s><span>%s</span></label>',
            esc_attr($id),esc_attr($name),checked($checked,true,false),$toggle ? ' role="switch"' : '',
            $description !== '' ? ' aria-describedby="' . esc_attr($id . '-help') . '"' : '',esc_html($label));
        self::help($description,$id);
    }

    private static function textarea(string $name, string $label, string $value, string $description = '', bool $ltr = false): void
    {
        $id = self::fieldId();
        printf('<div class="ach-admin-field"><label for="%s">%s</label><textarea rows="3" id="%s" name="%s"%s%s>%s</textarea>',
            esc_attr($id),esc_html($label),esc_attr($id),esc_attr($name),$ltr ? ' dir="ltr"' : '',
            $description !== '' ? ' aria-describedby="' . esc_attr($id . '-help') . '"' : '',esc_textarea($value));
        self::help($description,$id);
        echo '</div>';
    }

    private static function draft(string $action): array
    {
        $draft = self::$result['draft'] ?? [];
        return ($draft['ach_action'] ?? '') === $action ? $draft : [];
    }

    private static function state(bool $on): string
    {
        return $on ? __('Enabled','ach-wp-accounts') : __('Disabled','ach-wp-accounts');
    }

    private static function notice(string $message, bool $success = false): void
    {
        printf('<div class="notice notice-%s inline" role="%s"><p>%s</p></div>',
            $success ? 'success' : 'error',$success ? 'status' : 'alert',esc_html($message));
    }

    private static function error(string $code): string
    {
        return match ($code) {
            'settings_conflict' => __('Settings changed in another request. Reload and reconcile your edits before saving again.','ach-wp-accounts'),
            'invalid_nonce' => __('This form expired. Reload the page before trying again.','ach-wp-accounts'),
            'provider_unconfigured' => __('Save a complete provider configuration before selecting or enabling it.','ach-wp-accounts'),
            'provider_invalid' => __('Check the provider credentials and the selected pattern or text configuration.','ach-wp-accounts'),
            'password_login_required' => __('Enable mobile sign-in or keep password sign-in available to customers.','ach-wp-accounts'),
            'authentication_required' => __('Disable required mobile checkout before disabling mobile sign-in.','ach-wp-accounts'),
            'environment_unavailable' => __('Mobile sign-in requires a compatible HTTPS site origin and installed database schema.','ach-wp-accounts'),
            'woocommerce_unavailable' => __('WooCommerce must be active to enable this checkout requirement.','ach-wp-accounts'),
            'https_required' => __('Open administration over HTTPS to enter provider credentials.','ach-wp-accounts'),
            'confirmation_required' => __('Confirm the requested action first.','ach-wp-accounts'),
            'rate_limited' => __('The configured send or verification limit has been reached. Try again later.','ach-wp-accounts'),
            'request_conflict' => __('This test request was already used with different parameters. Reload before starting a new test.','ach-wp-accounts'),
            'invalid_settings' => __('Check the indicated settings field and try again.','ach-wp-accounts'),
            default => __('The operation could not be confirmed. Check diagnostics before repeating an external action.','ach-wp-accounts'),
        };
    }

    private static function receipt(array $r): void
    {
        $state = self::eventState($r['state'],$r['category']);
        echo '<div class="ach-admin-receipt" role="status"><strong>' . esc_html($state) . '</strong>';
        if ($r['category'] === 'sms') {
            self::paragraph($r['state'] === 'accepted' ?
                __('The provider accepted the message. This does not confirm delivery.','ach-wp-accounts') :
                ($r['state'] === 'uncertain' ? __('Delivery may have occurred. Do not automatically send another message.','ach-wp-accounts') :
                    __('The provider rejected the message or reported a definite failure.','ach-wp-accounts')));
        }
        echo '<dl class="ach-admin-metadata">';
        foreach ([
            __('Time','ach-wp-accounts')=>self::time($r['timestamp']),
            __('Provider code','ach-wp-accounts')=>$r['code'],
            __('Message reference','ach-wp-accounts')=>$r['reference'],
            __('Correlation ID','ach-wp-accounts')=>$r['correlation_id'],
            __('Duration (ms)','ach-wp-accounts')=>(string)$r['duration_ms'],
        ] as $label=>$value) {
            if ($value !== '') {
                echo '<div><dt>' . esc_html($label) . '</dt><dd><bdi dir="ltr">' . esc_html($value) . '</bdi></dd></div>';
            }
        }
        echo '</dl>';
        if (($r['recorded'] ?? true) === false) {
            self::paragraph(__('The connection result could not be recorded in diagnostic history.','ach-wp-accounts'));
        }
        echo '</div>';
    }

    private static function time(int $timestamp): string
    {
        return wp_date(get_option('date_format') . ' ' . get_option('time_format'),$timestamp);
    }

    private static function eventState(string $state, string $category): string
    {
        return match ($state) {
            'accepted' => $category === 'connection' ? __('Connected','ach-wp-accounts') : __('Accepted by provider','ach-wp-accounts'),
            'failed' => __('Failed','ach-wp-accounts'),
            default => __('Uncertain','ach-wp-accounts'),
        };
    }

    private static function readiness(array $settings): void
    {
        $status = Abilities::status();
        $provider = Admin::providers()[$settings['selected_provider']] ?? __('Not selected','ach-wp-accounts');
        $connection = null;
        $historyUnavailable = false;
        if ($settings['selected_provider'] !== '') {
            try {
                $connection = Diagnostics::query(['category'=>'connection','provider'=>$settings['selected_provider'],'limit'=>1])['items'][0] ?? null;
            } catch (SettingsError $ignored) {
                $historyUnavailable = true;
                // Configuration readiness remains available even if history is not.
            }
        }
        $rows = [
            __('Mobile sign-in','ach-wp-accounts')=>self::state($settings['auth_enabled']),
            __('Active SMS provider','ach-wp-accounts')=>$provider,
            __('Saved configuration','ach-wp-accounts')=>$status['provider_configured'] ? __('Valid','ach-wp-accounts') : __('Not configured','ach-wp-accounts'),
            __('Last connection test','ach-wp-accounts')=>$connection ?
                self::eventState($connection['state'],'connection') . ' — ' . self::time($connection['timestamp']) :
                ($historyUnavailable ? __('History unavailable','ach-wp-accounts') : __('No recorded test','ach-wp-accounts')),
        ];
        echo '<dl class="ach-admin-readiness">';
        foreach ($rows as $label=>$value) {
            printf('<div><dt>%s</dt><dd>%s</dd></div>',esc_html($label),esc_html($value));
        }
        echo '</dl>';
        self::paragraph(__('Connection history reflects earlier tests, not a live health or delivery guarantee.','ach-wp-accounts'));
        if (!$status['compatible_origin'] || !$status['schema_ready'] || !$status['cleanup_scheduled']) {
            self::notice(__('Check HTTPS origin, database schema and scheduled cleanup before enabling mobile sign-in.','ach-wp-accounts'));
            if ($status['schema_error']!=='') {
                echo '<p><code dir="ltr">'.esc_html($status['schema_error']).'</code></p>';
            }
        }
        if ($settings['auth_enabled'] && !$status['provider_configured']) {
            self::notice(__('Mobile sign-in is enabled but the saved provider is not ready. Review SMS settings.','ach-wp-accounts'));
        }
    }

    public static function render(string $tab, array $settings, array $result): void
    {
        Settings::requireOperator();
        self::$result = $result;
        echo '<div class="wrap ach-accounts-admin"><h1>ACh WP Accounts</h1>';
        self::readiness($settings);
        echo '<nav class="nav-tab-wrapper ach-admin-tabs" aria-label="' . esc_attr__('Account settings','ach-wp-accounts') . '">';
        foreach (Admin::tabs() as $key=>$label) {
            printf('<a class="nav-tab%s" href="%s"%s>%s</a>',$key===$tab ? ' nav-tab-active' : '',
                esc_url(Admin::url($key)),$key===$tab ? ' aria-current="page"' : '',esc_html($label));
        }
        echo '</nav>';
        if (isset($result['code'])) {
            self::notice(self::error($result['code']));
            if (($result['field'] ?? '') !== '') {
                printf('<p>%s <code dir="ltr">%s</code></p>',esc_html__('Field:','ach-wp-accounts'),esc_html($result['field']));
            }
            if (($result['draft']['ach_action'] ?? '') === 'provider') {
                self::paragraph(__('For your protection, re-enter any new credentials before saving.','ach-wp-accounts'));
            }
            if ($result['code'] === 'settings_conflict') {
                echo '<p><a class="button" href="' . esc_url(Admin::url($tab,$tab==='sms' ? ['provider'=>Admin::editor($settings)] : [])) . '">' .
                    esc_html__('Reload saved settings','ach-wp-accounts') . '</a></p>';
            }
        } elseif (Admin::queryParam('saved') === $settings['revision'] && $settings['revision'] !== 'initial') {
            self::notice(__('Settings saved.','ach-wp-accounts'),true);
        }
        match ($tab) {
            'sms'=>self::sms($settings),
            'diagnostics'=>self::diagnostics($settings),
            default=>self::general($settings),
        };
        echo '</div>';
    }

    private static function general(array $s): void
    {
        echo '<section class="ach-admin-panel">';
        self::title(__('Account access','ach-wp-accounts'));
        $draft = self::draft('general');
        self::startForm('general','general',$s);
        foreach ([
            'auth_enabled'=>[__('Mobile sign-in','ach-wp-accounts'),__('Allow eligible accounts to sign in with an SMS code.','ach-wp-accounts')],
            'allow_registration'=>[__('Mobile registration','ach-wp-accounts'),__('Allow mobile-based registration only where native site registration is enabled.','ach-wp-accounts')],
            'allow_email_registration'=>[__('Email registration','ach-wp-accounts'),__('Allow native public email registration where the site permits it.','ach-wp-accounts')],
            'allow_password_login'=>[__('Customer password sign-in','ach-wp-accounts'),__('Keep password sign-in available to ordinary customers. Administrators and other privileged accounts retain native access. Check access for customers without a mobile number before disabling.','ach-wp-accounts')],
            'checkout_required'=>[__('Require verified mobile at checkout','ach-wp-accounts'),__('Customers must sign in and have a verified mobile before payment.','ach-wp-accounts')],
            'checkout_reverify'=>[__('Require mobile verification in each checkout session','ach-wp-accounts'),__('When checkout verification is required, enable this to require mobile proof in the current login session. When off, a signed-in customer with a previously verified mobile can pay without another SMS.','ach-wp-accounts')],
        ] as $key=>[$label,$description]) {
            echo '<div class="ach-admin-setting">';
            self::checkbox($key,$label,isset($draft[$key]) ? $draft[$key]==='1' : $s[$key],$description,true);
            echo '</div>';
        }
        self::endForm(__('Save changes','ach-wp-accounts'));
        echo '</section><section class="ach-admin-panel">';
        self::title(__('Native site registration','ach-wp-accounts'));
        self::paragraph(__('The selected account methods also respect native WordPress and WooCommerce registration settings.','ach-wp-accounts'));
        $rows = [__('WordPress registration','ach-wp-accounts')=>self::state((bool)get_option('users_can_register'))];
        if (function_exists('WC')) {
            $rows[__('WooCommerce My Account registration','ach-wp-accounts')] = self::state(get_option('woocommerce_enable_myaccount_registration')==='yes');
            $rows[__('WooCommerce checkout registration','ach-wp-accounts')] = self::state(get_option('woocommerce_enable_signup_and_login_from_checkout')==='yes');
        } else {
            $rows['WooCommerce'] = __('Not active','ach-wp-accounts');
        }
        echo '<dl class="ach-admin-metadata">';
        foreach ($rows as $label=>$value) {
            printf('<div><dt>%s</dt><dd>%s</dd></div>',esc_html($label),esc_html($value));
        }
        echo '</dl></section>';
    }

    /** Convert the saved provider route to a presentation-only form model. */
    private static function routeModel(array $config): array
    {
        $model = [
            'mode'=>$config['mode'] ?? 'pattern',
            'template'=>$config['template'] ?? '',
            'body_id'=>(string)($config['body_id'] ?? ''),
            'values'=>implode("\n",$config['values'] ?? ['{code}']),
            'sender'=>$config['sender'] ?? '',
            'message'=>$config['message'] ?? __('Your verification code: {code}','ach-wp-accounts'),
        ];
        foreach (['token2','token3','token10','token20'] as $key) {
            $model[$key] = $config['tokens'][$key] ?? '';
        }
        return $model;
    }

    private static function routeFields(string $prefix, string $id, array $model): void
    {
        echo '<div class="ach-admin-route" data-ach-route>';
        self::select($prefix.'[mode]',__('Sending method','ach-wp-accounts'),[
            'pattern'=>__('Verification pattern','ach-wp-accounts'),'text'=>__('Ordinary SMS','ach-wp-accounts'),
        ],$model['mode'],true);
        echo '<div data-ach-mode-panel="pattern">';
        if ($id === 'kavenegar') {
            self::input($prefix.'[template]',__('Template name','ach-wp-accounts'),$model['template'],'text',
                __('The primary token is automatically replaced with the verification code.','ach-wp-accounts'),true);
            echo '<details><summary>' . esc_html__('Additional template tokens','ach-wp-accounts') . '</summary><div class="ach-admin-grid">';
            foreach (['token2','token3','token10','token20'] as $token) {
                self::input($prefix.'['.$token.']',$token,$model[$token]);
            }
            echo '</div></details>';
        } else {
            self::input($prefix.'[body_id]',__('Pattern body ID','ach-wp-accounts'),$model['body_id'],'text','',true);
            self::textarea($prefix.'[values]',__('Pattern values in order','ach-wp-accounts'),$model['values'],
                __('Enter one value per line in the provider template order. Include {code} where the verification code belongs.','ach-wp-accounts'));
        }
        echo '</div><div data-ach-mode-panel="text">';
        self::input($prefix.'[sender]',__('Sender number','ach-wp-accounts'),$model['sender'],'text','',true);
        self::textarea($prefix.'[message]',__('Message text','ach-wp-accounts'),$model['message'],
            __('Include {code} in the message. It is replaced with the verification code.','ach-wp-accounts'));
        echo '</div></div>';
    }

    private static function sms(array $s): void
    {
        $providers = Admin::providers();
        $saved = (array)$s['providers'];
        $id = Admin::editor($s);
        $provider = $saved[$id] ?? null;
        $hasConfig = is_array($provider);
        $draft = self::draft('provider');
        if (($draft['provider'] ?? '') !== $id) {
            $draft = [];
        }
        echo '<section class="ach-admin-panel">';
        self::title(__('Active SMS provider','ach-wp-accounts'));
        self::paragraph(__('Only the active provider sends customer verification messages. Configuring another provider does not switch it.','ach-wp-accounts'));
        self::startForm('sms','selection',$s,$id);
        $options = [''=>__('None','ach-wp-accounts')];
        foreach ($providers as $key=>$name) {
            if (isset($saved[$key])) {
                $options[$key] = $name;
            }
        }
        $selection = self::draft('selection')['selected_provider'] ?? $s['selected_provider'];
        self::select('selected_provider',__('Provider','ach-wp-accounts'),$options,$selection);
        self::endForm(__('Save provider selection','ach-wp-accounts'));
        echo '</section><section class="ach-admin-panel">';
        self::title(__('Provider configuration','ach-wp-accounts'));
        echo '<nav class="ach-admin-provider-nav" aria-label="' . esc_attr__('Configure provider','ach-wp-accounts') . '">';
        foreach ($providers as $key=>$name) {
            printf('<a class="button%s" href="%s"%s>%s%s</a>',
                $key===$id ? ' button-primary' : '',esc_url(Admin::url('sms',['provider'=>$key])),
                $key===$id ? ' aria-current="page"' : '',esc_html($name),
                $key===$s['selected_provider'] ? ' — '.esc_html__('Active','ach-wp-accounts') : '');
        }
        echo '</nav>';
        self::startForm('sms','provider',$s,$id);
        echo '<h3>' . esc_html__('Connection credentials','ach-wp-accounts') . '</h3>';
        self::paragraph(__('Saved credentials are not displayed. Leave a field empty to keep its saved value.','ach-wp-accounts'));
        if (!is_ssl()) {
            self::notice(self::error('https_required'));
        }
        foreach (ProviderRegistry::capabilities() as $capability) {
            if ($capability['id'] !== $id) {
                continue;
            }
            foreach ($capability['credential_fields'] as $key) {
                $set = in_array($key,$provider['configured_inputs'] ?? [],true);
                self::input('credentials['.$key.']',$key==='username' ? __('Provider username','ach-wp-accounts') : __('API key','ach-wp-accounts'),
                    '', 'password',$set ? __('Saved — leave blank to keep','ach-wp-accounts') : __('Not saved','ach-wp-accounts'),true);
            }
        }
        echo '<h3>' . esc_html__('Default message','ach-wp-accounts') . '</h3>';
        $default = array_replace(self::routeModel($provider['default'] ?? []),$draft['default'] ?? []);
        self::routeFields('default',$id,$default);
        echo '<details class="ach-admin-disclosure"' . ($draft || !empty((array)($provider['purposes'] ?? [])) ? ' open' : '') . '><summary>' .
            esc_html__('Optional purpose-specific messages','ach-wp-accounts') . '</summary>';
        self::paragraph(__('Use the default message unless an operation needs a different template.','ach-wp-accounts'));
        foreach (Admin::purposes() as $purpose=>$label) {
            $config = ((array)($provider['purposes'] ?? []))[$purpose] ?? null;
            $override = $draft['purposes'][$purpose] ?? [];
            $enabled = $draft ? ($override['enabled'] ?? '')==='1' : $config !== null;
            echo '<div class="ach-admin-override" data-ach-override><h4>' . esc_html($label) . '</h4>';
            self::checkbox('purposes['.$purpose.'][enabled]',__('Use a separate message','ach-wp-accounts'),$enabled);
            echo '<div data-ach-override-fields>';
            self::routeFields('purposes['.$purpose.']',$id,array_replace(self::routeModel($config ?? []),$override));
            echo '</div></div>';
        }
        echo '</details>';
        self::endForm(__('Save provider configuration','ach-wp-accounts'));
        if ($hasConfig) {
            echo '<details class="ach-admin-disclosure"><summary>' . esc_html__('Remove saved provider configuration','ach-wp-accounts') . '</summary>';
            self::paragraph(__('This removes its credentials and message settings. Disable mobile sign-in or switch to another configured provider before removing the active one.','ach-wp-accounts'));
            self::startForm('sms','remove',$s,$id);
            self::checkbox('confirm_remove',__('Confirm removal of this provider configuration','ach-wp-accounts'),false);
            self::endForm(__('Remove configuration','ach-wp-accounts'),'secondary');
            echo '</details>';
        }
        echo '</section><section class="ach-admin-panel">';
        self::title(__('Provider tests','ach-wp-accounts'));
        self::paragraph(__('Tests use saved settings for the provider shown above. Save edits first.','ach-wp-accounts'));
        if (!$hasConfig) {
            self::paragraph(__('Save the provider configuration to enable tests.','ach-wp-accounts'));
        } else {
            echo '<div class="ach-admin-grid ach-admin-test-grid"><div>';
            self::title(__('Connection test','ach-wp-accounts'));
            self::paragraph(__('Check provider access without sending a message.','ach-wp-accounts'));
            self::startForm('sms','connection',$s,$id);
            self::endForm(__('Test connection','ach-wp-accounts'),'secondary');
            if ((self::$result['operation'] ?? '')==='connection' && isset(self::$result['receipt'])) {
                echo '<div data-ach-new-receipt tabindex="-1">';
                self::receipt(self::$result['receipt']);
                echo '</div>';
            }
            echo '</div><div>';
            self::title(__('Test message','ach-wp-accounts'));
            self::startForm('sms','test_send',$s,$id);
            $testDraft = self::draft('test_send');
            self::hidden('request_id',$testDraft['request_id'] ?? bin2hex(random_bytes(16)));
            self::input('mobile',__('Recipient mobile','ach-wp-accounts'),'','tel','',true,true);
            self::select('purpose',__('Message purpose','ach-wp-accounts'),Admin::purposes(),$testDraft['purpose'] ?? 'login');
            self::checkbox('confirm_send',__('I confirm sending one potentially billable test message.','ach-wp-accounts'),false);
            self::endForm(__('Send test SMS','ach-wp-accounts'),'secondary');
            if ((self::$result['operation'] ?? '')==='test_send' && isset(self::$result['receipt'])) {
                echo '<div data-ach-new-receipt tabindex="-1">';
                self::receipt(self::$result['receipt']);
                echo '</div>';
            }
            echo '</div></div>';
        }
        echo '</section><section class="ach-admin-panel">';
        self::title(__('Verification policy','ach-wp-accounts'));
        self::startForm('sms','verification',$s,$id);
        $policy = array_replace($s['verification'],self::draft('verification'));
        echo '<div class="ach-admin-grid">';
        self::input('codeLifetime',__('Code lifetime (seconds)','ach-wp-accounts'),$policy['codeLifetime'],'number',
            __('Maximum 600 seconds.','ach-wp-accounts'),true,true);
        self::input('cooldown',__('Resend interval (seconds)','ach-wp-accounts'),$policy['cooldown'],'number','',true,true);
        echo '</div><details class="ach-admin-disclosure"' . (self::draft('verification') ? ' open' : '') . '><summary>' .
            esc_html__('Advanced traffic and verification limits','ach-wp-accounts') . '</summary>';
        self::paragraph(__('Adjust these limits to the site traffic and provider capacity. Limits apply to test messages too.','ach-wp-accounts'));
        echo '<div class="ach-admin-grid">';
        foreach ([
            'mobileHourly'=>__('SMS per mobile per hour','ach-wp-accounts'),
            'networkHourly'=>__('SMS per network per hour','ach-wp-accounts'),
            'siteHourly'=>__('SMS per site per hour','ach-wp-accounts'),
            'siteDaily'=>__('SMS per site per day','ach-wp-accounts'),
            'verifyHourly'=>__('Verification attempts per mobile per hour','ach-wp-accounts'),
        ] as $key=>$label) {
            self::input($key,$label,$policy[$key],'number','',true,true);
        }
        echo '</div></details>';
        self::endForm(__('Save verification policy','ach-wp-accounts'));
        echo '</section>';
    }

    private static function diagnostics(array $s): void
    {
        $categories = ['sms'=>__('SMS','ach-wp-accounts'),'connection'=>__('Connection','ach-wp-accounts'),'account'=>__('Account operations','ach-wp-accounts')];
        $states = ['accepted'=>__('Accepted','ach-wp-accounts'),'failed'=>__('Failed','ach-wp-accounts'),'uncertain'=>__('Uncertain','ach-wp-accounts')];
        $filters = [];
        foreach (['category','provider','state','correlation_id','cursor'] as $key) {
            $value = Admin::queryParam($key);
            if ($value !== '') {
                $filters[$key] = $value;
            }
        }
        echo '<section class="ach-admin-panel">';
        self::title(__('Diagnostic history','ach-wp-accounts'));
        self::paragraph(__('Provider acceptance does not confirm delivery. Times use the WordPress site timezone.','ach-wp-accounts'));
        printf('<form method="get" action="%s" class="ach-admin-filter">',esc_url(admin_url('admin.php')));
        self::hidden('page',Admin::PAGE);
        self::hidden('tab','diagnostics');
        self::select('category',__('Category','ach-wp-accounts'),[''=>__('All','ach-wp-accounts')]+$categories,$filters['category'] ?? '');
        self::select('provider',__('Provider','ach-wp-accounts'),[''=>__('All','ach-wp-accounts')]+Admin::providers(),$filters['provider'] ?? '');
        self::select('state',__('Result','ach-wp-accounts'),[''=>__('All','ach-wp-accounts')]+$states,$filters['state'] ?? '');
        self::input('correlation_id',__('Correlation ID','ach-wp-accounts'),$filters['correlation_id'] ?? '','text','',true);
        submit_button(__('Filter','ach-wp-accounts'),'secondary',self::fieldId(),false);
        printf('<a class="button" href="%s">%s</a>',esc_url(Admin::url('diagnostics')),esc_html__('Reset filters','ach-wp-accounts'));
        echo '</form>';
        try {
            $page = Diagnostics::query($filters);
            if (!$page['items']) {
                self::paragraph($filters ? __('No matching diagnostic events.','ach-wp-accounts') : __('No diagnostic events have been recorded yet.','ach-wp-accounts'));
            } else {
                echo '<div class="ach-admin-table-scroll" role="region" tabindex="0" aria-label="' . esc_attr__('Diagnostic events','ach-wp-accounts') . '">';
                echo '<table class="widefat striped"><thead><tr>';
                foreach ([__('Time','ach-wp-accounts'),__('Operation','ach-wp-accounts'),__('Provider','ach-wp-accounts'),
                    __('Result','ach-wp-accounts'),__('Recipient','ach-wp-accounts'),__('Details','ach-wp-accounts')] as $label) {
                    echo '<th scope="col">'.esc_html($label).'</th>';
                }
                echo '</tr></thead><tbody>';
                foreach ($page['items'] as $r) {
                    $purpose = Admin::purposes()[$r['purpose']] ?? $r['purpose'];
                    if (str_starts_with($r['purpose'],'test_')) {
                        $purpose = __('Test message','ach-wp-accounts').' — '.(Admin::purposes()[substr($r['purpose'],5)] ?? '');
                    }
                    $category = $categories[$r['category']] ?? $r['category'];
                    echo '<tr><td>'.esc_html(self::time($r['timestamp'])).'</td><td>'.esc_html($category);
                    if ($purpose !== '') {
                        echo '<p class="description">'.esc_html($purpose).'</p>';
                    }
                    echo '</td><td>'.esc_html(Admin::providers()[$r['provider']] ?? '—').'</td>';
                    printf('<td><span class="ach-admin-state ach-admin-state-%s">%s</span></td>',esc_attr($r['state']),esc_html(self::eventState($r['state'],$r['category'])));
                    echo '<td><bdi dir="ltr">'.esc_html($r['recipient_tail'] !== '' ? '••••'.$r['recipient_tail'] : '—').'</bdi></td>';
                    echo '<td><details><summary>'.esc_html($r['code'] ?: __('View details','ach-wp-accounts')).'</summary>';
                    self::receipt($r);
                    if ($r['verification_state'] !== '') {
                        $labels = [
                            'test_only'=>__('Test only','ach-wp-accounts'),'consumed'=>__('Used','ach-wp-accounts'),
                            'verified'=>__('Verified','ach-wp-accounts'),'not_sent'=>__('Not sent','ach-wp-accounts'),
                            'expired'=>__('Expired','ach-wp-accounts'),'attempts_exhausted'=>__('Attempts exhausted','ach-wp-accounts'),
                            'awaiting_code'=>__('Awaiting code','ach-wp-accounts'),
                        ];
                        self::paragraph(__('Verification state:','ach-wp-accounts').' '.($labels[$r['verification_state']] ?? $r['verification_state']));
                    }
                    if ($r['attempts'] !== null) {
                        self::paragraph(__('Attempts:','ach-wp-accounts').' '.number_format_i18n($r['attempts']));
                    }
                    echo '</details></td></tr>';
                }
                echo '</tbody></table></div><div class="ach-admin-pagination">';
                if (isset($filters['cursor'])) {
                    $first = $filters;
                    unset($first['cursor']);
                    printf('<a class="button" href="%s">%s</a>',esc_url(Admin::url('diagnostics',$first)),esc_html__('Newest events','ach-wp-accounts'));
                }
                if ($page['next_cursor'] !== null) {
                    $next = $filters;
                    $next['cursor'] = $page['next_cursor'];
                    printf('<a class="button" href="%s">%s</a>',esc_url(Admin::url('diagnostics',$next)),esc_html__('Older events','ach-wp-accounts'));
                }
                echo '</div>';
            }
        } catch (SettingsError $error) {
            self::notice(self::error($error->getMessage()));
        }
        echo '</section><section class="ach-admin-panel">';
        self::title(__('Diagnostic retention','ach-wp-accounts'));
        self::paragraph(__('Changes affect diagnostic history, not the separate lifecycle of verification challenges.','ach-wp-accounts'));
        self::startForm('diagnostics','retention',$s);
        self::input('diagnostics_retention_hours',__('Retention (hours)','ach-wp-accounts'),
            self::draft('retention')['diagnostics_retention_hours'] ?? $s['diagnostics_retention_hours'],'number',
            __('Allowed range: 1–168 hours.','ach-wp-accounts'),true,true);
        self::endForm(__('Save retention','ach-wp-accounts'));
        echo '</section>';
    }
}
