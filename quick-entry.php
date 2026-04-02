<?php
/**
 * Plugin Name: QuickEntry
 * Plugin URI: https://github.com/guilamu/quick-entry
 * Description: Create temporary login URLs with email verification and role assignment.
 * Version: 1.0.0
 * Author: guilamu
 * Author URI: https://github.com/guilamu
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: quick-entry
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * Update URI: https://github.com/guilamu/quick-entry/
 */

if (!defined('ABSPATH')) {
    exit;
}

define('QENTRY_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('QENTRY_PLUGIN_URL', plugin_dir_url(__FILE__));
define('QENTRY_PLUGIN_VERSION', '1.0.0');

// Include required files
require_once QENTRY_PLUGIN_DIR . 'includes/class-database.php';
require_once QENTRY_PLUGIN_DIR . 'includes/class-admin.php';
require_once QENTRY_PLUGIN_DIR . 'includes/class-frontend.php';
require_once QENTRY_PLUGIN_DIR . 'includes/class-email.php';
require_once QENTRY_PLUGIN_DIR . 'includes/class-authenticator.php';
require_once QENTRY_PLUGIN_DIR . 'includes/class-github-updater.php';

class Quick_Entry {
    
    private static $instance = null;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        register_activation_hook(__FILE__, array('QENTRY_Database', 'activate'));
        register_deactivation_hook(__FILE__, array('QENTRY_Database', 'deactivate'));
        
        add_action('init', array($this, 'init'));
        add_action('plugins_loaded', array($this, 'load_textdomain'));
        add_filter('plugin_row_meta', array($this, 'plugin_row_meta'), 10, 2);
    }
    
    public function init() {
        // Initialize components
        QENTRY_Admin::init();
        QENTRY_Frontend::init();
        QENTRY_Email::init();
        QENTRY_Authenticator::init();
    }
    
    public function load_textdomain() {
        load_plugin_textdomain('quick-entry', false, dirname(plugin_basename(__FILE__)) . '/languages');
    }

    /**
     * Add "View details" and "Report a Bug" links to plugin row meta.
     *
     * @param array  $links       Plugin row meta links.
     * @param string $plugin_file Plugin file path.
     * @return array Modified plugin row meta links.
     */
    public function plugin_row_meta($links, $plugin_file) {
        if (plugin_basename(__FILE__) === $plugin_file) {
            // "View details" thickbox link
            $links[] = sprintf(
                '<a href="%s" class="thickbox open-plugin-details-modal" aria-label="%s" data-title="%s">%s</a>',
                esc_url(self_admin_url(
                    'plugin-install.php?tab=plugin-information&plugin=quick-entry'
                    . '&TB_iframe=true&width=772&height=926'
                )),
                esc_attr__('More information about QuickEntry', 'quick-entry'),
                esc_attr__('QuickEntry', 'quick-entry'),
                esc_html__('View details', 'quick-entry')
            );

            // "Report a Bug" link for Guilamu Bug Reporter
            if (class_exists('Guilamu_Bug_Reporter')) {
                $links[] = sprintf(
                    '<a href="#" class="guilamu-bug-report-btn" data-plugin-slug="%s" data-plugin-name="%s">%s</a>',
                    'quick-entry',
                    esc_attr__('QuickEntry', 'quick-entry'),
                    esc_html__('🐛 Report a Bug', 'quick-entry')
                );
            } else {
                $links[] = sprintf(
                    '<a href="%s" target="_blank">%s</a>',
                    'https://github.com/guilamu/guilamu-bug-reporter/releases',
                    esc_html__('🐛 Report a Bug (install Bug Reporter)', 'quick-entry')
                );
            }
        }
        return $links;
    }
}

/**
 * Register with Guilamu Bug Reporter
 */
add_action('plugins_loaded', function() {
    if (class_exists('Guilamu_Bug_Reporter')) {
        Guilamu_Bug_Reporter::register(array(
            'slug'        => 'quick-entry',
            'name'        => 'QuickEntry',
            'version'     => QENTRY_PLUGIN_VERSION,
            'github_repo' => 'guilamu/quick-entry',
        ));
    }
}, 20);

// Initialize the plugin
Quick_Entry::get_instance();