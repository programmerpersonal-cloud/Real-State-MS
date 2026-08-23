<?php
/**
 * Saxane Real Estate Management System
 * CSRF Protection
 */

/**
 * Generate a CSRF token and store it in the session.
 */
function generateCSRFToken(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Output a hidden CSRF input field for forms.
 */
function csrfField(): string
{
    $token = generateCSRFToken();
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($token) . '">';
}

/**
 * Validate the submitted CSRF token.
 */
function validateCSRFToken(): bool
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return true;
    }

    $token = $_POST['csrf_token'] ?? '';
    if (empty($token) || !hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        return false;
    }

    // Regenerate token after successful validation
    unset($_SESSION['csrf_token']);
    return true;
}

/**
 * Enforce CSRF validation — die on failure.
 */
function enforceCSRF(): void
{
    if (!validateCSRFToken()) {
        http_response_code(403);
        die('Security validation failed. Please refresh and try again.');
    }
}

/**
 * Refuse anything that is not a POST.
 *
 * enforceCSRF() alone does not do this: validateCSRFToken() passes every
 * non-POST request straight through, on the reasonable assumption that a GET
 * is a read. An action that changes state therefore has to say so itself —
 * without this, `?action=approve&id=7` typed into the address bar, left in a
 * bookmark, or followed by a link prefetcher sails past the CSRF check with
 * no token at all, because there was no POST body to check.
 *
 * Call it immediately before enforceCSRF() in any action that writes.
 */
function requirePost(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        http_response_code(405);
        header('Allow: POST');
        die('This action must be submitted from the application.');
    }
}
