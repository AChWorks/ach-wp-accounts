<?php
namespace ACh\WPAccounts;

defined('ABSPATH') || exit;

/**
 * Own ordinary public sign-in/registration documents, not the theme's account
 * pages or privileged/special WordPress login actions. Native credentials and
 * registration remain native operations, behind the same presentation shell.
 */
final class AuthPage
{
    private const METHOD = 'ach_accounts_method';
    private const RETURN = 'ach_accounts_return';
    private const CORE_FORM = 'ach_accounts_native_form';

    public static function hooks(): void
    {
        add_action('template_redirect', [self::class, 'commerce'], 1);
        add_action('login_form_login', [self::class, 'core']);
        add_action('login_form_register', [self::class, 'core']);
    }

    private static function value(array $values, string $key): string
    {
        return is_string($values[$key] ?? null) ? wp_unslash($values[$key]) : '';
    }

    public static function commerceAvailable(): bool
    {
        return function_exists('wc_get_page_id') && wc_get_page_id('myaccount') > 0;
    }

    public static function emailRegistration(string $context): bool
    {
        return NativeAccess::emailAllowed() && ($context === 'account'
            ? get_option('woocommerce_enable_myaccount_registration') === 'yes'
            : !is_multisite() && (bool)get_option('users_can_register'));
    }

    private static function base(string $context): string
    {
        return $context === 'account' && self::commerceAvailable() ? wc_get_page_permalink('myaccount') : wp_login_url();
    }

    public static function url(string $context, string $return, string $method = 'mobile'): string
    {
        return add_query_arg([self::RETURN=>Frontend::localUrl($return, Frontend::defaultRedirect()),self::METHOD=>$method],self::base($context));
    }

    public static function secondaryUrl(string $return): string
    {
        $context = self::commerceAvailable() ? 'account' : 'wp';
        if (NativeAccess::passwordAllowed()) { return self::url($context,$return,'password'); }
        return self::emailRegistration($context) ? self::url($context,$return,'email') : '';
    }

    public static function method(string $context, string $requested): string
    {
        return match ($requested) {
            'password' => NativeAccess::passwordAllowed() ? 'password' : 'mobile',
            'email' => self::emailRegistration($context) ? 'email' : 'mobile',
            default => 'mobile',
        };
    }

    private static function destination(): string
    {
        return Frontend::localUrl(self::value($_GET,self::RETURN) ?: self::value($_REQUEST,'redirect_to'), Frontend::defaultRedirect());
    }

    public static function commerce(): void
    {
        if (!BrowserApi::enabled() || is_user_logged_in() || !self::commerceAvailable() ||
            !function_exists('is_account_page') || !is_account_page() || is_wc_endpoint_url() ||
            is_feed() || is_embed() || wp_doing_ajax() || (defined('REST_REQUEST') && REST_REQUEST)) {
            return;
        }
        // WooCommerce processes its standard forms on wp_loaded. On failure,
        // the same shell renders the native notices and the selected method.
        self::document('account',self::destination(),self::method('account',self::value($_GET,self::METHOD)));
        exit;
    }

    /** Special core actions and administrator reauthentication stay native. */
    public static function ordinaryCoreRequest(): bool
    {
        if (!BrowserApi::enabled() || is_user_logged_in() || is_multisite()) { return false; }
        foreach (['reauth','interim-login','checkemail','registration','loggedout','resetpass'] as $key) {
            if (isset($_REQUEST[$key])) { return false; }
        }
        $return = Frontend::localUrl(self::value($_REQUEST,'redirect_to'),'');
        $path = (string)wp_parse_url($return,PHP_URL_PATH);
        $admin = rtrim((string)wp_parse_url(admin_url(),PHP_URL_PATH),'/');
        if ($return !== '' && ($path === $admin || str_starts_with($path,$admin.'/'))) { return false; }
        return in_array($_SERVER['REQUEST_METHOD'] ?? 'GET',['GET','POST'],true);
    }

    private static function sameOriginPost(): bool
    {
        if (!is_ssl()) { return false; }
        // Form POST is not the AJAX OTP protocol. Require a same-origin
        // browser header in addition to the action nonce (including guests).
        $source = $_SERVER['HTTP_ORIGIN'] ?? $_SERVER['HTTP_REFERER'] ?? '';
        return is_string($source) && Frontend::localUrl($source,'') !== '';
    }

    public static function core(): void
    {
        if (!self::ordinaryCoreRequest()) { return; }
        global $action;
        $return = self::destination();
        $requested = self::value($_GET,self::METHOD) ?: ($action === 'register' ? 'email' : 'mobile');
        $method = self::method('wp',$requested);
        $errors = new \WP_Error();
        $notice = '';
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            // Native admin/core form POSTs not issued by our shell remain
            // native (with the same server-side customer policy).
            if (!isset($_POST[self::CORE_FORM])) { return; }
            $posted = self::value($_POST,self::CORE_FORM);
            $method = self::method('wp',$posted);
            if ($posted !== $method || !in_array($method,['password','email'],true) ||
                !wp_verify_nonce(self::value($_POST,'_ach_nonce'),'ach_accounts_public_'.$method) || !self::sameOriginPost()) {
                $errors->add('invalid_context',__('Please start again.', 'ach-wp-accounts'));
            } elseif ($method === 'password') {
                $user = wp_signon(['user_login'=>self::value($_POST,'log'),
                    'user_password'=>self::value($_POST,'pwd'),'remember'=>isset($_POST['rememberme'])],true);
                if (is_wp_error($user)) { $errors = $user; }
                else {
                    wp_safe_redirect(Frontend::localUrl((string)apply_filters('login_redirect',$return,$return,$user),$return));
                    exit;
                }
            } else {
                $result = register_new_user(self::value($_POST,'user_login'),self::value($_POST,'user_email'));
                if (is_wp_error($result)) { $errors = $result; }
                else {
                    $method = 'mobile';
                    $notice = __('Check your email to complete registration.', 'ach-wp-accounts');
                }
            }
        }
        self::document('wp',$return,$method,$errors,$notice);
        exit;
    }

    /** Public HTML is a separate document and never wraps a theme template. */
    public static function document(string $context,string $return,string $method,?\WP_Error $errors=null,string $notice=''): void
    {
        if (!defined('DONOTCACHEPAGE')) { define('DONOTCACHEPAGE',true); }
        nocache_headers();
        if (!headers_sent()) { header('Referrer-Policy: no-referrer',true); }
        $method = self::method($context,$method);
        Frontend::assets();
        $name = (string)get_bloginfo('name');
        $logo = (int)get_theme_mod('custom_logo');
        $privacy = get_privacy_policy_url();
        $back = Frontend::localUrl($return,home_url('/'));
        if (untrailingslashit($back) === untrailingslashit(self::base($context))) { $back = home_url('/'); }
        $terms = $context === 'account' && wc_get_page_id('terms') > 0 ? wc_get_page_permalink('terms') : '';
        ?>
        <!doctype html>
        <html <?php language_attributes(); ?>>
        <head><meta charset="<?php bloginfo('charset'); ?>"><meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="robots" content="noindex, nofollow"><title><?php echo esc_html(sprintf(__('%s — Sign in','ach-wp-accounts'),$name)); ?></title>
        <?php
        if ($context === 'account') { wp_head(); }
        else { wp_print_styles(['ach-wp-accounts']); }
        ?>
        </head>
        <body class="ach-auth-body">
        <main class="ach-auth-page">
          <div class="ach-auth-shell">
            <div class="ach-auth-brand">
              <a class="ach-auth-back" href="<?php echo esc_url($back); ?>" aria-label="<?php esc_attr_e('Back to site','ach-wp-accounts'); ?>"><span aria-hidden="true">←</span></a>
              <a class="ach-auth-logo" href="<?php echo esc_url(home_url('/')); ?>">
                <?php echo $logo ? wp_get_attachment_image($logo,'medium',false,['class'=>'ach-auth-image','alt'=>$name]) : esc_html($name); ?>
              </a>
            </div>
            <?php
            if ($context === 'account') { wc_print_notices(); }
            if ($errors && $errors->has_errors()) {
                echo '<div class="ach-auth-message" role="alert">';
                foreach ($errors->get_error_messages() as $message) { echo '<p>'.wp_kses_post($message).'</p>'; }
                echo '</div>';
            }
            if ($notice !== '') { echo '<p class="ach-auth-message" role="status">'.esc_html($notice).'</p>'; }
            if ($method === 'mobile') { echo Frontend::render($context,'login',$return,''); }
            else { self::nativeForm($context,$return,$method); }
            ?>
            <nav class="ach-auth-methods" aria-label="<?php esc_attr_e('Account access','ach-wp-accounts'); ?>">
            <?php if ($method !== 'mobile'): ?><a href="<?php echo esc_url(self::url($context,$return)); ?>"><?php esc_html_e('Sign in with mobile','ach-wp-accounts'); ?></a><?php endif; ?>
            <?php if ($method !== 'password' && NativeAccess::passwordAllowed()): ?><a href="<?php echo esc_url(self::url($context,$return,'password')); ?>"><?php esc_html_e('Sign in with email or username','ach-wp-accounts'); ?></a><?php endif; ?>
            <?php if ($method !== 'email' && self::emailRegistration($context)): ?><a href="<?php echo esc_url(self::url($context,$return,'email')); ?>"><?php esc_html_e('Register with email','ach-wp-accounts'); ?></a><?php endif; ?>
            </nav>
            <?php if ($privacy || $terms): ?>
              <div class="ach-auth-legal">
              <?php if ($terms): ?><a href="<?php echo esc_url($terms); ?>"><?php esc_html_e('Terms and conditions','ach-wp-accounts'); ?></a><?php endif; ?>
              <?php if ($privacy): ?><a href="<?php echo esc_url($privacy); ?>"><?php esc_html_e('Privacy policy','ach-wp-accounts'); ?></a><?php endif; ?>
              </div>
            <?php endif; ?>
          </div>
        </main>
        <?php if ($context === 'account') { wp_footer(); } else { wp_print_scripts(['ach-wp-accounts']); } ?>
        </body></html>
        <?php
    }

    /** Use actual WC form names, nonces and extension hooks, not duplicate WC handlers. */
    private static function nativeForm(string $context,string $return,string $method): void
    {
        $commerce = $context === 'account';
        $register = $method === 'email';
        $id = wp_unique_id('ach-native-');
        $username = $commerce ? (!$register || get_option('woocommerce_registration_generate_username') === 'no') : true;
        $password = !$register || ($commerce && get_option('woocommerce_registration_generate_password') === 'no');
        $target = self::url($context,$return,$method);
        ?>
        <section class="ach-accounts ach-auth-native">
        <h2><?php echo esc_html($register ? __('Register with email','ach-wp-accounts') : __('Sign in with email or username','ach-wp-accounts')); ?></h2>
        <form method="post" action="<?php echo esc_url($target); ?>" class="<?php echo $commerce ? ($register ? 'woocommerce-form woocommerce-form-register register' : 'woocommerce-form woocommerce-form-login login') : 'ach-core-form'; ?>">
        <?php
        if ($commerce) { do_action($register ? 'woocommerce_register_form_start' : 'woocommerce_login_form_start'); }
        if ($username): ?>
        <label for="<?php echo esc_attr($id.'-user'); ?>"><?php echo esc_html($register ? __('Username','ach-wp-accounts') : __('Email or username','ach-wp-accounts')); ?></label>
        <input type="text" id="<?php echo esc_attr($id.'-user'); ?>" name="<?php echo esc_attr($commerce ? 'username' : ($register ? 'user_login' : 'log')); ?>" autocomplete="username" required>
        <?php endif; if ($register): ?>
        <label for="<?php echo esc_attr($id.'-email'); ?>"><?php esc_html_e('Email address','ach-wp-accounts'); ?></label>
        <input type="email" id="<?php echo esc_attr($id.'-email'); ?>" name="<?php echo $commerce ? 'email' : 'user_email'; ?>" autocomplete="email" dir="ltr" required>
        <?php endif; if ($password): ?>
        <label for="<?php echo esc_attr($id.'-password'); ?>"><?php esc_html_e('Password','ach-wp-accounts'); ?></label>
        <input type="password" id="<?php echo esc_attr($id.'-password'); ?>" name="<?php echo $commerce ? 'password' : 'pwd'; ?>" autocomplete="<?php echo $register ? 'new-password' : 'current-password'; ?>" required>
        <?php endif;
        if ($commerce) {
            do_action($register ? 'woocommerce_register_form' : 'woocommerce_login_form');
            wp_nonce_field($register ? 'woocommerce-register' : 'woocommerce-login',$register ? 'woocommerce-register-nonce' : 'woocommerce-login-nonce');
            printf('<input type="hidden" name="redirect" value="%s">',esc_attr($return));
        } else {
            do_action($register ? 'register_form' : 'login_form');
            wp_nonce_field('ach_accounts_public_'.$method,'_ach_nonce');
            printf('<input type="hidden" name="%s" value="%s">',esc_attr(self::CORE_FORM),esc_attr($method));
        }
        ?>
        <button class="ach-accounts-primary" type="submit" name="<?php echo $register ? 'register' : 'login'; ?>" value="1"><?php echo esc_html($register ? __('Register','ach-wp-accounts') : __('Sign in','ach-wp-accounts')); ?></button>
        <?php if ($commerce) { do_action($register ? 'woocommerce_register_form_end' : 'woocommerce_login_form_end'); } ?>
        </form>
        <?php if (!$register): ?><p><a class="ach-accounts-native" href="<?php echo esc_url($commerce ? wc_lostpassword_url() : wp_lostpassword_url()); ?>"><?php esc_html_e('Forgot password?','ach-wp-accounts'); ?></a></p><?php endif; ?>
        </section>
        <?php
    }
}
