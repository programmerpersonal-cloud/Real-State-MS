<?php
/**
 * Saxane Real Estate Management System
 * Utility Functions
 */

/**
 * Sanitize user input to prevent XSS.
 */
function sanitize(?string $input): string
{
    return htmlspecialchars(trim($input ?? ''), ENT_QUOTES, 'UTF-8');
}

/**
 * Redirect to a URL and exit.
 */
function redirect(string $url): void
{
    header("Location: $url");
    exit;
}

/**
 * Set a flash message in session.
 */
function setFlash(string $type, string $message): void
{
    $_SESSION['flash'] = [
        'type'    => $type,
        'message' => $message,
    ];
}

/**
 * Get and clear the flash message.
 */
function getFlash(): ?array
{
    $flash = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $flash;
}

/**
 * Render a flash message as HTML if one exists.
 */
function renderFlash(): string
{
    $flash = getFlash();
    if (!$flash) return '';

    $typeMap = [
        'success' => 'alert--success',
        'error'   => 'alert--danger',
        'warning' => 'alert--warning',
        'info'    => 'alert--info',
    ];

    $iconMap = [
        'success' => 'bi-check-circle-fill',
        'error'   => 'bi-exclamation-triangle-fill',
        'warning' => 'bi-exclamation-circle-fill',
        'info'    => 'bi-info-circle-fill',
    ];

    $class = $typeMap[$flash['type']] ?? 'alert--info';
    $icon  = $iconMap[$flash['type']] ?? 'bi-info-circle-fill';
    $msg   = sanitize($flash['message']);

    return <<<HTML
    <div class="alert {$class}" id="flashMessage">
        <i class="bi {$icon}"></i>
        <span>{$msg}</span>
        <button type="button" class="alert__close" onclick="this.parentElement.remove()">
            <i class="bi bi-x-lg"></i>
        </button>
    </div>
    HTML;
}

/**
 * Format a number as currency.
 * Defaults to the symbol configured in Settings → Financial.
 */
function formatCurrency(float $amount, ?string $symbol = null): string
{
    return ($symbol ?? currencySymbol()) . number_format($amount, 2);
}

/**
 * Format a date string.
 */
function formatDate(?string $date, string $format = 'M d, Y'): string
{
    if (!$date) return '—';
    return date($format, strtotime($date));
}

/**
 * Format a datetime string.
 */
function formatDateTime(?string $datetime, string $format = 'M d, Y h:i A'): string
{
    if (!$datetime) return '—';
    return date($format, strtotime($datetime));
}

/**
 * Generate a unique code with prefix.
 */
function generateCode(string $prefix = 'SX'): string
{
    return strtoupper($prefix) . '-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -5));
}

/**
 * Upload a file securely.
 *
 * @return string|false The relative path to the uploaded file, or false on failure.
 */
function uploadFile(array $file, string $subDir = 'documents', array $allowedTypes = []): string|false
{
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return false;
    }

    if ($file['size'] > MAX_FILE_SIZE) {
        return false;
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    if (!empty($allowedTypes) && !in_array($mimeType, $allowedTypes)) {
        return false;
    }

    // The extension decides how Apache executes the file, so it is derived from
    // the sniffed type — never from the uploaded filename. Otherwise a genuine
    // image sent as "logo.php" would be stored as .php and run as code.
    $extByMime = [
        'image/jpeg' => 'jpg',  'image/png'  => 'png',
        'image/webp' => 'webp', 'image/gif'  => 'gif',
        'application/pdf' => 'pdf',
        'application/msword' => 'doc',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
    ];
    $extension = $extByMime[$mimeType] ?? null;
    if ($extension === null) {
        // Unknown-but-permitted type: fall back to the submitted extension,
        // stripped to plain letters/digits and refused if it is executable.
        $extension = strtolower(preg_replace('/[^a-z0-9]/i', '', pathinfo($file['name'], PATHINFO_EXTENSION)));
        $blocked = ['php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'phar',
                    'htaccess', 'htm', 'html', 'svg', 'js', 'exe', 'sh', 'cgi', 'pl'];
        if ($extension === '' || in_array($extension, $blocked, true)) {
            return false;
        }
    }

    $safeName  = uniqid('file_', true) . '.' . $extension;
    $targetDir = UPLOADS_PATH . '/' . $subDir;

    if (!is_dir($targetDir)) {
        mkdir($targetDir, 0755, true);
    }

    $targetPath = $targetDir . '/' . $safeName;

    if (move_uploaded_file($file['tmp_name'], $targetPath)) {
        return 'assets/uploads/' . $subDir . '/' . $safeName;
    }

    return false;
}

/**
 * Log an action in the audit_logs table.
 */
function logAudit(string $action, string $entityType = '', int $entityId = 0, string $oldValue = '', string $newValue = ''): void
{
    try {
        $db = getDBConnection();
        $stmt = $db->prepare("
            INSERT INTO audit_logs (user_id, action, entity_type, entity_id, old_value, new_value, ip_address, user_agent)
            VALUES (:user_id, :action, :entity_type, :entity_id, :old_value, :new_value, :ip, :ua)
        ");
        $stmt->execute([
            ':user_id'     => $_SESSION['user_id'] ?? null,
            ':action'      => $action,
            ':entity_type' => $entityType,
            ':entity_id'   => $entityId,
            ':old_value'   => $oldValue,
            ':new_value'   => $newValue,
            ':ip'          => $_SERVER['REMOTE_ADDR'] ?? '',
            ':ua'          => $_SERVER['HTTP_USER_AGENT'] ?? '',
        ]);
    } catch (PDOException $e) {
        error_log('Audit log error: ' . $e->getMessage());
    }
}

/**
 * Get the count of unread notifications for the current user.
 */
function getUnreadNotificationCount(): int
{
    if (!isLoggedIn()) return 0;

    try {
        $db = getDBConnection();
        $stmt = $db->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = :uid AND is_read = 0");
        $stmt->execute([':uid' => $_SESSION['user_id']]);
        return (int) $stmt->fetchColumn();
    } catch (PDOException $e) {
        return 0;
    }
}

/**
 * Truncate text to a max length with ellipsis.
 */
function truncate(string $text, int $length = 100): string
{
    if (mb_strlen($text) <= $length) return $text;
    return mb_substr($text, 0, $length) . '…';
}

/**
 * Get user avatar URL or default.
 */
function getAvatarUrl(?string $avatar): string
{
    if ($avatar && file_exists(BASE_PATH . '/' . $avatar)) {
        return APP_URL . '/' . $avatar;
    }
    return ASSETS_URL . '/img/default-avatar.svg';
}

/**
 * Render a page inside the application layout (sidebar + header + content + footer).
 * Controllers call this instead of bare `require`. View vars can be injected via $vars.
 */
function renderPage(string $viewFile, array $vars = []): void
{
    // Make controller-supplied variables visible inside the view
    if (!empty($vars)) {
        extract($vars, EXTR_SKIP);
    }
    $currentUser = getCurrentUser();
    $role = getUserRole();
    require VIEWS_PATH . '/layout.php';
    exit;
}

/**
 * Build a paginated URL preserving current query parameters.
 */
function paginateUrl(int $pageNumber): string
{
    $params = $_GET;
    $params['p'] = $pageNumber;
    return APP_URL . '/index.php?' . http_build_query($params);
}

/**
 * Get a setting value from the settings table (cached per-request).
 */
function setting(string $key, ?string $default = null): ?string
{
    static $cache = null;
    if ($cache === null) {
        try {
            $rows = getDBConnection()->query("SELECT setting_key, setting_value FROM settings")->fetchAll();
            $cache = [];
            foreach ($rows as $r) $cache[$r['setting_key']] = $r['setting_value'];
        } catch (PDOException $e) {
            $cache = [];
        }
    }
    return $cache[$key] ?? $default;
}

/* ─────────────────────────────────────────────────────────────────────────
   Configured business values
   ─────────────────────────────────────────────────────────────────────────
   Everything the admin can change in Settings is read through one of these
   accessors — never from a constant directly. The constants in config/app.php
   stay on as fallbacks so a fresh install (or a database that is momentarily
   unreachable) still renders sane values instead of blanks and zeroes.

   A blank setting means "not configured", so it falls back too; that is why
   these use ?: rather than ??.
   ───────────────────────────────────────────────────────────────────────── */

/** Trading name — shown in the sidebar, page titles, receipts and public site. */
function companyName(): string
{
    return setting('company_name') ?: APP_NAME;
}

/** Registered/legal name for contracts, receipts and schema.org markup. */
function companyLegalName(): string
{
    return setting('company_legal_name') ?: (setting('company_name') ?: BIZ_LEGAL_NAME_DEFAULT);
}

function companyEmail(): string
{
    return setting('company_email') ?: BIZ_EMAIL_DEFAULT;
}

function companyPhone(): string
{
    return setting('company_phone') ?: BIZ_PHONE_DEFAULT;
}

function companyAddress(): string
{
    return setting('company_address') ?: '';
}

/** Raw stored logo value — an uploaded path, or '' when none is configured. */
function companyLogo(): string
{
    return setting('company_logo') ?: '';
}

/**
 * Browser-ready URL for the company logo, or '' when there is none.
 *
 * Uploaded logos are stored repo-relative ("assets/uploads/logo/…"). Values
 * left over from when this field was a URL box are passed through as-is, so
 * an existing remote logo keeps working until it is replaced by an upload.
 */
function companyLogoUrl(): string
{
    $logo = companyLogo();
    if ($logo === '') return '';
    if (preg_match('#^https?://#i', $logo)) return $logo;
    return file_exists(BASE_PATH . '/' . $logo) ? APP_URL . '/' . $logo : '';
}

function currencyCode(): string
{
    return setting('currency') ?: DEFAULT_CURRENCY;
}

function currencySymbol(): string
{
    return setting('currency_symbol') ?: DEFAULT_CURRENCY_SYMBOL;
}

/** Sales tax applied to property sales, in percent. */
function taxRate(): float
{
    $v = setting('tax_rate');
    return is_numeric($v) ? (float)$v : (float)DEFAULT_TAX_RATE;
}

/** Default agent commission, in percent. */
function commissionRate(): float
{
    $v = setting('commission_rate');
    return is_numeric($v) ? (float)$v : (float)DEFAULT_COMMISSION_RATE;
}

/** Late rent penalty, in percent. */
function lateFeeRate(): float
{
    $v = setting('late_fee_percentage');
    return is_numeric($v) ? (float)$v : (float)LATE_FEE_PERCENTAGE;
}

/** How many days a reservation hold stays valid. */
function reservationExpiryDays(): int
{
    $v = setting('reservation_expiry_days');
    return is_numeric($v) && (int)$v > 0 ? (int)$v : (int)RESERVATION_EXPIRY_DAYS;
}

/** The expiry date a reservation created today would get (Y-m-d). */
function reservationExpiryDate(?string $from = null): string
{
    $base = $from ? strtotime($from) : time();
    return date('Y-m-d', strtotime('+' . reservationExpiryDays() . ' days', $base));
}

/**
 * Notify a user by inserting an in-app notification record.
 */
function notify(int $userId, string $title, string $message = '', string $type = 'info', string $refType = '', int $refId = 0): void
{
    try {
        $stmt = getDBConnection()->prepare("
            INSERT INTO notifications (user_id, title, message, type, reference_type, reference_id)
            VALUES (:uid, :title, :msg, :type, :rt, :rid)
        ");
        $stmt->execute([
            ':uid' => $userId, ':title' => $title, ':msg' => $message,
            ':type' => $type, ':rt' => $refType, ':rid' => $refId,
        ]);
    } catch (PDOException $e) {
        error_log('Notification error: ' . $e->getMessage());
    }
}

/**
 * Get the status badge class for a given status.
 */
function getStatusBadgeClass(string $status): string
{
    $map = [
        'available'    => 'badge--success',
        'active'       => 'badge--success',
        'approved'     => 'badge--success',
        'paid'         => 'badge--success',
        'completed'    => 'badge--success',
        'signed'       => 'badge--success',
        'confirmed'    => 'badge--success',
        'rented'       => 'badge--info',
        'in_progress'  => 'badge--info',
        'assigned'     => 'badge--info',
        'partial'      => 'badge--warning',
        'pending'      => 'badge--warning',
        'under_review' => 'badge--warning',
        'reserved'     => 'badge--warning',
        'new'          => 'badge--primary',
        'open'         => 'badge--primary',
        'overdue'      => 'badge--danger',
        'expired'      => 'badge--danger',
        'rejected'     => 'badge--danger',
        'terminated'   => 'badge--danger',
        'cancelled'    => 'badge--muted',
        'inactive'     => 'badge--muted',
        'sold'         => 'badge--purple',
        'maintenance'  => 'badge--orange',
    ];

    return $map[$status] ?? 'badge--muted';
}

// ═══════════════════════════════════════════════════════════════════════
// Property presentation helpers
// ───────────────────────────────────────────────────────────────────────
// Shared by the public cards, the listing detail page and the homepage so
// price wording, icons and status labels can never drift between views.
// ═══════════════════════════════════════════════════════════════════════

/**
 * Resolve the headline price for a property.
 *
 * A listing priced as a rental shows rent_amount with a "/month" suffix;
 * anything else shows the sale price. Returns display-ready parts rather
 * than a formatted string so callers can style the suffix separately.
 *
 * @return array{amount:float,suffix:string,isRental:bool,label:string}
 */
function propertyPrice(array $p): array
{
    $isRental = ($p['property_type'] ?? '') === 'rent';
    $rent     = (float) ($p['rent_amount'] ?? 0);
    $sale     = (float) ($p['price'] ?? 0);

    // 'both' listings lead with whichever figure is actually populated.
    if (($p['property_type'] ?? '') === 'both') {
        $isRental = $sale <= 0 && $rent > 0;
    }

    return [
        'amount'   => $isRental ? $rent : $sale,
        'suffix'   => $isRental ? '/month' : '',
        'isRental' => $isRental,
        'label'    => $isRental ? 'For Rent' : 'For Sale',
    ];
}

/**
 * Public URL for an uploaded file path stored relative to the app root.
 * Returns null when there is nothing to show, so callers can fall back
 * to a placeholder rather than rendering a broken image.
 */
function uploadUrl(?string $relativePath): ?string
{
    $relativePath = trim((string) $relativePath);
    if ($relativePath === '') return null;
    return APP_URL . '/' . ltrim($relativePath, '/');
}

/* ─── Stock imagery ─────────────────────────────────────────────
 * The catalogue ships with licensed photography (see templates/luxestate)
 * so the public site never renders an empty icon tile before an agency has
 * uploaded its own photos.
 *
 * Selection is deterministic — seeded on the record's primary key — so a
 * given property or agent keeps the same picture across page loads, paginated
 * views and repeat visits. A random pick would reshuffle the grid on every
 * request and make the site feel broken.
 *
 * Uploaded photos always win; these are strictly the fallback.
 */

/** Exterior shots read as "listing cover"; interiors fill out a gallery. */
const STOCK_EXTERIORS = [
    'property-exterior-2.webp',  'property-exterior-3.webp',  'property-exterior-4.webp',
    'property-exterior-5.webp',  'property-exterior-7.webp',  'property-exterior-8.webp',
    'property-exterior-9.webp',  'property-exterior-10.webp',
];

const STOCK_INTERIORS = [
    'property-interior-2.webp', 'property-interior-3.webp', 'property-interior-4.webp',
    'property-interior-5.webp', 'property-interior-6.webp', 'property-interior-8.webp',
];

/** Professional headshots for agent cards and profiles. */
const STOCK_AGENTS = [
    'agent-1.webp', 'agent-2.webp', 'agent-3.webp', 'agent-4.webp', 'agent-5.webp',
    'agent-6.webp', 'agent-7.webp', 'agent-8.webp', 'agent-9.webp', 'agent-10.webp',
];

/** Softer portraits, used for testimonials so they don't read as staff. */
const STOCK_PORTRAITS = [
    'person-f-8.webp',  'person-f-9.webp',  'person-f-10.webp', 'person-m-7.webp',
    'person-m-9.webp',  'person-m-11.webp', 'person-m-12.webp',
];

/**
 * Stable index into a fixed-size list for a given seed.
 *
 * Non-numeric seeds (a name, an email) are hashed first so the same person
 * resolves to the same portrait wherever they appear on the site.
 */
function stockIndex(int|string $seed, int $count): int
{
    if ($count < 1) return 0;
    $n = is_int($seed) ? $seed : (int) hexdec(substr(md5((string) $seed), 0, 6));
    return abs($n) % $count;
}

/** Fallback cover photo for a property. */
function stockPropertyImage(int|string $seed): string
{
    return IMG_URL . '/property/' . STOCK_EXTERIORS[stockIndex($seed, count(STOCK_EXTERIORS))];
}

/** Fallback headshot for an agent or staff member. */
function stockAgentImage(int|string $seed): string
{
    return IMG_URL . '/person/' . STOCK_AGENTS[stockIndex($seed, count(STOCK_AGENTS))];
}

/** Fallback portrait for a customer, testimonial or reviewer. */
function stockPortraitImage(int|string $seed): string
{
    return IMG_URL . '/person/' . STOCK_PORTRAITS[stockIndex($seed, count(STOCK_PORTRAITS))];
}

/**
 * A full fallback gallery for a listing with no uploads: one exterior to
 * open on, then interiors rotated from the same seed so two neighbouring
 * properties don't show an identical set.
 */
function stockPropertyGallery(int|string $seed, int $count = 5): array
{
    $out    = [stockPropertyImage($seed)];
    $offset = stockIndex($seed, count(STOCK_INTERIORS));
    $total  = count(STOCK_INTERIORS);

    for ($i = 0; $i < $count - 1 && $i < $total; $i++) {
        $out[] = IMG_URL . '/property/' . STOCK_INTERIORS[($offset + $i) % $total];
    }
    return $out;
}

/**
 * Cover image for a property row: the uploaded cover when one exists,
 * otherwise a seeded stock shot. Callers can render unconditionally.
 */
function propertyImage(array $property, ?array $cover = null): string
{
    return uploadUrl($cover['path'] ?? null)
        ?? stockPropertyImage((int) ($property['id'] ?? 0));
}

/** Avatar for a user row: uploaded avatar, else a seeded headshot. */
function agentImage(array $user): string
{
    return uploadUrl($user['avatar'] ?? null)
        ?? stockAgentImage((int) ($user['id'] ?? 0) ?: (string) ($user['full_name'] ?? ''));
}

/** Bootstrap Icons glyph for a property category. */
function categoryIcon(string $category): string
{
    return [
        'apartment'  => 'bi-building',
        'house'      => 'bi-house-door',
        'villa'      => 'bi-house-heart',
        'land'       => 'bi-map',
        'office'     => 'bi-briefcase',
        'commercial' => 'bi-shop',
        'warehouse'  => 'bi-box-seam',
    ][$category] ?? 'bi-buildings';
}

/** Human label for a property category. */
function categoryLabel(string $category): string
{
    return [
        'apartment'  => 'Apartments',
        'house'      => 'Houses',
        'villa'      => 'Villas',
        'land'       => 'Land',
        'office'     => 'Offices',
        'commercial' => 'Commercial',
        'warehouse'  => 'Warehouses',
    ][$category] ?? ucfirst($category);
}

/** Public-site tag modifier for a listing status. */
function statusTagClass(string $status): string
{
    return [
        'available'   => 'tag--green',
        'reserved'    => 'tag--gold',
        'rented'      => 'tag--accent',
        'sold'        => 'tag--warm',
        'maintenance' => 'tag--warm',
        'inactive'    => 'tag--muted',
    ][$status] ?? 'tag--muted';
}

/**
 * Property ids the signed-in user has favourited.
 * Returns an empty array for guests, so views can call it unconditionally.
 *
 * @return int[]
 */
function savedPropertyIds(): array
{
    if (!isLoggedIn()) return [];

    static $cache = null;
    if ($cache !== null) return $cache;

    try {
        $stmt = getDBConnection()->prepare("SELECT property_id FROM favorites WHERE user_id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $cache = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    } catch (Throwable $e) {
        $cache = [];
    }
    return $cache;
}

/**
 * Render a property card.
 *
 * @param array      $property Property row
 * @param array|null $cover    ['path' => ?string, 'total' => int] from Property::getCoversFor()
 * @param int[]      $savedIds Favourited property ids
 * @param bool       $eager    Skip lazy-loading (use for above-the-fold cards)
 */
function renderPropertyCard(array $property, ?array $cover = null, array $savedIds = [], bool $eager = false): void
{
    require VIEWS_PATH . '/public/components/property_card.php';
}
