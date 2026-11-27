<?php
namespace ACh\WPAccounts;

defined('ABSPATH') || exit;

/** Native operator discovery and execution. No account authentication endpoints. */
final class Abilities
{
    public static function hooks(): void
    {
        if (!function_exists('wp_register_ability') || !function_exists('wp_register_ability_category')) {
            return;
        }
        add_action('wp_abilities_api_categories_init', static function (): void {
            wp_register_ability_category('ach-wp-accounts', [
                'label' => __('ACh WP Accounts', 'ach-wp-accounts'),
                'description' => __('Verified mobile account operations.', 'ach-wp-accounts'),
            ]);
        });
        add_action('wp_abilities_api_init', [self::class, 'register']);
    }

    public static function status(): array
    {
        Settings::requireOperator();
        $settings = Settings::current();
        $configured = false;
        try {
            Settings::sender();
            $configured = true;
        } catch (SettingsError $ignored) {
        }
        return [
            'schema_version' => (int) get_option('ach_wp_accounts_schema_version', 0),
            'expected_schema_version' => Schema::VERSION,
            'schema_ready'=>Lifecycle::ready(),'schema_error'=>Lifecycle::error(),
            'providers' => array_map(static fn(array $provider): array => [
                'id'=>$provider['id'], 'modes'=>$provider['modes'],
                'pattern_parameters'=>$provider['pattern_parameters'],
                'required_inputs'=>$provider['credential_fields'],
            ],ProviderRegistry::capabilities()),
            'authentication_available' => BrowserApi::enabled(),
            'checkout_policy_available' => function_exists('WC'),
            'checkout_policy_enabled' => CheckoutGate::enabled(),
            'registration_policy_enabled' => Settings::flag('allow_registration'),
            'selected_provider' => $settings['selected_provider'],
            'provider_configured' => $configured,
            'compatible_origin' => BrowserApi::compatibleOrigin(),
            'cleanup_scheduled' => wp_next_scheduled('ach_wp_accounts_cleanup') !== false,
        ];
    }

    private static function object(array $properties, array $required = []): array
    {
        return ['type' => 'object', 'properties' => $properties, 'required' => $required, 'additionalProperties' => false];
    }

    private static function enum(array $values): array
    {
        return ['type' => 'string', 'enum' => $values];
    }

    private static function list(array $item): array
    {
        return ['type' => 'array', 'items' => $item];
    }

    private static function string(): array
    {
        return ['type' => 'string'];
    }

    private static function integer(int $min = 0, ?int $max = null): array
    {
        $schema = ['type'=>'integer', 'minimum'=>$min];
        if ($max !== null) {
            $schema['maximum'] = $max;
        }
        return $schema;
    }

    private static function route(): array
    {
        return self::object([
            'mode' => self::enum(['pattern','text']),
            'template' => self::string(),
            'tokens' => self::object(array_fill_keys(['token2','token3','token10','token20'], self::string())),
            'body_id' => ['type'=>['integer','string']],
            'values' => self::list(self::string()),
            'sender' => self::string(),
            'message' => self::string(),
        ], ['mode']);
    }

    private static function settingsProperties(bool $output): array
    {
        $providerIds = array_column(ProviderRegistry::capabilities(), 'id');
        $providerProperties = [
            'default' => self::route(),
            'purposes' => self::object(array_fill_keys(['login','link','step_up'], self::route())),
        ];
        if ($output) {
            $providerProperties['configured_inputs'] = self::list(self::enum(['api_key','username']));
        }
        $provider = self::object($providerProperties, $output ? array_keys($providerProperties) : []);
        $policy = [
            'codeLifetime' => self::integer(1, 600),
            'cooldown' => self::integer(1, 86400),
            'verifyHourly' => self::integer(1, 100),
        ] + array_fill_keys(['mobileHourly','networkHourly','siteHourly','siteDaily'], self::integer(1, 4294967295));
        $result = [
            'auth_enabled' => ['type'=>'boolean'],
            'allow_registration' => ['type'=>'boolean'],
            'checkout_required' => ['type'=>'boolean'],
            'selected_provider' => self::enum(['',...$providerIds]),
            'providers' => self::object(array_fill_keys($providerIds, $provider)),
            'verification' => self::object($policy, $output ? array_keys($policy) : []),
            'diagnostics_retention_hours' => self::integer(1, 168),
        ];
        return $output ? ['revision'=>self::string()] + $result : $result;
    }

    private static function event(bool $connection = false): array
    {
        $properties = [
            'correlation_id'=>self::string(), 'timestamp'=>self::integer(),
            'category'=>self::enum(['sms','connection','account']),
            'purpose'=>self::string(), 'provider'=>self::string(),
            'state'=>self::enum(['accepted','failed','uncertain']),
            'level'=>self::enum(['info','warning','error']),
            'code'=>self::string(), 'reference'=>self::string(), 'recipient_tail'=>self::string(),
            'duration_ms'=>self::integer(), 'retry_safety'=>self::enum(['no_automatic_retry','not_applicable']),
            'verification_state'=>self::string(), 'attempts'=>['type'=>['integer','null']],
        ];
        if ($connection) {
            $properties['recorded'] = ['type'=>'boolean'];
        }
        return self::object($properties, array_keys($properties));
    }

    private static function registerOne(string $name, string $label, string $description, ?array $input,
        array $output, callable $execute, bool $readonly, bool $idempotent, ?callable $permission = null, bool $destructive = false): void
    {
        $args = [
            'label'=>$label, 'description'=>$description, 'category'=>'ach-wp-accounts',
            'output_schema'=>$output,
            'permission_callback'=>$permission ?? static fn(): bool => current_user_can('manage_options'),
            'execute_callback'=>static function ($input = []) use ($execute) {
                try {
                    return $execute((array)$input);
                } catch (SettingsError $error) {
                    return new \WP_Error('ach_accounts_' . $error->getMessage(),
                        __('The requested account operation could not be completed. Check the error code and field.', 'ach-wp-accounts'),
                        ['field'=>$error->field]);
                } catch (VerificationError $error) {
                    return new \WP_Error('ach_accounts_' . $error->getMessage(),
                        __('The account operation could not be completed.', 'ach-wp-accounts'));
                } catch (\Throwable $error) {
                    return new \WP_Error('ach_accounts_operation_failed',
                        __('The account operation could not be completed.', 'ach-wp-accounts'));
                }
            },
            'meta'=>['show_in_rest'=>true, 'public'=>true,
                'annotations'=>['readonly'=>$readonly,'destructive'=>$destructive,'idempotent'=>$idempotent]],
        ];
        if ($input !== null) {
            $args['input_schema'] = $input;
        }
        wp_register_ability('ach-wp-accounts/' . $name, $args);
    }

    public static function register(): void
    {
        $properties = [
            'schema_version' => self::integer(),
            'expected_schema_version' => self::integer(),
            'schema_ready'=>['type'=>'boolean'],'schema_error'=>self::string(),
            'providers' => self::list(self::object([
                'id'=>self::string(),
                'modes'=>self::list(self::string()),
                'pattern_parameters'=>self::enum(['named','ordered']),
                'required_inputs'=>self::list(self::enum(['api_key','username'])),
            ], ['id','modes','pattern_parameters','required_inputs'])),
            'authentication_available'=>['type'=>'boolean'],
            'checkout_policy_available'=>['type'=>'boolean'],
            'checkout_policy_enabled'=>['type'=>'boolean'],
            'registration_policy_enabled'=>['type'=>'boolean'],
            'selected_provider'=>self::string(),
            'provider_configured'=>['type'=>'boolean'],
            'compatible_origin'=>['type'=>'boolean'],
            'cleanup_scheduled'=>['type'=>'boolean'],
        ];
        self::registerOne('status', __('Accounts status', 'ach-wp-accounts'),
            __('Inspect installed schema version and available provider capabilities. Does not send messages or access credentials.', 'ach-wp-accounts'),
            null, self::object($properties, array_keys($properties)), static fn()=>self::status(), true, true);
        $settings = self::settingsProperties(true);
        $settingsOutput = self::object($settings, array_keys($settings));
        self::registerOne('settings-read', __('Read account settings', 'ach-wp-accounts'),
            __('Read non-secret settings and credential presence. Never returns provider credentials.', 'ach-wp-accounts'),
            null, $settingsOutput, static fn()=>Settings::read(), true, true);
        self::registerOne('settings-update', __('Update account settings', 'ach-wp-accounts'),
            __('Update non-secret settings using the current settings revision. Credentials can only be entered through protected administration.', 'ach-wp-accounts'),
            self::object(['revision'=>self::string(), 'patch'=>self::object(self::settingsProperties(false))], ['revision','patch']),
            $settingsOutput, static fn($input)=>Settings::update((array)$input['patch'], $input['revision']), false, false);
        $pendingItem = self::object([
            'reservation_id'=>self::string(),'mobile_tail'=>self::string(),
            'native_user_id'=>['type'=>['integer','null']],
            'state'=>self::enum(['unstarted','native_account_missing','matching_native_account','native_account_conflict']),
        ],['reservation_id','mobile_tail','native_user_id','state']);
        self::registerOne('registration-pending',__('Inspect interrupted registrations','ach-wp-accounts'),
            __('Read pending reservations and matching native account evidence. Does not retry account creation.','ach-wp-accounts'),
            self::object(['cursor'=>self::string(),'limit'=>self::integer(1,100)]),
            self::object(['items'=>self::list($pendingItem),'next_cursor'=>['type'=>['string','null']]],['items','next_cursor']),
            static fn($input)=>RegistrationRecovery::inspect($input['cursor'] ?? '',$input['limit'] ?? 50),true,true,[AccountPolicy::class,'accountOperator']);
        self::registerOne('registration-reconcile',__('Reconcile an interrupted registration','ach-wp-accounts'),
            __('After reviewing native account side effects, confirm the exact matching reservation and user. Never creates another account.','ach-wp-accounts'),
            self::object(['reservation_id'=>self::string(),'user_id'=>self::integer(1),'confirm'=>['type'=>'boolean']],['reservation_id','user_id','confirm']),
            self::object(['user_id'=>self::integer(1),'mobile_tail'=>self::string(),'outcome'=>self::enum(['reconciled'])],['user_id','mobile_tail','outcome']),
            static fn($input)=>RegistrationRecovery::reconcile($input['reservation_id'],$input['user_id'],$input['confirm']),false,false,[AccountPolicy::class,'accountOperator']);
        self::registerOne('registration-release',__('Release an unstarted registration','ach-wp-accounts'),
            __('Confirm release of an exact reservation only when native account creation never started. Ambiguous native outcomes remain reserved.','ach-wp-accounts'),
            self::object(['reservation_id'=>self::string(),'confirm'=>['type'=>'boolean']],['reservation_id','confirm']),
            self::object(['outcome'=>self::enum(['released'])],['outcome']),
            static fn($input)=>RegistrationRecovery::release($input['reservation_id'],$input['confirm']),
            false,false,[AccountPolicy::class,'accountOperator'],true);
        $migrationInput = [
            'evidence'=>self::string(),
            'rows'=>self::list(self::object(['user_id'=>self::integer(1),'mobile'=>self::string(),
                'selected'=>['type'=>'boolean']],['user_id','mobile','selected'])),
            'offset'=>self::integer(),'limit'=>self::integer(1,100),
        ];
        $migrationOutput = self::object([
            'digest'=>self::string(),'total'=>self::integer(),'next_offset'=>['type'=>['integer','null']],
            'items'=>self::list(self::object(['index'=>self::integer(),'user_id'=>self::integer(1),'mobile_tail'=>self::string(),
                'state'=>self::enum(['invalid_mobile','source_conflict','excluded','ineligible_account','already_bound','identity_conflict','ready','imported','write_unconfirmed'])],
                ['index','user_id','mobile_tail','state'])),
        ],['digest','total','next_offset','items']);
        self::registerOne('migration-preview',__('Preview reviewed Digits identities','ach-wp-accounts'),
            __('Check selected legacy account mappings and all source conflicts. Historical OTP proof is not required; no possession is asserted.','ach-wp-accounts'),
            self::object($migrationInput,['evidence','rows']),$migrationOutput,
            static fn($input)=>Migration::run($input),true,true,[AccountPolicy::class,'accountOperator']);
        self::registerOne('migration-execute',__('Import reviewed Digits identities','ach-wp-accounts'),
            __('Import selected, uncontested existing-account mappings from the exact approved manifest. Fresh OTP is required to verify possession. Confirm first; resume with next offset. Does not create, merge or overwrite accounts.','ach-wp-accounts'),
            self::object($migrationInput+['digest'=>self::string(),'confirm'=>['type'=>'boolean']],['evidence','rows','digest','confirm']),$migrationOutput,
            static fn($input)=>Migration::run($input,true),false,true,[AccountPolicy::class,'accountOperator']);
        $provider = self::enum(array_column(ProviderRegistry::capabilities(), 'id'));
        self::registerOne('provider-connection', __('Test provider connection', 'ach-wp-accounts'),
            __('Test saved provider credentials without sending SMS; records a diagnostic result.', 'ach-wp-accounts'),
            self::object(['provider'=>$provider,'revision'=>self::string()]), self::event(true),
            static fn($input)=>Operations::connection($input['provider'] ?? null,$input['revision'] ?? null), false, false);
        self::registerOne('provider-test-send', __('Send a test SMS', 'ach-wp-accounts'),
            __('Explicitly send one potentially billable test message with the saved template. Requires confirmation and a unique request ID; exact replay is safe for the 24-hour challenge retention. The test cannot authenticate a customer.', 'ach-wp-accounts'),
            self::object([
                'provider'=>$provider, 'revision'=>self::string(), 'mobile'=>self::string(), 'purpose'=>self::enum(['login','link','step_up']),
                'request_id'=>['type'=>'string','pattern'=>'^[0-9a-f]{32}$'],
                'confirm_send'=>['type'=>'boolean'],
            ], ['mobile','purpose','request_id','confirm_send']),
            self::event(), static fn($input)=>Operations::testSend($input['mobile'], $input['purpose'],
                $input['request_id'], $input['confirm_send'], $input['provider'] ?? null,$input['revision'] ?? null), false, false);
        self::registerOne('diagnostics-query', __('Query account diagnostics', 'ach-wp-accounts'),
            __('Read paginated redacted message, connection and account-operation evidence; no OTPs, full mobile numbers or credentials.', 'ach-wp-accounts'),
            self::object([
                'category'=>self::enum(['sms','connection','account']), 'provider'=>$provider,
                'state'=>self::enum(['accepted','failed','uncertain']),
                'purpose'=>self::enum(['login','link','step_up','test_login','test_link','test_step_up','start','issue','complete','recovery_email','recovery_password']),
                'from'=>self::integer(), 'to'=>self::integer(), 'cursor'=>self::string(),
                'limit'=>self::integer(1,100), 'correlation_id'=>['type'=>'string','pattern'=>'^[0-9a-f]{32}$'],
            ]), self::object(['items'=>self::list(self::event()),'next_cursor'=>['type'=>['string','null']]], ['items','next_cursor']),
            static fn($input)=>Diagnostics::query($input), true, true);
    }
}
