<?php
/** Render admin fixtures for JavaScript syntax and preview checks, without WordPress. */
require __DIR__ . '/bootstrap.php';
function admin_url($path = '') { return home_url('/wp-admin/' . $path); }
function checked($actual, $expected = true, $echo = true) { $result = (string)$actual === (string)$expected ? 'checked="checked"' : ''; if ($echo) echo $result; return $result; }
function selected($actual, $expected = true, $echo = true) { $result = (string)$actual === (string)$expected ? 'selected="selected"' : ''; if ($echo) echo $result; return $result; }
function esc_js($value) { return str_replace(["\r", "\n", "'"], ['', '\\n', "\\'"], htmlspecialchars((string)$value, ENT_COMPAT, 'UTF-8')); }
function esc_textarea($value) { return esc_html($value); }
function wp_create_nonce($action) { return 'test-valid-' . $action; }
function wp_nonce_field($action, $name) { echo '<input type="hidden" name="' . esc_attr($name) . '" value="' . esc_attr(wp_create_nonce($action)) . '" />'; }
function settings_fields($group) { wp_nonce_field($group . '-options', '_wpnonce'); }
function submit_button($label, $class = '') { echo '<p class="submit"><input type="submit" class="button ' . esc_attr($class) . '" value="' . esc_attr($label) . '" /></p>'; }
function wp_get_current_user() { return (object)['user_email' => 'admin@example.test']; }
function get_editable_roles() { return wp_roles()->roles; }
function sanitize_title($value) { return strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', $value), '-')); }
function wp_trim_words($value, $count = 55, $more = '…') { $words = preg_split('/\s+/', strip_tags($value)); return implode(' ', array_slice($words, 0, $count)) . (count($words) > $count ? $more : ''); }
require dirname(__DIR__) . '/weekly-newsletter-sender.php';
wns_register_settings();
$options['wns_include_forum'] = 0;
$options['wns_language'] = $argv[4] ?? 'en';
$options['wns_email_font_family'] = "'Times New Roman', serif";
$options['wns_email_header_title'] = 'Title "quoted" </script>';
$options['wns_date_range_type'] = 'custom';
$options['wns_date_from'] = '2026-09-01';
$options['wns_date_to'] = '2026-09-30';
$_GET['tab'] = $argv[1] ?? 'preview';
ob_start();
wns_settings_page();
$html = ob_get_clean();
if (isset($argv[2])) file_put_contents($argv[2], $html);
else echo $html;
if (isset($argv[3]) && $argv[3] !== '') {
    $GLOBALS['json_result'] = null;
    $_POST = ['nonce' => wp_create_nonce('wns_preview_nonce')];
    do_action('wp_ajax_wns_generate_email_preview');
    file_put_contents($argv[3], wp_json_encode($GLOBALS['json_result']));
}
