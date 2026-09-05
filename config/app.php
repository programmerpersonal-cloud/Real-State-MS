<?php
/**
 * Saxane Real Estate Management System
 * Application Configuration
 */

require_once __DIR__ . '/../includes/env.php';

// ─── Environment ───────────────────────────────────────────────────────
// APP_ENV names the deployment: 'local' while developing, 'production' on the
// live host. APP_DEBUG governs whether real error text reaches the browser and
// defaults to on only in local — so a .env copied to production without
// thinking still fails closed.
define('APP_ENV', env('APP_ENV', 'local'));
define('APP_DEBUG', env_bool('APP_DEBUG', APP_ENV === 'local'));

// Errors are logged either way. This only decides whether they are also
// printed. Nothing is switched on here that PHP had off — the assignment runs
// in one direction, silencing output in production and otherwise leaving the
// server's own php.ini in charge.
if (!APP_DEBUG) {
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
}
ini_set('log_errors', '1');

// ─── Application Identity ──────────────────────────────────────────────
// APP_NAME is the fallback trading name only. Anything user-facing must call
// companyName(), which reads Settings → Company Profile and falls back here.
define('APP_NAME', env('APP_NAME', 'Saxane'));
define('APP_TAGLINE', 'Real Estate Management System');
define('APP_VERSION', '1.0.0');
// Detected from the incoming request so the app works over localhost, a LAN IP
// (phone testing), or a real domain without editing this file.
define('APP_URL', (function () {
    // An explicit APP_URL in .env wins. It is the only thing that produces
    // correct absolute links from CLI (cron, sitemap generation) and behind a
    // reverse proxy, where the request headers describe the proxy, not the site.
    $configured = env('APP_URL');
    if (is_string($configured) && $configured !== '') {
        return rtrim($configured, '/');
    }

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

// ─── Message attachments ───────────────────────────────────────────────
// Narrower than the document store on purpose. A document is filed by staff
// against a record they are responsible for; a message attachment is sent by
// anyone in a conversation, including tenants and technicians on a phone. The
// list is therefore the smallest one that does the job — a photograph of a
// broken tap, or a PDF.
//
// Office formats are deliberately absent. They are containers that can carry
// macros, and nothing in a conversation needs them: a contract belongs in the
// document store, attached to the record it governs.
//
// Written as mime => extension, like DOCUMENT_EXT_BY_MIME, because the stored
// extension is derived from the sniffed type and never from the upload's name.
define('MESSAGE_ATTACHMENT_TYPES', [
    'image/jpeg'      => 'jpg',
    'image/png'       => 'png',
    'image/webp'      => 'webp',
    'application/pdf' => 'pdf',
]);

// One unified per-file ceiling rather than a table of per-type limits: the
// document store already works this way, and a single number is one thing to
// explain in an error message. Well under PHP's own 40M, so the application
// refuses a large file with a sentence rather than letting the request die.
define('MESSAGE_ATTACHMENT_MAX_SIZE', 10 * 1024 * 1024);

// Per message. Enforced server-side in ConversationMessage::create(); the
// form's `multiple` attribute is a convenience, not the limit.
define('MESSAGE_ATTACHMENT_MAX_COUNT', 5);

// Which of the allowed types may be shown in place. Images and audio: both
// are rendered by an element that treats the bytes as media and cannot execute
// them. A PDF opens in the browser's own viewer and is offered as a download
// instead, so nothing stored here is ever handed to the page as markup.
// video/webm is here for a reason worth writing down: finfo identifies a
// WebM/Matroska file by its *container*, not by the codecs inside it, so an
// audio-only recording from Chrome or Firefox sniffs as video/webm. Omitting
// it meant a recorded voice note was rendered into an <audio> element and then
// force-downloaded by the delivery endpoint — a player that could never play.
// It is delivered to a media element, under the same nosniff and
// `default-src 'none'; sandbox` headers as everything else, so nothing about
// it can execute either way.
define('MESSAGE_ATTACHMENT_INLINE_TYPES', [
    'image/jpeg', 'image/png', 'image/webp',
    'audio/webm', 'audio/ogg', 'audio/mpeg', 'audio/mp4', 'video/webm',
]);

// ─── Voice notes ───────────────────────────────────────────────────────
// A voice note is not a new kind of record. It is an ordinary message
// carrying an audio attachment, which is what lets it inherit the entire
// attachment security model — sniffed MIME, extension derived from the
// sniffed type, unguessable private filename, authorised delivery — rather
// than growing a parallel one.
//
// The list is separate from MESSAGE_ATTACHMENT_TYPES because the two are
// reached differently: the paperclip offers images and documents, the
// microphone produces exactly one of these. Merging them would let someone
// attach an audio file through the document picker, which is harmless but
// muddies what each control promises.
//
// What a browser actually produces varies — Chrome and Firefox record WebM,
// Safari records MP4/AAC — so all the plausible containers are listed and the
// server decides from the bytes, never from what the browser claimed.
define('MESSAGE_VOICE_TYPES', [
    'audio/webm' => 'webm',
    'audio/ogg'  => 'ogg',
    'audio/mpeg' => 'mp3',
    'audio/mp4'  => 'm4a',
    // finfo reports a WebM/Matroska container by its container type rather
    // than by the codec inside it, so an audio-only WebM commonly sniffs as
    // video/webm. Accepted here and stored with an audio extension: the
    // container is identical, and it is delivered to an <audio> element which
    // will not execute anything either way.
    'video/webm' => 'webm',
]);

// Voice notes are short by nature and are recorded, not chosen, so the ceiling
// is lower than a photograph's. Roughly ten minutes of Opus.
define('MESSAGE_VOICE_MAX_SIZE', 8 * 1024 * 1024);

// ─── Reactions ─────────────────────────────────────────────────────────
// The emoji a reaction may be. Server-side allow-list, so the column cannot
// become a dumping ground for arbitrary user text however the form is edited.
// Five is deliberate: enough to say the useful things, few enough to fit on
// one row of a phone without a scroller.
define('MESSAGE_REACTIONS', ['👍', '❤️', '😂', '😮', '😢']);

// ─── Presence ──────────────────────────────────────────────────────────
// How recently a user must have made a request to count as "Online".
//
// This is a request-based signal, not a live one: it is stamped when a
// signed-in user loads a page, and there is no socket, heartbeat or polling
// behind it. Two minutes is short enough that the label stays close to the
// truth — someone reading the same page for five minutes is reported as last
// seen five minutes ago, which is exactly what the server knows.
define('PRESENCE_ONLINE_WINDOW', 120);

// How often the stamp is rewritten. Without this every request would issue an
// UPDATE on `users`; with it, a burst of page loads costs one write a minute.
define('PRESENCE_WRITE_INTERVAL', 60);

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

// Social accounts, as an ordered [key => [name, icon, url]] map.
//
// PLACEHOLDER HANDLES. These are the URLs the authentication screen and the
// public footer link to when the settings table has nothing for that network;
// an administrator overrides any of them with a `social_<key>` setting, and a
// network with neither a setting nor a default here is simply not rendered.
// Replace the handles below with the agency's real profiles before launch.
//
// WhatsApp is deliberately absent: it is derived from BIZ_PHONE, because the
// number people are told to call and the number they message have to be the
// same one.
define('BIZ_SOCIAL', [
    'facebook'  => ['Facebook',  'bi-facebook',  'https://www.facebook.com/saxanerealestate'],
    'instagram' => ['Instagram', 'bi-instagram', 'https://www.instagram.com/saxanerealestate'],
    'linkedin'  => ['LinkedIn',  'bi-linkedin',  'https://www.linkedin.com/company/saxanerealestate'],
    'x'         => ['X',         'bi-twitter-x', 'https://x.com/saxanerealest'],
]);

// ─── Marketing / SEO ───────────────────────────────────────────────────
// Social preview image (1200x630). Referenced absolutely — relative paths
// are ignored by every social crawler.
define('SOCIAL_IMAGE', IMG_URL . '/social/og-default.jpg');
define('SOCIAL_IMAGE_W', 1200);
define('SOCIAL_IMAGE_H', 630);

// Google Analytics 4. Left empty by default: the tag is only emitted when a
// real measurement ID is set, so development traffic is never counted and
// no third-party script loads on a site that has not opted in.
define('GA_MEASUREMENT_ID', env('GA_MEASUREMENT_ID', ''));

// ─── User Roles ────────────────────────────────────────────────────────
define('ROLE_ADMIN', 'admin');
define('ROLE_AGENT', 'agent');
define('ROLE_CUSTOMER', 'customer');
define('ROLE_OWNER', 'owner');
define('ROLE_MAINTENANCE', 'maintenance');

// ─── Backups ───────────────────────────────────────────────────────────
//
// The backup root is the one directory in this application that must sit
// outside the web root entirely. storage/documents gets away with living
// under the project because Apache is told to refuse it (.htaccess, plus a
// RewriteRule); a backup archive is the whole database and every uploaded
// file in one downloadable object, so it does not get to depend on a config
// file staying correct. Set BACKUP_PATH in .env to an absolute path Apache
// does not serve.
//
// The default is one level above the project — right for the common XAMPP
// layout htdocs/<project>/<app> and wrong for a document root pointed
// straight at the application. backupRootIsExposed() in includes/backup.php
// checks the resolved path against DOCUMENT_ROOT on every dashboard load and
// raises a health warning rather than trusting this default silently.
define('BACKUP_PATH', rtrim(str_replace('\\', '/', (string) env('BACKUP_PATH', dirname(BASE_PATH) . '/private_storage/backups')), '/'));

// Subdirectories under BACKUP_PATH. `temp` holds a run in progress and is
// swept on failure; `restore` is where an archive is expanded during a
// restore. Neither ever holds a finished artefact — a file in temp/ is by
// definition incomplete, which is what lets cleanup be unconditional.
//
// `logs` holds the scheduler's own record of every tick. It lives here rather
// than beside the code because a scheduled run has no console: Task Scheduler
// and cron both discard stdout, so a failure written only to the terminal is a
// failure nobody will ever read. It is inside the backup root because that
// directory is already outside the web root and already guarded.
define('BACKUP_DIRS', ['full', 'database', 'files', 'temp', 'restore', 'logs']);

// Scheduler log rotation. One active file plus this many rotated copies, each
// capped at the size below. Bounded on purpose: an unattended process that
// appends forever is a disk-full incident waiting for the quietest possible
// moment to happen.
define('BACKUP_LOG_MAX_BYTES', 2 * 1024 * 1024);   // 2 MB
define('BACKUP_LOG_KEEP',      5);

// How long the scheduler may go without checking in before the health panel
// treats it as stopped. The runner is meant to tick every 5–15 minutes; an
// hour of silence means the task is disabled, the machine is off, or the
// runner is dying before it can log anything.
//
// Configurable because the right answer is a multiple of the tick interval,
// and a deployment that ticks hourly would otherwise spend most of its life
// being told the scheduler had stopped — a false alarm that repeats is how a
// real one gets ignored.
define('BACKUP_SCHEDULER_STALE_MINUTES', max(5, (int) env('BACKUP_SCHEDULER_STALE_MINUTES', 60)));

// The name of the Windows Task Scheduler entry the installer creates and the
// doctor looks for. One constant so the batch file, the diagnostics and the
// documentation cannot drift apart.
define('BACKUP_TASK_NAME', 'Saxane Backup Scheduler');

// External binaries. mysqldump and mysql ship with XAMPP; a hosting panel may
// put them elsewhere, hence the override. Resolved and checked by
// backupBinary(), which reports a missing binary as a health problem instead
// of failing at the moment somebody presses Create Backup.
define('MYSQLDUMP_BIN', (string) env('MYSQLDUMP_PATH', 'D:/XAMPP/mysql/bin/mysqldump.exe'));
define('MYSQL_BIN',     (string) env('MYSQL_PATH',     'D:/XAMPP/mysql/bin/mysql.exe'));

// What a files backup contains, relative to BASE_PATH. This is the whole
// list — everything a restore would need to put the business back, and
// nothing that regenerates itself. Caches, logs, sessions and the vendor
// tree are absent by design: backing up derived files inflates every archive
// for no recovery value.
define('BACKUP_FILE_SOURCES', [
    'assets/uploads',       // property images, avatars, lease attachments
    'storage/documents',    // deeds, contracts, receipts, message media
]);

// Names never captured, matched against the basename at any depth. Keeps
// editor litter and OS bookkeeping out of the archive.
define('BACKUP_EXCLUDE_NAMES', ['.DS_Store', 'Thumbs.db', 'desktop.ini', '.gitkeep']);

// The backup module's own tables, excluded from every dump.
//
// This is not an optimisation, it is a correctness requirement, and it was
// found by running a restore and watching the evidence disappear. These tables
// describe archives on disk; the database has no authority over them. Include
// them in a dump and a database restore rewrites the backup history to
// whatever it was when that dump was taken — which deletes the record of the
// emergency safety copy taken sixty seconds earlier, resurrects rows for
// archives long since swept, and leaves whichever backup was running at dump
// time frozen in 'running' forever. The one record you need after a bad
// restore is the one pointing at the way back, and it must not be inside the
// thing being rolled back.
//
// Excluded from structure as well as data, so a restore cannot drop them
// either. BackupManager::ensureOwnTables() recreates them from the migration
// if a bare-metal restore lands in a database that never had them.
define('BACKUP_OWN_TABLES', ['backups', 'backup_schedules', 'backup_restores', 'backup_locks']);

// A run that has not touched its lock for this long is presumed dead and may
// be taken over. Long enough that a slow dump on a big database is not
// declared stale mid-write; short enough that a crash does not block tonight's
// scheduled backup. The lease is extended by heartbeat while a run is alive.
define('BACKUP_LOCK_TTL', 1800);          // 30 minutes

// Ceiling on a single restore's SQL statement, and on how long a backup may
// run before the runner gives up. Both exist so a corrupt archive fails
// loudly instead of exhausting memory or hanging a cron slot forever.
define('BACKUP_MAX_RUNTIME', 3600);       // 1 hour

// A completed archive smaller than this is treated as a failed verification
// regardless of what the checksum says: an empty zip has a perfectly valid
// SHA-256, and "verified" has to mean "would restore", not "is unchanged".
define('BACKUP_MIN_ARCHIVE_BYTES', 512);

// How many recent runs the health check reads when deciding whether failures
// are repeating rather than isolated.
define('BACKUP_HEALTH_WINDOW', 10);
