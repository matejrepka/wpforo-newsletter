<?php
/**
 * Database Configuration
 * 
 * IMPORTANT: For production, move these credentials to environment variables
 * or a separate config file outside the web root
 */

// Prevent direct access
if (!defined('DB_CONFIG_INCLUDED')) {
    define('DB_CONFIG_INCLUDED', true);
}

// Database credentials
$servername = getenv('DB_HOST') ?: "localhost";
$username = getenv('DB_USER') ?: "root";
$password = getenv('DB_PASS') ?: "";
$dbname = getenv('DB_NAME') ?: "newsletter";

// Database connection settings
$db_charset = "utf8mb4";
$db_options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

/**
 * SQL to create the waitlist table (run this once):
 * 
 * CREATE TABLE IF NOT EXISTS `waitlist` (
 *   `id` INT(11) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 *   `email` VARCHAR(255) NOT NULL UNIQUE,
 *   `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 *   `ip_address` VARCHAR(45) DEFAULT NULL,
 *   INDEX `idx_email` (`email`),
 *   INDEX `idx_created` (`created_at`)
 * ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
 */
?>