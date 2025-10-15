-- Database Setup Script for Weekly Newsletter Sender
-- Run this script to set up the database

-- Create database
CREATE DATABASE IF NOT EXISTS newsletter 
CHARACTER SET utf8mb4 
COLLATE utf8mb4_unicode_ci;

-- Use the database
USE newsletter;

-- Create waitlist table
CREATE TABLE IF NOT EXISTS `waitlist` (
  `id` INT(11) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `email` VARCHAR(255) NOT NULL UNIQUE,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `ip_address` VARCHAR(45) DEFAULT NULL,
  `user_agent` VARCHAR(255) DEFAULT NULL,
  `status` ENUM('pending', 'confirmed', 'unsubscribed') DEFAULT 'pending',
  INDEX `idx_email` (`email`),
  INDEX `idx_created` (`created_at`),
  INDEX `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Create admin user (optional - for management dashboard)
CREATE TABLE IF NOT EXISTS `admin_users` (
  `id` INT(11) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `username` VARCHAR(50) NOT NULL UNIQUE,
  `password_hash` VARCHAR(255) NOT NULL,
  `email` VARCHAR(255) NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `last_login` TIMESTAMP NULL DEFAULT NULL,
  INDEX `idx_username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insert sample admin user (password: admin123 - CHANGE THIS!)
-- Password hash for 'admin123' - change this immediately!
INSERT INTO `admin_users` (`username`, `password_hash`, `email`) VALUES
('admin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin@example.com')
ON DUPLICATE KEY UPDATE username=username;

-- Create email log table (for tracking sent emails)
CREATE TABLE IF NOT EXISTS `email_log` (
  `id` INT(11) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `email` VARCHAR(255) NOT NULL,
  `subject` VARCHAR(255) DEFAULT NULL,
  `sent_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `status` ENUM('sent', 'failed', 'bounced') DEFAULT 'sent',
  `ip_address` VARCHAR(45) DEFAULT NULL,
  INDEX `idx_email` (`email`),
  INDEX `idx_sent_at` (`sent_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Grant privileges (adjust user as needed)
-- GRANT ALL PRIVILEGES ON newsletter.* TO 'newsletter_user'@'localhost' IDENTIFIED BY 'secure_password';
-- FLUSH PRIVILEGES;

-- Verify tables were created
SHOW TABLES;

-- Display structure
DESCRIBE waitlist;
DESCRIBE admin_users;
DESCRIBE email_log;

-- Sample queries for management

-- View all subscribers
-- SELECT * FROM waitlist ORDER BY created_at DESC;

-- Count subscribers by status
-- SELECT status, COUNT(*) as count FROM waitlist GROUP BY status;

-- Recent signups (last 7 days)
-- SELECT * FROM waitlist WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY);

-- Export emails for mailing list
-- SELECT email FROM waitlist WHERE status = 'confirmed' ORDER BY created_at;
