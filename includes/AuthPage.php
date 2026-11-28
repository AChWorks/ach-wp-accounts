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
    private static ?string $commerceCredentialError = null;

    public static function hooks(): void
    {
        // Native WooCommerce endpoint handlers, including email confirmation,
        // must run before we decide whether to own the ordinary guest page.
        add_action('template_redirect', [self::class, 'commerce'], 20);
        add_action('login_form_login', [self::class, 'core']);
        add_action('login_form_register', [self::class, 'core']);
        add_action('wp_login_failed',[self::class,'recordCommerceCredentialError'],PHP_INT_MAX,2);
        add_filter('login_errors',[self::class,'commerceLoginError'],PHP_INT_MIN);
    }

    /** Observe the native error after authentication and all native security hooks.
     *  WC presents the first WP_Error message through login_errors. Only
     *  ordinary credential failures on the nonce-bound WC form are replaced.
     */
    public static function recordCommerceCredentialError(string $login, \WP_Error $error): void
    {
        self::$commerceCredentialError = null;
        if (!BrowserApi::enabled() || !isset($_POST['login'],$_POST['username'],$_POST['password']) ||
            !wp_verify_nonce(self::value($_REQUEST,'woocommerce-login-nonce') ?: self::value($_REQUEST,'_wpnonce'),'woocommerce-login')) {
            return;
        }
        if (in_array($error->get_error_code(),['invalid_username','invalid_email','incorrect_password'],true)) {
            self::$commerceCredentialError = $error->get_error_message();
        }
    }

    public static function commerceLoginError(string $message): string
    {
        $native = self::$commerceCredentialError;
        self::$commerceCredentialError = null;
        return $native!==null && $message===$native ? __('Invalid credentials.','ach-wp-accounts') : $message;
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
        return Capabilities::emailSignup($context);
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
        $cap=Capabilities::forContext($context);
        $forgot=$cap['email_reset'] && $cap['mobile_reset'] ? 'forgot' :
            ($cap['email_reset'] ? 'reset-email' : ($cap['mobile_reset'] ? 'reset-mobile' : 'mobile'));
        return match ($requested) {
            'password' => $cap['password_login'] ? 'password' : 'mobile',
            'email' => $cap['email_registration'] ? 'email' : 'mobile',
            'forgot' => $forgot,
            'reset-email' => $cap['email_reset'] ? 'reset-email' : $forgot,
            'reset-mobile' => $cap['mobile_reset'] ? 'reset-mobile' : $forgot,
            default => 'mobile',
        };
    }

    private static function destination(string $fallback = ''): string
    {
        return Frontend::localUrl(self::value($_GET,self::RETURN) ?: self::value($_REQUEST,'redirect_to'),
            $fallback ?: Frontend::defaultRedirect());
    }

    /** Only native workflows that do not render the ordinary guest login. */
    private static function specialEndpoint(string $endpoint): bool
    {
        if (in_array($endpoint,['customer-logout','order-pay','order-received'],true)) {
            return true;
        }
        // Newer WC versions have a standalone public withdrawal workflow.
        $controller = \Automattic\WooCommerce\Internal\OrderWithdrawal\OrderWithdrawalController::class;
        return class_exists($controller) && wc_get_container()->get($controller)->is_endpoint_request();
    }

    /** Recognize WC's native valid email-confirmation link, not arbitrary query flags. */
    private static function emailConfirmationUser(): ?\WP_User
    {
        $service = \Automattic\WooCommerce\Internal\CustomerEmailVerification\EmailVerificationService::class;
        $id = absint(self::value($_GET,'wc_verify_email_user'));
        $key = sanitize_text_field(self::value($_GET,'wc_verify_email_key'));
        if (!$id || $key === '' || !class_exists($service)) { return null; }
        $user = get_user_by('id',$id);
        return $user instanceof \WP_User && wc_get_container()->get($service)->check_verification_key($id,$key)
            ? $user : null;
    }

    public static function commerce(): void
    {
        if (!BrowserApi::enabled() || is_user_logged_in() || !self::commerceAvailable() ||
            !function_exists('is_account_page') || !is_account_page() ||
            is_feed() || is_embed() || wp_doing_ajax() || (defined('REST_REQUEST') && REST_REQUEST)) {
            return;
        }
        $endpoint = WC()->query->get_current_endpoint();
        if (self::specialEndpoint($endpoint)) { return; }
        if ($endpoint==='lost-password') {
            // WC retains reset-key/cookie/bridge processing and its actual
            // reset template. Only its ordinary lost-password presentation
            // is replaced by the shared shell.
            if (isset($_GET['key']) || isset($_GET['id'])) { return; }
            $method=isset($_GET['show-reset-form']) || isset($_GET['reset-link-sent']) ||
                isset($_POST['wc_reset_password']) ? 'native-reset' : 'forgot';
            self::document('account',self::destination(),$method);
            exit;
        }
        $notice='';
        $errors=new \WP_Error();
        if (($_SERVER['REQUEST_METHOD']??'GET')==='POST' && self::value($_POST,self::CORE_FORM)==='reset-email') {
            $notice=self::requestEmailReset('account',$errors);
            self::document('account',self::destination(),'reset-email',$errors,$notice);
            exit;
        }

        $confirmation = self::emailConfirmationUser();
        if ($confirmation) {
            // WC's guest form authenticates a password and may establish a
            // native login. If public password sign-in is disabled, first
            // authenticate through the existing mobile flow, then let WC
            // confirm the key and password in that authenticated session.
            // Never exempt the guest password check from NativeAccess.
            if (NativeAccess::passwordAllowed() || !AccountPolicy::ordinary($confirmation)) { return; }
            self::document('account',Frontend::currentUrl(),'mobile');
            exit;
        }

        // WC processes standard forms on wp_loaded. Its ordinary guest
        // account endpoints all render form-login; use the same standalone
        // presentation, retaining the actual local destination, not a slug.
        $fallback = $endpoint !== '' ? remove_query_arg([self::RETURN,self::METHOD,'redirect_to'],Frontend::currentUrl())
            : Frontend::defaultRedirect();
        self::document('account',self::destination($fallback),self::method('account',self::value($_GET,self::METHOD)));
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
            if ($posted !== $method || !in_array($method,['password','email','reset-email'],true) ||
                !wp_verify_nonce(self::value($_POST,'_ach_nonce'),'ach_accounts_public_'.$method) || !self::sameOriginPost()) {
                $errors->add('invalid_context',__('Please start again.', 'ach-wp-accounts'));
            } elseif ($method === 'password') {
                $user = wp_signon(['user_login'=>self::value($_POST,'log'),
                    'user_password'=>self::value($_POST,'pwd'),'remember'=>isset($_POST['rememberme'])],true);
                if (is_wp_error($user)) {
                    $errors = $user;
                    foreach (['invalid_username','invalid_email','incorrect_password'] as $code) {
                        if ($errors->get_error_message($code)!=='') {
                            $errors->remove($code);
                            $errors->add('invalid_credentials',__('Invalid credentials.','ach-wp-accounts'));
                        }
                    }
                }
                else {
                    wp_safe_redirect(Frontend::localUrl((string)apply_filters('login_redirect',$return,$return,$user),$return));
                    exit;
                }
            } elseif ($method==='reset-email') {
                $notice=self::requestEmailReset('wp',$errors);
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

    /** Native mail/key authority, generic public outcome and bounded requests. */
    private static function requestEmailReset(string $context,\WP_Error $errors): string
    {
        if (!Settings::flag('allow_email_password_reset') || !self::sameOriginPost() ||
            !wp_verify_nonce(self::value($_POST,'_ach_nonce'),'ach_accounts_public_reset-email')) {
            $errors->add('invalid_context',__('Please start again.','ach-wp-accounts'));
            return '';
        }
        $login=trim(self::value($_POST,'user_login'));
        if ($login==='' || strlen($login)>254) {
            $errors->add('invalid_input',__('Enter your email or username.','ach-wp-accounts'));
            return '';
        }
        try {
            $network=hash_hmac('sha256',(string)($_SERVER['REMOTE_ADDR']??''),wp_salt('auth'));
            AccountPolicy::reserveAccountAction('email-reset-network-'.$network,0,30);
            if ($context==='account' && class_exists('WC_Shortcode_My_Account')) {
                // Do not reveal whether a native account matched. Preserve
                // unrelated notices that existed before this operation.
                $before=wc_get_notices();
                try { \WC_Shortcode_My_Account::retrieve_password(); }
                finally { wc_set_notices($before); }
            } else { retrieve_password($login); }
        } catch (VerificationError $error) {
            $errors->add('rate_limited',__('Too many requests. Please try again later.','ach-wp-accounts'));
            return '';
        }
        return __('If the account is eligible for recovery, a password reset link will be sent.','ach-wp-accounts');
    }

    /** Public HTML is a separate document and never wraps a theme template. */
    public static function document(string $context,string $return,string $method,?\WP_Error $errors=null,string $notice=''): void
    {
        if (!defined('DONOTCACHEPAGE')) { define('DONOTCACHEPAGE',true); }
        nocache_headers();
        if (!headers_sent()) { header('Referrer-Policy: no-referrer',true); }
        if ($method!=='native-reset') { $method = self::method($context,$method); }
        $cap=Capabilities::forContext($context);
        Frontend::assets();
        $name = (string)get_bloginfo('name');
        $logo = Appearance::logo();
        $privacy = get_privacy_policy_url();
        $back = Frontend::localUrl($return,home_url('/'));
        if (untrailingslashit($back) === untrailingslashit(self::base($context)) ||
            ($context === 'account' && $back === Frontend::currentUrl())) { $back = home_url('/'); }
        $terms = $context === 'account' && wc_get_page_id('terms') > 0 ? wc_get_page_permalink('terms') : '';
        ?>
        <!doctype html>
        <html class="ach-auth-document" <?php language_attributes(); ?>>
        <head><meta charset="<?php bloginfo('charset'); ?>"><meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="robots" content="noindex, nofollow"><title><?php echo esc_html(sprintf(__('%s — Sign in','ach-wp-accounts'),$name)); ?></title>
        <?php
        if ($context === 'account') { wp_head(); }
        else {
            // Core login has its own assets; load registered global font
            // faces when core supports them, not the full active template.
            if (function_exists('wp_print_font_faces')) { wp_print_font_faces(); }
            wp_print_styles(['ach-wp-accounts']);
        }
        ?>
        </head>
        <body <?php if ($context==='account') { body_class('ach-auth-body'); } else { echo 'class="ach-auth-body"'; } ?>>
        <main class="ach-auth-page">
          <div class="ach-auth-shell" data-ach-auth-shell data-ach-return="<?php echo esc_url($return); ?>" data-ach-strings="<?php echo esc_attr(wp_json_encode(['checking'=>__('Checking…','ach-wp-accounts'),'network'=>__('Could not load this account method. Please try again.','ach-wp-accounts'),'native_uncertain'=>__('The request result is uncertain. Continue to check your account before trying again.','ach-wp-accounts'),'retry_status'=>__('Try again','ach-wp-accounts')])); ?>">
            <div class="ach-auth-brand">
              <a class="ach-auth-back"<?php if ($method!=='mobile' && $method!=='native-reset'): ?> data-ach-method<?php endif; ?> href="<?php echo esc_url($method==='mobile'||$method==='native-reset'?$back:self::url($context,$return)); ?>" aria-label="<?php esc_attr_e('Back','ach-wp-accounts'); ?>"><span aria-hidden="true">←</span></a>
              <a class="ach-auth-logo" href="<?php echo esc_url(home_url('/')); ?>">
                <?php echo ($logo && wp_attachment_is_image($logo) ? wp_get_attachment_image($logo,'medium',false,['class'=>'ach-auth-image','alt'=>$name]) : '') ?: esc_html($name); ?>
              </a>
            </div>
            <?php
            if ($context === 'account') { wc_print_notices(); }
            if ($errors && $errors->has_errors()) {
                echo '<div class="ach-auth-message" role="alert">';
                foreach ($errors->get_error_messages() as $message) { echo '<p>'.wp_kses_post($message).'</p>'; }
                echo '</div>';
            }
            if ($notice !== '') { echo '<p class="ach-auth-notice" role="status">'.esc_html($notice).'</p>'; }
            if ($method==='mobile' || $method==='reset-mobile') {
                echo Frontend::render($context,'login',$return,'','', $method==='reset-mobile'?'password_reset':'login');
            } elseif ($method==='forgot') {
                echo '<section class="ach-accounts"><h2>'.esc_html__('Reset password','ach-wp-accounts').'</h2>';
                echo '<p>'.esc_html__('Choose an available recovery method.','ach-wp-accounts').'</p>';
                foreach (['reset-mobile'=>__('Reset by mobile','ach-wp-accounts'),'reset-email'=>__('Reset by email','ach-wp-accounts')] as $key=>$label) {
                    printf('<a class="ach-accounts-native" data-ach-method href="%s">%s</a>',esc_url(self::url($context,$return,$key)),esc_html($label));
                }
                echo '</section>';
            } elseif ($method==='native-reset') {
                echo '<section class="ach-accounts ach-auth-native">';
                \WC_Shortcode_My_Account::lost_password();
                echo '</section>';
            } elseif ($method==='reset-email') {
                if ($notice==='') { self::emailResetForm($context,$return); }
                else {
                    echo '<section class="ach-accounts"><h2>'.esc_html__('Check your email','ach-wp-accounts').'</h2>';
                    printf('<a class="ach-accounts-native" data-ach-method href="%s">%s</a></section>',esc_url(self::url($context,$return,'reset-email')),esc_html__('Request another link','ach-wp-accounts'));
                }
            } else { self::nativeForm($context,$return,$method); }
            ?>
            <?php if ($method!=='mobile' || $cap['password_login'] || $cap['email_registration']): ?>
            <nav class="ach-auth-methods" aria-label="<?php esc_attr_e('Account access','ach-wp-accounts'); ?>">
            <?php if ($method !== 'mobile'): ?><a data-ach-method href="<?php echo esc_url(self::url($context,$return)); ?>"><?php esc_html_e('Sign in with mobile','ach-wp-accounts'); ?></a><?php endif; ?>
            <?php if ($method !== 'password' && $cap['password_login']): ?><a data-ach-method href="<?php echo esc_url(self::url($context,$return,'password')); ?>"><?php esc_html_e('Sign in with password','ach-wp-accounts'); ?></a><?php endif; ?>
            <?php if ($method !== 'email' && $cap['email_registration']): ?><a data-ach-method href="<?php echo esc_url(self::url($context,$return,'email')); ?>"><?php esc_html_e('Register with email','ach-wp-accounts'); ?></a><?php endif; ?>
            </nav>
            <?php endif; ?>
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

    private static function emailResetForm(string $context,string $return): void
    {
        $id=wp_unique_id('ach-reset-');
        ?>
        <section class="ach-accounts ach-auth-native">
            <h2><?php esc_html_e('Reset password by email','ach-wp-accounts'); ?></h2>
            <form method="post" data-ach-native-method="reset-email" data-ach-async="<?php echo apply_filters('ach_wp_accounts_async_native_forms',true,$context,'reset-email')?'1':'0'; ?>" action="<?php echo esc_url(self::url($context,$return,'reset-email')); ?>">
                <label for="<?php echo esc_attr($id); ?>"><?php esc_html_e('Email or username','ach-wp-accounts'); ?></label>
                <input id="<?php echo esc_attr($id); ?>" name="user_login" autocomplete="username" required>
                <?php do_action($context==='account'?'woocommerce_lostpassword_form':'lostpassword_form'); ?>
                <?php wp_nonce_field('ach_accounts_public_reset-email','_ach_nonce'); ?>
                <input type="hidden" name="<?php echo esc_attr(self::CORE_FORM); ?>" value="reset-email">
                <button type="submit" class="button ach-accounts-primary"><?php esc_html_e('Send password reset link','ach-wp-accounts'); ?></button>
            </form>
        </section>
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
        <h2><?php echo esc_html($register ? __('Register with email','ach-wp-accounts') : __('Sign in with password','ach-wp-accounts')); ?></h2>
        <form method="post" data-ach-native-method="<?php echo esc_attr($method); ?>" data-ach-async="<?php echo apply_filters('ach_wp_accounts_async_native_forms',true,$context,$method)?'1':'0'; ?>" action="<?php echo esc_url($target); ?>" class="<?php echo $commerce ? ($register ? 'woocommerce-form woocommerce-form-register register' : 'woocommerce-form woocommerce-form-login login') : 'ach-core-form'; ?>">
        <?php
        if ($commerce) { do_action($register ? 'woocommerce_register_form_start' : 'woocommerce_login_form_start'); }
        if ($username): ?>
        <label for="<?php echo esc_attr($id.'-user'); ?>"><?php echo esc_html($register || !Settings::flag('allow_email_login') ? __('Username','ach-wp-accounts') : __('Email or username','ach-wp-accounts')); ?></label>
        <input type="text" id="<?php echo esc_attr($id.'-user'); ?>" name="<?php echo esc_attr($commerce ? 'username' : ($register ? 'user_login' : 'log')); ?>" autocomplete="username" required>
        <?php endif; if ($register): ?>
        <label for="<?php echo esc_attr($id.'-email'); ?>"><?php esc_html_e('Email address','ach-wp-accounts'); ?></label>
        <input type="email" id="<?php echo esc_attr($id.'-email'); ?>" name="<?php echo $commerce ? 'email' : 'user_email'; ?>" autocomplete="email" dir="ltr" required>
        <?php endif; if ($password): ?>
        <label for="<?php echo esc_attr($id.'-password'); ?>"><?php esc_html_e('Password','ach-wp-accounts'); ?></label>
        <input type="password" id="<?php echo esc_attr($id.'-password'); ?>" name="<?php echo $commerce ? 'password' : 'pwd'; ?>" autocomplete="<?php echo $register ? 'new-password' : 'current-password'; ?>" required>
        <button type="button" class="ach-accounts-link" data-ach-password-visibility="<?php echo esc_attr($id.'-password'); ?>" aria-pressed="false"><?php esc_html_e('Show password','ach-wp-accounts'); ?></button>
        <?php endif; if (!$register): ?>
        <label class="ach-accounts-remember"><input type="checkbox" name="rememberme" value="forever"> <?php esc_html_e('Remember Me'); ?></label>
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
        <button class="button ach-accounts-primary" type="submit" name="<?php echo $register ? 'register' : 'login'; ?>" value="1"><?php echo esc_html($register ? __('Register','ach-wp-accounts') : __('Sign in','ach-wp-accounts')); ?></button>
        <?php if ($commerce) { do_action($register ? 'woocommerce_register_form_end' : 'woocommerce_login_form_end'); } ?>
        </form>
        <?php if (!$register && (Settings::flag('allow_email_password_reset') || Settings::flag('allow_mobile_password_reset'))): ?><p><a data-ach-method class="ach-accounts-native" href="<?php echo esc_url(self::url($context,$return,'forgot')); ?>"><?php esc_html_e('Forgot password?','ach-wp-accounts'); ?></a></p><?php endif; ?>
        </section>
        <?php
    }
}
