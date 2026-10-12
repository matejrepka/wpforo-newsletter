<?php
/** Settings validation shared by saving, scheduling and rendering. */
if (!defined('ABSPATH')) {
    exit;
}

function wns_setting_rules() {
    return [
        'wns_language' => ['enum', ['en', 'sk'], 'en'],
        'wns_send_day' => ['enum', ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'], 'monday'],
        'wns_date_range_type' => ['enum', ['week', 'custom'], 'week'],
        'wns_mail_type' => ['enum', ['wordpress', 'smtp'], 'wordpress'],
        'wns_smtp_encryption' => ['enum', ['tls', 'ssl', 'none'], 'tls'],
        'wns_email_font_family' => ['enum', ['Arial, sans-serif', 'Helvetica, Arial, sans-serif', 'Georgia, serif', "'Times New Roman', serif", 'Verdana, sans-serif', "'Trebuchet MS', sans-serif", "'Segoe UI', Tahoma, Geneva, Verdana, sans-serif"], 'Arial, sans-serif'],
        'wns_email_header_color' => ['color', '#2c5aa0'],
        'wns_email_accent_color' => ['color', '#0073aa'],
        'wns_email_text_color' => ['color', '#333333'],
        'wns_email_card_bg_color' => ['color', '#f8f8f8'],
        'wns_email_card_border_color' => ['color', '#e5e5e5'],
        'wns_email_header_text_size' => ['number', 16, 48, 28],
        'wns_email_intro_text_size' => ['number', 14, 32, 20],
        'wns_email_content_padding' => ['number', 10, 40, 20],
        'wns_email_card_radius' => ['number', 0, 20, 7],
        'wns_email_line_height' => ['number', 1, 2, 1.5],
        'wns_smtp_port' => ['number', 1, 65535, 587],
    ];
}

function wns_sanitize_setting($name, $value) {
    if (in_array($name, ['wns_roles', 'wns_post_types'], true)) {
        if (!is_array($value)) return [];
        $allowed = $name === 'wns_roles' ? array_keys(wp_roles()->roles) : get_post_types(['public' => true]);
        return array_values(array_unique(array_intersect(array_filter($value, 'is_string'), $allowed)));
    }
    if (in_array($name, ['wns_enabled', 'wns_include_wp', 'wns_include_forum', 'wns_cronjob_enabled'], true)) {
        return in_array($value, [1, '1', true], true) ? 1 : 0;
    }
    $rules = wns_setting_rules();
    if (isset($rules[$name])) {
        $rule = $rules[$name];
        if ($rule[0] === 'enum') return is_string($value) && in_array($value, $rule[1], true) ? $value : $rule[2];
        if ($rule[0] === 'color') return is_string($value) && sanitize_hex_color($value) ? sanitize_hex_color($value) : $rule[1];
        if ($rule[0] === 'number') {
            $number = is_scalar($value) && is_numeric($value) ? max($rule[1], min($rule[2], (float)$value)) : $rule[3];
            return $name === 'wns_email_line_height' ? (string)$number : (int)$number;
        }
    }
    if (!is_string($value)) return '';
    if ($name === 'wns_send_time') return preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/D', $value) ? $value : '08:00';
    if (in_array($name, ['wns_date_from', 'wns_date_to'], true)) {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value)) return '';
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $date && $date->format('Y-m-d') === $value ? $value : '';
    }
    if ($name === 'wns_email_logo_url') return esc_url_raw($value, ['http', 'https']);
    if (in_array($name, ['wns_email_footer_text', 'wns_email_intro_text', 'wns_email_header_title', 'wns_email_header_subtitle'], true)) return wp_kses_post($value);
    return sanitize_text_field($value);
}

function wns_register_setting($group, $name, $args = []) {
    if (!isset($args['sanitize_callback'])) {
        $args['sanitize_callback'] = function($value) use ($name) {
            return wns_sanitize_setting($name, $value);
        };
    }
    register_setting($group, $name, $args);
}

// Validate existing design values as well as new saves before interpolating CSS.
foreach (wns_setting_rules() as $wns_setting_name => $wns_rule) {
    if (strpos($wns_setting_name, 'wns_email_') !== 0) continue;
    $wns_validate_design = function($value) use ($wns_setting_name) {
        return wns_sanitize_setting($wns_setting_name, $value);
    };
    add_filter('option_' . $wns_setting_name, $wns_validate_design);
    add_filter('default_option_' . $wns_setting_name, $wns_validate_design);
}
unset($wns_setting_name, $wns_rule, $wns_validate_design);
