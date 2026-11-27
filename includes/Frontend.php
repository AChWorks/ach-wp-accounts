<?php
namespace ACh\WPAccounts;

defined('ABSPATH') || exit;

/** One presentation adapter for native login, account, checkout and shortcodes. */
final class Frontend
{
    private const NATIVE = 'ach_accounts_native';
    private const RETURN = 'ach_accounts_return';

    public static function hooks(): void
    {
        add_action('init', [self::class, 'register']);
        add_action('login_enqueue_scripts', [self::class, 'loginAssets']);
        add_filter('login_message', [self::class, 'loginMessage']);
        add_action('wp_enqueue_scripts', [self::class, 'pageAssets']);
        add_action('admin_enqueue_scripts', static function (string $hook): void {
            if ($hook === 'profile.php' && BrowserApi::enabled()) {
                self::assets();
            }
        });
        add_action('admin_footer-profile.php', [self::class, 'profile']);
        add_action('template_redirect', [self::class, 'protectCheckout'], 0);
        add_action('woocommerce_before_customer_login_form', [self::class, 'myAccount']);
        add_action('woocommerce_before_edit_account_form', [self::class, 'profile']);
        add_action('woocommerce_login_form_end', [self::class, 'nativeReturn']);
        add_action('woocommerce_register_form_end', [self::class, 'nativeReturn']);
        add_filter('pre_do_shortcode_tag', [self::class, 'checkoutShortcode'], 10, 4);
        add_filter('pre_render_block', [self::class, 'checkoutBlock'], 10, 2);
    }

    public static function register(): void
    {
        add_shortcode('ach_wp_accounts', [self::class, 'shortcode']);
        load_plugin_textdomain('ach-wp-accounts', false, 'ach-wp-accounts/languages');
        $base = plugin_dir_url(dirname(__DIR__) . '/ach-wp-accounts.php');
        wp_register_style('ach-wp-accounts', $base . 'assets/accounts.css', [], (string) filemtime(dirname(__DIR__) . '/assets/accounts.css'));
        wp_register_script('ach-wp-accounts', $base . 'assets/accounts.js', [], (string) filemtime(dirname(__DIR__) . '/assets/accounts.js'), true);
    }

    private static function assets(): void
    {
        wp_enqueue_style('ach-wp-accounts');
        wp_enqueue_script('ach-wp-accounts');
    }

    public static function nativeRequested(): bool
    {
        return isset($_GET[self::NATIVE]) && $_GET[self::NATIVE] === '1';
    }

    private static function accountUrl(): string
    {
        if (function_exists('wc_get_page_id') && wc_get_page_id('myaccount') > 0) {
            return wc_get_page_permalink('myaccount');
        }
        return wp_login_url();
    }

    private static function defaultRedirect(): string
    {
        return function_exists('wc_get_page_id') && wc_get_page_id('myaccount') > 0
            ? wc_get_page_permalink('myaccount') : home_url('/');
    }

    /** Accept same-origin absolute URLs or local absolute paths, never external destinations. */
    public static function localUrl(string $url, string $fallback = ''): string
    {
        $home = wp_parse_url(home_url('/'));
        if (!is_array($home) || !isset($home['scheme'], $home['host'])) {
            return $fallback;
        }
        $origin = $home['scheme'] . '://' . $home['host'] . (isset($home['port']) ? ':' . $home['port'] : '');
        if (str_starts_with($url, '/') && !str_starts_with($url, '//')) {
            $url = $origin . $url;
        }
        $safe = wp_validate_redirect($url, '');
        if ($safe === '') {
            return $fallback;
        }
        $parts = wp_parse_url($safe);
        if (!is_array($parts) || isset($parts['user']) || isset($parts['pass']) ||
            strcasecmp($parts['scheme'] ?? '', $home['scheme']) !== 0 ||
            strcasecmp($parts['host'] ?? '', $home['host']) !== 0 ||
            (int) ($parts['port'] ?? ($home['scheme'] === 'https' ? 443 : 80)) !==
                (int) ($home['port'] ?? ($home['scheme'] === 'https' ? 443 : 80))) {
            return $fallback;
        }
        return $safe;
    }

    private static function currentUrl(): string
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        return self::localUrl(is_string($uri) ? wp_unslash($uri) : '', self::defaultRedirect());
    }

    private static function redirectParam(string $key, string $fallback): string
    {
        $value = $_GET[$key] ?? '';
        return self::localUrl(is_string($value) ? wp_unslash($value) : '', $fallback);
    }

    private static function normalLogin(): bool
    {
        global $action, $interim_login;
        // Keep native recovery, reauth, post-password, application-password,
        // admin confirmation and failed native credential attempts untouched.
        return BrowserApi::enabled() && !self::nativeRequested() &&
            ($action ?? 'login') === 'login' && !$interim_login &&
            empty($_REQUEST['reauth']) && empty($_REQUEST['interim-login']) &&
            empty($_GET['checkemail']) && empty($_GET['registration']) &&
            ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' &&
            !str_contains(self::redirectParam('redirect_to', ''), 'authorize-application.php');
    }

    public static function loginAssets(): void
    {
        if (self::normalLogin()) {
            if (str_contains(self::redirectParam('redirect_to', ''), 'newuseremail=')) {
                nocache_headers();
                if (!headers_sent()) {
                    header('Referrer-Policy: no-referrer', true);
                }
            }
            self::assets();
        }
    }

    public static function loginMessage(string $message): string
    {
        if (!self::normalLogin()) {
            return $message;
        }
        $return = self::redirectParam('redirect_to', self::defaultRedirect());
        $native = add_query_arg(self::NATIVE, '1', wp_login_url($return));
        return $message . self::render('wp', 'login', $return, $native, 'core');
    }

    public static function pageAssets(): void
    {
        if (!BrowserApi::enabled() && !CheckoutGate::enabled()) {
            return;
        }
        $post = get_post();
        $relevant = (function_exists('is_account_page') && is_account_page()) ||
            (function_exists('is_checkout') && is_checkout()) ||
            ($post instanceof \WP_Post && has_shortcode($post->post_content, 'ach_wp_accounts'));
        if ($relevant) {
            self::assets();
        }
    }

    private static function nativeAccountUrl(string $return): string
    {
        if (function_exists('wc_get_page_id') && wc_get_page_id('myaccount') > 0 &&
            (get_option('woocommerce_enable_myaccount_registration') === 'yes' || !get_option('users_can_register'))) {
            return add_query_arg([self::NATIVE => '1', self::RETURN => $return], self::accountUrl());
        }
        return add_query_arg(self::NATIVE, '1', wp_login_url($return));
    }

    public static function nativeReturn(): void
    {
        if (!self::nativeRequested()) {
            return;
        }
        $return = self::redirectParam(self::RETURN, '');
        if ($return !== '') {
            // Native WC handlers read this field, validate and apply their own
            // redirect filters after actual login or registration.
            printf('<input type="hidden" name="redirect" value="%s">', esc_attr($return));
        }
    }

    public static function myAccount(): void
    {
        if (BrowserApi::enabled() && !self::nativeRequested() && !is_user_logged_in() &&
            ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
            $return = self::redirectParam(self::RETURN, self::defaultRedirect());
            echo self::render('account', 'login', $return, self::nativeAccountUrl($return), 'commerce');
        }
    }

    public static function profile(): void
    {
        if (BrowserApi::enabled() && is_user_logged_in()) {
            echo self::render('account', 'profile', self::currentUrl(), '');
        }
    }

    /** [ach_wp_accounts] or [ach_wp_accounts display="popup"], registration follows site policy. */
    public static function shortcode(mixed $attrs = []): string
    {
        if (!BrowserApi::enabled()) {
            return '';
        }
        $attrs = shortcode_atts(['display' => 'inline'], is_array($attrs) ? $attrs : [], 'ach_wp_accounts');
        $context = function_exists('wc_get_page_id') && wc_get_page_id('myaccount') > 0 ? 'account' : 'wp';
        $return = self::currentUrl();
        $form = self::render($context, 'login', $return, self::nativeAccountUrl($return));
        if ($attrs['display'] !== 'popup') {
            return $form;
        }
        return '<div class="ach-accounts-popup"><button type="button" class="button" data-ach-popup-open>' .
            esc_html__('Sign in', 'ach-wp-accounts') . '</button><dialog class="ach-accounts-dialog" aria-label="' .
            esc_attr__('Sign in', 'ach-wp-accounts') . '"><button type="button" class="ach-accounts-link" data-ach-popup-close>' .
            esc_html__('Close', 'ach-wp-accounts') . '</button>' . $form . '</dialog></div>';
    }

    public static function checkoutSurface(): bool
    {
        global $wp;
        if (isset($wp->query_vars['order-received'])) {
            return false;
        }
        if (!CheckoutGate::enabled() || !function_exists('is_checkout') || is_admin() ||
            (defined('REST_REQUEST') && REST_REQUEST) || !is_checkout() || is_order_received_page()) {
            return false;
        }
        // Preserve Woo's old receipt URLs; actual payment attempts remain
        // guarded at the server boundary even on a legacy pay link.
        if (isset($_GET['order'], $_GET['key']) && !is_checkout_pay_page()) {
            return false;
        }
        return is_checkout_pay_page() || (WC()->cart && !WC()->cart->is_empty());
    }

    public static function protectCheckout(): void
    {
        if (self::checkoutSurface() || (self::nativeRequested() && self::redirectParam(self::RETURN, '') !== '')) {
            // Qualified and unqualified HTML differ. Do not allow a shared
            // page cache to store either version or an order key.
            if (!defined('DONOTCACHEPAGE')) {
                define('DONOTCACHEPAGE', true);
            }
            nocache_headers();
            if (!headers_sent()) {
                header('Referrer-Policy: no-referrer', true);
            }
        }
    }

    private static function checkoutForm(): string
    {
        $return = self::currentUrl();
        return self::render('checkout', 'checkout', $return, self::nativeAccountUrl($return));
    }

    public static function checkoutShortcode(mixed $pre, string $tag, mixed $attrs, array $match): mixed
    {
        return $pre === false && $tag === 'woocommerce_checkout' && self::checkoutSurface() && CheckoutGate::blocked()
            ? self::checkoutForm() : $pre;
    }

    public static function checkoutBlock(mixed $pre, array $block): mixed
    {
        $name = $block['blockName'] ?? '';
        $checkout = $name === 'woocommerce/checkout' ||
            ($name === 'woocommerce/classic-shortcode' && ($block['attrs']['shortcode'] ?? '') === 'checkout');
        return $pre === null && $checkout && self::checkoutSurface() && CheckoutGate::blocked()
            ? self::checkoutForm() : $pre;
    }

    /** Static form shell: no account identity, nonce, OTP or proof in cacheable HTML. */
    public static function render(string $context, string $surface, string $return, string $native, string $nativeTarget = ''): string
    {
        self::assets();
        $enabled = BrowserApi::enabled();
        $strings = [
            'login' => __('Sign in', 'ach-wp-accounts'),
            'register' => __('Sign in or register', 'ach-wp-accounts'),
            'phone' => __('Enter your mobile number.', 'ach-wp-accounts'),
            'link' => __('Verify a mobile number for your current account.', 'ach-wp-accounts'),
            'step' => __('Verify your registered mobile number.', 'ach-wp-accounts'),
            'change' => __('Change your mobile number.', 'ach-wp-accounts'),
            'verified' => __('Your mobile number is verified.', 'ach-wp-accounts'),
            'signed_in' => __('You are signed in.', 'ach-wp-accounts'),
            'sent' => __('Enter the code sent to your mobile number.', 'ach-wp-accounts'),
            'uncertain' => __('The message may have been sent. Enter the code if you receive it.', 'ach-wp-accounts'),
            'failed' => __('The code could not be sent. Please try again.', 'ach-wp-accounts'),
            'network' => __('Could not reach the verification service. Please try again.', 'ach-wp-accounts'),
            'unavailable' => __('Mobile verification is temporarily unavailable.', 'ach-wp-accounts'),
            'retry' => __('Retry request', 'ach-wp-accounts'),
            'send' => __('Get verification code', 'ach-wp-accounts'),
            'continue' => __('Verify and continue', 'ach-wp-accounts'),
            'resend' => __('Send a new code', 'ach-wp-accounts'),
            'expired' => __('The code has expired. Request a new one.', 'ach-wp-accounts'),
            'success' => __('Verified. Continuing…', 'ach-wp-accounts'),
            'password' => __('Current password', 'ach-wp-accounts'),
            'password_change' => __('Use your current password to change the number', 'ach-wp-accounts'),
            'change_mobile' => __('Change mobile number', 'ach-wp-accounts'),
            'edit' => __('Edit number', 'ach-wp-accounts'),
            'retry_status' => __('Try again', 'ach-wp-accounts'),
            'email_requested' => __('Check your email for the confirmation link. Your account email has not changed yet.', 'ach-wp-accounts'),
            'email_confirmed' => __('This email is already confirmed.', 'ach-wp-accounts'),
            'password_saved' => __('Your password was saved.', 'ach-wp-accounts'),
            'password_mismatch' => __('Passwords do not match.', 'ach-wp-accounts'),
            'manage_email' => __('Use the optional recovery options to add or change your email.', 'ach-wp-accounts'),
            'continue_checkout' => __('Continue checkout', 'ach-wp-accounts'),
        ];
        $id = wp_unique_id('ach-accounts-');
        $title = $surface === 'checkout' ? __('Verify your mobile to continue checkout', 'ach-wp-accounts')
            : ($surface === 'profile' ? __('Mobile number', 'ach-wp-accounts') : __('Sign in', 'ach-wp-accounts'));
        $nativeLabel = (($context === 'wp' && get_option('users_can_register')) ||
            ($context !== 'wp' && (get_option('woocommerce_enable_myaccount_registration') === 'yes' || get_option('users_can_register'))))
            ? __('Sign in or register with email', 'ach-wp-accounts')
            : __('Sign in with email or username', 'ach-wp-accounts');
        ob_start();
        ?>
        <section class="ach-accounts" data-ach-accounts data-context="<?php echo esc_attr($context); ?>"
            data-surface="<?php echo esc_attr($surface); ?>" data-endpoint="<?php echo esc_url(admin_url('admin-ajax.php', 'https')); ?>"
            data-return="<?php echo esc_url(self::localUrl($return, self::defaultRedirect())); ?>"
            data-native-target="<?php echo esc_attr($nativeTarget); ?>"
            data-enabled="<?php echo $enabled ? '1' : '0'; ?>"
            data-strings="<?php echo esc_attr(wp_json_encode($strings)); ?>" aria-labelledby="<?php echo esc_attr($id . '-title'); ?>">
            <h2 id="<?php echo esc_attr($id . '-title'); ?>" data-ach-title><?php echo esc_html($title); ?></h2>
            <p data-ach-description aria-live="polite"><?php echo esc_html($surface === 'checkout' ? CheckoutGate::message() : __('Use your mobile number to access your account.', 'ach-wp-accounts')); ?></p>
            <p data-ach-current hidden><bdi dir="ltr"></bdi></p>
            <p data-ach-status role="status" aria-live="polite"></p>
            <?php if (!$enabled): ?>
                <p><?php echo esc_html($strings['unavailable']); ?></p>
            <?php endif; ?>
            <form method="post" data-ach-form hidden>
                <div data-ach-mobile-field>
                    <label for="<?php echo esc_attr($id . '-mobile'); ?>"><?php esc_html_e('Mobile number', 'ach-wp-accounts'); ?></label>
                    <input id="<?php echo esc_attr($id . '-mobile'); ?>" type="tel" inputmode="tel" autocomplete="tel" dir="ltr" data-ach-mobile>
                </div>
                <div data-ach-password-field hidden>
                    <label for="<?php echo esc_attr($id . '-password'); ?>"><?php echo esc_html($strings['password']); ?></label>
                    <input id="<?php echo esc_attr($id . '-password'); ?>" type="password" autocomplete="current-password" data-ach-password disabled>
                </div>
                <div data-ach-code-field hidden>
                    <label for="<?php echo esc_attr($id . '-code'); ?>"><?php esc_html_e('Verification code', 'ach-wp-accounts'); ?></label>
                    <input id="<?php echo esc_attr($id . '-code'); ?>" type="text" inputmode="numeric" autocomplete="one-time-code" dir="ltr" maxlength="6" data-ach-code disabled>
                    <p><bdi dir="ltr" data-ach-masked></bdi></p>
                </div>
                <button type="submit" class="button ach-accounts-primary" data-ach-submit><?php echo esc_html($strings['send']); ?></button>
                <div class="ach-accounts-actions">
                    <button type="button" class="ach-accounts-link" data-ach-edit hidden><?php echo esc_html($strings['edit']); ?></button>
                    <button type="button" class="ach-accounts-link" data-ach-resend hidden><?php echo esc_html($strings['resend']); ?></button>
                </div>
            </form>
            <button type="button" class="ach-accounts-link" data-ach-change hidden><?php echo esc_html($strings['change_mobile']); ?></button>
            <button type="button" class="ach-accounts-link" data-ach-password-change hidden><?php echo esc_html($strings['password_change']); ?></button>
            <button type="button" class="ach-accounts-link" data-ach-retry hidden><?php echo esc_html($strings['retry_status']); ?></button>
            <?php if ($native !== ''): ?>
                <a class="ach-accounts-native" data-ach-native-link href="<?php echo esc_url($native); ?>"><?php echo esc_html($nativeLabel); ?></a>
                <?php if ($surface === 'checkout' && function_exists('wc_get_page_id') && wc_get_page_id('myaccount') > 0): ?>
                    <a class="ach-accounts-native" data-ach-profile-link hidden href="<?php echo esc_url(wc_get_account_endpoint_url('edit-account')); ?>"><?php esc_html_e('Manage your mobile number', 'ach-wp-accounts'); ?></a>
                <?php endif; ?>
            <?php endif; ?>
            <?php if ($surface === 'profile'): ?>
                <details class="ach-accounts-recovery" data-ach-recovery hidden>
                    <summary><?php esc_html_e('Optional recovery options', 'ach-wp-accounts'); ?></summary>
                    <p><?php esc_html_e('Your mobile sign-in remains available. Add an email or password only if you want another way to access your account.', 'ach-wp-accounts'); ?></p>
                    <form method="post" data-ach-recovery-email>
                        <label for="<?php echo esc_attr($id . '-email'); ?>"><?php esc_html_e('Recovery email', 'ach-wp-accounts'); ?></label>
                        <input id="<?php echo esc_attr($id . '-email'); ?>" type="email" autocomplete="email" data-ach-email required>
                        <button type="submit" class="button"><?php esc_html_e('Send confirmation link', 'ach-wp-accounts'); ?></button>
                    </form>
                    <form method="post" data-ach-recovery-password>
                        <p><?php esc_html_e('Username for native sign-in:', 'ach-wp-accounts'); ?> <bdi data-ach-username dir="ltr"></bdi></p>
                        <label for="<?php echo esc_attr($id . '-new-password'); ?>"><?php esc_html_e('New password', 'ach-wp-accounts'); ?></label>
                        <input id="<?php echo esc_attr($id . '-new-password'); ?>" type="password" autocomplete="new-password" data-ach-new-password minlength="15" required>
                        <label for="<?php echo esc_attr($id . '-confirm-password'); ?>"><?php esc_html_e('Confirm new password', 'ach-wp-accounts'); ?></label>
                        <input id="<?php echo esc_attr($id . '-confirm-password'); ?>" type="password" autocomplete="new-password" data-ach-confirm-password required>
                        <p><?php esc_html_e('Use at least 15 characters. No specific mix of characters is required.', 'ach-wp-accounts'); ?></p>
                        <button type="submit" class="button"><?php esc_html_e('Save password', 'ach-wp-accounts'); ?></button>
                    </form>
                    <p data-ach-recovery-status role="status" aria-live="polite"></p>
                </details>
            <?php endif; ?>
            <noscript><p><?php esc_html_e('Enable JavaScript to verify your mobile number. Native sign-in remains available where permitted.', 'ach-wp-accounts'); ?></p></noscript>
        </section>
        <?php
        return (string) ob_get_clean();
    }
}
