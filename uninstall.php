<?php
/**
 * Weekly Newsletter Sender Uninstall Script
 * 
 * This file runs when the plugin is uninstalled.
 * It cleans up all plugin data from the database.
 * 
 * @package WeeklyNewsletterSender
 * @version 1.0.2
 * @author Marep
 * @license GPL-2.0-or-later
 */

// Prevent direct access
if (!defined('WP_UNINSTALL_PLUGIN')) {
    die('Direct access forbidden.');
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
        'wns_post_types',
        'wns_language',
        'wns_version',
        'wns_admin_notices',
        'wns_next_scheduled_send',
        'wns_next_email_content',
        'wns_cronjob_enabled',
        'wns_cronjob_api_key',
        'wns_cronjob_id',
        
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
        'wns_email_intro_text_size',
        
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
    
    // Log completion
    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('[WNS] Plugin uninstall cleanup completed.');
    }
}

// Execute the cleanup
if (is_multisite()) {
    $offset = 0;
    do {
        $site_ids = get_sites(['fields' => 'ids', 'number' => 100, 'offset' => $offset]);
        foreach ($site_ids as $site_id) {
            switch_to_blog($site_id);
            wns_uninstall_cleanup();
            restore_current_blog();
        }
        $offset += count($site_ids);
    } while (count($site_ids) === 100);
} else {
    wns_uninstall_cleanup();
}

// End of uninstall script
