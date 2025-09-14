
# Weekly Newsletter Sender for wpForo

A comprehensive WordPress plugin that automatically sends weekly newsletters containing WordPress blog posts and wpForo forum activity to your community members.

![Plugin Version](https://img.shields.io/badge/version-1.1-blue.svg)
![WordPress Compatibility](https://img.shields.io/badge/wordpress-5.0%2B-green.svg)
![PHP Version](https://img.shields.io/badge/php-7.4%2B-purple.svg)

## 🚀 Features

### Core Functionality
- **Automated Weekly Newsletters**: Scheduled delivery of weekly digests
- **Dual Content Sources**: Combines WordPress blog posts and wpForo forum discussions
- **Smart Content Aggregation**: Collects content from the past week automatically
- **Responsive Email Design**: Beautiful, mobile-friendly newsletter templates

### Email Delivery Options
- **WordPress Native Mail**: Uses WordPress's built-in `wp_mail()` function
- **SMTP Support**: Full SMTP configuration with encryption (TLS/SSL)
- **Secure Credentials**: Encrypted password storage using WordPress salts
- **Delivery Testing**: Send test emails to verify configuration

### Advanced Customization
- **Email Design System**: 
  - Customizable colors (header, accent, text, background)
  - Typography options (font family, line height)
  - Layout settings (padding, border radius, card styling)
  - Mobile-responsive design
- **Content Control**:
  - Toggle WordPress posts on/off
  - Toggle forum content on/off
  - User role targeting (subscribers, administrators, etc.)
  - Custom date range selection

### Admin Interface
- **WordPress Dashboard Integration**: Full admin panel in WordPress backend
- **Live Preview**: Real-time newsletter preview before sending
- **Manual Send Options**: Send newsletters immediately or test specific date ranges
- **Settings Management**: Comprehensive configuration interface
- **Scheduling Control**: Set custom day and time for automatic delivery

### Security & Performance
- **Password Encryption**: SMTP passwords encrypted using WordPress security salts
- **Safe Database Queries**: Prepared statements prevent SQL injection
- **Error Logging**: Comprehensive logging for troubleshooting
- **Memory Optimization**: Efficient handling of large subscriber lists

## 📋 Requirements

- **WordPress**: 5.0 or higher
- **PHP**: 7.4 or higher
- **wpForo Plugin**: Latest version (for forum content)
- **MySQL**: 5.6 or higher
- **PHP Extensions**:
  - `openssl` (for password encryption)
  - `mbstring` (for email handling)
  - Optional: `curl` (for SMTP delivery)

## 🔧 Installation

### Method 1: Upload Plugin Files
1. Download the plugin files
2. Upload `weekly-newsletter-sender.php` to your `/wp-content/plugins/` directory
3. Activate the plugin through the 'Plugins' menu in WordPress
4. Navigate to **Weekly Newsletter** in your WordPress admin menu

### Method 2: WordPress Admin Upload
1. Go to **Plugins > Add New** in WordPress admin
2. Click **Upload Plugin**
3. Choose the plugin file and click **Install Now**
4. Activate the plugin

## ⚙️ Configuration

### Basic Setup
1. **Navigate to Settings**: Go to **Weekly Newsletter** in your WordPress admin
2. **Enable the Newsletter**: Check "Enable weekly newsletter sending"
3. **Set Schedule**: Choose day of week and time for automatic sending
4. **Configure Recipients**: Select which user roles should receive newsletters

### Email Configuration

#### Using WordPress Mail (Default)
- No additional configuration needed
- Uses your WordPress site's mail settings

#### Using SMTP (Recommended for Reliability)
1. **Email Method**: Select "SMTP" 
2. **SMTP Settings**:
   - **Host**: Your SMTP server (e.g., `smtp.gmail.com`)
   - **Port**: Usually `587` (TLS) or `465` (SSL)
   - **Username**: Your email address
   - **Password**: Your email password or app password
   - **Encryption**: `TLS` (recommended) or `SSL`
   - **From Name**: Display name for sender

#### Popular SMTP Providers
- **Gmail**: `smtp.gmail.com`, Port `587`, TLS
- **Outlook**: `smtp-mail.outlook.com`, Port `587`, TLS  
- **Yahoo**: `smtp.mail.yahoo.com`, Port `587`, TLS
- **SendGrid**: `smtp.sendgrid.net`, Port `587`, TLS

### Design Customization
Customize your newsletter appearance in the **Email Design** section:
- **Colors**: Header, accent, text, background
- **Typography**: Font family, line height
- **Layout**: Content padding, card styling, border radius

### Content Settings
- **Include WordPress Posts**: Toggle blog post inclusion
- **Include Forum Content**: Toggle wpForo forum content
- **User Roles**: Select which user roles receive newsletters

## 🎯 Usage

### Automatic Sending
Once configured, newsletters are sent automatically according to your schedule. The plugin:
1. Collects WordPress posts from the past week
2. Gathers wpForo forum activity from the past week
3. Generates a beautifully formatted newsletter
4. Sends to all users with selected roles

### Manual Sending
- **Send Now**: Send immediately with current week's content
- **Custom Date Range**: Send newsletter for specific date range
- **Test Email**: Send test newsletter to administrator email

### Preview Newsletter
Use the preview feature to see how your newsletter will look before sending.

## 🔒 Security Features

### Password Protection
- SMTP passwords are encrypted using WordPress security salts
- Passwords stored securely in WordPress options table
- Automatic decryption only when needed for sending

### Constants Override
For enhanced security, you can define SMTP settings in `wp-config.php`:

```php
// SMTP Configuration
define('WNS_SMTP_HOST', 'smtp.gmail.com');
define('WNS_SMTP_PORT', '587');
define('WNS_SMTP_USERNAME', 'your-email@gmail.com');
define('WNS_SMTP_PASSWORD', 'your-app-password');
define('WNS_SMTP_ENCRYPTION', 'tls');
```

## 🛠️ Troubleshooting

### Common Issues

#### Newsletter Not Sending
1. **Check WordPress Cron**: Ensure WordPress cron is working
2. **Verify Schedule**: Confirm day/time settings are correct
3. **Check Error Logs**: Look for error messages in WordPress debug log
4. **Test Email Settings**: Use the test email feature

#### SMTP Connection Failed
1. **Verify Credentials**: Double-check username/password
2. **Check Port/Encryption**: Ensure correct port and encryption settings
3. **Firewall**: Verify SMTP ports aren't blocked
4. **App Passwords**: Use app-specific passwords for Gmail/Outlook

#### Missing Content
1. **Date Range**: Verify content exists in the specified date range
2. **wpForo Installation**: Ensure wpForo plugin is active
3. **Database Tables**: Confirm wpForo tables exist
4. **User Permissions**: Check if users have appropriate roles

### Debug Mode
Add this to `wp-config.php` for detailed logging:
```php
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
```

## 📊 Technical Details

### Database Tables Used
- `wp_users` - User information and email addresses
- `wp_usermeta` - User roles and metadata
- `wp_posts` - WordPress blog posts
- `wp_wpforo_posts` - Forum posts
- `wp_wpforo_topics` - Forum topics
- `wp_wpforo_forums` - Forum categories

### Hooks & Filters
The plugin provides several hooks for customization:
- `wns_email_content` - Filter newsletter content
- `wns_email_subject` - Filter email subject line
- `wns_recipient_list` - Filter recipient list

### Performance Considerations
- Newsletter generation is memory-optimized for large user bases
- Database queries use prepared statements
- Content is cached during generation process
- Large recipient lists are processed in batches

## 🔄 Updates & Maintenance

### Keeping Plugin Updated
- Regularly check for plugin updates
- Backup your settings before major updates
- Test newsletter functionality after updates

### Monitoring
- Check WordPress cron status regularly
- Monitor email delivery rates
- Review error logs periodically

## 🤝 Support & Contributing

### Getting Help
- Check the troubleshooting section above
- Review WordPress error logs
- Verify plugin configuration settings

### Author Information
- **Plugin Name**: Weekly Newsletter Sender
- **Version**: 2.0
- **Author**: Marep
- **Author URI**: [https://marep.sk](https://marep.sk)

## 📄 License

This plugin is released under the GPL v2 or later license. You are free to use, modify, and distribute this plugin according to the terms of the GNU General Public License.

---

**Note**: This plugin requires wpForo to be installed and active for forum content integration. WordPress blog post integration works independently.
