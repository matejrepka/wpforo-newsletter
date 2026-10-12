=== Weekly Newsletter Sender ===
Contributors: marep
Tags: newsletter, email, wpforo, smtp, digest
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.0.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Create scheduled email digests from WordPress posts and optional wpForo activity.

== Description ==

Weekly Newsletter Sender creates configurable email digests containing recent WordPress posts and, when enabled, wpForo forum activity.

Features include:

* Weekly scheduling with configurable day and time
* Published, non-password-protected WordPress posts, public custom post types, and approved public wpForo content
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

New installations start with delivery disabled. Existing installations retain their saved setting.

== Frequently Asked Questions ==

= Is wpForo required? =

No. Disable forum content to use the plugin only with WordPress posts.

= Can I use SMTP? =

Yes. Select SMTP in the delivery settings and provide the server details. SMTP constants may be defined in `wp-config.php` instead of storing credentials in the WordPress database.

= How are sensitive settings stored? =

Newly saved SMTP passwords and cron-job.org API keys use authenticated AES-256-GCM encryption derived from WordPress security salts. Version 1.0.0 credentials remain readable; replace and save them to use the new format. Changing salts requires re-entering stored credentials. Protect `wp-config.php`, use HTTPS, and restrict administrative access.

= Does a digest include private forum content? =

No. Shared digests exclude private and unapproved topics/posts and forums unavailable to the wpForo Guest group. Guest permissions to view topics and replies are checked. Administrator recipients do not change this rule.

= What happens if no recipient roles are selected? =

No newsletter is sent. Invalid addresses and duplicates are removed. Empty post-type selections include no WordPress content.

= Does scheduling follow daylight-saving changes? =

Use a named timezone in WordPress Settings > General. The plugin recalculates the next weekly send in that timezone after each run. Named timezones are also used for cron-job.org. WP-Cron still requires site traffic or an external trigger.

= What happens if SMTP settings are incomplete? =

SMTP delivery fails and does not switch automatically to WordPress mail. Correct the configuration and send a test email.

= Does the plugin contact an external service? =

Only if an administrator enables the optional cron-job.org integration. The plugin then sends authenticated HTTPS requests to `https://api.cron-job.org/jobs` containing the API key, the site's callback URL, schedule settings, and job metadata required to manage the remote job.

cron-job.org Terms of Service: https://cron-job.org/en/tos/

cron-job.org Privacy Policy: https://cron-job.org/en/privacy/

= What happens during uninstall? =

The plugin removes options, credentials, scheduled events, transients, and stored plugin data on each site, including multisite. Uploaded files and unrelated caches are preserved. Deactivation attempts to delete the remote cron-job.org job. If deletion fails, remove it in the cron-job.org console before uninstalling.

== Changelog ==

= 1.0.2 =

* Fix preview details alignment before and after refresh using the same responsive grid.
* Align refresh controls with the heading and remove overlapping negative margins.
* Improve mobile spacing and resize preview emails when the window changes.
* Improve accessible labels, keyboard controls, focus indicators, and mobile layouts across all settings tabs.
* Use native content-type checkboxes and show all editable recipient roles.
* Protect unsaved settings, preserve previews after refresh failures, and translate interface guidance.

= 1.0.1 =

* Exclude private/restricted/unapproved forum activity and password-protected WordPress content.
* Add request protection, settings validation, sandboxed previews, and authenticated credential encryption.
* Fix empty recipient selection, custom-date preview refresh, SMTP hook/state cleanup, and partial-send reporting.
* Preserve local send times across daylight saving and correct cron-job.org job creation and synchronization.
* Complete per-site uninstall cleanup and disable delivery by default on new installations.
* Add offline regression tests and review/upgrade documentation.

= 1.0.0 =

* First public open-source release.
* WordPress and wpForo digest generation.
* Scheduled delivery using WordPress mail or SMTP.
* Configurable recipients, content, and email design.
* English and Slovak interfaces.
* Optional cron-job.org integration.

== Upgrade Notice ==

= 1.0.2 =

Improves admin accessibility and mobile layouts, protects unsaved changes, and preserves previews after refresh failures.

= 1.0.1 =

Security and reliability update. Private/restricted content is excluded. Review recipients, custom dates, and SMTP configuration; incomplete SMTP settings no longer fall back to WordPress mail. Existing credentials remain readable. Replace and save them to use authenticated encryption.

= 1.0.0 =

First public release.
