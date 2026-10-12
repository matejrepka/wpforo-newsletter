# Weekly Newsletter Sender

Weekly Newsletter Sender is an open-source WordPress plugin that creates scheduled email digests from WordPress posts and optional wpForo forum activity.

Current version: **1.0.2**. This update improves admin accessibility, mobile layouts, unsaved-change protection, and preview recovery, and includes the security and reliability improvements from 1.0.1.

## Features

- Scheduled weekly newsletters with configurable day and time
- WordPress post and custom post type summaries
- Optional summaries of approved, public wpForo forum activity
- Recipient selection by WordPress user role
- WordPress `wp_mail()` or authenticated SMTP delivery
- Customizable email colors, typography, logo, header, introduction, and footer
- Email preview and test-send tools
- English and Slovak interfaces
- Optional cron-job.org integration for external scheduling

## Requirements

- WordPress 6.0 or newer
- PHP 7.4 or newer with OpenSSL enabled
- wpForo only when forum content is enabled
- A working WordPress mail configuration or SMTP account

## Installation

1. Download the `weekly-newsletter-sender.zip` file from the latest GitHub release.
2. In WordPress, open **Plugins > Add New Plugin > Upload Plugin**.
3. Select the ZIP file, install it, and activate **Weekly Newsletter Sender**.
4. Open **Newsletter** in WordPress administration and review every configuration tab before enabling scheduled delivery.

For development, clone this repository into `wp-content/plugins/weekly-newsletter-sender` and activate it from WordPress administration. The plugin has no build step or bundled dependency installation.

New installations start with newsletter delivery disabled. Choose recipient roles, content sources, and a schedule; send a test email; then enable delivery. Existing installations retain their saved enabled/disabled setting.

## Upgrading to 1.0.2

Back up the existing plugin settings, replace the plugin folder with the complete release (including `includes/` and `languages/`), and open **Newsletter** to review the configuration.

- Private or unapproved wpForo topics/posts and forums unavailable to wpForo's Guest group are excluded from every shared digest. Guest permissions to view topics and replies are checked explicitly. Restricted content is excluded even for administrator recipients.
- WordPress summaries include only published, non-password-protected content from selected public post types. An empty post-type selection includes no WordPress content.
- An empty or invalid recipient-role selection sends no messages. Invalid addresses and duplicate addresses are removed.
- The preview, preview refresh, and send operation use the configured custom date range. Invalid or reversed custom dates stop the operation.
- Scheduled delivery is recalculated in the WordPress timezone after each run to preserve local send time across daylight-saving changes. The old recurring event is replaced automatically during upgrade.
- SMTP configuration errors stop SMTP delivery. Correct the settings instead of relying on an automatic fallback to WordPress mail. SMTP usernames do not have to be email addresses; non-email usernames retain WordPress's configured sender address.
- The manual-send result reports how many messages the mail transport accepted, including partial failures. Transport acceptance does not confirm inbox delivery.

Use a named timezone such as `Europe/Bratislava` in **Settings > General** for daylight-saving adjustments. WP-Cron still depends on traffic or an external cron trigger.

Version 1.0.2 uses the same responsive details grid before and after preview refresh. The heading, refresh button, and status share a toolbar with consistent spacing; the preview stays within the content width and its email frame resizes when the window changes.

See [the review notes](docs/REVIEW-1.0.1.md) for findings, validation, and staging checks.

## Configuration and privacy

The plugin reads published WordPress content, optional public wpForo content, and email addresses belonging to the selected WordPress roles. It uses those addresses to deliver the newsletter configured by the site administrator. Site owners are responsible for obtaining any required consent, providing unsubscribe facilities where legally required, and complying with applicable email and privacy laws. The plugin does not provide a built-in subscription or unsubscribe system.

SMTP credentials and the optional cron-job.org API key can be stored in WordPress. Newly saved sensitive values use authenticated AES-256-GCM encryption with keys derived from WordPress security salts. Version 1.0.0 credentials remain readable; enter and save a replacement credential to adopt the new format. Changing the WordPress salts requires re-entering stored credentials. For SMTP, credentials can instead be defined as `WNS_SMTP_HOST`, `WNS_SMTP_PORT`, `WNS_SMTP_USERNAME`, `WNS_SMTP_PASSWORD`, and `WNS_SMTP_ENCRYPTION` in `wp-config.php`. Choose `tls` or `ssl` for encrypted SMTP connections.

## Optional external service

cron-job.org integration is disabled by default. If an administrator enables it and supplies an API key, the plugin sends authenticated HTTPS requests to `https://api.cron-job.org/jobs`. Requests contain the API key, the site's scheduled callback URL, schedule settings, and job metadata needed to create or manage the remote cron job.

Use of this feature is subject to the [cron-job.org Terms of Service](https://cron-job.org/en/tos/) and [Privacy Policy](https://cron-job.org/en/privacy/). The integration can be disabled from the plugin settings.

The external job calls `wp-cron.php` using GET five minutes after the newsletter schedule. Named WordPress timezones are preserved; fixed UTC offsets are converted to UTC. Settings changes are synchronized once after all submitted values are saved. Failed updates keep the existing job; only a 404 response causes recreation. Disabling the integration or deactivating the plugin attempts to delete the remote job. If deletion fails, remove the job in the cron-job.org console before uninstalling.

## Uninstalling

Uninstall removes plugin options (including credentials, language, cached previews, and remote job IDs), cron events, and transients on each site in a multisite installation. It does not remove uploaded files or flush unrelated site caches. Database backups and any remote job that could not be deleted require separate cleanup.

## Security

Please do not publish suspected vulnerabilities in a public issue. Follow the private reporting instructions in [SECURITY.md](SECURITY.md).

## Contributing

Bug reports and pull requests are welcome. Read [CONTRIBUTING.md](CONTRIBUTING.md) before contributing. By participating, you agree to follow the [Code of Conduct](CODE_OF_CONDUCT.md).

## Releases

The version in `weekly-newsletter-sender.php` and the `Stable tag` in `readme.txt` must always match. Releases use Git tags in the form `v1.0.0`; installable ZIP packages belong in GitHub Releases rather than in the repository.

## License

Copyright (C) 2025-2026 Marep.

This project is licensed under the GNU General Public License, version 2 or any later version. See [LICENSE](LICENSE).
