<?php
/**
 * Weekly Newsletter Sender Uninstall Script
 * 
 * This file runs when the plugin is uninstalled.
 * It cleans up all plugin data from the database.
 * 
 * @package WeeklyNewsletterSender
 * @version 1.0.0
 * @author Marep
 * @license Proprietary
 */

// Prevent direct access
if (!defined('WP_UNINSTALL_PLUGIN')) {
    die('Direct access forbidden.');
}

// Security check - ensure we're actually uninstalling
if (!current_user_can('delete_plugins')) {
    return;
}

/**
 * Delete all plugin options from the database
 */
function wns_cleanup_options() {
    $options_to_delete = [
        // Basic settings
        'wns_subject',
        'wns_roles', 
        'wns_enabled',
        'wns_date_range_type',
        'wns_date_from',
        'wns_date_to',
        'wns_send_day',
        'wns_send_time',
        'wns_include_forum',
        'wns_include_wp',
        'wns_from_name',
        
        // Mail configuration
        'wns_mail_type',
        'wns_smtp_host',
        'wns_smtp_port', 
        'wns_smtp_username',
        'wns_smtp_password',
        'wns_smtp_encryption',
        
        // Email design settings
        'wns_email_header_color',
        'wns_email_accent_color',
        'wns_email_text_color',
        'wns_email_background_color',
        'wns_email_font_family',
        'wns_email_logo_url',
        'wns_email_footer_text',
        'wns_email_card_bg_color',
        'wns_email_card_border_color',
        'wns_email_header_text_size',
        'wns_email_content_padding',
        'wns_email_card_radius',
        'wns_email_line_height',
        'wns_email_meta_color',
        'wns_email_header_title',
        'wns_email_header_subtitle',
        'wns_email_intro_text',
        
        // License and security
        'wns_license_accepted',
        'wns_license_accepted_date',
        'wns_license_accepted_by',
        'wns_file_hash',
        'wns_hash_temp_fix_done'
    ];

    foreach ($options_to_delete as $option) {
        delete_option($option);
    }
    
    // Also clean up any site options (multisite)
    if (is_multisite()) {
        foreach ($options_to_delete as $option) {
            delete_site_option($option);
        }
    }
}

/**
 * Clear all scheduled cron events
 */
function wns_cleanup_cron() {
    wp_clear_scheduled_hook('wns_send_weekly_newsletter');
    
    // Clear any other potential scheduled events
    $scheduled_hooks = [
        'wns_send_weekly_newsletter',
        'wns_cleanup_temp_files',
        'wns_check_updates'
    ];
    
    foreach ($scheduled_hooks as $hook) {
        wp_clear_scheduled_hook($hook);
    }
}

/**
 * Clean up transients and temporary data
 */
function wns_cleanup_transients() {
    $transients_to_delete = [
        'wns_activation_notice',
        'wns_admin_notice',
        'wns_update_check',
        'wns_temp_data'
    ];
    
    foreach ($transients_to_delete as $transient) {
        delete_transient($transient);
        delete_site_transient($transient);
    }
}



function wns_uninstall_cleanup() {
    // Log the uninstall process
    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('[WNS] Starting plugin uninstall cleanup...');
    }
    
    // Run cleanup functions
    wns_cleanup_options();
    wns_cleanup_cron();
    wns_cleanup_transients();
    
    // Final cleanup - remove any uploaded files if applicable
    $upload_dir = wp_upload_dir();
    $plugin_upload_dir = $upload_dir['basedir'] . '/weekly-newsletter-sender';
    
    if (is_dir($plugin_upload_dir)) {
        // Remove plugin upload directory if it exists
        // Be very careful here - only remove if it's definitely our directory
        $files = glob($plugin_upload_dir . '/*');
        foreach ($files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        if (is_dir($plugin_upload_dir)) {
            rmdir($plugin_upload_dir);
        }
    }
    
    // Clear any cached data
    if (function_exists('wp_cache_flush')) {
        wp_cache_flush();
    }
    
    // Log completion
    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('[WNS] Plugin uninstall cleanup completed.');
    }
}

// Execute the cleanup
wns_uninstall_cleanup();

// End of uninstall script