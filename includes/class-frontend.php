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
        
        // Generic error for all failure states — never reveal whether invalid, expired, or used (M01)
        $is_valid = $entry
            && strtotime($entry->expires_at) >= time()
            && ($entry->max_uses == 0 || $entry->use_count < $entry->max_uses);
        
        if (!$is_valid) {
            wp_die(
                __('This login link is no longer valid.', 'quick-entry'),
                __('Invalid Link - QuickEntry', 'quick-entry'),
                array('response' => 403, 'back_link' => true)
            );
        }
        
        // Security headers: prevent token leakage via Referer, prevent caching (H08, M04)
        header('Referrer-Policy: no-referrer');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        
        self::render_verification_page($entry, $token);
        exit;
    }
    
    /**
     * Render verification page
     *
     * @param object $entry     The database entry.
     * @param string $raw_token The raw token from the URL (for form hidden field).
     */
    private static function render_verification_page($entry, $raw_token) {
        // Server-side guard to prevent email flooding on page refresh (C05)
        $code_sent_key = 'qentry_code_sent_' . $entry->id;
        if (!get_transient($code_sent_key)) {
            $new_code = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            // Hash the code before storing (C04)
            QENTRY_Database::update_verification_code($entry->id, wp_hash_password($new_code));
            QENTRY_Email::send_verification_code($entry->email, $new_code);
            set_transient($code_sent_key, true, 60); // 60 second cooldown
        }
        
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
                        <input type="hidden" name="qentry_token" value="<?php echo esc_attr($raw_token); ?>">
                        
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
                    var form = document.getElementById('qentry-verify-form');
                    var codeInput = document.getElementById('qentry-verification-code');
                    var messageDiv = document.getElementById('qentry-verify-message');
                    var submitBtn = document.getElementById('qentry-verify-submit');
                    var btnText = submitBtn.querySelector('.qentry-btn-text');
                    var btnLoading = submitBtn.querySelector('.qentry-btn-loading');
                    var resendBtn = document.getElementById('qentry-resend-code');
                    
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
                        var code = codeInput.value;
                        if (code.length !== 6) return;
                        
                        btnText.style.display = 'none';
                        btnLoading.style.display = 'inline';
                        submitBtn.disabled = true;
                        messageDiv.style.display = 'none';
                        
                        fetch('<?php echo esc_url(admin_url('admin-ajax.php')); ?>', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                            body: new URLSearchParams({
                                action: 'qentry_verify_code',
                                nonce: '<?php echo wp_create_nonce('qentry_verify_nonce'); ?>',
                                qentry_code: code,
                                qentry_token: '<?php echo esc_js($raw_token); ?>'
                            })
                        })
                        .then(function(response) { return response.json(); })
                        .then(function(data) {
                            if (data.success) {
                                messageDiv.className = 'qentry-message qentry-message-success';
                                messageDiv.textContent = '<?php echo esc_js(__('Verification successful! Redirecting...', 'quick-entry')); ?>';
                                messageDiv.style.display = 'block';
                                setTimeout(function() { window.location.href = data.data.redirect_url; }, 1500);
                            } else {
                                messageDiv.className = 'qentry-message qentry-message-error';
                                messageDiv.textContent = data.data.message || '<?php echo esc_js(__('Invalid verification code. Please try again.', 'quick-entry')); ?>';
                                messageDiv.style.display = 'block';
                                codeInput.value = '';
                                codeInput.focus();
                                btnText.style.display = 'inline';
                                btnLoading.style.display = 'none';
                                submitBtn.disabled = false;
                            }
                        })
                        .catch(function() {
                            messageDiv.className = 'qentry-message qentry-message-error';
                            messageDiv.textContent = '<?php echo esc_js(__('An error occurred. Please try again.', 'quick-entry')); ?>';
                            messageDiv.style.display = 'block';
                            btnText.style.display = 'inline';
                            btnLoading.style.display = 'none';
                            submitBtn.disabled = false;
                        });
                    }
                    
                    resendBtn.addEventListener('click', function() {
                        this.disabled = true;
                        this.textContent = '<?php echo esc_js(__('Sending...', 'quick-entry')); ?>';
                        
                        fetch('<?php echo esc_url(admin_url('admin-ajax.php')); ?>', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                            body: new URLSearchParams({
                                action: 'qentry_send_code',
                                nonce: '<?php echo wp_create_nonce('qentry_send_code_nonce'); ?>',
                                qentry_token: '<?php echo esc_js($raw_token); ?>'
                            })
                        })
                        .then(function(response) { return response.json(); })
                        .then(function(data) {
                            if (data.success) {
                                messageDiv.className = 'qentry-message qentry-message-success';
                                messageDiv.textContent = '<?php echo esc_js(__('A new verification code has been sent to your email.', 'quick-entry')); ?>';
                                messageDiv.style.display = 'block';
                                resendBtn.textContent = '<?php echo esc_js(__('Code Sent!', 'quick-entry')); ?>';
                                setTimeout(function() {
                                    resendBtn.disabled = false;
                                    resendBtn.textContent = '<?php echo esc_js(__('Resend Code', 'quick-entry')); ?>';
                                }, 30000);
                            } else {
                                messageDiv.className = 'qentry-message qentry-message-error';
                                messageDiv.textContent = data.data.message || '<?php echo esc_js(__('Failed to send code. Please try again.', 'quick-entry')); ?>';
                                messageDiv.style.display = 'block';
                                resendBtn.disabled = false;
                                resendBtn.textContent = '<?php echo esc_js(__('Resend Code', 'quick-entry')); ?>';
                            }
                        })
                        .catch(function() {
                            resendBtn.disabled = false;
                            resendBtn.textContent = '<?php echo esc_js(__('Resend Code', 'quick-entry')); ?>';
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
        
        // Validate code is a 6-digit numeric string
        if (!$code || !$token || !preg_match('/^[0-9]{6}$/', $code)) {
            wp_send_json_error(array('message' => __('Missing required fields.', 'quick-entry')));
        }
        
        // Validate token is a hex string
        if (!preg_match('/^[0-9a-f]{64}$/', $token)) {
            wp_send_json_error(array('message' => __('Invalid request.', 'quick-entry')));
        }
        
        $result = QENTRY_Authenticator::verify_and_login($token, $code);
        
        if ($result['success']) {
            wp_send_json_success(array(
                'message'      => __('Verification successful!', 'quick-entry'),
                'redirect_url' => admin_url(),
            ));
        } else {
            wp_send_json_error(array('message' => $result['message']));
        }
    }
    
    /**
     * AJAX: Send code (resend)
     */
    public static function ajax_send_code() {
        check_ajax_referer('qentry_send_code_nonce', 'nonce');
        
        $token = sanitize_text_field($_POST['qentry_token']);
        
        // Validate token format
        if (!$token || !preg_match('/^[0-9a-f]{64}$/', $token)) {
            // Generic message — don't reveal whether the token exists (M01)
            wp_send_json_error(array('message' => __('Invalid request.', 'quick-entry')));
        }
        
        $entry = QENTRY_Database::get_by_token($token);
        
        if (!$entry) {
            // Generic message — same response as valid token to prevent enumeration
            wp_send_json_error(array('message' => __('Invalid request.', 'quick-entry')));
        }
        
        // Rate limit: max 1 code per 60 seconds per token (H02)
        $rate_key = 'qentry_resend_' . md5($token);
        if (get_transient($rate_key)) {
            wp_send_json_error(array('message' => __('Please wait before requesting another code.', 'quick-entry')));
        }
        
        // Rate limit: max 5 codes per 10 minutes per email (H02)
        $email_rate_key = 'qentry_email_rate_' . md5($entry->email);
        $email_count = (int) get_transient($email_rate_key);
        if ($email_count >= 5) {
            wp_send_json_error(array('message' => __('Too many code requests. Please try again later.', 'quick-entry')));
        }
        
        $new_code = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        // Hash code before storing (C04)
        QENTRY_Database::update_verification_code($entry->id, wp_hash_password($new_code));
        
        $sent = QENTRY_Email::send_verification_code($entry->email, $new_code);
        
        if ($sent) {
            set_transient($rate_key, true, 60);
            set_transient($email_rate_key, $email_count + 1, 600);
            wp_send_json_success(array('message' => __('Code sent successfully.', 'quick-entry')));
        } else {
            wp_send_json_error(array('message' => __('Failed to send code.', 'quick-entry')));
        }
    }
}