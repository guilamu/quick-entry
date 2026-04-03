<?php
/**
 * Logger Class - Tracks actions performed by temporary login users
 */

if (!defined('ABSPATH')) {
    exit;
}

class QENTRY_Logger {

    /**
     * Prevent duplicate logs within cooldown period using transients.
     *
     * @param string $dedup_key Unique key for this action+object combo.
     * @return bool True if should be skipped (duplicate), false if should log.
     */
    private static function is_duplicate($dedup_key) {
        $transient_key = 'qentry_log_dedup_' . md5($dedup_key);

        if (get_transient($transient_key)) {
            return true; // Duplicate found, skip logging.
        }

        // Set a 5-second transient to prevent duplicates.
        set_transient($transient_key, true, 5);
        return false;
    }

    private static function log_action($action, $object_type, $object_id, $object_name = '', $meta = array()) {
        // Check if logging is enabled.
        if (!get_option('qentry_logging_enabled', true)) {
            return;
        }

        $temp_login = self::get_temp_login_info();
        if (!$temp_login) {
            return;
        }

        // Prevent duplicate logs using transients (works across requests).
        $dedup_key = $action . '|' . $object_id;
        if (self::is_duplicate($dedup_key)) {
            return;
        }

        global $wpdb;

        $table = $wpdb->prefix . 'qentry_activity_logs';

        $data = array(
            'entry_id'    => $temp_login['entry_id'],
            'user_id'     => get_current_user_id(),
            'action'      => sanitize_text_field($action),
            'object_type' => sanitize_text_field($object_type),
            'object_id'   => absint($object_id),
            'object_name' => sanitize_text_field($object_name),
            'ip_address'  => self::get_client_ip(),
            'user_agent'  => isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field($_SERVER['HTTP_USER_AGENT']) : '',
            'meta'        => !empty($meta) ? wp_json_encode($meta) : '',
            'created_at'  => current_time('mysql'),
        );

        $wpdb->insert($table, $data);
    }

    /**
     * Get client IP address.
     */
    private static function get_client_ip() {
        $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '0.0.0.0';
        return filter_var($ip, FILTER_VALIDATE_IP) ?: '0.0.0.0';
    }

    public static function init() {
        self::hook_wordpress_actions();
        self::remove_duplicate_logs();
    }

    /**
     * Hook into WordPress core actions to track temporary login user activity.
     */
    private static function hook_wordpress_actions() {
        // Post actions
        add_action('save_post', array(__CLASS__, 'log_save_post'), 10, 3);
        add_action('wp_trash_post', array(__CLASS__, 'log_trash_post'));
        add_action('untrashed_post', array(__CLASS__, 'log_untrash_post'));
        add_action('deleted_post', array(__CLASS__, 'log_delete_post'), 10, 2);

        // Media actions
        add_action('delete_attachment', array(__CLASS__, 'log_delete_attachment'));

        // Comment actions
        add_action('wp_insert_comment', array(__CLASS__, 'log_insert_comment'), 10, 2);
        add_action('wp_update_comment', array(__CLASS__, 'log_update_comment'), 10, 2);
        add_action('wp_trash_comment', array(__CLASS__, 'log_trash_comment'));
        add_action('deleted_comment', array(__CLASS__, 'log_delete_comment'));

        // User actions
        add_action('create_user', array(__CLASS__, 'log_create_user'));
        add_action('delete_user', array(__CLASS__, 'log_delete_user'));
        add_action('profile_update', array(__CLASS__, 'log_profile_update'), 10, 2);

        // Plugin actions
        add_action('activated_plugin', array(__CLASS__, 'log_activated_plugin'));
        add_action('deactivated_plugin', array(__CLASS__, 'log_deactivated_plugin'));
        add_action('delete_plugin', array(__CLASS__, 'log_delete_plugin'));

        // Theme actions
        add_action('switch_theme', array(__CLASS__, 'log_switch_theme'));

        // Option/Setting actions
        add_action('added_option', array(__CLASS__, 'log_added_option'), 10, 2);
        add_action('updated_option', array(__CLASS__, 'log_updated_option'), 10, 3);
        add_action('deleted_option', array(__CLASS__, 'log_deleted_option'));
    }

    /**
     * Check if current user is logged in via QuickEntry temporary login.
     *
     * @return array|false Array with entry_id and role if temp login, false otherwise.
     */
    public static function get_temp_login_info() {
        $user_id = get_current_user_id();
        if (!$user_id) {
            return false;
        }

        $temp_login = get_transient('qentry_temp_login_' . $user_id);
        if (!$temp_login) {
            return false;
        }

        return $temp_login;
    }

    // -------------------------------------------------------------------------
    // Post Hooks
    // -------------------------------------------------------------------------

    public static function log_save_post($post_id, $post, $update) {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }
        if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
            return;
        }

        $action = $update ? 'Post Updated' : 'Post Created';
        self::log_action($action, 'post', $post_id, $post->post_title, array(
            'post_type'   => $post->post_type,
            'post_status' => $post->post_status,
        ));
    }

    public static function log_trash_post($post_id) {
        $post = get_post($post_id);
        if (!$post) {
            return;
        }
        self::log_action('Post Trashed', 'post', $post_id, $post->post_title, array(
            'post_type' => $post->post_type,
        ));
    }

    public static function log_untrash_post($post_id) {
        $post = get_post($post_id);
        if (!$post) {
            return;
        }
        self::log_action('Post Restored', 'post', $post_id, $post->post_title, array(
            'post_type' => $post->post_type,
        ));
    }

    public static function log_delete_post($post_id, $post) {
        if (!$post) {
            $post = get_post($post_id);
        }
        $post_name = $post ? $post->post_title : 'Unknown';
        self::log_action('Post Permanently Deleted', 'post', $post_id, $post_name, array(
            'post_type' => $post ? $post->post_type : '',
        ));
    }

    // -------------------------------------------------------------------------
    // Media Hooks
    // -------------------------------------------------------------------------

    public static function log_delete_attachment($post_id) {
        $post = get_post($post_id);
        if (!$post) {
            return;
        }
        self::log_action('Media Deleted', 'media', $post_id, $post->post_title, array(
            'mime_type' => $post->post_mime_type,
        ));
    }

    // -------------------------------------------------------------------------
    // Comment Hooks
    // -------------------------------------------------------------------------

    public static function log_insert_comment($comment_id, $comment) {
        if (is_admin()) {
            return;
        }
        self::log_action('Comment Posted', 'comment', $comment_id, '', array(
            'comment_post_id' => $comment->comment_post_ID,
            'comment_author'  => $comment->comment_author,
        ));
    }

    public static function log_update_comment($comment_id, $comment) {
        self::log_action('Comment Updated', 'comment', $comment_id, '', array(
            'comment_post_id' => $comment->comment_post_ID,
        ));
    }

    public static function log_trash_comment($comment_id) {
        $comment = get_comment($comment_id);
        if (!$comment) {
            return;
        }
        self::log_action('Comment Trashed', 'comment', $comment_id, '', array(
            'comment_post_id' => $comment->comment_post_ID,
        ));
    }

    public static function log_delete_comment($comment_id) {
        $comment = get_comment($comment_id);
        if (!$comment) {
            return;
        }
        self::log_action('Comment Deleted', 'comment', $comment_id, '', array(
            'comment_post_id' => $comment->comment_post_ID,
        ));
    }

    // -------------------------------------------------------------------------
    // User Hooks
    // -------------------------------------------------------------------------

    public static function log_create_user($user_id) {
        $user = get_user_by('id', $user_id);
        if (!$user) {
            return;
        }
        self::log_action('User Created', 'user', $user_id, $user->user_login, array(
            'user_email' => $user->user_email,
            'user_role'  => implode(', ', $user->roles),
        ));
    }

    public static function log_delete_user($user_id) {
        self::log_action('User Deleted', 'user', $user_id, 'User #' . $user_id);
    }

    public static function log_profile_update($user_id, $old_user_data) {
        $user = get_user_by('id', $user_id);
        if (!$user) {
            return;
        }
        self::log_action('User Profile Updated', 'user', $user_id, $user->user_login, array(
            'user_email' => $user->user_email,
        ));
    }

    // -------------------------------------------------------------------------
    // Plugin Hooks
    // -------------------------------------------------------------------------

    public static function log_activated_plugin($plugin) {
        self::log_action('Plugin Activated', 'plugin', 0, $plugin);
    }

    public static function log_deactivated_plugin($plugin) {
        self::log_action('Plugin Deactivated', 'plugin', 0, $plugin);
    }

    public static function log_delete_plugin($plugin) {
        self::log_action('Plugin Deleted', 'plugin', 0, $plugin);
    }

    // -------------------------------------------------------------------------
    // Theme Hooks
    // -------------------------------------------------------------------------

    public static function log_switch_theme($new_name, $new_theme = null) {
        self::log_action('Theme Switched', 'theme', 0, $new_name);
    }

    // -------------------------------------------------------------------------
    // Option/Setting Hooks
    // -------------------------------------------------------------------------

    public static function log_added_option($option, $value) {
        if (self::should_skip_option($option)) {
            return;
        }
        self::log_action('Option Added', 'setting', 0, $option);
    }

    public static function log_updated_option($option, $old_value, $new_value) {
        if (self::should_skip_option($option)) {
            return;
        }
        self::log_action('Option Updated', 'setting', 0, $option, array(
            'old_value' => is_array($old_value) ? wp_json_encode($old_value) : $old_value,
            'new_value' => is_array($new_value) ? wp_json_encode($new_value) : $new_value,
        ));
    }

    public static function log_deleted_option($option) {
        if (self::should_skip_option($option)) {
            return;
        }
        self::log_action('Option Deleted', 'setting', 0, $option);
    }

    private static function should_skip_option($option) {
        $skip_prefixes = array(
            '_site_transient_',
            '_transient_',
            'widget_',
            'qentry_',
            'cron',
            'rewrite_rules',
            'wp_user_roles',
        );

        foreach ($skip_prefixes as $prefix) {
            if (strpos($option, $prefix) === 0) {
                return true;
            }
        }
        return false;
    }

    // -------------------------------------------------------------------------
    // Database Methods
    // -------------------------------------------------------------------------

    public static function create_table() {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}qentry_activity_logs (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            entry_id int(11) NOT NULL,
            user_id bigint(20) NOT NULL,
            action varchar(100) NOT NULL,
            object_type varchar(50) NOT NULL,
            object_id bigint(20) DEFAULT 0,
            object_name varchar(255) DEFAULT '',
            ip_address varchar(45) DEFAULT '',
            user_agent text DEFAULT '',
            meta text DEFAULT '',
            created_at datetime NOT NULL,
            PRIMARY KEY (id),
            KEY entry_id (entry_id),
            KEY user_id (user_id),
            KEY object_type (object_type),
            KEY created_at (created_at)
        ) $charset_collate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    public static function get_logs($per_page = 25, $page = 1, $entry_id = 0, $user_id = 0, $object_type = '') {
        global $wpdb;

        $table = $wpdb->prefix . 'qentry_activity_logs';
        $offset = ($page - 1) * $per_page;

        $where = array('1=1');
        $params = array();

        if ($entry_id > 0) {
            $where[] = 'entry_id = %d';
            $params[] = $entry_id;
        }

        if ($user_id > 0) {
            $where[] = 'user_id = %d';
            $params[] = $user_id;
        }

        if (!empty($object_type)) {
            $where[] = 'object_type = %s';
            $params[] = sanitize_text_field($object_type);
        }

        $where_clause = implode(' AND ', $where);
        $params[] = $per_page;
        $params[] = $offset;

        $query = "SELECT * FROM {$table} WHERE {$where_clause} ORDER BY created_at DESC LIMIT %d OFFSET %d";

        return $wpdb->get_results($wpdb->prepare($query, $params));
    }

    public static function get_total_count($entry_id = 0, $user_id = 0, $object_type = '') {
        global $wpdb;

        $table = $wpdb->prefix . 'qentry_activity_logs';

        $where = array('1=1');
        $params = array();

        if ($entry_id > 0) {
            $where[] = 'entry_id = %d';
            $params[] = $entry_id;
        }

        if ($user_id > 0) {
            $where[] = 'user_id = %d';
            $params[] = $user_id;
        }

        if (!empty($object_type)) {
            $where[] = 'object_type = %s';
            $params[] = sanitize_text_field($object_type);
        }

        $where_clause = implode(' AND ', $where);
        $query = "SELECT COUNT(*) FROM {$table} WHERE {$where_clause}";

        return $wpdb->get_var($wpdb->prepare($query, $params));
    }

    public static function cleanup_old_logs($days = 30) {
        global $wpdb;

        $table = $wpdb->prefix . 'qentry_activity_logs';
        $cutoff = date('Y-m-d H:i:s', time() - ($days * DAY_IN_SECONDS));

        return $wpdb->query($wpdb->prepare(
            "DELETE FROM {$table} WHERE created_at < %s",
            $cutoff
        ));
    }

    /**
     * Remove exact duplicate log entries (same action, user, object, ip within same second).
     * Keeps only the first occurrence of each duplicate group.
     */
    private static function remove_duplicate_logs() {
        global $wpdb;

        $table = $wpdb->prefix . 'qentry_activity_logs';

        // Delete duplicates: keep the lowest ID for each group of identical entries
        $wpdb->query(
            "DELETE t1 FROM {$table} t1
            INNER JOIN {$table} t2
            WHERE t1.id > t2.id
            AND t1.action = t2.action
            AND t1.user_id = t2.user_id
            AND t1.object_id = t2.object_id
            AND t1.ip_address = t2.ip_address
            AND t1.created_at = t2.created_at"
        );
    }
}
