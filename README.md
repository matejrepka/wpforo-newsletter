# Weekly Newsletter Sender

Weekly Newsletter Sender is an open-source WordPress plugin that creates scheduled email digests from WordPress posts and optional wpForo forum activity.

Version 1.0.0 is the first public release.

## Features

- Scheduled weekly newsletters with configurable day and time
- WordPress post and custom post type summaries
- Optional wpForo forum and topic summaries
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

## Configuration and privacy

The plugin reads published WordPress content, optional wpForo content, and email addresses belonging to the selected WordPress roles. It uses those addresses to deliver the newsletter configured by the site administrator. Site owners are responsible for obtaining any required consent, providing unsubscribe facilities where legally required, and complying with applicable email and privacy laws.

SMTP credentials and the optional cron-job.org API key can be stored in WordPress. Sensitive values are encrypted using keys derived from WordPress security salts. For SMTP, credentials can instead be defined as `WNS_SMTP_HOST`, `WNS_SMTP_PORT`, `WNS_SMTP_USERNAME`, `WNS_SMTP_PASSWORD`, and `WNS_SMTP_ENCRYPTION` in `wp-config.php`.

## Optional external service

cron-job.org integration is disabled by default. If an administrator enables it and supplies an API key, the plugin sends authenticated HTTPS requests to `https://api.cron-job.org/jobs`. Requests contain the API key, the site's scheduled callback URL, schedule settings, and job metadata needed to create or manage the remote cron job.

Use of this feature is subject to the [cron-job.org Terms of Service](https://cron-job.org/en/tos/) and [Privacy Policy](https://cron-job.org/en/privacy/). The integration can be disabled from the plugin settings.

## Security

Please do not publish suspected vulnerabilities in a public issue. Follow the private reporting instructions in [SECURITY.md](SECURITY.md).

## Contributing

Bug reports and pull requests are welcome. Read [CONTRIBUTING.md](CONTRIBUTING.md) before contributing. By participating, you agree to follow the [Code of Conduct](CODE_OF_CONDUCT.md).

## Releases

The version in `weekly-newsletter-sender.php` and the `Stable tag` in `readme.txt` must always match. Releases use Git tags in the form `v1.0.0`; installable ZIP packages belong in GitHub Releases rather than in the repository.

## License

Copyright (C) 2025-2026 Marep.

This project is licensed under the GNU General Public License, version 2 or any later version. See [LICENSE](LICENSE).
