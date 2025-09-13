<?php
/**
 * Plugin Name: Weekly Newsletter Sender
 * Description: Sends a weekly newsletter with WordPress posts and wpForo forum posts to all users.
 * Version: 1.1
 * Author: Marep
 * Author URI: https://marep.sk
 */

if (!defined('ABSPATH')) exit;

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
            return '<blockquote class="forum-quote">'.nl2br(esc_html($quote)).'</blockquote>';
        },
        $text
    );
}

// --- EMAIL BUILDING ---
function wns_build_email($summary, $count, $wp_posts = []) {
    $intro = get_option('wns_intro');
    $intro = str_replace('{count}', $count, $intro);
    $include_wp = get_option('wns_include_wp', 1);
    $include_forum = get_option('wns_include_forum', 1);

    $message = "<h1>SARAP Fórum & Novinky</h1><div class='intro'><strong>".esc_html($intro)."</strong></div>";

    // --- If no new posts ---
    if (
        (!$include_wp || empty($wp_posts)) &&
        (!$include_forum || empty($summary))
    ) {
        $message .= '<div class="card" style="text-align:center; padding:2em 1em;">
            <p style="font-size:1.2em; color:#888; margin-bottom:1.2em;">Tento týždeň nie sú žiadne nové príspevky ani články.</p>
            <p>
                <a href="' . esc_url(home_url('/')) . '" style="color:#357ae8; font-weight:600; text-decoration:underline; margin-right:1.5em;">Prejsť na web</a>
                <a href="' . esc_url(site_url('/community/')) . '" style="color:#357ae8; font-weight:600; text-decoration:underline;">Prejsť na fórum</a>
            </p>
            <p style="color:#aaa; font-size:0.98em; margin-top:1.5em;">Môžete si pozrieť staršie články alebo diskusie na našom webe a fóre.</p>
        </div>';
    }

    // --- Otherwise, show normal content ---
    // WordPress posts section
    if ($include_wp && !empty($wp_posts)) {
        // Group posts by first category
        $posts_by_cat = [];
        foreach ($wp_posts as $post) {
            $categories = get_the_category($post->ID);
            $cat_name = (!empty($categories)) ? $categories[0]->name : 'Bez kategórie';
            $cat_slug = (!empty($categories)) ? $categories[0]->slug : 'bez-kategorie';
            if (!isset($posts_by_cat[$cat_slug])) {
                $posts_by_cat[$cat_slug] = [
                    'cat_name' => $cat_name,
                    'posts' => []
                ];
            }
            $posts_by_cat[$cat_slug]['posts'][] = $post;
        }

        $message .= '<section class="wp-posts"><h2 class="section-title">Najnovšie články z webu</h2>';
        foreach ($posts_by_cat as $cat) {
            $message .= '<div class="forum-header"><span class="category-label">'.esc_html($cat['cat_name']).'</span></div>';
            foreach ($cat['posts'] as $post) {
                $url = get_permalink($post->ID);
                $date = get_the_date('d.m.Y', $post->ID);
                $title = esc_html(get_the_title($post->ID));
                $excerpt_raw = wp_trim_words(strip_tags($post->post_content), 40, '...');
                $excerpt = wns_format_quotes($excerpt_raw);

                $message .= "<div class='card post-card'>";
                $message .= "<div class='post-title'><a href='{$url}'>{$title}</a></div>";
                $message .= "<div class='post-meta'>{$date}</div>";
                $message .= "<div class='post-excerpt'>{$excerpt}</div>";
                $message .= "<div class='post-readmore'><a href='{$url}'>Čítať ďalej na webe...</a></div>";
                $message .= "</div>";
            }
        }
        $message .= '</section>';
    }

    // Forum section
    if ($include_forum && !empty($summary)) {
        $message .= '<section class="forum-section"><h2 class="section-title">Najnovšie príspevky z fóra</h2>';
        foreach ($summary as $forum) {
            $message .= '<div class="forum-header"><span class="category-label">Kategória</span><span class="forum-name">'.esc_html($forum['forum_name']).'</span></div>';
            foreach ($forum['threads'] as $thread) {
                $message .= '<div class="thread-header"><span class="topic-label">Téma</span><span class="thread-name">'.esc_html($thread['thread_subject']).'</span></div>';
                foreach ($thread['posts'] as $post) {
                    $url = function_exists('wpforo_topic') ? wpforo_topic($post->topicid, 'url') : site_url("/community/topic/{$post->topicid}");
                    $postdate = date('d.m.Y', strtotime($post->created));
                    $posttime = date('H:i', strtotime($post->created));
                    $author = isset($post->userid) ? esc_html(get_the_author_meta('display_name', $post->userid)) : '';
                    $body = wp_kses_post(wns_format_quotes($post->body));
                    $excerpt_html = wns_truncate_html_words($body, 40, $url);
                    $message .= "<div class='card forum-post'>";
                    $message .= "<div class='post-title'><a href='{$url}'>".esc_html($post->title)."</a></div>";
                    $message .= "<div class='post-meta'>{$author} &middot; {$postdate} {$posttime}</div>";
                    $message .= "<div class='post-excerpt'>{$excerpt_html}</div>";
                    $message .= "<div class='post-readmore'><a href='{$url}'>Čítať ďalej na stránke fóra...</a></div>";
                    $message .= "</div>";
                }
            }
        }
        $message .= '</section>';
    }

    $html_header = "\n<!DOCTYPE html>\n<html>\n<head>\n<title>SARAP Forum & Novinky</title>\n<meta charset='utf-8' />\n<style>
body {background:#f6f8fa; font-family:Segoe UI,Arial,sans-serif; margin:0;}
.content {color: #222; background:#fff; padding: 1.5em 1em; margin: 2em auto; border-radius: 10px; box-shadow:0 2px 12px #0001; max-width: 700px;}
h1 {color:#171f57; border-bottom:2px solid #e5e5e5; padding-bottom:0.2em; font-size:1.7em; margin-bottom:1em;}
.section-title {color:#171f57; font-size:1.45em; margin-top:1.5em; margin-bottom:0.8em; font-weight:700;}
.intro {font: size 1.25em; margin-bottom:1.2em;}
.card {
    background:#f8f9fb;
    border:1px solid #e5e5e5;
    border-radius:7px;
    margin-bottom:1.5em;
    padding:0.9em 1em;
    box-sizing: border-box;
}
.wp-posts, .forum-section {
    margin-bottom:2em;
}
.forum-header, .thread-header {
    display: flex;
    align-items: center;
    margin-top: 1.2em;
    margin-bottom: 0.7em;
}
.category-label {
    color: #fff;
    background: #171f57;
    display: inline-block;
    padding: 0.18em 0.7em;
    border-radius: 6px;
    font-size: 1em;
    font-weight: 600;
    margin-right: 0.6em;
}
.forum-name {
    font-size: 1.10em;
    font-weight: bold;
    color: #171f57;
}
.topic-label {
    color: #fff;
    background: #f7b32b;
    display: inline-block;
    padding: 0.13em 0.6em;
    border-radius: 6px;
    font-size: 0.97em;
    font-weight: 600;
    margin-right: 0.6em;
}
.thread-name {
    font-size: 1.02em;
    font-weight: bold;
    color: #2d2d2d;
}
.post-title {
    font-size: 1.06em;
    font-weight: 600;
    color: #171f57;
    margin-bottom: 0.15em;
}
.post-title a {
    color: #171f57;
    text-decoration: none;
}
.post-meta {
    color: #888;
    font-size: 0.95em;
    margin-bottom: 0.3em;
}
.post-excerpt {
    color: #222;
    font-size: 1em;
    margin-bottom: 0.7em;
    margin-top: 0.1em;
    line-height: 1.5;
}
.forum-quote {
    border-left: 3px solid #f7b32b;
    background: #fffbe7;
    color: #444;
    margin: 0.5em 0 0.7em 0;
    padding: 0.5em 0.8em;
    font-style: italic;
    font-size: 0.97em;
    border-radius: 5px;
}
.post-readmore {
    margin-top: 0.3em;
}
.post-readmore a {
    color: #357ae8;
    font-size: 0.98em;
    text-decoration: underline;
    font-weight: 600;
}
@media (max-width: 600px) { .content {padding:0.5em;} }
</style>\n</head>\n<body>\n<div class='content'>\n";
    $html_footer = "</div></body></html>";
    return $html_header . $message . $html_footer;
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
        $now = new DateTime('now', $timezone);
        $date_to = $now->format('Y-m-d');
        $now->modify('-7 days');
        $date_from = $now->format('Y-m-d');
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
    $email_content = wns_build_email($summary, $count, $wp_posts);
    $subject = get_option('wns_subject');
    // Pridaj dátumový rozsah do predmetu pre unikátne
    if ($date_from && $date_to) {
        $subject .= " (" . date('d.m.Y', strtotime($date_from)) . " - " . date('d.m.Y', strtotime($date_to)) . ")";
    }
    $headers = [
        'Content-Type: text/html; charset=UTF-8',
        'Reply-To: noreply@sarap.sk' 
    ];
    $recipients = wns_get_user_emails();
    $log = '';
    $sent_count = 0;
    foreach ($recipients as $email) {
        $sent = wp_mail($email, $subject, $email_content, $headers);
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
}

// --- SETTINGS PAGE ---
function wns_register_settings() {
    add_option('wns_subject', 'SARAP Fórum - týždenný sumár');
    add_option('wns_intro', 'Aktivita: {count} nových príspevkov za posledný týždeň.');
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
    add_option('wns_from_name', 'Fórum & Novinky'); // new: from name for newsletter only
    register_setting('wns_options_group', 'wns_subject');
    register_setting('wns_options_group', 'wns_intro');
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
}
add_action('admin_init', 'wns_register_settings');

function wns_set_newsletter_from_name($name) {
    $custom_name = get_option('wns_from_name', 'Fórum & Novinky');
    return $custom_name;
}

function wns_settings_page() {
    $date_range_type = get_option('wns_date_range_type', 'week');
    $date_from = get_option('wns_date_from', '');
    $date_to = get_option('wns_date_to', '');
    $send_day = get_option('wns_send_day', 'monday');
    $send_time = get_option('wns_send_time', '08:00');
    $include_forum = get_option('wns_include_forum', 1);
    $include_wp = get_option('wns_include_wp', 1);
    $roles = get_option('wns_roles', ['subscriber', 'administrator']);
    $from_name = get_option('wns_from_name', 'Fórum & Novinky'); // new
    if (!is_array($roles)) {
        $roles = [$roles];
    }
    ?>
    <style>
    .wns-admin-card {
        background: #fff;
        border-radius: 12px;
        box-shadow: 0 2px 16px #0002;
        padding: 2.2em 2em 1.5em 2em;
        max-width: 700px;
        margin: 2em auto 2em auto;
        border: 1px solid #e5e7eb;
    }
    .wns-admin-card h1 {
        font-size: 2em;
        color: #171f57;
        margin-bottom: 1.2em;
        border-bottom: 2px solid #f7b32b;
        padding-bottom: 0.3em;
    }
    .wns-admin-table {
        width: 100%;
        border-collapse: separate;
        border-spacing: 0 1em;
    }
    .wns-admin-table th {
        text-align: left;
        color: #444;
        font-weight: 600;
        width: 220px;
        vertical-align: top;
        padding-top: 0.4em;
    }
    .wns-admin-table td {
        padding-bottom: 0.5em;
    }
    .wns-admin-table input[type="text"],
    .wns-admin-table input[type="date"],
    .wns-admin-table input[type="time"],
    .wns-admin-table select {
        width: 90%;
        padding: 0.4em 0.7em;
        border-radius: 6px;
        border: 1px solid #d1d5db;
        font-size: 1em;
        background: #f9fafb;
        margin-bottom: 0.2em;
    }
    .wns-admin-table input[type="checkbox"] {
        transform: scale(1.2);
        margin-right: 0.5em;
        accent-color: #f7b32b;
    }
    .wns-role-label {
        display: inline-block;
        margin-right: 2em;
        font-size: 1.05em;
        cursor: pointer;
        padding: 0.2em 0.5em;
        border-radius: 5px;
        transition: background 0.2s;
    }
    .wns-role-label:hover {
        background: #f7b32b22;
    }
    .wns-section-title {
        font-size: 1.2em;
        color: #171f57;
        margin-top: 2em;
        margin-bottom: 1em;
        font-weight: 700;
        border-bottom: 1px solid #e5e7eb;
        padding-bottom: 0.2em;
    }
    /* Remove custom .submit button style for settings */
    .wns-admin-card .submit {
        margin-top: 1.2em;
        margin-left: 0;
        border-radius: 6px;
        font-size: 1.1em;
        font-weight: 600;
        /* Remove background and color to use WP default */
        background: none !important;
        color: inherit !important;
        box-shadow: none !important;
        border: none !important;
        padding: 0 !important;
    }
    /* Manual send block matches card width and centering */
    .wns-manual-send {
        background: #fff;
        border-radius: 12px;
        box-shadow: 0 2px 16px #0002;
        padding: 2.2em 2em 1.5em 2em;
        max-width: 700px;
        margin: 2em auto 2em auto;
        border: 1px solid #e5e7eb;
    }
    .wns-manual-send .submit {
        margin-top: 0.5em;
        margin-left: 0;
        border-radius: 6px;
        font-size: 1.1em;
        font-weight: 600;
        background: none !important;
        color: inherit !important;
        box-shadow: none !important;
        border: none !important;
        padding: 0 !important;
        text-align: left;
        display: inline-block;
        transition: background 0.2s;
    }
    /* Manual send button: left align, light style */
    .wns-manual-send .submit:hover {
        background: #ffe2a0 !important;
        color: #171f57 !important;
    }
    </style>
    <div class="wns-admin-card">
        <h1>Weekly Newsletter Sender</h1>
        <form method="post" action="options.php">
            <?php settings_fields('wns_options_group'); ?>
            <table class="wns-admin-table">
                <tr>
                    <th>Enable Newsletter</th>
                    <td><input type="checkbox" name="wns_enabled" value="1" <?php checked(1, get_option('wns_enabled'), true); ?> /> <span style="color:#888;">Enable or disable sending</span></td>
                </tr>
                <tr>
                    <th>From Name (displayed in email)</th>
                    <td>
                        <input type="text" name="wns_from_name" value="<?php echo esc_attr($from_name); ?>" />
                        <div style="color:#888; font-size:0.97em; margin-top:0.2em;">Only for this newsletter, not other site emails.</div>
                    </td>
                </tr>
                <tr>
                    <th>Email Subject</th>
                    <td><input type="text" name="wns_subject" value="<?php echo esc_attr(get_option('wns_subject')); ?>" /></td>
                </tr>
                <tr>
                    <th>Intro Text</th>
                    <td><input type="text" name="wns_intro" value="<?php echo esc_attr(get_option('wns_intro')); ?>" /></td>
                </tr>
                <tr>
                    <th>User Roles to Send To</th>
                    <td>
                        <label class="wns-role-label">
                            <input type="checkbox" name="wns_roles[]" value="subscriber" <?php checked(in_array('subscriber', $roles)); ?>> Subscriber
                        </label>
                        <label class="wns-role-label">
                            <input type="checkbox" name="wns_roles[]" value="administrator" <?php checked(in_array('administrator', $roles)); ?>> Administrator
                        </label>
                        <div style="color:#888; font-size:0.97em; margin-top:0.2em;">Only checked roles will receive the newsletter.</div>
                    </td>
                </tr>
                <tr>
                    <th>Date Range for Newsletter Content</th>
                    <td>
                        <select name="wns_date_range_type" id="wns_date_range_type">
                            <option value="week" <?php selected($date_range_type, 'week'); ?>>Latest this week</option>
                            <option value="custom" <?php selected($date_range_type, 'custom'); ?>>Custom range</option>
                        </select><br>
                        <div id="wns_custom_dates" style="margin-top:8px;<?php if($date_range_type!=='custom') echo 'display:none;'; ?>">
                            From: <input type="date" name="wns_date_from" value="<?php echo esc_attr($date_from); ?>">
                            To: <input type="date" name="wns_date_to" value="<?php echo esc_attr($date_to); ?>">
                        </div>
                        <div style="color:#888; font-size:0.97em; margin-top:0.2em;">This date range applies to both WordPress posts and forum posts in the newsletter.</div>
                        <script>
                        document.getElementById('wns_date_range_type').addEventListener('change', function() {
                            document.getElementById('wns_custom_dates').style.display = this.value === 'custom' ? '' : 'none';
                        });
                        </script>
                    </td>
                </tr>
                <tr>
                    <th>Include Forum Posts</th>
                    <td><input type="checkbox" name="wns_include_forum" value="1" <?php checked(1, $include_forum, true); ?> /> <span style="color:#888;">Include wpForo forum posts</span></td>
                </tr>
                <tr>
                    <th>Include Website Posts</th>
                    <td><input type="checkbox" name="wns_include_wp" value="1" <?php checked(1, $include_wp, true); ?> /> <span style="color:#888;">Include WordPress posts</span></td>
                </tr>
                <tr>
                    <th>Send Day</th>
                    <td>
                        <select name="wns_send_day">
                            <option value="monday" <?php selected($send_day, 'monday'); ?>>Monday</option>
                            <option value="tuesday" <?php selected($send_day, 'tuesday'); ?>>Tuesday</option>
                            <option value="wednesday" <?php selected($send_day, 'wednesday'); ?>>Wednesday</option>
                            <option value="thursday" <?php selected($send_day, 'thursday'); ?>>Thursday</option>
                            <option value="friday" <?php selected($send_day, 'friday'); ?>>Friday</option>
                            <option value="saturday" <?php selected($send_day, 'saturday'); ?>>Saturday</option>
                            <option value="sunday" <?php selected($send_day, 'sunday'); ?>>Sunday</option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th>Send Time</th>
                    <td><input type="time" name="wns_send_time" value="<?php echo esc_attr($send_time); ?>" /></td>
                </tr>
                <tr>
                    <th>Next Scheduled Send</th>
                    <td>
                        <?php
                        $send_day = get_option('wns_send_day', 'monday');
                        $send_time = get_option('wns_send_time', '08:00');
                        $timezone = wp_timezone();
                        $next = new DateTime('now', $timezone);
                        $target = new DateTime('this ' . $send_day . ' ' . $send_time, $timezone);
                        if ($target < $next) {
                            $target->modify('+1 week');
                        }
                        echo esc_html($target->format('l, d.m.Y H:i')) . ' (' . esc_html($timezone->getName()) . ')';
                        ?>
                    </td>
                </tr>
            </table>
            <div style="text-align:left;">
                <?php submit_button('Save Settings', 'primary large'); ?>
            </div>
        </form>
    </div>
    <div class="wns-manual-send">
        <div class="wns-section-title">Manual Send</div>
        <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
            <input type="hidden" name="action" value="wns_manual_send" />
            <div style="text-align:left;">
                <?php submit_button('Send Newsletter Now', 'secondary'); ?>
            </div>
        </form>
    </div>
    <?php
}

// Manual send handler - only trigger when button is clicked and on correct admin page
add_action('admin_post_wns_manual_send', function() {
    if (current_user_can('manage_options')) {
        wns_send_newsletter(true);
        wp_redirect(add_query_arg('wns_sent', '1', wp_get_referer()));
        exit;
    }
});

// Show notification if sent manually
add_action('admin_notices', function() {
    if (isset($_GET['wns_sent']) && $_GET['wns_sent'] == '1') {
        echo '<div class="updated"><p>Newsletter sent manually!</p></div>';
    }
});

// --- ADMIN MENU: Top-level menu for Newsletter ---
add_action('admin_menu', function() {
    // Top-level menu
    add_menu_page(
        'Weekly Newsletter', // Page title
        'Newsletter',        // Menu title
        'manage_options',    // Capability
        'wns-main',         // Menu slug
        'wns_settings_page',// Callback for main page
        'dashicons-email',  // Icon
        25                  // Position
    );
    // Settings submenu (redundant, but for clarity)
    add_submenu_page(
        'wns-main',
        'Newsletter Settings',
        'Settings',
        'manage_options',
        'wns-main',
        'wns_settings_page'
    );
    // Preview submenu
    add_submenu_page(
        'wns-main',
        'Newsletter Preview',
        'Preview',
        'manage_options',
        'wns-preview',
        function() {
            // Use the same settings as the main send function
            $type = get_option('wns_date_range_type', 'week');
            $timezone = wp_timezone();
            $date_from = null;
            $date_to = null;
            if ($type === 'week') {
                $now = new DateTime('now', $timezone);
                $date_to = $now->format('Y-m-d');
                $now->modify('-7 days');
                $date_from = $now->format('Y-m-d');
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
            $email_content = wns_build_email($summary, $count, $wp_posts);

            // Wider, email-like preview
            echo '<div class="wrap" style="max-width:none;">';
            echo '<h1 style="margin-bottom:1.5em;">Newsletter Preview</h1>';
            echo '<div style="
                background: #f6f8fa;
                padding: 40px 0;
                min-height: 100vh;
                ">
                <div style="
                    margin: 0 auto;
                    max-width: 820px;
                    background: #fff;
                    border-radius: 12px;
                    box-shadow: 0 2px 24px #0002;
                    padding: 0;
                    ">
                    ' . $email_content . '
                </div>
            </div>';
            echo '</div>';
        }
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
        $target = new DateTime('this ' . $send_day . ' ' . $send_time, $timezone);
        if ($target < $next) {
            $target->modify('+1 week');
        }
        $time = $target->getTimestamp();
        if (!wp_next_scheduled($hook)) {
            // Always schedule as 'weekly'
            wp_schedule_event($time, 'weekly', $hook);
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


