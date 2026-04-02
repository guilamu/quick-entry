<?php
/**
 * QuickEntry Uninstall Script
 * Removes all plugin data when deleted from WordPress
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

global $wpdb;

// Delete custom table
$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}qentry_tokens");

// Delete all options
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE 'qentry_%'");

// Delete all user meta
$wpdb->query("DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE '_qentry_%'");

// Delete all transients
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_qentry_%'");
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_qentry_%'");