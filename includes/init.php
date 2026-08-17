<?php
/**
 * Saxane Real Estate Management System
 * Bootstrap / Init File
 *
 * This file is the single entry point for loading all dependencies.
 * Every page should include this file first.
 */

// Load configuration
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';

// Load core includes
require_once __DIR__ . '/session.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/functions.php';

// ─── Company identity, resolved from Settings ──────────────────────────
// Defined here (after functions.php, before anything that reads them) so the
// ~60 templates and schema builders that already reference these constants
// become admin-configurable without each one having to query the database.
// Falls back to config/app.php when a value is blank or the DB is unreachable.
define('BIZ_LEGAL_NAME', companyLegalName());
define('BIZ_PHONE',      companyPhone());
define('BIZ_EMAIL',      companyEmail());

require_once __DIR__ . '/content.php';
require_once __DIR__ . '/seo.php';
require_once __DIR__ . '/auth.php';

// Levels 1 and 2 of authorization — may this role open the module, may this
// user perform the action. Loaded immediately after auth.php because it reads
// the signed-in role, and before everything below because the navigation,
// controllers and views all ask it first.
require_once __DIR__ . '/permissions.php';

// The menu, as data — read by the sidebar rail and by the header's global
// search. After permissions.php because every entry is gated by canAccessPage().
require_once __DIR__ . '/navigation.php';

// Loaded after auth.php: all three read the signed-in role through
// getUserRole()/hasRole().
require_once __DIR__ . '/documents.php';
require_once __DIR__ . '/legal.php';
require_once __DIR__ . '/property_access.php';

// Security headers
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('X-XSS-Protection: 1; mode=block');
header('Referrer-Policy: strict-origin-when-cross-origin');
