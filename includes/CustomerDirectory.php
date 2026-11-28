<?php
namespace ACh\WPAccounts;

defined('ABSPATH') || exit;

/** Read-only native directory adapters. Identities remain the sole source. */
final class CustomerDirectory
{
    private const MOBILE = 'ach_accounts_mobile';
    private const VERIFIED = 'ach_accounts_verified';
    private const ALIAS = 'ach_accounts_directory';
    private static ?\WP_REST_Request $reportRequest = null;
    private static array $reportTerms = [];
    private static bool $reportArray = false;
    private static array $page = [];
    private static bool $pageLoaded = false;

    public static function hooks(): void
    {
        add_filter('manage_users_columns', [self::class, 'columns']);
        add_filter('manage_users_custom_column', [self::class, 'cell'], 10, 3);
        add_filter('users_list_table_query_args', [self::class, 'userArgs']);
        add_filter('user_search_columns', [self::class, 'userSearchColumns'], PHP_INT_MAX, 3);
        add_action('pre_user_query', [self::class, 'userSearch'], PHP_INT_MAX);
        add_filter('rest_endpoints', [self::class, 'reportSearchArgs']);
        add_filter('rest_request_before_callbacks', [self::class, 'beginReport'], 10, 3);
        add_filter('rest_request_after_callbacks', [self::class, 'endReport'], 10, 3);
        add_filter('woocommerce_analytics_clauses_where_customers_subquery', [self::class, 'reportSearch']);
        add_filter('woocommerce_analytics_clauses_where_customers_stats_subquery', [self::class, 'reportSearch']);
        add_filter('woocommerce_analytics_report_should_use_cache', [self::class, 'reportCache'], 10, 2);
        add_filter('rest_post_dispatch', [self::class, 'reportProjection'], 10, 3);
        add_action('admin_enqueue_scripts', [self::class, 'reportAssets']);
    }

    private static function usersAllowed(): bool
    {
        return is_admin() && !is_network_admin() && current_user_can('list_users');
    }

    private static function reportAllowed(): bool
    {
        return current_user_can('list_users') && current_user_can('view_woocommerce_reports');
    }

    /** Pattern for the canonical +98 identity; no arbitrary SQL wildcards. */
    public static function pattern(string $input): ?string
    {
        $value = preg_replace('/[\s()\-]+/u', '', Mobile::asciiDigits(trim($input, "* \t\n\r")));
        if (!is_string($value) || !preg_match('/\A\+?[0-9]+\z/D', $value)) {
            return null;
        }
        try {
            return Mobile::normalize($value);
        } catch (\InvalidArgumentException) {
            // A partial international or local prefix should match the start
            // of the national number; a bare fragment may match any position.
        }
        if (preg_match('/\A(?:\+98|0098|98(?=9)|0(?=9))(9[0-9]*)\z/D', $value, $m)) {
            return strlen($m[1]) <= 10 ? '+98'.$m[1].'%' : null;
        }
        if (ctype_digit($value) && strlen($value) <= 10) {
            return '+98%'.$value.'%';
        }
        return null;
    }

    /** One indexed batch per visible page; null means storage unavailable. */
    private static function read(array $ids): ?array
    {
        global $wpdb;
        $ids = array_values(array_unique(array_filter(array_map('absint', $ids))));
        if (!$ids) { return []; }
        if (!Lifecycle::ready()) { return null; }
        $table = Schema::table('identities');
        $slots = implode(',', array_fill(0, count($ids), '%d'));
        $previous = $wpdb->suppress_errors(true);
        try {
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT user_id,mobile,verified_at FROM $table WHERE user_id IN ($slots)", ...$ids
            ), OBJECT_K);
            return $wpdb->last_error === '' ? $rows : null;
        } finally {
            $wpdb->suppress_errors($previous);
        }
    }

    private static function project(?array $rows, int $id): array
    {
        if ($id <= 0) { return ['mobile'=>'', 'verification'=>'—']; }
        if ($rows === null) {
            return ['mobile'=>'', 'verification'=>__('Unavailable','ach-wp-accounts')];
        }
        $identity = $rows[$id] ?? null;
        if (!$identity) {
            return ['mobile'=>'', 'verification'=>__('No mobile number','ach-wp-accounts')];
        }
        $verified = (int)$identity->verified_at;
        return [
            'mobile'=>Mobile::local($identity->mobile),
            'verification'=>$verified > 0
                ? sprintf(__('Verified — %s','ach-wp-accounts'), wp_date(get_option('date_format').' '.get_option('time_format'),$verified))
                : __('Pending mobile verification','ach-wp-accounts'),
        ];
    }

    public static function columns(array $columns): array
    {
        if (!self::usersAllowed()) { return $columns; }
        $columns[self::MOBILE] = __('Mobile number','ach-wp-accounts');
        $columns[self::VERIFIED] = __('Mobile verification','ach-wp-accounts');
        return $columns;
    }

    public static function cell(string $output, string $column, int $id): string
    {
        if (!self::usersAllowed() || !in_array($column,[self::MOBILE,self::VERIFIED],true)) {
            return $output;
        }
        global $wp_list_table;
        if (!self::$pageLoaded) {
            $ids = $wp_list_table instanceof \WP_Users_List_Table
                ? wp_list_pluck($wp_list_table->items,'ID') : [$id];
            self::$page = ['rows'=>self::read($ids), 'ids'=>array_map('intval',$ids)];
            self::$pageLoaded = true;
        }
        $rows = in_array($id,self::$page['ids'],true) ? self::$page['rows'] : self::read([$id]);
        $item = self::project($rows,$id);
        return $column === self::MOBILE
            ? '<bdi dir="ltr">'.esc_html($item['mobile'] ?: '—').'</bdi>'
            : esc_html($item['verification']);
    }

    public static function userArgs(array $args): array
    {
        if (self::usersAllowed() && Lifecycle::ready() && self::pattern((string)($args['search'] ?? '')) !== null) {
            $args['_ach_accounts_directory'] = true;
            // Core's user-query cache is not invalidated by identity updates.
            $args['cache_results'] = false;
        }
        return $args;
    }

    public static function userSearchColumns(array $columns, string $search, \WP_User_Query $query): array
    {
        if ($query->get('_ach_accounts_directory') && self::usersAllowed() && self::pattern($search) !== null) {
            $columns[] = self::ALIAS.'.mobile';
        }
        return $columns;
    }

    public static function userSearch(\WP_User_Query $query): void
    {
        global $wpdb;
        if (!$query->get('_ach_accounts_directory') || !self::usersAllowed()) { return; }
        $raw = trim((string)$query->get('search'));
        $search = trim($raw,'*');
        $pattern = self::pattern($search);
        if ($pattern === null) { return; }
        $column = self::ALIAS.'.mobile';
        $native = $wpdb->prepare("$column LIKE %s",
            (str_starts_with($raw,'*') ? '%' : '').$wpdb->esc_like($search).(str_ends_with($raw,'*') ? '%' : ''));
        $table = Schema::table('identities');
        // Unique user_id means the native count, role joins, limit and order
        // are unchanged. Replace only the additional native search predicate.
        $query->query_from .= " LEFT JOIN $table ".self::ALIAS." ON ".self::ALIAS.".user_id=$wpdb->users.ID";
        $query->query_where = str_replace($native,$wpdb->prepare("$column LIKE %s",$pattern),$query->query_where);
    }

    private static function isReport(\WP_REST_Request $request, bool $stats = true): bool
    {
        return $request->get_method() === 'GET' &&
            ($request->get_route() === '/wc-analytics/customers' || $request->get_route() === '/wc-analytics/reports/customers' ||
                ($stats && $request->get_route() === '/wc-analytics/reports/customers/stats'));
    }

    /**
     * The native Woo table's supported server-side search adapter supplies
     * an array of search terms. Extend only its two report GET schemas.
     * Existing string callers, suggestions and other REST routes stay native.
     */
    public static function reportSearchArgs(array $routes): array
    {
        foreach (['/wc-analytics/reports/customers','/wc-analytics/reports/customers/stats'] as $route) {
            foreach ($routes[$route] ?? [] as $index => $handler) {
                if (is_array($handler) && isset($handler['args']['search']) &&
                    in_array('GET', (array)($handler['methods'] ?? []), true)) {
                    $routes[$route][$index]['args']['search']['type'] = ['string','array'];
                    $routes[$route][$index]['args']['search']['items'] = ['type'=>'string'];
                } elseif (is_array($handler) && isset($handler['args']['search']) &&
                    !empty($handler['methods']['GET'])) {
                    $routes[$route][$index]['args']['search']['type'] = ['string','array'];
                    $routes[$route][$index]['args']['search']['items'] = ['type'=>'string'];
                }
            }
        }
        return $routes;
    }

    public static function beginReport(mixed $response, array $handler, \WP_REST_Request $request): mixed
    {
        if ($response === null && self::isReport($request) && current_user_can('view_woocommerce_reports')) {
            self::$reportRequest = $request;
            $raw = $request->get_param('search');
            self::$reportArray = is_array($raw);
            self::$reportTerms = is_array($raw) ? array_values(array_filter($raw,'is_string'))
                : (is_string($raw) && $raw !== '' ? [$raw] : []);
            if (is_array($raw)) {
                // The native datastore expects a scalar. Its actual WHERE
                // expression is extended below; preserve every search term.
                $request->set_param('search',implode(',',self::$reportTerms));
                if ($request->get_route() === '/wc-analytics/reports/customers') {
                    $request->set_param('searchby','all');
                }
            }
        }
        return $response;
    }

    public static function endReport(mixed $response, array $handler, \WP_REST_Request $request): mixed
    {
        if (self::$reportRequest === $request) {
            self::$reportRequest = null;
            self::$reportTerms = [];
            self::$reportArray = false;
        }
        return $response;
    }

    private static function extendedSearch(): bool
    {
        if (!self::$reportRequest) { return false; }
        if (self::$reportArray && self::$reportTerms) { return true; }
        return self::reportAllowed() && Lifecycle::ready() && self::pattern(self::$reportTerms[0] ?? '') !== null;
    }

    public static function reportCache(bool $enabled, string $key): bool
    {
        return in_array($key,['customers','customers_stats'],true) && self::extendedSearch() ? false : $enabled;
    }

    public static function reportSearch(array $clauses): array
    {
        global $wpdb;
        if (!self::extendedSearch()) { return $clauses; }
        $request = self::$reportRequest;
        $fields = ["CONCAT_WS( ' ', first_name, last_name, username, email )",
            "CONCAT_WS( ' ', first_name, last_name )",'email','username'];
        $table = Schema::table('identities');
        $lookup = $wpdb->prefix.'wc_customer_lookup';
        foreach ($fields as $field) {
            $native = $wpdb->prepare("$field LIKE %s",'%'.$wpdb->esc_like($request->get_param('search')).'%');
            $alternatives = [];
            foreach (self::$reportTerms as $term) {
                if ($term === '') { continue; }
                $searchField = self::$reportArray ? $fields[0] : $field;
                $name = $wpdb->prepare("$searchField LIKE %s",'%'.$wpdb->esc_like($term).'%');
                $pattern = self::reportAllowed() && Lifecycle::ready() ? self::pattern($term) : null;
                if ($pattern !== null) {
                    $phone = $wpdb->prepare("EXISTS (SELECT 1 FROM $table WHERE user_id=$lookup.user_id AND mobile LIKE %s)",$pattern);
                    $name = "($name OR $phone)";
                }
                $alternatives[] = $name;
            }
            if (!$alternatives) { continue; }
            $replacement = '('.implode(' OR ',$alternatives).')';
            foreach ($clauses as &$clause) {
                $clause = str_replace($native,$replacement,$clause);
            }
            unset($clause);
        }
        return $clauses;
    }

    public static function reportProjection(mixed $response, \WP_REST_Server $server, \WP_REST_Request $request): mixed
    {
        if ($request->get_route() !== '/wc-analytics/reports/customers' || !self::isReport($request,false) || !self::reportAllowed() || !$response instanceof \WP_REST_Response ||
            $response->get_status() !== 200 || $request->get_param('_fields')) { return $response; }
        $data = $response->get_data();
        if (!is_array($data) || !array_is_list($data)) { return $response; }
        $rows = self::read(wp_list_pluck($data,'user_id'));
        foreach ($data as &$item) {
            if (is_array($item)) { $item['ach_accounts_identity'] = self::project($rows,(int)($item['user_id'] ?? 0)); }
        }
        unset($item);
        $response->set_data($data);
        return $response;
    }

    public static function reportAssets(string $hook): void
    {
        if (!self::reportAllowed() || !function_exists('WC') || ($_GET['page'] ?? '') !== 'wc-admin' ||
            ($_GET['path'] ?? '') !== '/customers') { return; }
        $asset = dirname(__DIR__).'/assets/customer-directory.js';
        wp_enqueue_script('ach-accounts-customer-directory',plugins_url('assets/customer-directory.js',dirname(__DIR__).'/ach-wp-accounts.php'),
            ['wp-hooks','wp-element'],(string)filemtime($asset),false);
        wp_localize_script('ach-accounts-customer-directory','achAccountsDirectory',[
            'mobile'=>__('Mobile number','ach-wp-accounts'),
            'verification'=>__('Mobile verification','ach-wp-accounts'),
        ]);
    }
}
