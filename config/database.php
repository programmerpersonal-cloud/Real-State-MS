<?php
/**
 * Saxane Real Estate Management System
 * Database Configuration & Connection
 * 
 * Uses PDO with prepared statements for SQL injection prevention.
 * All connections use utf8mb4 charset for full Unicode support.
 */

require_once __DIR__ . '/../includes/env.php';

// Credentials come from .env so they are never committed. The defaults are the
// stock XAMPP development values, which keeps a fresh clone working locally
// with no .env present; a real deployment must supply its own.
define('DB_HOST',    env('DB_HOST', 'localhost'));
define('DB_PORT',    env_int('DB_PORT', 3306));
define('DB_NAME',    env('DB_NAME', 'saxane_realestate'));
define('DB_USER',    env('DB_USER', 'root'));
define('DB_PASS',    (string) env('DB_PASS', ''));
define('DB_CHARSET', env('DB_CHARSET', 'utf8mb4'));

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
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            DB_HOST,
            DB_PORT,
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
            error_log('Database Connection Error: ' . $e->getMessage());

            // With APP_DEBUG on, show the real reason: a mistyped DB_PASS in
            // .env is the usual cause and the generic message below gives no
            // clue which of host, name, user or password is wrong. Never shown
            // when APP_DEBUG is off, because the text leaks the schema name.
            if (defined('APP_DEBUG') && APP_DEBUG) {
                die(
                    '<h1>Database connection failed</h1>'
                    . '<p>' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</p>'
                    . '<p>Check DB_HOST, DB_NAME, DB_USER and DB_PASS in your <code>.env</code> file.</p>'
                );
            }

            die('System is temporarily unavailable. Please try again later.');
        }
    }

    return $pdo;
}
