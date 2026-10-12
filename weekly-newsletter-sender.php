<?php
/**
 * Plugin Name: Weekly Newsletter Sender
 * Plugin URI: https://marep.sk/plugins/weekly-newsletter-sender
 * Description: Sends scheduled email digests of WordPress posts and wpForo discussions with customizable templates and SMTP support.
 * Version: 1.0.2
 * Author: Marep
 * Author URI: https://marep.sk
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: weekly-newsletter-sender
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Tested up to: 6.8
 * Update URI: https://github.com/matejrepka/wpforo-newsletter
 * Network: false
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    die('Direct access forbidden.');
}

// Plugin constants
if (!defined('WNS_PLUGIN_FILE')) {
    define('WNS_PLUGIN_FILE', __FILE__);
}
define('WNS_VERSION', '1.0.2');

require_once __DIR__ . '/includes/settings.php';
require_once __DIR__ . '/includes/scheduling.php';

// Default excerpt length (words) used in newsletters. Change this to show more/less text.
if (!defined('WNS_EXCERPT_WORDS')) {
    define('WNS_EXCERPT_WORDS', 80);
}

// Language system
$wns_translations = array();

/**
 * Load language translation file
 */
function wns_load_translations($language = 'en') {
    global $wns_translations;
    $language = wns_sanitize_setting('wns_language', $language);
    
    $language_file = dirname(WNS_PLUGIN_FILE) . '/languages/' . $language . '.php';
    
    if (file_exists($language_file)) {
        // Force fresh include (clear opcode cache if enabled)
        if (function_exists('opcache_invalidate')) {
            opcache_invalidate($language_file, true);
        }
        $wns_translations = include $language_file;
        
        // Debug logging
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log("WNS: Loaded translations from: $language_file");
            error_log("WNS: Translations count: " . count($wns_translations));
        }
    } else {
        // Fallback to English if requested language not found
        $fallback_file = dirname(WNS_PLUGIN_FILE) . '/languages/en.php';
        if (file_exists($fallback_file)) {
            $wns_translations = include $fallback_file;
            
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log("WNS: Language file $language_file not found, using fallback: $fallback_file");
            }
        } else {
            $wns_translations = [];
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log("WNS: No translation files found!");
            }
        }
    }
}

/**
 * Get translated string
 */
function wns_t($key, $default = '') {
    global $wns_translations;
    
    if (empty($wns_translations)) {
        $language = get_option('wns_language', 'en');
        wns_load_translations($language);
    }
    
    $result = isset($wns_translations[$key]) ? $wns_translations[$key] : ($default ? $default : $key);
    
    // Debug info (can be removed in production)
    if (!isset($wns_translations[$key]) && defined('WP_DEBUG') && WP_DEBUG) {
        error_log("WNS Translation missing: $key");
    }
    
    return $result;
}

/**
 * Echo translated string
 */
function wns_te($key, $default = '') {
    echo wns_t($key, $default);
}

// Load translations on plugin init and when language is updated
add_action('init', function() {
    $language = get_option('wns_language', 'en');
    wns_load_translations($language);
});

// Reload translations when language setting is updated
add_action('update_option_wns_language', function($old_value, $value, $option_name) {
    wns_load_translations($value);
}, 10, 3);

// Initialize the plugin

// Activation hook - simple activation without license enforcement
register_activation_hook(WNS_PLUGIN_FILE, function() {
    // Set a transient to show activation notice
    set_transient('wns_activation_notice', true, 30);
});

// Display simple activation notice
add_action('admin_notices', function() {
    // Activation notice
    if (get_transient('wns_activation_notice')) {
        delete_transient('wns_activation_notice');
        ?>
        <div class="notice notice-info is-dismissible" style="border-left-color: #2c5aa0;">
            <p><strong><?php wns_te('plugin_activated'); ?></strong></p>
            <p><?php printf(wns_t('activation_config_message'), admin_url('admin.php?page=wns-main')); ?></p>
        </div>
        <?php
    }
});

// AJAX handler for email preview generation
add_action('wp_ajax_wns_generate_email_preview', function() {
    check_ajax_referer('wns_preview_nonce', 'nonce');
    
    // Force fresh translation loading for AJAX context
    global $wns_translations;
    $wns_translations = null; // Clear any cached translations
    $language = get_option('wns_language', 'en');
    wns_load_translations($language);
    
    // Debug logging
    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log("WNS Preview: Language setting is: $language");
        error_log("WNS Preview: Translations loaded: " . (!empty($wns_translations) ? 'yes' : 'no'));
        if (!empty($wns_translations)) {
            error_log("WNS Preview: Sample translation for 'subject_line': " . (isset($wns_translations['subject_line']) ? $wns_translations['subject_line'] : 'not found'));
        }
    }
    
    if (!current_user_can('manage_options')) {
        wp_send_json_error(wns_t('insufficient_permissions'));
        return;
    }
    
    try {
        $period = wns_content_period(true);
        $target = $period['target'];
        $date_from_str = $period['from'];
        $date_to_str = $period['to'];

        // Get settings for what to include
        $include_wp = get_option('wns_include_wp', 1);
        $include_forum = get_option('wns_include_forum', 1);
        
        // Get WordPress posts for preview - with error handling
        $wp_posts = [];
        if ($include_wp) {
            try {
                $wp_posts_result = wns_get_wp_posts_summary($date_from_str, $date_to_str);
                $wp_posts = is_array($wp_posts_result) ? $wp_posts_result : [];
            } catch (Exception $e) {
                error_log('[WNS Preview] WordPress posts error: ' . $e->getMessage());
            }
        }
        
        // Get wpForo posts for preview - with error handling
        $wpforo_summary = [];
        if ($include_forum) {
            try {
                if (function_exists('wns_get_wpforo_summary')) {
                    $wpforo_result = wns_get_wpforo_summary($date_from_str, $date_to_str);
                    $wpforo_summary = is_array($wpforo_result) ? $wpforo_result : [];
                }
            } catch (Exception $e) {
                error_log('[WNS Preview] wpForo posts error: ' . $e->getMessage());
            }
        }
        
        // Generate email content safely
        $subject = '';
        $email_content = '';
        try {
            // Use the same subject logic as the actual sending function
            $subject = get_option('wns_subject', wns_t('default_subject'));
            // Add date range to subject for uniqueness (matching actual send logic)
            if ($date_from_str && $date_to_str) {
                $subject .= " (" . date('d.m.Y', strtotime($date_from_str)) . " - " . date('d.m.Y', strtotime($date_to_str)) . ")";
            }
            
            if (function_exists('wns_build_email')) {
                // Count total items for email building
                $forum_post_count = 0;
                foreach ($wpforo_summary as $forum_data) {
                    if (isset($forum_data['threads']) && is_array($forum_data['threads'])) {
                        foreach ($forum_data['threads'] as $thread_data) {
                            if (isset($thread_data['posts']) && is_array($thread_data['posts'])) {
                                $forum_post_count += count($thread_data['posts']);
                            }
                        }
                    }
                }
                $total_count = count($wp_posts) + $forum_post_count;
                
                $email_content = wns_build_email($wpforo_summary, $total_count, $wp_posts);
            } else {
                $email_content = '<p>Email builder function not available. Please check plugin configuration.</p>';
            }
        } catch (Exception $e) {
            error_log('[WNS Preview] Email build error: ' . $e->getMessage());
            $email_content = '<p>Error building email content: ' . esc_html($e->getMessage()) . '</p>';
        }
        
        // Get recipient count safely
        $recipients_count = 0;
        try {
            if (function_exists('wns_get_user_emails')) {
                $user_emails = wns_get_user_emails();
                $recipients_count = is_array($user_emails) ? count($user_emails) : 0;
            }
        } catch (Exception $e) {
            error_log('[WNS Preview] Recipients error: ' . $e->getMessage());
        }
        
        // Count forum activities safely
        $forum_activity_count = 0;
        try {
            foreach ($wpforo_summary as $forum_data) {
                if (isset($forum_data['threads']) && is_array($forum_data['threads'])) {
                    foreach ($forum_data['threads'] as $thread_data) {
                        if (isset($thread_data['posts']) && is_array($thread_data['posts'])) {
                            $forum_activity_count += count($thread_data['posts']);
                        }
                    }
                }
            }
        } catch (Exception $e) {
            error_log('[WNS Preview] Forum activity count error: ' . $e->getMessage());
        }
        
        // Create content status messages
        $wp_status = '';
        $forum_status = '';
        
        if (!$include_wp) {
            $wp_status = ' (WordPress posts disabled in settings)';
        } elseif (empty($wp_posts)) {
            $wp_status = ' (no new posts in this period)';
        }
        
        if (!$include_forum) {
            $forum_status = ' (Forum posts disabled in settings)';
        } elseif (empty($wpforo_summary)) {
            $forum_status = ' (no forum activity in this period)';
        }
        
        // Build preview HTML
        $preview_html = '
        <div class="wns-preview-meta">
          <div class="wns-preview-meta-grid">
            <div class="wns-preview-meta-item">
                <div class="wns-preview-meta-label">' . wns_t('subject_line') . '</div>
                <div class="wns-preview-meta-value">' . esc_html($subject) . '</div>
            </div>
            <div class="wns-preview-meta-item">
                <div class="wns-preview-meta-label">' . wns_t('recipients') . '</div>
                <div class="wns-preview-meta-value">' . $recipients_count . ' ' . wns_t('users') . '</div>
            </div>
            <div class="wns-preview-meta-item">
                <div class="wns-preview-meta-label">' . wns_t('content_period') . '</div>
                <div class="wns-preview-meta-value">' . date('M j', strtotime($date_from_str)) . ' - ' . date('M j, Y', strtotime($date_to_str)) . '</div>
            </div>
            <div class="wns-preview-meta-item">
                <div class="wns-preview-meta-label">' . wns_t('wordpress_posts') . '</div>
                <div class="wns-preview-meta-value">' . count($wp_posts) . ' ' . wns_t('posts') . ' ' . esc_html($wp_status) . '</div>
            </div>
            <div class="wns-preview-meta-item">
                <div class="wns-preview-meta-label">' . wns_t('forum_activities') . '</div>
                <div class="wns-preview-meta-value">' . $forum_activity_count . ' ' . wns_t('posts') . ' ' . wns_t('in') . ' ' . count($wpforo_summary) . ' ' . wns_t('forums') . ' ' . esc_html($forum_status) . '</div>
            </div>
            <div class="wns-preview-meta-item">
                <div class="wns-preview-meta-label">' . wns_t('scheduled_send') . '</div>
                <div class="wns-preview-meta-value">' . (isset($target) ? $target->format('M j, Y H:i') : wns_t('not_calculated')) . '</div>
            </div>
          </div>
        </div>
        <div class="wns-preview-email-content"></div>';

        
        wp_send_json_success(array(
            'html' => $preview_html,
            'email_html' => $email_content,
            'subject' => $subject,
            'recipients' => $recipients_count,
            'wp_posts_count' => count($wp_posts),
            'forum_activity_count' => $forum_activity_count,
            'date_from' => $date_from_str,
            'date_to' => $date_to_str,
            'scheduled_send' => isset($target) ? $target->format('Y-m-d H:i:s') : null
        ));
        
    } catch (Exception $e) {
        error_log('[WNS Preview] Error: ' . $e->getMessage());
        wp_send_json_error('Error generating preview. Check the date range and plugin configuration.');
    } catch (Error $e) {
        error_log('[WNS Preview] Fatal error: ' . $e->getMessage());
        wp_send_json_error('Fatal error generating preview. Please check your plugin configuration.');
    }
});

// Function to build proper newsletter subject based on scheduled send date
function wns_build_subject($target_date = null) {
    if ($target_date && $target_date instanceof DateTime) {
        $date_str = $target_date->format('F j, Y');
    } else {
        // Calculate the scheduled send date if not provided
        $send_day = get_option('wns_send_day', 'monday');
        $send_time = get_option('wns_send_time', '08:00');
        $timezone = wp_timezone();
        
        try {
            $target = new DateTime('this ' . $send_day, $timezone);
            $time_parts = explode(':', $send_time);
            $target->setTime((int)$time_parts[0], (int)$time_parts[1]);
            
            $now = new DateTime('now', $timezone);
            // Ensure $target represents the most recent scheduled send at or before now.
            // If 'this <day>' resolves to a future date (later this week), go back one week.
            if ($target > $now) {
                $target->modify('-1 week');
            }
            
            $date_str = $target->format('F j, Y');
        } catch (Exception $e) {
            // Fallback to next scheduled day
            $date_str = date('F j, Y', strtotime('next ' . $send_day));
        }
    }
    
    return wns_t('default_subject') . ' - ' . $date_str;
}

// --- ENCRYPTION FUNCTIONS ---
function wns_encrypt_password($password) {
    if (!is_string($password) || $password === '') {
        return '';
    }
    if (!function_exists('openssl_encrypt')) throw new RuntimeException('OpenSSL is required to save newsletter credentials.');
    $key = hash('sha256', wp_salt('auth') . wp_salt('secure_auth'), true);
    $iv = random_bytes(12);
    $tag = '';
    $encrypted = openssl_encrypt($password, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, 'WNS:v1', 16);
    if ($encrypted === false) throw new RuntimeException('Could not encrypt newsletter credentials.');
    return 'wns:v1:' . base64_encode($iv . $tag . $encrypted);
}

function wns_decrypt_password($encrypted_password) {
    if (!is_string($encrypted_password) || $encrypted_password === '' || !function_exists('openssl_decrypt')) {
        return '';
    }
    
    try {
        if (strpos($encrypted_password, 'wns:v1:') === 0) {
            $data = base64_decode(substr($encrypted_password, 7), true);
            if ($data === false || strlen($data) < 29) return '';
            $key = hash('sha256', wp_salt('auth') . wp_salt('secure_auth'), true);
            $decrypted = openssl_decrypt(substr($data, 28), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr($data, 0, 12), substr($data, 12, 16), 'WNS:v1');
            return $decrypted !== false ? $decrypted : '';
        }
        // Read version 1.0.0 ciphertext without requiring credentials to be re-entered.
        $key = wp_salt('auth') . wp_salt('secure_auth');
        $key = hash('sha256', $key);
        
        // Decode the base64 data
        $data = base64_decode($encrypted_password, true);
        if ($data === false || strlen($data) <= 16) return '';
        
        // Extract IV and encrypted password
        $iv = substr($data, 0, 16);
        $encrypted = substr($data, 16);
        
        // Decrypt the password
        $decrypted = openssl_decrypt($encrypted, 'AES-256-CBC', $key, 0, $iv);
        
        return $decrypted !== false ? $decrypted : '';
    } catch (Throwable $e) {
        error_log('[WNS] Password decryption failed.');
        return '';
    }
}

function wns_sanitize_secret($value, $option, $mask) {
    $current = get_option($option, '');
    if (!is_string($value)) return $current;
    if ($value === '') return '';
    if ($value === $mask || ($current !== '' && $value === $current)) return $current;
    // WordPress sanitizes new options twice; do not encrypt our output again.
    static $encrypted_values = [];
    if (isset($encrypted_values[$option][$value])) return $value;
    try {
        $encrypted = wns_encrypt_password($value);
        $encrypted_values[$option][$encrypted] = true;
        return $encrypted;
    } catch (Throwable $e) {
        add_settings_error($option, 'wns_encryption_failed', 'Credential could not be encrypted; the previous value was retained.');
        return $current;
    }
}

// --- CRON-JOB.ORG API INTEGRATION ---

/**
 * Sanitize and encrypt cron-job.org API key before saving
 */
function wns_sanitize_cronjob_api_key($api_key) {
    return wns_sanitize_secret($api_key, 'wns_cronjob_api_key', '••••••••••••••••••••••••••••••••');
}

/**
 * Make API call to cron-job.org
 */
function wns_cronjob_api_call($endpoint, $method = 'GET', $data = null) {
    $api_key_encrypted = get_option('wns_cronjob_api_key', '');
    
    if (empty($api_key_encrypted)) {
        return ['success' => false, 'error' => 'API key not configured'];
    }
    
    $api_key = wns_decrypt_password($api_key_encrypted);
    
    if (empty($api_key)) {
        return ['success' => false, 'error' => 'Failed to decrypt API key'];
    }
    
    $url = 'https://api.cron-job.org/jobs' . $endpoint;
    
    $args = [
        'method' => $method,
        'headers' => [
            'Authorization' => 'Bearer ' . $api_key,
            'Content-Type' => 'application/json',
        ],
        'timeout' => 30,
        'redirection' => 0,
    ];
    
    if ($data !== null) {
        $args['body'] = wp_json_encode($data);
    }
    
    $response = wp_remote_request($url, $args);
    
    if (is_wp_error($response)) {
        error_log('[WNS Cron-job.org] API call error: ' . $response->get_error_message());
        return ['success' => false, 'error' => $response->get_error_message()];
    }
    
    $status_code = wp_remote_retrieve_response_code($response);
    $body = wp_remote_retrieve_body($response);
    $decoded = json_decode($body, true);
    
    if ($status_code >= 200 && $status_code < 300) {
        return ['success' => true, 'data' => $decoded];
    } else {
        $error_message = isset($decoded['error']['message']) && is_string($decoded['error']['message']) ? sanitize_text_field($decoded['error']['message']) : 'Cron-job.org request failed';
        error_log('[WNS Cron-job.org] API error (status ' . $status_code . '): ' . $error_message);
        return ['success' => false, 'error' => $error_message, 'status' => $status_code];
    }
}

/**
 * Calculate cron expression for the scheduled send time + 5 minutes
 */
function wns_get_cronjob_schedule() {
    $target = wns_scheduled_target()->modify('+5 minutes');
    $local_time = $target->format('H:i');
    $local_day = $target->format('l');
    $timezone = wp_timezone()->getName();
    // Named zones let the external service follow daylight-saving changes.
    if (!in_array($timezone, timezone_identifiers_list(), true)) $timezone = 'UTC';
    $api_target = $target->setTimezone(new DateTimeZone($timezone));
    return [
        'success' => true,
        'schedule' => $api_target->format('i H') . ' * * ' . $api_target->format('w'),
        'time' => $local_time,
        'time_utc' => $target->setTimezone(new DateTimeZone('UTC'))->format('H:i'),
        'api_time' => $api_target->format('H:i'),
        'timezone' => $timezone,
        'day' => (int)$api_target->format('w'),
        'day_name' => $local_day,
    ];
}

/**
 * Create or update cron job on cron-job.org
 */
function wns_sync_cronjob() {
    if (!get_option('wns_cronjob_enabled', 0)) {
        return ['success' => true, 'message' => 'Cron-job.org integration disabled'];
    }
    
    $schedule = wns_get_cronjob_schedule();
    if (!$schedule['success']) {
        return $schedule;
    }
    
    // Get stored job ID
    $job_id = absint(get_option('wns_cronjob_id', 0));
    
    // Build the wp-cron.php URL
    $site_url = get_site_url();
    $cron_url = trailingslashit($site_url) . 'wp-cron.php?doing_wp_cron';
    
    // Parse the UTC time to get hour and minute
    list($hour, $minute) = explode(':', $schedule['api_time']);
    $day_of_week = (int)$schedule['day'];
    
    // Day is already in correct format (1-7) from wns_get_cronjob_schedule()
    
    $job_data = [
        'job' => [
            'enabled' => true,
            'title' => 'WordPress Newsletter Cron - ' . get_bloginfo('name'),
            'url' => $cron_url,
            'schedule' => [
                'timezone' => $schedule['timezone'],
                'expiresAt' => 0,
                'hours' => [(int)$hour],
                'mdays' => [-1],
                'minutes' => [(int)$minute],
                'months' => [-1],
                'wdays' => [$day_of_week],
            ],
            'requestMethod' => 0, // GET
            'extendedData' => [
                'headers' => [],
                'body' => ''
            ],
            'requestTimeout' => 30
        ]
    ];
    
    // If job exists, update it
    if (!empty($job_id)) {
        $result = wns_cronjob_api_call('/' . $job_id, 'PATCH', $job_data);
        
        if ($result['success']) {
            return ['success' => true, 'message' => 'Cron job updated successfully', 'job_id' => $job_id];
        } else {
            // Preserve existing jobs on network, authorization and service failures.
            if (!isset($result['status']) || $result['status'] !== 404) return $result;
            delete_option('wns_cronjob_id');
            $job_id = 0;
        }
    }
    
    // Create new job
    if (empty($job_id)) {
        $result = wns_cronjob_api_call('', 'PUT', $job_data);
        
        if ($result['success'] && isset($result['data']['jobId'])) {
            $new_job_id = absint($result['data']['jobId']);
            if (!$new_job_id) return ['success' => false, 'error' => 'Invalid cron job ID returned'];
            update_option('wns_cronjob_id', $new_job_id);
            return ['success' => true, 'message' => 'Cron job created successfully', 'job_id' => $new_job_id];
        } else {
            return ['success' => false, 'error' => isset($result['error']) ? $result['error'] : 'Failed to create cron job'];
        }
    }
}

/**
 * Delete cron job from cron-job.org
 */
function wns_delete_cronjob() {
    $job_id = absint(get_option('wns_cronjob_id', 0));
    
    if (empty($job_id)) {
        return ['success' => true, 'message' => 'No cron job to delete'];
    }
    
    $result = wns_cronjob_api_call('/' . $job_id, 'DELETE');
    
    if ($result['success'] || (isset($result['status']) && $result['status'] === 404)) {
        delete_option('wns_cronjob_id');
        return ['success' => true, 'message' => 'Cron job deleted successfully'];
    } else {
        return $result;
    }
}

/**
 * Test cron-job.org API connection
 */
function wns_test_cronjob_connection() {
    $result = wns_cronjob_api_call('', 'GET');
    
    if ($result['success']) {
        return ['success' => true, 'message' => 'API connection successful'];
    } else {
        return $result;
    }
}

// Sync once after all submitted settings (including the API key) have been saved.
function wns_queue_cronjob_sync() {
    static $queued = false;
    if ($queued) return;
    $queued = true;
    add_action('shutdown', function() {
        $result = get_option('wns_cronjob_enabled', 0) ? wns_sync_cronjob() : wns_delete_cronjob();
        if (!$result['success']) wns_add_admin_notice('cronjob_sync_failed', 'error', 'External cron job could not be synchronized. Test the API connection and retry synchronization.', 'configuration');
    });
}
foreach (['wns_send_day', 'wns_send_time', 'wns_cronjob_enabled', 'wns_cronjob_api_key', 'timezone_string', 'gmt_offset'] as $wns_cron_option) {
    add_action('update_option_' . $wns_cron_option, 'wns_queue_cronjob_sync');
    add_action('add_option_' . $wns_cron_option, 'wns_queue_cronjob_sync');
}
unset($wns_cron_option);

// --- DATA FETCHING ---
function wns_get_wpforo_summary($date_from = null, $date_to = null) {
    global $wpdb;
    if (!function_exists('WPF')) return [];
    $forum = WPF();
    if (!isset($forum->tables->posts, $forum->tables->topics, $forum->tables->forums, $forum->perm) ||
        !is_callable([$forum->perm, 'forum_can'])) return [];
    // Date logic
    if ($date_from && $date_to) {
        $where = $wpdb->prepare('p.created BETWEEN %s AND %s', $date_from . ' 00:00:00', $date_to . ' 23:59:59');
    } else {
        $date = date('Y-m-d H:i:s', strtotime('-7 days'));
        $where = $wpdb->prepare('p.created >= %s', $date);
    }
    $posts = $wpdb->get_results("
        SELECT p.*, t.title AS thread_subject, f.title AS forum_name
        FROM {$forum->tables->posts} p
        INNER JOIN {$forum->tables->topics} t ON t.topicid = p.topicid AND t.forumid = p.forumid
        INNER JOIN {$forum->tables->forums} f ON f.forumid = t.forumid
        WHERE $where AND p.status = 0 AND p.private = 0 AND t.status = 0 AND t.private = 0
        ORDER BY f.title ASC, t.title ASC, p.created ASC
    ");
    // Group by forum and thread
    $summary = [];
    foreach ((array)$posts as $post) {
        $fid = $post->forumid;
        $tid = $post->topicid;
        // A shared digest may contain only content visible to wpForo guests (group 4).
        if (!$forum->perm->forum_can('vf', $fid, [4]) || !$forum->perm->forum_can('vt', $fid, [4]) ||
            (empty($post->is_first_post) && !$forum->perm->forum_can('vr', $fid, [4]))) continue;
        if (!isset($summary[$fid])) {
            $summary[$fid] = [
                'forum_name' => $post->forum_name,
                'threads' => []
            ];
        }
        if (!isset($summary[$fid]['threads'][$tid])) {
            $summary[$fid]['threads'][$tid] = [
                'thread_subject' => $post->thread_subject,
                'posts' => []
            ];
        }
        $summary[$fid]['threads'][$tid]['posts'][] = $post;
    }
    return $summary;
}

// Fetch latest WordPress posts in date range
function wns_get_wp_posts_summary($date_from = null, $date_to = null) {
    $selected_post_types = wns_sanitize_setting('wns_post_types', get_option('wns_post_types', array('post')));
    if (empty($selected_post_types)) {
        return [];
    }
    
    $args = [
        'post_type' => $selected_post_types,
        'post_status' => 'publish',
        'has_password' => false,
        'orderby' => 'date',
        'order' => 'DESC',
        'posts_per_page' => 20,
    ];
    if ($date_from && $date_to) {
        $args['date_query'] = [
            [
                'after' => $date_from,
                'before' => $date_to . ' 23:59:59',
                'inclusive' => true,
            ]
        ];
    } elseif ($date_from) {
        $args['date_query'] = [
            [
                'after' => $date_from,
                'inclusive' => true,
            ]
        ];
    }
    $query = new WP_Query($args);
    return $query->posts;
}

// Add before wns_build_email or at the top of your file

function wns_format_quotes($text) {
    // Replace [quote ...]...[/quote] with styled blockquote (plain text only)
    return preg_replace_callback(
        '/\[quote([^\]]*)\](.*?)\[\/quote\]/is',
        function($m) {
            $quote = trim(strip_tags($m[2]));
            return '<blockquote class="wns-forum-quote">'.nl2br(esc_html($quote)).'</blockquote>';
        },
        $text
    );
}

// --- CENTRALIZED EMAIL CSS GENERATION ---
// This function generates the unified CSS for all email templates
function wns_generate_email_styles() {
    // Get design settings
    $header_color = get_option('wns_email_header_color', '#2c5aa0');
    $accent_color = get_option('wns_email_accent_color', '#0073aa');
    $text_color = get_option('wns_email_text_color', '#333333');
    $font_family = get_option('wns_email_font_family', 'Arial, sans-serif');
    $card_bg = get_option('wns_email_card_bg_color', '#f8f8f8');
    $card_border = get_option('wns_email_card_border_color', '#e5e5e5');
    $content_padding = get_option('wns_email_content_padding', '20');
    $card_radius = get_option('wns_email_card_radius', '7');
    $line_height = get_option('wns_email_line_height', '1.5');
    $intro_text_size = get_option('wns_email_intro_text_size', '20');
    $line_height = get_option('wns_email_line_height', '1.5');
    
    return "
/* Email body wrapper - ONLY targets actual body tags in emails/iframes */
body.wns-email-body {
    font-family: {$font_family};
    margin: 0;
    padding: {$content_padding}px;
    color: {$text_color};
    line-height: {$line_height};
}
.wns-email-content {
    color: {$text_color};
    background: #fff;
    padding: 0;
    margin: 0 auto;
    border-radius: {$card_radius}px;
    overflow: hidden;
    max-width: 700px;
    box-shadow: 0 8px 32px rgba(0,0,0,0.2);
    border: 1px solid {$header_color};
}
.wns-email-header {
    background: {$header_color};
    color: #fff;
    text-align: center;
    padding: {$content_padding}px;
}
.wns-email-header h1 {
    margin: 0 !important;
    padding: 0;
    border: none;
}
.wns-section-title {
    color: {$text_color};
    font-size: 1.45em;
    margin-top: 1.5em;
    margin-bottom: 0.8em;
    font-weight: 700;
    margin-left: {$content_padding}px;
}
.wns-intro {
    font-size: {$intro_text_size}px;
    font-weight: 600;
    margin-top: 1.5em;
    margin-bottom: 1.2em;
    margin-left: {$content_padding}px;
    color: {$text_color};
}
.wns-card {
    background: {$card_bg};
    border: 1px solid {$card_border};
    border-radius: {$card_radius}px;
    margin: 0 {$content_padding}px 1.5em;
    padding: {$content_padding}px;
    box-sizing: border-box;
}
.wns-wp-posts, .wns-forum-section {
    margin-bottom: 2em;
    padding: 0 {$content_padding}px;
}
.wns-forum-header, .wns-thread-header {
    display: flex;
    align-items: center;
    margin-top: 1.2em;
    margin-bottom: 0.7em;
    margin-left: {$content_padding}px;
}
.wns-category-label {
    color: #fff;
    background: {$accent_color};
    display: inline-block;
    padding: 0.18em 0.7em;
    border-radius: 6px;
    font-size: 1em;
    font-weight: 600;
    margin-right: 0.6em;
}
.wns-forum-label {
    color: #fff;
    background: {$header_color};
    display: inline-block;
    padding: 0.18em 0.7em;
    border-radius: 6px;
    font-size: 1em;
    font-weight: 600;
    margin-right: 0.6em;
}
.wns-forum-name {
    font-size: 1.10em;
    font-weight: bold;
    color: {$text_color};
}
.wns-topic-label {
    color: #fff;
    background: {$accent_color};
    display: inline-block;
    padding: 0.13em 0.6em;
    border-radius: 6px;
    font-size: 0.97em;
    font-weight: 600;
    margin-right: 0.6em;
}
.wns-thread-name {
    font-size: 1.02em;
    font-weight: bold;
    color: {$text_color};
}
.wns-post-title {
    font-size: 1.02em;
    font-weight: 700 !important;
    color: {$text_color};
    margin-bottom: 0.15em;
}
.wns-post-meta {
    color: {$text_color};
    font-size: 0.95em;
    margin-bottom: 0.3em;
}
/* For forum posts we want author/date to use the title style so it stands out */
.wns-forum-post .wns-post-meta {
    color: {$text_color};
    font-size: 1.02em;
    font-weight: 600;
    margin-bottom: 0.3em;
}
/* Reply label - subtle gray to avoid distracting from content */
.wns-reply-label {
    color: #777777;
    font-size: 0.95em;
    font-weight: 600;
    margin-bottom: 0.15em;
    display: inline-block;
}
.wns-post-excerpt {
    color: {$text_color};
    font-size: 1em;
    margin-bottom: 0.7em;
    margin-top: 0.1em;
    line-height: {$line_height};
}
.wns-forum-quote {
    border-left: 3px solid {$accent_color};
    background: #fffbe7;
    color: #444;
    margin: 0.5em 0 0.7em 0;
    padding: 0.5em 0.8em;
    font-style: italic;
    font-size: 0.97em;
    border-radius: 5px;
}
.wns-post-readmore {
    margin-top: 0.3em;
}
.wns-post-readmore a {
    color: {$accent_color};
    font-size: 0.98em;
    text-decoration: underline;
    font-weight: 600;
}
.wns-email-footer {
    background: #f5f5f5;
    color: #666;
    padding: 15px {$content_padding}px;
    font-size: 12px;
    text-align: center;
    border-top: 1px solid #e0e0e0;
    margin-top: 20px;
}
@media (max-width: 600px) { 
    .wns-email-content {margin: 1em;} 
    body.wns-email-body {padding: 10px;}
}
";
}

// --- UNIFIED EMAIL TEMPLATE WRAPPER ---
// This function wraps content in the standard email HTML template with CSS
function wns_wrap_email_template($content, $title = null) {
    if ($title === null) {
        $title = wns_t('default_header_title');
    }
    
    // Get design settings for inline styles
    $font_family = get_option('wns_email_font_family', 'Arial, sans-serif');
    $font_family_attr = esc_attr($font_family);
    $content_padding = get_option('wns_email_content_padding', '20');
    $text_color = get_option('wns_email_text_color', '#333333');
    $line_height = get_option('wns_email_line_height', '1.5');
    $card_radius = get_option('wns_email_card_radius', '7');
    $header_color = get_option('wns_email_header_color', '#2c5aa0');
    
    $html = "<!DOCTYPE html>
<html>
<head>
<title>" . esc_html($title) . "</title>
<meta charset='utf-8' />
<meta name='viewport' content='width=device-width, initial-scale=1.0' />
<style>
" . wns_generate_email_styles() . "
</style>
</head>
<body class='wns-email-body' style='font-family: {$font_family_attr}; margin:0; padding:{$content_padding}px; color: {$text_color}; line-height: {$line_height};'>
<div class='wns-email-content' style='color: {$text_color}; background:#fff; padding: 0; margin: 0 auto; border-radius: {$card_radius}px; overflow: hidden; max-width: 700px; box-shadow: 0 8px 32px rgba(0,0,0,0.2); border: 1px solid {$header_color};'>
{$content}
</div>
</body>
</html>";
    
    return $html;
}

// --- BUILD PREVIEW CONTENT ONLY (no header/styles) ---
function wns_build_preview_content_only($summary, $count, $wp_posts = []) {
    $include_wp = get_option('wns_include_wp', 1);
    $include_forum = get_option('wns_include_forum', 1);
    
    $message = '';

    // --- If no new posts ---
    if (
        (!$include_wp || empty($wp_posts)) &&
        (!$include_forum || empty($summary))
    ) {
        $accent_color = get_option('wns_email_accent_color', '#0073aa');
        $text_color = get_option('wns_email_text_color', '#333333');
        $message .= '<div class="wns-card" style="text-align:center; padding:2em 1em;">
            <p style="font-size:1.2em; color:'.$text_color.'; margin-bottom:1.2em;">'.wns_t('no_new_posts_this_week').'</p>
            <p>
                <a href="' . esc_url(home_url('/')) . '" style="color:'.$accent_color.'; font-weight:600; text-decoration:underline; margin-right:1.5em;">'.wns_t('visit_website').'</a>
                <a href="' . esc_url(site_url('/community/')) . '" style="color:'.$accent_color.'; font-weight:600; text-decoration:underline;">'.wns_t('visit_forum').'</a>
            </p>
            <p style="color:'.$text_color.'; font-size:0.98em; margin-top:1.5em;">'.wns_t('browse_older_content').'</p>
        </div>';
        return $message;
    }

    // --- Otherwise, show normal content ---
    // WordPress posts section
        if ($include_wp && !empty($wp_posts)) {
        // Group posts by first category
        $posts_by_cat = [];
        foreach ($wp_posts as $post) {
            $categories = get_the_category($post->ID);
            $cat_name = (!empty($categories)) ? $categories[0]->name : 'Uncategorized';
            $cat_slug = (!empty($categories)) ? $categories[0]->slug : 'uncategorized';
            if (!isset($posts_by_cat[$cat_slug])) {
                $posts_by_cat[$cat_slug] = [
                    'cat_name' => $cat_name,
                    'posts' => []
                ];
            }
            $posts_by_cat[$cat_slug]['posts'][] = $post;
        }

        $message .= '<section class="wns-wp-posts"><h2 class="wns-section-title">'.wns_t('latest_articles_website').'</h2>';
        foreach ($posts_by_cat as $cat) {
            $message .= '<div class="wns-forum-header"><span class="wns-category-label">'.wns_t('category_label').'</span><span class="wns-forum-name">'.esc_html($cat['cat_name']).'</span></div>';
            foreach ($cat['posts'] as $post) {
                $url = get_permalink($post->ID);
                $date = get_the_date('d.m.Y', $post->ID);
                $title = esc_html(get_the_title($post->ID));
                $excerpt_raw = wp_trim_words(strip_tags($post->post_content), WNS_EXCERPT_WORDS, '...');
                $excerpt = wns_format_quotes($excerpt_raw);

                $message .= "<div class='wns-card wns-post-card'>";
                $message .= "<div class='wns-post-title'>{$title}</div>";
                $message .= "<div class='wns-post-meta'>{$date}</div>";
                $message .= "<div class='wns-post-excerpt'>{$excerpt}</div>";
                $message .= "<div class='wns-post-readmore'><a href='{$url}'>Read more on website...</a></div>";
                $message .= "</div>";
            }
        }
        $message .= '</section>';
    }

    // Forum section
    if ($include_forum && !empty($summary)) {
        $message .= '<section class="wns-forum-section"><h2 class="wns-section-title">'.wns_t('latest_forum_posts').'</h2>';
        foreach ($summary as $forum) {
            $message .= '<div class="wns-forum-header"><span class="wns-forum-label">'.wns_t('forum').'</span><span class="wns-forum-name">'.esc_html($forum['forum_name']).'</span></div>';
            foreach ($forum['threads'] as $thread) {
                $message .= '<div class="wns-thread-header"><span class="wns-topic-label">'.wns_t('topic').'</span><span class="wns-thread-name">'.esc_html($thread['thread_subject']).'</span></div>';
                foreach ($thread['posts'] as $post) {
                    $url = esc_url(function_exists('wpforo_topic') ? wpforo_topic($post->topicid, 'url') : site_url("/community/topic/{$post->topicid}"));
                    $postdate = date('d.m.Y', strtotime($post->created));
                    $posttime = date('H:i', strtotime($post->created));
                    $author = isset($post->userid) ? esc_html(get_the_author_meta('display_name', $post->userid)) : '';
                    // Ensure we have a text color for inline styles in preview builder
                    $text_color = get_option('wns_email_text_color', '#333333');
                    $body = wp_kses_post(wns_format_quotes($post->body));
                    $excerpt_html = wns_truncate_html_words($body, WNS_EXCERPT_WORDS, $url);
                    $message .= "<div class='wns-card wns-forum-post'>";
                    $post_title_raw = trim((string)$post->title);
                    $is_reply = false;
                    if (empty($post_title_raw)) {
                        $is_reply = true;
                    } else {
                        if (preg_match('/^RE\s*[:\-]/i', $post_title_raw) || preg_match('/^RE\s+/i', $post_title_raw)) {
                            $is_reply = true;
                        }
                    }
                    if ($is_reply) {
                        // Only show the localized Reply label (no icon)
                        $message .= "<div class='wns-reply-label'>" . esc_html(wns_t('reply_label', 'Reply')) . "</div>";
                    }
                    // Inline style for post-meta so preview shows bold/colored text reliably
                    $meta_inline = 'style="color: ' . esc_attr($text_color) . '; font-weight:600; font-size:1.02em; margin-bottom:0.3em;"';
                    $message .= "<div class='wns-post-meta' {$meta_inline}>" . ($author ? esc_html($author) . ' &middot; ' : '') . "{$postdate} {$posttime}</div>";
                    $message .= "<div class='wns-post-excerpt'>{$excerpt_html}</div>";
                    $message .= "<div class='wns-post-readmore'><a href='{$url}'>Read more on forum...</a></div>";
                    $message .= "</div>";
                }
            }
        }
        $message .= '</section>';
    }

    return $message;
}

// --- INLINE STYLE HELPER FUNCTION ---
function wns_add_inline_styles($content) {
    // Get design settings for inline styles
    $header_color = get_option('wns_email_header_color', '#2c5aa0');
    $accent_color = get_option('wns_email_accent_color', '#0073aa');
    $text_color = get_option('wns_email_text_color', '#333333');
    $font_family = get_option('wns_email_font_family', 'Arial, sans-serif');
    $card_bg = get_option('wns_email_card_bg_color', '#f8f8f8');
    $card_border = get_option('wns_email_card_border_color', '#e5e5e5');
    $content_padding = get_option('wns_email_content_padding', '20');
    $card_radius = get_option('wns_email_card_radius', '7');
    $line_height = get_option('wns_email_line_height', '1.5');
    
    // Replace class-based elements with inline styles for better email client compatibility
    $replacements = [
        "class='wns-wp-posts'" => "class='wns-wp-posts' style='margin-bottom: 2em; padding: 0 {$content_padding}px;'",
        "class='wns-forum-section'" => "class='wns-forum-section' style='margin-bottom: 2em; padding: 0 {$content_padding}px;'",
        "class='wns-section-title'" => "class='wns-section-title' style='color: {$text_color}; font-size: 1.45em; margin-top: 1.5em; margin-bottom: 0.8em; font-weight: 700; margin-left: {$content_padding}px;'",
        "class='wns-post-title'" => "class='wns-post-title' style='font-size: 1.02em; font-weight: 700; color: {$text_color}; margin-bottom: 0.15em;'",
        "class='wns-card wns-post-card'" => "class='wns-card wns-post-card' style='background: {$card_bg}; border: 1px solid {$card_border}; border-radius: {$card_radius}px; margin: 0 {$content_padding}px 1.5em; padding: {$content_padding}px; box-sizing: border-box;'",
        "class='wns-card wns-forum-post'" => "class='wns-card wns-forum-post' style='background: {$card_bg}; border: 1px solid {$card_border}; border-radius: {$card_radius}px; margin: 0 {$content_padding}px 1.5em; padding: {$content_padding}px; box-sizing: border-box;'",
        "class='wns-card'" => "class='wns-card' style='background: {$card_bg}; border: 1px solid {$card_border}; border-radius: {$card_radius}px; margin: 0 {$content_padding}px 1.5em; padding: {$content_padding}px; box-sizing: border-box;'"
    ];
    
    foreach ($replacements as $search => $replace) {
        $content = str_replace($search, $replace, $content);
    }
    
    return $content;
}

// --- EMAIL BUILDING ---
function wns_build_email($summary, $count, $wp_posts = []) {
    // Use design intro text only - no fallback to old settings
    $intro = get_option('wns_email_intro_text', wns_t('intro_text_placeholder'));
    // Ensure intro is a string before str_replace
    if (!is_string($intro)) {
        $intro = wns_t('intro_text_placeholder');
    }
    $intro = str_replace('{count}', $count, $intro);
    $include_wp = get_option('wns_include_wp', 1);
    $include_forum = get_option('wns_include_forum', 1);
    
    // Get design settings
    $header_color = get_option('wns_email_header_color', '#2c5aa0');
    $accent_color = get_option('wns_email_accent_color', '#0073aa');
    $text_color = get_option('wns_email_text_color', '#333333');
    $font_family = get_option('wns_email_font_family', 'Arial, sans-serif');
    $logo_url = get_option('wns_email_logo_url', '');
    $footer_text = get_option('wns_email_footer_text', '');
    $card_bg = get_option('wns_email_card_bg_color', '#f8f8f8');
    $card_border = get_option('wns_email_card_border_color', '#e5e5e5');
    $header_text_size = get_option('wns_email_header_text_size', '28');
    $content_padding = get_option('wns_email_content_padding', '20');
    $card_radius = get_option('wns_email_card_radius', '7');
    $line_height = get_option('wns_email_line_height', '1.5');
    $header_title = get_option('wns_email_header_title', 'Weekly Newsletter');
    $header_subtitle = get_option('wns_email_header_subtitle', '');

    // Email header with logo or text
    $header_content = '';
    if (!empty($logo_url)) {
        $header_content = '<img src="' . esc_url($logo_url) . '" alt="Logo" style="max-height: 60px; max-width: 250px;" />';
        if (!empty($header_subtitle)) {
            $header_content .= '<div style="font-size: 16px; opacity: 0.9;">' . wp_kses_post($header_subtitle) . '</div>';
        }
    } else {
        $header_content = '<h1 style="margin: 0 !important; padding: 0; border: none; font-size: '.$header_text_size.'px; color: white;">' . wp_kses_post($header_title) . '</h1>';
        if (!empty($header_subtitle)) {
            $header_content .= '<div style="font-size: 16px; opacity: 0.9;">' . wp_kses_post($header_subtitle) . '</div>';
        }
    }

    $message = "<div class='wns-email-header' style='background: {$header_color}; color: #fff; text-align: center; padding: {$content_padding}px;'>{$header_content}</div>";

    // --- If no new posts ---
    if (
        (!$include_wp || empty($wp_posts)) &&
        (!$include_forum || empty($summary))
    ) {
        $message .= '<div class="wns-card" style="text-align:center; padding:2em 1em;">
            <p style="font-size:1.2em; color:'.$text_color.'; margin-bottom:1.2em;">'.wns_t('no_new_posts_this_week').'</p>
            <p>
                <a href="' . esc_url(home_url('/')) . '" style="color:'.$accent_color.'; font-weight:600; text-decoration:underline; margin-right:1.5em;">'.wns_t('visit_website').'</a>
                <a href="' . esc_url(site_url('/community/')) . '" style="color:'.$accent_color.'; font-weight:600; text-decoration:underline;">'.wns_t('visit_forum').'</a>
            </p>
            <p style="color:'.$text_color.'; font-size:0.98em; margin-top:1.5em;">'.wns_t('browse_older_content').'</p>
        </div>';
    }

    // --- Otherwise, show normal content ---
    // WordPress posts section
    if ($include_wp && !empty($wp_posts)) {
        // Group posts by first category
        $posts_by_cat = [];
        foreach ($wp_posts as $post) {
            $categories = get_the_category($post->ID);
            $cat_name = (!empty($categories)) ? $categories[0]->name : 'Uncategorized';
            $cat_slug = (!empty($categories)) ? $categories[0]->slug : 'uncategorized';
            if (!isset($posts_by_cat[$cat_slug])) {
                $posts_by_cat[$cat_slug] = [
                    'cat_name' => $cat_name,
                    'posts' => []
                ];
            }
            $posts_by_cat[$cat_slug]['posts'][] = $post;
        }

        $message .= '<section class="wns-wp-posts">';
        // Add intro at the top of the first section
        $intro_text_size = get_option('wns_email_intro_text_size', '20');
        $message .= '<div class="wns-intro" style="font-size: '.$intro_text_size.'px; font-weight: 600; margin-top: 1.5em; margin-bottom: 1.2em; color: '.$text_color.';">'.wp_kses_post($intro).'</div>';
        $message .= '<h2 class="wns-section-title">'.wns_t('latest_articles_website').'</h2>';
        foreach ($posts_by_cat as $cat) {
            $message .= '<div class="wns-forum-header"><span class="wns-category-label">'.wns_t('category_label').'</span><span class="wns-forum-name">'.esc_html($cat['cat_name']).'</span></div>';
            foreach ($cat['posts'] as $post) {
                $url = get_permalink($post->ID);
                $date = get_the_date('d.m.Y', $post->ID);
                $title = esc_html(get_the_title($post->ID));
                $excerpt_raw = wp_trim_words(strip_tags($post->post_content), WNS_EXCERPT_WORDS, '...');
                $excerpt = wns_format_quotes($excerpt_raw);

                $message .= "<div class='wns-card wns-post-card'>";
                $message .= "<div class='wns-post-title'>{$title}</div>";
                $message .= "<div class='wns-post-meta'>{$date}</div>";
                $message .= "<div class='wns-post-excerpt'>{$excerpt}</div>";
                $message .= "<div class='wns-post-readmore'><a href='{$url}'>Read more on website...</a></div>";
                $message .= "</div>";
            }
        }
        $message .= '</section>';
    }

    // Forum section
    if ($include_forum && !empty($summary)) {
        $message .= '<section class="wns-forum-section">';
        // Add intro at the top if WP posts section didn't already add it
        if (!$include_wp || empty($wp_posts)) {
            $intro_text_size = get_option('wns_email_intro_text_size', '20');
            $message .= '<div class="wns-intro" style="font-size: '.$intro_text_size.'px; font-weight: 600; margin-top: 1.5em; margin-bottom: 1.2em; color: '.$text_color.';">'.wp_kses_post($intro).'</div>';
        }
        $message .= '<h2 class="wns-section-title">'.wns_t('latest_forum_posts').'</h2>';
        foreach ($summary as $forum) {
            $message .= '<div class="wns-forum-header"><span class="wns-forum-label">'.wns_t('forum').'</span><span class="wns-forum-name">'.esc_html($forum['forum_name']).'</span></div>';
            foreach ($forum['threads'] as $thread) {
                $message .= '<div class="wns-thread-header"><span class="wns-topic-label">'.wns_t('topic').'</span><span class="wns-thread-name">'.esc_html($thread['thread_subject']).'</span></div>';
                foreach ($thread['posts'] as $post) {
                    $url = esc_url(function_exists('wpforo_topic') ? wpforo_topic($post->topicid, 'url') : site_url("/community/topic/{$post->topicid}"));
                    $postdate = date('d.m.Y', strtotime($post->created));
                    $posttime = date('H:i', strtotime($post->created));
                    $author = isset($post->userid) ? esc_html(get_the_author_meta('display_name', $post->userid)) : '';
                    $body = wp_kses_post(wns_format_quotes($post->body));
                    $excerpt_html = wns_truncate_html_words($body, WNS_EXCERPT_WORDS, $url);
                        $message .= "<div class='wns-card wns-forum-post'>";
                        // Determine if this post is a reply. Many forum systems prefix replies with 'RE:' or leave title empty.
                        $post_title_raw = trim((string)$post->title);
                        $is_reply = false;
                        if (empty($post_title_raw)) {
                            $is_reply = true;
                        } else {
                            // Case-insensitive check for RE: prefix
                            if (preg_match('/^RE\s*[:\-]/i', $post_title_raw) || preg_match('/^RE\s+/i', $post_title_raw)) {
                                $is_reply = true;
                            }
                        }

                        if ($is_reply) {
                            // Only show the localized Reply label (no icon)
                            $message .= "<div class='wns-reply-label'>" . esc_html(wns_t('reply_label', 'Reply')) . "</div>";
                        }

                        // Inline style for post-meta so preview shows bold/colored text reliably
                        $meta_inline = 'style="color: ' . esc_attr($text_color) . '; font-weight:700 !important; font-size:1.02em; margin-bottom:0.3em;"';
                        $message .= "<div class='wns-post-meta' {$meta_inline}>" . ($author ? esc_html($author) . ' &middot; ' : '') . "{$postdate} {$posttime}</div>";
                        $message .= "<div class='wns-post-excerpt'>{$excerpt_html}</div>";
                        $message .= "<div class='wns-post-readmore'><a href='{$url}'>".wns_t('read_more_forum')."</a></div>";
                        $message .= "</div>";
                }
            }
        }
        $message .= '</section>';
    }

    if (!empty($footer_text)) {
        $message .= '<div class="wns-email-footer">' . wp_kses_post($footer_text) . '</div>';
    }
    
    // Apply inline styles for better email client compatibility
    $message = wns_add_inline_styles($message);
    
    // Use unified template wrapper
    return wns_wrap_email_template($message);
}

/**
 * Truncate HTML by words, preserving <blockquote> and basic formatting.
 */
function wns_truncate_html_words($html, $max_words, $url = '') {
    $text = wp_kses($html, ['blockquote' => ['class' => true], 'b' => [], 'strong' => [], 'i' => [], 'em' => [], 'br' => []]);

    // Split by spaces, but keep tags
    $output = '';
    $word_count = 0;
    $truncated = false;
    $tokens = preg_split('/(<[^>]+>|[\s]+)/', $text, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);

    foreach ($tokens as $token) {
        if (preg_match('/<[^>]+>/', $token)) {
            // It's a tag, keep it
            $output .= $token;
        } else {
            // It's text, split into words
            $words = preg_split('/\s+/', $token, -1, PREG_SPLIT_NO_EMPTY);
            foreach ($words as $word) {
                if ($word_count < $max_words) {
                    $output .= ($word_count > 0 ? ' ' : '') . $word;
                    $word_count++;
                } else {
                    $truncated = true;
                    break 2;
                }
            }
        }
    }

    if ($truncated) {
        $output .= '...';
        if ($url) $output .= "<br />";
    }

    return force_balance_tags($output);
}

// --- GET USER EMAILS BY ROLE ---
function wns_get_user_emails() {
    $roles = wns_sanitize_setting('wns_roles', get_option('wns_roles', ['subscriber', 'administrator']));
    if (empty($roles)) return [];
    $args = [
        'role__in' => $roles,
        'fields' => ['user_email']
    ];
    $users = get_users($args);
    return array_values(array_unique(array_filter(wp_list_pluck($users, 'user_email'), 'is_email')));
}

// --- GENERATE DEMO EMAIL CONTENT ---
function wns_generate_demo_email_content() {
    // Demo WordPress posts
    $demo_wp_posts = [
        (object) [
            'ID' => 1,
            'post_title' => 'New Features in WordPress 6.5',
            'post_content' => 'WordPress 6.5 brings a wealth of new features and improvements that will simplify managing your websites. Among the most significant updates are an enhanced block editor, new customization options, and better performance optimizations.',
            'post_date' => date('Y-m-d H:i:s', strtotime('-2 days')),
            'category' => 'Technology'
        ],
        (object) [
            'ID' => 2,
            'post_title' => 'SEO Optimization Tips for 2025',
            'post_content' => 'SEO continues to evolve, and for 2025 the key trends include AI-optimized content, technical SEO, and user experience. Focus on quality content, loading speed, and mobile optimization for the best results.',
            'post_date' => date('Y-m-d H:i:s', strtotime('-1 day')),
            'category' => 'Marketing'
        ]
    ];
    
    // Demo forum posts
    $demo_forum_summary = [
        [
            'forum_name' => 'General Discussion',
            'threads' => [
                [
                    'thread_subject' => 'Beginner Questions',
                    'posts' => [
                        (object) [
                            'title' => 'How to start with programming?',
                            'body' => 'Hello everyone! I\'m a complete beginner in programming and would like to learn. Can you recommend which programming language would be best to start with? I\'m thinking between Python and JavaScript.',
                            'created' => date('Y-m-d H:i:s', strtotime('-3 hours')),
                            'userid' => 1,
                            'topicid' => 123
                        ]
                    ]
                ]
            ]
        ],
        [
            'forum_name' => 'Web Design',
            'threads' => [
                [
                    'thread_subject' => 'CSS Grid vs Flexbox',
                    'posts' => [
                        (object) [
                            'title' => 'When to use CSS Grid vs Flexbox?',
                            'body' => 'I often encounter the question of when it\'s better to use CSS Grid versus Flexbox. Can someone explain the main differences and practical applications? <blockquote>Grid is excellent for two-dimensional layouts, while Flexbox is ideal for one-dimensional ones.</blockquote>',
                            'created' => date('Y-m-d H:i:s', strtotime('-5 hours')),
                            'userid' => 2,
                            'topicid' => 124
                        ]
                    ]
                ]
            ]
        ]
    ];
    
    return ['wp_posts' => $demo_wp_posts, 'forum_summary' => $demo_forum_summary];
}

// Mock get_the_category for demo posts
function wns_get_demo_post_category($post_id, $demo_posts) {
    foreach ($demo_posts as $post) {
        if ($post->ID == $post_id) {
            return [(object)['name' => $post->category, 'slug' => sanitize_title($post->category)]];
        }
    }
    return [(object)['name' => 'General', 'slug' => 'general']];
}

// Mock get_the_date for demo posts
function wns_get_demo_post_date($format, $post_id, $demo_posts) {
    foreach ($demo_posts as $post) {
        if ($post->ID == $post_id) {
            return date($format, strtotime($post->post_date));
        }
    }
    return date($format);
}

// Mock get_permalink for demo posts
function wns_get_demo_post_permalink($post_id) {
    return home_url("/demo-post-{$post_id}/");
}

// Mock get_the_title for demo posts
function wns_get_demo_post_title($post_id, $demo_posts) {
    foreach ($demo_posts as $post) {
        if ($post->ID == $post_id) {
            return $post->post_title;
        }
    }
    return 'Demo Article';
}

// Mock get_the_author_meta for demo forum posts
function wns_get_demo_author_name($userid) {
    $names = [1 => 'John Smith', 2 => 'Sarah Johnson', 3 => 'Michael Brown'];
    return $names[$userid] ?? 'Demo User';
}

// --- BUILD DEMO EMAIL FOR PREVIEW ---
function wns_build_demo_email() {
    $demo_data = wns_generate_demo_email_content();
    $intro = get_option('wns_email_intro_text', wns_t('intro_text_placeholder'));
    // Ensure intro is a string before str_replace
    if (!is_string($intro)) {
        $intro = wns_t('intro_text_placeholder');
    }
    $count = count($demo_data['wp_posts']) + count($demo_data['forum_summary'][0]['threads'][0]['posts']) + count($demo_data['forum_summary'][1]['threads'][0]['posts']);
    $intro = str_replace('{count}', $count, $intro);
    
    // Get design settings
    $header_color = get_option('wns_email_header_color', '#2c5aa0');
    $accent_color = get_option('wns_email_accent_color', '#0073aa');
    $text_color = get_option('wns_email_text_color', '#333333');
    $font_family = get_option('wns_email_font_family', 'Arial, sans-serif');
    $logo_url = get_option('wns_email_logo_url', '');
    $footer_text = get_option('wns_email_footer_text', '');
    $card_bg = get_option('wns_email_card_bg_color', '#f8f8f8');
    $card_border = get_option('wns_email_card_border_color', '#e5e5e5');
    $header_text_size = get_option('wns_email_header_text_size', '28');
    $content_padding = get_option('wns_email_content_padding', '20');
    $card_radius = get_option('wns_email_card_radius', '7');
    $line_height = get_option('wns_email_line_height', '1.5');
    $header_title = get_option('wns_email_header_title', 'Weekly Newsletter');
    $header_subtitle = get_option('wns_email_header_subtitle', '');

    // Email header with logo or text
    $header_content = '';
    if (!empty($logo_url)) {
        $header_content = '<img src="' . esc_url($logo_url) . '" alt="Logo" style="max-height: 60px; max-width: 250px;" />';
        if (!empty($header_subtitle)) {
            $header_content .= '<div style="font-size: 16px; opacity: 0.9;">' . wp_kses_post($header_subtitle) . '</div>';
        }
    } else {
        $header_content = '<h1 style="margin: 0 !important; padding: 0; border: none; font-size: '.$header_text_size.'px; color: white;">' . wp_kses_post($header_title) . '</h1>';
        if (!empty($header_subtitle)) {
            $header_content .= '<div style="font-size: 16px; opacity: 0.9;">' . wp_kses_post($header_subtitle) . '</div>';
        }
    }

    $message = "<div class='wns-email-header' style='background: {$header_color}; color: #fff; text-align: center; padding: {$content_padding}px;'>{$header_content}</div>";

    // WordPress posts section with demo data
    $message .= '<section class="wns-wp-posts">';
    // Add intro at the top of the first section
    $intro_text_size = get_option('wns_email_intro_text_size', '20');
    $message .= '<div class="wns-intro" style="font-size: '.$intro_text_size.'px; font-weight: 600; margin-top: 1.5em; margin-bottom: 1.2em; margin-left: '.$content_padding.'px; margin-left: '.$content_padding.'px; color: '.$text_color.';">'.wp_kses_post($intro).'</div>';
    $message .= '<h2 class="wns-section-title">'.wns_t('latest_articles_website').'</h2>';
    $posts_by_cat = [];
    foreach ($demo_data['wp_posts'] as $post) {
        $cat_name = $post->category;
        $cat_slug = sanitize_title($cat_name);
        if (!isset($posts_by_cat[$cat_slug])) {
            $posts_by_cat[$cat_slug] = [
                'cat_name' => $cat_name,
                'posts' => []
            ];
        }
        $posts_by_cat[$cat_slug]['posts'][] = $post;
    }

    foreach ($posts_by_cat as $cat) {
        $message .= '<div class="wns-forum-header"><span class="wns-category-label">'.wns_t('category_label').'</span><span class="wns-forum-name">'.esc_html($cat['cat_name']).'</span></div>';
        foreach ($cat['posts'] as $post) {
            $url = wns_get_demo_post_permalink($post->ID);
            $date = date('M j, Y', strtotime($post->post_date));
            $title = esc_html($post->post_title);
            $excerpt_raw = wp_trim_words(strip_tags($post->post_content), WNS_EXCERPT_WORDS, '...');
            $excerpt = wns_format_quotes($excerpt_raw);

            $message .= "<div class='wns-card wns-post-card'>";
            $message .= "<div class='wns-post-title'>{$title}</div>";
            $message .= "<div class='wns-post-meta'>{$date}</div>";
            $message .= "<div class='wns-post-excerpt'>{$excerpt}</div>";
            $message .= "<div class='wns-post-readmore'><a href='{$url}'>".wns_t('read_more_website')."</a></div>";
            $message .= "</div>";
        }
    }
    $message .= '</section>';

    // Forum section with demo data
    $message .= '<section class="wns-forum-section"><h2 class="wns-section-title">'.wns_t('latest_forum_posts').'</h2>';
    foreach ($demo_data['forum_summary'] as $forum) {
        $message .= '<div class="wns-forum-header"><span class="wns-forum-label">'.wns_t('forum').'</span><span class="wns-forum-name">'.esc_html($forum['forum_name']).'</span></div>';
        foreach ($forum['threads'] as $thread) {
            $message .= '<div class="wns-thread-header"><span class="wns-topic-label">'.wns_t('topic_label').'</span><span class="wns-thread-name">'.esc_html($thread['thread_subject']).'</span></div>';
            foreach ($thread['posts'] as $post) {
                $url = site_url("/community/topic/{$post->topicid}");
                $postdate = date('M j, Y', strtotime($post->created));
                $posttime = date('H:i', strtotime($post->created));
                $author = wns_get_demo_author_name($post->userid);
                $body = wp_kses_post(wns_format_quotes($post->body));
                $excerpt_html = wns_truncate_html_words($body, WNS_EXCERPT_WORDS, $url);
                $message .= "<div class='wns-card wns-forum-post'>";
                // Determine if this post is a reply. Many forum systems prefix replies with 'RE:' or leave title empty.
                $post_title_raw = trim((string)$post->title);
                $is_reply = false;
                if (empty($post_title_raw)) {
                    $is_reply = true;
                } else {
                    // Case-insensitive check for RE: prefix
                    if (preg_match('/^RE\s*[:\-]/i', $post_title_raw) || preg_match('/^RE\s+/i', $post_title_raw)) {
                        $is_reply = true;
                    }
                }

                if ($is_reply) {
                    // Only show the localized Reply label (no icon)
                    $message .= "<div class='wns-reply-label'>" . esc_html(wns_t('reply_label', 'Reply')) . "</div>";
                }

                // Inline style for post-meta so preview shows bold/colored text reliably
                $meta_inline = 'style="color: ' . esc_attr($text_color) . '; font-weight:700 !important; font-size:1.02em; margin-bottom:0.3em;"';
                $message .= "<div class='wns-post-meta' {$meta_inline}>" . ($author ? esc_html($author) . ' &middot; ' : '') . "{$postdate} {$posttime}</div>";
                $message .= "<div class='wns-post-excerpt'>{$excerpt_html}</div>";
                $message .= "<div class='wns-post-readmore'><a href='{$url}'>".wns_t('read_more_forum')."</a></div>";
                $message .= "</div>";
            }
        }
    }
    $message .= '</section>';

    // Add footer if configured
    if (!empty($footer_text)) {
        $message .= '<div class="wns-email-footer">' . wp_kses_post($footer_text) . '</div>';
    }
    
    // Apply inline styles for better email client compatibility
    $message = wns_add_inline_styles($message);
    
    // Use unified template wrapper
    return wns_wrap_email_template($message, wns_t('weekly_newsletter_title'));
}

// --- MAIN SEND FUNCTION ---
function wns_send_newsletter($manual_override = false) {
    if (!get_option('wns_enabled', 0)) return ['success' => false, 'message' => 'Newsletter sending is disabled.'];
    $recipients = wns_get_user_emails();
    if (!$recipients) return ['success' => false, 'message' => 'No valid recipients match the selected roles.'];
    add_filter('wp_mail_from_name', 'wns_set_newsletter_from_name');
    try {
        $period = wns_content_period((bool)$manual_override);
        $summary = get_option('wns_include_forum', 1) ? wns_get_wpforo_summary($period['from'], $period['to']) : [];
        $wp_posts = get_option('wns_include_wp', 1) ? wns_get_wp_posts_summary($period['from'], $period['to']) : [];
        $count = count($wp_posts);
        foreach ($summary as $forum) foreach ($forum['threads'] as $thread) $count += count($thread['posts']);
        $content = wns_build_email($summary, $count, $wp_posts);
        $subject = sanitize_text_field(get_option('wns_subject', wns_t('default_subject')));
        $subject .= ' (' . date('d.m.Y', strtotime($period['from'])) . ' - ' . date('d.m.Y', strtotime($period['to'])) . ')';
        $sent_count = 0;
        foreach ($recipients as $email) {
            if (wns_send_email($email, $subject, $content, ['Content-Type: text/html; charset=UTF-8'])) $sent_count++;
        }
        update_option('wns_next_scheduled_send', wns_scheduled_target()->format('Y-m-d H:i:s'), false);
        $total = count($recipients);
        return ['success' => $sent_count === $total, 'sent' => $sent_count, 'total' => $total,
            'message' => sprintf('Mail transport accepted %d of %d newsletter messages.', $sent_count, $total)];
    } catch (Throwable $e) {
        error_log('[WNS] Newsletter could not be sent: ' . $e->getMessage());
        return ['success' => false, 'message' => 'Newsletter could not be sent. Check the date range and mail configuration.'];
    } finally {
        remove_filter('wp_mail_from_name', 'wns_set_newsletter_from_name');
    }
}

// --- EMAIL SENDING FUNCTION ---
function wns_send_email($to, $subject, $message, $headers = []) {
    $mail_type = get_option('wns_mail_type', 'wordpress');
    
    if ($mail_type === 'smtp') {
        return wns_send_email_smtp($to, $subject, $message, $headers);
    } else {
        return wp_mail($to, $subject, $message, $headers);
    }
}

function wns_send_email_smtp($to, $subject, $message, $headers = []) {
    $smtp_host = defined('WNS_SMTP_HOST') ? WNS_SMTP_HOST : get_option('wns_smtp_host', '');
    $smtp_port = defined('WNS_SMTP_PORT') ? WNS_SMTP_PORT : get_option('wns_smtp_port', 587);
    $smtp_username = defined('WNS_SMTP_USERNAME') ? WNS_SMTP_USERNAME : get_option('wns_smtp_username', '');
    $smtp_password = defined('WNS_SMTP_PASSWORD') ? WNS_SMTP_PASSWORD : wns_decrypt_password(get_option('wns_smtp_password', ''));
    $smtp_encryption = defined('WNS_SMTP_ENCRYPTION') ? WNS_SMTP_ENCRYPTION : get_option('wns_smtp_encryption', 'tls');
    if (!is_string($smtp_host) || $smtp_host === '' || !is_string($smtp_username) || $smtp_username === '' ||
        !is_string($smtp_password) || $smtp_password === '' || !is_numeric($smtp_port) || $smtp_port < 1 || $smtp_port > 65535 ||
        !in_array($smtp_encryption, ['tls', 'ssl', 'none'], true)) {
        error_log('[WNS] SMTP configuration is incomplete or invalid.');
        return false;
    }
    $snapshot = [];
    $mailer = null;
    $configure = function($phpmailer) use ($smtp_host, $smtp_port, $smtp_username, $smtp_password, $smtp_encryption, &$snapshot, &$mailer) {
        $mailer = $phpmailer;
        foreach (['Mailer', 'Host', 'Port', 'Username', 'Password', 'SMTPAuth', 'SMTPSecure', 'SMTPAutoTLS', 'From', 'FromName', 'Sender'] as $property) {
            $snapshot[$property] = $phpmailer->$property;
        }
        $phpmailer->isSMTP();
        $phpmailer->Host = $smtp_host;
        $phpmailer->Port = (int)$smtp_port;
        $phpmailer->SMTPAuth = true;
        $phpmailer->Username = $smtp_username;
        $phpmailer->Password = $smtp_password;
        $phpmailer->SMTPSecure = $smtp_encryption === 'none' ? '' : $smtp_encryption;
        $phpmailer->SMTPAutoTLS = $smtp_encryption !== 'none';
        $from_name = sanitize_text_field(get_option('wns_from_name', 'Newsletter'));
        if (is_email($smtp_username)) $phpmailer->setFrom($smtp_username, $from_name, false);
        else $phpmailer->FromName = $from_name;
    };
    add_action('phpmailer_init', $configure, PHP_INT_MAX);
    try {
        return wp_mail($to, $subject, $message, $headers);
    } finally {
        remove_action('phpmailer_init', $configure, PHP_INT_MAX);
        if ($mailer) {
            $mailer->smtpClose();
            foreach ($snapshot as $property => $value) $mailer->$property = $value;
        }
    }
}

// --- ADMIN NOTICE HELPERS ---
function wns_get_admin_notices() {
    $notices = get_option('wns_admin_notices', []);
    if (!is_array($notices)) $notices = [];
    return $notices;
}

/**
 * Add a persistent admin notice stored in options
 * @param string $message_key - translation key or identifier
 * @param string $type - 'success'|'error'|'info'
 * @param string $message_text - optional explicit text
 * @param string $tab - optional admin tab to scope the notice to
 * @param array $meta - optional meta flags (e.g. preserve_on_reload)
 */
function wns_add_admin_notice($message_key, $type = 'info', $message_text = '', $tab = '', $meta = []) {
    $notices = wns_get_admin_notices();
    $id = uniqid('wns_notice_');
    $notices[$id] = [
        'id' => $id,
        'key' => $message_key,
        'type' => $type,
        'text' => $message_text,
        'tab' => $tab,
        'meta' => is_array($meta) ? $meta : [],
        'timestamp' => time(),
    ];
    update_option('wns_admin_notices', $notices);
    return $id;
}

function wns_get_tab_from_url($url) {
    if (empty($url)) return '';
    $parts = parse_url($url);
    if (isset($parts['query'])) {
        parse_str($parts['query'], $query);
        if (isset($query['tab'])) return sanitize_text_field($query['tab']);
    }
    return '';
}


function wns_remove_admin_notice($id) {
    $notices = wns_get_admin_notices();
    if (isset($notices[$id])) {
        unset($notices[$id]);
        update_option('wns_admin_notices', $notices);
        return true;
    }
    return false;
}

// AJAX handler to dismiss admin notice
add_action('wp_ajax_wns_dismiss_admin_notice', function() {
    check_ajax_referer('wns_dismiss_notice', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error('no_permission', 403);
    }

    $removed = [];
    $errors = [];

    // Support a single id or multiple ids via form data.
    $ids = [];
    if (isset($_POST['id']) && is_string($_POST['id'])) {
        $ids[] = sanitize_text_field(wp_unslash($_POST['id']));
    }
    if (isset($_POST['ids']) && is_array($_POST['ids'])) {
        foreach (wp_unslash($_POST['ids']) as $i) if (is_string($i)) $ids[] = sanitize_text_field($i);
    }
    if (empty($ids)) {
        wp_send_json_error('missing_id', 400);
    }

    foreach ($ids as $id) {
        if (wns_remove_admin_notice($id)) {
            $removed[] = $id;
        } else {
            $errors[] = $id;
        }
    }

    if (!empty($removed)) {
        wp_send_json_success(['removed' => $removed, 'errors' => $errors]);
    }

    wp_send_json_error(['removed' => $removed, 'errors' => $errors], 404);
});

// --- SETTINGS PAGE ---
function wns_register_settings() {
    add_option('wns_subject', wns_t('default_subject', 'Weekly Newsletter'));
    // Only allow subscriber and administrator
    add_option('wns_roles', ['subscriber', 'administrator']);
    add_option('wns_enabled', 0);
    add_option('wns_date_range_type', 'week'); // new: week or custom
    add_option('wns_date_from', ''); // new: custom from
    add_option('wns_date_to', '');   // new: custom to
    add_option('wns_send_day', 'monday'); // new: day of week
    add_option('wns_send_time', '08:00'); // new: time of day
    add_option('wns_include_forum', 1); // new: include forum posts
    add_option('wns_include_wp', 1); // new: include wp posts
    add_option('wns_post_types', array('post')); // new: selected post types
    add_option('wns_from_name', 'Forum & News'); // new: from name for newsletter only
    add_option('wns_language', 'en'); // new: language preference
    
    // Mail configuration options
    add_option('wns_mail_type', 'wordpress'); // wordpress or smtp
    add_option('wns_smtp_host', '');
    add_option('wns_smtp_port', '587');
    add_option('wns_smtp_username', '');
    add_option('wns_smtp_password', '');
    add_option('wns_smtp_encryption', 'tls'); // tls, ssl, or none
    
    // Cron-job.org integration options
    add_option('wns_cronjob_enabled', 0); // Enable/disable cron-job.org integration
    add_option('wns_cronjob_api_key', ''); // Encrypted API key
    add_option('wns_cronjob_id', ''); // Stored job ID
    
    wns_register_setting('wns_options_group', 'wns_subject');
    wns_register_setting('wns_options_group', 'wns_roles'); // <-- Make editable
    wns_register_setting('wns_options_group', 'wns_enabled');
    wns_register_setting('wns_options_group', 'wns_date_range_type');
    wns_register_setting('wns_options_group', 'wns_date_from');
    wns_register_setting('wns_options_group', 'wns_date_to');
    wns_register_setting('wns_options_group', 'wns_send_day');
    wns_register_setting('wns_options_group', 'wns_send_time');
    wns_register_setting('wns_options_group', 'wns_include_forum');
    wns_register_setting('wns_options_group', 'wns_include_wp');
    wns_register_setting('wns_options_group', 'wns_post_types');
    wns_register_setting('wns_options_group', 'wns_from_name'); // new: from name
    
    // Language settings - separate group
    wns_register_setting('wns_language_group', 'wns_language'); // language preference
    
    // Email design settings
    wns_register_setting('wns_design_group', 'wns_email_header_color');
    wns_register_setting('wns_design_group', 'wns_email_accent_color');
    wns_register_setting('wns_design_group', 'wns_email_text_color');
    wns_register_setting('wns_design_group', 'wns_email_font_family');
    wns_register_setting('wns_design_group', 'wns_email_logo_url');
    wns_register_setting('wns_design_group', 'wns_email_footer_text');
    wns_register_setting('wns_design_group', 'wns_email_card_bg_color');
    wns_register_setting('wns_design_group', 'wns_email_card_border_color');
    wns_register_setting('wns_design_group', 'wns_email_header_text_size');
    wns_register_setting('wns_design_group', 'wns_email_content_padding');
    wns_register_setting('wns_design_group', 'wns_email_card_radius');
    wns_register_setting('wns_design_group', 'wns_email_line_height');
    wns_register_setting('wns_design_group', 'wns_email_header_title');
    wns_register_setting('wns_design_group', 'wns_email_header_subtitle');
    wns_register_setting('wns_design_group', 'wns_email_intro_text');
    wns_register_setting('wns_design_group', 'wns_email_intro_text_size');
    
    // Mail configuration settings - separate group
    wns_register_setting('wns_mail_group', 'wns_mail_type');
    wns_register_setting('wns_mail_group', 'wns_smtp_host');
    wns_register_setting('wns_mail_group', 'wns_smtp_port');
    wns_register_setting('wns_mail_group', 'wns_smtp_username');
    wns_register_setting('wns_mail_group', 'wns_smtp_password', [
        'sanitize_callback' => 'wns_sanitize_smtp_password'
    ]);
    wns_register_setting('wns_mail_group', 'wns_smtp_encryption');
    
    // Cron-job.org integration settings - separate group
    wns_register_setting('wns_cronjob_group', 'wns_cronjob_enabled');
    wns_register_setting('wns_cronjob_group', 'wns_cronjob_api_key', [
        'sanitize_callback' => 'wns_sanitize_cronjob_api_key'
    ]);
}

// Sanitize and encrypt SMTP password before saving
function wns_sanitize_smtp_password($password) {
    return wns_sanitize_secret($password, 'wns_smtp_password', '••••••••••••');
}

add_action('admin_init', 'wns_register_settings');

function wns_set_newsletter_from_name($name) {
    $custom_name = get_option('wns_from_name', 'Forum & News');
    return sanitize_text_field($custom_name);
}

function wns_settings_page() {
    if (!current_user_can('manage_options')) wp_die(wns_t('insufficient_permissions'));
    // Force fresh translation loading for admin context
    global $wns_translations;
    $wns_translations = []; // Clear any cached translations
    $language = get_option('wns_language', 'en');
    wns_load_translations($language);
    
    // Debug: Check if cron translations loaded
    if (defined('WP_DEBUG') && WP_DEBUG && isset($wns_translations)) {
        error_log('[WNS] Configuration tab - Translations loaded: ' . count($wns_translations));
        error_log('[WNS] Sample check - cronjob_integration: ' . (isset($wns_translations['cronjob_integration']) ? 'EXISTS' : 'MISSING'));
    }
    
    $current_tab = isset($_GET['tab']) && is_string($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : 'settings';
    if (!in_array($current_tab, ['settings', 'configuration', 'design', 'preview'], true)) $current_tab = 'settings';
    
    // Persistent admin notices are stored in option 'wns_admin_notices'
    // We'll render stored notices below and allow dismiss via AJAX.
    // If the settings page was just saved by WordPress (settings-updated), add a persistent notice.
    if (isset($_GET['settings-updated']) && $_GET['settings-updated'] === 'true') {
        // Add a persistent notice for settings saved (scoped to current tab)
        $current_tab_for_notice = $current_tab;
    wns_add_admin_notice('settings_saved', 'success', '', $current_tab_for_notice, ['preserve_on_reload' => true]);
    }
    
    $date_range_type = get_option('wns_date_range_type', 'week');
    $date_from = get_option('wns_date_from', '');
    $date_to = get_option('wns_date_to', '');
    $send_day = get_option('wns_send_day', 'monday');
    $send_time = get_option('wns_send_time', '08:00');
    $include_forum = get_option('wns_include_forum', 1);
    $include_wp = get_option('wns_include_wp', 1);
    $roles = get_option('wns_roles', ['subscriber', 'administrator']);
    $from_name = get_option('wns_from_name', 'Forum & News'); // new
    if (!is_array($roles)) {
        $roles = [$roles];
    }
    ?>
    <style>
    /* Fix for WordPress admin body padding interference */
    body.toplevel_page_wns-main {
        padding: 0 !important;
    }
    
    /* Paper-style grey, white, black admin UI */
    .wns-admin-wrapper {
        background: #fefefe;
        min-height: 100vh;
        margin: 0;
        font-family: ui-sans-serif, -apple-system, BlinkMacSystemFont, "Segoe UI", Helvetica, Arial, sans-serif;
        color: #2c2c2c;
        line-height: 1.5;
        --wns-focus: #2271b1;
    }
    
    .wns-admin-header {
        background: #f8f8f8;
        border-bottom: 1px solid #d8d8d8;
        padding: 48px 80px 0;
    }
    
    .wns-admin-header h1 {
        font-size: 32px;
        font-weight: 700;
        color: #2c2c2c;
        margin: 0 0 32px 0;
        font-family: ui-sans-serif, -apple-system, BlinkMacSystemFont, "Segoe UI", Helvetica, "Apple Color Emoji", Arial, sans-serif, "Segoe UI Emoji", "Segoe UI Symbol";
        line-height: 1.2;
    }
    
    .wns-admin-wrapper *, .wns-admin-wrapper *::before, .wns-admin-wrapper *::after { box-sizing: border-box; }
    .wns-admin-wrapper :is(a, button, input, select, textarea, summary):focus-visible {
        outline: 2px solid var(--wns-focus);
        outline-offset: 3px;
    }
    .wns-admin-wrapper input::placeholder, .wns-admin-wrapper textarea::placeholder { color: #646970; opacity: 1; }
    .wns-admin-wrapper :is(button, input[type="submit"]):disabled { cursor: wait; opacity: 0.65; }
    .wns-admin-wrapper .button, .wns-button { min-height: 44px; white-space: normal; }
    .wns-admin-wrapper ::selection { background: #d8e8f5; color: #1d2327; }
    .wns-admin-wrapper input, .wns-admin-wrapper textarea { caret-color: var(--wns-focus); }
    .wns-admin-footer > div { gap: 16px; flex-wrap: wrap; }
    .wns-admin-footer { overflow-wrap: anywhere; }
    .wns-notice-dismiss { min-width: 44px; min-height: 44px; }
    /* Tab Navigation */
    .wns-tab-nav {
        display: flex;
        flex-wrap: wrap;
        border-bottom: 1px solid #d8d8d8;
        margin-bottom: 0;
    }
    
    .wns-tab-button {
        background: none;
        border: none;
        padding: 16px 24px;
        font-size: 14px;
        font-weight: 500;
        color: #5a5a5a;
        cursor: pointer;
        border-bottom: 2px solid transparent;
        transition: all 0.2s ease;
        font-family: ui-sans-serif, -apple-system, BlinkMacSystemFont, "Segoe UI", Helvetica, "Apple Color Emoji", Arial, sans-serif, "Segoe UI Emoji", "Segoe UI Symbol";
        text-decoration: none;
        display: inline-block;
    }
    
    .wns-tab-button:hover {
        color: #2c2c2c;
        background: #f0f0f0;
    }
    
    .wns-tab-button.active {
        color: #2c2c2c;
        border-bottom-color: #4a4a4a;
        background: #fefefe;
    }
    
    .wns-admin-content {
        padding: 48px 80px;
        max-width: 1600px;
        background: #fefefe;
    }
    
    .wns-section {
        margin-bottom: 48px;
    }
    
    .wns-section-title {
        font-size: 20px;
        font-weight: 600;
        color: #2c2c2c;
        margin-bottom: 24px;
        font-family: ui-sans-serif, -apple-system, BlinkMacSystemFont, "Segoe UI", Helvetica, "Apple Color Emoji", Arial, sans-serif, "Segoe UI Emoji", "Segoe UI Symbol";
    }
    
    .wns-form-row {
        margin-bottom: 32px;
    }
    
    .wns-form-label {
        display: block;
        font-size: 14px;
        font-weight: 500;
        color: #2c2c2c;
        margin-bottom: 8px;
        font-family: ui-sans-serif, -apple-system, BlinkMacSystemFont, "Segoe UI", Helvetica, "Apple Color Emoji", Arial, sans-serif, "Segoe UI Emoji", "Segoe UI Symbol";
    }
    
    .wns-form-input {
        width: 100%;
        max-width: 430px;
        padding: 8px 12px;
        border: 1px solid #c8c8c8;
        border-radius: 2px;
        font-size: 14px;
        color: #2c2c2c;
        background: #fefefe;
        transition: border-color 0.15s ease, box-shadow 0.15s ease;
        font-family: ui-sans-serif, -apple-system, BlinkMacSystemFont, "Segoe UI", Helvetica, "Apple Color Emoji", Arial, sans-serif, "Segoe UI Emoji", "Segoe UI Symbol";
    }
    
    .wns-form-input:focus {
        outline: none;
        border-color: #8a8a8a;
        box-shadow: 0 0 0 1px rgba(138, 138, 138, 0.2);
    }
    
    /* Color input specific styling */
    .wns-form-input[type="color"] {
        width: 80px;
        height: 40px;
        padding: 4px;
        cursor: pointer;
        border-radius: 4px;
    }
    
    .wns-form-input[type="color"]::-webkit-color-swatch-wrapper {
        padding: 0;
        border-radius: 2px;
    }
    
    .wns-form-input[type="color"]::-webkit-color-swatch {
        border: none;
        border-radius: 2px;
    }
    
    /* Range input styling */
    .wns-form-range {
        width: 100%;
        max-width: 300px;
        min-height: 44px;
        height: 44px;
        border-radius: 3px;
        accent-color: #2c5aa0;
        background: transparent;
        margin: 8px 0;
    }
    
    .wns-form-range::-webkit-slider-thumb {
        -webkit-appearance: none;
        appearance: none;
        width: 18px;
        height: 18px;
        border-radius: 50%;
        background: #2c5aa0;
        cursor: pointer;
        border: 2px solid #fff;
        box-shadow: 0 2px 4px rgba(0,0,0,0.2);
    }
    
    .wns-form-range::-moz-range-thumb {
        width: 18px;
        height: 18px;
        border-radius: 50%;
        background: #2c5aa0;
        cursor: pointer;
        border: 2px solid #fff;
        box-shadow: 0 2px 4px rgba(0,0,0,0.2);
    }
    
    .wns-form-row span {
        font-weight: 600;
        color: #666;
        margin-left: 10px;
        min-width: 50px;
        display: inline-block;
    }
    
    .wns-form-select {
        width: 100%;
        max-width: 430px;
        padding: 8px 12px;
        border: 1px solid #c8c8c8;
        border-radius: 2px;
        font-size: 14px;
        color: #2c2c2c;
        background: #fefefe;
        transition: border-color 0.15s ease, box-shadow 0.15s ease;
        font-family: ui-sans-serif, -apple-system, BlinkMacSystemFont, "Segoe UI", Helvetica, "Apple Color Emoji", Arial, sans-serif, "Segoe UI Emoji", "Segoe UI Symbol";
    }
    
    .wns-form-select:focus {
        outline: none;
        border-color: #8a8a8a;
        box-shadow: 0 0 0 1px rgba(138, 138, 138, 0.2);
    }
    
    .wns-post-types { max-width: 430px; }

    .wns-form-help {
        font-size: 13px;
        color: #5a5a5a;
        margin-top: 6px;
        font-family: ui-sans-serif, -apple-system, BlinkMacSystemFont, "Segoe UI", Helvetica, "Apple Color Emoji", Arial, sans-serif, "Segoe UI Emoji", "Segoe UI Symbol";
    }
    
    .wns-checkbox-group {
        display: flex;
        gap: 24px;
        flex-wrap: wrap;
    }
    
    .wns-checkbox-label {
        display: flex;
        align-items: center;
        min-height: 44px;
        font-size: 14px;
        color: #2c2c2c;
        cursor: pointer;
        font-family: ui-sans-serif, -apple-system, BlinkMacSystemFont, "Segoe UI", Helvetica, "Apple Color Emoji", Arial, sans-serif, "Segoe UI Emoji", "Segoe UI Symbol";
    }
    
    .wns-checkbox-input {
        margin-right: 8px;
        width: 16px;
        height: 16px;
        accent-color: #4a4a4a;
        cursor: pointer;
        appearance: auto;
        -webkit-appearance: auto;
        border: 1px solid #c8c8c8;
        border-radius: 2px;
        background: #ffffff;
        position: relative;
        transition: all 0.15s ease;
    }
    
    .wns-checkbox-input::before { display: none; }

    .wns-checkbox-input:checked {
        background: #ffffff;
        border-color: #4a4a4a;
    }
    
    .wns-checkbox-input:checked::after {
        position: absolute;
        top: -1px;
        left: 2px;
        color: #4a4a4a;
        font-size: 12px;
        font-weight: bold;
    }
    
    .wns-checkbox-input:hover {
        border-color: #8a8a8a;
        background: #ffffff;
    }
    
    .wns-checkbox-input:focus {
        outline: none;
        border-color: #8a8a8a;
        box-shadow: 0 0 0 1px rgba(138, 138, 138, 0.2);
        background: #ffffff;
    }
    
    .wns-date-inputs {
        display: flex;
        gap: 16px;
        align-items: center;
        margin-top: 12px;
    }
    
    .wns-date-inputs input {
        max-width: 430px;
    }
    
    .wns-button {
        background: #4a4a4a;
        color: #fefefe;
        border: 1px solid #4a4a4a;
        border-radius: 2px;
        padding: 10px 20px;
        font-size: 14px;
        font-weight: 500;
        cursor: pointer;
        transition: all 0.15s ease;
        font-family: ui-sans-serif, -apple-system, BlinkMacSystemFont, "Segoe UI", Helvetica, "Apple Color Emoji", Arial, sans-serif, "Segoe UI Emoji", "Segoe UI Symbol";
        box-shadow: 0 1px 2px rgba(0,0,0,0.05);
    }
    
    .wns-button:hover {
        background: #3a3a3a;
        border-color: #3a3a3a;
        box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    }
    
    .wns-button-secondary {
        background: #f5f5f5;
        color: #2c2c2c;
        border: 1px solid #c8c8c8;
    }
    
    .wns-button-secondary:hover {
        background: #ebebeb;
        border-color: #b8b8b8;
        color: #2c2c2c;
        box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    }
    
    .wns-divider {
        height: 1px;
        background: #d8d8d8;
        margin: 48px 0;
    }
    
    .wns-info-text {
        font-size: 14px;
        color: #5a5a5a;
        margin-top: 16px;
        padding: 16px;
        background: #f8f8f8;
        border-radius: 2px;
        border: 1px solid #d8d8d8;
        font-family: ui-sans-serif, -apple-system, BlinkMacSystemFont, "Segoe UI", Helvetica, "Apple Color Emoji", Arial, sans-serif, "Segoe UI Emoji", "Segoe UI Symbol";
        box-shadow: 0 1px 2px rgba(0,0,0,0.02);
    }
    
    /* Tab Content */
    .wns-tab-content {
        display: none;
    }
    
    .wns-tab-content.active {
        display: block;
    }
    
    /* Design tab specific layout */
    .wns-design-container {
        display: flex;
        gap: 40px;
        align-items: flex-start;
        max-width: 1600px;
    }
    
    .wns-design-settings {
        flex: 0 0 400px;
        max-width: 400px;
    }
    
    .wns-design-preview {
        flex: 1;
        width: 100%;
        position: sticky;
        top: 32px;
        max-height: calc(100vh - 60px);
        overflow-y: auto;
        border-radius: 8px;
        padding: 20px;
        min-width: 0;
        margin-left: auto;
    }
    
    .wns-email-preview {
        background: white;
        border-radius: 8px;
        overflow: hidden;
        width: 100%;
        max-width: 800px;
        margin: 20px auto;
        padding: 0;
    }
    
    @media (max-width: 1400px) {
        .wns-design-container {
            flex-direction: column;
            max-width: none;
        }
        
        .wns-design-settings {
            flex: none;
            width: 100%;
            max-width: none;
        }
        
        .wns-design-preview {
            position: static;
            max-height: none;
            width: 100%;
            min-width: auto;
            margin-left: 0;
        }
    }
    
    @media (max-width: 800px) {
        .wns-design-preview {
            padding: 10px;
        }
        
        .wns-email-preview {
            max-width: 100%;
        }
    }
    
    @media (max-width: 960px) {
        .wns-admin-header { padding: 32px 24px 0; }
        .wns-admin-content { padding: 32px 24px; }
        .wns-notification { margin: 20px 24px 0; }
        .wns-admin-wrapper .wns-admin-footer { padding: 24px; }
    }
    @media (max-width: 600px) {
        .wns-admin-header { padding: 24px 16px 0; }
        .wns-admin-header h1 { font-size: 26px; margin-bottom: 20px; }
        .wns-admin-content { padding: 24px 16px; }
        .wns-tab-button { flex: 1 1 50%; padding: 12px; text-align: center; }
        .wns-language-container { flex-wrap: wrap; padding: 16px; }
        .wns-notification { margin: 16px 16px 0; }
        .wns-date-inputs { flex-direction: column; align-items: stretch; gap: 8px; }
        .wns-form-input, .wns-form-select { min-height: 44px; font-size: 16px; }
        .wns-form-input[type="color"] { height: 44px; }
        .wns-admin-wrapper .wns-admin-footer { padding: 24px 16px; }
    }
    @media (prefers-reduced-motion: reduce) {
        .wns-notification { animation: none; }
        .wns-language-option:hover { transform: none; }
        .wns-preview-loading .spinner { animation: none; }
    }
    /* Hide WP default styles for our custom implementation */
    .wns-admin-wrapper .wrap {
        margin: 0;
        padding: 0;
    }
    
    .wns-admin-wrapper .submit {
        margin: 0;
        padding: 0;
    }
    
    .wns-admin-wrapper .submit input {
        margin: 0;
    }
    
    /* Override WordPress default button styles completely */
    .wns-admin-wrapper .submit input[type="submit"],
    .wns-admin-wrapper input[type="submit"].button,
    .wns-admin-wrapper input[type="submit"].button-primary,
    .wns-admin-wrapper input[type="submit"].button-secondary {
        background: #4a4a4a !important;
        color: #fefefe !important;
        border: 1px solid #4a4a4a !important;
        border-radius: 2px !important;
        padding: 6px 14px !important;
        font-size: 13px !important;
        font-weight: 400 !important;
        cursor: pointer !important;
        transition: all 0.15s ease !important;
        font-family: ui-sans-serif, -apple-system, BlinkMacSystemFont, "Segoe UI", Helvetica, "Apple Color Emoji", Arial, sans-serif, "Segoe UI Emoji", "Segoe UI Symbol" !important;
        box-shadow: 0 1px 2px rgba(0,0,0,0.05) !important;
        text-shadow: none !important;
        text-decoration: none !important;
        height: auto !important;
        line-height: 1.2 !important;
    }
    
    .wns-admin-wrapper .submit input[type="submit"]:hover,
    .wns-admin-wrapper input[type="submit"].button:hover,
    .wns-admin-wrapper input[type="submit"].button-primary:hover,
    .wns-admin-wrapper input[type="submit"].button-secondary:hover {
        background: #3a3a3a !important;
        border-color: #3a3a3a !important;
        color: #fefefe !important;
        box-shadow: 0 2px 4px rgba(0,0,0,0.1) !important;
    }
    
    .wns-admin-wrapper .submit input[type="submit"].button-secondary,
    .wns-admin-wrapper input[type="submit"].button-secondary {
        background: #f5f5f5 !important;
        color: #2c2c2c !important;
        border: 1px solid #c8c8c8 !important;
    }
    
    .wns-admin-wrapper .submit input[type="submit"].button-secondary:hover,
    .wns-admin-wrapper input[type="submit"].button-secondary:hover {
        background: #ebebeb !important;
        border-color: #b8b8b8 !important;
        color: #2c2c2c !important;
        box-shadow: 0 2px 4px rgba(0,0,0,0.1) !important;
    }
    
    /* Paper texture effect for inputs */
    .wns-form-input,
    .wns-form-select {
        background: linear-gradient(135deg, #fefefe 0%, #fdfdfd 100%);
    }
    
    .wns-form-input:focus,
    .wns-form-select:focus {
        background: #fefefe;
    }
    
    /* Notification styles */
    .wns-notification {
        margin: 20px 80px 0;
        padding: 16px 20px;
        border-radius: 6px;
        border: 1px solid;
        background: #fff;
        box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        font-family: ui-sans-serif, -apple-system, BlinkMacSystemFont, "Segoe UI", Helvetica, Arial, sans-serif;
        font-size: 14px;
        line-height: 1.4;
        animation: wns-notification-slide 0.3s ease-out;
    }
    
    .wns-notification.success {
        border-color: #10b981;
        background: linear-gradient(135deg, #f0fdf4 0%, #ecfdf5 100%);
        color: #065f46;
    }
    
    .wns-notification.error {
        border-color: #ef4444;
        background: linear-gradient(135deg, #fef2f2 0%, #fee2e2 100%);
        color: #991b1b;
    }
    
    .wns-notification-icon {
        display: inline-block;
        width: 18px;
        height: 18px;
        margin-right: 8px;
        vertical-align: middle;
    }
    
    .wns-notification-message {
        display: inline-block;
        vertical-align: middle;
        font-weight: 500;
    }
    
    @keyframes wns-notification-slide {
        from {
            opacity: 0;
            transform: translateY(-10px);
        }
        to {
            opacity: 1;
            transform: translateY(0);
        }
    }
    
    /* Auto-hide animation */
    .wns-notification.auto-hide {
        animation: wns-notification-slide 0.3s ease-out, wns-notification-fadeout 0.5s ease-in 4s forwards;
    }
    
    @keyframes wns-notification-fadeout {
        to {
            opacity: 0;
            transform: translateY(-10px);
        }
    }
    </style>
    <div class="wns-admin-wrapper">
        <div class="wns-admin-header">
            <h1><?php wns_te('weekly_newsletter_sender'); ?></h1>
            <nav class="wns-tab-nav" aria-label="<?php echo esc_attr(wns_t('newsletter_navigation')); ?>">
                <a href="<?php echo admin_url('admin.php?page=wns-main&tab=settings'); ?>" 
                   class="wns-tab-button <?php echo $current_tab === 'settings' ? 'active' : ''; ?>" <?php if ($current_tab === 'settings') echo 'aria-current="page"'; ?>>
                    <?php wns_te('tab_settings'); ?>
                </a>
                <a href="<?php echo admin_url('admin.php?page=wns-main&tab=configuration'); ?>" 
                   class="wns-tab-button <?php echo $current_tab === 'configuration' ? 'active' : ''; ?>" <?php if ($current_tab === 'configuration') echo 'aria-current="page"'; ?>>
                    <?php wns_te('tab_configuration'); ?>
                </a>
                <a href="<?php echo admin_url('admin.php?page=wns-main&tab=design'); ?>" 
                   class="wns-tab-button <?php echo $current_tab === 'design' ? 'active' : ''; ?>" <?php if ($current_tab === 'design') echo 'aria-current="page"'; ?>>
                    <?php wns_te('tab_email_design'); ?>
                </a>
                <a href="<?php echo admin_url('admin.php?page=wns-main&tab=preview'); ?>" 
                   class="wns-tab-button <?php echo $current_tab === 'preview' ? 'active' : ''; ?>" <?php if ($current_tab === 'preview') echo 'aria-current="page"'; ?>>
                    <?php wns_te('tab_preview'); ?>
                </a>
            </nav>
        </div>
        

        
        <!-- Language Selection Section -->
        <div class="wns-language-section">
            <div class="wns-language-container">
                <span class="wns-language-label"><?php wns_te('language_preference'); ?>:</span>
                <form method="post" action="options.php" id="wns-language-form" class="wns-language-form">
                    <?php 
                    settings_fields('wns_language_group'); 
                    $current_language = get_option('wns_language', 'en');
                    ?>
                    <div class="wns-language-options" role="radiogroup" aria-label="<?php echo esc_attr(wns_t('language_preference')); ?>">
                        <label class="wns-language-option <?php echo $current_language === 'en' ? 'active' : ''; ?>">
                            <input type="radio" name="wns_language" value="en" <?php checked($current_language, 'en'); ?> />
                                                        <span class="wns-language-name"><?php wns_te('english'); ?></span>
                        </label>
                        <label class="wns-language-option <?php echo $current_language === 'sk' ? 'active' : ''; ?>">
                            <input type="radio" name="wns_language" value="sk" <?php checked($current_language, 'sk'); ?> />
                                                        <span class="wns-language-name"><?php wns_te('slovak'); ?></span>
                        </label>
                    </div>
                </form>
            </div>
        </div>
        
        <script>
        document.addEventListener('DOMContentLoaded', function() {
            const languageInputs = document.querySelectorAll('input[name="wns_language"]');
            const form = document.getElementById('wns-language-form');
            
            languageInputs.forEach(function(input) {
                input.addEventListener('change', function() {
                    if (this.checked) {
                        const currentLang = '<?php echo $current_language; ?>';
                        
                        if (this.value !== currentLang) {
                            const message = <?php echo wp_json_encode(wns_t('language_switch_confirm')); ?>;
                            if (confirm(message)) {
                                window.wnsLeavingConfirmed = true;
                                form.submit();
                            } else {
                                // Revert selection
                                document.querySelector('input[value="' + currentLang + '"]').checked = true;
                                // Update visual state
                                document.querySelectorAll('.wns-language-option').forEach(opt => opt.classList.remove('active'));
                                document.querySelector('input[value="' + currentLang + '"]').closest('.wns-language-option').classList.add('active');
                            }
                        }
                    }
                });
            });
        });
        </script>


        <?php
        // Render persistent admin notices stored in option
        $wns_notices = wns_get_admin_notices();
        if (!empty($wns_notices) && is_array($wns_notices)):
        ?>
            <div id="wns-notices-container">
            <?php foreach ($wns_notices as $nid => $n):
                // Only show notices that are global (no tab) or match current tab
                $notice_tab = isset($n['tab']) ? $n['tab'] : '';
                if (!empty($notice_tab) && $notice_tab !== $current_tab) continue;
                $type = isset($n['type']) ? $n['type'] : 'success';
                $key = isset($n['key']) ? $n['key'] : '';
                $text = isset($n['text']) && $n['text'] !== '' ? $n['text'] : wns_t($key);
            ?>
                <?php $preserve = (isset($n['meta']) && is_array($n['meta']) && !empty($n['meta']['preserve_on_reload'])); ?>
                <div role="status" class="wns-notification <?php echo esc_attr($type); ?>" data-wns-id="<?php echo esc_attr($nid); ?>" data-wns-tab="<?php echo esc_attr($notice_tab); ?>" data-wns-preserve="<?php echo $preserve ? '1' : '0'; ?>">
                    <span class="wns-notification-icon">
                        <?php if ($type === 'success'): ?>
                            ✓
                        <?php elseif ($type === 'error'): ?>
                            ⚠
                        <?php endif; ?>
                    </span>
                    <span class="wns-notification-message"><?php echo esc_html($text); ?></span>
                    <button type="button" class="wns-notice-dismiss" aria-label="<?php echo esc_attr(wns_t('dismiss_notice')); ?>" style="float:right; background:transparent; border:none; font-size:16px; cursor:pointer;">&times;</button>
                </div>
                <?php
                // Mark notice as displayed so it won't be shown again on subsequent loads.
                // We remove it from persistent storage here while leaving it in the DOM for the user
                // to dismiss during this page view. This ensures the notice appears once and
                // won't reappear after refresh or navigating between tabs.
                wns_remove_admin_notice($nid);
                ?>
            <?php endforeach; ?>
            </div>

            <script>
            (function(){
                function sendDismiss(ids, useBeacon) {
                    if (!ids || ids.length === 0) return;
                    try {
                        var payload = new FormData();
                        payload.append('action', 'wns_dismiss_admin_notice');
                        payload.append('nonce', '<?php echo esc_js(wp_create_nonce('wns_dismiss_notice')); ?>');
                        ids.forEach(function(id) { payload.append('ids[]', id); });
                        if (useBeacon && navigator.sendBeacon) {
                            navigator.sendBeacon(ajaxurl, payload);
                            return;
                        }
                        fetch(ajaxurl, { method: 'POST', body: payload, keepalive: !!useBeacon, credentials: 'same-origin' }).catch(function(){});
                    } catch(e) {}
                }

                // Single-click dismiss handler
                document.addEventListener('click', function(e){
                    var el = e.target;
                    if (el && el.classList && el.classList.contains('wns-notice-dismiss')) {
                        var wrapper = el.closest('.wns-notification');
                        if (!wrapper) return;
                        var id = wrapper.getAttribute('data-wns-id');
                        // Optimistically remove from DOM
                        wrapper.style.transition = 'opacity 0.2s ease';
                        wrapper.style.opacity = '0';
                        setTimeout(function(){ wrapper.remove(); }, 220);
                        // Use beacon/keepalive where possible so dismissal persists even if page reloads immediately
                        sendDismiss([id], true);
                    }
                });

                // Dismiss all visible notices (used on navigation/refresh/tab-switch)
                // includePreserved: if true, include notices marked as preserve_on_reload; default false
                function dismissAllVisible(includePreserved) {
                    includePreserved = !!includePreserved;
                    var wrappers = document.querySelectorAll('.wns-notification');
                    var ids = [];
                    if (!wrappers || wrappers.length === 0) return ids;
                    wrappers.forEach(function(w){
                        var preserve = w.getAttribute('data-wns-preserve');
                        if (!includePreserved && preserve && (preserve === '1' || preserve === 'true')) {
                            // skip preserved notices
                            return;
                        }
                        var id = w.getAttribute('data-wns-id');
                        if (id) ids.push(id);
                    });
                    // Remove non-preserved from DOM immediately
                    wrappers.forEach(function(w){
                        var preserve = w.getAttribute('data-wns-preserve');
                        if (!includePreserved && preserve && (preserve === '1' || preserve === 'true')) return;
                        w.parentNode && w.parentNode.removeChild(w);
                    });
                    // Return collected ids so caller can send batched dismiss
                    return ids;
                }

                // On page unload (refresh/navigate away) send dismisses so notices don't reappear
                var wnsSubmittingSettings = false;
                var settingsFormEl = document.getElementById('wns-settings-form');
                if (settingsFormEl) {
                    settingsFormEl.addEventListener('submit', function(){ wnsSubmittingSettings = true; });
                }

                window.addEventListener('beforeunload', function(){
                    if (wnsSubmittingSettings) return; // don't dismiss when saving settings
                    var ids = dismissAllVisible(true);
                    if (ids && ids.length) sendDismiss(ids, true);
                });
                window.addEventListener('pagehide', function(){
                    if (wnsSubmittingSettings) return;
                    var ids = dismissAllVisible(true);
                    if (ids && ids.length) sendDismiss(ids, true);
                });

                // When clicking our own tab buttons, dismiss visible notices first so they don't persist across tabs
                document.addEventListener('click', function(e){
                    var el = e.target;
                    if (el && el.matches && el.matches('.wns-tab-button')) {
                        // dismiss and send via beacon/keepalive
                        var ids = dismissAllVisible(true);
                        if (ids && ids.length) sendDismiss(ids, true);
                    }
                });
            })();
            </script>
        <?php endif; ?>
        
        <style>
        .wns-language-section {
            background: linear-gradient(135deg, #2c2c2c 0%, #1a1a1a 100%);
            border-radius: 8px;
            margin-bottom: 20px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.15);
            border: 1px solid #444;
        }
        
        .wns-language-container {
            padding: 16px 24px;
            display: flex;
            align-items: center;
            gap: 16px;
        }
        
        .wns-language-label {
            color: #e0e0e0;
            font-weight: 600;
            font-size: 14px;
            white-space: nowrap;
        }
        
        .wns-language-form {
            margin: 0;
        }
        
        .wns-language-options {
            display: flex;
            gap: 8px;
        }
        
        .wns-language-option {
            display: flex;
            align-items: center;
            gap: 6px;
            background: rgba(255, 255, 255, 0.05);
            border: 2px solid #555;
            border-radius: 20px;
            padding: 6px 12px;
            cursor: pointer;
            transition: all 0.2s ease;
            color: #cccccc;
            font-size: 13px;
            font-weight: 500;
        }
        
        .wns-language-option:hover {
            background: rgba(255, 255, 255, 0.1);
            border-color: #777;
            transform: translateY(-1px);
            color: #ffffff;
        }
        
        .wns-language-option.active {
            background: #ffffff;
            border-color: #ffffff;
            color: #333;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.25);
        }
        
        .wns-language-option input[type="radio"] {
            position: absolute;
            opacity: 0;
            width: 1px;
            height: 1px;
        }
        .wns-language-option:focus-within {
            outline: 2px solid #fff;
            outline-offset: 3px;
        }
        
        .wns-flag {
            font-size: 16px;
            filter: grayscale(0);
        }
        
        .wns-language-option:not(.active) .wns-flag {
            filter: grayscale(0.7) brightness(0.8);
        }
        
        .wns-language-name {
            font-weight: 600;
        }
        </style>

        <div class="wns-admin-content<?php echo $current_tab === 'preview' ? ' wns-preview-active' : ''; ?>">
            
            <!-- Settings Tab -->
            <div class="wns-tab-content <?php echo $current_tab === 'settings' ? 'active' : ''; ?>">
                <div class="wns-section">
                    <form id="wns-settings-form" method="post" action="options.php">
                        <?php settings_fields('wns_options_group'); ?>
                        
                        <div class="wns-form-row">
                            <div class="wns-form-label"><?php wns_te('enable_newsletter'); ?></div>
                            <label class="wns-checkbox-label">
                                <input type="checkbox" name="wns_enabled" value="1" class="wns-checkbox-input" <?php checked(1, get_option('wns_enabled'), true); ?> />
                                <?php wns_te('enable_disable_sending'); ?>
                            </label>
                        </div>
                        
                        <div class="wns-form-row">
                            <label class="wns-form-label" for="wns-from-name"><?php wns_te('from_name_label'); ?></label>
                            <input id="wns-from-name" aria-describedby="wns-from-name-help" type="text" name="wns_from_name" value="<?php echo esc_attr($from_name); ?>" class="wns-form-input" />
                            <div class="wns-form-help" id="wns-from-name-help"><?php wns_te('from_name_help'); ?></div>
                        </div>
                        
                        <div class="wns-form-row">
                            <label class="wns-form-label" for="wns-subject"><?php wns_te('email_subject'); ?></label>
                            <input id="wns-subject" type="text" name="wns_subject" value="<?php echo esc_attr(get_option('wns_subject')); ?>" class="wns-form-input" />
                        </div>
                        
                        <div class="wns-form-row">
                            <div class="wns-form-label" id="wns-roles-label"><?php wns_te('user_roles_to_send'); ?></div>
                            <div class="wns-checkbox-group" role="group" aria-labelledby="wns-roles-label" aria-describedby="wns-roles-help">
                                <?php foreach (get_editable_roles() as $role_key => $role_details): ?>
                                    <label class="wns-checkbox-label">
                                        <input type="checkbox" name="wns_roles[]" value="<?php echo esc_attr($role_key); ?>" class="wns-checkbox-input" <?php checked(in_array($role_key, $roles, true)); ?> />
                                        <?php echo esc_html(in_array($role_key, ['subscriber', 'administrator'], true) ? wns_t('role_' . $role_key) : ($role_details['name'] ?? ucwords(str_replace('_', ' ', $role_key)))); ?>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                            <div class="wns-form-help" id="wns-roles-help"><?php wns_te('roles_help'); ?></div>
                        </div>
                        
                        <div class="wns-form-row">
                            <label class="wns-form-label" for="wns_date_range_type"><?php wns_te('date_range_newsletter'); ?></label>
                            <select name="wns_date_range_type" id="wns_date_range_type" class="wns-form-select">
                                <option value="week" <?php selected($date_range_type, 'week'); ?>><?php wns_te('latest_this_week'); ?></option>
                                <option value="custom" <?php selected($date_range_type, 'custom'); ?>><?php wns_te('custom_range'); ?></option>
                            </select>
                            <div id="wns_custom_dates" class="wns-date-inputs" style="<?php if($date_range_type!=='custom') echo 'display:none;'; ?>">
                                <label for="wns-date-from"><?php wns_te('from_label'); ?></label>
                                <input type="date" id="wns-date-from" name="wns_date_from" value="<?php echo esc_attr($date_from); ?>" class="wns-form-input">
                                <label for="wns-date-to"><?php wns_te('to_label'); ?></label>
                                <input type="date" id="wns-date-to" name="wns_date_to" value="<?php echo esc_attr($date_to); ?>" class="wns-form-input">
                            </div>
                            <div class="wns-form-help"><?php wns_te('date_range_help'); ?></div>
                            <script>
                            document.getElementById('wns_date_range_type').addEventListener('change', function() {
                                document.getElementById('wns_custom_dates').style.display = this.value === 'custom' ? '' : 'none';
                            });
                            </script>
                        </div>
                        
                        <div class="wns-form-row">
                            <div class="wns-form-label"><?php wns_te('include_forum_posts'); ?></div>
                            <label class="wns-checkbox-label">
                                <input type="checkbox" name="wns_include_forum" value="1" class="wns-checkbox-input" <?php checked(1, $include_forum, true); ?> />
                                <?php wns_te('include_wpforo_posts'); ?>
                            </label>
                        </div>
                        
                        <div class="wns-form-row">
                            <div class="wns-form-label"><?php wns_te('include_website_posts'); ?></div>
                            <label class="wns-checkbox-label">
                                <input type="checkbox" name="wns_include_wp" value="1" class="wns-checkbox-input" <?php checked(1, $include_wp, true); ?> />
                                <?php wns_te('include_wordpress_posts'); ?>
                            </label>
                        </div>
                        
                        <div class="wns-form-row">
                            <div class="wns-form-label" id="wns-post-types-label"><?php wns_te('post_types_label'); ?></div>
                            <?php
                            $selected_post_types = get_option('wns_post_types', array('post'));
                            $post_types = get_post_types(array('public' => true), 'objects');
                            // Exclude attachments and navigation menu items
                            unset($post_types['attachment']);
                            unset($post_types['nav_menu_item']);
                            ?>
                            
                            <div class="wns-checkbox-group wns-post-types" role="group" aria-labelledby="wns-post-types-label" aria-describedby="wns-post-types-help">
                                <?php foreach ($post_types as $post_type): ?>
                                    <label class="wns-checkbox-label">
                                        <input type="checkbox" name="wns_post_types[]" value="<?php echo esc_attr($post_type->name); ?>" class="wns-checkbox-input" <?php checked(in_array($post_type->name, (array)$selected_post_types, true)); ?> />
                                        <?php echo esc_html($post_type->label); ?>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                            <div class="wns-form-help" id="wns-post-types-help"><?php wns_te('post_types_help'); ?></div>
                        </div>
                        
                        <div class="wns-form-row">
                            <label class="wns-form-label" for="wns-send-day"><?php wns_te('send_day'); ?></label>
                            <select id="wns-send-day" name="wns_send_day" class="wns-form-select">
                                <option value="monday" <?php selected($send_day, 'monday'); ?>><?php wns_te('monday'); ?></option>
                                <option value="tuesday" <?php selected($send_day, 'tuesday'); ?>><?php wns_te('tuesday'); ?></option>
                                <option value="wednesday" <?php selected($send_day, 'wednesday'); ?>><?php wns_te('wednesday'); ?></option>
                                <option value="thursday" <?php selected($send_day, 'thursday'); ?>><?php wns_te('thursday'); ?></option>
                                <option value="friday" <?php selected($send_day, 'friday'); ?>><?php wns_te('friday'); ?></option>
                                <option value="saturday" <?php selected($send_day, 'saturday'); ?>><?php wns_te('saturday'); ?></option>
                                <option value="sunday" <?php selected($send_day, 'sunday'); ?>><?php wns_te('sunday'); ?></option>
                            </select>
                        </div>
                        
                        <div class="wns-form-row">
                            <label class="wns-form-label" for="wns-send-time"><?php wns_te('send_time'); ?></label>
                            <input id="wns-send-time" type="time" name="wns_send_time" value="<?php echo esc_attr($send_time); ?>" class="wns-form-input" />
                        </div>
                        
                        <div class="wns-form-row">
                            <div class="wns-form-label"><?php wns_te('next_scheduled_send'); ?></div>
                            <div class="wns-info-text">
                                <?php
                                $send_day = get_option('wns_send_day', 'monday');
                                $send_time = get_option('wns_send_time', '08:00');
                                $timezone = wp_timezone();
                                $next = new DateTime('now', $timezone);
                                
                                // More robust date construction
                                try {
                                    $target = wns_scheduled_target();
                                    echo esc_html($target->format('l, d.m.Y H:i')) . ' (' . esc_html($timezone->getName()) . ')';
                                } catch (Exception $e) {
                                    wns_te('error_calculating_send_time');
                                }
                                ?>
                            </div>
                            <div style="margin-top: 10px;">
                                <a href="<?php echo admin_url('admin.php?page=wns-main&tab=preview'); ?>" class="button button-secondary">
                                    <?php wns_te('preview_next_email'); ?>
                                </a>
                                <span style="color: #666; font-size: 13px; margin-left: 10px;">
                                    <?php wns_te('preview_help_text'); ?>
                                </span>
                            </div>
                        </div>
                        
                        <?php submit_button(wns_t('save_settings'), 'primary large wns-button'); ?>
                    </form>
                </div>
                
                <div class="wns-divider"></div>
                
                    <div class="wns-section">
                    <h2 class="wns-section-title"><?php wns_te('manual_send'); ?></h2>
                    <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
                        <?php wp_nonce_field('wns_manual_send_action', 'wns_manual_send_nonce'); ?>
                        <input type="hidden" name="action" value="wns_manual_send" />
                        <?php submit_button(wns_t('send_newsletter_now'), 'secondary large wns-button wns-button-secondary'); ?>
                    </form>
                </div>
            </div>
            
            <!-- Email Design Tab -->
            <div class="wns-tab-content <?php echo $current_tab === 'design' ? 'active' : ''; ?>">
                <div class="wns-design-container">
                    <div class="wns-design-settings">
                        <div class="wns-section">
                            <h2 class="wns-section-title"><?php wns_te('email_design_customization'); ?></h2>
                            
                            <form method="post" action="options.php" id="design-form">
                                <?php settings_fields('wns_design_group'); ?>
                                
                                <div class="wns-form-row">
                                    <label class="wns-form-label" for="header-title"><?php wns_te('header_title_label'); ?></label>
                                    <input type="text" name="wns_email_header_title" id="header-title" value="<?php echo esc_attr(get_option('wns_email_header_title', 'Weekly Newsletter')); ?>" class="wns-form-input" placeholder="<?php echo esc_attr(wns_t('weekly_newsletter_title')); ?>" />
                                    <div class="wns-form-help"><?php wns_te('header_title_help'); ?></div>
                                </div>
                                
                                <div class="wns-form-row">
                                    <label class="wns-form-label" for="header-subtitle"><?php wns_te('header_subtitle_label'); ?></label>
                                    <input type="text" name="wns_email_header_subtitle" id="header-subtitle" aria-describedby="header-subtitle-help" value="<?php echo esc_attr(get_option('wns_email_header_subtitle', '')); ?>" class="wns-form-input" placeholder="<?php echo esc_attr(wns_t('header_subtitle_placeholder')); ?>" />
                                    <div class="wns-form-help" id="header-subtitle-help"><?php wns_te('header_subtitle_help'); ?></div>
                                </div>
                                
                                <div class="wns-form-row">
                                    <label class="wns-form-label" for="logo-url"><?php wns_te('logo_url_label'); ?></label>
                                    <input type="url" name="wns_email_logo_url" id="logo-url" aria-describedby="logo-url-help" value="<?php echo esc_attr(get_option('wns_email_logo_url', '')); ?>" class="wns-form-input" placeholder="<?php echo esc_attr(wns_t('logo_url_placeholder')); ?>" />
                                    <div class="wns-form-help" id="logo-url-help"><?php wns_te('logo_url_help'); ?></div>
                                </div>
                                
                                <div class="wns-form-row">
                                    <label class="wns-form-label" for="header-color"><?php wns_te('header_color_label'); ?></label>
                                    <input type="color" name="wns_email_header_color" id="header-color" aria-describedby="header-color-help" value="<?php echo esc_attr(get_option('wns_email_header_color', '#2c5aa0')); ?>" class="wns-form-input" />
                                    <div class="wns-form-help" id="header-color-help"><?php wns_te('header_color_help'); ?></div>
                                </div>
                                
                                <div class="wns-form-row">
                                    <label class="wns-form-label" for="intro-text"><?php wns_te('intro_text_label'); ?></label>
                                    <textarea name="wns_email_intro_text" id="intro-text" aria-describedby="intro-text-help" class="wns-form-input" style="min-height: 80px; resize: vertical;" rows="3" placeholder="<?php echo esc_attr(wns_t('intro_text_placeholder')); ?>"><?php echo esc_textarea(get_option('wns_email_intro_text', wns_t('intro_text_placeholder'))); ?></textarea>
                                    <div class="wns-form-help" id="intro-text-help"><?php wns_te('intro_text_help'); ?></div>
                                </div>
                                
                                <div class="wns-form-row">
                                    <label class="wns-form-label" for="intro-text-size"><?php wns_te('intro_text_size_label'); ?></label>
                                    <input type="range" name="wns_email_intro_text_size" id="intro-text-size" aria-describedby="intro-text-size-help" value="<?php echo esc_attr(get_option('wns_email_intro_text_size', '20')); ?>" min="14" max="32" class="wns-form-range" />
                                    <span id="intro-text-size-value"><?php echo get_option('wns_email_intro_text_size', '20'); ?>px</span>
                                    <div class="wns-form-help" id="intro-text-size-help"><?php wns_te('intro_text_size_help'); ?></div>
                                </div>
                                
                                <div class="wns-form-row">
                                    <label class="wns-form-label" for="header-text-size"><?php wns_te('header_text_size_label'); ?></label>
                                    <input type="range" name="wns_email_header_text_size" id="header-text-size" aria-describedby="header-text-size-help" value="<?php echo esc_attr(get_option('wns_email_header_text_size', '28')); ?>" min="16" max="48" class="wns-form-range" />
                                    <span id="header-text-size-value"><?php echo get_option('wns_email_header_text_size', '28'); ?>px</span>
                                    <div class="wns-form-help" id="header-text-size-help"><?php wns_te('header_text_size_help'); ?></div>
                                </div>
                                
                                <div class="wns-form-row">
                                    <label class="wns-form-label" for="accent-color"><?php wns_te('accent_color_label'); ?></label>
                                    <input type="color" name="wns_email_accent_color" id="accent-color" aria-describedby="accent-color-help" value="<?php echo esc_attr(get_option('wns_email_accent_color', '#0073aa')); ?>" class="wns-form-input" />
                                    <div class="wns-form-help" id="accent-color-help"><?php wns_te('accent_color_help'); ?></div>
                                </div>
                                
                                <div class="wns-form-row">
                                    <label class="wns-form-label" for="text-color"><?php wns_te('text_color_label'); ?></label>
                                    <input type="color" name="wns_email_text_color" id="text-color" aria-describedby="text-color-help" value="<?php echo esc_attr(get_option('wns_email_text_color', '#333333')); ?>" class="wns-form-input" />
                                    <div class="wns-form-help" id="text-color-help"><?php wns_te('text_color_help'); ?></div>
                                </div>
                                
                                <div class="wns-form-row">
                                    <label class="wns-form-label" for="card-bg-color"><?php wns_te('card_background_color_label'); ?></label>
                                    <input type="color" name="wns_email_card_bg_color" id="card-bg-color" aria-describedby="card-bg-color-help" value="<?php echo esc_attr(get_option('wns_email_card_bg_color', '#f8f8f8')); ?>" class="wns-form-input" />
                                    <div class="wns-form-help" id="card-bg-color-help"><?php wns_te('card_background_color_help'); ?></div>
                                </div>
                                
                                <div class="wns-form-row">
                                    <label class="wns-form-label" for="card-border-color"><?php wns_te('card_border_color_label'); ?></label>
                                    <input type="color" name="wns_email_card_border_color" id="card-border-color" aria-describedby="card-border-color-help" value="<?php echo esc_attr(get_option('wns_email_card_border_color', '#e5e5e5')); ?>" class="wns-form-input" />
                                    <div class="wns-form-help" id="card-border-color-help"><?php wns_te('card_border_color_help'); ?></div>
                                </div>
                                
                                <div class="wns-form-row">
                                    <label class="wns-form-label" for="content-padding"><?php wns_te('content_padding_label'); ?></label>
                                    <input type="range" name="wns_email_content_padding" id="content-padding" aria-describedby="content-padding-help" value="<?php echo esc_attr(get_option('wns_email_content_padding', '20')); ?>" min="10" max="40" class="wns-form-range" />
                                    <span id="content-padding-value"><?php echo get_option('wns_email_content_padding', '20'); ?>px</span>
                                    <div class="wns-form-help" id="content-padding-help"><?php wns_te('content_padding_help'); ?></div>
                                </div>
                                
                                <div class="wns-form-row">
                                    <label class="wns-form-label" for="card-radius"><?php wns_te('card_border_radius_label'); ?></label>
                                    <input type="range" name="wns_email_card_radius" id="card-radius" aria-describedby="card-radius-help" value="<?php echo esc_attr(get_option('wns_email_card_radius', '7')); ?>" min="0" max="20" class="wns-form-range" />
                                    <span id="card-radius-value"><?php echo get_option('wns_email_card_radius', '7'); ?>px</span>
                                    <div class="wns-form-help" id="card-radius-help"><?php wns_te('card_border_radius_help'); ?></div>
                                </div>
                                
                                <div class="wns-form-row">
                                    <label class="wns-form-label" for="line-height"><?php wns_te('line_height_label'); ?></label>
                                    <input type="range" name="wns_email_line_height" id="line-height" aria-describedby="line-height-help" value="<?php echo esc_attr(get_option('wns_email_line_height', '1.5')); ?>" min="1" max="2" step="0.1" class="wns-form-range" />
                                    <span id="line-height-value"><?php echo get_option('wns_email_line_height', '1.5'); ?></span>
                                    <div class="wns-form-help" id="line-height-help"><?php wns_te('line_height_help'); ?></div>
                                </div>
                                
                                <div class="wns-form-row">
                                    <label class="wns-form-label" for="font-family"><?php wns_te('font_family_label'); ?></label>
                                    <select name="wns_email_font_family" id="font-family" aria-describedby="font-family-help" class="wns-form-input">
                                        <?php $current_font = get_option('wns_email_font_family', 'Arial, sans-serif'); ?>
                                        <option value="Arial, sans-serif" <?php selected($current_font, 'Arial, sans-serif'); ?>>Arial</option>
                                        <option value="Helvetica, Arial, sans-serif" <?php selected($current_font, 'Helvetica, Arial, sans-serif'); ?>>Helvetica</option>
                                        <option value="Georgia, serif" <?php selected($current_font, 'Georgia, serif'); ?>>Georgia</option>
                                        <option value="'Times New Roman', serif" <?php selected($current_font, "'Times New Roman', serif"); ?>>Times New Roman</option>
                                        <option value="Verdana, sans-serif" <?php selected($current_font, 'Verdana, sans-serif'); ?>>Verdana</option>
                                        <option value="'Trebuchet MS', sans-serif" <?php selected($current_font, "'Trebuchet MS', sans-serif"); ?>>Trebuchet MS</option>
                                        <option value="'Segoe UI', Tahoma, Geneva, Verdana, sans-serif" <?php selected($current_font, "'Segoe UI', Tahoma, Geneva, Verdana, sans-serif"); ?>>Segoe UI</option>
                                    </select>
                                    <div class="wns-form-help" id="font-family-help"><?php wns_te('font_family_help'); ?></div>
                                </div>
                                
                                <div class="wns-form-row">
                                    <label class="wns-form-label" for="footer-text"><?php wns_te('footer_text_label'); ?></label>
                                    <textarea name="wns_email_footer_text" id="footer-text" aria-describedby="footer-text-help" class="wns-form-input" style="min-height: 100px; resize: vertical;" rows="4" placeholder="<?php echo esc_attr(wns_t('footer_text_placeholder')); ?>"><?php echo esc_textarea(get_option('wns_email_footer_text', '')); ?></textarea>
                                    <div class="wns-form-help" id="footer-text-help"><?php wns_te('footer_text_help'); ?></div>
                                </div>
                                
                                <?php submit_button(wns_t('save_design_settings'), 'primary large wns-button wns-button-primary'); ?>
                            </form>
                        </div>
                    </div>
                    
                    <div class="wns-design-preview">
                        <h3 style="margin-top: 0; color: #333; text-align: center;"><?php wns_te('live_preview'); ?></h3>
                        <div class="wns-email-preview" id="email-preview">
                            <?php echo wns_build_demo_email(); ?>
                        </div>
                    </div>
                </div>
                
                <script>
                document.addEventListener('DOMContentLoaded', function() {
                    // Get all form inputs
                    var inputs = {
                        headerTitle: document.getElementById('header-title'),
                        headerSubtitle: document.getElementById('header-subtitle'),
                        logoUrl: document.getElementById('logo-url'),
                        introText: document.getElementById('intro-text'),
                        introTextSize: document.getElementById('intro-text-size'),
                        headerColor: document.getElementById('header-color'),
                        headerTextSize: document.getElementById('header-text-size'),
                        accentColor: document.getElementById('accent-color'),
                        textColor: document.getElementById('text-color'),
                        textColor: document.getElementById('text-color'),
                        cardBgColor: document.getElementById('card-bg-color'),
                        cardBorderColor: document.getElementById('card-border-color'),
                        contentPadding: document.getElementById('content-padding'),
                        cardRadius: document.getElementById('card-radius'),
                        lineHeight: document.getElementById('line-height'),
                        fontFamily: document.getElementById('font-family'),
                        footerText: document.getElementById('footer-text')
                    };
                    
                    var preview = document.getElementById('email-preview');
                    var iframe = null;
                    function resizeDesignPreview() {
                        if (!iframe || !iframe.contentDocument || !iframe.contentDocument.body) return;
                        iframe.style.height = '0px';
                        iframe.style.height = iframe.contentDocument.body.scrollHeight + 'px';
                    }
                    window.addEventListener('resize', resizeDesignPreview);
                    
                    // Range input value displays
                    function updateRangeDisplay() {
                        document.getElementById('intro-text-size-value').textContent = inputs.introTextSize.value + 'px';
                        document.getElementById('header-text-size-value').textContent = inputs.headerTextSize.value + 'px';
                        document.getElementById('content-padding-value').textContent = inputs.contentPadding.value + 'px';
                        document.getElementById('card-radius-value').textContent = inputs.cardRadius.value + 'px';
                        document.getElementById('line-height-value').textContent = inputs.lineHeight.value;
                    }
                    
                    // Update preview function
                    function updatePreview() {
                        updateRangeDisplay();
                        
                        // Create iframe for isolated styles
                        if (!iframe) {
                            iframe = document.createElement('iframe');
                            iframe.setAttribute('sandbox', 'allow-same-origin');
                            iframe.title = <?php echo wp_json_encode(wns_t('live_preview')); ?>;
                            iframe.style.width = '100%';
                            iframe.style.border = 'none';
                            iframe.style.backgroundColor = 'transparent';
                            preview.innerHTML = '';
                            preview.appendChild(iframe);
                        }
                        
                        // Build dynamic email content
                        var emailHTML = buildDynamicEmail();
                        
                        // Write to iframe
                        iframe.contentDocument.open();
                        iframe.contentDocument.write(emailHTML);
                        iframe.contentDocument.close();
                        
                        // Auto-resize iframe
                        setTimeout(resizeDesignPreview, 100);
                        iframe.contentDocument.querySelectorAll('img').forEach(function(image) {
                            image.addEventListener('load', resizeDesignPreview);
                        });
                    }
                    
                    function buildDynamicEmail() {
                        var values = {
                            headerTitle: inputs.headerTitle.value || '<?php echo esc_js(wns_t('weekly_newsletter_title')); ?>',
                            headerSubtitle: inputs.headerSubtitle.value,
                            logoUrl: inputs.logoUrl.value,
                            introText: inputs.introText.value || 'This week we have prepared {count} new posts and articles for you.',
                            introTextSize: inputs.introTextSize.value,
                            headerColor: inputs.headerColor.value,
                            headerTextSize: inputs.headerTextSize.value,
                            accentColor: inputs.accentColor.value,
                            textColor: inputs.textColor.value,
                            cardBgColor: inputs.cardBgColor.value,
                            cardBorderColor: inputs.cardBorderColor.value,
                            contentPadding: inputs.contentPadding.value,
                            cardRadius: inputs.cardRadius.value,
                            lineHeight: inputs.lineHeight.value,
                            fontFamily: inputs.fontFamily.value,
                            footerText: inputs.footerText.value
                        };
                        
                        var headerContent = '';
                        if (values.logoUrl) {
                            headerContent = '<img src="' + values.logoUrl + '" alt="Logo" style="max-height: 60px; max-width: 250px;" />';
                            if (values.headerSubtitle) {
                                headerContent += '<div style="margin-top: 10px; font-size: 16px; opacity: 0.9;">' + values.headerSubtitle + '</div>';
                            }
                        } else {
                            headerContent = '<h1 style="margin: 0 !important; padding: 0; border: none; font-size: ' + values.headerTextSize + 'px; color: white;">' + values.headerTitle + '</h1>';
                            if (values.headerSubtitle) {
                                headerContent += '<div style="margin-top: 8px; font-size: 16px; opacity: 0.9;">' + values.headerSubtitle + '</div>';
                            }
                        }
                        
                        var introContent = values.introText.replace('{count}', '4');
                        
                        var footerHTML = values.footerText 
                            ? '<div class="wns-email-footer">' + values.footerText.replace(/\n/g, '<br>') + '</div>'
                            : '';
                        
                        // Generate dynamic CSS based on current form values
                        var dynamicCSS = `
/* Email body wrapper - ONLY targets actual body tags in emails/iframes */
body.wns-email-body {
    font-family: ${values.fontFamily};
    margin: 0;
    padding: ${values.contentPadding}px;
    color: ${values.textColor};
    line-height: ${values.lineHeight};
}
.wns-email-content {
    color: ${values.textColor};
    background: #fff;
    padding: 0;
    margin: 0 auto;
    border-radius: ${values.cardRadius}px;
    overflow: hidden;
    max-width: 700px;
    box-shadow: 0 8px 32px rgba(0,0,0,0.2);
    border: 1px solid ${values.headerColor};
}
.wns-email-header {
    background: ${values.headerColor};
    color: #fff;
    text-align: center;
    padding: ${values.contentPadding}px;
}
.wns-email-header h1 {
    margin: 0 !important;
    padding: 0;
    border: none;
}
.wns-section-title {
    color: ${values.textColor};
    font-size: 1.45em;
    margin-top: 1.5em;
    margin-bottom: 0.8em;
    font-weight: 700;
    margin-left: ${values.contentPadding}px;
}
.wns-intro {
    font-size: ${values.introTextSize}px;
    font-weight: 600;
    margin-top: 1.5em;
    margin-bottom: 1.2em;
    margin-left: ${values.contentPadding}px;
    color: ${values.textColor};
}
.wns-card {
    background: ${values.cardBgColor};
    border: 1px solid ${values.cardBorderColor};
    border-radius: ${values.cardRadius}px;
    margin: 0 ${values.contentPadding}px 1.5em;
    padding: ${values.contentPadding}px;
    box-sizing: border-box;
}
.wns-wp-posts, .wns-forum-section {
    margin-bottom: 2em;
    padding: 0 ${values.contentPadding}px;
}
.wns-forum-header, .wns-thread-header {
    display: flex;
    align-items: center;
    margin-top: 1.2em;
    margin-bottom: 0.7em;
    margin-left: ${values.contentPadding}px;
}
.wns-category-label {
    color: #fff;
    background: ${values.accentColor};
    display: inline-block;
    padding: 0.18em 0.7em;
    border-radius: 6px;
    font-size: 1em;
    font-weight: 600;
    margin-right: 0.6em;
}
.wns-forum-label {
    color: #fff;
    background: ${values.headerColor};
    display: inline-block;
    padding: 0.18em 0.7em;
    border-radius: 6px;
    font-size: 1em;
    font-weight: 600;
    margin-right: 0.6em;
}
.wns-forum-name {
    font-size: 1.10em;
    font-weight: bold;
    color: ${values.textColor};
}
.wns-topic-label {
    color: #fff;
    background: ${values.accentColor};
    display: inline-block;
    padding: 0.13em 0.6em;
    border-radius: 6px;
    font-size: 0.97em;
    font-weight: 600;
    margin-right: 0.6em;
}
.wns-thread-name {
    font-size: 1.02em;
    font-weight: bold;
    color: ${values.textColor};
}
.wns-post-title {
    font-size: 1.02em;
    font-weight: 700 !important;
    color: ${values.textColor};
    margin-bottom: 0.15em;
}
.wns-post-meta {
    color: ${values.textColor};
    font-size: 0.95em;
    margin-bottom: 0.3em;
}
/* For forum posts we want author/date to use the title style so it stands out */
.wns-forum-post .wns-post-meta {
    color: ${values.textColor};
    font-size: 1.02em;
    font-weight: 600;
    margin-bottom: 0.3em;
}
/* Reply label - subtle gray to avoid distracting from content */
.wns-reply-label {
    color: #777777;
    font-size: 0.95em;
    font-weight: 600;
    margin-bottom: 0.15em;
    display: inline-block;
}
.wns-post-excerpt {
    color: ${values.textColor};
    font-size: 1em;
    margin-bottom: 0.7em;
    margin-top: 0.1em;
    line-height: ${values.lineHeight};
}
.wns-forum-quote {
    border-left: 3px solid ${values.accentColor};
    background: #fffbe7;
    color: #444;
    margin: 0.5em 0 0.7em 0;
    padding: 0.5em 0.8em;
    font-style: italic;
    font-size: 0.97em;
    border-radius: 5px;
}
.wns-post-readmore {
    margin-top: 0.3em;
}
.wns-post-readmore a {
    color: ${values.accentColor};
    font-size: 0.98em;
    text-decoration: underline;
    font-weight: 600;
}
.wns-email-footer {
    background: #f5f5f5;
    color: #666;
    padding: 15px ${values.contentPadding}px;
    font-size: 12px;
    text-align: center;
    border-top: 1px solid #e0e0e0;
    margin-top: 20px;
}
@media (max-width: 600px) { 
    .wns-email-content {margin: 1em;} 
    body.wns-email-body {padding: 10px;}
}
`;
                        
                        return `<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<style>
${dynamicCSS}
</style>
</head>
<body class='wns-email-body' style='font-family: ${values.fontFamily.replace(/'/g, '&#39;')}; margin:0; padding:${values.contentPadding}px; color: ${values.textColor}; line-height: ${values.lineHeight};'>
<div class='wns-email-content' style='color: ${values.textColor}; background:#fff; padding: 0; margin: 0 auto; border-radius: ${values.cardRadius}px; overflow: hidden; max-width: 700px; box-shadow: 0 8px 32px rgba(0,0,0,0.2); border: 1px solid ${values.headerColor};'>
<div class='wns-email-header'>${headerContent}</div>
<section class="wns-wp-posts">
<div class='wns-intro' style='font-size: ${values.introTextSize}px; font-weight: 600; margin-top: 1.5em; margin-bottom: 1.2em; margin-left: ${values.contentPadding}px; color: ${values.textColor};'><strong>${introContent}</strong></div>
<h2 class="wns-section-title"><?php echo esc_js(wns_t('latest_articles_website')); ?></h2>
<div class="wns-forum-header"><span class="wns-category-label">Technology</span></div>
<div class='wns-card wns-post-card'>
<div class='wns-post-title'><a href='#'>New Features in WordPress 6.5</a></div>
<div class='wns-post-meta'>Sep 12, 2025</div>
<div class='wns-post-excerpt'>WordPress 6.5 brings a wealth of new features and improvements that will simplify managing your websites. Among the most significant updates are an enhanced block editor, new customization options, and better performance optimizations...</div>
<div class='wns-post-readmore'><a href='#'><?php echo esc_js(wns_t('read_more_website')); ?></a></div>
</div>
<div class="wns-forum-header"><span class="wns-category-label">Marketing</span></div>
<div class='wns-card wns-post-card'>
<div class='wns-post-title'><a href='#'>SEO Optimization Tips for 2025</a></div>
<div class='wns-post-meta'>Sep 13, 2025</div>
<div class='wns-post-excerpt'>SEO continues to evolve, and for 2025 the key trends include AI-optimized content, technical SEO, and user experience. Focus on quality content, loading speed, and mobile optimization for the best results...</div>
<div class='wns-post-readmore'><a href='#'><?php echo esc_js(wns_t('read_more_website')); ?></a></div>
</div>
</section>
<section class="wns-forum-section">
<h2 class="wns-section-title"><?php echo esc_js(wns_t('latest_forum_posts')); ?></h2>
<div class="wns-forum-header"><span class="wns-forum-label"><?php echo esc_js(wns_t('forum')); ?></span><span class="wns-forum-name">General Discussion</span></div>
<div class="wns-thread-header"><span class="wns-topic-label"><?php echo esc_js(wns_t('topic')); ?></span><span class="wns-thread-name">Beginner Questions</span></div>
<div class='wns-card wns-forum-post'>
<div class='wns-post-meta'>John Smith &middot; Sep 14, 2025 08:30</div>
<div class='wns-post-excerpt'>Hello everyone! I'm a complete beginner in programming and would like to learn. Can you recommend which programming language would be best to start with? I'm thinking between Python and JavaScript...</div>
<div class='wns-post-readmore'><a href='#'><?php echo esc_js(wns_t('continue_reading_forum')); ?></a></div>
</div>
<div class="wns-forum-header"><span class="wns-forum-label"><?php echo esc_js(wns_t('forum')); ?></span><span class="wns-forum-name">Web Design</span></div>
<div class="wns-thread-header"><span class="wns-topic-label"><?php echo esc_js(wns_t('topic')); ?></span><span class="wns-thread-name">CSS Grid vs Flexbox</span></div>
<div class='wns-card wns-forum-post'>
<div class='wns-post-meta'>Sarah Johnson &middot; Sep 14, 2025 06:30</div>
<div class='wns-post-excerpt'>I often encounter the question of when it's better to use CSS Grid versus Flexbox. Can someone explain the main differences and practical applications? <div class="wns-forum-quote">Grid is excellent for two-dimensional layouts, while Flexbox is ideal for one-dimensional ones.</div></div>
<div class='wns-post-readmore'><a href='#'>Continue reading on forum...</a></div>
</div>
</section>
${footerHTML}
</div>
</body>
</html>`;
                    }
                    
                    // Add event listeners to all inputs
                    Object.values(inputs).forEach(function(input) {
                        if (input) {
                            input.addEventListener('input', updatePreview);
                            input.addEventListener('change', updatePreview);
                        }
                    });
                    
                    // Initial preview update
                    updatePreview();
                });
                </script>
            </div>
            
            <!-- Configuration Tab -->
            <div class="wns-tab-content <?php echo $current_tab === 'configuration' ? 'active' : ''; ?>">
                <div class="wns-section">
                    <h2 class="wns-section-title"><?php wns_te('mail_configuration'); ?></h2>
                    
                    <form method="post" action="options.php">
                        <?php 
                        settings_fields('wns_mail_group'); 
                        $mail_type = get_option('wns_mail_type', 'wordpress');
                        $smtp_host = get_option('wns_smtp_host', '');
                        $smtp_port = get_option('wns_smtp_port', '587');
                        $smtp_username = get_option('wns_smtp_username', '');
                        $smtp_password_encrypted = get_option('wns_smtp_password', '');
                        // Show masked password if one exists, empty if not
                        $smtp_password_display = !empty($smtp_password_encrypted) ? '••••••••••••' : '';
                        $smtp_encryption = get_option('wns_smtp_encryption', 'tls');
                        ?>
                        
                        <div class="wns-form-row">
                            <div class="wns-form-label" id="wns-mail-method-label"><?php wns_te('mail_method'); ?></div>
                            <div role="radiogroup" aria-labelledby="wns-mail-method-label" style="margin-top: 12px;">
                                <label class="wns-checkbox-label" style="margin-bottom: 12px; display: block;">
                                    <input type="radio" name="wns_mail_type" value="wordpress" class="wns-checkbox-input" <?php checked($mail_type, 'wordpress'); ?> style="margin-right: 8px;" />
                                    <?php wns_te('use_wordpress_mail'); ?>
                                </label>
                                <label class="wns-checkbox-label" style="display: block;">
                                    <input type="radio" name="wns_mail_type" value="smtp" class="wns-checkbox-input" <?php checked($mail_type, 'smtp'); ?> style="margin-right: 8px;" />
                                    <?php wns_te('use_smtp_configuration'); ?>
                                </label>
                            </div>
                            <div class="wns-form-help"><?php wns_te('mail_method_help'); ?></div>
                        </div>
                        
                        <div id="wns-smtp-config" style="<?php if($mail_type !== 'smtp') echo 'display:none;'; ?>">
                            <div class="wns-divider" style="margin: 24px 0;"></div>
                            
                            <!-- Security Notice - only shown when SMTP is selected -->
                            <div style="background: #fff3cd; border: 1px solid #ffeaa7; border-radius: 4px; padding: 16px; margin-bottom: 24px;">
                                <h4 style="margin: 0 0 8px 0; color: #856404;"><?php wns_te('security_notice'); ?></h4>
                                <p style="margin: 0 0 12px 0; color: #856404; font-size: 14px;">
                                    <?php wns_te('smtp_security_message'); ?>
                                </p>
                                <p style="margin: 0 0 12px 0; color: #856404; font-size: 14px;">
                                    <?php wns_te('available_constants'); ?>
                                </p>
                                <p style="margin: 0 0 12px 0; color: #856404; font-size: 14px;">
                                    <?php wns_te('smtp_disclaimer'); ?>
                                </p>
                                <p style="margin: 0; color: #856404; font-size: 14px;">
                                    <?php wns_te('smtp_learn_link'); ?>
                                </p>
                            </div>
                            
                            <div class="wns-form-row">
                                <label class="wns-form-label" for="wns-smtp-host"><?php wns_te('smtp_host'); ?></label>
                                <input id="wns-smtp-host" aria-describedby="wns-smtp-host-help" type="text" name="wns_smtp_host" value="<?php echo esc_attr($smtp_host); ?>" class="wns-form-input" placeholder="smtp.gmail.com" />
                                <div class="wns-form-help" id="wns-smtp-host-help"><?php wns_te('smtp_host_help'); ?></div>
                            </div>
                            
                            <div class="wns-form-row">
                                <label class="wns-form-label" for="wns-smtp-port"><?php wns_te('smtp_port'); ?></label>
                                <input id="wns-smtp-port" aria-describedby="wns-smtp-port-help" type="number" name="wns_smtp_port" value="<?php echo esc_attr($smtp_port); ?>" class="wns-form-input" placeholder="587" />
                                <div class="wns-form-help" id="wns-smtp-port-help"><?php wns_te('smtp_port_help'); ?></div>
                            </div>
                            
                            <div class="wns-form-row">
                                <label class="wns-form-label" for="wns-smtp-username"><?php wns_te('smtp_username'); ?></label>
                                <input id="wns-smtp-username" aria-describedby="wns-smtp-username-help" type="text" name="wns_smtp_username" value="<?php echo esc_attr($smtp_username); ?>" class="wns-form-input" placeholder="your-email@domain.com" />
                                <div class="wns-form-help" id="wns-smtp-username-help"><?php wns_te('smtp_username_help'); ?></div>
                            </div>
                            
                            <div class="wns-form-row">
                                <label class="wns-form-label" for="wns-smtp-password"><?php wns_te('smtp_password'); ?></label>
                                <input id="wns-smtp-password" aria-describedby="wns-smtp-password-help" type="password" name="wns_smtp_password" value="<?php echo esc_attr($smtp_password_display); ?>" class="wns-form-input" placeholder="Your SMTP password or app password" />
                                <div class="wns-form-help" id="wns-smtp-password-help"><?php wns_te('smtp_password_help'); ?></div>
                            </div>
                            
                            <div class="wns-form-row">
                                <label class="wns-form-label" for="wns-smtp-encryption"><?php wns_te('encryption'); ?></label>
                                <select id="wns-smtp-encryption" aria-describedby="wns-smtp-encryption-help" name="wns_smtp_encryption" class="wns-form-select">
                                    <option value="tls" <?php selected($smtp_encryption, 'tls'); ?>><?php wns_te('encryption_tls'); ?></option>
                                    <option value="ssl" <?php selected($smtp_encryption, 'ssl'); ?>><?php wns_te('encryption_ssl'); ?></option>
                                    <option value="none" <?php selected($smtp_encryption, 'none'); ?>><?php wns_te('encryption_none'); ?></option>
                                </select>
                                <div class="wns-form-help" id="wns-smtp-encryption-help"><?php wns_te('encryption_help'); ?></div>
                            </div>
                        </div>
                        
                        <?php submit_button(wns_t('save_configuration'), 'primary large wns-button'); ?>
                    </form>
                </div>
                
                <div class="wns-divider"></div>
                
                <div class="wns-section">
                    <h2 class="wns-section-title"><?php wns_te('test_email_configuration'); ?></h2>
                    <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
                        <input type="hidden" name="action" value="wns_test_email" />
                        <?php wp_nonce_field('wns_test_email_action', 'wns_test_email_nonce'); ?>
                        <div class="wns-form-row">
                            <label class="wns-form-label" for="test-email"><?php wns_te('test_email_address'); ?></label>
                            <input id="test-email" aria-describedby="test-email-help" type="email" name="test_email" value="<?php echo esc_attr(wp_get_current_user()->user_email); ?>" class="wns-form-input" required />
                            <div class="wns-form-help" id="test-email-help"><?php wns_te('test_email_help'); ?></div>
                        </div>
                        <?php submit_button(wns_t('send_test_email'), 'secondary large wns-button wns-button-secondary'); ?>
                    </form>
                </div>
                
                <div class="wns-divider"></div>
                
                <div class="wns-section">
                    <h2 class="wns-section-title"><?php wns_te('cronjob_integration'); ?></h2>
                    
                    <!-- Info Notice -->
                    <div style="background: #e7f3ff; border: 1px solid #b3d9ff; border-radius: 4px; padding: 16px; margin-bottom: 24px;">
                        <h4 style="margin: 0 0 8px 0; color: #0066cc;">
                            <span class="dashicons dashicons-info" style="vertical-align: middle;"></span>
                            <?php wns_te('cronjob_info_title'); ?>
                        </h4>
                        <p style="margin: 0 0 12px 0; color: #0066cc; font-size: 14px;">
                            <?php wns_te('cronjob_info_message'); ?>
                        </p>
                        <p style="margin: 0 0 12px 0; color: #0066cc; font-size: 14px;">
                            <?php wns_te('cronjob_schedule_info'); ?>
                        </p>
                        <p style="margin: 0; color: #0066cc; font-size: 14px;">
                            <strong><?php wns_te('cronjob_get_api_key'); ?></strong>
                            <a href="https://console.cron-job.org/" target="_blank" style="color: #0066cc; text-decoration: underline;">
                                <?php wns_te('cronjob_console_link'); ?>
                            </a>
                        </p>
                    </div>
                    
                    <form method="post" action="options.php">
                        <?php 
                        settings_fields('wns_cronjob_group');
                        $cronjob_enabled = get_option('wns_cronjob_enabled', 0);
                        $cronjob_api_key_encrypted = get_option('wns_cronjob_api_key', '');
                        $cronjob_api_key_display = !empty($cronjob_api_key_encrypted) ? '••••••••••••••••••••••••••••••••' : '';
                        $cronjob_id = get_option('wns_cronjob_id', '');
                        ?>
                        
                        <div class="wns-form-row">
                            <label class="wns-checkbox-label" style="display: flex; align-items: center;">
                                <input type="checkbox" name="wns_cronjob_enabled" value="1" 
                                       class="wns-checkbox-input" <?php checked($cronjob_enabled, 1); ?> 
                                       style="margin-right: 8px;" />
                                <span><?php wns_te('enable_cronjob_integration'); ?></span>
                            </div>
                            <div class="wns-form-help"><?php wns_te('cronjob_enable_help'); ?></div>
                        </div>
                        
                        <div id="wns-cronjob-config" style="<?php if(!$cronjob_enabled) echo 'display:none;'; ?>">
                            <div class="wns-divider" style="margin: 24px 0;"></div>
                            
                            <!-- Security Notice -->
                            <div style="background: #fff3cd; border: 1px solid #ffeaa7; border-radius: 4px; padding: 16px; margin-bottom: 24px;">
                                <h4 style="margin: 0 0 8px 0; color: #856404;">
                                    <span class="dashicons dashicons-lock" style="vertical-align: middle;"></span>
                                    <?php wns_te('security_notice'); ?>
                                </h4>
                                <p style="margin: 0 0 12px 0; color: #856404; font-size: 14px;">
                                    <?php wns_te('cronjob_security_message'); ?>
                                </p>
                                <p style="margin: 0; color: #856404; font-size: 14px;">
                                    <?php wns_te('cronjob_api_key_encrypted'); ?>
                                </p>
                            </div>
                            
                            <div class="wns-form-row">
                                <label class="wns-form-label" for="wns-cronjob-api-key"><?php wns_te('cronjob_api_key'); ?></label>
                                <input type="password" id="wns-cronjob-api-key" name="wns_cronjob_api_key"
                                       value="<?php echo esc_attr($cronjob_api_key_display); ?>" 
                                       class="wns-form-input" 
                                       placeholder="<?php echo esc_attr(wns_t('cronjob_api_key_placeholder')); ?>" 
                                       autocomplete="off" />
                                <div class="wns-form-help"><?php wns_te('cronjob_api_key_help'); ?></div>
                            </div>
                            
                            <?php if (!empty($cronjob_id)): ?>
                            <div class="wns-form-row">
                                <div class="wns-form-label"><?php wns_te('cronjob_status'); ?></div>
                                <div style="padding: 12px; background: #e7f7e7; border: 1px solid #4caf50; border-radius: 4px;">
                                    <span class="dashicons dashicons-yes-alt" style="color: #4caf50; vertical-align: middle;"></span>
                                    <strong style="color: #2e7d32;"><?php wns_te('cronjob_active'); ?></strong>
                                    <p style="margin: 8px 0 0 0; font-size: 13px; color: #2e7d32;">
                                        <?php wns_te('cronjob_id_label'); ?>: <code style="background: #fff; padding: 2px 6px; border-radius: 3px;"><?php echo esc_html($cronjob_id); ?></code>
                                    </p>
                                </div>
                            </div>
                            <?php endif; ?>
                            
                            <?php
                            // Calculate and display the schedule
                            $schedule_info = wns_get_cronjob_schedule();
                            if ($schedule_info['success']): 
                            ?>
                            <div class="wns-form-row">
                                <div class="wns-form-label"><?php wns_te('cronjob_schedule_label'); ?></div>
                                <div style="padding: 12px; background: #f5f5f5; border: 1px solid #ddd; border-radius: 4px;">
                                    <p style="margin: 0; font-size: 14px;">
                                        <strong><?php wns_te('cronjob_trigger_time'); ?>:</strong> 
                                        <?php echo esc_html($schedule_info['time']); ?> 
                                        (<?php echo esc_html(ucfirst(get_option('wns_send_day', 'monday'))); ?>)
                                    </p>
                                    <p style="margin: 8px 0 0 0; font-size: 13px; color: #666;">
                                        <?php wns_te('cronjob_5min_buffer'); ?>
                                    </p>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
                        
                        <?php submit_button(wns_t('save_configuration'), 'primary large wns-button'); ?>
                    </form>
                    
                    <?php if ($cronjob_enabled && !empty($cronjob_api_key_encrypted)): ?>
                    <div style="margin-top: 20px;">
                        <button type="button" id="wns-test-cronjob" class="button button-secondary">
                            <?php wns_te('test_cronjob_connection'); ?>
                        </button>
                        <button type="button" id="wns-sync-cronjob" class="button button-secondary" style="margin-left: 10px;">
                            <?php wns_te('sync_cronjob_now'); ?>
                        </button>
                    </div>
                    <div id="wns-cronjob-test-result" style="margin-top: 15px;"></div>
                    <?php endif; ?>
                </div>
                
                <script>
                document.addEventListener('DOMContentLoaded', function() {
                    var radioButtons = document.querySelectorAll('input[name="wns_mail_type"]');
                    var smtpConfig = document.getElementById('wns-smtp-config');
                    
                    function toggleSmtpConfig() {
                        var selectedValue = document.querySelector('input[name="wns_mail_type"]:checked').value;
                        smtpConfig.style.display = selectedValue === 'smtp' ? 'block' : 'none';
                    }
                    
                    radioButtons.forEach(function(radio) {
                        radio.addEventListener('change', toggleSmtpConfig);
                    });
                    
                    // Cron-job.org integration toggle
                    var cronjobCheckbox = document.querySelector('input[name="wns_cronjob_enabled"]');
                    var cronjobConfig = document.getElementById('wns-cronjob-config');
                    
                    if (cronjobCheckbox && cronjobConfig) {
                        cronjobCheckbox.addEventListener('change', function() {
                            cronjobConfig.style.display = this.checked ? 'block' : 'none';
                        });
                    }
                    
                    // Test cron-job.org connection
                    var testCronjobBtn = document.getElementById('wns-test-cronjob');
                    if (testCronjobBtn) {
                        testCronjobBtn.addEventListener('click', function() {
                            var resultDiv = document.getElementById('wns-cronjob-test-result');
                            resultDiv.innerHTML = '<p style="color: #666;">Testing connection...</p>';
                            
                            jQuery.ajax({
                                url: ajaxurl,
                                type: 'POST',
                                data: {
                                    action: 'wns_test_cronjob',
                                    nonce: '<?php echo wp_create_nonce('wns_cronjob_test'); ?>'
                                },
                                success: function(response) {
                                    if (response.success) {
                                        resultDiv.innerHTML = '<div style="padding: 12px; background: #e7f7e7; border: 1px solid #4caf50; border-radius: 4px; color: #2e7d32;"><span class="dashicons dashicons-yes-alt"></span> ' + jQuery('<div>').text(response.data.message).html() + '</div>';
                                    } else {
                                        resultDiv.innerHTML = '<div style="padding: 12px; background: #ffe7e7; border: 1px solid #f44336; border-radius: 4px; color: #c62828;"><span class="dashicons dashicons-warning"></span> ' + jQuery('<div>').text(response.data.message).html() + '</div>';
                                    }
                                },
                                error: function() {
                                    resultDiv.innerHTML = '<div style="padding: 12px; background: #ffe7e7; border: 1px solid #f44336; border-radius: 4px; color: #c62828;">Failed to test connection</div>';
                                }
                            });
                        });
                    }
                    
                    // Sync cron-job now
                    var syncCronjobBtn = document.getElementById('wns-sync-cronjob');
                    if (syncCronjobBtn) {
                        syncCronjobBtn.addEventListener('click', function() {
                            var resultDiv = document.getElementById('wns-cronjob-test-result');
                            resultDiv.innerHTML = '<p style="color: #666;">Syncing cron job...</p>';
                            
                            jQuery.ajax({
                                url: ajaxurl,
                                type: 'POST',
                                data: {
                                    action: 'wns_sync_cronjob_manual',
                                    nonce: '<?php echo wp_create_nonce('wns_cronjob_sync'); ?>'
                                },
                                success: function(response) {
                                    if (response.success) {
                                        resultDiv.innerHTML = '<div style="padding: 12px; background: #e7f7e7; border: 1px solid #4caf50; border-radius: 4px; color: #2e7d32;"><span class="dashicons dashicons-yes-alt"></span> ' + jQuery('<div>').text(response.data.message).html() + '</div>';
                                        setTimeout(function() {
                                            location.reload();
                                        }, 1500);
                                    } else {
                                        resultDiv.innerHTML = '<div style="padding: 12px; background: #ffe7e7; border: 1px solid #f44336; border-radius: 4px; color: #c62828;"><span class="dashicons dashicons-warning"></span> ' + jQuery('<div>').text(response.data.message).html() + '</div>';
                                    }
                                },
                                error: function() {
                                    resultDiv.innerHTML = '<div style="padding: 12px; background: #ffe7e7; border: 1px solid #f44336; border-radius: 4px; color: #c62828;">Failed to sync cron job</div>';
                                }
                            });
                        });
                    }
                });
                </script>
            </div>
            
            <!-- Preview Tab -->
            <div class="wns-tab-content <?php echo $current_tab === 'preview' ? 'active' : ''; ?>">
                <div class="wns-preview-toolbar">
                    <h2 class="wns-section-title"><?php echo esc_html(wns_t('next_email_preview')); ?></h2>
                    <div class="wns-preview-actions">
                        <button type="button" id="wns-refresh-preview" class="button button-secondary wns-button wns-button-secondary"><?php echo esc_html(wns_t('refresh_preview')); ?></button>
                        <span class="wns-preview-status" role="status" aria-live="polite"></span>
                    </div>
                </div>
                <div id="wns-email-preview-content" class="wns-email-preview-content">
                        <?php if ($current_tab === 'preview'): ?>
                            <?php
                            // Auto-generate preview when tab is active
                            try {
                                // Get date range type from settings (week or custom)
                                $date_range_type = get_option('wns_date_range_type', 'week');
                                $timezone = wp_timezone();
                                
                                $period = wns_content_period(true);
                                $target = $period['target'];
                                $date_from_str = $period['from'];
                                $date_to_str = $period['to'];

                                // Get settings for what to include
                                $include_wp = get_option('wns_include_wp', 1);
                                $include_forum = get_option('wns_include_forum', 1);
                                
                                // Get WordPress posts for preview - with error handling
                                $wp_posts = [];
                                if ($include_wp) {
                                    try {
                                        $wp_posts_result = wns_get_wp_posts_summary($date_from_str, $date_to_str);
                                        $wp_posts = is_array($wp_posts_result) ? $wp_posts_result : [];
                                    } catch (Exception $e) {
                                        error_log('[WNS Preview] WordPress posts error: ' . $e->getMessage());
                                    }
                                }
                                
                                // Get wpForo posts for preview - with error handling
                                $wpforo_summary = [];
                                if ($include_forum) {
                                    try {
                                        if (function_exists('wns_get_wpforo_summary')) {
                                            $wpforo_result = wns_get_wpforo_summary($date_from_str, $date_to_str);
                                            $wpforo_summary = is_array($wpforo_result) ? $wpforo_result : [];
                                        }
                                    } catch (Exception $e) {
                                        error_log('[WNS Preview] wpForo posts error: ' . $e->getMessage());
                                    }
                                }
                                
                                // Generate email content safely
                                $subject = '';
                                $email_content = '';
                                try {
                                    // Use the same subject logic as the actual sending function
                                    $subject = get_option('wns_subject', wns_t('default_subject'));
                                    // Add date range to subject for uniqueness (matching actual send logic)
                                    if ($date_from_str && $date_to_str) {
                                        $subject .= " (" . date('d.m.Y', strtotime($date_from_str)) . " - " . date('d.m.Y', strtotime($date_to_str)) . ")";
                                    }
                                    
                                    if (function_exists('wns_build_email')) {
                                        // Count total items for email building
                                        $forum_post_count = 0;
                                        foreach ($wpforo_summary as $forum_data) {
                                            if (isset($forum_data['threads']) && is_array($forum_data['threads'])) {
                                                foreach ($forum_data['threads'] as $thread_data) {
                                                    if (isset($thread_data['posts']) && is_array($thread_data['posts'])) {
                                                        $forum_post_count += count($thread_data['posts']);
                                                    }
                                                }
                                            }
                                        }
                                        $total_count = count($wp_posts) + $forum_post_count;
                                        
                                        $email_content = wns_build_email($wpforo_summary, $total_count, $wp_posts);
                                    } else {
                                        $email_content = '<p>' . wns_t('email_builder_not_available') . '</p>';
                                    }
                                } catch (Exception $e) {
                                    error_log('[WNS Preview] Email build error: ' . $e->getMessage());
                                    $email_content = '<p>Error building email content: ' . esc_html($e->getMessage()) . '</p>';
                                }
                                
                                // Get recipient count safely
                                $recipients_count = 0;
                                try {
                                    if (function_exists('wns_get_user_emails')) {
                                        $user_emails = wns_get_user_emails();
                                        $recipients_count = is_array($user_emails) ? count($user_emails) : 0;
                                    }
                                } catch (Exception $e) {
                                    error_log('[WNS Preview] Recipients error: ' . $e->getMessage());
                                }
                                
                                // Count forum activities safely
                                $forum_activity_count = 0;
                                try {
                                    foreach ($wpforo_summary as $forum_data) {
                                        if (isset($forum_data['threads']) && is_array($forum_data['threads'])) {
                                            foreach ($forum_data['threads'] as $thread_data) {
                                                if (isset($thread_data['posts']) && is_array($thread_data['posts'])) {
                                                    $forum_activity_count += count($thread_data['posts']);
                                                }
                                            }
                                        }
                                    }
                                } catch (Exception $e) {
                                    error_log('[WNS Preview] Forum activity count error: ' . $e->getMessage());
                                }
                                
                                // Create content status messages
                                $wp_status = '';
                                $forum_status = '';
                                
                                if (!$include_wp) {
                                    $wp_status = ' (' . wns_t('wp_posts_disabled') . ')';
                                } elseif (empty($wp_posts)) {
                                    $wp_status = ' (' . wns_t('no_new_posts_period') . ')';
                                }
                                
                                if (!$include_forum) {
                                    $forum_status = ' (' . wns_t('forum_posts_disabled') . ')';
                                } elseif (empty($wpforo_summary)) {
                                    $forum_status = ' (' . wns_t('no_forum_activity_period') . ')';
                                }
                                ?>
                                
                                <div class="wns-preview-meta">
                                    <div class="wns-preview-meta-grid">
                                        <div class="wns-preview-meta-item">
                                            <div class="wns-preview-meta-label"><?php echo wns_t('subject_line'); ?></div>
                                            <div class="wns-preview-meta-value"><?php echo esc_html($subject); ?></div>
                                        </div>
                                        <div class="wns-preview-meta-item">
                                            <div class="wns-preview-meta-label"><?php echo wns_t('recipients'); ?></div>
                                            <div class="wns-preview-meta-value"><?php echo $recipients_count; ?> <?php echo wns_t('users'); ?></div>
                                        </div>
                                        <div class="wns-preview-meta-item">
                                            <div class="wns-preview-meta-label"><?php echo wns_t('content_period'); ?></div>
                                            <div class="wns-preview-meta-value"><?php echo date('M j', strtotime($date_from_str)) . ' - ' . date('M j, Y', strtotime($date_to_str)); ?></div>
                                        </div>
                                        <div class="wns-preview-meta-item">
                                            <div class="wns-preview-meta-label"><?php echo wns_t('wordpress_posts'); ?></div>
                                            <div class="wns-preview-meta-value"><?php echo count($wp_posts); ?> <?php echo wns_t('posts'); ?><?php echo $wp_status; ?></div>
                                        </div>
                                        <div class="wns-preview-meta-item">
                                            <div class="wns-preview-meta-label"><?php echo wns_t('forum_activities'); ?></div>
                                            <div class="wns-preview-meta-value"><?php echo $forum_activity_count; ?> <?php echo wns_t('posts'); ?> <?php echo wns_t('in'); ?> <?php echo count($wpforo_summary); ?> <?php echo wns_t('forums'); ?><?php echo $forum_status; ?></div>
                                        </div>
                                        <div class="wns-preview-meta-item">
                                            <div class="wns-preview-meta-label"><?php echo wns_t('scheduled_send'); ?></div>
                                            <div class="wns-preview-meta-value"><?php echo isset($target) ? $target->format('M j, Y H:i') : wns_t('not_calculated'); ?></div>
                                        </div>
                                    </div>
                                </div>
                                <div class="wns-preview-email-content">
                                    <script>
                                    (function() {
                                        var iframe = document.createElement('iframe');
                            iframe.setAttribute('sandbox', 'allow-same-origin');
                                        iframe.style.width = '100%';
                                        iframe.style.border = 'none';
                                        iframe.style.display = 'block';
                                        document.currentScript.parentElement.appendChild(iframe);
                                        var emailHTML = <?php echo wp_json_encode($email_content, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
                                        iframe.contentDocument.open();
                                        iframe.contentDocument.write(emailHTML);
                                        iframe.contentDocument.close();
                                        setTimeout(function() {
                                            iframe.style.height = iframe.contentDocument.body.scrollHeight + 'px';
                                        }, 100);
                                    })();
                                    </script>
                                </div>
                                
                                <?php
                            } catch (Exception $e) {
                                echo '<div class="wns-preview-error">❌ Error generating preview: ' . esc_html($e->getMessage()) . '</div>';
                                error_log('[WNS Preview] General error: ' . $e->getMessage());
                            } catch (Error $e) {
                                echo '<div class="wns-preview-error">❌ Fatal error: ' . esc_html($e->getMessage()) . '. Please check your plugin configuration.</div>';
                                error_log('[WNS Preview] Fatal error: ' . $e->getMessage());
                            }
                            ?>
                        <?php else: ?>
                            <div class="wns-preview-loading">
                                <div class="spinner"></div>
                                Preview will load when tab is selected...
                            </div>
                        <?php endif; ?>
                </div>
                
                <style>
                .wns-preview-toolbar {
                    display: flex;
                    align-items: flex-start;
                    justify-content: space-between;
                    gap: 16px 24px;
                    margin-bottom: 24px;
                }
                .wns-preview-toolbar .wns-section-title {
                    margin: 8px 0 0;
                    line-height: 1.4;
                }
                .wns-preview-actions {
                    display: flex;
                    flex-direction: column;
                    align-items: flex-end;
                    gap: 8px;
                    min-width: 0;
                }
                #wns-refresh-preview {
                    min-height: 40px;
                    padding: 8px 20px;
                    line-height: 1.5;
                    white-space: nowrap;
                }
                #wns-refresh-preview:focus-visible {
                    outline: 2px solid #2271b1;
                    outline-offset: 3px;
                }
                #wns-refresh-preview:disabled {
                    cursor: wait;
                    opacity: 0.65;
                }
                .wns-preview-status {
                    min-height: 20px;
                    max-width: 36ch;
                    font-size: 13px;
                    line-height: 1.5;
                    text-align: right;
                }
                .wns-email-preview-content {
                    background: transparent;
                    overflow: visible;
                }
                .wns-email-preview-content iframe {
                    width: 100%;
                    border: none;
                    display: block;
                }
                .wns-preview-loading {
                    text-align: center;
                    padding: 40px 20px;
                    color: #666;
                }
                .wns-preview-loading .spinner {
                    display: inline-block;
                    width: 20px;
                    height: 20px;
                    border: 2px solid #f3f3f3;
                    border-top: 2px solid #2271b1;
                    border-radius: 50%;
                    animation: spin 1s linear infinite;
                    margin-right: 10px;
                }
                @keyframes spin {
                    0% { transform: rotate(0deg); }
                    100% { transform: rotate(360deg); }
                }
                #wns-email-preview-content {
                    margin: 0;
                    padding: 0;
                }
                .wns-preview-meta {
                    background: linear-gradient(135deg, #2c2c2c 0%, #1a1a1a 100%);
                    color: #fff;
                    font-size: 13px;
                    margin: 0 0 24px;
                    padding: 24px;
                    box-shadow: 0 4px 20px rgba(0, 0, 0, 0.15);
                    border: 1px solid #444;
                    border-radius: 8px;
                }
                .wns-preview-meta-grid {
                    display: grid;
                    grid-template-columns: repeat(3, minmax(0, 1fr));
                    gap: 24px;
                }
                .wns-preview-meta-item {
                    display: flex;
                    flex-direction: column;
                    min-width: 0;
                }
                .wns-preview-meta-label {
                    font-weight: 600;
                    margin-bottom: 8px;
                    line-height: 1.4;
                    opacity: 0.9;
                }
                .wns-preview-meta-value {
                    font-size: 14px;
                    line-height: 1.6;
                    overflow-wrap: anywhere;
                }
                .wns-preview-email-content {
                    max-height: none;
                    overflow: visible;
                    padding: 0;
                }
                .wns-preview-error {
                    background: #f8d7da;
                    color: #721c24;
                    padding: 15px 20px;
                    border: 1px solid #dc3545;
                    border-radius: 6px;
                    margin-bottom: 20px;
                }
                @media (max-width: 1000px) {
                    .wns-preview-meta-grid {
                        grid-template-columns: repeat(2, minmax(0, 1fr));
                    }
                }
                @media (max-width: 600px) {
                    .wns-admin-content.wns-preview-active {
                        padding: 24px;
                    }
                    .wns-preview-toolbar {
                        flex-direction: column;
                        gap: 16px;
                    }
                    .wns-preview-toolbar .wns-section-title {
                        margin-top: 0;
                    }
                    .wns-preview-actions {
                        align-items: flex-start;
                    }
                    .wns-preview-status {
                        text-align: left;
                    }
                    .wns-preview-meta {
                        padding: 20px;
                    }
                    .wns-preview-meta-grid {
                        grid-template-columns: minmax(0, 1fr);
                        gap: 20px;
                    }
                }
                </style>
                
                <script>
                document.addEventListener('DOMContentLoaded', function() {
                    const refreshBtn = document.getElementById('wns-refresh-preview');
                    const previewContent = document.getElementById('wns-email-preview-content');
                    const previewStatus = document.querySelector('.wns-preview-status');
                    function resizePreviewFrames() {
                        if (!previewContent) return;
                        previewContent.querySelectorAll('iframe').forEach(function(frame) {
                            if (frame.contentDocument && frame.contentDocument.body) {
                                frame.style.height = frame.contentDocument.body.scrollHeight + 'px';
                            }
                        });
                    }
                    window.addEventListener('resize', resizePreviewFrames);
                    
                    // Update status on load
                    if (previewContent && previewContent.querySelector('.wns-preview-meta')) {
                        previewStatus.textContent = <?php echo wp_json_encode(wns_t('preview_loaded')); ?>;
                        previewStatus.style.color = '#237a3b';
                    } else if (previewContent && previewContent.querySelector('.wns-preview-error')) {
                        previewStatus.textContent = <?php echo wp_json_encode(wns_t('preview_error')); ?>;
                        previewStatus.style.color = '#dc3545';
                    }
                    
                    function refreshPreview() {
                        if (!refreshBtn || !previewContent || refreshBtn.disabled) return;
                        refreshBtn.disabled = true;
                        refreshBtn.textContent = <?php echo wp_json_encode(wns_t('refreshing_preview')); ?>;
                        previewStatus.textContent = <?php echo wp_json_encode(wns_t('preview_refresh_loading')); ?>;
                        previewStatus.style.color = '#646970';
                        previewContent.setAttribute('aria-busy', 'true');

                        fetch(ajaxurl, {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                            body: new URLSearchParams({
                                action: 'wns_generate_email_preview',
                                nonce: '<?php echo wp_create_nonce('wns_preview_nonce'); ?>'
                            })
                        })
                        .then(response => {
                            if (response.ok === false) throw new Error('Preview request failed');
                            return response.json();
                        })
                        .then(data => {
                            if (!data.success || !data.data || typeof data.data.html !== 'string' || typeof data.data.email_html !== 'string') throw new Error('Invalid preview');
                            // Prepare the new markup before replacing the last usable preview.
                            const nextPreview = document.createElement('div');
                            nextPreview.innerHTML = data.data.html;
                            const emailContent = nextPreview.querySelector('.wns-preview-email-content');
                            if (!emailContent) throw new Error('Missing email preview');
                            var emailFrame = document.createElement('iframe');
                            emailFrame.setAttribute('sandbox', 'allow-same-origin');
                            emailFrame.style.width = '100%';
                            emailFrame.style.border = 'none';
                            emailFrame.title = <?php echo wp_json_encode(wns_t('next_email_preview')); ?>;
                            emailContent.appendChild(emailFrame);
                            previewContent.replaceChildren(...nextPreview.childNodes);
                            emailFrame.contentDocument.open();
                            emailFrame.contentDocument.write(data.data.email_html);
                            emailFrame.contentDocument.close();
                            setTimeout(function() {
                                if (emailFrame.contentDocument && emailFrame.contentDocument.body) emailFrame.style.height = emailFrame.contentDocument.body.scrollHeight + 'px';
                            }, 100);
                            previewStatus.textContent = <?php echo wp_json_encode(wns_t('preview_refreshed')); ?>;
                            previewStatus.style.color = '#237a3b';
                        })
                        .catch(() => {
                            previewStatus.textContent = <?php echo wp_json_encode(wns_t('preview_refresh_failed')); ?>;
                            previewStatus.style.color = '#b32d2e';
                        })
                        .finally(() => {
                            previewContent.removeAttribute('aria-busy');
                            refreshBtn.disabled = false;
                            refreshBtn.textContent = <?php echo wp_json_encode(wns_t('refresh_preview')); ?>;
                        });
                    }

                    if (refreshBtn) {
                        refreshBtn.addEventListener('click', refreshPreview);
                    }
                });
                </script>
            </div>
        </div>
        
        <script>
        document.addEventListener('DOMContentLoaded', function() {
            const dirtyForms = new Set();
            document.querySelectorAll('.wns-admin-content form[action="options.php"]').forEach(function(form) {
                const initial = new URLSearchParams(new FormData(form)).toString();
                function updateDirtyState() {
                    if (new URLSearchParams(new FormData(form)).toString() === initial) dirtyForms.delete(form);
                    else dirtyForms.add(form);
                }
                form.addEventListener('input', updateDirtyState);
                form.addEventListener('change', updateDirtyState);
                form.addEventListener('submit', function() { dirtyForms.delete(form); });
            });
            window.addEventListener('beforeunload', function(event) {
                if (!window.wnsLeavingConfirmed && dirtyForms.size) {
                    event.preventDefault();
                    event.returnValue = '';
                }
            });
        });
        </script>
        <!-- Contact Footer -->
        <div class="wns-admin-footer" style="border-top: 1px solid #e8e8e8; padding: 24px 80px; background: #f8f8f8; color: #6a6a6a; font-size: 13px; font-family: ui-sans-serif, -apple-system, BlinkMacSystemFont, 'Segoe UI', Helvetica, Arial, sans-serif;">
            <div style="display: flex; justify-content: space-between; align-items: center;">
                <div>
                    <?php echo str_replace('{version}', esc_html(WNS_VERSION), wns_t('plugin_footer')); ?>
                </div>
                <div>
                    <?php wns_te('need_support'); ?>
                </div>
            </div>
        </div>
    </div>
    <?php
}

// Manual send handler - only trigger when button is clicked and on correct admin page
add_action('admin_post_wns_manual_send', function() {
    if (!current_user_can('manage_options')) {
        wp_die(wns_t('insufficient_permissions'));
    }

    // Verify nonce
    if (!isset($_POST['wns_manual_send_nonce']) || !is_string($_POST['wns_manual_send_nonce']) || !wp_verify_nonce(wp_unslash($_POST['wns_manual_send_nonce']), 'wns_manual_send_action')) {
        wp_die(wns_t('invalid_nonce'));
    }

    // Trigger manual send using the configured date range (manual override flag = true)
    $result = wns_send_newsletter(true);

    // Add persistent notice scoped to the tab from referer
    $redirect = wp_get_referer();
    $tab = wns_get_tab_from_url($redirect);
    wns_add_admin_notice('newsletter_send_result', $result['success'] ? 'success' : 'error', $result['message'], $tab);
    if (!$redirect) $redirect = admin_url('admin.php?page=wns-main');
    wp_safe_redirect($redirect);
    exit;
});

// Test email handler
add_action('admin_post_wns_test_email', function() {
    if (!current_user_can('manage_options')) wp_die(wns_t('insufficient_permissions'));
    check_admin_referer('wns_test_email_action', 'wns_test_email_nonce');
    if (!isset($_POST['test_email']) || !is_string($_POST['test_email']) || !is_email(wp_unslash($_POST['test_email']))) wp_die('Enter a valid test email address.');
    if (current_user_can('manage_options') && isset($_POST['test_email'])) {
        $test_email = sanitize_email(wp_unslash($_POST['test_email']));
        $subject = 'Newsletter Configuration Test';
        $message = '<h2>Test Email</h2><p>This is a test email to verify your newsletter mail configuration is working properly.</p><p>If you receive this email, your configuration is set up correctly.</p>';
        $headers = ['Content-Type: text/html; charset=UTF-8'];
        
        // Add filter for from name
        add_filter('wp_mail_from_name', 'wns_set_newsletter_from_name');
        
        try {
            $sent = wns_send_email($test_email, $subject, $message, $headers);
        } finally {
            remove_filter('wp_mail_from_name', 'wns_set_newsletter_from_name');
        }
        
        $redirect = wp_get_referer();
        $tab = wns_get_tab_from_url($redirect);
        if ($sent) {
            wns_add_admin_notice('test_email_sent_success', 'success', '', $tab);
        } else {
            wns_add_admin_notice('test_email_send_failed', 'error', '', $tab);
        }
        if (!$redirect) $redirect = admin_url('admin.php?page=wns-main');
        wp_safe_redirect($redirect);
        exit;
    }
});

// AJAX handler for testing cron-job.org connection
add_action('wp_ajax_wns_test_cronjob', function() {
    check_ajax_referer('wns_cronjob_test', 'nonce');
    
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Insufficient permissions']);
        return;
    }
    
    $result = wns_test_cronjob_connection();
    
    if ($result['success']) {
        wp_send_json_success(['message' => $result['message']]);
    } else {
        wp_send_json_error(['message' => isset($result['error']) ? $result['error'] : 'Connection test failed']);
    }
});

// AJAX handler for manual cron-job sync
add_action('wp_ajax_wns_sync_cronjob_manual', function() {
    check_ajax_referer('wns_cronjob_sync', 'nonce');
    
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Insufficient permissions']);
        return;
    }
    
    $result = wns_sync_cronjob();
    
    if ($result['success']) {
        wp_send_json_success(['message' => $result['message']]);
    } else {
        wp_send_json_error(['message' => isset($result['error']) ? $result['error'] : 'Sync failed']);
    }
});

// Notifications are now handled within the admin page UI instead of admin_notices

// --- ADMIN MENU: Single menu for Newsletter ---
add_action('admin_menu', function() {
    // Single top-level menu
    add_menu_page(
        wns_t('weekly_newsletter_sender'), // Page title
        'Newsletter',        // Menu title
        'manage_options',    // Capability
        'wns-main',         // Menu slug
        'wns_settings_page',// Callback for main page
        'dashicons-email',  // Icon
        25                  // Position
    );
});

// --- CRON SCHEDULING BASED ON SETTINGS ---
function wns_update_cron_schedule() {
    $hook = 'wns_send_weekly_newsletter';
    wp_clear_scheduled_hook($hook);
    if (get_option('wns_enabled', 0)) {
        // Recalculate each week to preserve the local wall-clock time across DST.
        wp_schedule_single_event(wns_scheduled_target()->getTimestamp(), $hook);
    }
}

add_action('update_option_wns_send_day', 'wns_update_cron_schedule');
add_action('update_option_wns_send_time', 'wns_update_cron_schedule');
add_action('update_option_wns_enabled', 'wns_update_cron_schedule');
add_action('update_option_timezone_string', 'wns_update_cron_schedule');
add_action('update_option_gmt_offset', 'wns_update_cron_schedule');
register_activation_hook(__FILE__, 'wns_update_cron_schedule');
register_deactivation_hook(__FILE__, function() {
    wp_clear_scheduled_hook('wns_send_weekly_newsletter');
    $result = wns_delete_cronjob();
    if (!$result['success']) error_log('[WNS] External cron job could not be removed; delete it in the cron-job.org console.');
});

// Hook to execute the newsletter sending function
add_action('wns_send_weekly_newsletter', 'wns_run_scheduled_newsletter');
