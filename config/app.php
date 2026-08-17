<?php
/**
 * Saxane Real Estate Management System
 * Application Configuration
 */

// ─── Application Identity ──────────────────────────────────────────────
// APP_NAME is the fallback trading name only. Anything user-facing must call
// companyName(), which reads Settings → Company Profile and falls back here.
define('APP_NAME', 'Saxane');
define('APP_TAGLINE', 'Real Estate Management System');
define('APP_VERSION', '1.0.0');
// Detected from the incoming request so the app works over localhost, a LAN IP
// (phone testing), or a real domain without editing this file.
define('APP_URL', (function () {
    if (PHP_SAPI === 'cli') {
        return 'http://localhost/Real-State-MS/Real-State-MS';
    }
    $https  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['SERVER_PORT'] ?? '') === '443');
    $scheme = $https ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    // Strip anything that isn't a valid host[:port] to avoid Host-header injection.
    $host   = preg_replace('/[^A-Za-z0-9\.\-\:\[\]]/', '', $host);
    // Directory the front controller lives in, e.g. /Real-State-MS/Real-State-MS
    $base   = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');

    return $scheme . '://' . $host . $base;
})());

// ─── Paths ─────────────────────────────────────────────────────────────
define('BASE_PATH', dirname(__DIR__));
define('ASSETS_PATH', BASE_PATH . '/assets');
define('UPLOADS_PATH', ASSETS_PATH . '/uploads');
define('VIEWS_PATH', BASE_PATH . '/views');

// URL helpers
define('ASSETS_URL', APP_URL . '/assets');
define('CSS_URL', ASSETS_URL . '/css');
define('JS_URL', ASSETS_URL . '/js');
define('IMG_URL', ASSETS_URL . '/img');
define('VENDOR_URL', ASSETS_URL . '/vendor'); // self-hosted third-party assets (no CDN)
define('UPLOADS_URL', ASSETS_URL . '/uploads');

// ─── Upload Constraints ────────────────────────────────────────────────
define('MAX_FILE_SIZE', 5 * 1024 * 1024); // 5MB
define('MAX_IMAGE_SIZE', 3 * 1024 * 1024); // 3MB
define('ALLOWED_IMAGE_TYPES', ['image/jpeg', 'image/png', 'image/webp', 'image/gif']);
define('ALLOWED_DOC_TYPES', ['application/pdf', 'image/jpeg', 'image/png', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document']);

// ─── Document store ────────────────────────────────────────────────────
// Property documents are deliberately kept out of assets/uploads: that tree
// is served straight off disk by Apache, which is fine for listing photos and
// wrong for a title deed. Everything below is delivered by
// index.php?page=documents&action=download after an authorisation check.
//
// ALLOWED_DOC_TYPES above is left alone on purpose — LeaseController still
// uses it for contract files that live under assets/uploads/documents, and
// widening it there would widen what can be uploaded into a public directory.
define('DOCS_STORAGE_PATH', BASE_PATH . '/storage/documents');
define('MAX_DOCUMENT_SIZE', 10 * 1024 * 1024); // 10MB ceiling; Settings can lower it
define('ALLOWED_DOCUMENT_TYPES', [
    'application/pdf',
    'image/jpeg',
    'image/png',
    'image/webp',
    'application/msword',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'application/vnd.ms-excel',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
]);
// The stored extension is derived from the sniffed MIME type, never from the
// uploaded filename, so "deed.php" cannot become an executable file on disk.
define('DOCUMENT_EXT_BY_MIME', [
    'application/pdf'  => 'pdf',
    'image/jpeg'       => 'jpg',
    'image/png'        => 'png',
    'image/webp'       => 'webp',
    'application/msword' => 'doc',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
    'application/vnd.ms-excel' => 'xls',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
]);
// Types a browser may render in place. Anything else is force-downloaded, so
// a stored file can never execute in the site's own origin.
define('DOCUMENT_INLINE_TYPES', ['application/pdf', 'image/jpeg', 'image/png', 'image/webp']);

// ─── Security ──────────────────────────────────────────────────────────
define('SESSION_LIFETIME', 3600); // 1 hour
define('MAX_LOGIN_ATTEMPTS', 5);
define('LOCKOUT_DURATION', 900); // 15 minutes
define('BCRYPT_COST', 12);
// The minimum every password-setting screen enforces — user accounts, owner
// login access, and password changes — so the rule lives in one place.
define('PASSWORD_MIN_LENGTH', 8);

// ─── Business Defaults ─────────────────────────────────────────────────
define('DEFAULT_CURRENCY', 'USD');
define('DEFAULT_CURRENCY_SYMBOL', '$');
define('DEFAULT_TAX_RATE', 0.00);
define('DEFAULT_COMMISSION_RATE', 5.00);
define('LATE_FEE_PERCENTAGE', 2.00);
define('RESERVATION_EXPIRY_DAYS', 7);

// ─── Pagination ────────────────────────────────────────────────────────
define('ITEMS_PER_PAGE', 15);

// ─── Business identity (NAP) ───────────────────────────────────────────
// Name/Address/Phone must be byte-identical everywhere they appear — the
// public footer, the contact page, the schema markup and any external
// directory listing. Inconsistent NAP is the single most common reason a
// local business fails to rank in map results, so it is defined once here
// and read from everywhere rather than retyped per template.
//
// The three fields an admin can edit in Settings are declared as _DEFAULT
// here; includes/init.php resolves the live BIZ_LEGAL_NAME / BIZ_PHONE /
// BIZ_EMAIL from the settings table, using these when nothing is configured.
// The rest are still file-level configuration.
define('BIZ_LEGAL_NAME_DEFAULT', 'Saxane Real Estate');
define('BIZ_PHONE_DEFAULT',      '+252 63 331 1945');
define('BIZ_EMAIL_DEFAULT',      'Realstate@saxane.com');
define('BIZ_STREET',     'Xafiiska Majid Harawa, City Center');
define('BIZ_CITY',       'Borama');
define('BIZ_REGION',     'Awdal');
define('BIZ_POSTAL',     '');
define('BIZ_COUNTRY',    'SO');            // ISO 3166-1 alpha-2
define('BIZ_LAT',        9.9366);          // Borama city centre
define('BIZ_LNG',        43.1806);
define('BIZ_PRICE_RANGE', '$$');
// Opening hours as [days, opens, closes] in 24h local time.
define('BIZ_HOURS', [
    [['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Saturday'], '08:00', '17:00'],
    [['Sunday'], '09:00', '13:00'],
]);
// What we promise publicly and must therefore actually meet.
define('BIZ_RESPONSE_HOURS', 24);

// ─── Marketing / SEO ───────────────────────────────────────────────────
// Social preview image (1200x630). Referenced absolutely — relative paths
// are ignored by every social crawler.
define('SOCIAL_IMAGE', IMG_URL . '/social/og-default.jpg');
define('SOCIAL_IMAGE_W', 1200);
define('SOCIAL_IMAGE_H', 630);

// Google Analytics 4. Left empty by default: the tag is only emitted when a
// real measurement ID is set, so development traffic is never counted and
// no third-party script loads on a site that has not opted in.
define('GA_MEASUREMENT_ID', '');

// ─── User Roles ────────────────────────────────────────────────────────
define('ROLE_ADMIN', 'admin');
define('ROLE_AGENT', 'agent');
define('ROLE_CUSTOMER', 'customer');
define('ROLE_OWNER', 'owner');
define('ROLE_MAINTENANCE', 'maintenance');
