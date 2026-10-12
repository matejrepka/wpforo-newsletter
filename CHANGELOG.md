# Changelog

All notable changes to this project will be documented in this file.

The project follows [Semantic Versioning](https://semver.org/).

## [1.0.2] - 2026-10-12

### Fixed

- Use matching markup and a responsive grid for initial and refreshed preview details; prevent labels and values from being forced into narrow columns.
- Align the preview heading and refresh controls, use the existing secondary-button styling, and keep status messages separate from the details panel.
- Remove overlapping negative margins and reduce preview insets on mobile. Resize the email frame on window changes and improve status-text contrast.
- Verify desktop and mobile layouts against the actual preview-refresh response in browser checks.
- Associate admin controls with labels and help text, expose language selection to keyboard users, and improve focus indicators and mobile targets.
- Replace the mouse-only post-type picker with native checkboxes and expose all editable recipient roles.
- Protect unsaved settings, preserve the last usable preview after refresh failures, and translate preview recovery and interface guidance.
- Keep design controls before the preview on narrow screens and resize the complete preview after viewport changes or logo loading.

## [1.0.1] - 2026-10-12

### Security

- Restrict language-file loading to bundled English and Slovak files.
- Exclude private/unapproved wpForo topics and posts and check Guest forum, topic, and reply permissions before building shared digests; use the active wpForo board's tables and skip absent wpForo installations.
- Exclude password-protected WordPress posts and non-public post types.
- Add nonce verification to test-email and notice-dismissal requests; validate test recipients and use safe local redirects.
- Validate settings, including CSS values, schedules, roles, and content selections. Sandbox email preview frames, encode embedded email HTML safely, escape forum URLs, and render API messages as text.
- Authenticate newly stored SMTP passwords and API keys with AES-256-GCM while retaining read compatibility with 1.0.0 ciphertext. Handle malformed ciphertext and encryption failures safely.

### Fixed

- Empty recipient-role and post-type selections no longer expand to unintended users or content; validate and deduplicate recipient addresses.
- Preview refresh honors custom date ranges and displays email content without depending on scripts inserted through `innerHTML`.
- Restore missing preview refresh/status controls, preserve quoted font names in previews, and remove an obsolete hidden preview block.
- Recalculate local weekly schedules after each run and on timezone changes to follow daylight saving; migrate old recurring schedules on upgrade and repair missing events.
- Preserve other plugins' mail hooks and restore reused PHPMailer state after SMTP sends, including exceptions. Handle non-email SMTP usernames and the explicit no-encryption setting; reject incomplete SMTP configuration.
- Report disabled sends, missing recipients, and partial transport failures accurately, and always remove the newsletter sender filter.
- Use the correct GET request-method value and named timezone for cron-job.org jobs; synchronize after settings are saved and avoid deleting/recreating jobs on transient API failures.
- Correct nonce-protected notice dismissal to use form data understood by WordPress AJAX.
- Remove all plugin options and per-site data on multisite uninstall without deleting uploaded files or flushing unrelated caches. Attempt remote job cleanup on deactivation.

### Changed

- New installations start with delivery disabled; existing settings are preserved.
- Add an offline regression suite and documented staging checks.

## [1.0.0] - 2026-07-11

### Added

- First public open-source release.
- Scheduled digests of WordPress posts and optional wpForo activity.
- Recipient selection by WordPress user role.
- WordPress mail and SMTP delivery methods.
- Configurable newsletter content and visual design.
- Preview and test-send tools.
- English and Slovak interfaces.
- Optional cron-job.org scheduling integration.

[1.0.1]: https://github.com/matejrepka/wpforo-newsletter/compare/v1.0.0...v1.0.1
[1.0.0]: https://github.com/matejrepka/wpforo-newsletter/releases/tag/v1.0.0

[1.0.2]: https://github.com/matejrepka/wpforo-newsletter/compare/v1.0.1...v1.0.2
