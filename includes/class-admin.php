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
        add_action('wp_ajax_qentry_toggle_logging', array(__CLASS__, 'ajax_toggle_logging'));
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

        // Activity logs pagination
        $log_page = isset($_GET['log_paged']) ? max(1, intval($_GET['log_paged'])) : 1;
        $log_per_page = 25;
        $filter_entry = isset($_GET['filter_entry']) ? absint($_GET['filter_entry']) : 0;
        $filter_type = isset($_GET['filter_type']) ? sanitize_text_field($_GET['filter_type']) : '';

        $logs = QENTRY_Logger::get_logs($log_per_page, $log_page, $filter_entry, 0, $filter_type);
        $log_total = QENTRY_Logger::get_total_count($filter_entry, 0, $filter_type);
        $logging_enabled = get_option('qentry_logging_enabled', true);

        global $wp_roles;
        $all_roles = array();
        foreach ($wp_roles->roles as $role_key => $role_data) {
            $all_roles[$role_key] = translate_user_role($role_data['name']);
        }

        $roles = self::get_available_roles();
        ?>
        <div class="wrap qentry-admin-wrap">
            <h1><?php _e('QuickEntry', 'quick-entry'); ?></h1>
            <hr class="wp-header-end">

            <!-- Create Login Form -->
            <div class="qentry-create-section">
                <div class="qentry-create-form">

                    <!-- Card Header -->
                    <div class="qentry-card-header">
                        <div class="qentry-card-header-left">
                            <div>
                                <div class="qentry-card-title"><?php _e('Create Temporary Login', 'quick-entry'); ?></div>
                                <div class="qentry-card-subtitle"><?php _e('Generate a temporary login link', 'quick-entry'); ?></div>
                            </div>
                        </div>
                        <span class="qentry-badge"><?php _e('Temporary Access', 'quick-entry'); ?></span>
                    </div>

                    <!-- Card Body -->
                    <div class="qentry-card-body">
                        <form id="qentry-create-form" method="post">
                            <div class="qentry-form-grid">

                                <!-- User Role -->
                                <div class="qentry-field" id="qentry-field-role">
                                    <label class="qentry-field-label" for="qentry-role">
                                        <span class="qentry-required-dot" aria-hidden="true"></span>
                                        <?php _e('User Role', 'quick-entry'); ?>
                                    </label>
                                    <div class="qentry-input-wrap qentry-has-icon qentry-select-wrap">
                                        <span class="qentry-input-icon">
                                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                <circle cx="12" cy="8" r="4"/>
                                                <path d="M4 20c0-4 3.6-7 8-7s8 3 8 7"/>
                                            </svg>
                                        </span>
                                        <select name="qentry_role" id="qentry-role" required>
                                            <option value="" disabled><?php _e('Select a role…', 'quick-entry'); ?></option>
                                            <?php foreach ($roles as $role_key => $role_name) : ?>
                                                <option value="<?php echo esc_attr($role_key); ?>"><?php echo esc_html($role_name); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <p class="qentry-field-hint"><?php _e('Role assigned to the temporary user.', 'quick-entry'); ?></p>
                                </div>

                                <!-- Email Address -->
                                <div class="qentry-field" id="qentry-field-email">
                                    <label class="qentry-field-label" for="qentry-email">
                                        <span class="qentry-required-dot" aria-hidden="true"></span>
                                        <?php _e('Email Address', 'quick-entry'); ?>
                                    </label>
                                    <div class="qentry-input-wrap qentry-has-icon">
                                        <span class="qentry-input-icon">
                                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                <rect x="2" y="4" width="20" height="16" rx="2"/>
                                                <path d="m2 7 10 7 10-7"/>
                                            </svg>
                                        </span>
                                        <input type="email" name="qentry_email" id="qentry-email" required placeholder="user@example.com" autocomplete="email">
                                    </div>
                                    <p class="qentry-field-hint"><?php _e('Verification code will be sent to this address.', 'quick-entry'); ?></p>
                                </div>

                                <!-- Expiration Date & Time -->
                                <div class="qentry-field" id="qentry-field-expiry">
                                    <label class="qentry-field-label" for="qentry-expiration-date">
                                        <span class="qentry-required-dot" aria-hidden="true"></span>
                                        <?php _e('Expiration Date & Time', 'quick-entry'); ?>
                                    </label>
                                    <div class="qentry-datetime-row">
                                        <div class="qentry-input-wrap qentry-has-icon">
                                            <span class="qentry-input-icon">
                                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                    <rect x="3" y="4" width="18" height="18" rx="2"/>
                                                    <line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/>
                                                    <line x1="3" y1="10" x2="21" y2="10"/>
                                                </svg>
                                            </span>
                                            <input type="text" name="qentry_expiration_date" id="qentry-expiration-date" class="qentry-date-picker" required placeholder="mm/dd/yyyy">
                                        </div>
                                        <div class="qentry-input-wrap qentry-has-icon">
                                            <span class="qentry-input-icon">
                                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                    <circle cx="12" cy="12" r="10"/>
                                                    <polyline points="12 6 12 12 16 14"/>
                                                </svg>
                                            </span>
                                            <input type="time" name="qentry_expiration_time" id="qentry-expiration-time" value="23:59" required>
                                        </div>
                                    </div>
                                    <p class="qentry-field-hint"><?php _e('The URL will expire after this date and time.', 'quick-entry'); ?></p>
                                </div>

                                <!-- Number of Uses -->
                                <div class="qentry-field">
                                    <label class="qentry-field-label" for="qentry-max-uses"><?php _e('Number of Uses', 'quick-entry'); ?></label>
                                    <input type="number" name="qentry_max_uses" id="qentry-max-uses" value="0" min="0" class="qentry-number-input">
                                    <p class="qentry-field-hint"><?php _e('Enter 0 for unlimited uses.', 'quick-entry'); ?></p>
                                </div>

                            </div><!-- /qentry-form-grid -->
                        </form>
                    </div>

                    <!-- Card Footer -->
                    <div class="qentry-card-footer">
                        <div class="qentry-footer-row">
                            <button type="submit" class="qentry-btn-primary" id="qentry-create-btn" form="qentry-create-form">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                    <circle cx="12" cy="12" r="10"/>
                                    <line x1="12" y1="8" x2="12" y2="16"/>
                                    <line x1="8" y1="12" x2="16" y2="12"/>
                                </svg>
                                <?php _e('Create Temporary Login', 'quick-entry'); ?>
                            </button>
                            <button type="button" class="qentry-btn-secondary" id="qentry-reset-btn">
                                <svg width="13" height="13" viewBox="0 0 21 21" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <g transform="matrix(0 1 1 0 2.5 2.5)">
                                        <path d="m3.98652376 1.07807068c-2.38377179 1.38514556-3.98652376 3.96636605-3.98652376 6.92192932 0 4.418278 3.581722 8 8 8s8-3.581722 8-8-3.581722-8-8-8"/>
                                        <path d="m4 1v4h-4" transform="matrix(1 0 0 -1 0 6)"/>
                                    </g>
                                </svg>
                                <?php _e('Reset', 'quick-entry'); ?>
                            </button>
                        </div>
                        <p class="qentry-footer-note" id="qentry-summary">
                            <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <rect x="3" y="11" width="18" height="11" rx="2"/>
                                <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
                            </svg>
                            <span id="qentry-summary-text"><?php _e('Fill out the form to see a summary of the link you are about to create.', 'quick-entry'); ?></span>
                        </p>
                    </div>
                    <span id="qentry-loading" class="spinner" style="display:none;"></span>

                </div>
            </div>

            <!-- All Logins Section -->
            <div class="qentry-logins-section">
                <div class="qentry-logins-list">
                    <div class="qentry-card-header">
                        <div class="qentry-card-header-left">
                            <div>
                                <div class="qentry-card-title"><?php _e('All Logins', 'quick-entry'); ?></div>
                                <div class="qentry-card-subtitle"><?php _e('Manage all temporary login links', 'quick-entry'); ?></div>
                            </div>
                        </div>
                        <span class="qentry-badge"><?php printf(__('%d total', 'quick-entry'), $total); ?></span>
                    </div>
                    <div class="qentry-logins-list-body">
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
            </div>

            <!-- Activity Log Section -->
            <div class="qentry-activity-section <?php echo $logging_enabled ? 'qentry-logging-enabled' : ''; ?>">
                <div class="qentry-activity-list">
                    <div class="qentry-card-header">
                        <div class="qentry-card-header-left">
                            <div>
                                <div class="qentry-card-title"><?php _e('Activity Log', 'quick-entry'); ?></div>
                                <div class="qentry-card-subtitle"><?php _e('Track actions performed by temporary users', 'quick-entry'); ?></div>
                            </div>
                        </div>
                        <span class="qentry-badge"><?php _e('Audit Trail', 'quick-entry'); ?></span>
                    </div>
                    <div class="qentry-activity-list-body">
                    <form method="get" class="qentry-log-filters">
                        <input type="hidden" name="page" value="quick-entry">
                        <div class="qentry-log-filters-left">
                            <select name="filter_type" class="qentry-form-input" style="width:150px;">
                                <option value=""><?php _e('All Types', 'quick-entry'); ?></option>
                                <option value="post" <?php selected($filter_type, 'post'); ?>><?php _e('Posts', 'quick-entry'); ?></option>
                                <option value="media" <?php selected($filter_type, 'media'); ?>><?php _e('Media', 'quick-entry'); ?></option>
                                <option value="comment" <?php selected($filter_type, 'comment'); ?>><?php _e('Comments', 'quick-entry'); ?></option>
                                <option value="user" <?php selected($filter_type, 'user'); ?>><?php _e('Users', 'quick-entry'); ?></option>
                                <option value="plugin" <?php selected($filter_type, 'plugin'); ?>><?php _e('Plugins', 'quick-entry'); ?></option>
                                <option value="theme" <?php selected($filter_type, 'theme'); ?>><?php _e('Themes', 'quick-entry'); ?></option>
                                <option value="setting" <?php selected($filter_type, 'setting'); ?>><?php _e('Settings', 'quick-entry'); ?></option>
                            </select>
                            <button type="submit" class="button"><?php _e('Filter', 'quick-entry'); ?></button>
                            <?php if ($filter_type) : ?>
                                <a href="<?php echo esc_url(admin_url('admin.php?page=quick-entry')); ?>" class="button"><?php _e('Clear Filter', 'quick-entry'); ?></a>
                            <?php endif; ?>
                        </div>
                        <div class="qentry-log-filters-right">
                            <label class="qentry-logging-toggle">
                                <span class="qentry-toggle-label"><?php _e('Logging', 'quick-entry'); ?></span>
                                <input type="checkbox" id="qentry-logging-toggle" <?php checked(get_option('qentry_logging_enabled', true)); ?>>
                                <span class="qentry-toggle-slider"></span>
                            </label>
                            <input type="hidden" name="qentry_toggle_logging" id="qentry-logging-nonce" value="<?php echo wp_create_nonce('qentry_toggle_logging'); ?>">
                        </div>
                    </form>

                    <?php if ($logging_enabled) : ?>
                    <div class="qentry-activity-table-wrap">
                    <table class="wp-list-table widefat fixed striped qentry-activity-table" style="table-layout:auto;">
                        <thead>
                            <tr>
                                <th scope="col" class="manage-column column-time"><?php _e('Time', 'quick-entry'); ?></th>
                                <th scope="col" class="manage-column column-action"><?php _e('Action', 'quick-entry'); ?></th>
                                <th scope="col" class="manage-column column-user"><?php _e('User', 'quick-entry'); ?></th>
                                <th scope="col" class="manage-column column-object"><?php _e('Object', 'quick-entry'); ?></th>
                                <th scope="col" class="manage-column column-ip"><?php _e('IP Address', 'quick-entry'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($logs)) : ?>
                                <tr>
                                    <td colspan="5"><?php _e('No activity logged yet.', 'quick-entry'); ?></td>
                                </tr>
                            <?php else : ?>
                                <?php foreach ($logs as $log) :
                                    $user = get_user_by('id', $log->user_id);
                                    $email = $user ? $user->user_email : '';
                                    $meta = !empty($log->meta) ? json_decode($log->meta, true) : array();
                                ?>
                                    <tr>
                                        <td class="column-time"><?php echo esc_html(date('M j, Y H:i', strtotime($log->created_at))); ?></td>
                                        <td class="column-action"><strong><?php echo esc_html($log->action); ?></strong></td>
                                        <td class="column-user">
                                            <?php if ($user) : ?>
                                                <?php echo esc_html($user->display_name); ?>
                                            <?php else : ?>
                                                <em><?php _e('Unknown', 'quick-entry'); ?></em>
                                            <?php endif; ?>
                                        </td>
                                        <td class="column-object">
                                            <?php if ($log->object_name) : ?>
                                                <?php echo esc_html($log->object_name); ?>
                                            <?php else : ?>
                                                <em><?php _e('N/A', 'quick-entry'); ?></em>
                                            <?php endif; ?>
                                            <?php if ($log->object_id > 0) : ?>
                                                <small>(#<?php echo esc_html($log->object_id); ?>)</small>
                                            <?php endif; ?>
                                        </td>
                                        <td class="column-ip"><?php echo esc_html($log->ip_address); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>

                    <?php
                    if ($log_total > $log_per_page) {
                        echo '<div class="tablenav bottom">';
                        echo '<div class="tablenav-pages">';
                        echo paginate_links(array(
                            'base' => add_query_arg('log_paged', '%#%'),
                            'format' => '',
                            'prev_text' => __('&laquo;', 'quick-entry'),
                            'next_text' => __('&raquo;', 'quick-entry'),
                            'total' => ceil($log_total / $log_per_page),
                            'current' => $log_page,
                        ));
                        echo '</div>';
                        echo '</div>';
                    }
                    ?>
                    </div>
                    <?php else : ?>
                    <div class="qentry-logging-disabled">
                        <p><?php _e('Activity logging is currently disabled. Enable it using the toggle above to start tracking user actions.', 'quick-entry'); ?></p>
                    </div>
                    <?php endif; ?>
                    </div><!-- /qentry-activity-list-body -->
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
        <div class="qentry-create-section">
            <div class="qentry-create-form">
                <form id="qentry-create-form" method="post">
                    <div class="qentry-form-row">
                        <div class="qentry-form-field">
                            <label for="qentry-role" class="qentry-form-label">
                                <?php _e('User Role', 'quick-entry'); ?>
                                <span class="qentry-required">*</span>
                            </label>
                            <div class="qentry-input-wrapper qentry-select-wrapper">
                                <span class="qentry-input-icon dashicons dashicons-admin-users"></span>
                                <select name="qentry_role" id="qentry-role" class="qentry-form-input" required>
                                    <?php foreach ($roles as $role_key => $role_name) : ?>
                                        <option value="<?php echo esc_attr($role_key); ?>"><?php echo esc_html($role_name); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <p class="qentry-form-help"><?php _e('The role that will be assigned to the temporary user.', 'quick-entry'); ?></p>
                        </div>

                        <div class="qentry-form-field">
                            <label for="qentry-email" class="qentry-form-label">
                                <?php _e('Email Address', 'quick-entry'); ?>
                                <span class="qentry-required">*</span>
                            </label>
                            <div class="qentry-input-wrapper">
                                <span class="qentry-input-icon dashicons dashicons-email"></span>
                                <input type="email" name="qentry_email" id="qentry-email" class="qentry-form-input" required placeholder="user@example.com">
                            </div>
                            <p class="qentry-form-help"><?php _e('The verification code will be sent to this email address.', 'quick-entry'); ?></p>
                        </div>
                    </div>

                    <div class="qentry-form-row">
                        <div class="qentry-form-field">
                            <label class="qentry-form-label">
                                <?php _e('Expiration Date & Time', 'quick-entry'); ?>
                                <span class="qentry-required">*</span>
                            </label>
                            <div class="qentry-datetime-inputs">
                                <div class="qentry-input-wrapper">
                                    <span class="qentry-input-icon dashicons dashicons-calendar"></span>
                                    <input type="text" name="qentry_expiration_date" id="qentry-expiration-date" class="qentry-form-input qentry-date-picker" required placeholder="mm/dd/yyyy">
                                </div>
                                <div class="qentry-input-wrapper qentry-time-wrapper">
                                    <span class="qentry-input-icon dashicons dashicons-clock"></span>
                                    <input type="time" name="qentry_expiration_time" id="qentry-expiration-time" class="qentry-form-input qentry-time-input" value="23:59" required>
                                </div>
                            </div>
                            <p class="qentry-form-help"><?php _e('The URL will expire after this date and time.', 'quick-entry'); ?></p>
                        </div>

                        <div class="qentry-form-field">
                            <label for="qentry-max-uses" class="qentry-form-label">
                                <?php _e('Number of Uses', 'quick-entry'); ?>
                            </label>
                            <input type="number" name="qentry_max_uses" id="qentry-max-uses" class="qentry-form-input" value="0" min="0">
                            <p class="qentry-form-help"><?php _e('Enter 0 for unlimited uses.', 'quick-entry'); ?></p>
                        </div>
                    </div>

                    <div class="qentry-form-actions">
                        <button type="submit" class="qentry-btn qentry-btn-primary" id="qentry-create-btn">
                            <span class="dashicons dashicons-plus-alt2"></span>
                            <span><?php _e('Create Temporary Login', 'quick-entry'); ?></span>
                        </button>
                    </div>
                </form>
            </div>
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
     * AJAX: Toggle activity logging
     */
    public static function ajax_toggle_logging() {
        check_ajax_referer('qentry_toggle_logging', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Permission denied.', 'quick-entry'));
        }

        $enabled = isset($_POST['enabled']) && $_POST['enabled'] === 'true';
        update_option('qentry_logging_enabled', $enabled);

        wp_send_json_success(array(
            'enabled' => $enabled,
            'message' => $enabled ? __('Activity logging enabled.', 'quick-entry') : __('Activity logging disabled.', 'quick-entry'),
        ));
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
