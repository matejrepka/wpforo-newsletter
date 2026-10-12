<?php
require __DIR__ . '/bootstrap.php';

require dirname(__DIR__) . '/weekly-newsletter-sender.php';
wns_register_settings();
foreach ($settings as $name => $args) verify(is_callable($args['sanitize_callback']), $name . ' has a sanitizer');
verify(get_option('wns_enabled') === 0, 'fresh installs are disabled');
verify(wns_sanitize_setting('wns_language', '../../uninstall') === 'en', 'language traversal blocked');
wns_load_translations(['unexpected']);
verify(wns_t('default_subject') === 'Weekly Newsletter', 'malformed stored language safely falls back');
verify(wns_sanitize_setting('wns_send_time', '29:99') === '08:00', 'invalid time rejected');
verify(wns_sanitize_setting('wns_date_from', '2026-02-30') === '', 'invalid date rejected');
verify(wns_sanitize_setting('wns_date_from', "2026-10-01\0") === '', 'null-byte date safely rejected');
verify(wns_sanitize_setting('wns_email_header_color', '#fff;}</style><script>') === '#2c5aa0', 'CSS injection blocked');
verify(wns_sanitize_setting('wns_email_content_padding', ['bad']) === 20, 'non-scalar CSS values rejected');
verify(wns_sanitize_setting('wns_email_line_height', 200) === '2', 'numeric CSS bounded');
verify(wns_sanitize_setting('wns_email_logo_url', 'javascript:alert(1)') === '', 'unsafe logo scheme rejected');
verify(wns_sanitize_setting('wns_roles', ['subscriber', 'unknown', 'subscriber', []]) === ['subscriber'], 'roles validated and deduplicated');
$options['wns_email_header_color'] = '</style><script>alert(1)</script>';
verify(get_option('wns_email_header_color') === '#2c5aa0', 'legacy stored CSS is validated during rendering');
$options['wns_roles'] = [];
verify(wns_get_user_emails() === [] && !isset($GLOBALS['user_query']), 'empty roles do not query all users');
$options['wns_roles'] = ['invalid'];
verify(wns_get_user_emails() === [], 'invalid stored roles send nothing');
$options['wns_roles'] = ['subscriber'];
$GLOBALS['users'] = [(object)['user_email' => 'one@example.test'], (object)['user_email' => 'one@example.test'], (object)['user_email' => 'invalid']];
verify(wns_get_user_emails() === ['one@example.test'], 'recipient addresses validated and deduplicated');
wns_get_wp_posts_summary('2026-10-01', '2026-10-10');
verify($GLOBALS['post_query']['has_password'] === false && $GLOBALS['post_query']['post_status'] === 'publish', 'protected WordPress posts excluded');
$options['wns_post_types'] = ['private-type'];
verify(wns_get_wp_posts_summary() === [], 'non-public post types excluded');
$options['wns_post_types'] = ['post'];

$secret = ' password with spaces & "quotes" ';
$encrypted = wns_sanitize_smtp_password($secret);
verify(strpos($encrypted, 'wns:v1:') === 0 && wns_decrypt_password($encrypted) === $secret, 'authenticated encryption round trip preserves exact secret');
verify(wns_sanitize_smtp_password($encrypted) === $encrypted, 'new-option double sanitization is idempotent');
$options['wns_smtp_password'] = $encrypted;
verify(wns_sanitize_smtp_password('••••••••••••') === $encrypted, 'masked secret retained');
$tampered = base64_decode(substr($encrypted, 7));
$tampered[28] = chr(ord($tampered[28]) ^ 1);
verify(wns_decrypt_password('wns:v1:' . base64_encode($tampered)) === '', 'ciphertext tampering rejected');
foreach (['%%%%', 'aA==', 'wns:v1:aA==', [], null] as $bad) verify(wns_decrypt_password($bad) === '', 'malformed ciphertext rejected');
$iv = str_repeat('x', 16);
$legacy = base64_encode($iv . openssl_encrypt($secret, 'AES-256-CBC', hash('sha256', wp_salt('auth') . wp_salt('secure_auth')), 0, $iv));
verify(wns_decrypt_password($legacy) === $secret, 'version 1.0.0 credentials remain readable');

$options['wns_send_day'] = 'monday';
$options['wns_send_time'] = '08:00';
$now = new DateTimeImmutable('2026-10-12 08:05', wp_timezone());
verify(wns_scheduled_target(false, $now)->format('Y-m-d H:i') === '2026-10-12 08:00', 'automatic send uses last scheduled time');
verify(wns_scheduled_target(true, $now)->format('Y-m-d H:i') === '2026-10-19 08:00', 'preview uses next scheduled time');
$period = wns_content_period(false, $now);
verify($period['from'] === '2026-10-05' && $period['to'] === '2026-10-11', 'completed seven-day content period');
$before = wns_scheduled_target(true, new DateTimeImmutable('2026-10-18 09:00', wp_timezone()));
$after = wns_scheduled_target(true, new DateTimeImmutable('2026-10-25 09:00', wp_timezone()));
verify($after->format('H:i') === $before->format('H:i') && $after->getTimestamp() - $before->getTimestamp() === 7 * 86400 + 3600, 'weekly wall-clock time survives autumn DST');
$before = wns_scheduled_target(true, new DateTimeImmutable('2026-03-22 09:00', wp_timezone()));
$after = wns_scheduled_target(true, new DateTimeImmutable('2026-03-29 09:00', wp_timezone()));
verify($after->getTimestamp() - $before->getTimestamp() === 7 * 86400 - 3600, 'weekly wall-clock time survives spring DST');
$options['wns_date_range_type'] = 'custom';
$options['wns_date_from'] = '2026-09-01';
$options['wns_date_to'] = '2026-09-30';
verify(wns_content_period(true, $now)['from'] === wns_content_period(false, $now)['from'], 'custom preview and send periods match');
$options['wns_date_to'] = '2026-08-01';
try { wns_content_period(); verify(false, 'reversed custom range rejected'); } catch (InvalidArgumentException $e) { verify(true, 'reversed custom range rejected'); }
$options['wns_date_range_type'] = 'week';
$options['wns_send_day'] = 'sunday';
$options['wns_send_time'] = '23:58';
$schedule = wns_get_cronjob_schedule();
verify($schedule['time'] === '00:03' && $schedule['day'] === 1 && $schedule['timezone'] === 'Europe/Bratislava', 'external schedule rolls over midnight in named timezone');
$timezone = '+05:30';
verify(wns_get_cronjob_schedule()['timezone'] === 'UTC', 'fixed-offset timezone converted for external API');
$timezone = 'Europe/Bratislava';
$options['wns_send_day'] = 'monday';
$options['wns_send_time'] = '08:00';
$options['wns_enabled'] = 1;
wns_update_cron_schedule();
verify(wp_next_scheduled('wns_send_weekly_newsletter') === wns_scheduled_target()->getTimestamp(), 'single event uses local target');
$options['wns_enabled'] = 0;
wns_update_cron_schedule();
verify(!wp_next_scheduled('wns_send_weekly_newsletter'), 'disabling removes scheduled delivery');

verify(request_result('admin_post_wns_test_email', ['test_email' => 'one@example.test']) === 'Invalid nonce', 'test email blocks CSRF');
$allowed = false;
verify(is_string(request_result('admin_post_wns_test_email', [])), 'test email blocks non-admin users');
$allowed = true;
verify(request_result('wp_ajax_wns_dismiss_admin_notice', ['id' => 'notice']) === 'Invalid nonce', 'notice dismissal blocks CSRF');
$options['wns_admin_notices'] = ['notice' => []];
verify(request_result('wp_ajax_wns_dismiss_admin_notice', ['nonce' => 'test-valid-wns_dismiss_notice', 'ids' => ['notice', []]])['success'], 'valid notice dismissal handles malformed IDs');
$options['wns_include_forum'] = 0;
$options['wns_date_range_type'] = 'custom';
$options['wns_date_from'] = '2026-09-01';
$options['wns_date_to'] = '2026-09-30';
$preview = request_result('wp_ajax_wns_generate_email_preview', ['nonce' => 'test-valid-wns_preview_nonce']);
verify($preview['data']['date_from'] === '2026-09-01' && $preview['data']['date_to'] === '2026-09-30', 'AJAX preview honors custom dates');
verify(isset($preview['data']['email_html']) && strpos($preview['data']['html'], '<script>') === false, 'refreshed preview provides explicit email payload');
$options['wns_date_range_type'] = 'week';

$options['wns_smtp_host'] = 'smtp.example.test';
$options['wns_smtp_username'] = 'smtp-user';
$options['wns_smtp_encryption'] = 'none';
$other_calls = 0;
$other = function($mailer) use (&$other_calls) { $other_calls++; };
add_action('phpmailer_init', $other);
$original = clone $GLOBALS['mailer'];
verify(wns_send_email_smtp('one@example.test', 'Subject', 'Body'), 'SMTP sends through chosen transport');
$sent_mailer = end($mail_calls)[1];
verify($sent_mailer->SMTPSecure === '' && !$sent_mailer->SMTPAutoTLS && $sent_mailer->From === 'wordpress@example.test', 'none encryption and non-email SMTP usernames handled');
verify($GLOBALS['mailer'] == $original, 'reused mailer state restored');
wp_mail('one@example.test', 'Other plugin', 'Body');
verify($other_calls === 2 && end($mail_calls)[1]->Mailer === 'mail', 'other plugins retain hooks and original transport');
$GLOBALS['mail_throw'] = true;
try { wns_send_email_smtp('one@example.test', 'Subject', 'Body'); } catch (RuntimeException $e) {}
verify($GLOBALS['mailer'] == $original && empty($hooks['phpmailer_init'][PHP_INT_MAX]), 'SMTP cleanup runs after exceptions');
$GLOBALS['mail_throw'] = false;
$options['wns_smtp_password'] = 'invalid';
$mail_count = count($mail_calls);
verify(!wns_send_email_smtp('one@example.test', 'Subject', 'Body') && count($mail_calls) === $mail_count, 'invalid SMTP credentials never silently fall back');
$options['wns_smtp_password'] = $encrypted;
verify(!wns_send_newsletter()['success'] && empty($hooks['wp_mail_from_name'][10]), 'disabled sends leave no sender filter');
$options['wns_enabled'] = 1;
$GLOBALS['users'][] = (object)['user_email' => 'fail@example.test'];
$result = wns_send_newsletter();
verify(!$result['success'] && $result['sent'] === 1 && $result['total'] === 2, 'partial delivery reported accurately');
verify(empty($hooks['wp_mail_from_name'][10]), 'newsletter sender filter cleaned up');

$options['wns_cronjob_enabled'] = 1;
$options['wns_cronjob_api_key'] = wns_encrypt_password('test-api-key');
$options['wns_cronjob_id'] = 123;
$http_responses[] = ['status' => 500, 'body' => '{"error":"unavailable"}'];
$result = wns_sync_cronjob();
verify(!$result['success'] && count($http_calls) === 1 && get_option('wns_cronjob_id') === 123, 'failed updates retain existing remote jobs');
$http_responses[] = ['status' => 404, 'body' => '{}'];
$http_responses[] = ['status' => 200, 'body' => '{"jobId":456}'];
verify(wns_sync_cronjob()['success'] && get_option('wns_cronjob_id') === 456 && count($http_calls) === 3, 'only missing remote jobs are recreated');
$payload = json_decode(end($http_calls)[1]['body'], true);
verify($payload['job']['requestMethod'] === 0 && $payload['job']['schedule']['timezone'] === 'Europe/Bratislava', 'external job uses GET and local timezone');
verify(end($http_calls)[1]['redirection'] === 0, 'API requests do not follow redirects with credentials');
$http_responses[] = new TestHttpError();
verify(!wns_delete_cronjob()['success'] && get_option('wns_cronjob_id') === 456, 'failed remote deletion retains job ID for retry');

// Execute the real newsletter SQL against SQLite fixtures.
class TestDatabase {
    public $db;
    function __construct() {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec('CREATE TABLE forums (forumid INTEGER, title TEXT); CREATE TABLE topics (topicid INTEGER, forumid INTEGER, title TEXT, status INTEGER, private INTEGER); CREATE TABLE posts (postid INTEGER, forumid INTEGER, topicid INTEGER, created TEXT, status INTEGER, private INTEGER, is_first_post INTEGER);');
    }
    function prepare($query, ...$args) { foreach ($args as $arg) $query = preg_replace('/%s/', $this->db->quote($arg), $query, 1); return $query; }
    function get_results($query) { return $this->db->query($query)->fetchAll(PDO::FETCH_OBJ); }
}
class TestPermissions {
    function forum_can($action, $id, $groups = null) { verify($groups === [4], 'forum visibility checked against guests'); return (int)$id === 1; }
}
function WPF() { return $GLOBALS['forum_api'] ?? null; }
$GLOBALS['forum_api'] = null;
verify(wns_get_wpforo_summary() === [], 'absent wpForo skips database queries');
$wpdb = new TestDatabase();
$GLOBALS['forum_api'] = (object)['tables' => (object)['posts' => 'posts', 'topics' => 'topics', 'forums' => 'forums'], 'perm' => new TestPermissions()];
$wpdb->db->exec("INSERT INTO forums VALUES (1, 'Public'), (2, 'Members'); INSERT INTO topics VALUES (1,1,'Public',0,0), (2,1,'Private',0,1), (3,1,'Pending',1,0), (4,2,'Restricted',0,0);");
$wpdb->db->exec("INSERT INTO posts VALUES (1,1,1,'2026-10-08 08:00:00',0,0,1), (2,1,2,'2026-10-08 08:00:00',0,0,1), (3,1,3,'2026-10-08 08:00:00',0,0,1), (4,2,4,'2026-10-08 08:00:00',0,0,1), (5,1,1,'2026-10-08 08:00:00',1,0,0), (6,1,1,'2026-10-08 08:00:00',0,1,0), (7,1,1,'2026-09-08 08:00:00',0,0,0);");
$summary = wns_get_wpforo_summary('2026-10-05', '2026-10-11');
verify(count($summary) === 1 && count($summary[1]['threads']) === 1 && count($summary[1]['threads'][1]['posts']) === 1 && (int)$summary[1]['threads'][1]['posts'][0]->postid === 1, 'SQL excludes restricted/private/unapproved/out-of-period forum content');

// Uninstall must erase credentials and caches on every site without flushing unrelated caches.
define('WP_UNINSTALL_PLUGIN', true);
function is_multisite() { return true; }
function get_sites($args) { return $args['offset'] === 0 ? [1, 2] : []; }
function switch_to_blog($id) { $GLOBALS['blog'] = $id; }
function restore_current_blog() { $GLOBALS['blog'] = 1; }
function delete_transient($name) {}
function delete_site_transient($name) {}
require dirname(__DIR__) . '/uninstall.php';
foreach ([1, 2] as $site) foreach (['wns_smtp_password', 'wns_cronjob_api_key', 'wns_cronjob_id', 'wns_post_types', 'wns_language', 'wns_next_email_content', 'wns_email_intro_text_size', 'wns_version'] as $name) verify(in_array($name, $GLOBALS['deleted_options'][$site], true), $name . ' removed on site ' . $site);
echo 'PASS: ' . $checks . " checks; no real email or external requests.\n";
