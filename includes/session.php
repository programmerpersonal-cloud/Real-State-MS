<?php
/**
 * Saxane Real Estate Management System
 * Session Management
 */

if (session_status() === PHP_SESSION_NONE) {
    // Secure session cookie parameters
    session_set_cookie_params([
        'lifetime' => SESSION_LIFETIME,
        'path'     => '/',
        'domain'   => '',
        'secure'   => false, // Set true in production with HTTPS
        'httponly'  => true,
        'samesite'  => 'Lax',
    ]);

    session_start();
}

/**
 * Regenerate session ID on privilege change (login).
 */
function regenerateSession(): void
{
    session_regenerate_id(true);
}

/**
 * Check if session has timed out.
 */
function checkSessionTimeout(): void
{
    if (isset($_SESSION['last_activity'])) {
        $elapsed = time() - $_SESSION['last_activity'];
        if ($elapsed > SESSION_LIFETIME) {
            session_unset();
            session_destroy();
            redirect(APP_URL . '/index.php?page=login&timeout=1');
        }
    }
    $_SESSION['last_activity'] = time();
}

/**
 * Destroy session completely.
 */
function destroySession(): void
{
    $_SESSION = [];
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }
    session_destroy();
}
