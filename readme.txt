=== Weekly Newsletter Sender ===
Contributors: marep
Tags: newsletter, email, wpforo, smtp, digest
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Create scheduled email digests from WordPress posts and optional wpForo activity.

== Description ==

Weekly Newsletter Sender creates configurable email digests containing recent WordPress posts and, when enabled, wpForo forum activity.

Features include:

* Weekly scheduling with configurable day and time
* WordPress posts, custom post types, and optional wpForo content
* Recipient selection by WordPress user role
* WordPress mail or authenticated SMTP delivery
* Customizable email design, header, introduction, logo, and footer
* Preview and test-send tools
* English and Slovak interfaces
* Optional cron-job.org scheduling integration

Site administrators are responsible for collecting any consent required to send email and for complying with applicable privacy and anti-spam rules.

== Installation ==

1. Upload the `weekly-newsletter-sender` folder to `/wp-content/plugins/`, or upload the release ZIP through the WordPress plugin installer.
2. Activate Weekly Newsletter Sender through the Plugins screen.
3. Open Newsletter in WordPress administration.
4. Review recipients, content, schedule, delivery, and design settings before enabling scheduled delivery.

== Frequently Asked Questions ==

= Is wpForo required? =

No. Disable forum content to use the plugin only with WordPress posts.

= Can I use SMTP? =

Yes. Select SMTP in the delivery settings and provide the server details. SMTP constants may be defined in `wp-config.php` instead of storing credentials in the WordPress database.

= How are sensitive settings stored? =

SMTP passwords and cron-job.org API keys stored by the plugin are encrypted using keys derived from the WordPress security salts. Protect `wp-config.php`, use HTTPS, and restrict administrative access.

= Does the plugin contact an external service? =

Only if an administrator enables the optional cron-job.org integration. The plugin then sends authenticated HTTPS requests to `https://api.cron-job.org/jobs` containing the API key, the site's callback URL, schedule settings, and job metadata required to manage the remote job.

cron-job.org Terms of Service: https://cron-job.org/en/tos/

cron-job.org Privacy Policy: https://cron-job.org/en/privacy/

= What happens during uninstall? =

The plugin removes its options, scheduled events, transients, and stored plugin data. Review `uninstall.php` before uninstalling if you need to retain configuration.

== Changelog ==

= 1.0.0 =

* First public open-source release.
* WordPress and wpForo digest generation.
* Scheduled delivery using WordPress mail or SMTP.
* Configurable recipients, content, and email design.
* English and Slovak interfaces.
* Optional cron-job.org integration.

== Upgrade Notice ==

= 1.0.0 =

First public release.
