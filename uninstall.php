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

// Delete all options (use prepared LIKE query — L03)
$wpdb->query($wpdb->prepare(
    "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
    $wpdb->esc_like('qentry_') . '%'
));

// Delete all user meta
$wpdb->query($wpdb->prepare(
    "DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE %s",
    $wpdb->esc_like('_qentry_') . '%'
));

// Delete all transients
$wpdb->query($wpdb->prepare(
    "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
    $wpdb->esc_like('_transient_qentry_') . '%'
));
$wpdb->query($wpdb->prepare(
    "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
    $wpdb->esc_like('_transient_timeout_qentry_') . '%'
));

// Clear scheduled events
wp_clear_scheduled_hook('qentry_cleanup_expired_tokens');