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
        add_action('init', array(__CLASS__, 'maybe_start_session'), 1);
    }
    
    public static function maybe_start_session() {
        if (isset($_GET['qentry']) && !session_id()) {
            session_start();
        }
    }
    
    public static function verify_and_login($token, $code) {
        $entry = QENTRY_Database::get_by_token($token);
        
        if (!$entry) {
            return array('success' => false, 'message' => __('Invalid login link.', 'quick-entry'));
        }
        
        if (strtotime($entry->expires_at) < time()) {
            return array('success' => false, 'message' => __('This login link has expired.', 'quick-entry'));
        }
        
        if ($entry->use_count >= $entry->max_uses) {
            return array('success' => false, 'message' => __('This login link has been used the maximum number of times.', 'quick-entry'));
        }
        
        if ($entry->code_expires_at && strtotime($entry->code_expires_at) < time()) {
            return array('success' => false, 'message' => __('Your verification code has expired. Please request a new one.', 'quick-entry'));
        }
        
        if ($entry->verification_code !== $code) {
            self::increment_failed_attempts($token);
            $attempts = self::get_failed_attempts($token);
            $remaining = self::MAX_ATTEMPTS - $attempts;
            
            if ($remaining <= 0) {
                return array('success' => false, 'message' => __('Too many failed attempts. Please request a new login link.', 'quick-entry'));
            }
            
            return array('success' => false, 'message' => sprintf(__('Invalid verification code. %d attempts remaining.', 'quick-entry'), $remaining));
        }
        
        self::clear_failed_attempts($token);
        return self::login_user($entry);
    }
    
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
        
        wp_set_current_user($user->ID, $user->user_login);
        wp_set_auth_cookie($user->ID, true);
        
        QENTRY_Database::mark_as_used($entry->id);
        
        set_transient('qentry_temp_login_' . $user->ID, array(
            'entry_id' => $entry->id,
            'role' => $entry->role,
            'expires_at' => $entry->expires_at,
        ), strtotime($entry->expires_at) - time());
        
        return array(
            'success' => true,
            'message' => __('Login successful!', 'quick-entry'),
            'redirect_url' => admin_url(),
        );
    }
    
    private static function get_failed_attempts($token) {
        $session_key = 'qentry_failed_attempts_' . md5($token);
        return isset($_SESSION[$session_key]) ? intval($_SESSION[$session_key]) : 0;
    }
    
    private static function increment_failed_attempts($token) {
        $session_key = 'qentry_failed_attempts_' . md5($token);
        if (!isset($_SESSION[$session_key])) {
            $_SESSION[$session_key] = 0;
        }
        $_SESSION[$session_key]++;
    }
    
    private static function clear_failed_attempts($token) {
        $session_key = 'qentry_failed_attempts_' . md5($token);
        unset($_SESSION[$session_key]);
    }
    
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