<?php
namespace ACh\WPAccounts;

defined('ABSPATH') || exit;

/** Native discovery only. The development foundation exposes no authentication or send action. */
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
        return [
            'schema_version' => (int) get_option('ach_wp_accounts_schema_version', 0),
            'expected_schema_version' => Schema::VERSION,
            'providers' => ProviderRegistry::capabilities(),
            'authentication_available' => false,
            'checkout_policy_available' => false,
        ];
    }

    public static function register(): void
    {
        $properties = [
            'schema_version' => ['type' => 'integer'],
            'expected_schema_version' => ['type' => 'integer'],
            'providers' => [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'id' => ['type' => 'string'],
                        'modes' => ['type' => 'array', 'items' => ['type' => 'string']],
                        'pattern_parameters' => ['type' => 'string', 'enum' => ['named', 'ordered']],
                    ],
                    'required' => ['id', 'modes', 'pattern_parameters'],
                    'additionalProperties' => false,
                ],
            ],
            'authentication_available' => ['type' => 'boolean'],
            'checkout_policy_available' => ['type' => 'boolean'],
        ];
        wp_register_ability('ach-wp-accounts/status', [
            'label' => __('Accounts status', 'ach-wp-accounts'),
            'description' => __('Inspect installed schema version and available provider capabilities. Does not send messages or access credentials.', 'ach-wp-accounts'),
            'category' => 'ach-wp-accounts',
            'output_schema' => [
                'type' => 'object',
                'properties' => $properties,
                'required' => array_keys($properties),
                'additionalProperties' => false,
            ],
            'permission_callback' => static fn (): bool => current_user_can('manage_options'),
            'execute_callback' => static fn (): array => self::status(),
            'meta' => ['show_in_rest' => true, 'public' => true,
                'annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true]],
        ]);
    }
}
