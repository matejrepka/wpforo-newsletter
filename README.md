
# Weekly Newsletter Sender for wpForo

This project provides a PHP script (`weekly-newsletter-sender.php`) designed to automate the sending of weekly newsletters to users of a WordPress site using the **wpForo** forum plugin.

## What It Does

- Collects user emails from the wpForo plugin database tables.
- Generates and sends a weekly newsletter to all forum members.
- Can be customized to include forum highlights, announcements, or any content relevant to your community.

## Usage

1. Configure your email sending logic and newsletter content in `weekly-newsletter-sender.php`.
2. Make sure your script can access the WordPress and wpForo database tables.
3. Run the script using PHP:

```sh
php weekly-newsletter-sender.php
```

## Requirements

- PHP 7.0 or higher
- WordPress with the wpForo plugin installed and active
- Database access credentials
- Any required PHP extensions for sending emails (e.g., `mail`, `PHPMailer`, etc.)

## License
Specify your license here.
