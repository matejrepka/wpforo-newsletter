<?php
/**
 * Plugin Name: Weekly Newsletter Sender
 * Plugin URI: https://marep.sk/plugins/weekly-newsletter-sender
 * Description: Automatically sends weekly newsletters with WordPress posts and wpForo forum posts to all users with customizable design, SMTP support, and comprehensive admin interface.
 * Version: 1.0.0
 * Author: Marep
 * Author URI: https://marep.sk
 * Text Domain: weekly-newsletter-sender
 * Domain Path: /languages
 * Requires at least: 6.0
 * Tested up to: 6.8
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
    
    $language_file = dirname(WNS_PLUGIN_FILE) . '/languages/' . $language . '.php';
    
    if (file_exists($language_file)) {
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
        // Calculate date range based on scheduled send time (not today)
        $send_day = get_option('wns_send_day', 'monday');
        $send_time = get_option('wns_send_time', '08:00');
        $timezone = wp_timezone();
        $next_send = new DateTime('now', $timezone);
        
        // Calculate next scheduled send date
        try {
            $target = new DateTime('this ' . $send_day, $timezone);
            $time_parts = explode(':', $send_time);
            $target->setTime((int)$time_parts[0], (int)$time_parts[1]);
            
            if ($target < $next_send) {
                $target->modify('+1 week');
            }
            
            // Date range should be 7 days before the scheduled send
            $date_to = clone $target;
            $date_to->modify('-1 day'); // Day before send
            $date_from = clone $date_to;
            $date_from->modify('-6 days'); // 7 days total (including end date)
            
            $date_from_str = $date_from->format('Y-m-d');
            $date_to_str = $date_to->format('Y-m-d');
            
        } catch (Exception $e) {
            // Fallback to 7 days before scheduled send time if date calculation fails
            $fallback_end = date('Y-m-d', strtotime('next ' . $send_day . ' -1 day'));
            $date_to_str = $fallback_end;
            $date_from_str = date('Y-m-d', strtotime($fallback_end . ' -6 days'));
        }
        
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
        <div class="wns-preview-email-content">
            <script>
            (function() {
                var iframe = document.createElement(\'iframe\');
                iframe.style.width = \'100%\';
                iframe.style.border = \'none\';
                iframe.style.display = \'block\';
                document.currentScript.parentElement.appendChild(iframe);
                var emailHTML = ' . json_encode($email_content) . ';
                iframe.contentDocument.open();
                iframe.contentDocument.write(emailHTML);
                iframe.contentDocument.close();
                setTimeout(function() {
                    iframe.style.height = iframe.contentDocument.body.scrollHeight + \'px\';
                }, 100);
            })();
            </script>
        </div>';
        
        wp_send_json_success(array(
            'html' => $preview_html,
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
        wp_send_json_error('Error generating preview: ' . $e->getMessage());
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
    if (empty($password)) {
        return '';
    }
    
    // Use WordPress salts for encryption key
    $key = wp_salt('auth') . wp_salt('secure_auth');
    $key = hash('sha256', $key);
    
    // Generate a random IV
    $iv = openssl_random_pseudo_bytes(16);
    
    // Encrypt the password
    $encrypted = openssl_encrypt($password, 'AES-256-CBC', $key, 0, $iv);
    
    // Combine IV and encrypted data, then base64 encode
    return base64_encode($iv . $encrypted);
}

function wns_decrypt_password($encrypted_password) {
    if (empty($encrypted_password)) {
        return '';
    }
    
    try {
        // Use WordPress salts for encryption key
        $key = wp_salt('auth') . wp_salt('secure_auth');
        $key = hash('sha256', $key);
        
        // Decode the base64 data
        $data = base64_decode($encrypted_password);
        
        // Extract IV and encrypted password
        $iv = substr($data, 0, 16);
        $encrypted = substr($data, 16);
        
        // Decrypt the password
        $decrypted = openssl_decrypt($encrypted, 'AES-256-CBC', $key, 0, $iv);
        
        return $decrypted !== false ? $decrypted : '';
    } catch (Exception $e) {
        error_log('[WNS] Password decryption error: ' . $e->getMessage());
        return '';
    }
}

// --- DATA FETCHING ---
function wns_get_wpforo_summary($date_from = null, $date_to = null) {
    global $wpdb;
    // Date logic
    if ($date_from && $date_to) {
        $where = $wpdb->prepare('p.created BETWEEN %s AND %s', $date_from . ' 00:00:00', $date_to . ' 23:59:59');
    } else {
        $date = date('Y-m-d H:i:s', strtotime('-7 days'));
        $where = $wpdb->prepare('p.created >= %s', $date);
    }
    $forums = $wpdb->get_results("SELECT forumid, title FROM {$wpdb->prefix}wpforo_forums ORDER BY title ASC", OBJECT_K);
    $topics = $wpdb->get_results("SELECT topicid, forumid, title FROM {$wpdb->prefix}wpforo_topics", OBJECT_K);
    $posts = $wpdb->get_results("
        SELECT p.*, t.title AS thread_subject, f.title AS forum_name
        FROM {$wpdb->prefix}wpforo_posts p
        LEFT JOIN {$wpdb->prefix}wpforo_topics t ON t.topicid = p.topicid
        LEFT JOIN {$wpdb->prefix}wpforo_forums f ON f.forumid = t.forumid
        WHERE $where
        ORDER BY f.title ASC, t.title ASC, p.created ASC
    ");
    // Group by forum and thread
    $summary = [];
    foreach ($posts as $post) {
        $fid = $post->forumid;
        $tid = $post->topicid;
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
    $args = [
        'post_type' => 'post',
        'post_status' => 'publish',
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
    font-size: 1.25em;
    margin: 1.5em {$content_padding}px 1.2em;
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
<body class='wns-email-body' style='font-family: {$font_family}; margin:0; padding:{$content_padding}px; color: {$text_color}; line-height: {$line_height};'>
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
            $message .= '<div class="wns-forum-header"><span class="wns-category-label">'.wns_t('forum').'</span><span class="wns-forum-name">'.esc_html($forum['forum_name']).'</span></div>';
            foreach ($forum['threads'] as $thread) {
                $message .= '<div class="wns-thread-header"><span class="wns-topic-label">'.wns_t('topic').'</span><span class="wns-thread-name">'.esc_html($thread['thread_subject']).'</span></div>';
                foreach ($thread['posts'] as $post) {
                    $url = function_exists('wpforo_topic') ? wpforo_topic($post->topicid, 'url') : site_url("/community/topic/{$post->topicid}");
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
            $header_content .= '<div style="font-size: 16px; opacity: 0.9;">' . esc_html($header_subtitle) . '</div>';
        }
    } else {
        $header_content = '<h1 style="margin: 0 !important; padding: 0; border: none; font-size: '.$header_text_size.'px; color: white;">' . esc_html($header_title) . '</h1>';
        if (!empty($header_subtitle)) {
            $header_content .= '<div style="font-size: 16px; opacity: 0.9;">' . esc_html($header_subtitle) . '</div>';
        }
    }

    $message = "<div class='wns-email-header' style='background: {$header_color}; color: #fff; text-align: center; padding: {$content_padding}px;'>{$header_content}</div><div class='wns-intro' style='font-size: 1.25em; margin: 1.5em {$content_padding}px 1.2em; color: {$text_color};'><strong>".esc_html($intro)."</strong></div>";

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
            $message .= '<div class="wns-forum-header"><span class="wns-category-label">'.wns_t('forum').'</span><span class="wns-forum-name">'.esc_html($forum['forum_name']).'</span></div>';
            foreach ($forum['threads'] as $thread) {
                $message .= '<div class="wns-thread-header"><span class="wns-topic-label">'.wns_t('topic').'</span><span class="wns-thread-name">'.esc_html($thread['thread_subject']).'</span></div>';
                foreach ($thread['posts'] as $post) {
                    $url = function_exists('wpforo_topic') ? wpforo_topic($post->topicid, 'url') : site_url("/community/topic/{$post->topicid}");
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
        $message .= '<div class="wns-email-footer">' . nl2br(esc_html($footer_text)) . '</div>';
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
    $allowed_tags = '<blockquote><b><strong><i><em><br>';
    $text = strip_tags($html, $allowed_tags);

    // Split by spaces, but keep tags
    $output = '';
    $word_count = 0;
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
                    break 2;
                }
            }
        }
    }

    if ($word_count >= $max_words) {
        $output .= '...';
        if ($url) $output .= "<br />";
    }

    return $output;
}

// --- GET USER EMAILS BY ROLE ---
function wns_get_user_emails() {
    $roles = get_option('wns_roles', ['subscriber', 'administrator']);
    $args = [
        'role__in' => $roles,
        'fields' => ['user_email']
    ];
    $users = get_users($args);
    return wp_list_pluck($users, 'user_email');
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
            $header_content .= '<div style="font-size: 16px; opacity: 0.9;">' . esc_html($header_subtitle) . '</div>';
        }
    } else {
        $header_content = '<h1 style="margin: 0 !important; padding: 0; border: none; font-size: '.$header_text_size.'px; color: white;">' . esc_html($header_title) . '</h1>';
        if (!empty($header_subtitle)) {
            $header_content .= '<div style="font-size: 16px; opacity: 0.9;">' . esc_html($header_subtitle) . '</div>';
        }
    }

    $message = "<div class='wns-email-header' style='background: {$header_color}; color: #fff; text-align: center; padding: {$content_padding}px;'>{$header_content}</div><div class='wns-intro' style='font-size: 1.25em; margin: 1.5em {$content_padding}px 1.2em; color: {$text_color};'><strong>".esc_html($intro)."</strong></div>";

    // WordPress posts section with demo data
    $message .= '<section class="wns-wp-posts"><h2 class="wns-section-title">'.wns_t('latest_articles_website').'</h2>';
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
        $message .= '<div class="wns-forum-header"><span class="wns-category-label">'.wns_t('forum').'</span><span class="wns-forum-name">'.esc_html($forum['forum_name']).'</span></div>';
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
        $message .= '<div class="wns-email-footer">' . nl2br(esc_html($footer_text)) . '</div>';
    }
    
    // Apply inline styles for better email client compatibility
    $message = wns_add_inline_styles($message);
    
    // Use unified template wrapper
    return wns_wrap_email_template($message, wns_t('weekly_newsletter_title'));
}

// --- MAIN SEND FUNCTION ---
function wns_send_newsletter($manual_override = false) {
    // Add filter only for this send
    add_filter('wp_mail_from_name', 'wns_set_newsletter_from_name');

    // Date range logic
    $date_from = null;
    $date_to = null;
    // Always use settings for both auto and manual send
    $type = get_option('wns_date_range_type', 'week');
    $timezone = wp_timezone();
    if ($type === 'week') {
        // Calculate based on scheduled send time (consistent with preview)
        $send_day = get_option('wns_send_day', 'monday');
        $send_time = get_option('wns_send_time', '08:00');
        
        try {
            $target = new DateTime('this ' . $send_day, $timezone);
            $time_parts = explode(':', $send_time);
            $target->setTime((int)$time_parts[0], (int)$time_parts[1]);
            
            $now = new DateTime('now', $timezone);
            // Manual send should use the configured scheduled send range (matching preview behavior)
            // which corresponds to the next scheduled send (in the future). Automatic/cron
            // sends should use the most recent scheduled send (in the past or now).
            if (!empty($manual_override)) {
                // For manual sends: if the configured target is in the past, move to next week
                // so we use the upcoming scheduled range (consistent with preview).
                if ($target < $now) {
                    $target->modify('+1 week');
                }
            } else {
                // For automatic sends: if the configured target is in the future, use previous week
                // so that the send covers the most recent completed period.
                if ($target > $now) {
                    $target->modify('-1 week');
                }
            }
            
            // Date range should be 7 days before the scheduled send
            $date_to_obj = clone $target;
            $date_to_obj->modify('-1 day'); // Day before send
            $date_from_obj = clone $date_to_obj;
            $date_from_obj->modify('-6 days'); // 7 days total (including end date)
            
            $date_from = $date_from_obj->format('Y-m-d');
            $date_to = $date_to_obj->format('Y-m-d');
            
        } catch (Exception $e) {
            // Fallback to 7 days before scheduled send time if calculation fails
            $fallback_end = date('Y-m-d', strtotime('next ' . $send_day . ' -1 day'));
            $date_to = $fallback_end;
            $date_from = date('Y-m-d', strtotime($fallback_end . ' -6 days'));
        }
    } elseif ($type === 'custom') {
        $date_from = get_option('wns_date_from');
        $date_to = get_option('wns_date_to');
    }
    if (!get_option('wns_enabled')) return;
    $include_forum = get_option('wns_include_forum', 1);
    $include_wp = get_option('wns_include_wp', 1);
    $summary = $include_forum ? wns_get_wpforo_summary($date_from, $date_to) : [];
    $wp_posts = $include_wp ? wns_get_wp_posts_summary($date_from, $date_to) : [];
    $count = 0;
    if ($include_forum) foreach ($summary as $forum) foreach ($forum['threads'] as $thread) $count += count($thread['posts']);
    if ($include_wp) $count += count($wp_posts);
    // Build and send the actual email for the current period
    $email_content = wns_build_email($summary, $count, $wp_posts);
    $subject = get_option('wns_subject');
    if ($date_from && $date_to) {
        $subject .= " (" . date('d.m.Y', strtotime($date_from)) . " - " . date('d.m.Y', strtotime($date_to)) . ")";
    }
    $headers = [
        'Content-Type: text/html; charset=UTF-8',
    ];
    $recipients = wns_get_user_emails();
    $log = '';
    $sent_count = 0;
    foreach ($recipients as $email) {
        $sent = wns_send_email($email, $subject, $email_content, $headers);
        if (!$sent) {
            $log .= "[WNS] Failed to send to $email\n";
        } else {
            $log .= "[WNS] Sent to $email\n";
            $sent_count++;
        }
    }
    // Remove filter after sending
    remove_filter('wp_mail_from_name', 'wns_set_newsletter_from_name');
    $log .= "[WNS] Newsletter send finished. Recipients: $sent_count, Posts: $count, Date range: $date_from to $date_to\n";
    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log($log);
    }

    // --- Prepare next scheduled send date/time and next email content (do NOT send) ---
    try {
        $send_day = get_option('wns_send_day', 'monday');
        $send_time = get_option('wns_send_time', '08:00');
        $timezone = wp_timezone();
        $next = new DateTime('now', $timezone);
        $target = new DateTime('this ' . $send_day, $timezone);
        $time_parts = explode(':', $send_time);
        $target->setTime((int)$time_parts[0], (int)$time_parts[1]);
        if ($target < $next) {
            $target->modify('+1 week');
        }
        // Save next scheduled send date/time
        update_option('wns_next_scheduled_send', $target->format('Y-m-d H:i:s'));

        // Prepare next email content (date range for next period)
        $date_to_obj = clone $target;
        $date_to_obj->modify('-1 day');
        $date_from_obj = clone $date_to_obj;
        $date_from_obj->modify('-6 days');
        $date_from_next = $date_from_obj->format('Y-m-d');
        $date_to_next = $date_to_obj->format('Y-m-d');
        $include_forum = get_option('wns_include_forum', 1);
        $include_wp = get_option('wns_include_wp', 1);
        $summary_next = $include_forum ? wns_get_wpforo_summary($date_from_next, $date_to_next) : [];
        $wp_posts_next = $include_wp ? wns_get_wp_posts_summary($date_from_next, $date_to_next) : [];
        $count_next = 0;
        if ($include_forum) foreach ($summary_next as $forum) foreach ($forum['threads'] as $thread) $count_next += count($thread['posts']);
        if ($include_wp) $count_next += count($wp_posts_next);
        $next_email_content = wns_build_email($summary_next, $count_next, $wp_posts_next);
        update_option('wns_next_email_content', $next_email_content);
    } catch (Exception $e) {
        error_log('[WNS] Error preparing next email content: ' . $e->getMessage());
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
    // Check for WordPress constants first (more secure), then database
    $smtp_host = defined('WNS_SMTP_HOST') ? WNS_SMTP_HOST : get_option('wns_smtp_host', '');
    $smtp_port = defined('WNS_SMTP_PORT') ? WNS_SMTP_PORT : get_option('wns_smtp_port', '587');
    $smtp_username = defined('WNS_SMTP_USERNAME') ? WNS_SMTP_USERNAME : get_option('wns_smtp_username', '');
    $smtp_password = defined('WNS_SMTP_PASSWORD') ? WNS_SMTP_PASSWORD : wns_decrypt_password(get_option('wns_smtp_password', ''));
    $smtp_encryption = defined('WNS_SMTP_ENCRYPTION') ? WNS_SMTP_ENCRYPTION : get_option('wns_smtp_encryption', 'tls');
    $from_name = get_option('wns_from_name', 'Newsletter');
    
    // Validate SMTP settings
    if (empty($smtp_host) || empty($smtp_username) || empty($smtp_password)) {
        error_log('[WNS] SMTP settings incomplete, falling back to wp_mail');
        return wp_mail($to, $subject, $message, $headers);
    }
    
    // Use WordPress's wp_mail with PHPMailer configuration
    add_action('phpmailer_init', function($phpmailer) use ($smtp_host, $smtp_port, $smtp_username, $smtp_password, $smtp_encryption, $from_name) {
        $phpmailer->isSMTP();
        $phpmailer->Host = $smtp_host;
        $phpmailer->SMTPAuth = true;
        $phpmailer->Username = $smtp_username;
        $phpmailer->Password = $smtp_password;
        $phpmailer->Port = (int)$smtp_port;
        
        // Set encryption
        if ($smtp_encryption === 'ssl') {
            $phpmailer->SMTPSecure = 'ssl';
        } elseif ($smtp_encryption === 'tls') {
            $phpmailer->SMTPSecure = 'tls';
        }
        
        // Set from name
        $phpmailer->setFrom($smtp_username, $from_name);
    });
    
    // Send using wp_mail which will use our PHPMailer configuration
    $result = wp_mail($to, $subject, $message, $headers);
    
    // Remove the action after sending
    remove_all_actions('phpmailer_init');
    
    return $result;
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
    if (!current_user_can('manage_options')) {
        wp_send_json_error('no_permission', 403);
    }

    $removed = [];
    $errors = [];

    // Support single id or multiple ids via ids[] or JSON string
    $ids = [];
    if (isset($_POST['id'])) {
        $ids[] = sanitize_text_field($_POST['id']);
    }
    if (isset($_POST['ids']) && is_array($_POST['ids'])) {
        foreach ($_POST['ids'] as $i) $ids[] = sanitize_text_field($i);
    }
    // If raw body JSON with ids
    if (empty($ids)) {
        $raw = file_get_contents('php://input');
        if ($raw) {
            $json = json_decode($raw, true);
            if ($json && isset($json['ids']) && is_array($json['ids'])) {
                foreach ($json['ids'] as $i) $ids[] = sanitize_text_field($i);
            }
        }
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
    add_option('wns_enabled', 1);
    add_option('wns_date_range_type', 'week'); // new: week or custom
    add_option('wns_date_from', ''); // new: custom from
    add_option('wns_date_to', '');   // new: custom to
    add_option('wns_send_day', 'monday'); // new: day of week
    add_option('wns_send_time', '08:00'); // new: time of day
    add_option('wns_include_forum', 1); // new: include forum posts
    add_option('wns_include_wp', 1); // new: include wp posts
    add_option('wns_from_name', 'Forum & News'); // new: from name for newsletter only
    add_option('wns_language', 'en'); // new: language preference
    
    // Mail configuration options
    add_option('wns_mail_type', 'wordpress'); // wordpress or smtp
    add_option('wns_smtp_host', '');
    add_option('wns_smtp_port', '587');
    add_option('wns_smtp_username', '');
    add_option('wns_smtp_password', '');
    add_option('wns_smtp_encryption', 'tls'); // tls, ssl, or none
    
    register_setting('wns_options_group', 'wns_subject');
    register_setting('wns_options_group', 'wns_roles'); // <-- Make editable
    register_setting('wns_options_group', 'wns_enabled');
    register_setting('wns_options_group', 'wns_date_range_type');
    register_setting('wns_options_group', 'wns_date_from');
    register_setting('wns_options_group', 'wns_date_to');
    register_setting('wns_options_group', 'wns_send_day');
    register_setting('wns_options_group', 'wns_send_time');
    register_setting('wns_options_group', 'wns_include_forum');
    register_setting('wns_options_group', 'wns_include_wp');
    register_setting('wns_options_group', 'wns_from_name'); // new: from name
    
    // Language settings - separate group
    register_setting('wns_language_group', 'wns_language'); // language preference
    
    // Email design settings
    register_setting('wns_design_group', 'wns_email_header_color');
    register_setting('wns_design_group', 'wns_email_accent_color');
    register_setting('wns_design_group', 'wns_email_text_color');
    register_setting('wns_design_group', 'wns_email_font_family');
    register_setting('wns_design_group', 'wns_email_logo_url');
    register_setting('wns_design_group', 'wns_email_footer_text');
    register_setting('wns_design_group', 'wns_email_card_bg_color');
    register_setting('wns_design_group', 'wns_email_card_border_color');
    register_setting('wns_design_group', 'wns_email_header_text_size');
    register_setting('wns_design_group', 'wns_email_content_padding');
    register_setting('wns_design_group', 'wns_email_card_radius');
    register_setting('wns_design_group', 'wns_email_line_height');
    register_setting('wns_design_group', 'wns_email_header_title');
    register_setting('wns_design_group', 'wns_email_header_subtitle');
    register_setting('wns_design_group', 'wns_email_intro_text');
    
    // Mail configuration settings - separate group
    register_setting('wns_mail_group', 'wns_mail_type');
    register_setting('wns_mail_group', 'wns_smtp_host');
    register_setting('wns_mail_group', 'wns_smtp_port');
    register_setting('wns_mail_group', 'wns_smtp_username');
    register_setting('wns_mail_group', 'wns_smtp_password', [
        'sanitize_callback' => 'wns_sanitize_smtp_password'
    ]);
    register_setting('wns_mail_group', 'wns_smtp_encryption');
}

// Sanitize and encrypt SMTP password before saving
function wns_sanitize_smtp_password($password) {
    if (empty($password)) {
        return '';
    }
    
    // Get current stored password
    $current_password = get_option('wns_smtp_password', '');
    
    // If the submitted password is the masked version, keep the current encrypted password
    if ($password === '••••••••••••' && !empty($current_password)) {
        return $current_password;
    }
    
    // If it's a new password, encrypt it
    return wns_encrypt_password($password);
}
add_action('admin_init', 'wns_register_settings');

function wns_set_newsletter_from_name($name) {
    $custom_name = get_option('wns_from_name', 'Forum & News');
    return $custom_name;
}

function wns_settings_page() {
    // Force fresh translation loading for admin context
    global $wns_translations;
    $wns_translations = null; // Clear any cached translations
    $language = get_option('wns_language', 'en');
    wns_load_translations($language);
    
    $current_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'settings';
    
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
        margin: -20px -20px 0 0; /* Expand beyond WordPress admin margins */
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
    
    /* Tab Navigation */
    .wns-tab-nav {
        display: flex;
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
        max-width: 900px;
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
        height: 6px;
        border-radius: 3px;
        background: #ddd;
        outline: none;
        -webkit-appearance: none;
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
        appearance: none;
        -webkit-appearance: none;
        -moz-appearance: none;
        border: 1px solid #c8c8c8;
        border-radius: 2px;
        background: #ffffff;
        position: relative;
        transition: all 0.15s ease;
    }
    
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
        border-left: 3px solid #4a4a4a;
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
        position: sticky;
        top: 32px;
        max-height: calc(100vh - 60px);
        overflow-y: auto;
        border-radius: 8px;
        padding: 20px;
        min-width: 700px;
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
            max-width: none;
        }
        
        .wns-design-preview {
            position: static;
            max-height: none;
            order: -1;
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
        border-left: 4px solid;
        background: #fff;
        box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        font-family: ui-sans-serif, -apple-system, BlinkMacSystemFont, "Segoe UI", Helvetica, Arial, sans-serif;
        font-size: 14px;
        line-height: 1.4;
        animation: wns-notification-slide 0.3s ease-out;
    }
    
    .wns-notification.success {
        border-left-color: #10b981;
        background: linear-gradient(135deg, #f0fdf4 0%, #ecfdf5 100%);
        color: #065f46;
    }
    
    .wns-notification.error {
        border-left-color: #ef4444;
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
            <div class="wns-tab-nav">
                <a href="<?php echo admin_url('admin.php?page=wns-main&tab=settings'); ?>" 
                   class="wns-tab-button <?php echo $current_tab === 'settings' ? 'active' : ''; ?>">
                    <?php wns_te('tab_settings'); ?>
                </a>
                <a href="<?php echo admin_url('admin.php?page=wns-main&tab=configuration'); ?>" 
                   class="wns-tab-button <?php echo $current_tab === 'configuration' ? 'active' : ''; ?>">
                    <?php wns_te('tab_configuration'); ?>
                </a>
                <a href="<?php echo admin_url('admin.php?page=wns-main&tab=design'); ?>" 
                   class="wns-tab-button <?php echo $current_tab === 'design' ? 'active' : ''; ?>">
                    <?php wns_te('tab_email_design'); ?>
                </a>
                <a href="<?php echo admin_url('admin.php?page=wns-main&tab=preview'); ?>" 
                   class="wns-tab-button <?php echo $current_tab === 'preview' ? 'active' : ''; ?>">
                    <?php wns_te('tab_preview'); ?>
                </a>
            </div>
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
                    <div class="wns-language-options">
                        <label class="wns-language-option <?php echo $current_language === 'en' ? 'active' : ''; ?>">
                            <input type="radio" name="wns_language" value="en" <?php checked($current_language, 'en'); ?> />
                            <span class="wns-flag">🇺🇸</span>
                            <span class="wns-language-name"><?php wns_te('english'); ?></span>
                        </label>
                        <label class="wns-language-option <?php echo $current_language === 'sk' ? 'active' : ''; ?>">
                            <input type="radio" name="wns_language" value="sk" <?php checked($current_language, 'sk'); ?> />
                            <span class="wns-flag">🇸🇰</span>
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
                        const newLang = this.value;
                        const langNames = {
                            'en': '<?php wns_te("english"); ?>',
                            'sk': '<?php wns_te("slovak"); ?>'
                        };
                        
                        if (newLang !== currentLang) {
                            const message = 'Switch interface language to ' + langNames[newLang] + '? The page will reload to apply changes.';
                            if (confirm(message)) {
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
                <div class="wns-notification <?php echo esc_attr($type); ?>" data-wns-id="<?php echo esc_attr($nid); ?>" data-wns-tab="<?php echo esc_attr($notice_tab); ?>" data-wns-preserve="<?php echo $preserve ? '1' : '0'; ?>">
                    <span class="wns-notification-icon">
                        <?php if ($type === 'success'): ?>
                            ✓
                        <?php elseif ($type === 'error'): ?>
                            ⚠
                        <?php endif; ?>
                    </span>
                    <span class="wns-notification-message"><?php echo esc_html($text); ?></span>
                    <button type="button" class="wns-notice-dismiss" aria-label="Dismiss" style="float:right; background:transparent; border:none; font-size:16px; cursor:pointer;">&times;</button>
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
                        // If sending via beacon or keepalive, batch into a JSON body
                        if (useBeacon && navigator && navigator.sendBeacon) {
                            var url = ajaxurl;
                            var payload = JSON.stringify({ action: 'wns_dismiss_admin_notice', ids: ids });
                            var blob = new Blob([payload], { type: 'application/json' });
                            navigator.sendBeacon(url, blob);
                            return;
                        }

                        // Try fetch with keepalive
                        if (useBeacon && window.fetch) {
                            fetch(ajaxurl, {
                                method: 'POST',
                                headers: { 'Content-Type': 'application/json' },
                                body: JSON.stringify({ action: 'wns_dismiss_admin_notice', ids: ids }),
                                keepalive: true,
                                credentials: 'same-origin'
                            }).catch(function(){});
                            return;
                        }

                        // Fallback: send requests sequentially using fetch
                        ids.forEach(function(id){
                            var d = new FormData();
                            d.append('action', 'wns_dismiss_admin_notice');
                            d.append('id', id);
                            fetch(ajaxurl, { method: 'POST', body: d, credentials: 'same-origin' }).catch(function(){});
                        });
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
            display: none;
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

        <div class="wns-admin-content">
            
            <!-- Settings Tab -->
            <div class="wns-tab-content <?php echo $current_tab === 'settings' ? 'active' : ''; ?>">
                <div class="wns-section">
                    <form id="wns-settings-form" method="post" action="options.php">
                        <?php settings_fields('wns_options_group'); ?>
                        
                        <div class="wns-form-row">
                            <label class="wns-form-label"><?php wns_te('enable_newsletter'); ?></label>
                            <label class="wns-checkbox-label">
                                <input type="checkbox" name="wns_enabled" value="1" class="wns-checkbox-input" <?php checked(1, get_option('wns_enabled'), true); ?> />
                                <?php wns_te('enable_disable_sending'); ?>
                            </label>
                        </div>
                        
                        <div class="wns-form-row">
                            <label class="wns-form-label"><?php wns_te('from_name_label'); ?></label>
                            <input type="text" name="wns_from_name" value="<?php echo esc_attr($from_name); ?>" class="wns-form-input" />
                            <div class="wns-form-help"><?php wns_te('from_name_help'); ?></div>
                        </div>
                        
                        <div class="wns-form-row">
                            <label class="wns-form-label"><?php wns_te('email_subject'); ?></label>
                            <input type="text" name="wns_subject" value="<?php echo esc_attr(get_option('wns_subject')); ?>" class="wns-form-input" />
                        </div>
                        
                        <div class="wns-form-row">
                            <label class="wns-form-label"><?php wns_te('user_roles_to_send'); ?></label>
                            <div class="wns-checkbox-group">
                                <label class="wns-checkbox-label">
                                    <input type="checkbox" name="wns_roles[]" value="subscriber" class="wns-checkbox-input" <?php checked(in_array('subscriber', $roles)); ?>>
                                    <?php wns_te('role_subscriber'); ?>
                                </label>
                                <label class="wns-checkbox-label">
                                    <input type="checkbox" name="wns_roles[]" value="administrator" class="wns-checkbox-input" <?php checked(in_array('administrator', $roles)); ?>>
                                    <?php wns_te('role_administrator'); ?>
                                </label>
                            </div>
                            <div class="wns-form-help"><?php wns_te('roles_help'); ?></div>
                        </div>
                        
                        <div class="wns-form-row">
                            <label class="wns-form-label"><?php wns_te('date_range_newsletter'); ?></label>
                            <select name="wns_date_range_type" id="wns_date_range_type" class="wns-form-select">
                                <option value="week" <?php selected($date_range_type, 'week'); ?>><?php wns_te('latest_this_week'); ?></option>
                                <option value="custom" <?php selected($date_range_type, 'custom'); ?>><?php wns_te('custom_range'); ?></option>
                            </select>
                            <div id="wns_custom_dates" class="wns-date-inputs" style="<?php if($date_range_type!=='custom') echo 'display:none;'; ?>">
                                <label><?php wns_te('from_label'); ?></label>
                                <input type="date" name="wns_date_from" value="<?php echo esc_attr($date_from); ?>" class="wns-form-input">
                                <label><?php wns_te('to_label'); ?></label>
                                <input type="date" name="wns_date_to" value="<?php echo esc_attr($date_to); ?>" class="wns-form-input">
                            </div>
                            <div class="wns-form-help"><?php wns_te('date_range_help'); ?></div>
                            <script>
                            document.getElementById('wns_date_range_type').addEventListener('change', function() {
                                document.getElementById('wns_custom_dates').style.display = this.value === 'custom' ? '' : 'none';
                            });
                            </script>
                        </div>
                        
                        <div class="wns-form-row">
                            <label class="wns-form-label"><?php wns_te('include_forum_posts'); ?></label>
                            <label class="wns-checkbox-label">
                                <input type="checkbox" name="wns_include_forum" value="1" class="wns-checkbox-input" <?php checked(1, $include_forum, true); ?> />
                                <?php wns_te('include_wpforo_posts'); ?>
                            </label>
                        </div>
                        
                        <div class="wns-form-row">
                            <label class="wns-form-label"><?php wns_te('include_website_posts'); ?></label>
                            <label class="wns-checkbox-label">
                                <input type="checkbox" name="wns_include_wp" value="1" class="wns-checkbox-input" <?php checked(1, $include_wp, true); ?> />
                                <?php wns_te('include_wordpress_posts'); ?>
                            </label>
                        </div>
                        
                        <div class="wns-form-row">
                            <label class="wns-form-label"><?php wns_te('send_day'); ?></label>
                            <select name="wns_send_day" class="wns-form-select">
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
                            <label class="wns-form-label"><?php wns_te('send_time'); ?></label>
                            <input type="time" name="wns_send_time" value="<?php echo esc_attr($send_time); ?>" class="wns-form-input" />
                        </div>
                        
                        <div class="wns-form-row">
                            <label class="wns-form-label"><?php wns_te('next_scheduled_send'); ?></label>
                            <div class="wns-info-text">
                                <?php
                                $send_day = get_option('wns_send_day', 'monday');
                                $send_time = get_option('wns_send_time', '08:00');
                                $timezone = wp_timezone();
                                $next = new DateTime('now', $timezone);
                                
                                // More robust date construction
                                try {
                                    $target = new DateTime('this ' . $send_day, $timezone);
                                    $time_parts = explode(':', $send_time);
                                    $target->setTime((int)$time_parts[0], (int)$time_parts[1]);
                                    
                                    if ($target < $next) {
                                        $target->modify('+1 week');
                                    }
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
                    <div class="wns-section-title"><?php wns_te('manual_send'); ?></div>
                    <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
                        <?php wp_nonce_field('wns_manual_send_action', 'wns_manual_send_nonce'); ?>
                        <input type="hidden" name="action" value="wns_manual_send" />
                        <?php submit_button(wns_t('send_newsletter_now'), 'secondary large wns-button wns-button-secondary'); ?>
                    </form>
                </div>
            </div>
            
            <!-- Email Design Tab -->
            <div class="wns-tab-content <?php echo $current_tab === 'design' ? 'active' : ''; ?>">
                <?php if ($current_tab === 'preview'): ?>
                    <div class="wns-section">
                        <div class="wns-section-title"><?php wns_te('live_newsletter_preview'); ?></div>
                        <div class="wns-info-text">
                            <?php wns_te('live_preview_description'); ?>
                        </div>
                        
                        <div style="
                            background: #f5f5f5;
                            padding: 32px;
                            border-radius: 6px;
                            border: 1px solid #e0e0e0;
                            margin-top: 24px;
                            ">
                            <div class="wns-email-preview" id="preview-email-container">
                                <div style="text-align: center; padding: 40px; color: #666;">
                                    <?php wns_te('loading_preview'); ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <script>
                    document.addEventListener('DOMContentLoaded', function() {
                        // Get all form inputs for real-time updates
                        var designInputs = {
                            headerTitle: document.getElementById('header-title'),
                            headerSubtitle: document.getElementById('header-subtitle'),
                            logoUrl: document.getElementById('logo-url'),
                            introText: document.getElementById('intro-text'),
                            headerColor: document.getElementById('header-color'),
                            headerTextSize: document.getElementById('header-text-size'),
                            accentColor: document.getElementById('accent-color'),
                            textColor: document.getElementById('text-color'),
                            cardBgColor: document.getElementById('card-bg-color'),
                            cardBorderColor: document.getElementById('card-border-color'),
                            contentPadding: document.getElementById('content-padding'),
                            cardRadius: document.getElementById('card-radius'),
                            lineHeight: document.getElementById('line-height'),
                            fontFamily: document.getElementById('font-family'),
                            footerText: document.getElementById('footer-text')
                        };
                        
                        // Load actual email content with current design settings
                        loadEmailPreview();
                        
                        // Add event listeners to all design inputs for real-time updates
                        Object.values(designInputs).forEach(function(input) {
                            if (input) {
                                input.addEventListener('input', loadEmailPreview);
                                input.addEventListener('change', loadEmailPreview);
                            }
                        });
                        
                        // Generate dynamic CSS for email based on current settings
                        function generateDynamicEmailCSS(settings) {
                            return `
body.wns-email-body {
    font-family: ${settings.fontFamily};
    margin: 0;
    padding: ${settings.contentPadding}px;
    color: ${settings.textColor};
    line-height: ${settings.lineHeight};
}
.wns-email-content {
    color: ${settings.textColor};
    background: #fff;
    padding: 0;
    margin: 0 auto;
    border-radius: ${settings.cardRadius}px;
    overflow: hidden;
    max-width: 700px;
    box-shadow: 0 8px 32px rgba(0,0,0,0.2);
    border: 1px solid ${settings.headerColor};
}
.wns-email-header {
    background: ${settings.headerColor};
    color: #fff;
    text-align: center;
    padding: ${settings.contentPadding}px;
}
.wns-email-header h1 {
    margin: 0 !important;
    padding: 0;
    border: none;
}
.wns-section-title {
    color: ${settings.textColor};
    font-size: 1.45em;
    margin-top: 1.5em;
    margin-bottom: 0.8em;
    font-weight: 700;
    margin-left: ${settings.contentPadding}px;
}
.wns-intro {
    font-size: 1.25em;
    margin: 1.5em ${settings.contentPadding}px 1.2em;
    color: ${settings.textColor};
}
.wns-card {
    background: ${settings.cardBgColor};
    border: 1px solid ${settings.cardBorderColor};
    border-radius: ${settings.cardRadius}px;
    margin: 0 ${settings.contentPadding}px 1.5em;
    padding: ${settings.contentPadding}px;
    box-sizing: border-box;
}
.wns-wp-posts, .wns-forum-section {
    margin-bottom: 2em;
    padding: 0 ${settings.contentPadding}px;
}
.wns-forum-header, .wns-thread-header {
    display: flex;
    align-items: center;
    margin-top: 1.2em;
    margin-bottom: 0.7em;
    margin-left: ${settings.contentPadding}px;
}
.wns-category-label {
    color: #fff;
    background: ${settings.headerColor};
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
    color: ${settings.textColor};
}
.wns-topic-label {
    color: #fff;
    background: ${settings.accentColor};
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
    color: ${settings.textColor};
}
.wns-post-title {
    font-size: 1.02em;
    font-weight: 700 !important;
    color: ${settings.textColor};
    margin-bottom: 0.15em;
}
.wns-post-meta {
    color: ${settings.textColor};
    font-size: 0.95em;
    margin-bottom: 0.3em;
}
.wns-forum-post .wns-post-meta {
    color: ${settings.textColor};
    font-size: 1.02em;
    font-weight: 600;
    margin-bottom: 0.3em;
}
.wns-reply-label {
    color: #777777;
    font-size: 0.95em;
    font-weight: 600;
    margin-bottom: 0.15em;
    display: inline-block;
}
.wns-post-excerpt {
    color: ${settings.textColor};
    font-size: 1em;
    margin-bottom: 0.7em;
    margin-top: 0.1em;
    line-height: ${settings.lineHeight};
}
.wns-forum-quote {
    border-left: 3px solid ${settings.accentColor};
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
    color: ${settings.accentColor};
    font-size: 0.98em;
    text-decoration: underline;
    font-weight: 600;
}
.wns-email-footer {
    background: #f5f5f5;
    color: #666;
    padding: 15px ${settings.contentPadding}px;
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
                        }
                        
                        function loadEmailPreview() {
                            var container = document.getElementById('preview-email-container');
                            
                            // Create iframe for isolated email styles  
                            var iframe = document.createElement('iframe');
                            iframe.style.width = '100%';
                            iframe.style.border = 'none';
                            iframe.style.backgroundColor = 'transparent';
                            iframe.style.borderRadius = '8px';
                            iframe.style.boxShadow = '0 1px 3px rgba(0,0,0,0.1)';
                            
                            container.innerHTML = '';
                            container.appendChild(iframe);
                            
                            // Get current design settings from form inputs (real-time values)
                            var designSettings = {
                                headerTitle: designInputs.headerTitle ? (designInputs.headerTitle.value || '<?php echo esc_js(get_option('wns_email_header_title', 'Weekly Newsletter')); ?>') : '<?php echo esc_js(get_option('wns_email_header_title', 'Weekly Newsletter')); ?>',
                                headerSubtitle: designInputs.headerSubtitle ? designInputs.headerSubtitle.value : '<?php echo esc_js(get_option('wns_email_header_subtitle', '')); ?>',
                                logoUrl: designInputs.logoUrl ? designInputs.logoUrl.value : '<?php echo esc_js(get_option('wns_email_logo_url', '')); ?>',
                                introText: designInputs.introText ? (designInputs.introText.value || '<?php echo esc_js(get_option('wns_email_intro_text', wns_t('intro_text_placeholder'))); ?>') : '<?php echo esc_js(get_option('wns_email_intro_text', wns_t('intro_text_placeholder'))); ?>',
                                headerColor: designInputs.headerColor ? designInputs.headerColor.value : '<?php echo esc_js(get_option('wns_email_header_color', '#2c5aa0')); ?>',
                                headerTextSize: designInputs.headerTextSize ? designInputs.headerTextSize.value : '<?php echo esc_js(get_option('wns_email_header_text_size', '28')); ?>',
                                accentColor: designInputs.accentColor ? designInputs.accentColor.value : '<?php echo esc_js(get_option('wns_email_accent_color', '#0073aa')); ?>',
                                textColor: designInputs.textColor ? designInputs.textColor.value : '<?php echo esc_js(get_option('wns_email_text_color', '#333333')); ?>',
                                cardBgColor: designInputs.cardBgColor ? designInputs.cardBgColor.value : '<?php echo esc_js(get_option('wns_email_card_bg_color', '#f8f8f8')); ?>',
                                cardBorderColor: designInputs.cardBorderColor ? designInputs.cardBorderColor.value : '<?php echo esc_js(get_option('wns_email_card_border_color', '#e5e5e5')); ?>',
                                contentPadding: designInputs.contentPadding ? designInputs.contentPadding.value : '<?php echo esc_js(get_option('wns_email_content_padding', '20')); ?>',
                                cardRadius: designInputs.cardRadius ? designInputs.cardRadius.value : '<?php echo esc_js(get_option('wns_email_card_radius', '7')); ?>',
                                lineHeight: designInputs.lineHeight ? designInputs.lineHeight.value : '<?php echo esc_js(get_option('wns_email_line_height', '1.5')); ?>',
                                fontFamily: designInputs.fontFamily ? designInputs.fontFamily.value : '<?php echo esc_js(get_option('wns_email_font_family', 'Arial, sans-serif')); ?>',
                                footerText: designInputs.footerText ? designInputs.footerText.value : '<?php echo esc_js(get_option('wns_email_footer_text', '')); ?>'
                            };
                            
                            // Build email HTML with actual content but using design settings
                            var emailHTML = buildPreviewEmail(designSettings);
                            
                            // Write to iframe
                            iframe.contentDocument.open();
                            iframe.contentDocument.write(emailHTML);
                            iframe.contentDocument.close();
                            
                            // Auto-resize iframe
                            setTimeout(function() {
                                iframe.style.height = iframe.contentDocument.body.scrollHeight + 'px';
                            }, 100);
                        }
                        
                        function buildPreviewEmail(settings) {
                            // Build header content
                            var headerContent = '';
                            if (settings.logoUrl) {
                                headerContent = '<img src="' + settings.logoUrl + '" alt="Logo" style="max-height: 60px; max-width: 250px;" />';
                                if (settings.headerSubtitle) {
                                    headerContent += '<div style="margin-top: 10px; font-size: 16px; opacity: 0.9;">' + settings.headerSubtitle + '</div>';
                                }
                            } else {
                                headerContent = '<h1 style="margin: 0 !important; padding: 0; border: none; font-size: ' + settings.headerTextSize + 'px; color: white;">' + settings.headerTitle + '</h1>';
                                if (settings.headerSubtitle) {
                                    headerContent += '<div style="margin-top: 8px; font-size: 16px; opacity: 0.9;">' + settings.headerSubtitle + '</div>';
                                }
                            }
                            
                            // Get actual content from PHP
                            var actualContent = '<?php
                                // Use the same settings as the main send function
                                $type = get_option('wns_date_range_type', 'week');
                                $timezone = wp_timezone();
                                $date_from = null;
                                $date_to = null;
                                if ($type === 'week') {
                                    // Calculate based on scheduled send time (consistent with preview)
                                    $send_day = get_option('wns_send_day', 'monday');
                                    $send_time = get_option('wns_send_time', '08:00');
                                    
                                    try {
                                        $target = new DateTime('this ' . $send_day, $timezone);
                                        $time_parts = explode(':', $send_time);
                                        $target->setTime((int)$time_parts[0], (int)$time_parts[1]);
                                        
                                        $now = new DateTime('now', $timezone);
                                        if ($target < $now) {
                                            $target->modify('+1 week');
                                        }
                                        
                                        // Date range should be 7 days before the scheduled send
                                        $date_to_obj = clone $target;
                                        $date_to_obj->modify('-1 day'); // Day before send
                                        $date_from_obj = clone $date_to_obj;
                                        $date_from_obj->modify('-6 days'); // 7 days total (including end date)
                                        
                                        $date_from = $date_from_obj->format('Y-m-d');
                                        $date_to = $date_to_obj->format('Y-m-d');
                                        
                                    } catch (Exception $e) {
                                        // Fallback to 7 days before scheduled send time if calculation fails
                                        $fallback_end = date('Y-m-d', strtotime('next ' . $send_day . ' -1 day'));
                                        $date_to = $fallback_end;
                                        $date_from = date('Y-m-d', strtotime($fallback_end . ' -6 days'));
                                    }
                                } elseif ($type === 'custom') {
                                    $date_from = get_option('wns_date_from');
                                    $date_to = get_option('wns_date_to');
                                }
                                $include_forum = get_option('wns_include_forum', 1);
                                $include_wp = get_option('wns_include_wp', 1);
                                $summary = $include_forum ? wns_get_wpforo_summary($date_from, $date_to) : [];
                                $wp_posts = $include_wp ? wns_get_wp_posts_summary($date_from, $date_to) : [];
                                $count = 0;
                                if ($include_forum) foreach ($summary as $forum) foreach ($forum['threads'] as $thread) $count += count($thread['posts']);
                                if ($include_wp) $count += count($wp_posts);
                                echo esc_js(wns_build_preview_content_only($summary, $count, $wp_posts));
                            ?>';
                            
                            // Build intro
                            var introContent = settings.introText.replace('{count}', '<?php
                                $type = get_option('wns_date_range_type', 'week');
                                $timezone = wp_timezone();
                                $date_from = null;
                                $date_to = null;
                                if ($type === 'week') {
                                    // Calculate based on scheduled send time (consistent with preview)
                                    $send_day = get_option('wns_send_day', 'monday');
                                    $send_time = get_option('wns_send_time', '08:00');
                                    
                                    try {
                                        $target = new DateTime('this ' . $send_day, $timezone);
                                        $time_parts = explode(':', $send_time);
                                        $target->setTime((int)$time_parts[0], (int)$time_parts[1]);
                                        
                                        $now = new DateTime('now', $timezone);
                                        if ($target < $now) {
                                            $target->modify('+1 week');
                                        }
                                        
                                        // Date range should be 7 days before the scheduled send
                                        $date_to_obj = clone $target;
                                        $date_to_obj->modify('-1 day'); // Day before send
                                        $date_from_obj = clone $date_to_obj;
                                        $date_from_obj->modify('-6 days'); // 7 days total (including end date)
                                        
                                        $date_from = $date_from_obj->format('Y-m-d');
                                        $date_to = $date_to_obj->format('Y-m-d');
                                        
                                    } catch (Exception $e) {
                                        // Fallback to 7 days before scheduled send time if calculation fails
                                        $fallback_end = date('Y-m-d', strtotime('next ' . $send_day . ' -1 day'));
                                        $date_to = $fallback_end;
                                        $date_from = date('Y-m-d', strtotime($fallback_end . ' -6 days'));
                                    }
                                } elseif ($type === 'custom') {
                                    $date_from = get_option('wns_date_from');
                                    $date_to = get_option('wns_date_to');
                                }
                                $include_forum = get_option('wns_include_forum', 1);
                                $include_wp = get_option('wns_include_wp', 1);
                                $summary = $include_forum ? wns_get_wpforo_summary($date_from, $date_to) : [];
                                $wp_posts = $include_wp ? wns_get_wp_posts_summary($date_from, $date_to) : [];
                                $count = 0;
                                if ($include_forum) foreach ($summary as $forum) foreach ($forum['threads'] as $thread) $count += count($thread['posts']);
                                if ($include_wp) $count += count($wp_posts);
                                echo $count;
                            ?>');
                            
                            // Footer
                            var footerHTML = settings.footerText 
                                ? '<div class="wns-email-footer">' + settings.footerText.replace(/\n/g, '<br>') + '</div>'
                                : '';
                            
                            // Use dynamically generated CSS for real-time updates
                            var dynamicCSS = generateDynamicEmailCSS(settings);
                            
                            return `<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<style>
${dynamicCSS}
</style>
</head>
<body class='wns-email-body' style='font-family: ${settings.fontFamily}; margin:0; padding:${settings.contentPadding}px; color: ${settings.textColor}; line-height: ${settings.lineHeight};'>
<div class='wns-email-content' style='color: ${settings.textColor}; background:#fff; padding: 0; margin: 0 auto; border-radius: ${settings.cardRadius}px; overflow: hidden; max-width: 700px; box-shadow: 0 8px 32px rgba(0,0,0,0.2); border: 1px solid ${settings.headerColor};'>
<div class='wns-email-header'>${headerContent}</div>
<div class='wns-intro'><strong>${introContent}</strong></div>
${actualContent}
${footerHTML}
</div>
</body>
</html>`;
                        }
                    });
                    </script>
                <?php endif; ?>
            </div>
            
            <!-- Email Design Tab -->
            <div class="wns-tab-content <?php echo $current_tab === 'design' ? 'active' : ''; ?>">
                <div class="wns-design-container">
                    <div class="wns-design-settings">
                        <div class="wns-section">
                            <div class="wns-section-title"><?php wns_te('email_design_customization'); ?></div>
                            
                            <form method="post" action="options.php" id="design-form">
                                <?php settings_fields('wns_design_group'); ?>
                                
                                <div class="wns-form-row">
                                    <label class="wns-form-label"><?php wns_te('header_title_label'); ?></label>
                                    <input type="text" name="wns_email_header_title" id="header-title" value="<?php echo esc_attr(get_option('wns_email_header_title', 'Weekly Newsletter')); ?>" class="wns-form-input" placeholder="<?php echo esc_attr(wns_t('weekly_newsletter_title')); ?>" />
                                    <div class="wns-form-help"><?php wns_te('header_title_help'); ?></div>
                                </div>
                                
                                <div class="wns-form-row">
                                    <label class="wns-form-label"><?php wns_te('header_subtitle_label'); ?></label>
                                    <input type="text" name="wns_email_header_subtitle" id="header-subtitle" value="<?php echo esc_attr(get_option('wns_email_header_subtitle', '')); ?>" class="wns-form-input" placeholder="<?php echo esc_attr(wns_t('header_subtitle_placeholder')); ?>" />
                                    <div class="wns-form-help"><?php wns_te('header_subtitle_help'); ?></div>
                                </div>
                                
                                <div class="wns-form-row">
                                    <label class="wns-form-label"><?php wns_te('logo_url_label'); ?></label>
                                    <input type="url" name="wns_email_logo_url" id="logo-url" value="<?php echo esc_attr(get_option('wns_email_logo_url', '')); ?>" class="wns-form-input" placeholder="<?php echo esc_attr(wns_t('logo_url_placeholder')); ?>" />
                                    <div class="wns-form-help"><?php wns_te('logo_url_help'); ?></div>
                                </div>
                                
                                <div class="wns-form-row">
                                    <label class="wns-form-label"><?php wns_te('header_color_label'); ?></label>
                                    <input type="color" name="wns_email_header_color" id="header-color" value="<?php echo esc_attr(get_option('wns_email_header_color', '#2c5aa0')); ?>" class="wns-form-input" />
                                    <div class="wns-form-help"><?php wns_te('header_color_help'); ?></div>
                                </div>
                                
                                <div class="wns-form-row">
                                    <label class="wns-form-label"><?php wns_te('intro_text_label'); ?></label>
                                    <textarea name="wns_email_intro_text" id="intro-text" class="wns-form-input" style="min-height: 80px; resize: vertical;" rows="3" placeholder="<?php echo esc_attr(wns_t('intro_text_placeholder')); ?>"><?php echo esc_textarea(get_option('wns_email_intro_text', wns_t('intro_text_placeholder'))); ?></textarea>
                                    <div class="wns-form-help"><?php wns_te('intro_text_help'); ?></div>
                                </div>
                                
                                <div class="wns-form-row">
                                    <label class="wns-form-label"><?php wns_te('header_text_size_label'); ?></label>
                                    <input type="range" name="wns_email_header_text_size" id="header-text-size" value="<?php echo esc_attr(get_option('wns_email_header_text_size', '28')); ?>" min="16" max="48" class="wns-form-range" />
                                    <span id="header-text-size-value"><?php echo get_option('wns_email_header_text_size', '28'); ?>px</span>
                                    <div class="wns-form-help"><?php wns_te('header_text_size_help'); ?></div>
                                </div>
                                
                                <div class="wns-form-row">
                                    <label class="wns-form-label"><?php wns_te('accent_color_label'); ?></label>
                                    <input type="color" name="wns_email_accent_color" id="accent-color" value="<?php echo esc_attr(get_option('wns_email_accent_color', '#0073aa')); ?>" class="wns-form-input" />
                                    <div class="wns-form-help"><?php wns_te('accent_color_help'); ?></div>
                                </div>
                                
                                <div class="wns-form-row">
                                    <label class="wns-form-label"><?php wns_te('text_color_label'); ?></label>
                                    <input type="color" name="wns_email_text_color" id="text-color" value="<?php echo esc_attr(get_option('wns_email_text_color', '#333333')); ?>" class="wns-form-input" />
                                    <div class="wns-form-help"><?php wns_te('text_color_help'); ?></div>
                                </div>
                                
                                <div class="wns-form-row">
                                    <label class="wns-form-label"><?php wns_te('card_background_color_label'); ?></label>
                                    <input type="color" name="wns_email_card_bg_color" id="card-bg-color" value="<?php echo esc_attr(get_option('wns_email_card_bg_color', '#f8f8f8')); ?>" class="wns-form-input" />
                                    <div class="wns-form-help"><?php wns_te('card_background_color_help'); ?></div>
                                </div>
                                
                                <div class="wns-form-row">
                                    <label class="wns-form-label"><?php wns_te('card_border_color_label'); ?></label>
                                    <input type="color" name="wns_email_card_border_color" id="card-border-color" value="<?php echo esc_attr(get_option('wns_email_card_border_color', '#e5e5e5')); ?>" class="wns-form-input" />
                                    <div class="wns-form-help"><?php wns_te('card_border_color_help'); ?></div>
                                </div>
                                
                                <div class="wns-form-row">
                                    <label class="wns-form-label"><?php wns_te('content_padding_label'); ?></label>
                                    <input type="range" name="wns_email_content_padding" id="content-padding" value="<?php echo esc_attr(get_option('wns_email_content_padding', '20')); ?>" min="10" max="40" class="wns-form-range" />
                                    <span id="content-padding-value"><?php echo get_option('wns_email_content_padding', '20'); ?>px</span>
                                    <div class="wns-form-help"><?php wns_te('content_padding_help'); ?></div>
                                </div>
                                
                                <div class="wns-form-row">
                                    <label class="wns-form-label"><?php wns_te('card_border_radius_label'); ?></label>
                                    <input type="range" name="wns_email_card_radius" id="card-radius" value="<?php echo esc_attr(get_option('wns_email_card_radius', '7')); ?>" min="0" max="20" class="wns-form-range" />
                                    <span id="card-radius-value"><?php echo get_option('wns_email_card_radius', '7'); ?>px</span>
                                    <div class="wns-form-help"><?php wns_te('card_border_radius_help'); ?></div>
                                </div>
                                
                                <div class="wns-form-row">
                                    <label class="wns-form-label"><?php wns_te('line_height_label'); ?></label>
                                    <input type="range" name="wns_email_line_height" id="line-height" value="<?php echo esc_attr(get_option('wns_email_line_height', '1.5')); ?>" min="1" max="2" step="0.1" class="wns-form-range" />
                                    <span id="line-height-value"><?php echo get_option('wns_email_line_height', '1.5'); ?></span>
                                    <div class="wns-form-help"><?php wns_te('line_height_help'); ?></div>
                                </div>
                                
                                <div class="wns-form-row">
                                    <label class="wns-form-label"><?php wns_te('font_family_label'); ?></label>
                                    <select name="wns_email_font_family" id="font-family" class="wns-form-input">
                                        <?php $current_font = get_option('wns_email_font_family', 'Arial, sans-serif'); ?>
                                        <option value="Arial, sans-serif" <?php selected($current_font, 'Arial, sans-serif'); ?>>Arial</option>
                                        <option value="Helvetica, Arial, sans-serif" <?php selected($current_font, 'Helvetica, Arial, sans-serif'); ?>>Helvetica</option>
                                        <option value="Georgia, serif" <?php selected($current_font, 'Georgia, serif'); ?>>Georgia</option>
                                        <option value="'Times New Roman', serif" <?php selected($current_font, "'Times New Roman', serif"); ?>>Times New Roman</option>
                                        <option value="Verdana, sans-serif" <?php selected($current_font, 'Verdana, sans-serif'); ?>>Verdana</option>
                                        <option value="'Trebuchet MS', sans-serif" <?php selected($current_font, "'Trebuchet MS', sans-serif"); ?>>Trebuchet MS</option>
                                        <option value="'Segoe UI', Tahoma, Geneva, Verdana, sans-serif" <?php selected($current_font, "'Segoe UI', Tahoma, Geneva, Verdana, sans-serif"); ?>>Segoe UI</option>
                                    </select>
                                    <div class="wns-form-help"><?php wns_te('font_family_help'); ?></div>
                                </div>
                                
                                <div class="wns-form-row">
                                    <label class="wns-form-label"><?php wns_te('footer_text_label'); ?></label>
                                    <textarea name="wns_email_footer_text" id="footer-text" class="wns-form-input" style="min-height: 100px; resize: vertical;" rows="4" placeholder="<?php echo esc_attr(wns_t('footer_text_placeholder')); ?>"><?php echo esc_textarea(get_option('wns_email_footer_text', '')); ?></textarea>
                                    <div class="wns-form-help"><?php wns_te('footer_text_help'); ?></div>
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
                    
                    // Range input value displays
                    function updateRangeDisplay() {
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
                        setTimeout(function() {
                            iframe.style.height = iframe.contentDocument.body.scrollHeight + 'px';
                        }, 100);
                    }
                    
                    function buildDynamicEmail() {
                        var values = {
                            headerTitle: inputs.headerTitle.value || '<?php echo esc_js(wns_t('weekly_newsletter_title')); ?>',
                            headerSubtitle: inputs.headerSubtitle.value,
                            logoUrl: inputs.logoUrl.value,
                            introText: inputs.introText.value || 'This week we have prepared {count} new posts and articles for you.',
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
    font-size: 1.25em;
    margin: 1.5em ${values.contentPadding}px 1.2em;
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
<body class='wns-email-body' style='font-family: ${values.fontFamily}; margin:0; padding:${values.contentPadding}px; color: ${values.textColor}; line-height: ${values.lineHeight};'>
<div class='wns-email-content' style='color: ${values.textColor}; background:#fff; padding: 0; margin: 0 auto; border-radius: ${values.cardRadius}px; overflow: hidden; max-width: 700px; box-shadow: 0 8px 32px rgba(0,0,0,0.2); border: 1px solid ${values.headerColor};'>
<div class='wns-email-header'>${headerContent}</div>
<div class='wns-intro'><strong>${introContent}</strong></div>
<section class="wns-wp-posts">
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
<div class="wns-forum-header"><span class="wns-category-label"><?php echo esc_js(wns_t('forum')); ?></span><span class="wns-forum-name">General Discussion</span></div>
<div class="wns-thread-header"><span class="wns-topic-label"><?php echo esc_js(wns_t('topic')); ?></span><span class="wns-thread-name">Beginner Questions</span></div>
<div class='wns-card wns-forum-post'>
<div class='wns-post-meta'>John Smith &middot; Sep 14, 2025 08:30</div>
<div class='wns-post-excerpt'>Hello everyone! I'm a complete beginner in programming and would like to learn. Can you recommend which programming language would be best to start with? I'm thinking between Python and JavaScript...</div>
<div class='wns-post-readmore'><a href='#'><?php echo esc_js(wns_t('continue_reading_forum')); ?></a></div>
</div>
<div class="wns-forum-header"><span class="wns-category-label"><?php echo esc_js(wns_t('forum')); ?></span><span class="wns-forum-name">Web Design</span></div>
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
                    <div class="wns-section-title"><?php wns_te('mail_configuration'); ?></div>
                    
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
                            <label class="wns-form-label"><?php wns_te('mail_method'); ?></label>
                            <div style="margin-top: 12px;">
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
                                <label class="wns-form-label"><?php wns_te('smtp_host'); ?></label>
                                <input type="text" name="wns_smtp_host" value="<?php echo esc_attr($smtp_host); ?>" class="wns-form-input" placeholder="smtp.gmail.com" />
                                <div class="wns-form-help"><?php wns_te('smtp_host_help'); ?></div>
                            </div>
                            
                            <div class="wns-form-row">
                                <label class="wns-form-label"><?php wns_te('smtp_port'); ?></label>
                                <input type="number" name="wns_smtp_port" value="<?php echo esc_attr($smtp_port); ?>" class="wns-form-input" placeholder="587" />
                                <div class="wns-form-help"><?php wns_te('smtp_port_help'); ?></div>
                            </div>
                            
                            <div class="wns-form-row">
                                <label class="wns-form-label"><?php wns_te('smtp_username'); ?></label>
                                <input type="text" name="wns_smtp_username" value="<?php echo esc_attr($smtp_username); ?>" class="wns-form-input" placeholder="your-email@domain.com" />
                                <div class="wns-form-help"><?php wns_te('smtp_username_help'); ?></div>
                            </div>
                            
                            <div class="wns-form-row">
                                <label class="wns-form-label"><?php wns_te('smtp_password'); ?></label>
                                <input type="password" name="wns_smtp_password" value="<?php echo esc_attr($smtp_password_display); ?>" class="wns-form-input" placeholder="Your SMTP password or app password" />
                                <div class="wns-form-help"><?php wns_te('smtp_password_help'); ?></div>
                            </div>
                            
                            <div class="wns-form-row">
                                <label class="wns-form-label"><?php wns_te('encryption'); ?></label>
                                <select name="wns_smtp_encryption" class="wns-form-select">
                                    <option value="tls" <?php selected($smtp_encryption, 'tls'); ?>><?php wns_te('encryption_tls'); ?></option>
                                    <option value="ssl" <?php selected($smtp_encryption, 'ssl'); ?>><?php wns_te('encryption_ssl'); ?></option>
                                    <option value="none" <?php selected($smtp_encryption, 'none'); ?>><?php wns_te('encryption_none'); ?></option>
                                </select>
                                <div class="wns-form-help"><?php wns_te('encryption_help'); ?></div>
                            </div>
                        </div>
                        
                        <?php submit_button(wns_t('save_configuration'), 'primary large wns-button'); ?>
                    </form>
                </div>
                
                <div class="wns-divider"></div>
                
                <div class="wns-section">
                    <div class="wns-section-title"><?php wns_te('test_email_configuration'); ?></div>
                    <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
                        <input type="hidden" name="action" value="wns_test_email" />
                        <div class="wns-form-row">
                            <label class="wns-form-label"><?php wns_te('test_email_address'); ?></label>
                            <input type="email" name="test_email" value="<?php echo esc_attr(wp_get_current_user()->user_email); ?>" class="wns-form-input" required />
                            <div class="wns-form-help"><?php wns_te('test_email_help'); ?></div>
                        </div>
                        <?php submit_button(wns_t('send_test_email'), 'secondary large wns-button wns-button-secondary'); ?>
                    </form>
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
                });
                </script>
            </div>
            
            <!-- Preview Tab -->
            <div class="wns-tab-content <?php echo $current_tab === 'preview' ? 'active' : ''; ?>">
                <div id="wns-email-preview-content" class="wns-email-preview-content">
                        <?php if ($current_tab === 'preview'): ?>
                            <?php
                            // Auto-generate preview when tab is active
                            try {
                                // Get date range type from settings (week or custom)
                                $date_range_type = get_option('wns_date_range_type', 'week');
                                $timezone = wp_timezone();
                                
                                // Calculate date range based on settings
                                if ($date_range_type === 'custom') {
                                    // Use custom date range from settings
                                    $date_from_str = get_option('wns_date_from');
                                    $date_to_str = get_option('wns_date_to');
                                    
                                    // Validate custom dates
                                    if (empty($date_from_str) || empty($date_to_str)) {
                                        throw new Exception('Custom date range not properly configured');
                                    }
                                } else {
                                    // Use week-based calculation (7 days before scheduled send)
                                    $send_day = get_option('wns_send_day', 'monday');
                                    $send_time = get_option('wns_send_time', '08:00');
                                    $next_send = new DateTime('now', $timezone);
                                    
                                    // Calculate next scheduled send date
                                    try {
                                        $target = new DateTime('this ' . $send_day, $timezone);
                                        $time_parts = explode(':', $send_time);
                                        $target->setTime((int)$time_parts[0], (int)$time_parts[1]);
                                        
                                        if ($target < $next_send) {
                                            $target->modify('+1 week');
                                        }
                                        
                                        // Date range should be 7 days before the scheduled send
                                        $date_to = clone $target;
                                        $date_to->modify('-1 day'); // Day before send
                                        $date_from = clone $date_to;
                                        $date_from->modify('-6 days'); // 7 days total (including end date)
                                        
                                        $date_from_str = $date_from->format('Y-m-d');
                                        $date_to_str = $date_to->format('Y-m-d');
                                        
                                    } catch (Exception $e) {
                                        // Fallback to 7 days before scheduled send time if date calculation fails
                                        $fallback_end = date('Y-m-d', strtotime('next ' . $send_day . ' -1 day'));
                                        $date_to_str = $fallback_end;
                                        $date_from_str = date('Y-m-d', strtotime($fallback_end . ' -6 days'));
                                    }
                                }
                                
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
                                    <div>
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
                                        iframe.style.width = '100%';
                                        iframe.style.border = 'none';
                                        iframe.style.display = 'block';
                                        document.currentScript.parentElement.appendChild(iframe);
                                        var emailHTML = <?php echo json_encode($email_content); ?>;
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
                .wns-preview-controls-section {
                    margin-bottom: 20px;
                    padding: 15px;
                    background: #f8f9fa;
                    border-radius: 6px;
                    border: 1px solid #dee2e6;
                }
                .wns-email-preview-content {
                    background: transparent;
                    overflow: visible;
                }
                .wns-email-preview-content iframe {
                    width: 100%;
                    border: none;
                    display: block;
                    min-height: 600px;
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
                    margin: -48px -80px 0 -80px;
                    padding: 48px 80px 0 80px;
                }
                .wns-preview-meta {
                    background: linear-gradient(135deg, #2c2c2c 0%, #1a1a1a 100%);
                    color: #fff;
                    font-size: 13px;
                    margin: -48px -80px 30px -80px;
                    padding: 25px 80px;
                    box-shadow: 0 4px 20px rgba(0, 0, 0, 0.15);
                    border: 1px solid #444;
                    border-radius: 8px;
                }
                .wns-preview-meta > div {
                    display: grid;
                    grid-template-columns: repeat(3, 1fr);
                    gap: 20px 30px;
                }
                .wns-preview-meta-item {
                    display: flex;
                    flex-direction: column;
                }
                .wns-preview-meta-label {
                    font-weight: 600;
                    margin-bottom: 2px;
                    opacity: 0.9;
                }
                .wns-preview-meta-value {
                    font-size: 14px;
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
                    border-left: 4px solid #dc3545;
                    border-radius: 6px;
                    margin-bottom: 20px;
                }
                </style>
                
                <script>
                document.addEventListener('DOMContentLoaded', function() {
                    const refreshBtn = document.getElementById('wns-refresh-preview');
                    const previewContent = document.getElementById('wns-email-preview-content');
                    const previewStatus = document.querySelector('.wns-preview-status');
                    
                    // Update status on load
                    if (previewContent && previewContent.querySelector('.wns-preview-meta')) {
                        previewStatus.textContent = 'Preview loaded successfully';
                        previewStatus.style.color = '#28a745';
                    } else if (previewContent && previewContent.querySelector('.wns-preview-error')) {
                        previewStatus.textContent = 'Error in preview';
                        previewStatus.style.color = '#dc3545';
                    }
                    
                    function refreshPreview() {
                        if (!refreshBtn || !previewContent) return;
                        
                        refreshBtn.disabled = true;
                        refreshBtn.textContent = '⏳ Refreshing...';
                        previewStatus.textContent = 'Refreshing preview...';
                        previewStatus.style.color = '#666';
                        
                        previewContent.innerHTML = '<div class="wns-preview-loading"><div class="spinner"></div>Refreshing your email preview...</div>';
                        
                        // AJAX request to refresh preview
                        fetch(ajaxurl, {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/x-www-form-urlencoded',
                            },
                            body: new URLSearchParams({
                                action: 'wns_generate_email_preview',
                                nonce: '<?php echo wp_create_nonce('wns_preview_nonce'); ?>'
                            })
                        })
                        .then(response => response.json())
                        .then(data => {
                            if (data.success) {
                                previewContent.innerHTML = data.data.html;
                                previewStatus.textContent = 'Preview refreshed successfully';
                                previewStatus.style.color = '#28a745';
                            } else {
                                previewContent.innerHTML = '<div class="wns-preview-error">❌ Error: ' + data.data + '</div>';
                                previewStatus.textContent = 'Error refreshing preview';
                                previewStatus.style.color = '#dc3545';
                            }
                        })
                        .catch(error => {
                            previewContent.innerHTML = '<div class="wns-preview-error">❌ Network error: Could not refresh preview</div>';
                            previewStatus.textContent = 'Network error';
                            previewStatus.style.color = '#dc3545';
                        })
                        .finally(() => {
                            refreshBtn.disabled = false;
                            refreshBtn.textContent = 'Refresh Preview';
                        });
                    }
                    
                    if (refreshBtn) {
                        refreshBtn.addEventListener('click', refreshPreview);
                    }
                });
                </script>
            </div>
        </div>
        
        <!-- Contact Footer -->
        <div style="border-top: 1px solid #e8e8e8; padding: 24px 80px; background: #f8f8f8; color: #6a6a6a; font-size: 13px; font-family: ui-sans-serif, -apple-system, BlinkMacSystemFont, 'Segoe UI', Helvetica, Arial, sans-serif;">
            <div style="display: flex; justify-content: space-between; align-items: center;">
                <div>
                    <?php wns_te('plugin_footer'); ?>
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
    if (!isset($_POST['wns_manual_send_nonce']) || !wp_verify_nonce($_POST['wns_manual_send_nonce'], 'wns_manual_send_action')) {
        wp_die(wns_t('invalid_nonce'));
    }

    // Trigger manual send using the configured date range (manual override flag = true)
    wns_send_newsletter(true);

    // Add persistent notice scoped to the tab from referer
    $redirect = wp_get_referer();
    $tab = wns_get_tab_from_url($redirect);
    wns_add_admin_notice('newsletter_sent_manually', 'success', '', $tab);
    if (!$redirect) $redirect = admin_url('admin.php?page=wns-main');
    wp_redirect($redirect);
    exit;
});

// Test email handler
add_action('admin_post_wns_test_email', function() {
    if (current_user_can('manage_options') && isset($_POST['test_email'])) {
        $test_email = sanitize_email($_POST['test_email']);
        $subject = 'Newsletter Configuration Test';
        $message = '<h2>Test Email</h2><p>This is a test email to verify your newsletter mail configuration is working properly.</p><p>If you receive this email, your configuration is set up correctly.</p>';
        $headers = ['Content-Type: text/html; charset=UTF-8'];
        
        // Add filter for from name
        add_filter('wp_mail_from_name', 'wns_set_newsletter_from_name');
        
        $sent = wns_send_email($test_email, $subject, $message, $headers);
        
        // Remove filter after sending
        remove_filter('wp_mail_from_name', 'wns_set_newsletter_from_name');
        
        $redirect = wp_get_referer();
        $tab = wns_get_tab_from_url($redirect);
        if ($sent) {
            wns_add_admin_notice('test_email_sent_success', 'success', '', $tab);
        } else {
            wns_add_admin_notice('test_email_send_failed', 'error', '', $tab);
        }
        if (!$redirect) $redirect = admin_url('admin.php?page=wns-main');
        wp_redirect($redirect);
        exit;
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
    if (get_option('wns_enabled')) {
        $send_day = get_option('wns_send_day', 'monday');
        $send_time = get_option('wns_send_time', '08:00');
        $timezone = wp_timezone();
        $next = new DateTime('now', $timezone);
        
        try {
            $target = new DateTime('this ' . $send_day, $timezone);
            $time_parts = explode(':', $send_time);
            $target->setTime((int)$time_parts[0], (int)$time_parts[1]);
            
            if ($target < $next) {
                $target->modify('+1 week');
            }
            $time = $target->getTimestamp();
            if (!wp_next_scheduled($hook)) {
                // Always schedule as 'weekly'
                wp_schedule_event($time, 'weekly', $hook);
            }
        } catch (Exception $e) {
            error_log('[WNS] Error scheduling cron: ' . $e->getMessage());
        }
    }
}
add_action('update_option_wns_send_day', 'wns_update_cron_schedule');
add_action('update_option_wns_send_time', 'wns_update_cron_schedule');
add_action('update_option_wns_enabled', 'wns_update_cron_schedule');
register_activation_hook(__FILE__, 'wns_update_cron_schedule');
register_deactivation_hook(__FILE__, function() {
    wp_clear_scheduled_hook('wns_send_weekly_newsletter');
});

// Hook to execute the newsletter sending function
add_action('wns_send_weekly_newsletter', 'wns_send_newsletter');