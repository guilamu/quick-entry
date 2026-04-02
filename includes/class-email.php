<?php
/**
 * Email Class - Handles all email functionality
 */

if (!defined('ABSPATH')) {
    exit;
}

class QENTRY_Email {
    
    private static $site_name;
    
    public static function init() {
        self::$site_name = wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES);
    }
    
    /**
     * Send verification code email
     */
    public static function send_verification_code($email, $code) {
        if (!is_email($email)) {
            return false;
        }
        
        $subject = self::get_email_subject();
        $message = self::get_email_body($code);
        $headers = self::get_email_headers();
        
        return wp_mail($email, $subject, $message, $headers);
    }
    
    /**
     * Get email subject
     */
    private static function get_email_subject() {
        return sprintf(
            __('Your Verification Code for %s', 'quick-entry'),
            self::$site_name
        );
    }
    
    /**
     * Get email body (HTML)
     */
    private static function get_email_body($code) {
        $expiration_minutes = get_option('qentry_code_expiry_minutes', 10);
        
        ob_start();
        ?>
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <style>
                body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; line-height: 1.6; color: #333; background-color: #f5f5f5; margin: 0; padding: 0; }
                .email-container { max-width: 600px; margin: 20px auto; background-color: #ffffff; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
                .email-header { background-color: #2271b1; color: #ffffff; padding: 30px; text-align: center; }
                .email-header h1 { margin: 0; font-size: 24px; }
                .email-body { padding: 30px; background-color: #ffffff; }
                .email-body p { margin: 0 0 15px 0; }
                .code-box { background-color: #f0f0f1; border: 2px dashed #2271b1; border-radius: 8px; padding: 20px; text-align: center; margin: 25px 0; }
                .code { font-size: 36px; font-weight: bold; color: #2271b1; letter-spacing: 8px; font-family: 'Courier New', monospace; }
                .expiry-info { background-color: #fff8e5; border-left: 4px solid #dba617; padding: 15px; margin: 20px 0; border-radius: 0 4px 4px 0; }
                .expiry-info p { margin: 0; color: #6c6c6c; font-size: 14px; }
                .email-footer { padding: 20px 30px; background-color: #f5f5f5; text-align: center; font-size: 12px; color: #6c6c6c; }
                .email-footer p { margin: 0; }
            </style>
        </head>
        <body>
            <div class="email-container">
                <div class="email-header">
                    <h1><?php echo esc_html(self::$site_name); ?></h1>
                    <p style="margin: 10px 0 0 0; opacity: 0.9;"><?php _e('Temporary Access Verification', 'quick-entry'); ?></p>
                </div>
                <div class="email-body">
                    <p><?php _e('Hello,', 'quick-entry'); ?></p>
                    <p><?php _e('You have been granted temporary access to', 'quick-entry'); ?> <strong><?php echo esc_html(self::$site_name); ?></strong>. <?php _e('Please use the verification code below to log in:', 'quick-entry'); ?></p>
                    <div class="code-box">
                        <p style="margin: 0 0 10px 0; color: #6c6c6c; font-size: 14px;"><?php _e('Your Verification Code', 'quick-entry'); ?></p>
                        <span class="code"><?php echo esc_html($code); ?></span>
                    </div>
                    <div class="expiry-info">
                        <p><strong><?php printf(__('Important: This code expires in %d minutes.', 'quick-entry'), $expiration_minutes); ?></strong></p>
                        <p><?php _e('If you did not request this access, please ignore this email or contact the site administrator.', 'quick-entry'); ?></p>
                    </div>
                    <p style="font-size: 14px; color: #6c6c6c;"><?php _e('For security reasons, do not share this code with anyone.', 'quick-entry'); ?></p>
                </div>
                <div class="email-footer">
                    <p><?php printf(__('This is an automated email from %s. Please do not reply to this email.', 'quick-entry'), esc_html(self::$site_name)); ?></p>
                </div>
            </div>
        </body>
        </html>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Get email headers
     */
    private static function get_email_headers() {
        $admin_email = get_option('admin_email');
        $headers = array(
            'From: ' . self::$site_name . ' <' . $admin_email . '>',
            'Content-Type: text/html; charset=UTF-8',
            'Reply-To: ' . $admin_email,
        );
        return implode("\r\n", $headers);
    }
}