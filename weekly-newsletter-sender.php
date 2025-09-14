<?php
/**
 * Plugin Name: Weekly Newsletter Sender
 * Description: Sends a weekly newsletter with WordPress posts and wpForo forum posts to all users.
 * Version: 2.0
 * Author: Marep
 * Author URI: https://marep.sk
 */

if (!defined('ABSPATH')) exit;

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
            return '<blockquote class="forum-quote">'.nl2br(esc_html($quote)).'</blockquote>';
        },
        $text
    );
}

// --- SHARED EMAIL CSS GENERATION ---
function wns_generate_email_styles() {
    // Get design settings
    $header_color = get_option('wns_email_header_color', '#2c5aa0');
    $accent_color = get_option('wns_email_accent_color', '#0073aa');
    $text_color = get_option('wns_email_text_color', '#333333');
    $bg_color = get_option('wns_email_background_color', '#f9f9f9');
    $font_family = get_option('wns_email_font_family', 'Arial, sans-serif');
    $card_bg = get_option('wns_email_card_bg_color', '#f8f8f8');
    $card_border = get_option('wns_email_card_border_color', '#e5e5e5');
    $content_padding = get_option('wns_email_content_padding', '20');
    $card_radius = get_option('wns_email_card_radius', '7');
    $line_height = get_option('wns_email_line_height', '1.5');
    $meta_color = get_option('wns_email_meta_color', '#888888');
    
    return "
body {background: {$bg_color}; font-family: {$font_family}; margin:0; padding:20px; color: {$text_color}; line-height: {$line_height};}
.content {color: {$text_color}; background:#fff; padding: 0; margin: 0 auto; border-radius: {$card_radius}px; overflow: hidden; max-width: 700px; box-shadow: 0 8px 32px rgba(0,0,0,0.2);}
.email-header {background: {$header_color}; color: #fff; text-align: center; padding: {$content_padding}px;}
.email-header h1 {margin: 0 !important; padding: 0; border: none;}
h1 {color: {$text_color}; border-bottom:2px solid {$card_border}; padding-bottom:0.2em; font-size:1.7em; margin-bottom:1em;}
.section-title {color: {$text_color}; font-size:1.45em; margin-top:1.5em; margin-bottom:0.8em; font-weight:700;}
.intro {font-size: 1.25em; margin: 1.5em {$content_padding}px 1.2em; color: {$text_color};}
.card {
    background: {$card_bg};
    border: 1px solid {$card_border};
    border-radius: {$card_radius}px;
    margin: 0 {$content_padding}px 1.5em;
    padding: 0.9em 1em;
    box-sizing: border-box;
}
.wp-posts, .forum-section {
    margin-bottom: 2em;
    padding: 0 {$content_padding}px;
}
.forum-header, .thread-header {
    display: flex;
    align-items: center;
    margin-top: 1.2em;
    margin-bottom: 0.7em;
}
.category-label {
    color: #fff;
    background: {$header_color};
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
    color: {$text_color};
}
.topic-label {
    color: #fff;
    background: {$accent_color};
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
    color: {$text_color};
}
.post-title {
    font-size: 1.06em;
    font-weight: 600;
    color: {$text_color};
    margin-bottom: 0.15em;
}
.post-title a {
    color: {$text_color} !important;
    text-decoration: none !important;
    display: block;
    font-weight: inherit;
    font-size: inherit;
}
.post-title a:hover {
    color: {$accent_color} !important;
    text-decoration: none !important;
}
.post-meta {
    color: {$meta_color};
    font-size: 0.95em;
    margin-bottom: 0.3em;
}
.post-excerpt {
    color: {$text_color};
    font-size: 1em;
    margin-bottom: 0.7em;
    margin-top: 0.1em;
    line-height: {$line_height};
}
.forum-quote {
    border-left: 3px solid {$accent_color};
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
    color: {$accent_color};
    font-size: 0.98em;
    text-decoration: underline;
    font-weight: 600;
}
.email-footer {
    background: #f5f5f5;
    color: #666;
    padding: 15px {$content_padding}px;
    font-size: 12px;
    text-align: center;
    border-top: 1px solid #e0e0e0;
    margin-top: 20px;
}
@media (max-width: 600px) { .content {margin: 1em;} }
";
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
        $meta_color = get_option('wns_email_meta_color', '#888888');
        $message .= '<div class="card" style="text-align:center; padding:2em 1em;">
            <p style="font-size:1.2em; color:'.$meta_color.'; margin-bottom:1.2em;">No new posts or articles this week.</p>
            <p>
                <a href="' . esc_url(home_url('/')) . '" style="color:'.$accent_color.'; font-weight:600; text-decoration:underline; margin-right:1.5em;">Visit Website</a>
                <a href="' . esc_url(site_url('/community/')) . '" style="color:'.$accent_color.'; font-weight:600; text-decoration:underline;">Visit Forum</a>
            </p>
            <p style="color:'.$meta_color.'; font-size:0.98em; margin-top:1.5em;">You can browse older articles or discussions on our website and forum.</p>
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

        $message .= '<section class="wp-posts"><h2 class="section-title">Latest Articles from Website</h2>';
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
                $message .= "<div class='post-readmore'><a href='{$url}'>Read more on website...</a></div>";
                $message .= "</div>";
            }
        }
        $message .= '</section>';
    }

    // Forum section
    if ($include_forum && !empty($summary)) {
        $message .= '<section class="forum-section"><h2 class="section-title">Latest Forum Posts</h2>';
        foreach ($summary as $forum) {
            $message .= '<div class="forum-header"><span class="category-label">Forum</span><span class="forum-name">'.esc_html($forum['forum_name']).'</span></div>';
            foreach ($forum['threads'] as $thread) {
                $message .= '<div class="thread-header"><span class="topic-label">Topic</span><span class="thread-name">'.esc_html($thread['thread_subject']).'</span></div>';
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
                    $message .= "<div class='post-readmore'><a href='{$url}'>Read more on forum...</a></div>";
                    $message .= "</div>";
                }
            }
        }
        $message .= '</section>';
    }

    return $message;
}

// --- EMAIL BUILDING ---
function wns_build_email($summary, $count, $wp_posts = []) {
    // Use design intro text only - no fallback to old settings
    $intro = get_option('wns_email_intro_text', 'This week we have prepared {count} new posts and articles for you.');
    $intro = str_replace('{count}', $count, $intro);
    $include_wp = get_option('wns_include_wp', 1);
    $include_forum = get_option('wns_include_forum', 1);
    
    // Get design settings
    $header_color = get_option('wns_email_header_color', '#2c5aa0');
    $accent_color = get_option('wns_email_accent_color', '#0073aa');
    $text_color = get_option('wns_email_text_color', '#333333');
    $bg_color = get_option('wns_email_background_color', '#f9f9f9');
    $font_family = get_option('wns_email_font_family', 'Arial, sans-serif');
    $logo_url = get_option('wns_email_logo_url', '');
    $footer_text = get_option('wns_email_footer_text', '');
    $card_bg = get_option('wns_email_card_bg_color', '#f8f8f8');
    $card_border = get_option('wns_email_card_border_color', '#e5e5e5');
    $header_text_size = get_option('wns_email_header_text_size', '28');
    $content_padding = get_option('wns_email_content_padding', '20');
    $card_radius = get_option('wns_email_card_radius', '7');
    $line_height = get_option('wns_email_line_height', '1.5');
    $meta_color = get_option('wns_email_meta_color', '#888888');
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

    $message = "<div class='email-header'>{$header_content}</div><div class='intro'><strong>".esc_html($intro)."</strong></div>";

    // --- If no new posts ---
    if (
        (!$include_wp || empty($wp_posts)) &&
        (!$include_forum || empty($summary))
    ) {
        $message .= '<div class="card" style="text-align:center; padding:2em 1em;">
            <p style="font-size:1.2em; color:'.$meta_color.'; margin-bottom:1.2em;">No new posts or articles this week.</p>
            <p>
                <a href="' . esc_url(home_url('/')) . '" style="color:'.$accent_color.'; font-weight:600; text-decoration:underline; margin-right:1.5em;">Visit Website</a>
                <a href="' . esc_url(site_url('/community/')) . '" style="color:'.$accent_color.'; font-weight:600; text-decoration:underline;">Visit Forum</a>
            </p>
            <p style="color:'.$meta_color.'; font-size:0.98em; margin-top:1.5em;">You can browse older articles or discussions on our website and forum.</p>
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

        $message .= '<section class="wp-posts"><h2 class="section-title">Latest Articles from Website</h2>';
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
                $message .= "<div class='post-readmore'><a href='{$url}'>Read more on website...</a></div>";
                $message .= "</div>";
            }
        }
        $message .= '</section>';
    }

    // Forum section
    if ($include_forum && !empty($summary)) {
        $message .= '<section class="forum-section"><h2 class="section-title">Latest Forum Posts</h2>';
        foreach ($summary as $forum) {
            $message .= '<div class="forum-header"><span class="category-label">Forum</span><span class="forum-name">'.esc_html($forum['forum_name']).'</span></div>';
            foreach ($forum['threads'] as $thread) {
                $message .= '<div class="thread-header"><span class="topic-label">Topic</span><span class="thread-name">'.esc_html($thread['thread_subject']).'</span></div>';
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
                    $message .= "<div class='post-readmore'><a href='{$url}'>Read more on forum...</a></div>";
                    $message .= "</div>";
                }
            }
        }
        $message .= '</section>';
    }

    $html_header = "\n<!DOCTYPE html>\n<html>\n<head>\n<title>Weekly Newsletter</title>\n<meta charset='utf-8' />\n<style>
" . wns_generate_email_styles() . "
</style>\n</head>\n<body>\n<div class='content'>\n";
    // Add footer if configured
    if (!empty($footer_text)) {
        $message .= '<div class="email-footer">' . nl2br(esc_html($footer_text)) . '</div>';
    }
    
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
    $intro = get_option('wns_email_intro_text', 'This week we have prepared {count} new posts and articles for you.');
    $count = count($demo_data['wp_posts']) + count($demo_data['forum_summary'][0]['threads'][0]['posts']) + count($demo_data['forum_summary'][1]['threads'][0]['posts']);
    $intro = str_replace('{count}', $count, $intro);
    
    // Get design settings
    $header_color = get_option('wns_email_header_color', '#2c5aa0');
    $accent_color = get_option('wns_email_accent_color', '#0073aa');
    $text_color = get_option('wns_email_text_color', '#333333');
    $bg_color = get_option('wns_email_background_color', '#f9f9f9');
    $font_family = get_option('wns_email_font_family', 'Arial, sans-serif');
    $logo_url = get_option('wns_email_logo_url', '');
    $footer_text = get_option('wns_email_footer_text', '');
    $card_bg = get_option('wns_email_card_bg_color', '#f8f8f8');
    $card_border = get_option('wns_email_card_border_color', '#e5e5e5');
    $header_text_size = get_option('wns_email_header_text_size', '28');
    $content_padding = get_option('wns_email_content_padding', '20');
    $card_radius = get_option('wns_email_card_radius', '7');
    $line_height = get_option('wns_email_line_height', '1.5');
    $meta_color = get_option('wns_email_meta_color', '#888888');
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

    $message = "<div class='email-header'>{$header_content}</div><div class='intro'><strong>".esc_html($intro)."</strong></div>";

    // WordPress posts section with demo data
    $message .= '<section class="wp-posts"><h2 class="section-title">Latest Articles from Website</h2>';
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
        $message .= '<div class="forum-header"><span class="category-label">'.esc_html($cat['cat_name']).'</span></div>';
        foreach ($cat['posts'] as $post) {
            $url = wns_get_demo_post_permalink($post->ID);
            $date = date('M j, Y', strtotime($post->post_date));
            $title = esc_html($post->post_title);
            $excerpt_raw = wp_trim_words(strip_tags($post->post_content), 40, '...');
            $excerpt = wns_format_quotes($excerpt_raw);

            $message .= "<div class='card post-card'>";
            $message .= "<div class='post-title'><a href='{$url}'>{$title}</a></div>";
            $message .= "<div class='post-meta'>{$date}</div>";
            $message .= "<div class='post-excerpt'>{$excerpt}</div>";
            $message .= "<div class='post-readmore'><a href='{$url}'>Read more on website...</a></div>";
            $message .= "</div>";
        }
    }
    $message .= '</section>';

    // Forum section with demo data
    $message .= '<section class="forum-section"><h2 class="section-title">Latest Forum Posts</h2>';
    foreach ($demo_data['forum_summary'] as $forum) {
        $message .= '<div class="forum-header"><span class="category-label">Category</span><span class="forum-name">'.esc_html($forum['forum_name']).'</span></div>';
        foreach ($forum['threads'] as $thread) {
            $message .= '<div class="thread-header"><span class="topic-label">Topic</span><span class="thread-name">'.esc_html($thread['thread_subject']).'</span></div>';
            foreach ($thread['posts'] as $post) {
                $url = site_url("/community/topic/{$post->topicid}");
                $postdate = date('M j, Y', strtotime($post->created));
                $posttime = date('H:i', strtotime($post->created));
                $author = wns_get_demo_author_name($post->userid);
                $body = wp_kses_post(wns_format_quotes($post->body));
                $excerpt_html = wns_truncate_html_words($body, 40, $url);
                $message .= "<div class='card forum-post'>";
                $message .= "<div class='post-title'><a href='{$url}'>".esc_html($post->title)."</a></div>";
                $message .= "<div class='post-meta'>{$author} &middot; {$postdate} {$posttime}</div>";
                $message .= "<div class='post-excerpt'>{$excerpt_html}</div>";
                $message .= "<div class='post-readmore'><a href='{$url}'>Continue reading on forum...</a></div>";
                $message .= "</div>";
            }
        }
    }
    $message .= '</section>';

    // Add footer if configured
    if (!empty($footer_text)) {
        $message .= '<div class="email-footer">' . nl2br(esc_html($footer_text)) . '</div>';
    }

    $html_header = "<!DOCTYPE html>\n<html>\n<head>\n<title>Weekly Newsletter</title>\n<meta charset='utf-8' />\n<style>
" . wns_generate_email_styles() . "
</style>\n</head>\n<body>\n<div class='content'>\n";
    
    $html_footer = "</div></body></html>";
    return $html_header . $message . $html_footer;
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

// --- SETTINGS PAGE ---
function wns_register_settings() {
    add_option('wns_subject', 'SARAP Fórum - týždenný sumár');
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
    
    // Email design settings
    register_setting('wns_design_group', 'wns_email_header_color');
    register_setting('wns_design_group', 'wns_email_accent_color');
    register_setting('wns_design_group', 'wns_email_text_color');
    register_setting('wns_design_group', 'wns_email_background_color');
    register_setting('wns_design_group', 'wns_email_font_family');
    register_setting('wns_design_group', 'wns_email_logo_url');
    register_setting('wns_design_group', 'wns_email_footer_text');
    register_setting('wns_design_group', 'wns_email_card_bg_color');
    register_setting('wns_design_group', 'wns_email_card_border_color');
    register_setting('wns_design_group', 'wns_email_header_text_size');
    register_setting('wns_design_group', 'wns_email_content_padding');
    register_setting('wns_design_group', 'wns_email_card_radius');
    register_setting('wns_design_group', 'wns_email_line_height');
    register_setting('wns_design_group', 'wns_email_meta_color');
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
    $custom_name = get_option('wns_from_name', 'Fórum & Novinky');
    return $custom_name;
}

function wns_settings_page() {
    $current_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'settings';
    
    // Handle notifications
    $notification = '';
    $notification_type = '';
    if (isset($_GET['wns_sent']) && $_GET['wns_sent'] == '1') {
        $notification = 'Newsletter sent manually!';
        $notification_type = 'success';
    }
    if (isset($_GET['test_sent']) && $_GET['test_sent'] == '1') {
        $notification = 'Test email sent successfully!';
        $notification_type = 'success';
    }
    if (isset($_GET['test_failed']) && $_GET['test_failed'] == '1') {
        $notification = 'Test email failed to send. Please check your configuration.';
        $notification_type = 'error';
    }
    
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
    /* Paper-style grey, white, black admin UI */
    .wns-admin-wrapper {
        background: #fefefe;
        min-height: 100vh;
        margin: -20px -20px 0 -2px; /* Expand beyond WordPress admin margins */
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
        background: #f5f5f5;
        border-radius: 8px;
        padding: 20px;
        min-width: 700px;
        margin-left: auto;
    }
    
    .wns-email-preview {
        background: white;
        border-radius: 8px;
        box-shadow: 0 4px 20px rgba(0,0,0,0.15);
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
            <h1>Weekly Newsletter Sender</h1>
            <div class="wns-tab-nav">
                <a href="<?php echo admin_url('admin.php?page=wns-main&tab=settings'); ?>" 
                   class="wns-tab-button <?php echo $current_tab === 'settings' ? 'active' : ''; ?>">
                    Settings
                </a>
                <a href="<?php echo admin_url('admin.php?page=wns-main&tab=configuration'); ?>" 
                   class="wns-tab-button <?php echo $current_tab === 'configuration' ? 'active' : ''; ?>">
                    Configuration
                </a>
                <a href="<?php echo admin_url('admin.php?page=wns-main&tab=design'); ?>" 
                   class="wns-tab-button <?php echo $current_tab === 'design' ? 'active' : ''; ?>">
                    Email Design
                </a>
            </div>
        </div>
        
        <?php if (!empty($notification)): ?>
            <div class="wns-notification <?php echo esc_attr($notification_type); ?> auto-hide">
                <span class="wns-notification-icon">
                    <?php if ($notification_type === 'success'): ?>
                        ✓
                    <?php elseif ($notification_type === 'error'): ?>
                        ⚠
                    <?php endif; ?>
                </span>
                <span class="wns-notification-message"><?php echo esc_html($notification); ?></span>
            </div>
        <?php endif; ?>
        
        <div class="wns-admin-content">
            
            <!-- Settings Tab -->
            <div class="wns-tab-content <?php echo $current_tab === 'settings' ? 'active' : ''; ?>">
                <div class="wns-section">
                    <form method="post" action="options.php">
                        <?php settings_fields('wns_options_group'); ?>
                        
                        <div class="wns-form-row">
                            <label class="wns-form-label">Enable Newsletter</label>
                            <label class="wns-checkbox-label">
                                <input type="checkbox" name="wns_enabled" value="1" class="wns-checkbox-input" <?php checked(1, get_option('wns_enabled'), true); ?> />
                                Enable or disable sending
                            </label>
                        </div>
                        
                        <div class="wns-form-row">
                            <label class="wns-form-label">From Name (displayed in email)</label>
                            <input type="text" name="wns_from_name" value="<?php echo esc_attr($from_name); ?>" class="wns-form-input" />
                            <div class="wns-form-help">Only for this newsletter, not other site emails.</div>
                        </div>
                        
                        <div class="wns-form-row">
                            <label class="wns-form-label">Email Subject</label>
                            <input type="text" name="wns_subject" value="<?php echo esc_attr(get_option('wns_subject')); ?>" class="wns-form-input" />
                        </div>
                        
                        <div class="wns-form-row">
                            <label class="wns-form-label">User Roles to Send To</label>
                            <div class="wns-checkbox-group">
                                <label class="wns-checkbox-label">
                                    <input type="checkbox" name="wns_roles[]" value="subscriber" class="wns-checkbox-input" <?php checked(in_array('subscriber', $roles)); ?>>
                                    Subscriber
                                </label>
                                <label class="wns-checkbox-label">
                                    <input type="checkbox" name="wns_roles[]" value="administrator" class="wns-checkbox-input" <?php checked(in_array('administrator', $roles)); ?>>
                                    Administrator
                                </label>
                            </div>
                            <div class="wns-form-help">Only checked roles will receive the newsletter.</div>
                        </div>
                        
                        <div class="wns-form-row">
                            <label class="wns-form-label">Date Range for Newsletter Content</label>
                            <select name="wns_date_range_type" id="wns_date_range_type" class="wns-form-select">
                                <option value="week" <?php selected($date_range_type, 'week'); ?>>Latest this week</option>
                                <option value="custom" <?php selected($date_range_type, 'custom'); ?>>Custom range</option>
                            </select>
                            <div id="wns_custom_dates" class="wns-date-inputs" style="<?php if($date_range_type!=='custom') echo 'display:none;'; ?>">
                                <label>From:</label>
                                <input type="date" name="wns_date_from" value="<?php echo esc_attr($date_from); ?>" class="wns-form-input">
                                <label>To:</label>
                                <input type="date" name="wns_date_to" value="<?php echo esc_attr($date_to); ?>" class="wns-form-input">
                            </div>
                            <div class="wns-form-help">This date range applies to both WordPress posts and forum posts in the newsletter.</div>
                            <script>
                            document.getElementById('wns_date_range_type').addEventListener('change', function() {
                                document.getElementById('wns_custom_dates').style.display = this.value === 'custom' ? '' : 'none';
                            });
                            </script>
                        </div>
                        
                        <div class="wns-form-row">
                            <label class="wns-form-label">Include Forum Posts</label>
                            <label class="wns-checkbox-label">
                                <input type="checkbox" name="wns_include_forum" value="1" class="wns-checkbox-input" <?php checked(1, $include_forum, true); ?> />
                                Include wpForo forum posts
                            </label>
                        </div>
                        
                        <div class="wns-form-row">
                            <label class="wns-form-label">Include Website Posts</label>
                            <label class="wns-checkbox-label">
                                <input type="checkbox" name="wns_include_wp" value="1" class="wns-checkbox-input" <?php checked(1, $include_wp, true); ?> />
                                Include WordPress posts
                            </label>
                        </div>
                        
                        <div class="wns-form-row">
                            <label class="wns-form-label">Send Day</label>
                            <select name="wns_send_day" class="wns-form-select">
                                <option value="monday" <?php selected($send_day, 'monday'); ?>>Monday</option>
                                <option value="tuesday" <?php selected($send_day, 'tuesday'); ?>>Tuesday</option>
                                <option value="wednesday" <?php selected($send_day, 'wednesday'); ?>>Wednesday</option>
                                <option value="thursday" <?php selected($send_day, 'thursday'); ?>>Thursday</option>
                                <option value="friday" <?php selected($send_day, 'friday'); ?>>Friday</option>
                                <option value="saturday" <?php selected($send_day, 'saturday'); ?>>Saturday</option>
                                <option value="sunday" <?php selected($send_day, 'sunday'); ?>>Sunday</option>
                            </select>
                        </div>
                        
                        <div class="wns-form-row">
                            <label class="wns-form-label">Send Time</label>
                            <input type="time" name="wns_send_time" value="<?php echo esc_attr($send_time); ?>" class="wns-form-input" />
                        </div>
                        
                        <div class="wns-form-row">
                            <label class="wns-form-label">Next Scheduled Send</label>
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
                                    echo 'Error calculating next send time. Please check your send day and time settings.';
                                }
                                ?>
                            </div>
                        </div>
                        
                        <?php submit_button('Save Settings', 'primary large wns-button'); ?>
                    </form>
                </div>
                
                <div class="wns-divider"></div>
                
                <div class="wns-section">
                    <div class="wns-section-title">Manual Send</div>
                    <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
                        <input type="hidden" name="action" value="wns_manual_send" />
                        <?php submit_button('Send Newsletter Now', 'secondary large wns-button wns-button-secondary'); ?>
                    </form>
                </div>
            </div>
            
            <!-- Email Design Tab -->
            <div class="wns-tab-content <?php echo $current_tab === 'design' ? 'active' : ''; ?>">
                <?php if ($current_tab === 'preview'): ?>
                    <div class="wns-section">
                        <div class="wns-section-title">Live Newsletter Preview</div>
                        <div class="wns-info-text">
                            This preview shows exactly what recipients will receive in their email, using your current Email Design settings and the latest content from your website.
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
                                    Loading preview...
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <script>
                    document.addEventListener('DOMContentLoaded', function() {
                        // Load actual email content with current design settings
                        loadEmailPreview();
                        
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
                            
                            // Get current design settings
                            var designSettings = {
                                headerTitle: '<?php echo esc_js(get_option('wns_email_header_title', 'Weekly Newsletter')); ?>',
                                headerSubtitle: '<?php echo esc_js(get_option('wns_email_header_subtitle', '')); ?>',
                                logoUrl: '<?php echo esc_js(get_option('wns_email_logo_url', '')); ?>',
                                introText: '<?php echo esc_js(get_option('wns_email_intro_text', 'This week we have prepared {count} new posts and articles for you.')); ?>',
                                headerColor: '<?php echo esc_js(get_option('wns_email_header_color', '#2c5aa0')); ?>',
                                headerTextSize: '<?php echo esc_js(get_option('wns_email_header_text_size', '28')); ?>',
                                accentColor: '<?php echo esc_js(get_option('wns_email_accent_color', '#0073aa')); ?>',
                                textColor: '<?php echo esc_js(get_option('wns_email_text_color', '#333333')); ?>',
                                metaColor: '<?php echo esc_js(get_option('wns_email_meta_color', '#888888')); ?>',
                                backgroundColor: '<?php echo esc_js(get_option('wns_email_background_color', '#f9f9f9')); ?>',
                                cardBgColor: '<?php echo esc_js(get_option('wns_email_card_bg_color', '#f8f8f8')); ?>',
                                cardBorderColor: '<?php echo esc_js(get_option('wns_email_card_border_color', '#e5e5e5')); ?>',
                                contentPadding: '<?php echo esc_js(get_option('wns_email_content_padding', '20')); ?>',
                                cardRadius: '<?php echo esc_js(get_option('wns_email_card_radius', '7')); ?>',
                                lineHeight: '<?php echo esc_js(get_option('wns_email_line_height', '1.5')); ?>',
                                fontFamily: '<?php echo esc_js(get_option('wns_email_font_family', 'Arial, sans-serif')); ?>',
                                footerText: '<?php echo esc_js(get_option('wns_email_footer_text', '')); ?>'
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
                                echo esc_js(wns_build_preview_content_only($summary, $count, $wp_posts));
                            ?>';
                            
                            // Build intro
                            var introContent = settings.introText.replace('{count}', '<?php
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
                                echo $count;
                            ?>');
                            
                            // Footer
                            var footerHTML = settings.footerText 
                                ? '<div class="email-footer">' + settings.footerText.replace(/\n/g, '<br>') + '</div>'
                                : '';
                            
                            return `<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
body {background: ${settings.backgroundColor}; font-family: ${settings.fontFamily}; margin:0; color: ${settings.textColor}; line-height: ${settings.lineHeight};}
.content {color: ${settings.textColor}; background:#fff; padding: 0; margin: 0; border-radius: ${settings.cardRadius}px; overflow: hidden; max-width: 700px;}
.email-header {background: ${settings.headerColor}; color: #fff; text-align: center; padding: ${settings.contentPadding}px;}
.email-header h1 {margin: 0 !important; padding: 0; border: none;}
h1 {color: ${settings.textColor}; border-bottom:2px solid ${settings.cardBorderColor}; padding-bottom:0.2em; font-size:1.7em; margin-bottom:1em;}
.section-title {color: ${settings.textColor}; font-size:1.45em; margin-top:1.5em; margin-bottom:0.8em; font-weight:700;}
.intro {font-size: 1.25em; margin: 1.5em ${settings.contentPadding}px 1.2em; color: ${settings.textColor};}
.card {background: ${settings.cardBgColor}; border: 1px solid ${settings.cardBorderColor}; border-radius: ${settings.cardRadius}px; margin: 0 ${settings.contentPadding}px 1.5em; padding: 0.9em 1em; box-sizing: border-box;}
.wp-posts, .forum-section {margin-bottom: 2em; padding: 0 ${settings.contentPadding}px;}
.forum-header, .thread-header {display: flex; align-items: center; margin-top: 1.2em; margin-bottom: 0.7em;}
.category-label {color: #fff; background: ${settings.headerColor}; display: inline-block; padding: 0.18em 0.7em; border-radius: 6px; font-size: 1em; font-weight: 600; margin-right: 0.6em;}
.forum-name {font-size: 1.10em; font-weight: bold; color: ${settings.textColor};}
.topic-label {color: #fff; background: ${settings.accentColor}; display: inline-block; padding: 0.13em 0.6em; border-radius: 6px; font-size: 0.97em; font-weight: 600; margin-right: 0.6em;}
.thread-name {font-size: 1.02em; font-weight: bold; color: ${settings.textColor};}
.post-title {font-size: 1.06em; font-weight: 600; color: ${settings.textColor}; margin-bottom: 0.15em;}
.post-title a {color: ${settings.textColor}; text-decoration: none;}
.post-meta {color: ${settings.metaColor}; font-size: 0.95em; margin-bottom: 0.3em;}
.post-excerpt {color: ${settings.textColor}; font-size: 1em; margin-bottom: 0.7em; margin-top: 0.1em; line-height: ${settings.lineHeight};}
.forum-quote {border-left: 3px solid ${settings.accentColor}; background: #fffbe7; color: #444; margin: 0.5em 0 0.7em 0; padding: 0.5em 0.8em; font-style: italic; font-size: 0.97em; border-radius: 5px;}
.post-readmore {margin-top: 0.3em;}
.post-readmore a {color: ${settings.accentColor}; font-size: 0.98em; text-decoration: underline; font-weight: 600;}
.email-footer {background: #f5f5f5; color: #666; padding: 15px ${settings.contentPadding}px; font-size: 12px; text-align: center; border-top: 1px solid #e0e0e0; margin-top: 20px;}
@media (max-width: 600px) { .content {margin: 1em;} }
</style>
</head>
<body>
<div class='content'>
<div class='email-header'>${headerContent}</div>
<div class='intro'><strong>${introContent}</strong></div>
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
                            <div class="wns-section-title">Email Design Customization</div>
                            
                            <form method="post" action="options.php" id="design-form">
                                <?php settings_fields('wns_design_group'); ?>
                                
                                <div class="wns-form-row">
                                    <label class="wns-form-label">Header Title</label>
                                    <input type="text" name="wns_email_header_title" id="header-title" value="<?php echo esc_attr(get_option('wns_email_header_title', 'Weekly Newsletter')); ?>" class="wns-form-input" placeholder="Weekly Newsletter" />
                                    <div class="wns-form-help">Main title text for the email header.</div>
                                </div>
                                
                                <div class="wns-form-row">
                                    <label class="wns-form-label">Header Subtitle</label>
                                    <input type="text" name="wns_email_header_subtitle" id="header-subtitle" value="<?php echo esc_attr(get_option('wns_email_header_subtitle', '')); ?>" class="wns-form-input" placeholder="Your weekly digest" />
                                    <div class="wns-form-help">Optional subtitle text below the main title.</div>
                                </div>
                                
                                <div class="wns-form-row">
                                    <label class="wns-form-label">Logo URL</label>
                                    <input type="url" name="wns_email_logo_url" id="logo-url" value="<?php echo esc_attr(get_option('wns_email_logo_url', '')); ?>" class="wns-form-input" placeholder="https://example.com/logo.png" />
                                    <div class="wns-form-help">URL to your logo image. Logo will replace header text if provided.</div>
                                </div>
                                
                                <div class="wns-form-row">
                                    <label class="wns-form-label">Header Color</label>
                                    <input type="color" name="wns_email_header_color" id="header-color" value="<?php echo esc_attr(get_option('wns_email_header_color', '#2c5aa0')); ?>" class="wns-form-input" />
                                    <div class="wns-form-help">Background color for the newsletter header.</div>
                                </div>
                                
                                <div class="wns-form-row">
                                    <label class="wns-form-label">Intro Text</label>
                                    <textarea name="wns_email_intro_text" id="intro-text" class="wns-form-input" style="min-height: 80px; resize: vertical;" rows="3" placeholder="This week we have prepared {count} new posts and articles for you."><?php echo esc_textarea(get_option('wns_email_intro_text', 'This week we have prepared {count} new posts and articles for you.')); ?></textarea>
                                    <div class="wns-form-help">Introduction text for your newsletter. Use {count} to show the number of posts.</div>
                                </div>
                                
                                <div class="wns-form-row">
                                    <label class="wns-form-label">Header Text Size</label>
                                    <input type="range" name="wns_email_header_text_size" id="header-text-size" value="<?php echo esc_attr(get_option('wns_email_header_text_size', '28')); ?>" min="16" max="48" class="wns-form-range" />
                                    <span id="header-text-size-value"><?php echo get_option('wns_email_header_text_size', '28'); ?>px</span>
                                    <div class="wns-form-help">Size of the header text.</div>
                                </div>
                                
                                <div class="wns-form-row">
                                    <label class="wns-form-label">Accent Color</label>
                                    <input type="color" name="wns_email_accent_color" id="accent-color" value="<?php echo esc_attr(get_option('wns_email_accent_color', '#0073aa')); ?>" class="wns-form-input" />
                                    <div class="wns-form-help">Color for links and highlights.</div>
                                </div>
                                
                                <div class="wns-form-row">
                                    <label class="wns-form-label">Text Color</label>
                                    <input type="color" name="wns_email_text_color" id="text-color" value="<?php echo esc_attr(get_option('wns_email_text_color', '#333333')); ?>" class="wns-form-input" />
                                    <div class="wns-form-help">Main text color for the email content.</div>
                                </div>
                                
                                <div class="wns-form-row">
                                    <label class="wns-form-label">Meta Text Color</label>
                                    <input type="color" name="wns_email_meta_color" id="meta-color" value="<?php echo esc_attr(get_option('wns_email_meta_color', '#888888')); ?>" class="wns-form-input" />
                                    <div class="wns-form-help">Color for dates, authors, and other meta information.</div>
                                </div>
                                
                                <div class="wns-form-row">
                                    <label class="wns-form-label">Background Color</label>
                                    <input type="color" name="wns_email_background_color" id="background-color" value="<?php echo esc_attr(get_option('wns_email_background_color', '#f9f9f9')); ?>" class="wns-form-input" />
                                    <div class="wns-form-help">Background color for the email content area.</div>
                                </div>
                                
                                <div class="wns-form-row">
                                    <label class="wns-form-label">Card Background Color</label>
                                    <input type="color" name="wns_email_card_bg_color" id="card-bg-color" value="<?php echo esc_attr(get_option('wns_email_card_bg_color', '#f8f8f8')); ?>" class="wns-form-input" />
                                    <div class="wns-form-help">Background color for post and forum cards.</div>
                                </div>
                                
                                <div class="wns-form-row">
                                    <label class="wns-form-label">Card Border Color</label>
                                    <input type="color" name="wns_email_card_border_color" id="card-border-color" value="<?php echo esc_attr(get_option('wns_email_card_border_color', '#e5e5e5')); ?>" class="wns-form-input" />
                                    <div class="wns-form-help">Border color for post and forum cards.</div>
                                </div>
                                
                                <div class="wns-form-row">
                                    <label class="wns-form-label">Content Padding</label>
                                    <input type="range" name="wns_email_content_padding" id="content-padding" value="<?php echo esc_attr(get_option('wns_email_content_padding', '20')); ?>" min="10" max="40" class="wns-form-range" />
                                    <span id="content-padding-value"><?php echo get_option('wns_email_content_padding', '20'); ?>px</span>
                                    <div class="wns-form-help">Internal spacing for email content.</div>
                                </div>
                                
                                <div class="wns-form-row">
                                    <label class="wns-form-label">Card Border Radius</label>
                                    <input type="range" name="wns_email_card_radius" id="card-radius" value="<?php echo esc_attr(get_option('wns_email_card_radius', '7')); ?>" min="0" max="20" class="wns-form-range" />
                                    <span id="card-radius-value"><?php echo get_option('wns_email_card_radius', '7'); ?>px</span>
                                    <div class="wns-form-help">Roundness of card corners.</div>
                                </div>
                                
                                <div class="wns-form-row">
                                    <label class="wns-form-label">Line Height</label>
                                    <input type="range" name="wns_email_line_height" id="line-height" value="<?php echo esc_attr(get_option('wns_email_line_height', '1.5')); ?>" min="1" max="2" step="0.1" class="wns-form-range" />
                                    <span id="line-height-value"><?php echo get_option('wns_email_line_height', '1.5'); ?></span>
                                    <div class="wns-form-help">Spacing between lines of text.</div>
                                </div>
                                
                                <div class="wns-form-row">
                                    <label class="wns-form-label">Font Family</label>
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
                                    <div class="wns-form-help">Font family for the email text.</div>
                                </div>
                                
                                <div class="wns-form-row">
                                    <label class="wns-form-label">Footer Text</label>
                                    <textarea name="wns_email_footer_text" id="footer-text" class="wns-form-input" style="min-height: 100px; resize: vertical;" rows="4" placeholder="Footer text for your newsletter..."><?php echo esc_textarea(get_option('wns_email_footer_text', '')); ?></textarea>
                                    <div class="wns-form-help">Custom footer text to appear at the bottom of your newsletter.</div>
                                </div>
                                
                                <?php submit_button('Save Design Settings', 'primary large wns-button wns-button-primary'); ?>
                            </form>
                        </div>
                    </div>
                    
                    <div class="wns-design-preview">
                        <h3 style="margin-top: 0; color: #333; text-align: center;">Live Preview</h3>
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
                        metaColor: document.getElementById('meta-color'),
                        backgroundColor: document.getElementById('background-color'),
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
                            headerTitle: inputs.headerTitle.value || 'Weekly Newsletter',
                            headerSubtitle: inputs.headerSubtitle.value,
                            logoUrl: inputs.logoUrl.value,
                            introText: inputs.introText.value || 'This week we have prepared {count} new posts and articles for you.',
                            headerColor: inputs.headerColor.value,
                            headerTextSize: inputs.headerTextSize.value,
                            accentColor: inputs.accentColor.value,
                            textColor: inputs.textColor.value,
                            metaColor: inputs.metaColor.value,
                            backgroundColor: inputs.backgroundColor.value,
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
                            ? '<div class="email-footer">' + values.footerText.replace(/\n/g, '<br>') + '</div>'
                            : '';
                        
                        return `<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
body {background: ${values.backgroundColor}; font-family: ${values.fontFamily}; margin:0; padding:20px; color: ${values.textColor}; line-height: ${values.lineHeight};}
.content {color: ${values.textColor}; background:#fff; padding: 0; margin: 0 auto; border-radius: ${values.cardRadius}px; overflow: hidden; max-width: 700px; box-shadow: 0 8px 32px rgba(0,0,0,0.2);}
.email-header {background: ${values.headerColor}; color: #fff; text-align: center; padding: ${values.contentPadding}px;}
.email-header h1 {margin: 0 !important; padding: 0; border: none;}
h1 {color: ${values.textColor}; border-bottom:2px solid ${values.cardBorderColor}; padding-bottom:0.2em; font-size:1.7em; margin-bottom:1em;}
.section-title {color: ${values.textColor}; font-size:1.45em; margin-top:1.5em; margin-bottom:0.8em; font-weight:700;}
.intro {font-size: 1.25em; margin: 1.5em ${values.contentPadding}px 1.2em; color: ${values.textColor};}
.card {
    background: ${values.cardBgColor};
    border: 1px solid ${values.cardBorderColor};
    border-radius: ${values.cardRadius}px;
    margin: 0 ${values.contentPadding}px 1.5em;
    padding: 0.9em 1em;
    box-sizing: border-box;
}
.wp-posts, .forum-section {
    margin-bottom: 2em;
    padding: 0 ${values.contentPadding}px;
}
.forum-header, .thread-header {
    display: flex;
    align-items: center;
    margin-top: 1.2em;
    margin-bottom: 0.7em;
}
.category-label {
    color: #fff;
    background: ${values.headerColor};
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
    color: ${values.textColor};
}
.topic-label {
    color: #fff;
    background: ${values.accentColor};
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
    color: ${values.textColor};
}
.post-title {
    font-size: 1.06em;
    font-weight: 600;
    color: ${values.textColor};
    margin-bottom: 0.15em;
}
.post-title a {
    color: ${values.textColor} !important;
    text-decoration: none !important;
    display: block;
    font-weight: inherit;
    font-size: inherit;
}
.post-title a:hover {
    color: ${values.accentColor} !important;
    text-decoration: none !important;
}
.post-meta {
    color: ${values.metaColor};
    font-size: 0.95em;
    margin-bottom: 0.3em;
}
.post-excerpt {
    color: ${values.textColor};
    font-size: 1em;
    margin-bottom: 0.7em;
    margin-top: 0.1em;
    line-height: ${values.lineHeight};
}
.forum-quote {
    border-left: 3px solid ${values.accentColor};
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
    color: ${values.accentColor};
    font-size: 0.98em;
    text-decoration: underline;
    font-weight: 600;
}
.email-footer {
    background: #f5f5f5;
    color: #666;
    padding: 15px ${values.contentPadding}px;
    font-size: 12px;
    text-align: center;
    border-top: 1px solid #e0e0e0;
    margin-top: 20px;
}
@media (max-width: 600px) { .content {margin: 1em;} }
</style>
</head>
<body>
<div class='content'>
<div class='email-header'>${headerContent}</div>
<div class='intro'><strong>${introContent}</strong></div>
<section class="wp-posts">
<h2 class="section-title">Latest Articles from Website</h2>
<div class="forum-header"><span class="category-label">Technology</span></div>
<div class='card post-card'>
<div class='post-title'><a href='#'>New Features in WordPress 6.5</a></div>
<div class='post-meta'>Sep 12, 2025</div>
<div class='post-excerpt'>WordPress 6.5 brings a wealth of new features and improvements that will simplify managing your websites. Among the most significant updates are an enhanced block editor, new customization options, and better performance optimizations...</div>
<div class='post-readmore'><a href='#'>Read more on website...</a></div>
</div>
<div class="forum-header"><span class="category-label">Marketing</span></div>
<div class='card post-card'>
<div class='post-title'><a href='#'>SEO Optimization Tips for 2025</a></div>
<div class='post-meta'>Sep 13, 2025</div>
<div class='post-excerpt'>SEO continues to evolve, and for 2025 the key trends include AI-optimized content, technical SEO, and user experience. Focus on quality content, loading speed, and mobile optimization for the best results...</div>
<div class='post-readmore'><a href='#'>Read more on website...</a></div>
</div>
</section>
<section class="forum-section">
<h2 class="section-title">Latest Forum Posts</h2>
<div class="forum-header"><span class="category-label">Category</span><span class="forum-name">General Discussion</span></div>
<div class="thread-header"><span class="topic-label">Topic</span><span class="thread-name">Beginner Questions</span></div>
<div class='card forum-post'>
<div class='post-title'><a href='#'>How to start with programming?</a></div>
<div class='post-meta'>John Smith &middot; Sep 14, 2025 08:30</div>
<div class='post-excerpt'>Hello everyone! I'm a complete beginner in programming and would like to learn. Can you recommend which programming language would be best to start with? I'm thinking between Python and JavaScript...</div>
<div class='post-readmore'><a href='#'>Continue reading on forum...</a></div>
</div>
<div class="forum-header"><span class="category-label">Category</span><span class="forum-name">Web Design</span></div>
<div class="thread-header"><span class="topic-label">Topic</span><span class="thread-name">CSS Grid vs Flexbox</span></div>
<div class='card forum-post'>
<div class='post-title'><a href='#'>When to use CSS Grid vs Flexbox?</a></div>
<div class='post-meta'>Sarah Johnson &middot; Sep 14, 2025 06:30</div>
<div class='post-excerpt'>I often encounter the question of when it's better to use CSS Grid versus Flexbox. Can someone explain the main differences and practical applications? <div class="forum-quote">Grid is excellent for two-dimensional layouts, while Flexbox is ideal for one-dimensional ones.</div></div>
<div class='post-readmore'><a href='#'>Continue reading on forum...</a></div>
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
                    <div class="wns-section-title">Mail Configuration</div>
                    
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
                            <label class="wns-form-label">Mail Method</label>
                            <div style="margin-top: 12px;">
                                <label class="wns-checkbox-label" style="margin-bottom: 12px; display: block;">
                                    <input type="radio" name="wns_mail_type" value="wordpress" class="wns-checkbox-input" <?php checked($mail_type, 'wordpress'); ?> style="margin-right: 8px;" />
                                    Use WordPress Mail Function (default)
                                </label>
                                <label class="wns-checkbox-label" style="display: block;">
                                    <input type="radio" name="wns_mail_type" value="smtp" class="wns-checkbox-input" <?php checked($mail_type, 'smtp'); ?> style="margin-right: 8px;" />
                                    Use SMTP Configuration
                                </label>
                            </div>
                            <div class="wns-form-help">Choose how emails should be sent. WordPress mail function uses your server's mail settings, while SMTP allows custom configuration.</div>
                        </div>
                        
                        <div id="wns-smtp-config" style="<?php if($mail_type !== 'smtp') echo 'display:none;'; ?>">
                            <div class="wns-divider" style="margin: 24px 0;"></div>
                            
                            <!-- Security Notice - only shown when SMTP is selected -->
                            <div style="background: #fff3cd; border: 1px solid #ffeaa7; border-radius: 4px; padding: 16px; margin-bottom: 24px;">
                                <h4 style="margin: 0 0 8px 0; color: #856404;">🔒 Security Notice</h4>
                                <p style="margin: 0 0 12px 0; color: #856404; font-size: 14px;">
                                    While SMTP passwords are encrypted in your database, for maximum security we recommend storing sensitive credentials in WordPress constants in your <code>wp-config.php</code> file instead of the database.
                                </p>
                                <p style="margin: 0 0 12px 0; color: #856404; font-size: 14px;">
                                    <strong>Available constants:</strong> <code>WNS_SMTP_HOST</code>, <code>WNS_SMTP_PORT</code>, <code>WNS_SMTP_USERNAME</code>, <code>WNS_SMTP_PASSWORD</code>, <code>WNS_SMTP_ENCRYPTION</code>
                                </p>
                                <p style="margin: 0 0 12px 0; color: #856404; font-size: 14px;">
                                    <strong>Disclaimer:</strong> We are not responsible for the security of your SMTP credentials. Use at your own risk.
                                </p>
                                <p style="margin: 0; color: #856404; font-size: 14px;">
                                    📖 <a href="https://developer.wordpress.org/apis/wp-config-php/#custom-settings" target="_blank" style="color: #856404; text-decoration: underline;">Learn how to use WordPress constants for credentials</a>
                                </p>
                            </div>
                            
                            <div class="wns-form-row">
                                <label class="wns-form-label">SMTP Host</label>
                                <input type="text" name="wns_smtp_host" value="<?php echo esc_attr($smtp_host); ?>" class="wns-form-input" placeholder="smtp.gmail.com" />
                                <div class="wns-form-help">Your SMTP server hostname (e.g., smtp.gmail.com, smtp.mailgun.org)</div>
                            </div>
                            
                            <div class="wns-form-row">
                                <label class="wns-form-label">SMTP Port</label>
                                <input type="number" name="wns_smtp_port" value="<?php echo esc_attr($smtp_port); ?>" class="wns-form-input" placeholder="587" />
                                <div class="wns-form-help">Common ports: 587 (TLS), 465 (SSL), 25 (no encryption)</div>
                            </div>
                            
                            <div class="wns-form-row">
                                <label class="wns-form-label">SMTP Username</label>
                                <input type="text" name="wns_smtp_username" value="<?php echo esc_attr($smtp_username); ?>" class="wns-form-input" placeholder="your-email@domain.com" />
                                <div class="wns-form-help">Your SMTP username (usually your email address)</div>
                            </div>
                            
                            <div class="wns-form-row">
                                <label class="wns-form-label">SMTP Password</label>
                                <input type="password" name="wns_smtp_password" value="<?php echo esc_attr($smtp_password_display); ?>" class="wns-form-input" placeholder="Your SMTP password or app password" />
                                <div class="wns-form-help">Your SMTP password. For Gmail, use an App Password instead of your regular password. Leave unchanged to keep current password. <br><strong>For better security:</strong> Consider defining <code>WNS_SMTP_PASSWORD</code> constant in wp-config.php instead.</div>
                            </div>
                            
                            <div class="wns-form-row">
                                <label class="wns-form-label">Encryption</label>
                                <select name="wns_smtp_encryption" class="wns-form-select">
                                    <option value="tls" <?php selected($smtp_encryption, 'tls'); ?>>TLS (recommended)</option>
                                    <option value="ssl" <?php selected($smtp_encryption, 'ssl'); ?>>SSL</option>
                                    <option value="none" <?php selected($smtp_encryption, 'none'); ?>>None (not recommended)</option>
                                </select>
                                <div class="wns-form-help">Encryption method for secure connection. TLS is recommended for most providers.</div>
                            </div>
                        </div>
                        
                        <?php submit_button('Save Configuration', 'primary large wns-button'); ?>
                    </form>
                </div>
                
                <div class="wns-divider"></div>
                
                <div class="wns-section">
                    <div class="wns-section-title">Test Email Configuration</div>
                    <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
                        <input type="hidden" name="action" value="wns_test_email" />
                        <div class="wns-form-row">
                            <label class="wns-form-label">Test Email Address</label>
                            <input type="email" name="test_email" value="<?php echo esc_attr(wp_get_current_user()->user_email); ?>" class="wns-form-input" required />
                            <div class="wns-form-help">Send a test email to verify your mail configuration is working.</div>
                        </div>
                        <?php submit_button('Send Test Email', 'secondary large wns-button wns-button-secondary'); ?>
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
        </div>
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
        
        $status = $sent ? 'test_sent' : 'test_failed';
        wp_redirect(add_query_arg($status, '1', wp_get_referer()));
        exit;
    }
});

// Notifications are now handled within the admin page UI instead of admin_notices

// --- ADMIN MENU: Single menu for Newsletter ---
add_action('admin_menu', function() {
    // Single top-level menu
    add_menu_page(
        'Weekly Newsletter', // Page title
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