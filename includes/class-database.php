<?php
/**
 * Database Management Class
 */

if (!defined('ABSPATH')) {
    exit;
}

class QENTRY_Database {
    
    /**
     * Create the custom table
     */
    public static function create_table() {
        global $wpdb;
        
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}qentry_tokens (
            id int(11) NOT NULL AUTO_INCREMENT,
            token varchar(64) NOT NULL,
            email varchar(255) NOT NULL,
            role varchar(50) NOT NULL,
            verification_code varchar(6) NOT NULL,
            expires_at datetime NOT NULL,
            code_expires_at datetime DEFAULT NULL,
            used tinyint(1) DEFAULT 0,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            used_at datetime DEFAULT NULL,
            usage_type varchar(20) DEFAULT 'one_time',
            max_uses int(11) DEFAULT 1,
            use_count int(11) DEFAULT 0,
            PRIMARY KEY  (id),
            UNIQUE KEY token (token),
            KEY email (email),
            KEY expires_at (expires_at)
        ) $charset_collate;";
        
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }
    
    /**
     * Plugin activation hook
     */
    public static function activate() {
        self::create_table();
        self::add_default_options();
    }
    
    /**
     * Plugin deactivation hook
     */
    public static function deactivate() {
        self::cleanup_expired();
    }
    
    /**
     * Add default options
     */
    private static function add_default_options() {
        add_option('qentry_code_expiry_minutes', 10);
        add_option('qentry_max_code_requests', 5);
        add_option('qentry_cleanup_days', 30);
    }
    
    /**
     * Clean up expired entries
     */
    public static function cleanup_expired() {
        global $wpdb;
        
        $table = $wpdb->prefix . 'qentry_tokens';
        $now = current_time('mysql');
        
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$table} WHERE expires_at < %s AND used = 1",
            $now
        ));
    }
    
    /**
     * Insert a new temporary login
     */
    public static function insert_login($data) {
        global $wpdb;
        
        $table = $wpdb->prefix . 'qentry_tokens';
        
        $defaults = array(
            'token' => wp_generate_uuid4(),
            'email' => '',
            'role' => 'subscriber',
            'verification_code' => '',
            'expires_at' => '',
            'usage_type' => 'one_time',
            'max_uses' => 1,
        );
        
        $data = wp_parse_args($data, $defaults);
        
        $result = $wpdb->insert($table, $data);
        
        if ($result) {
            return $wpdb->insert_id;
        }
        
        return false;
    }
    
    /**
     * Get a login entry by token
     */
    public static function get_by_token($token) {
        global $wpdb;
        
        $table = $wpdb->prefix . 'qentry_tokens';
        
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE token = %s",
            sanitize_text_field($token)
        ));
    }
    
    /**
     * Get a login entry by email
     */
    public static function get_by_email($email) {
        global $wpdb;
        
        $table = $wpdb->prefix . 'qentry_tokens';
        
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} WHERE email = %s ORDER BY created_at DESC",
            sanitize_email($email)
        ));
    }
    
    /**
     * Update a login entry
     */
    public static function update_login($id, $data) {
        global $wpdb;
        
        $table = $wpdb->prefix . 'qentry_tokens';
        
        return $wpdb->update($table, $data, array('id' => $id));
    }
    
    /**
     * Delete a login entry
     */
    public static function delete_login($id) {
        global $wpdb;
        
        $table = $wpdb->prefix . 'qentry_tokens';
        
        return $wpdb->delete($table, array('id' => $id), array('%d'));
    }
    
    /**
     * Get all login entries
     */
    public static function get_all_logins($per_page = 20, $page = 1) {
        global $wpdb;
        
        $table = $wpdb->prefix . 'qentry_tokens';
        $offset = ($page - 1) * $per_page;
        
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} ORDER BY created_at DESC LIMIT %d OFFSET %d",
            $per_page,
            $offset
        ));
    }
    
    /**
     * Get total count of entries
     */
    public static function get_total_count() {
        global $wpdb;
        
        $table = $wpdb->prefix . 'qentry_tokens';
        
        return $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
    }
    
    /**
     * Update verification code and its expiry
     */
    public static function update_verification_code($id, $code, $expiry_minutes = 10) {
        global $wpdb;
        
        $code_expires = current_time('mysql', true);
        $code_expires = date('Y-m-d H:i:s', strtotime($code_expires . ' +' . $expiry_minutes . ' minutes'));
        
        return $wpdb->update(
            $wpdb->prefix . 'qentry_tokens',
            array(
                'verification_code' => $code,
                'code_expires_at' => $code_expires,
            ),
            array('id' => $id)
        );
    }
    
    /**
     * Mark login as used
     */
    public static function mark_as_used($id) {
        global $wpdb;
        
        $table = $wpdb->prefix . 'qentry_tokens';
        
        $entry = self::get_by_id($id);
        $new_use_count = $entry->use_count + 1;
        
        if ($new_use_count >= $entry->max_uses) {
            return $wpdb->update($table, array(
                'used' => 1,
                'used_at' => current_time('mysql'),
                'use_count' => $new_use_count,
            ), array('id' => $id));
        }
        
        return $wpdb->update($table, array(
            'used_at' => current_time('mysql'),
            'use_count' => $new_use_count,
        ), array('id' => $id));
    }
    
    /**
     * Get login entry by ID
     */
    public static function get_by_id($id) {
        global $wpdb;
        
        $table = $wpdb->prefix . 'qentry_tokens';
        
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE id = %d",
            $id
        ));
    }
    
    /**
     * Search entries by email
     */
    public static function search_by_email($search, $per_page = 20, $page = 1) {
        global $wpdb;
        
        $table = $wpdb->prefix . 'qentry_tokens';
        $offset = ($page - 1) * $per_page;
        
        $like = '%' . $wpdb->esc_like($search) . '%';
        
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} WHERE email LIKE %s ORDER BY created_at DESC LIMIT %d OFFSET %d",
            $like,
            $per_page,
            $offset
        ));
    }
}