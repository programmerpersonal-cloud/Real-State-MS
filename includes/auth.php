<?php
/**
 * Saxane Real Estate Management System
 * Authentication Helpers
 */

/**
 * Check if user is currently logged in.
 */
function isLoggedIn(): bool
{
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

/**
 * Get current logged-in user data from session.
 */
function getCurrentUser(): ?array
{
    if (!isLoggedIn()) {
        return null;
    }

    return [
        'id'        => $_SESSION['user_id'],
        'full_name' => $_SESSION['user_name'] ?? '',
        'email'     => $_SESSION['user_email'] ?? '',
        'role'      => $_SESSION['user_role'] ?? '',
        'role_id'   => $_SESSION['user_role_id'] ?? 0,
        'avatar'    => $_SESSION['user_avatar'] ?? null,
        'branch_id' => $_SESSION['user_branch_id'] ?? null,
    ];
}

/**
 * Get current user's role name.
 */
function getUserRole(): string
{
    return $_SESSION['user_role'] ?? '';
}

/**
 * Require the user to be logged in. Redirects to login if not.
 */
function requireLogin(): void
{
    if (!isLoggedIn()) {
        setFlash('error', 'Please log in to access this page.');
        redirect(APP_URL . '/index.php?page=login');
    }
    checkSessionTimeout();
}

/**
 * Require one of a set of roles.
 *
 * Superseded by authorize() in permissions.php, which names the action rather
 * than the job title and so survives a role gaining or losing one capability.
 * Kept for the routes that have not been migrated, and it refuses the same way
 * authorize() does: a 403 that explains itself, never a silent bounce to the
 * dashboard that reads as the application being broken.
 */
function requireRole(string ...$roles): void
{
    requireLogin();
    if (!in_array(getUserRole(), $roles, true)) {
        denyAccess();
    }
}

/**
 * Check if current user has one of the specified roles.
 */
function hasRole(string ...$roles): bool
{
    return in_array(getUserRole(), $roles);
}

/**
 * Set user session data after successful login.
 */
function setUserSession(array $user, string $roleName): void
{
    regenerateSession();
    $_SESSION['user_id']        = $user['id'];
    $_SESSION['user_name']      = $user['full_name'];
    $_SESSION['user_email']     = $user['email'];
    $_SESSION['user_role']      = $roleName;
    $_SESSION['user_role_id']   = $user['role_id'];
    $_SESSION['user_avatar']    = $user['avatar'];
    $_SESSION['user_branch_id'] = $user['branch_id'];
    $_SESSION['last_activity']  = time();
}
