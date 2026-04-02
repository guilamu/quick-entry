<?php
/**
 * Frontend Class - Handles frontend verification page
 */

if (!defined('ABSPATH')) {
    exit;
}

class QENTRY_Frontend {
    
    public static function init() {
        add_action('template_redirect', array(__CLASS__, 'handle_qentry'));
        add_action('wp_enqueue_scripts', array(__CLASS__, 'enqueue_scripts'));
        add_action('wp_ajax_qentry_verify_code', array(__CLASS__, 'ajax_verify_code'));
        add_action('wp_ajax_nopriv_qentry_verify_code', array(__CLASS__, 'ajax_verify_code'));
        add_action('wp_ajax_qentry_send_code', array(__CLASS__, 'ajax_send_code'));
        add_action('wp_ajax_nopriv_qentry_send_code', array(__CLASS__, 'ajax_send_code'));
    }
    
    /**
     * Handle temporary login URL
     */
    public static function handle_qentry() {
        if (!isset($_GET['qentry'])) {
            return;
        }
        
        $token = sanitize_text_field($_GET['qentry']);
        $entry = QENTRY_Database::get_by_token($token);
        
        if (!$entry) {
            wp_die(
                __('This temporary login link is invalid.', 'quick-entry'),
                __('Invalid Link - QuickEntry', 'quick-entry'),
                array('response' => 404, 'back_link' => true)
            );
        }
        
        if (strtotime($entry->expires_at) < time()) {
            wp_die(
                __('This temporary login link has expired.', 'quick-entry'),
                __('Link Expired - QuickEntry', 'quick-entry'),
                array('response' => 410, 'back_link' => true)
            );
        }
        
        $is_max_uses_reached = $entry->use_count >= $entry->max_uses;
        if ($is_max_uses_reached) {
            wp_die(
                __('This temporary login link has reached its maximum number of uses.', 'quick-entry'),
                __('Link No Longer Available - QuickEntry', 'quick-entry'),
                array('response' => 410, 'back_link' => true)
            );
        }
        
        self::render_verification_page($entry);
        exit;
    }
    
    /**
     * Render verification page
     */
    private static function render_verification_page($entry) {
        ob_start();
        ?>
        <!DOCTYPE html>
        <html <?php language_attributes(); ?>>
        <head>
            <meta charset="<?php bloginfo('charset'); ?>">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title><?php echo esc_html(get_bloginfo('name')); ?> &rsaquo; <?php _e('Verification Code', 'quick-entry'); ?></title>
            <?php wp_head(); ?>
        </head>
        <body class="qentry-verify-body login">
            <?php
            if (!isset($_GET['code_sent'])) {
                $new_code = str_pad(rand(0, 999999), 6, '0', STR_PAD_LEFT);
                QENTRY_Database::update_verification_code($entry->id, $new_code);
                QENTRY_Email::send_verification_code($entry->email, $new_code);
            }
            ?>
            <div class="qentry-verify-container">
                <h1 class="screen-reader-text"><?php _e('Verify Your Identity', 'quick-entry'); ?></h1>
                <div class="qentry-verify-box">
                <div class="qentry-verify-card">
                    <div class="qentry-verify-header">
                        <a href="<?php echo esc_url(home_url('/')); ?>" title="<?php esc_attr_e('Powered by WordPress', 'quick-entry'); ?>">
                            <?php if (has_site_icon()) : ?>
                                <img src="<?php echo esc_url(get_site_icon_url()); ?>" alt="<?php echo esc_attr(get_bloginfo('name')); ?>" class="qentry-site-logo" width="84" height="84">
                            <?php else : ?>
                                <img src="<?php echo esc_url(admin_url('images/wordpress-logo.svg?ver=20131107')); ?>" alt="<?php esc_attr_e('Powered by WordPress', 'quick-entry'); ?>" class="qentry-site-logo" width="84" height="84">
                            <?php endif; ?>
                        </a>
                    </div>
                    
                    <p class="qentry-verify-subtitle" style="text-align:center;margin-bottom:20px;"><?php printf(__('A 6-digit verification code has been sent to <strong>%s</strong>', 'quick-entry'), esc_html($entry->email)); ?></p>
                    
                    <form id="qentry-verify-form" class="qentry-verify-form">
                        <input type="hidden" name="qentry_token" value="<?php echo esc_attr($entry->token); ?>">
                        
                        <div class="qentry-form-group">
                            <label for="qentry-verification-code"><?php _e('Verification Code', 'quick-entry'); ?></label>
                            <input type="text" id="qentry-verification-code" name="qentry_code" class="qentry-code-input" maxlength="6" pattern="[0-9]{6}" placeholder="0 0 0 0 0 0" required inputmode="numeric" autocomplete="one-time-code">
                        </div>
                        
                        <div id="qentry-verify-message" class="qentry-message" style="display:none;"></div>
                        
                        <p class="submit">
                            <button type="submit" class="qentry-verify-btn" id="qentry-verify-submit">
                                <span class="qentry-btn-text"><?php _e('Verify & Login', 'quick-entry'); ?></span>
                                <span class="qentry-btn-loading" style="display:none;">
                                    <span class="qentry-spinner"></span>
                                    <?php _e('Verifying...', 'quick-entry'); ?>
                                </span>
                            </button>
                        </p>
                    </form>
                    
                    <div class="qentry-verify-footer">
                        <p style="margin:0 0 8px;">
                            <button type="button" id="qentry-resend-code" class="qentry-resend-link">
                                <?php _e('Resend Code', 'quick-entry'); ?>
                            </button>
                        </p>
                        <p class="qentry-help-text"><?php _e('Check your spam folder if you don\'t see the email.', 'quick-entry'); ?></p>
                    </div>
                </div>
                <p class="qentry-back-link">
                    <a href="<?php echo esc_url(wp_login_url()); ?>"><?php _e('&larr; Go to login', 'quick-entry'); ?></a>
                </p>
                </div>
            </div>
            
            <script>
                document.addEventListener('DOMContentLoaded', function() {
                    const form = document.getElementById('qentry-verify-form');
                    const codeInput = document.getElementById('qentry-verification-code');
                    const messageDiv = document.getElementById('qentry-verify-message');
                    const submitBtn = document.getElementById('qentry-verify-submit');
                    const btnText = submitBtn.querySelector('.qentry-btn-text');
                    const btnLoading = submitBtn.querySelector('.qentry-btn-loading');
                    const resendBtn = document.getElementById('qentry-resend-code');
                    
                    codeInput.addEventListener('input', function() {
                        this.value = this.value.replace(/[^0-9]/g, '');
                        if (this.value.length === 6) {
                            submitForm();
                        }
                    });
                    
                    form.addEventListener('submit', function(e) {
                        e.preventDefault();
                        submitForm();
                    });
                    
                    function submitForm() {
                        const code = codeInput.value;
                        if (code.length !== 6) return;
                        
                        btnText.style.display = 'none';
                        btnLoading.style.display = 'inline';
                        submitBtn.disabled = true;
                        messageDiv.style.display = 'none';
                        
                        fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                            body: new URLSearchParams({
                                action: 'qentry_verify_code',
                                nonce: '<?php echo wp_create_nonce('qentry_verify_nonce'); ?>',
                                qentry_code: code,
                                qentry_token: '<?php echo esc_js($entry->token); ?>'
                            })
                        })
                        .then(response => response.json())
                        .then(data => {
                            if (data.success) {
                                messageDiv.className = 'qentry-message qentry-message-success';
                                messageDiv.innerHTML = '<?php _e('Verification successful! Redirecting...', 'quick-entry'); ?>';
                                messageDiv.style.display = 'block';
                                setTimeout(() => { window.location.href = data.data.redirect_url; }, 1500);
                            } else {
                                messageDiv.className = 'qentry-message qentry-message-error';
                                messageDiv.innerHTML = data.data.message || '<?php _e('Invalid verification code. Please try again.', 'quick-entry'); ?>';
                                messageDiv.style.display = 'block';
                                codeInput.value = '';
                                codeInput.focus();
                                btnText.style.display = 'inline';
                                btnLoading.style.display = 'none';
                                submitBtn.disabled = false;
                            }
                        })
                        .catch(() => {
                            messageDiv.className = 'qentry-message qentry-message-error';
                            messageDiv.innerHTML = '<?php _e('An error occurred. Please try again.', 'quick-entry'); ?>';
                            messageDiv.style.display = 'block';
                            btnText.style.display = 'inline';
                            btnLoading.style.display = 'none';
                            submitBtn.disabled = false;
                        });
                    }
                    
                    resendBtn.addEventListener('click', function() {
                        this.disabled = true;
                        this.textContent = '<?php _e('Sending...', 'quick-entry'); ?>';
                        
                        fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                            body: new URLSearchParams({
                                action: 'qentry_send_code',
                                nonce: '<?php echo wp_create_nonce('qentry_send_code_nonce'); ?>',
                                qentry_token: '<?php echo esc_js($entry->token); ?>'
                            })
                        })
                        .then(response => response.json())
                        .then(data => {
                            if (data.success) {
                                messageDiv.className = 'qentry-message qentry-message-success';
                                messageDiv.innerHTML = '<?php _e('A new verification code has been sent to your email.', 'quick-entry'); ?>';
                                messageDiv.style.display = 'block';
                                resendBtn.textContent = '<?php _e('Code Sent!', 'quick-entry'); ?>';
                                setTimeout(() => {
                                    resendBtn.disabled = false;
                                    resendBtn.textContent = '<?php _e('Resend Code', 'quick-entry'); ?>';
                                }, 30000);
                            } else {
                                messageDiv.className = 'qentry-message qentry-message-error';
                                messageDiv.innerHTML = data.data.message || '<?php _e('Failed to send code. Please try again.', 'quick-entry'); ?>';
                                messageDiv.style.display = 'block';
                                resendBtn.disabled = false;
                                resendBtn.textContent = '<?php _e('Resend Code', 'quick-entry'); ?>';
                            }
                        })
                        .catch(() => {
                            resendBtn.disabled = false;
                            resendBtn.textContent = '<?php _e('Resend Code', 'quick-entry'); ?>';
                        });
                    });
                });
            </script>
            
            <?php wp_footer(); ?>
        </body>
        </html>
        <?php
        $content = ob_get_clean();
        echo $content;
    }
    
    /**
     * Enqueue frontend scripts
     */
    public static function enqueue_scripts() {
        if (isset($_GET['qentry'])) {
            wp_enqueue_style(
                'qentry-frontend',
                QENTRY_PLUGIN_URL . 'assets/css/frontend.css',
                array(),
                QENTRY_PLUGIN_VERSION
            );
        }
    }
    
    /**
     * AJAX: Verify code
     */
    public static function ajax_verify_code() {
        check_ajax_referer('qentry_verify_nonce', 'nonce');
        
        $code = sanitize_text_field($_POST['qentry_code']);
        $token = sanitize_text_field($_POST['qentry_token']);
        
        if (!$code || !$token) {
            wp_send_json_error(array('message' => __('Missing required fields.', 'quick-entry')));
        }
        
        $result = QENTRY_Authenticator::verify_and_login($token, $code);
        
        if ($result['success']) {
            wp_send_json_success(array(
                'message' => __('Verification successful!', 'quick-entry'),
                'redirect_url' => admin_url(),
            ));
        } else {
            wp_send_json_error(array('message' => $result['message']));
        }
    }
    
    /**
     * AJAX: Send code
     */
    public static function ajax_send_code() {
        check_ajax_referer('qentry_send_code_nonce', 'nonce');
        
        $token = sanitize_text_field($_POST['qentry_token']);
        $entry = QENTRY_Database::get_by_token($token);
        
        if (!$entry) {
            wp_send_json_error(array('message' => __('Invalid token.', 'quick-entry')));
        }
        
        $new_code = str_pad(rand(0, 999999), 6, '0', STR_PAD_LEFT);
        QENTRY_Database::update_verification_code($entry->id, $new_code);
        
        $sent = QENTRY_Email::send_verification_code($entry->email, $new_code);
        
        if ($sent) {
            wp_send_json_success(array('message' => __('Code sent successfully.', 'quick-entry')));
        } else {
            wp_send_json_error(array('message' => __('Failed to send code.', 'quick-entry')));
        }
    }
}