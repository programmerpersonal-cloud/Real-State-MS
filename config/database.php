<?php
/**
 * Saxane Real Estate Management System
 * Database Configuration & Connection
 * 
 * Uses PDO with prepared statements for SQL injection prevention.
 * All connections use utf8mb4 charset for full Unicode support.
 */

define('DB_HOST', 'localhost');
define('DB_NAME', 'saxane_realestate');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_CHARSET', 'utf8mb4');

/**
 * Get a PDO database connection instance.
 * Uses singleton pattern to avoid multiple connections.
 *
 * @return PDO
 * @throws PDOException
 */
function getDBConnection(): PDO
{
    static $pdo = null;

    if ($pdo === null) {
        $dsn = sprintf(
            'mysql:host=%s;dbname=%s;charset=%s',
            DB_HOST,
            DB_NAME,
            DB_CHARSET
        );

        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            // Emulation must be ON so a named placeholder (e.g. :s in search
            // queries) can be reused across multiple LIKE clauses. Native
            // prepares forbid reusing a placeholder and throw HY093.
            // Values are still fully parametrized/quoted, so this is SQLi-safe.
            PDO::ATTR_EMULATE_PREPARES   => true,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci",
        ];

        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            // In production, log this and show a generic error page
            error_log('Database Connection Error: ' . $e->getMessage());
            die('System is temporarily unavailable. Please try again later.');
        }
    }

    return $pdo;
}
