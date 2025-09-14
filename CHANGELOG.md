# Changelog

All notable changes to the Weekly Newsletter Sender plugin will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.0.0] - 2025-09-14

### Added
- **Initial Release** - Complete newsletter automation system
- **Dual Content Integration** - WordPress posts and wpForo forum content
- **Automated Scheduling** - Weekly newsletter delivery with custom day/time
- **Email Design System** - Comprehensive customization options
  - Custom colors (header, accent, text, background)
  - Typography settings (font family, line height)
  - Layout controls (padding, border radius, card styling)
  - Responsive email templates
- **Multiple Delivery Methods**
  - WordPress native mail support
  - Full SMTP configuration with TLS/SSL encryption
  - Secure password storage using WordPress salts
- **Advanced Admin Interface**
  - Tabbed configuration panel
  - Live newsletter preview
  - Manual send options
  - Test email functionality
- **User Management**
  - Role-based recipient targeting
  - Support for subscriber and administrator roles
- **Content Control**
  - Toggle WordPress posts on/off
  - Toggle forum content on/off
  - Custom date range selection
  - Smart content aggregation
- **Security Features**
  - Password encryption for SMTP credentials
  - SQL injection protection with prepared statements
  - File integrity monitoring
  - Nonce verification for all forms
  - Capability checks for admin functions
- **Performance Optimizations**
  - Memory-efficient handling of large subscriber lists
  - Optimized database queries
  - Error logging and debugging support
- **License Management**
  - Built-in license acceptance system
  - Proprietary software protection
  - File modification detection

### Security
- Implemented comprehensive security measures
- Added password encryption using WordPress salts
- Protected against SQL injection attacks
- Added file integrity monitoring system
- Implemented proper capability checks

### Technical
- **Minimum Requirements**: WordPress 5.0+, PHP 7.4+
- **Database Integration**: Optimized queries for WordPress and wpForo tables
- **Hook System**: Extensible with WordPress hooks and filters
- **Internationalization**: Ready for translation (text domain: weekly-newsletter-sender)
- **Multisite**: Not supported in initial release

### Documentation
- Complete installation and configuration guide
- SMTP setup instructions for major providers
- Troubleshooting section
- Security best practices
- API documentation for developers

---

## Version History

### Pre-release Development
- Multiple internal versions during development
- Security audits and code reviews
- Performance testing with large user bases
- Compatibility testing across WordPress versions

---

## Planned Features

### [1.1.0] - Future Release
- Multi-language support with translation files
- Additional email templates
- Advanced content filtering options
- Integration with more forum plugins
- Improved mobile email rendering

### [1.2.0] - Future Release  
- WordPress Multisite support
- Custom post type integration
- Advanced analytics and reporting
- A/B testing for email designs
- Subscriber management interface

### [2.0.0] - Major Release
- Visual email designer
- Automation workflows
- Advanced segmentation
- Newsletter archives
- API for third-party integrations

---

## Support and Maintenance

- **Bug Fixes**: Critical issues addressed within 48 hours
- **Feature Requests**: Evaluated and prioritized based on user feedback
- **Security Updates**: Released immediately when necessary
- **Compatibility**: Maintained with latest WordPress versions

For support, contact: support@marep.sk
For feature requests: https://marep.sk/plugins/weekly-newsletter-sender

---

## License

This software is proprietary. All rights reserved.
Unauthorized modification, distribution, or reverse engineering is prohibited.