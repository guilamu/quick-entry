<?php
/**
 * Admin Class - Handles all admin UI
 */

if (!defined('ABSPATH')) {
    exit;
}

class QENTRY_Admin {
    
    public static function init() {
        add_action('admin_menu', array(__CLASS__, 'add_admin_menu'));
        add_action('admin_enqueue_scripts', array(__CLASS__, 'enqueue_scripts'));
        add_action('wp_ajax_qentry_create_login', array(__CLASS__, 'ajax_create_login'));
        add_action('wp_ajax_qentry_delete_login', array(__CLASS__, 'ajax_delete_login'));
        add_action('wp_ajax_qentry_resend_code', array(__CLASS__, 'ajax_resend_code'));
        add_action('wp_ajax_qentry_get_tab_content', array(__CLASS__, 'ajax_get_tab_content'));
    }
    
    /**
     * Add admin menu
     */
    public static function add_admin_menu() {
        add_menu_page(
            __('QuickEntry', 'quick-entry'),
            __('QuickEntry', 'quick-entry'),
            'manage_options',
            'quick-entry',
            array(__CLASS__, 'render_main_page'),
            'dashicons-clock',
            100
        );
    }
    
    /**
     * Enqueue admin scripts and styles
     */
    public static function enqueue_scripts($hook) {
        if ('toplevel_page_quick-entry' !== $hook) {
            return;
        }
        
        wp_enqueue_script('jquery');
        wp_enqueue_script('jquery-ui-core');
        wp_enqueue_script('jquery-ui-datepicker');
        
        wp_enqueue_style('qentry-jquery-ui', '//ajax.googleapis.com/ajax/libs/jqueryui/1.12.1/themes/smoothness/jquery-ui.css', array(), '1.12.1');
        
        wp_enqueue_style(
            'qentry-admin',
            QENTRY_PLUGIN_URL . 'assets/css/admin.css',
            array(),
            QENTRY_PLUGIN_VERSION
        );
        
        wp_enqueue_script(
            'qentry-admin-js',
            QENTRY_PLUGIN_URL . 'assets/js/admin.js',
            array('jquery', 'jquery-ui-datepicker'),
            QENTRY_PLUGIN_VERSION,
            true
        );
        
        wp_localize_script('qentry-admin-js', 'qentry_data', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('qentry_nonce'),
            'site_url' => home_url(),
            'i18n' => array(
                'success' => __('Temporary login URL created successfully!', 'quick-entry'),
                'error' => __('An error occurred. Please try again.', 'quick-entry'),
                'confirm_delete' => __('Are you sure you want to delete this temporary login?', 'quick-entry'),
                'deleted' => __('Temporary login deleted.', 'quick-entry'),
                'copy_success' => __('URL copied to clipboard!', 'quick-entry'),
            ),
        ));
    }
    
    /**
     * Render main plugin page
     */
    public static function render_main_page() {
        $active_tab = isset($_GET['qentry_tab']) ? sanitize_text_field($_GET['qentry_tab']) : 'create';
        ?>
        <div class="wrap qentry-admin-wrap">
            <h1 class="wp-heading-inline"><?php _e('QuickEntry', 'quick-entry'); ?></h1>
            <hr class="wp-header-end">
            
            <h2 class="nav-tab-wrapper">
                <a href="<?php echo esc_url(admin_url('admin.php?page=quick-entry&qentry_tab=create')); ?>" class="nav-tab <?php echo $active_tab === 'create' ? 'nav-tab-active' : ''; ?>">
                    <span class="dashicons dashicons-plus-alt"></span> <?php _e('Create New', 'quick-entry'); ?>
                </a>
                <a href="<?php echo esc_url(admin_url('admin.php?page=quick-entry&qentry_tab=logins')); ?>" class="nav-tab <?php echo $active_tab === 'logins' ? 'nav-tab-active' : ''; ?>">
                    <span class="dashicons dashicons-list-view"></span> <?php _e('All Logins', 'quick-entry'); ?>
                </a>
            </h2>
            
            <div class="qentry-tab-content" id="qentry-tab-content">
                <?php
                if ($active_tab === 'logins') {
                    self::render_logins_tab();
                } else {
                    self::render_create_tab();
                }
                ?>
            </div>
            
            <!-- Create Login Modal -->
            <div id="qentry-modal" class="qentry-modal" style="display:none;">
                <div class="qentry-modal-content">
                    <div class="qentry-modal-header">
                        <h2><?php _e('Temporary Login Created', 'quick-entry'); ?></h2>
                        <button type="button" class="qentry-modal-close">&times;</button>
                    </div>
                    <div class="qentry-modal-body">
                        <div class="qentry-success-message">
                            <span class="dashicons dashicons-yes-alt qentry-success-icon"></span>
                            <p><?php _e('Your temporary login URL has been generated:', 'quick-entry'); ?></p>
                        </div>
                        <div class="qentry-url-display">
                            <input type="text" id="qentry-generated-url" class="regular-text" readonly>
                            <button type="button" id="qentry-copy-url" class="button button-secondary">
                                <span class="dashicons dashicons-clipboard"></span> <?php _e('Copy', 'quick-entry'); ?>
                            </button>
                        </div>
                        <div class="qentry-url-info">
                            <p><strong><?php _e('Instructions:', 'quick-entry'); ?></strong></p>
                            <ol>
                                <li><?php _e('Copy the URL above and send it to the user.', 'quick-entry'); ?></li>
                                <li><?php _e('When they click the link, a 6-digit verification code will be sent to their email.', 'quick-entry'); ?></li>
                                <li><?php _e('They enter the code to gain access.', 'quick-entry'); ?></li>
                            </ol>
                        </div>
                        <div class="qentry-modal-actions">
                            <button type="button" class="button button-primary qentry-modal-close-btn"><?php _e('Close', 'quick-entry'); ?></button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }
    
    /**
     * Render create tab
     */
    private static function render_create_tab() {
        $roles = self::get_available_roles();
        ?>
        <div class="qentry-create-form">
            <form id="qentry-create-form" method="post">
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="qentry-role"><?php _e('User Role', 'quick-entry'); ?> <span class="required">*</span></label>
                        </th>
                        <td>
                            <select name="qentry_role" id="qentry-role" required>
                                <option value=""><?php _e('Select a role...', 'quick-entry'); ?></option>
                                <?php foreach ($roles as $role_key => $role_name) : ?>
                                    <option value="<?php echo esc_attr($role_key); ?>"><?php echo esc_html($role_name); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description"><?php _e('The role that will be assigned to the temporary user.', 'quick-entry'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="qentry-email"><?php _e('Email Address', 'quick-entry'); ?> <span class="required">*</span></label>
                        </th>
                        <td>
                            <input type="email" name="qentry_email" id="qentry-email" class="regular-text" required placeholder="user@example.com">
                            <p class="description"><?php _e('The verification code will be sent to this email address.', 'quick-entry'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="qentry-expiration"><?php _e('Expiration Date & Time', 'quick-entry'); ?> <span class="required">*</span></label>
                        </th>
                        <td>
                            <input type="text" name="qentry_expiration_date" id="qentry-expiration-date" class="qentry-date-picker" required placeholder="mm/dd/yyyy">
                            <input type="time" name="qentry_expiration_time" id="qentry-expiration-time" value="23:59" required>
                            <p class="description"><?php _e('The URL will expire after this date and time.', 'quick-entry'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label><?php _e('Usage Type', 'quick-entry'); ?> <span class="required">*</span></label>
                        </th>
                        <td>
                            <label>
                                <input type="radio" name="qentry_usage_type" value="one_time" checked id="qentry-usage-one-time">
                                <strong><?php _e('One-time use', 'quick-entry'); ?></strong>
                                <span class="description"><?php _e('- URL can only be used once', 'quick-entry'); ?></span>
                            </label><br>
                            <label>
                                <input type="radio" name="qentry_usage_type" value="multiple" id="qentry-usage-multiple">
                                <strong><?php _e('Multiple uses', 'quick-entry'); ?></strong>
                                <span class="description"><?php _e('- URL can be used a specific number of times', 'quick-entry'); ?></span>
                            </label>
                            <div id="qentry-max-uses-container" style="margin-top:10px; display:none;">
                                <label for="qentry-max-uses"><?php _e('Maximum number of uses', 'quick-entry'); ?></label>
                                <input type="number" name="qentry_max_uses" id="qentry-max-uses" value="0" min="0" class="small-text">
                                <p class="description"><?php _e('Enter 0 for unlimited uses.', 'quick-entry'); ?></p>
                            </div>
                        </td>
                    </tr>
                </table>
                <p class="submit">
                    <button type="submit" class="button button-primary button-hero" id="qentry-create-btn">
                        <span class="dashicons dashicons-plus-alt"></span><span><?php _e('Create Temporary Login', 'quick-entry'); ?></span>
                    </button>
                    <span id="qentry-loading" class="spinner" style="display:none; float:none;"></span>
                </p>
            </form>
        </div>
        <?php
    }
    
    /**
     * Render logins tab
     */
    private static function render_logins_tab() {
        $page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $per_page = 20;
        $search = isset($_GET['s']) ? sanitize_text_field($_GET['s']) : '';
        
        if (!empty($search)) {
            $logins = QENTRY_Database::search_by_email($search, $per_page, $page);
            $total = 0;
        } else {
            $logins = QENTRY_Database::get_all_logins($per_page, $page);
            $total = QENTRY_Database::get_total_count();
        }
        
        $roles = self::get_available_roles();
        ?>
        <div class="qentry-logins-list">
            <?php if (!empty($search)) : ?>
                <p><?php printf(__('Search results for: <strong>%s</strong>', 'quick-entry'), esc_html($search)); ?></p>
            <?php endif; ?>
            
            <form method="get" class="qentry-search-form">
                <input type="hidden" name="page" value="quick-entry">
                <input type="hidden" name="qentry_tab" value="logins">
                <p class="search-box">
                    <label class="screen-reader-text" for="qentry-search"><?php _e('Search by email:', 'quick-entry'); ?></label>
                    <input type="search" id="qentry-search" name="s" value="<?php echo esc_attr($search); ?>" placeholder="<?php _e('Search by email...', 'quick-entry'); ?>">
                    <input type="submit" class="button" value="<?php _e('Search', 'quick-entry'); ?>">
                </p>
            </form>
            
            <table class="wp-list-table widefat fixed striped qentry-logins-table" style="table-layout:auto;">
                <thead>
                    <tr>
                        <th scope="col" class="manage-column column-id"><?php _e('ID', 'quick-entry'); ?></th>
                        <th scope="col" class="manage-column column-email"><?php _e('Email', 'quick-entry'); ?></th>
                        <th scope="col" class="manage-column column-role"><?php _e('Role', 'quick-entry'); ?></th>
                        <th scope="col" class="manage-column column-usage"><?php _e('Usage', 'quick-entry'); ?></th>
                        <th scope="col" class="manage-column column-created"><?php _e('Created', 'quick-entry'); ?></th>
                        <th scope="col" class="manage-column column-expires"><?php _e('Expires', 'quick-entry'); ?></th>
                        <th scope="col" class="manage-column column-status"><?php _e('Status', 'quick-entry'); ?></th>
                        <th scope="col" class="manage-column column-actions" style="white-space:nowrap;"><?php _e('Actions', 'quick-entry'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($logins)) : ?>
                        <tr>
                            <td colspan="8"><?php _e('No temporary logins found.', 'quick-entry'); ?></td>
                        </tr>
                    <?php else : ?>
                        <?php foreach ($logins as $login) : 
                            $is_expired = strtotime($login->expires_at) < time();
                            $is_used = $login->max_uses > 1 ? $login->use_count >= $login->max_uses : $login->used;
                            $url = home_url('?qentry=' . $login->token);
                        ?>
                            <tr>
                                <td class="column-id"><?php echo esc_html($login->id); ?></td>
                                <td class="column-email"><strong><?php echo esc_html($login->email); ?></strong></td>
                                <td class="column-role"><?php echo esc_html($roles[$login->role] ?? $login->role); ?></td>
                                <td class="column-usage">
                                    <?php if ($login->usage_type === 'one_time') : ?>
                                        <?php _e('One-time', 'quick-entry'); ?>
                                    <?php elseif ($login->max_uses == 0) : ?>
                                        <?php printf(__('%d uses (unlimited)', 'quick-entry'), $login->use_count); ?>
                                    <?php else : ?>
                                        <?php printf(__('%d/%d', 'quick-entry'), $login->use_count, $login->max_uses); ?>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo esc_html(date('M j, Y H:i', strtotime($login->created_at))); ?></td>
                                <td><?php echo esc_html(date('M j, Y H:i', strtotime($login->expires_at))); ?></td>
                                <td>
                                    <?php if ($is_used || $is_expired) : ?>
                                        <span class="qentry-status-badge qentry-expired"><?php _e('Expired/Used', 'quick-entry'); ?></span>
                                    <?php else : ?>
                                        <span class="qentry-status-badge qentry-active"><?php _e('Active', 'quick-entry'); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td class="column-actions" style="white-space:nowrap;">
                                    <button class="button qentry-copy-btn" data-url="<?php echo esc_url($url); ?>" title="<?php _e('Copy login URL to clipboard', 'quick-entry'); ?>">
                                        <span class="dashicons dashicons-clipboard"></span>
                                    </button>
                                    <button class="button qentry-resend-btn" data-id="<?php echo esc_attr($login->id); ?>" data-email="<?php echo esc_attr($login->email); ?>" title="<?php _e('Resend verification code to this email', 'quick-entry'); ?>">
                                        <span class="dashicons dashicons-email"></span>
                                    </button>
                                    <button class="button qentry-delete-btn button-link-delete" data-id="<?php echo esc_attr($login->id); ?>" title="<?php _e('Delete this temporary login', 'quick-entry'); ?>">
                                        <span class="dashicons dashicons-trash"></span>
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
            
            <?php
            if ($total > $per_page) {
                echo '<div class="tablenav bottom">';
                echo '<div class="tablenav-pages">';
                echo paginate_links(array(
                    'base' => add_query_arg('paged', '%#%'),
                    'format' => '',
                    'prev_text' => __('&laquo;', 'quick-entry'),
                    'next_text' => __('&raquo;', 'quick-entry'),
                    'total' => ceil($total / $per_page),
                    'current' => $page,
                ));
                echo '</div>';
                echo '</div>';
            }
            ?>
        </div>
        <?php
    }
    
    /**
     * Get available WordPress roles
     */
    private static function get_available_roles() {
        global $wp_roles;
        
        $roles = array();
        foreach ($wp_roles->roles as $role_key => $role_data) {
            $roles[$role_key] = translate_user_role($role_data['name']);
        }
        
        return $roles;
    }
    
    /**
     * AJAX: Create temporary login
     */
    public static function ajax_create_login() {
        check_ajax_referer('qentry_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Permission denied.', 'quick-entry'));
        }
        
        $role = sanitize_text_field($_POST['qentry_role']);
        $email = sanitize_email($_POST['qentry_email']);
        $expiration_date = sanitize_text_field($_POST['qentry_expiration_date']);
        $expiration_time = sanitize_text_field($_POST['qentry_expiration_time']);
        $usage_type = sanitize_text_field($_POST['qentry_usage_type'] ?? 'one_time');
        $max_uses = $usage_type === 'one_time' ? 1 : intval($_POST['qentry_max_uses'] ?? 0);
        
        if (!is_email($email)) {
            wp_send_json_error(__('Invalid email address.', 'quick-entry'));
        }
        
        global $wp_roles;
        if (!array_key_exists($role, $wp_roles->roles)) {
            wp_send_json_error(__('Invalid role selected.', 'quick-entry'));
        }
        
        $expires_at = date('Y-m-d H:i:s', strtotime($expiration_date . ' ' . $expiration_time));
        if (strtotime($expires_at) < time()) {
            wp_send_json_error(__('Expiration date must be in the future.', 'quick-entry'));
        }
        
        $verification_code = str_pad(rand(0, 999999), 6, '0', STR_PAD_LEFT);
        
        $data = array(
            'email' => $email,
            'role' => $role,
            'verification_code' => $verification_code,
            'expires_at' => $expires_at,
            'usage_type' => $usage_type,
            'max_uses' => $max_uses,
        );
        
        $id = QENTRY_Database::insert_login($data);
        
        if (!$id) {
            wp_send_json_error(__('Failed to create temporary login.', 'quick-entry'));
        }
        
        $entry = QENTRY_Database::get_by_id($id);
        $login_url = home_url('?qentry=' . $entry->token);
        
        wp_send_json_success(array(
            'url' => $login_url,
            'email' => $email,
            'message' => __('Temporary login created successfully!', 'quick-entry'),
        ));
    }
    
    /**
     * AJAX: Delete temporary login
     */
    public static function ajax_delete_login() {
        check_ajax_referer('qentry_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Permission denied.', 'quick-entry'));
        }
        
        $id = intval($_POST['id']);
        $result = QENTRY_Database::delete_login($id);
        
        if ($result) {
            wp_send_json_success(__('Temporary login deleted.', 'quick-entry'));
        } else {
            wp_send_json_error(__('Failed to delete temporary login.', 'quick-entry'));
        }
    }
    
    /**
     * AJAX: Resend verification code
     */
    public static function ajax_resend_code() {
        check_ajax_referer('qentry_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Permission denied.', 'quick-entry'));
        }
        
        $id = intval($_POST['id']);
        $email = sanitize_email($_POST['email']);
        
        $new_code = str_pad(rand(0, 999999), 6, '0', STR_PAD_LEFT);
        $result = QENTRY_Database::update_verification_code($id, $new_code);
        
        if ($result) {
            QENTRY_Email::send_verification_code($email, $new_code);
            wp_send_json_success(__('Verification code sent to ' . $email, 'quick-entry'));
        } else {
            wp_send_json_error(__('Failed to resend code.', 'quick-entry'));
        }
    }
    
    /**
     * AJAX: Get tab content
     */
    public static function ajax_get_tab_content() {
        check_ajax_referer('qentry_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Permission denied.', 'quick-entry'));
        }
        
        $tab = sanitize_text_field($_POST['qentry_tab'] ?? 'create');
        
        ob_start();
        if ($tab === 'logins') {
            self::render_logins_tab();
        } else {
            self::render_create_tab();
        }
        $html = ob_get_clean();
        
        wp_send_json_success(array('html' => $html));
    }
}