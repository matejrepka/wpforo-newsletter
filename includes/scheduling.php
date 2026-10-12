<?php
/** Calculate newsletter periods and schedules in the WordPress timezone. */
if (!defined('ABSPATH')) {
    exit;
}

function wns_scheduled_target($upcoming = true, $now = null) {
    $timezone = wp_timezone();
    $now = $now ? (new DateTimeImmutable('@' . $now->getTimestamp()))->setTimezone($timezone) : new DateTimeImmutable('now', $timezone);
    $day = wns_sanitize_setting('wns_send_day', get_option('wns_send_day', 'monday'));
    $time = wns_sanitize_setting('wns_send_time', get_option('wns_send_time', '08:00'));
    list($hour, $minute) = array_map('intval', explode(':', $time));
    $target = $now->modify('this ' . $day)->setTime($hour, $minute, 0);
    if ($upcoming && $target <= $now) $target = $target->modify('+1 week');
    if (!$upcoming && $target > $now) $target = $target->modify('-1 week');
    return $target;
}

function wns_content_period($upcoming = true, $now = null) {
    $target = wns_scheduled_target($upcoming, $now);
    if (get_option('wns_date_range_type', 'week') === 'custom') {
        $from = wns_sanitize_setting('wns_date_from', get_option('wns_date_from', ''));
        $to = wns_sanitize_setting('wns_date_to', get_option('wns_date_to', ''));
        if (!$from || !$to || $from > $to) throw new InvalidArgumentException('Choose a valid custom date range with the start on or before the end.');
    } else {
        $to = $target->modify('-1 day')->format('Y-m-d');
        $from = $target->modify('-7 days')->format('Y-m-d');
    }
    return ['target' => $target, 'from' => $from, 'to' => $to];
}

function wns_run_scheduled_newsletter() {
    try {
        wns_send_newsletter();
    } finally {
        wns_update_cron_schedule();
    }
}

// Repair first-time or missing schedules and migrate fixed intervals on upgrade.
add_action('init', function() {
    if (get_option('wns_version') !== WNS_VERSION) {
        wns_update_cron_schedule();
        if (get_option('wns_cronjob_enabled', 0)) wns_queue_cronjob_sync();
        update_option('wns_version', WNS_VERSION, false);
    } elseif (get_option('wns_enabled', 0) && !wp_next_scheduled('wns_send_weekly_newsletter')) {
        wns_update_cron_schedule();
    }
});
