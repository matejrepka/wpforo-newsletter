<?php
/** Offline regression checks. No real emails or external API requests are made. */
define('ABSPATH', __DIR__ . '/');
$options = [];
$hooks = [];
$settings = [];
$mail_calls = [];
$http_calls = [];
$http_responses = [];
$allowed = true;
$timezone = 'Europe/Bratislava';
$checks = 0;

function verify($condition, $description) {
    global $checks;
    if (!$condition) throw new RuntimeException('FAIL: ' . $description);
    $checks++;
}
function add_filter($hook, $callback, $priority = 10, $args = 1) {
    $GLOBALS['hooks'][$hook][$priority][] = [$callback, $args];
}
function add_action($hook, $callback, $priority = 10, $args = 1) { add_filter($hook, $callback, $priority, $args); }
function remove_filter($hook, $callback, $priority = 10) {
    foreach ($GLOBALS['hooks'][$hook][$priority] ?? [] as $key => $item) {
        if ($item[0] === $callback) unset($GLOBALS['hooks'][$hook][$priority][$key]);
    }
}
function remove_action($hook, $callback, $priority = 10) { remove_filter($hook, $callback, $priority); }
function apply_filters($hook, $value, ...$args) {
    $priorities = $GLOBALS['hooks'][$hook] ?? [];
    ksort($priorities);
    foreach ($priorities as $items) foreach ($items as $item) $value = call_user_func_array($item[0], array_slice(array_merge([$value], $args), 0, $item[1]));
    return $value;
}
function do_action($hook, ...$args) {
    $priorities = $GLOBALS['hooks'][$hook] ?? [];
    ksort($priorities);
    foreach ($priorities as $items) foreach ($items as $item) call_user_func_array($item[0], array_slice($args, 0, $item[1]));
}
function register_activation_hook($file, $callback) {}
function register_deactivation_hook($file, $callback) {}
function register_setting($group, $name, $args = []) { $GLOBALS['settings'][$name] = $args; }
function get_option($name, $default = false) {
    return array_key_exists($name, $GLOBALS['options']) ? apply_filters('option_' . $name, $GLOBALS['options'][$name]) : apply_filters('default_option_' . $name, $default);
}
function add_option($name, $value) { if (!array_key_exists($name, $GLOBALS['options'])) $GLOBALS['options'][$name] = $value; }
function update_option($name, $value, $autoload = null) { $GLOBALS['options'][$name] = $value; }
function delete_option($name) { unset($GLOBALS['options'][$name]); $GLOBALS['deleted_options'][$GLOBALS['blog'] ?? 1][] = $name; }
function delete_site_option($name) {}
function add_settings_error($name, $code, $message) { $GLOBALS['settings_errors'][] = $code; }
function wp_salt($scheme) { return 'test-only-salt-' . $scheme; }
function wp_timezone() { return new DateTimeZone($GLOBALS['timezone']); }
function wp_roles() { return (object)['roles' => ['subscriber' => [], 'administrator' => [], 'editor' => []]]; }
function get_post_types($args = [], $output = 'names') {
    return $output === 'objects' ? ['post' => (object)['name' => 'post', 'label' => 'Posts'], 'page' => (object)['name' => 'page', 'label' => 'Pages']] : ['post' => 'post', 'page' => 'page'];
}
function sanitize_hex_color($value) { return preg_match('/^#(?:[a-f0-9]{3}|[a-f0-9]{6})$/iD', $value) ? $value : null; }
function sanitize_text_field($value) { return is_scalar($value) ? trim(strip_tags(preg_replace('/[\r\n\t]+/', ' ', (string)$value))) : ''; }
function sanitize_key($value) { return preg_replace('/[^a-z0-9_-]/', '', strtolower($value)); }
function esc_url_raw($value, $protocols = null) { return preg_match('~^https?://~i', $value) ? $value : ''; }
function esc_url($value) { return htmlspecialchars(esc_url_raw($value), ENT_QUOTES, 'UTF-8'); }
function esc_html($value) { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
function esc_attr($value) { return esc_html($value); }
function wp_kses_post($value) { return strip_tags($value, '<p><br><b><strong><em><i><blockquote>'); }
function wp_kses($value, $tags) { return strip_tags($value, '<' . implode('><', array_keys($tags)) . '>'); }
function force_balance_tags($value) { return $value; }
function wp_unslash($value) { return is_array($value) ? array_map('wp_unslash', $value) : stripslashes($value); }
function is_email($value) { return is_string($value) && filter_var($value, FILTER_VALIDATE_EMAIL); }
function sanitize_email($value) { return filter_var($value, FILTER_SANITIZE_EMAIL); }
function absint($value) { return abs((int)$value); }
function get_users($args) { $GLOBALS['user_query'] = $args; return $GLOBALS['users'] ?? []; }
function wp_list_pluck($items, $field) { return array_map(function($item) use ($field) { return $item->$field; }, $items); }
function wp_json_encode($value, $flags = 0) { return json_encode($value, $flags); }
function get_site_url() { return 'https://example.test'; }
function trailingslashit($url) { return rtrim($url, '/') . '/'; }
function get_bloginfo($key) { return 'Test site'; }
function home_url($path = '') { return 'https://example.test' . $path; }
function site_url($path = '') { return home_url($path); }
function wp_remote_request($url, $args) {
    $GLOBALS['http_calls'][] = [$url, $args];
    return array_shift($GLOBALS['http_responses']);
}
function is_wp_error($response) { return $response instanceof TestHttpError; }
class TestHttpError { function get_error_message() { return 'Offline'; } }
function wp_remote_retrieve_response_code($response) { return $response['status']; }
function wp_remote_retrieve_body($response) { return $response['body']; }
function wp_clear_scheduled_hook($hook) { unset($GLOBALS['events'][$hook]); }
function wp_next_scheduled($hook) { return $GLOBALS['events'][$hook] ?? false; }
function wp_schedule_single_event($time, $hook) { $GLOBALS['events'][$hook] = $time; return true; }
class TestAbort extends RuntimeException {
    public $payload;
    function __construct($payload) { parent::__construct('Request stopped'); $this->payload = $payload; }
}
function current_user_can($capability) { return $GLOBALS['allowed']; }
function wp_die($message) { throw new TestAbort($message); }
function check_ajax_referer($action, $key) { if (($_POST[$key] ?? '') !== 'test-valid-' . $action) throw new TestAbort('Invalid nonce'); }
function check_admin_referer($action, $key) { check_ajax_referer($action, $key); }
function wp_send_json_success($data) { if ($GLOBALS['json_result'] === null) $GLOBALS['json_result'] = ['success' => true, 'data' => $data]; }
function wp_send_json_error($data, $status = null) { if ($GLOBALS['json_result'] === null) $GLOBALS['json_result'] = ['success' => false, 'data' => $data]; }
function request_result($hook, $post) {
    $_POST = $post;
    $GLOBALS['json_result'] = null;
    try { do_action($hook); } catch (TestAbort $e) { return $e->payload; }
    if ($GLOBALS['json_result'] !== null) return $GLOBALS['json_result'];
    throw new RuntimeException('Handler did not stop');
}
class WP_Query {
    public $posts = [];
    function __construct($args) { $GLOBALS['post_query'] = $args; }
}
class TestMailer {
    public $Mailer = 'mail', $Host = 'original.test', $Port = 25, $Username = '', $Password = '', $SMTPAuth = false,
        $SMTPSecure = '', $SMTPAutoTLS = true, $From = 'wordpress@example.test', $FromName = 'WordPress', $Sender = '';
    function isSMTP() { $this->Mailer = 'smtp'; }
    function smtpClose() {}
    function setFrom($address, $name, $auto = true) { $this->From = $address; $this->FromName = $name; if ($auto) $this->Sender = $address; }
}
function wp_mail($to, $subject, $message, $headers = []) {
    $mailer = $GLOBALS['mailer'];
    do_action('phpmailer_init', $mailer);
    $GLOBALS['mail_calls'][] = [$to, clone $mailer];
    if (!empty($GLOBALS['mail_throw'])) throw new RuntimeException('Mail transport failure');
    return $to !== 'fail@example.test';
}
$GLOBALS['mailer'] = new TestMailer();
