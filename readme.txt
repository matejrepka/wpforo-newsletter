=== Weekly Newsletter Sender ===
Contributors: marep
Donate link: https://marep.sk/donate
Tags: newsletter, email, wpforo, forum, automation, smtp, digest, weekly
Requires at least: 5.0
Tested up to: 6.4
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

Automatically sends weekly newsletters with WordPress posts and wpForo forum posts to all users with customizable design.

== Description ==

Weekly Newsletter Sender is a comprehensive WordPress plugin that automatically sends beautiful weekly newsletters containing WordPress blog posts and wpForo forum activity to your community members.

= Key Features =

* **Automated Weekly Newsletters** - Scheduled delivery of weekly digests
* **Dual Content Sources** - Combines WordPress blog posts and wpForo forum discussions
* **Smart Content Aggregation** - Collects content from the past week automatically
* **Responsive Email Design** - Beautiful, mobile-friendly newsletter templates
* **SMTP Support** - Full SMTP configuration with encryption (TLS/SSL)
* **Secure Credentials** - Encrypted password storage using WordPress salts
* **Delivery Testing** - Send test emails to verify configuration
* **Email Design System** - Customizable colors, typography, and layout
* **Content Control** - Toggle WordPress posts and forum content on/off
* **User Role Targeting** - Select which user roles receive newsletters
* **WordPress Dashboard Integration** - Full admin panel in WordPress backend
* **Live Preview** - Real-time newsletter preview before sending
* **Manual Send Options** - Send newsletters immediately or test specific date ranges

= Email Delivery Options =

* **WordPress Native Mail** - Uses WordPress's built-in wp_mail() function
* **SMTP Support** - Professional SMTP configuration with popular providers
* **Secure Storage** - Encrypted password storage using WordPress salts
* **Test Functionality** - Verify your email configuration works

= Advanced Customization =

* **Colors** - Header, accent, text, and background colors
* **Typography** - Font family and line height options  
* **Layout** - Content padding, border radius, and card styling
* **Mobile-Responsive** - Optimized for all devices
* **Content Filtering** - Choose what content to include
* **Scheduling** - Set custom day and time for delivery

= Security & Performance =

* **Password Encryption** - SMTP passwords encrypted using WordPress salts
* **SQL Injection Protection** - All database queries use prepared statements
* **Memory Optimized** - Efficient handling of large subscriber lists
* **Error Logging** - Comprehensive logging for troubleshooting

= Requirements =

* WordPress 5.0 or higher
* PHP 7.4 or higher
* wpForo Plugin (optional, for forum content integration)
* MySQL 5.6 or higher

== Installation ==

1. Upload the plugin files to the `/wp-content/plugins/weekly-newsletter-sender` directory, or install the plugin through the WordPress plugins screen directly.
2. Activate the plugin through the 'Plugins' screen in WordPress.
3. Navigate to Newsletter in your WordPress admin menu.
4. Accept the license agreement.
5. Configure your newsletter settings, including schedule and email delivery method.
6. Customize your email design if desired.
7. Send a test email to verify everything works correctly.

= Manual Installation =

1. Download the plugin ZIP file.
2. Go to Plugins > Add New in WordPress admin.
3. Click Upload Plugin and choose the ZIP file.
4. Click Install Now and then Activate Plugin.
5. Navigate to Newsletter in your admin menu to configure.

== Frequently Asked Questions ==

= Does this plugin work without wpForo? =

Yes! While the plugin integrates beautifully with wpForo for forum content, it works perfectly fine with just WordPress blog posts. You can disable forum content in the settings.

= How do I configure SMTP for reliable email delivery? =

Go to Newsletter > Configuration tab and select SMTP as your mail method. Enter your SMTP server details (host, port, username, password, encryption). Popular providers like Gmail, Outlook, and SendGrid are supported.

= Can I customize the email design? =

Absolutely! The plugin includes a comprehensive email design system. You can customize colors, fonts, layout, and more in the Email Design tab with live preview.

= How often are newsletters sent? =

By default, newsletters are sent weekly. You can configure the specific day of the week and time in the settings. Manual sending is also available.

= What user roles receive newsletters? =

You can select which user roles receive newsletters in the settings. By default, subscribers and administrators are included, but you can customize this.

= Is the plugin GDPR compliant? =

Yes, the plugin handles user data according to WordPress standards and doesn't collect any external data. All email addresses come from your existing WordPress users.

= Can I preview newsletters before sending? =

Yes! The plugin includes a live preview feature that shows exactly what recipients will receive, using your current settings and latest content.

= What happens if my SMTP configuration is wrong? =

The plugin includes test email functionality. You can send test emails to verify your configuration before sending newsletters to all users.

= Does this work with caching plugins? =

Yes, the plugin is compatible with popular caching plugins. Newsletter generation and sending happen in the background and don't interfere with page caching.

= Can I translate the plugin? =

Yes, the plugin is translation-ready with proper text domain and localization. Translation files can be added to the languages directory.

== Screenshots ==

1. Main settings interface with scheduling and configuration options
2. Email design customization panel with live preview  
3. SMTP configuration for reliable email delivery
4. Newsletter preview showing WordPress posts and forum content
5. Admin interface showing all available tabs and options

== Changelog ==

= 1.0.0 - 2025-09-14 =
* Initial release
* Complete newsletter automation system
* Full admin interface with tabbed navigation
* SMTP and WordPress mail support
* Customizable email design system
* Security features and password encryption
* Responsive email templates
* wpForo forum integration
* Performance optimizations
* Comprehensive documentation

== Upgrade Notice ==

= 1.0.0 =
Initial release of Weekly Newsletter Sender. Install to start sending beautiful automated newsletters to your community.

== Third Party Services ==

This plugin integrates with the following third-party services:

= wpForo =
* **Service**: wpForo forum plugin integration
* **Purpose**: Collect forum posts and topics for newsletter content
* **Data**: Forum post titles, content, authors, and dates
* **Website**: https://wpforo.com/
* **Privacy**: No data sent to external servers

= SMTP Providers (Optional) =
When using SMTP delivery, you may connect to email service providers such as:
* Gmail (smtp.gmail.com)
* Outlook (smtp-mail.outlook.com)  
* SendGrid (smtp.sendgrid.net)
* Other SMTP providers

**Data Transmitted**: Email content, recipient addresses, sender credentials
**Privacy**: Follow your chosen provider's privacy policy
**Security**: All credentials stored encrypted locally

== Support ==

For support, documentation, and updates:

* **Documentation**: https://marep.sk/docs/weekly-newsletter-sender
* **Support Email**: support@marep.sk
* **Knowledge Base**: https://marep.sk/kb/weekly-newsletter-sender

== Donations ==

If this plugin has helped you save time and improve your newsletter workflow, please consider supporting its development:

* **Donate**: https://marep.sk/donate
* **PayPal**: https://paypal.me/marepsk
* **Buy me a coffee**: https://buymeacoffee.com/marep

Your support helps maintain and improve this plugin for the entire WordPress community. Thank you!

== Development ==

* **GitHub Repository**: https://github.com/matejrepka/weekly-newsletter-sender
* **Bug Reports**: https://github.com/matejrepka/weekly-newsletter-sender/issues
* **Feature Requests**: support@marep.sk

== Credits ==

Developed by Marep (https://marep.sk)