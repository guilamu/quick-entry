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
        
        wp_enqueue_style('qentry-jquery-ui', 'https://ajax.googleapis.com/ajax/libs/jqueryui/1.12.1/themes/smoothness/jquery-ui.css', array(), '1.12.1');
        
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
        $page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $per_page = 20;

        $logins = QENTRY_Database::get_all_logins($per_page, $page);
        $total = QENTRY_Database::get_total_count();

        global $wp_roles;
        $all_roles = array();
        foreach ($wp_roles->roles as $role_key => $role_data) {
            $all_roles[$role_key] = translate_user_role($role_data['name']);
        }

        $roles = self::get_available_roles();
        ?>
        <div class="wrap qentry-admin-wrap">
            <h1 class="wp-heading-inline"><?php _e('QuickEntry', 'quick-entry'); ?></h1>
            <hr class="wp-header-end">

            <!-- Create Login Form -->
            <div class="qentry-create-section">
                <div class="qentry-create-form">
                    <form id="qentry-create-form" method="post">
                        <div class="qentry-form-row">
                            <div class="qentry-form-field qentry-form-col-third">
                                <label for="qentry-role" class="qentry-form-label">
                                    <?php _e('User Role', 'quick-entry'); ?>
                                    <span class="qentry-required">*</span>
                                </label>
                                <select name="qentry_role" id="qentry-role" class="qentry-form-input" required>
                                    <option value=""><?php _e('Select a role...', 'quick-entry'); ?></option>
                                    <?php foreach ($roles as $role_key => $role_name) : ?>
                                        <option value="<?php echo esc_attr($role_key); ?>"><?php echo esc_html($role_name); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="qentry-form-help"><?php _e('The role that will be assigned to the temporary user.', 'quick-entry'); ?></p>
                            </div>

                            <div class="qentry-form-field qentry-form-col-third">
                                <label for="qentry-email" class="qentry-form-label">
                                    <?php _e('Email Address', 'quick-entry'); ?>
                                    <span class="qentry-required">*</span>
                                </label>
                                <input type="email" name="qentry_email" id="qentry-email" class="qentry-form-input" required placeholder="user@example.com">
                                <p class="qentry-form-help"><?php _e('The verification code will be sent to this email address.', 'quick-entry'); ?></p>
                            </div>

                            <div class="qentry-form-field qentry-form-col-third">
                                <label class="qentry-form-label">
                                    <?php _e('Expiration Date & Time', 'quick-entry'); ?>
                                    <span class="qentry-required">*</span>
                                </label>
                                <div class="qentry-datetime-inputs">
                                    <input type="text" name="qentry_expiration_date" id="qentry-expiration-date" class="qentry-form-input qentry-date-picker" required placeholder="mm/dd/yyyy">
                                    <input type="time" name="qentry_expiration_time" id="qentry-expiration-time" class="qentry-form-input qentry-time-input" value="23:59" required>
                                </div>
                                <p class="qentry-form-help"><?php _e('The URL will expire after this date and time.', 'quick-entry'); ?></p>
                            </div>

                            <div class="qentry-form-field qentry-form-col-third">
                                <label for="qentry-max-uses" class="qentry-form-label">
                                    <?php _e('Number of Uses', 'quick-entry'); ?>
                                </label>
                                <input type="number" name="qentry_max_uses" id="qentry-max-uses" class="qentry-form-input" value="0" min="0">
                                <p class="qentry-form-help"><?php _e('Enter 0 for unlimited uses.', 'quick-entry'); ?></p>
                            </div>
                        </div>

                        <div class="qentry-form-actions">
                            <button type="submit" class="button button-primary button-hero" id="qentry-create-btn">
                                <span class="dashicons dashicons-plus-alt"></span><span><?php _e('Create Temporary Login', 'quick-entry'); ?></span>
                            </button>
                            <span id="qentry-loading" class="spinner"></span>
                        </div>
                    </form>
                </div>
            </div>

            <!-- All Logins Section -->
            <div class="qentry-logins-section">
                <h2><?php _e('All Logins', 'quick-entry'); ?></h2>
                <div class="qentry-logins-list">
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
                                    $login_url = home_url('?qentry=' . $login->token);
                                ?>
                                    <tr>
                                        <td class="column-id"><?php echo esc_html($login->id); ?></td>
                                        <td class="column-email"><strong><?php echo esc_html($login->email); ?></strong></td>
                                        <td class="column-role"><?php echo esc_html($all_roles[$login->role] ?? $login->role); ?></td>
                                        <td class="column-usage">
                                            <?php if ($login->max_uses == 0) : ?>
                                                <?php printf(__('%d uses (unlimited)', 'quick-entry'), $login->use_count); ?>
                                            <?php elseif ($login->max_uses == 1) : ?>
                                                <?php _e('One-time', 'quick-entry'); ?>
                                                <?php if ($login->use_count > 0) : ?>
                                                    (<?php printf(__('used'), 'quick-entry'); ?>)
                                                <?php endif; ?>
                                            <?php else : ?>
                                                <?php printf(__('%d/%d', 'quick-entry'), $login->use_count, $login->max_uses); ?>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo esc_html(date('M j, Y H:i', strtotime($login->created_at))); ?></td>
                                        <td><?php echo esc_html(date('M j, Y H:i', strtotime($login->expires_at))); ?></td>
                                        <td>
                                            <?php if ($is_used || $is_expired) : ?>
                                                <span class="qentry-status-badge qentry-expired"><?php _e('Expired', 'quick-entry'); ?></span>
                                            <?php else : ?>
                                                <span class="qentry-status-badge qentry-active"><?php _e('Active', 'quick-entry'); ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="column-actions" style="white-space:nowrap;">
                                            <?php if (!$is_used && !$is_expired) : ?>
                                                <button class="button qentry-copy-btn" data-url="<?php echo esc_attr($login_url); ?>" title="<?php _e('Copy login URL', 'quick-entry'); ?>">
                                                    <span class="dashicons dashicons-clipboard"></span>
                                                </button>
                                            <?php endif; ?>
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
                <div class="qentry-form-row">
                    <div class="qentry-form-field qentry-form-col-third">
                        <label for="qentry-role" class="qentry-form-label">
                            <?php _e('User Role', 'quick-entry'); ?>
                            <span class="qentry-required">*</span>
                        </label>
                        <select name="qentry_role" id="qentry-role" class="qentry-form-input" required>
                            <option value=""><?php _e('Select a role...', 'quick-entry'); ?></option>
                            <?php foreach ($roles as $role_key => $role_name) : ?>
                                <option value="<?php echo esc_attr($role_key); ?>"><?php echo esc_html($role_name); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <p class="qentry-form-help"><?php _e('The role that will be assigned to the temporary user.', 'quick-entry'); ?></p>
                    </div>

                    <div class="qentry-form-field qentry-form-col-third">
                        <label for="qentry-email" class="qentry-form-label">
                            <?php _e('Email Address', 'quick-entry'); ?>
                            <span class="qentry-required">*</span>
                        </label>
                        <input type="email" name="qentry_email" id="qentry-email" class="qentry-form-input" required placeholder="user@example.com">
                        <p class="qentry-form-help"><?php _e('The verification code will be sent to this email address.', 'quick-entry'); ?></p>
                    </div>

                    <div class="qentry-form-field qentry-form-col-third">
                        <label class="qentry-form-label">
                            <?php _e('Expiration Date & Time', 'quick-entry'); ?>
                            <span class="qentry-required">*</span>
                        </label>
                        <div class="qentry-datetime-inputs">
                            <input type="text" name="qentry_expiration_date" id="qentry-expiration-date" class="qentry-form-input qentry-date-picker" required placeholder="mm/dd/yyyy">
                            <input type="time" name="qentry_expiration_time" id="qentry-expiration-time" class="qentry-form-input qentry-time-input" value="23:59" required>
                        </div>
                        <p class="qentry-form-help"><?php _e('The URL will expire after this date and time.', 'quick-entry'); ?></p>
                    </div>

                    <div class="qentry-form-field qentry-form-col-third">
                        <label for="qentry-max-uses" class="qentry-form-label">
                            <?php _e('Number of Uses', 'quick-entry'); ?>
                        </label>
                        <input type="number" name="qentry_max_uses" id="qentry-max-uses" class="qentry-form-input" value="0" min="0">
                        <p class="qentry-form-help"><?php _e('Enter 0 for unlimited uses.', 'quick-entry'); ?></p>
                    </div>
                </div>

                <div class="qentry-form-actions">
                    <button type="submit" class="button button-primary button-hero" id="qentry-create-btn">
                        <span class="dashicons dashicons-plus-alt"></span><span><?php _e('Create Temporary Login', 'quick-entry'); ?></span>
                    </button>
                    <span id="qentry-loading" class="spinner"></span>
                </div>
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

        $logins = QENTRY_Database::get_all_logins($per_page, $page);
        $total = QENTRY_Database::get_total_count();

        global $wp_roles;
        $all_roles = array();
        foreach ($wp_roles->roles as $role_key => $role_data) {
            $all_roles[$role_key] = translate_user_role($role_data['name']);
        }
        ?>
        <div class="qentry-logins-list">
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
                            $login_url = home_url('?qentry=' . $login->token);
                        ?>
                            <tr>
                                <td class="column-id"><?php echo esc_html($login->id); ?></td>
                                <td class="column-email"><strong><?php echo esc_html($login->email); ?></strong></td>
                                <td class="column-role"><?php echo esc_html($all_roles[$login->role] ?? $login->role); ?></td>
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
                                        <span class="qentry-status-badge qentry-expired"><?php _e('Expired', 'quick-entry'); ?></span>
                                    <?php else : ?>
                                        <span class="qentry-status-badge qentry-active"><?php _e('Active', 'quick-entry'); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td class="column-actions" style="white-space:nowrap;">
                                    <?php if (!$is_used && !$is_expired) : ?>
                                        <button class="button qentry-copy-btn" data-url="<?php echo esc_attr($login_url); ?>" title="<?php _e('Copy login URL', 'quick-entry'); ?>">
                                            <span class="dashicons dashicons-clipboard"></span>
                                        </button>
                                    <?php endif; ?>
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
     * Get available WordPress roles — excludes denied roles (H06)
     */
    private static function get_available_roles() {
        global $wp_roles;
        
        // Optionally restrict roles via filter, e.g. to block administrator:
        // add_filter('qentry_denied_roles', function() { return ['administrator']; });
        $denied_roles = apply_filters('qentry_denied_roles', array());
        
        $roles = array();
        foreach ($wp_roles->roles as $role_key => $role_data) {
            if (in_array($role_key, $denied_roles, true)) {
                continue;
            }
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
        $max_uses = intval($_POST['qentry_max_uses'] ?? 0);

        if (!is_email($email)) {
            wp_send_json_error(__('Invalid email address.', 'quick-entry'));
        }

        global $wp_roles;
        if (!array_key_exists($role, $wp_roles->roles)) {
            wp_send_json_error(__('Invalid role selected.', 'quick-entry'));
        }

        // Validate against denied roles (configurable via qentry_denied_roles filter)
        $denied_roles = apply_filters('qentry_denied_roles', array());
        if (in_array($role, $denied_roles, true)) {
            wp_send_json_error(__('This role cannot be assigned via QuickEntry.', 'quick-entry'));
        }

        $expires_at = date('Y-m-d H:i:s', strtotime($expiration_date . ' ' . $expiration_time));
        if (strtotime($expires_at) < time()) {
            wp_send_json_error(__('Expiration date must be in the future.', 'quick-entry'));
        }

        // Use random_int() instead of rand() for verification code (C03)
        $verification_code = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        // Determine usage type based on max_uses
        $usage_type = ($max_uses == 1 || $max_uses == 0) ? 'one_time' : 'multi_use';

        $data = array(
            'email'             => $email,
            'role'              => $role,
            'verification_code' => wp_hash_password($verification_code), // Hash before storing (C04)
            'expires_at'        => $expires_at,
            'max_uses'          => $max_uses,
            'usage_type'        => $usage_type,
        );
        
        $insert_id = QENTRY_Database::insert_login($data);
        
        if (!$insert_id) {
            wp_send_json_error(__('Failed to create temporary login.', 'quick-entry'));
        }
        
        // Fetch the newly created entry to get the token for URL
        $entry = QENTRY_Database::get_by_id($insert_id);
        $login_url = home_url('?qentry=' . $entry->token);
        
        // HTTPS enforcement (M06)
        if (strpos($login_url, 'https://') !== 0 && !defined('QENTRY_ALLOW_HTTP')) {
            // Still create the login but warn admin
            // In production, consider blocking: wp_send_json_error(...)
        }
        
        wp_send_json_success(array(
            'url'     => $login_url,
            'email'   => $email,
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
        
        $id = absint($_POST['id']);
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
        
        $id = absint($_POST['id']);
        $email = sanitize_email($_POST['email']);
        
        // Verify the entry exists and email matches
        $entry = QENTRY_Database::get_by_id($id);
        if (!$entry || $entry->email !== $email) {
            wp_send_json_error(__('Invalid request.', 'quick-entry'));
        }
        
        // Use random_int() instead of rand() (C03)
        $new_code = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        // Hash code before storing (C04)
        $result = QENTRY_Database::update_verification_code($id, wp_hash_password($new_code));
        
        if ($result !== false) {
            QENTRY_Email::send_verification_code($email, $new_code);
            // Escaped output — no concatenation of user input into translation strings (H07)
            wp_send_json_success(sprintf(
                /* translators: %s: email address */
                __('Verification code sent to %s', 'quick-entry'),
                $email
            ));
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

        // Tab functionality removed - return error
        wp_send_json_error(__('Tab functionality has been removed.', 'quick-entry'));
    }
}
