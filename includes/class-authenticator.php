<?php
/**
 * Authenticator Class - Handles verification and login logic
 */

if (!defined('ABSPATH')) {
    exit;
}

class QENTRY_Authenticator {
    
    const MAX_ATTEMPTS = 5;
    
    public static function init() {
        // No PHP sessions needed — rate limiting uses transients
    }
    
    /**
     * Verify code and log in user.
     *
     * @param string $token Raw token from the URL.
     * @param string $code  6-digit verification code.
     * @return array Result with 'success' and 'message' keys.
     */
    public static function verify_and_login($token, $code) {
        // Generic failure message — never reveal whether the token is invalid, expired, or used
        $generic_fail = __('Verification failed. Please check your code and try again.', 'quick-entry');
        $locked_msg   = __('This link has been temporarily locked due to too many attempts. Please try again later.', 'quick-entry');
        
        // Check global rate limit first (per-token, regardless of IP)
        if (self::get_global_failed_attempts($token) >= self::MAX_ATTEMPTS) {
            return array('success' => false, 'message' => $locked_msg);
        }
        
        // Check per-IP rate limit
        if (self::get_failed_attempts($token) >= self::MAX_ATTEMPTS) {
            return array('success' => false, 'message' => $locked_msg);
        }
        
        $entry = QENTRY_Database::get_by_token($token);
        
        if (!$entry) {
            return array('success' => false, 'message' => $generic_fail);
        }
        
        if (strtotime($entry->expires_at) < time()) {
            return array('success' => false, 'message' => $generic_fail);
        }
        
        if ($entry->max_uses > 0 && $entry->use_count >= $entry->max_uses) {
            return array('success' => false, 'message' => $generic_fail);
        }
        
        if ($entry->code_expires_at && strtotime($entry->code_expires_at) < time()) {
            return array('success' => false, 'message' => __('Your verification code has expired. Please request a new one.', 'quick-entry'));
        }
        
        // Verify hashed code
        if (!wp_check_password($code, $entry->verification_code)) {
            self::increment_failed_attempts($token);
            self::increment_global_failed_attempts($token);
            return array('success' => false, 'message' => $generic_fail);
        }
        
        self::clear_failed_attempts($token);
        return self::login_user($entry);
    }
    
    /**
     * Log in the user after successful verification.
     */
    private static function login_user($entry) {
        $user = get_user_by('email', $entry->email);
        
        if (!$user) {
            $user_login = sanitize_user(strtok($entry->email, '@'));
            $user_email = sanitize_email($entry->email);
            $user_pass = wp_generate_password(20, true, true);
            
            $original_username = $user_login;
            $counter = 1;
            while (username_exists($user_login)) {
                $user_login = $original_username . '_' . $counter;
                $counter++;
            }
            
            $user_id = wp_create_user($user_login, $user_pass, $user_email);
            
            if (is_wp_error($user_id)) {
                return array('success' => false, 'message' => __('Failed to create user account.', 'quick-entry'));
            }
            
            $user = new WP_User($user_id);
            $user->remove_role('subscriber');
            
            global $wp_roles;
            if (isset($wp_roles->roles[$entry->role])) {
                $user->add_role($entry->role);
            } else {
                $user->add_role('subscriber');
            }
            
            $user = get_user_by('id', $user_id);
        } else {
            $original_roles = $user->roles;
            update_user_meta($user->ID, '_qentry_original_roles', $original_roles);
            
            global $wp_roles;
            if (isset($wp_roles->roles[$entry->role])) {
                $user->add_role($entry->role);
            }
            
            $user = get_user_by('id', $user->ID);
        }
        
        // Session fixation prevention
        if (session_id()) {
            session_regenerate_id(true);
        }
        
        // Clear existing auth cookies before setting new ones
        wp_clear_auth_cookie();
        
        wp_set_current_user($user->ID, $user->user_login);
        wp_set_auth_cookie($user->ID, false); // false = session cookie, not persistent 14-day "remember me"
        
        // Fire wp_login action for audit trail compatibility (WP Activity Log, Wordfence, etc.)
        do_action('wp_login', $user->user_login, $user);
        
        QENTRY_Database::mark_as_used($entry->id);
        
        set_transient('qentry_temp_login_' . $user->ID, array(
            'entry_id'   => $entry->id,
            'role'       => $entry->role,
            'expires_at' => $entry->expires_at,
        ), strtotime($entry->expires_at) - time());
        
        return array(
            'success'      => true,
            'message'      => __('Login successful!', 'quick-entry'),
            'redirect_url' => admin_url(),
        );
    }
    
    // -------------------------------------------------------------------------
    // Rate limiting: per-IP + per-token (uses transients, NOT PHP sessions)
    // -------------------------------------------------------------------------
    
    /**
     * Get per-IP failed attempts for a token.
     */
    private static function get_failed_attempts($token) {
        $ip  = self::get_client_ip();
        $key = 'qentry_attempts_' . md5($token . $ip);
        return (int) get_transient($key);
    }
    
    /**
     * Increment per-IP failed attempts for a token.
     */
    private static function increment_failed_attempts($token) {
        $ip       = self::get_client_ip();
        $key      = 'qentry_attempts_' . md5($token . $ip);
        $attempts = (int) get_transient($key);
        set_transient($key, $attempts + 1, 900); // 15-minute window
    }
    
    /**
     * Clear per-IP failed attempts for a token.
     */
    private static function clear_failed_attempts($token) {
        $ip  = self::get_client_ip();
        $key = 'qentry_attempts_' . md5($token . $ip);
        delete_transient($key);
    }
    
    /**
     * Get global failed attempts for a token (regardless of IP).
     */
    private static function get_global_failed_attempts($token) {
        $key = 'qentry_global_attempts_' . md5($token);
        return (int) get_transient($key);
    }
    
    /**
     * Increment global failed attempts for a token.
     */
    private static function increment_global_failed_attempts($token) {
        $key      = 'qentry_global_attempts_' . md5($token);
        $attempts = (int) get_transient($key);
        set_transient($key, $attempts + 1, 900); // 15-minute window
    }
    
    /**
     * Get sanitised client IP address.
     */
    private static function get_client_ip() {
        // Only trust REMOTE_ADDR — never raw HTTP_X_FORWARDED_FOR without a proxy whitelist
        $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '0.0.0.0';
        return filter_var($ip, FILTER_VALIDATE_IP) ?: '0.0.0.0';
    }
    
    /**
     * Restore original roles when temporary login expires.
     */
    public static function restore_original_roles($user_id) {
        $original_roles = get_user_meta($user_id, '_qentry_original_roles', true);
        
        if ($original_roles && is_array($original_roles)) {
            $user = new WP_User($user_id);
            
            $temp_login = get_transient('qentry_temp_login_' . $user_id);
            if ($temp_login && $temp_login['role'] !== 'subscriber') {
                $user->remove_role($temp_login['role']);
            }
            
            foreach ($original_roles as $role) {
                $user->add_role($role);
            }
            
            delete_user_meta($user_id, '_qentry_original_roles');
        }
    }
}