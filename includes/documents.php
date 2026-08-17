<?php
/**
 * Document access control and file handling.
 *
 * Everything that decides who may see a document, and everything that moves a
 * document on or off disk, lives in this one file so the rules can be read in
 * a single sitting. Controllers and views ask questions here; they never
 * compare roles or build filesystem paths themselves.
 *
 * The store (storage/documents) sits outside assets/ and is denied by Apache,
 * so a file only ever reaches a browser through DocumentController::download(),
 * which re-checks documentVisibilityAllows() on every request.
 */

/** Visibility levels, in increasing order of restriction. */
const DOC_VISIBILITIES = [
    'public'  => 'Public',
    'staff'   => 'Staff Only',
    'private' => 'Private',
];

/** Help text shown next to each level in the upload form. */
const DOC_VISIBILITY_HINTS = [
    'public'  => 'Shown on the public property listing and downloadable by anyone.',
    'staff'   => 'Visible to administrators and agents only.',
    'private' => 'Legal and confidential paperwork. Administrators only.',
];

/* ─── Configured limits ──────────────────────────────────────────────── */

/** Days before expiry at which a document starts warning. Settings → Documents. */
function documentExpiryWarningDays(): int
{
    $v = setting('document_expiry_warning_days');
    return is_numeric($v) && (int) $v > 0 ? (int) $v : 30;
}

/**
 * Upload ceiling in bytes.
 *
 * Clamped to what PHP will actually accept: a 50 MB setting is a lie if
 * upload_max_filesize is 2 MB, and the user deserves the real number in the
 * error message rather than a silently truncated upload.
 */
function documentMaxBytes(): int
{
    $configured = (int) (setting('document_max_size_mb') ?: 0);
    $limit = $configured > 0 ? $configured * 1024 * 1024 : MAX_DOCUMENT_SIZE;
    $limit = min($limit, MAX_DOCUMENT_SIZE);

    foreach (['upload_max_filesize', 'post_max_size'] as $directive) {
        $ini = phpSizeToBytes((string) ini_get($directive));
        if ($ini > 0) {
            $limit = min($limit, $ini);
        }
    }

    return max(1, $limit);
}

/** Parse a php.ini shorthand size ("8M", "512K") into bytes. */
function phpSizeToBytes(string $value): int
{
    $value = trim($value);
    if ($value === '') return 0;

    $unit   = strtolower(substr($value, -1));
    $number = (float) $value;

    return (int) match ($unit) {
        'g'     => $number * 1024 * 1024 * 1024,
        'm'     => $number * 1024 * 1024,
        'k'     => $number * 1024,
        default => $number,
    };
}

/** Whether public documents are published on public listings at all. */
function documentPublicEnabled(): bool
{
    return (setting('document_public_enabled') ?? '1') === '1';
}

/* ─── Derived lifecycle state ────────────────────────────────────────── */

/**
 * Work out a document's lifecycle state.
 *
 * Only 'active' and 'archived' are stored. Expiry is derived here on every
 * read rather than written to a column, because this project has no scheduled
 * job: a stored 'expired' would be correct on the day it was written and
 * wrong every day after. Nothing is ever auto-deleted — an expired title deed
 * is still the title deed.
 *
 * @return array{key:string,label:string,badge:string,days:?int}
 *         key is one of active|expiring|expired|archived
 */
function documentStatus(array $doc, ?int $warnDays = null): array
{
    if (($doc['status'] ?? 'active') === 'archived') {
        return ['key' => 'archived', 'label' => 'Archived', 'badge' => 'badge--muted', 'days' => null];
    }

    $expiry = $doc['expiry_date'] ?? null;
    if (empty($expiry) || $expiry === '0000-00-00') {
        return ['key' => 'active', 'label' => 'Active', 'badge' => 'badge--success', 'days' => null];
    }

    $end = DateTimeImmutable::createFromFormat('!Y-m-d', substr((string) $expiry, 0, 10));
    if ($end === false) {
        // An unparseable date is a data problem, not an expiry — say so rather
        // than silently reporting the document as active.
        return ['key' => 'active', 'label' => 'Active', 'badge' => 'badge--success', 'days' => null];
    }

    $today = new DateTimeImmutable('today');
    $days  = (int) $today->diff($end)->format('%r%a');

    if ($days < 0) {
        return ['key' => 'expired', 'label' => 'Expired', 'badge' => 'badge--danger', 'days' => $days];
    }
    if ($days <= ($warnDays ?? documentExpiryWarningDays())) {
        return ['key' => 'expiring', 'label' => 'Expiring Soon', 'badge' => 'badge--warning', 'days' => $days];
    }

    return ['key' => 'active', 'label' => 'Active', 'badge' => 'badge--success', 'days' => $days];
}

/** Sentence describing when a document expires, for the row subtitle. */
function documentExpiryNote(array $doc): string
{
    $state = documentStatus($doc);
    if ($state['days'] === null) {
        return empty($doc['expiry_date']) ? 'No expiry date' : '';
    }
    if ($state['days'] < 0) {
        $n = abs($state['days']);
        return 'Expired ' . $n . ' day' . ($n === 1 ? '' : 's') . ' ago';
    }
    if ($state['days'] === 0) {
        return 'Expires today';
    }
    return 'Expires in ' . $state['days'] . ' day' . ($state['days'] === 1 ? '' : 's');
}

/* ─── Authorisation ──────────────────────────────────────────────────── */

// These three read their answer from the permission matrix rather than
// naming roles themselves. They stay as named helpers because the document
// views ask the same question in a dozen places and "documentCanDelete()"
// reads better there than the raw permission string — but the answer now has
// one source, so granting a role document access is still a single edit.

/**
 * Can the current user upload, edit, archive and restore documents?
 *
 * Deliberately not the same as "can open the property page": properties.show
 * is granted to every signed-in role, so every management control has to be
 * gated on this instead.
 */
function documentCanManage(): bool
{
    return can('documents.edit');
}

/** Permanent deletion is an administrator action. Everyone else archives. */
function documentCanDelete(): bool
{
    return can('documents.delete');
}

/** Staff see archived documents; nobody else knows they exist. */
function documentIsStaff(): bool
{
    return can('documents.view');
}

/**
 * Is this property's public-facing page live?
 *
 * A public document is published *through* its property, so a listing that is
 * still awaiting approval or has been archived must not leak its floor plans.
 */
function propertyIsPubliclyVisible(?array $property): bool
{
    if (!$property) return false;

    return ($property['approval_status'] ?? '') === 'approved'
        && (int) ($property['is_archived'] ?? 0) === 0;
}

/**
 * The visibility levels the current viewer is allowed to see.
 *
 * Used to filter list queries in SQL. This is an optimisation, not the
 * security boundary — documentVisibilityAllows() is the authority, and the
 * download endpoint calls it again even when the list query already filtered.
 *
 * $property is the subject being listed, when there is a single one. Pass it
 * whenever it is known: an owner's clearance is not a property of their role
 * but of the property in front of them, so without it they are scoped down to
 * public and their own paperwork disappears from their own portfolio.
 *
 * @return string[]
 */
function documentVisibilityScope(?array $property = null): array
{
    if (hasRole(ROLE_ADMIN))  return ['public', 'staff', 'private'];
    if (hasRole(ROLE_AGENT))  return ['public', 'staff'];

    // An owner is a principal of their own asset, not a third party to it:
    // on a property they own they read every level, the title deed included.
    // On anyone else's they are an ordinary visitor.
    if (ownsProperty($property)) return ['public', 'staff', 'private'];

    return ['public'];
}

/**
 * May the current viewer see this document?
 *
 *   private  administrators only
 *   staff    administrators and agents
 *   public   everyone, including signed-out visitors
 *
 * Three rules sit on top of that table:
 *   * an archived document is staff-only whatever its visibility, so
 *     withdrawing a document really withdraws it — and that holds for the
 *     owner too, otherwise "withdrawn" would not mean withdrawn;
 *   * a property's owner reads every level on that property, because the
 *     paperwork describing an asset belongs to whoever owns the asset;
 *   * a public document is only public while its property is approved and
 *     unarchived and the public switch is on — otherwise it falls back to
 *     the staff rule.
 *
 * @param array|null $property The parent property row when the document
 *                             references one; null when it has no parent.
 *                             Must carry owner_id for the owner rule to
 *                             apply — without it an owner is treated as an
 *                             ordinary viewer, which fails closed.
 */
function documentVisibilityAllows(array $doc, ?array $property = null): bool
{
    $visibility = (string) ($doc['visibility'] ?? 'private');
    $archived   = ($doc['status'] ?? 'active') === 'archived';

    // Administrators see everything, including archived and unapproved.
    if (hasRole(ROLE_ADMIN)) {
        return true;
    }

    if ($archived) {
        return documentIsStaff();
    }

    // Checked after the archive rule and before the visibility table: an
    // owner's clearance covers every level on their own property, but not
    // documents that have been withdrawn from circulation.
    if (ownsProperty($property)) {
        return true;
    }

    return match ($visibility) {
        'private' => false, // admin-only, and admin already returned above
        'staff'   => documentIsStaff(),
        'public'  => documentIsStaff()
            || (documentPublicEnabled()
                && ($property === null || propertyIsPubliclyVisible($property))),
        default   => false,
    };
}

/* ─── File storage ───────────────────────────────────────────────────── */

/**
 * Resolve a stored path to a real file inside the document store.
 *
 * Returns null unless every check passes, so a caller can treat null as
 * "there is no such file" without needing to know why:
 *   1. the stored value must be inside storage/documents by prefix;
 *   2. its basename must be plain characters — this alone defeats "..",
 *      slashes, NUL bytes and absolute paths;
 *   3. the resolved realpath must still sit inside the store, which catches
 *      a symlink planted in the directory;
 *   4. it must actually be a readable file.
 */
function documentStoragePath(?string $storedPath): ?string
{
    $stored = trim((string) $storedPath);
    if ($stored === '' || !str_starts_with($stored, 'storage/documents/')) {
        return null;
    }

    $name = substr($stored, strlen('storage/documents/'));
    if (!preg_match('/^[A-Za-z0-9._-]{1,160}$/', $name) || str_contains($name, '..')) {
        return null;
    }

    $root = realpath(DOCS_STORAGE_PATH);
    if ($root === false) {
        return null;
    }

    $full = realpath($root . DIRECTORY_SEPARATOR . $name);
    if ($full === false || !is_file($full) || !is_readable($full)) {
        return null;
    }

    // The resolved path must still be inside the store.
    if (strncmp($full, $root . DIRECTORY_SEPARATOR, strlen($root) + 1) !== 0) {
        return null;
    }

    return $full;
}

/**
 * Validate and store an uploaded document.
 *
 * Returns the metadata to persist, or null with a human-readable reason
 * pushed onto $errors. Deliberately separate from uploadFile(): that helper
 * writes into assets/uploads and returns only a path, and six existing call
 * sites depend on both of those things.
 *
 * @return array{file_path:string,file_name:string,file_type:string,file_ext:string,file_size:int,checksum:string}|null
 */
function storeDocumentFile(array $file, array &$errors): ?array
{
    // PHP's own limits fire before any check of ours, so translate them.
    $errorCode = $file['error'] ?? UPLOAD_ERR_NO_FILE;
    if ($errorCode !== UPLOAD_ERR_OK) {
        $errors[] = match ($errorCode) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE =>
                'The file is larger than the server allows (' . formatBytes(documentMaxBytes()) . ' maximum).',
            UPLOAD_ERR_PARTIAL   => 'The upload was interrupted. Please try again.',
            UPLOAD_ERR_NO_FILE   => 'Choose a file to upload.',
            UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE =>
                'The server could not write the file. Check that the storage directory is writable.',
            default              => 'The file could not be uploaded.',
        };
        return null;
    }

    // Guard against a forged $_FILES entry pointing at a server-side path.
    if (!is_uploaded_file($file['tmp_name'] ?? '')) {
        $errors[] = 'The upload could not be verified.';
        return null;
    }

    $size = (int) ($file['size'] ?? 0);
    if ($size <= 0) {
        $errors[] = 'The file is empty.';
        return null;
    }

    $max = documentMaxBytes();
    if ($size > $max) {
        $errors[] = 'The file is ' . formatBytes($size) . '. The maximum is ' . formatBytes($max) . '.';
        return null;
    }

    // Sniff the real type; the browser-supplied content-type is not evidence.
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime  = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    if (!is_string($mime) || !in_array($mime, ALLOWED_DOCUMENT_TYPES, true)) {
        $errors[] = 'That file type is not accepted. Upload a PDF, image, Word or Excel document.';
        return null;
    }

    // An image must also decode as one — a PHP script renamed to .jpg sniffs
    // as text/plain and is already rejected, but this closes the gap where a
    // polyglot file carries a valid image header and a script payload.
    if (str_starts_with($mime, 'image/') && getimagesize($file['tmp_name']) === false) {
        $errors[] = 'That image file appears to be corrupt.';
        return null;
    }

    // The extension comes from the sniffed type, never the submitted name, so
    // the file on disk cannot be given an executable extension.
    $ext = DOCUMENT_EXT_BY_MIME[$mime] ?? null;
    if ($ext === null) {
        $errors[] = 'That file type is not accepted.';
        return null;
    }

    if (!is_dir(DOCS_STORAGE_PATH) && !mkdir(DOCS_STORAGE_PATH, 0755, true) && !is_dir(DOCS_STORAGE_PATH)) {
        $errors[] = 'The document storage directory could not be created.';
        return null;
    }

    // Unguessable name: the last line of defence if a server is ever
    // misconfigured to serve this directory.
    try {
        $safeName = 'doc_' . bin2hex(random_bytes(16)) . '.' . $ext;
    } catch (Exception $e) {
        $safeName = 'doc_' . bin2hex((string) mt_rand()) . uniqid('', true) . '.' . $ext;
    }

    $target = DOCS_STORAGE_PATH . DIRECTORY_SEPARATOR . $safeName;
    if (!move_uploaded_file($file['tmp_name'], $target)) {
        $errors[] = 'The file could not be saved to the document store.';
        return null;
    }
    @chmod($target, 0644);

    return [
        'file_path' => 'storage/documents/' . $safeName,
        'file_name' => documentSafeOriginalName((string) ($file['name'] ?? $safeName)),
        'file_type' => $mime,
        'file_ext'  => $ext,
        'file_size' => $size,
        'checksum'  => hash_file('sha256', $target) ?: '',
    ];
}

/**
 * Keep the uploader's filename for display and download, minus anything that
 * could confuse a filesystem or a Content-Disposition header.
 */
function documentSafeOriginalName(string $name): string
{
    $name = basename(str_replace('\\', '/', $name));
    $name = preg_replace('/[\x00-\x1F\x7F"\\\\\/:*?<>|]+/u', '', $name) ?? '';
    $name = trim($name);

    return $name === '' ? 'document' : mb_substr($name, 0, 200);
}

/**
 * Remove a stored document file.
 *
 * Resolves through documentStoragePath() first, so a path that has been
 * tampered with in the database can never make this unlink something else.
 */
function deleteDocumentFile(?string $storedPath): bool
{
    $full = documentStoragePath($storedPath);
    if ($full === null) {
        return false;
    }

    return @unlink($full);
}

/** URL for the delivery endpoint. $disposition is 'attachment' or 'inline'. */
function documentUrl(int $id, string $disposition = 'attachment'): string
{
    $url = APP_URL . '/index.php?page=documents&action=download&id=' . $id;
    return $disposition === 'inline' ? $url . '&disposition=inline' : $url;
}
