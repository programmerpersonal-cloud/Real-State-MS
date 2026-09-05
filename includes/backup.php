<?php
/**
 * Backup — storage, locking, retention, health.
 *
 * The half of the module that has no opinion about how an archive is built.
 * Everything here answers a question the engine, the controller and the CLI
 * runner all ask, and each question is answered in exactly one place:
 *
 *   where does a backup live?          backupDir(), backupArchivePath()
 *   may this run start?                backupLockAcquire()
 *   when does this backup expire?      backupExpiryFor()
 *   when is this schedule next due?    backupNextRun()
 *   is the system actually protected?  backupHealth()
 *
 * Two conventions run through the whole file.
 *
 * First, nothing trusts a path. A row carries a *basename* and a type; the
 * directory is derived from the type, and backupArchivePath() re-resolves the
 * result with realpath() and refuses anything that lands outside the backup
 * root. There is no code path from a request parameter to a filesystem path.
 *
 * Second, nothing claims more than it can prove. backupHealth() reads real
 * rows and real disk figures, and where it cannot answer — no quota
 * configured, no schedule enabled — it says so rather than inventing a
 * reassuring default. A backup dashboard that lies is worse than none, because
 * it stops people checking.
 *
 * Timezone note: scheduling is computed in PHP against the configured backup
 * timezone, and age/RPO comparisons are computed in MySQL against NOW(). Each
 * is internally consistent, and the two are never compared to one another.
 */

/* ─────────────────────────────────────────────────────────────────────
   Configuration
   ───────────────────────────────────────────────────────────────────── */

/** The backup root, normalised to forward slashes and with no trailing slash. */
function backupRoot(): string
{
    return BACKUP_PATH;
}

/**
 * One of the five storage directories, created on demand.
 *
 * $kind is checked against BACKUP_DIRS rather than concatenated, so this
 * cannot be handed a value out of a request and turned into a path. An
 * unknown kind is a programming error and throws.
 */
function backupDir(string $kind): string
{
    if (!in_array($kind, BACKUP_DIRS, true)) {
        throw new InvalidArgumentException('Unknown backup directory: ' . $kind);
    }

    $path = backupRoot() . '/' . $kind;
    if (!is_dir($path)) {
        @mkdir($path, 0700, true);
    }
    return $path;
}

/**
 * Create the backup root and its guards.
 *
 * The .htaccess and index.php written here are belt-and-braces: the root is
 * supposed to be outside the document root, and if it is these files are
 * never consulted by anybody. They exist for the deployment where it is not —
 * a shared host with one document root, an operator who pointed BACKUP_PATH
 * somewhere convenient — because the cost of writing them is nothing and the
 * cost of being wrong is the entire database over HTTP.
 *
 * Returns an empty array on success, or a list of problems.
 *
 * @return string[]
 */
function backupEnsureStorage(): array
{
    $problems = [];
    $root     = backupRoot();

    if (!is_dir($root) && !@mkdir($root, 0700, true) && !is_dir($root)) {
        return ['The backup directory could not be created: ' . $root];
    }
    if (!is_writable($root)) {
        $problems[] = 'The backup directory is not writable: ' . $root;
    }

    foreach (BACKUP_DIRS as $kind) {
        $dir = $root . '/' . $kind;
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            $problems[] = 'Could not create ' . $kind . '/ inside the backup directory.';
        }
    }

    $htaccess = $root . '/.htaccess';
    if (!is_file($htaccess)) {
        @file_put_contents($htaccess, implode("\n", [
            '# Backups are never served over HTTP. This directory is expected to sit',
            '# outside the document root; this file is the second lock on the door.',
            '<IfModule mod_authz_core.c>',
            '    Require all denied',
            '</IfModule>',
            '<IfModule !mod_authz_core.c>',
            '    Order allow,deny',
            '    Deny from all',
            '</IfModule>',
            '',
        ]));
    }

    $index = $root . '/index.php';
    if (!is_file($index)) {
        @file_put_contents($index, "<?php http_response_code(404); exit;\n");
    }

    return $problems;
}

/**
 * Has the backup root ended up somewhere Apache serves?
 *
 * Compared as resolved real paths, so a symlink or a ../ in either value
 * cannot make an exposed directory look safe. A true here is a Critical
 * health finding, not a warning: it means the archives are one directory
 * listing away from being public.
 */
function backupRootIsExposed(): bool
{
    $docRoot = realpath((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''));
    $root    = realpath(backupRoot());

    if ($docRoot === false || $root === false) {
        return false;   // cannot resolve one of them — reported separately
    }

    $docRoot = rtrim(str_replace('\\', '/', $docRoot), '/') . '/';
    $root    = rtrim(str_replace('\\', '/', $root), '/') . '/';

    // Windows paths are case-insensitive; comparing them case-sensitively
    // would call D:/XAMPP/htdocs/… safe because the drive letter differed.
    if (DIRECTORY_SEPARATOR === '\\') {
        return str_starts_with(strtolower($root), strtolower($docRoot));
    }
    return str_starts_with($root, $docRoot);
}

/**
 * An external binary, or null when it is not usable.
 *
 * Checked rather than assumed so a missing mysqldump becomes a health finding
 * on the dashboard instead of a failed backup discovered at 2am.
 */
function backupBinary(string $which): ?string
{
    $path = $which === 'mysql' ? MYSQL_BIN : MYSQLDUMP_BIN;

    if ($path === '') {
        return null;
    }
    // An absolute path must exist. A bare name is left to the shell's PATH,
    // which is how this works on a Linux host where mysqldump is on PATH.
    if (str_contains($path, '/') || str_contains($path, '\\')) {
        return is_file($path) ? $path : null;
    }
    return $path;
}

/** The timezone schedules are expressed in. Falls back to UTC if misconfigured. */
function backupTimezone(): DateTimeZone
{
    static $tz = null;
    if ($tz !== null) {
        return $tz;
    }
    try {
        return $tz = new DateTimeZone((string) setting('backup_timezone', 'UTC'));
    } catch (Throwable $e) {
        return $tz = new DateTimeZone('UTC');
    }
}

/** Recovery point objective, in hours. Below this age, backups are current. */
function backupRpoHours(): int
{
    return max(1, (int) setting('backup_rpo_hours', '24'));
}

/** Configured storage ceiling in bytes, or 0 when none is set. */
function backupStorageQuotaBytes(): int
{
    return max(0, (int) setting('backup_storage_quota_gb', '0')) * 1024 * 1024 * 1024;
}

/** Consecutive failures tolerated before administrators are notified. */
function backupFailureThreshold(): int
{
    return max(1, (int) setting('backup_failure_threshold', '2'));
}

/** Human labels for the three backup types. One list, read by the UI and the validators. */
function backupTypes(): array
{
    return [
        'full'     => 'Full Backup',
        'database' => 'Database Only',
        'files'    => 'Files Only',
    ];
}

/** What each type actually captures — shown in the create dialog. */
function backupTypeDescriptions(): array
{
    return [
        'full'     => 'The MySQL database and every uploaded file: property images, documents, contracts, receipts and message attachments.',
        'database' => 'The MySQL database only. Fast and small — records, but none of the files they point at.',
        'files'    => 'Uploaded files only. Property images, documents, contracts, receipts and message attachments, with no database.',
    ];
}

/* ─────────────────────────────────────────────────────────────────────
   Clock
   ───────────────────────────────────────────────────────────────────── */

/**
 * Seconds MySQL's clock runs ahead of PHP's.
 *
 * These two do not agree on this installation, and probably do not on most:
 * PHP takes its timezone from php.ini (Europe/Berlin here) while MySQL uses
 * the operating system's, an hour ahead. Every timestamp in this schema is
 * written by MySQL — NOW(), CURRENT_TIMESTAMP — and every relative time on
 * screen is rendered by PHP's timeAgo(). Feed one to the other unchanged and a
 * backup taken eleven minutes ago reads as "just now", which on this page is
 * not a cosmetic error: the whole point of the top row is how stale the
 * newest backup is.
 *
 * Measured once per request rather than assumed, so it stays correct across
 * daylight saving and a reconfigured server. It is deliberately scoped to this
 * module: the mismatch affects every relative time in the application, and
 * correcting it globally is a one-line change to the bootstrap that belongs to
 * whoever owns that decision, not to a backup feature.
 */
function backupClockSkew(): int
{
    static $skew = null;
    if ($skew !== null) {
        return $skew;
    }

    try {
        $dbNow = (string) getDBConnection()->query("SELECT NOW()")->fetchColumn();
        return $skew = strtotime($dbNow) - time();
    } catch (Throwable $e) {
        return $skew = 0;
    }
}

/**
 * "Now", on the database's clock, for a datetime PHP is about to store.
 *
 * The rule this module follows, without exception:
 *
 *   every datetime column is written on MySQL's clock — either by NOW() in
 *   the statement, or by this function when the value has to be computed in
 *   PHP first — and is read back through backupAgo()/backupWhen().
 *
 *   the single exception is backup_schedules.next_run_at, which is wall-clock
 *   in the configured backup timezone by design, is compared only in PHP, and
 *   is rendered with plain date formatting.
 *
 * The rule exists because the first version broke it: completed_at came from
 * NOW() and verified_at from PHP's date(), so a backup verified one second
 * after it completed displayed as an hour older than itself.
 */
function backupDbNow(): string
{
    return date('Y-m-d H:i:s', time() + backupClockSkew());
}

/**
 * A database timestamp, expressed on PHP's clock.
 *
 * Everything in the backup views renders through this pair rather than calling
 * timeAgo()/formatDateTime() directly on a raw column.
 */
function backupAgo(?string $dbDatetime): string
{
    if (empty($dbDatetime)) {
        return '—';
    }
    $ts = strtotime($dbDatetime);

    return $ts === false ? '—' : timeAgo(date('Y-m-d H:i:s', $ts - backupClockSkew()));
}

/** The same correction, for an absolute date and time. */
function backupWhen(?string $dbDatetime, string $format = 'M d, Y h:i A'): string
{
    if (empty($dbDatetime)) {
        return '—';
    }
    $ts = strtotime($dbDatetime);

    return $ts === false ? '—' : date($format, $ts - backupClockSkew());
}

/**
 * A schedule time, rendered as stored.
 *
 * The exception named in backupDbNow(): next_run_at is already wall-clock in
 * the configured backup timezone — it is what an administrator typed, resolved
 * to a date — so it gets no clock correction at all. Passing it through
 * backupWhen() would shift a 02:00 run to 01:00 on screen while the runner
 * still fired it at 02:00, which is the most confusing kind of wrong.
 */
function backupScheduleWhen(?string $wallClock, string $format = 'M d, H:i'): string
{
    if (empty($wallClock)) {
        return '—';
    }
    $ts = strtotime($wallClock);

    return $ts === false ? '—' : date($format, $ts);
}

/* ─────────────────────────────────────────────────────────────────────
   Paths
   ───────────────────────────────────────────────────────────────────── */

/**
 * Resolve a backup row to a real file on disk, or null.
 *
 * The single chokepoint between a database row and the filesystem, and the
 * reason a tampered row cannot become an arbitrary file read. Four checks,
 * in order:
 *
 *   1. the stored value must be a bare basename — no separators at all;
 *   2. the type must be a known storage directory;
 *   3. realpath() must resolve (the file exists and symlinks are followed);
 *   4. the resolved path must still sit inside that directory.
 *
 * Step 4 is what catches a symlink planted inside the backup directory that
 * points at C:/Windows/win.ini — steps 1 to 3 all pass for that file.
 */
function backupArchivePath(array $row): ?string
{
    $name = (string) ($row['file_name'] ?? '');
    $type = (string) ($row['type'] ?? '');

    if ($name === '' || $name !== basename($name)) {
        return null;
    }
    if (str_contains($name, '/') || str_contains($name, '\\') || str_contains($name, "\0")) {
        return null;
    }
    if (!in_array($type, ['full', 'database', 'files'], true)) {
        return null;
    }

    $dir = realpath(backupRoot() . '/' . $type);
    if ($dir === false) {
        return null;
    }

    $full = realpath($dir . DIRECTORY_SEPARATOR . $name);
    if ($full === false || !is_file($full)) {
        return null;
    }

    $dirN  = rtrim(str_replace('\\', '/', $dir), '/') . '/';
    $fullN = str_replace('\\', '/', $full);

    if (DIRECTORY_SEPARATOR === '\\') {
        return str_starts_with(strtolower($fullN), strtolower($dirN)) ? $full : null;
    }
    return str_starts_with($fullN, $dirN) ? $full : null;
}

/**
 * The filename a new archive is written under.
 *
 * Type, timestamp and eight random hex characters. The random tail is not
 * decoration: two backups started in the same second would otherwise collide,
 * and the name is also what an administrator sees when the file is copied
 * off-site, so it has to stay meaningful on its own.
 */
function backupArchiveName(string $type): string
{
    return sprintf(
        'saxane-%s-%s-%s.zip',
        $type,
        (new DateTimeImmutable('now', backupTimezone()))->format('Ymd-His'),
        bin2hex(random_bytes(4))
    );
}

/** RFC 4122 version 4 identifier — the only backup handle a browser ever sees. */
function backupUuid(): string
{
    $b = random_bytes(16);
    $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
    $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
}

/* ─────────────────────────────────────────────────────────────────────
   Locking
   ───────────────────────────────────────────────────────────────────── */

/**
 * Take the backup lock, or return null if another run holds it.
 *
 * Two atomic statements, no open transaction:
 *
 *   INSERT IGNORE  — wins when no row exists at all (the first ever run)
 *   UPDATE … WHERE expires_at < NOW()  — wins when the holder's lease died
 *
 * Both report through rowCount(), so two runners racing produce exactly one
 * winner however the timing falls. The returned token must be handed back to
 * backupLockRelease(); a runner that crashed and restarted cannot release the
 * lock its previous incarnation held, which is what stops a zombie process
 * from unlocking a backup that is genuinely running.
 */
function backupLockAcquire(string $owner, string $name = 'backup'): ?string
{
    $db    = getDBConnection();
    $token = bin2hex(random_bytes(16));
    $owner = mb_substr($owner, 0, 120);

    $insert = $db->prepare("
        INSERT IGNORE INTO backup_locks (lock_name, token, owner, acquired_at, heartbeat_at, expires_at)
        VALUES (:n, :t, :o, NOW(), NOW(), DATE_ADD(NOW(), INTERVAL :ttl SECOND))
    ");
    $insert->execute([':n' => $name, ':t' => $token, ':o' => $owner, ':ttl' => BACKUP_LOCK_TTL]);
    if ($insert->rowCount() === 1) {
        return $token;
    }

    // A row is already there. Take it over only if its lease has expired —
    // that is the stale-lock recovery, and the WHERE clause is what makes it
    // safe to attempt from several processes at once.
    $steal = $db->prepare("
        UPDATE backup_locks
           SET token = :t, owner = :o, acquired_at = NOW(), heartbeat_at = NOW(),
               expires_at = DATE_ADD(NOW(), INTERVAL :ttl SECOND)
         WHERE lock_name = :n AND expires_at < NOW()
    ");
    $steal->execute([':t' => $token, ':o' => $owner, ':n' => $name, ':ttl' => BACKUP_LOCK_TTL]);

    // Ownership is confirmed by reading the token back, not by rowCount().
    // MySQL reports *changed* rows rather than matched ones, so an UPDATE that
    // writes the values already present reports zero — and any check built on
    // rowCount() then declares a lock lost that is in fact held. Reading the
    // token is unambiguous however the update was counted.
    return backupLockHeldBy($token, $name) ? $token : null;
}

/**
 * Do we still hold this lock?
 *
 * The one authoritative ownership test, used by both the heartbeat and the
 * steal above. An expired lease is not held, even by the process that took it.
 */
function backupLockHeldBy(string $token, string $name = 'backup'): bool
{
    $stmt = getDBConnection()->prepare("
        SELECT 1 FROM backup_locks
         WHERE lock_name = :n AND token = :t AND expires_at >= NOW()
    ");
    $stmt->execute([':n' => $name, ':t' => $token]);

    return (bool) $stmt->fetchColumn();
}

/**
 * Extend the lease. Called between the phases of a long run.
 *
 * Returns false when the lock is no longer ours — the run has been declared
 * stale and taken over — which the engine treats as a reason to abort rather
 * than to carry on writing into a directory somebody else now owns.
 */
function backupLockHeartbeat(string $token, string $name = 'backup'): bool
{
    // Ownership first, extension second. The reverse order would have to read
    // the UPDATE's rowCount() to decide, and MySQL counts changed rows — two
    // heartbeats inside the same second write identical values, report zero,
    // and would abort a perfectly healthy backup.
    if (!backupLockHeldBy($token, $name)) {
        return false;
    }

    getDBConnection()->prepare("
        UPDATE backup_locks
           SET heartbeat_at = NOW(), expires_at = DATE_ADD(NOW(), INTERVAL :ttl SECOND)
         WHERE lock_name = :n AND token = :t
    ")->execute([':n' => $name, ':t' => $token, ':ttl' => BACKUP_LOCK_TTL]);

    return true;
}

/** Release the lock, but only if we still hold it. */
function backupLockRelease(string $token, string $name = 'backup'): void
{
    $stmt = getDBConnection()->prepare("DELETE FROM backup_locks WHERE lock_name = :n AND token = :t");
    $stmt->execute([':n' => $name, ':t' => $token]);
}

/**
 * Who holds the lock right now, or null.
 *
 * An expired lease reads as free, so the UI never says "a backup is running"
 * about a process that died three days ago.
 */
function backupLockHolder(string $name = 'backup'): ?array
{
    $stmt = getDBConnection()->prepare("
        SELECT owner, acquired_at, heartbeat_at, expires_at,
               TIMESTAMPDIFF(SECOND, acquired_at, NOW()) AS held_seconds
          FROM backup_locks
         WHERE lock_name = :n AND expires_at >= NOW()
    ");
    $stmt->execute([':n' => $name]);

    return $stmt->fetch() ?: null;
}

/* ─────────────────────────────────────────────────────────────────────
   Retention
   ───────────────────────────────────────────────────────────────────── */

/**
 * How long a backup of this class is kept, in days.
 *
 * Manual backups have no class of their own — an operator who names a backup
 * "Before system update" is making a recovery point, not filling a rota — so
 * they are kept for the daily period and can be protected individually.
 */
function backupRetentionDays(string $class): int
{
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        foreach (getDBConnection()->query("SELECT frequency, retention_days FROM backup_schedules")->fetchAll() as $r) {
            $cache[$r['frequency']] = (int) $r['retention_days'];
        }
    }

    return match ($class) {
        'daily', 'manual' => $cache['daily']   ?? 30,
        'weekly'          => $cache['weekly']  ?? 84,
        'monthly'         => $cache['monthly'] ?? 365,
        default           => 30,
    };
}

/**
 * The expiry stamped on a backup at creation, or null when it never expires.
 *
 * Resolved once, on write, rather than evaluated during the sweep: a policy
 * changed in March must not retroactively delete February's archives, and a
 * stored date is the only way to make that guarantee.
 */
function backupExpiryFor(string $class, bool $protected): ?string
{
    if ($protected) {
        return null;
    }
    $days = backupRetentionDays($class);

    // Built from the database's clock, because backupRetentionDue() compares
    // this column against MySQL's NOW(). Computing it from PHP's clock would
    // put every expiry an hour out on this installation.
    return date('Y-m-d H:i:s', time() + backupClockSkew() + max(1, $days) * 86400);
}

/**
 * Backups the retention policy says may go.
 *
 * Deliberately conservative. Excluded from the sweep:
 *   - protected recovery points, whatever their age
 *   - anything without an expiry
 *   - pending and running rows, which have no finished file to delete
 *   - rows already marked deleted
 *   - emergency copies taken for a restore that is still in flight
 *
 * The last one is the subtle case: the safety backup for a running restore
 * looks like an ordinary old archive to a date comparison, and deleting it
 * mid-restore removes the only way back.
 *
 * @return array<int, array<string, mixed>>
 */
function backupRetentionDue(): array
{
    return getDBConnection()->query("
        SELECT b.*
          FROM backups b
         WHERE b.is_protected = 0
           AND b.expires_at IS NOT NULL
           AND b.expires_at < NOW()
           AND b.status IN ('completed','verified','failed')
           AND NOT EXISTS (
                 SELECT 1 FROM backup_restores r
                  WHERE r.status IN ('pending','running')
                    AND (r.backup_id = b.id OR r.safety_backup_id = b.id)
               )
         ORDER BY b.expires_at ASC
    ")->fetchAll();
}

/* ─────────────────────────────────────────────────────────────────────
   Schedules
   ───────────────────────────────────────────────────────────────────── */

/** All three schedules, in cadence order. */
function backupSchedules(): array
{
    return getDBConnection()->query("
        SELECT * FROM backup_schedules
         ORDER BY FIELD(frequency, 'daily', 'weekly', 'monthly')
    ")->fetchAll();
}

/**
 * The next time this schedule should fire, as a wall-clock string in the
 * configured backup timezone.
 *
 * Computed in PHP rather than SQL because the answer depends on a timezone
 * MySQL has not been told about, and because "the 31st of a month with 30
 * days" needs a decision rather than an error — it is clamped to the last day
 * of that month, which is what an administrator who picked 31 meant.
 *
 * Returns null for an inactive schedule: an inactive schedule has no next run,
 * and returning a date for one is how a dashboard ends up promising a backup
 * that will never happen.
 */
function backupNextRun(array $schedule, ?DateTimeImmutable $after = null): ?string
{
    if (empty($schedule['is_active'])) {
        return null;
    }

    $tz    = backupTimezone();
    $after = $after ? $after->setTimezone($tz) : new DateTimeImmutable('now', $tz);

    [$h, $m] = array_map('intval', explode(':', (string) $schedule['run_at']) + [1 => '0']);
    $atTime  = static fn(DateTimeImmutable $d): DateTimeImmutable => $d->setTime($h, $m, 0);

    switch ($schedule['frequency']) {
        case 'daily':
            $next = $atTime($after);
            if ($next <= $after) {
                $next = $atTime($after->modify('+1 day'));
            }
            return $next->format('Y-m-d H:i:s');

        case 'weekly':
            // ISO day: 1 = Monday … 7 = Sunday, matching the column.
            $target = min(7, max(1, (int) $schedule['day_of_week']));
            $next   = $atTime($after);
            $delta  = ($target - (int) $next->format('N') + 7) % 7;
            $next   = $atTime($after->modify('+' . $delta . ' day'));
            if ($next <= $after) {
                $next = $atTime($next->modify('+7 day'));
            }
            return $next->format('Y-m-d H:i:s');

        case 'monthly':
            $wanted = min(31, max(1, (int) $schedule['day_of_month']));
            $cursor = $after->modify('first day of this month');
            for ($i = 0; $i < 3; $i++) {
                $len  = (int) $cursor->format('t');
                $next = $atTime($cursor->setDate(
                    (int) $cursor->format('Y'),
                    (int) $cursor->format('n'),
                    min($wanted, $len)      // 31st of February becomes the 28th
                ));
                if ($next > $after) {
                    return $next->format('Y-m-d H:i:s');
                }
                $cursor = $cursor->modify('first day of next month');
            }
            return null;
    }

    return null;
}

/** Write each schedule's next_run_at back, after an edit or a run. */
function backupRefreshNextRuns(): void
{
    $db   = getDBConnection();
    $stmt = $db->prepare("UPDATE backup_schedules SET next_run_at = :n WHERE id = :id");

    foreach (backupSchedules() as $s) {
        $stmt->execute([':n' => backupNextRun($s), ':id' => $s['id']]);
    }
}

/**
 * Schedules that are due right now.
 *
 * The comparison happens in PHP against the backup timezone, for the same
 * reason backupNextRun() computes there: next_run_at is wall-clock in that
 * zone, and asking MySQL to compare it with its own NOW() would silently
 * apply the server's timezone instead.
 *
 * A schedule whose next_run_at was never computed (freshly activated, or
 * activated before this code existed) is treated as due, so enabling a
 * schedule cannot leave it dormant forever waiting for a date nobody wrote.
 */
function backupDueSchedules(): array
{
    $now = (new DateTimeImmutable('now', backupTimezone()))->format('Y-m-d H:i:s');
    $due = [];

    foreach (backupSchedules() as $s) {
        if (empty($s['is_active'])) {
            continue;
        }
        if (empty($s['next_run_at']) || $s['next_run_at'] <= $now) {
            $due[] = $s;
        }
    }
    return $due;
}

/* ─────────────────────────────────────────────────────────────────────
   Storage accounting
   ───────────────────────────────────────────────────────────────────── */

/**
 * Real storage figures, from the rows and from the disk.
 *
 * `used` is the sum of the archives this application knows about, broken down
 * by type for the storage card. `disk_free` comes from the filesystem, and is
 * null when the platform will not say — reporting an unknown as zero would
 * turn "we cannot tell" into "the disk is full".
 *
 * @return array{used:int, by_type:array<string,array{count:int,bytes:int}>, count:int,
 *               quota:int, pct:?float, disk_free:?float, disk_total:?float}
 */
function backupStorageUsage(): array
{
    $rows = getDBConnection()->query("
        SELECT type, COUNT(*) AS n, COALESCE(SUM(file_size), 0) AS bytes
          FROM backups
         WHERE status IN ('completed','verified')
         GROUP BY type
    ")->fetchAll();

    $byType = [];
    foreach (array_keys(backupTypes()) as $t) {
        $byType[$t] = ['count' => 0, 'bytes' => 0];
    }
    $used = $count = 0;
    foreach ($rows as $r) {
        $byType[$r['type']] = ['count' => (int) $r['n'], 'bytes' => (int) $r['bytes']];
        $used  += (int) $r['bytes'];
        $count += (int) $r['n'];
    }

    $quota = backupStorageQuotaBytes();
    $root  = backupRoot();
    $free  = is_dir($root) ? @disk_free_space($root)  : false;
    $total = is_dir($root) ? @disk_total_space($root) : false;

    return [
        'used'       => $used,
        'by_type'    => $byType,
        'count'      => $count,
        'quota'      => $quota,
        // Percentage of the configured quota, or of the disk when no quota is
        // set and the platform reports one. Null when neither is knowable.
        'pct'        => $quota > 0
            ? min(100.0, round($used / $quota * 100, 1))
            : ($total !== false && $total > 0 ? round($used / $total * 100, 1) : null),
        'disk_free'  => $free  === false ? null : (float) $free,
        'disk_total' => $total === false ? null : (float) $total,
    ];
}

/* ─────────────────────────────────────────────────────────────────────
   Health
   ───────────────────────────────────────────────────────────────────── */

/**
 * The system's backup health, from real rows and real disk state.
 *
 * Three levels, and the rule for each is written down rather than felt:
 *
 *   critical  nothing to restore from, the RPO is breached, failures are
 *             repeating, or the archives are reachable over HTTP
 *   warning   backups are ageing, unverified, unscheduled, or storage is
 *             running out
 *   healthy   a verified backup exists inside the RPO and nothing above
 *             applies
 *
 * Every finding carries the fact that produced it, because "Warning" with no
 * reason is a badge, not a diagnosis. The findings are what the dashboard
 * lists under the health tile.
 *
 * @return array{level:string, label:string, tone:string, findings:array<int,array{tone:string,text:string}>,
 *               last_backup:?array, last_verified:?array, rpo_hours:int, hours_since:?int}
 */
function backupHealth(): array
{
    $db = getDBConnection();

    $lastBackup = $db->query("
        SELECT id, public_id, name, type, status, verification_status, completed_at,
               TIMESTAMPDIFF(HOUR, completed_at, NOW()) AS hours_ago
          FROM backups
         WHERE status IN ('completed','verified')
         ORDER BY completed_at DESC LIMIT 1
    ")->fetch() ?: null;

    $lastVerified = $db->query("
        SELECT id, public_id, name, type, verified_at,
               TIMESTAMPDIFF(HOUR, verified_at, NOW()) AS hours_ago
          FROM backups
         WHERE verification_status = 'passed' AND status = 'verified'
         ORDER BY verified_at DESC LIMIT 1
    ")->fetch() ?: null;

    // Consecutive failures, newest first — a run that succeeded ends the
    // streak. "Two failures last week and a success since" is not a repeating
    // failure, and treating it as one trains people to ignore the badge.
    $recent = $db->query("
        SELECT status FROM backups
         WHERE status IN ('completed','verified','failed')
         ORDER BY COALESCE(completed_at, created_at) DESC
         LIMIT " . (int) BACKUP_HEALTH_WINDOW
    )->fetchAll(PDO::FETCH_COLUMN);

    $streak = 0;
    foreach ($recent as $s) {
        if ($s !== 'failed') {
            break;
        }
        $streak++;
    }

    $rpo      = backupRpoHours();
    $hoursAgo = $lastBackup ? (int) $lastBackup['hours_ago'] : null;
    $storage  = backupStorageUsage();
    $findings = [];
    $level    = 'healthy';

    $raise = static function (string $want, string &$level): void {
        $rank  = ['healthy' => 0, 'warning' => 1, 'critical' => 2];
        $level = $rank[$want] > $rank[$level] ? $want : $level;
    };

    /* ── Do we have anything at all? ── */
    if (!$lastBackup) {
        $findings[] = ['tone' => 'danger', 'text' => 'No completed backup exists. Nothing could be restored right now.'];
        $raise('critical', $level);
    } elseif ($hoursAgo !== null && $hoursAgo > $rpo) {
        $findings[] = ['tone' => 'danger', 'text' => sprintf(
            'The most recent backup is %d hours old, past the %d-hour recovery point objective.',
            $hoursAgo, $rpo
        )];
        $raise('critical', $level);
    } elseif ($hoursAgo !== null && $hoursAgo > (int) ($rpo * 0.75)) {
        $findings[] = ['tone' => 'warning', 'text' => sprintf(
            'The most recent backup is %d hours old and approaching the %d-hour objective.',
            $hoursAgo, $rpo
        )];
        $raise('warning', $level);
    }

    /* ── Is it proven, or merely present? ── */
    if ($lastBackup && !$lastVerified) {
        $findings[] = ['tone' => 'warning', 'text' => 'No backup has passed verification. An unverified archive is not a recovery guarantee.'];
        $raise('warning', $level);
    } elseif ($lastBackup && $lastVerified && (int) $lastVerified['hours_ago'] > $rpo * 2) {
        $findings[] = ['tone' => 'warning', 'text' => sprintf(
            'The newest verified backup is %d hours old. Newer backups exist but have not been verified.',
            (int) $lastVerified['hours_ago']
        )];
        $raise('warning', $level);
    }

    /* ── Are failures repeating? ── */
    if ($streak >= backupFailureThreshold()) {
        $findings[] = ['tone' => 'danger', 'text' => sprintf(
            '%d backup%s failed in a row. The cause has not cleared on its own.',
            $streak, $streak === 1 ? '' : 's'
        )];
        $raise('critical', $level);
    } elseif ($streak > 0) {
        $findings[] = ['tone' => 'warning', 'text' => 'The most recent backup run failed.'];
        $raise('warning', $level);
    }

    /* ── Is anything scheduled, and is anything running the schedule? ──
       These two questions are asked together because separately each has a
       reassuring answer. "Three schedules are active" says nothing if no
       runner exists to act on them, and that is precisely the state this
       module was found in: a daily schedule enabled, its next run two days in
       the past, and a Critical badge that blamed the missing backup rather
       than the missing scheduler. The finding has to name the cause, because
       the cure — install the task — is not one anybody guesses from
       "no completed backup exists". */
    $activeSchedules = (int) $db->query("SELECT COUNT(*) FROM backup_schedules WHERE is_active = 1")->fetchColumn();
    if ($activeSchedules === 0) {
        $findings[] = ['tone' => 'warning', 'text' => 'No automatic schedule is enabled. Every backup has to be started by hand.'];
        $raise('warning', $level);
    } else {
        $scheduler = backupSchedulerState();

        if (!$scheduler['installed']) {
            $findings[] = ['tone' => 'danger', 'text' => sprintf(
                '%d automatic schedule%s enabled, but the backup scheduler has never run. '
                . 'Nothing is acting on them — install the scheduled task described on Backup Settings.',
                $activeSchedules, $activeSchedules === 1 ? ' is' : 's are'
            )];
            $raise('critical', $level);
        } elseif ($scheduler['stale']) {
            $findings[] = ['tone' => 'danger', 'text' => sprintf(
                'The backup scheduler last ran %s and should run every few minutes. '
                . 'It has stopped, so no schedule can fire.',
                backupAgo($scheduler['last_tick'])
            )];
            $raise('critical', $level);
        }

        // Overdue is reported separately from stale. A scheduler that is
        // ticking but leaving a schedule behind is a different fault — a run
        // that fails to start every time, most often — and the fix is not the
        // same one.
        if ($scheduler['installed'] && !$scheduler['stale']) {
            foreach (backupOverdueSchedules() as $late) {
                $findings[] = ['tone' => 'warning', 'text' => sprintf(
                    'The %s schedule was due at %s and has not run — it is %s late.',
                    $late['frequency'],
                    backupScheduleWhen($late['next_run_at'], 'M d, H:i'),
                    $late['minutes_late'] >= 120
                        ? round($late['minutes_late'] / 60) . ' hours'
                        : $late['minutes_late'] . ' minutes'
                )];
                $raise('warning', $level);
            }
        }
    }

    /* ── Is a run stuck behind a lock? ──
       A live lock is normal and says only that a backup is in progress. One
       held past the full lease is not: the lease is what makes a crashed run
       recoverable, and a holder that old means either a genuinely enormous
       backup or a process that died without releasing. Said out loud because
       from the outside both look like "nothing is happening". */
    $lock = backupLockHolder();
    if ($lock !== null && (int) $lock['held_seconds'] > BACKUP_LOCK_TTL) {
        $findings[] = ['tone' => 'warning', 'text' => sprintf(
            'A backup lock has been held by %s since %s. If no backup is really running it will '
            . 'clear itself once the lease expires.',
            $lock['owner'], backupWhen($lock['acquired_at'])
        )];
        $raise('warning', $level);
    }

    /* ── Storage ── */
    if ($storage['quota'] > 0 && $storage['used'] >= $storage['quota']) {
        $findings[] = ['tone' => 'danger', 'text' => 'The configured storage quota is exhausted. New backups will fail.'];
        $raise('critical', $level);
    } elseif ($storage['pct'] !== null && $storage['pct'] >= 85) {
        $findings[] = ['tone' => 'warning', 'text' => sprintf('Backup storage is %.1f%% used.', $storage['pct'])];
        $raise('warning', $level);
    }
    if ($storage['disk_free'] !== null && $storage['disk_free'] < 512 * 1024 * 1024) {
        $findings[] = ['tone' => 'danger', 'text' => 'Less than 512 MB of disk space remains on the backup volume.'];
        $raise('critical', $level);
    }

    /* ── Is the store itself sound? ── */
    if (backupRootIsExposed()) {
        $findings[] = ['tone' => 'danger', 'text' => 'The backup directory is inside the web root and may be reachable over HTTP. Move BACKUP_PATH outside it.'];
        $raise('critical', $level);
    }
    foreach (backupEnsureStorage() as $problem) {
        $findings[] = ['tone' => 'danger', 'text' => $problem];
        $raise('critical', $level);
    }
    if (backupBinary('mysqldump') === null) {
        $findings[] = ['tone' => 'danger', 'text' => 'mysqldump was not found. Database backups cannot run — set MYSQLDUMP_PATH in .env.'];
        $raise('critical', $level);
    }
    if (!class_exists('ZipArchive')) {
        $findings[] = ['tone' => 'danger', 'text' => 'The PHP zip extension is not enabled. Archives cannot be created.'];
        $raise('critical', $level);
    }

    if (!$findings) {
        $findings[] = ['tone' => 'success', 'text' => sprintf(
            'A verified backup exists from %s.',
            timeAgo($lastVerified['verified_at'] ?? null)
        )];
    }

    return [
        'level'         => $level,
        'label'         => ['healthy' => 'Healthy', 'warning' => 'Warning', 'critical' => 'Critical'][$level],
        'tone'          => ['healthy' => 'success', 'warning' => 'warning', 'critical' => 'danger'][$level],
        'findings'      => $findings,
        'last_backup'   => $lastBackup,
        'last_verified' => $lastVerified,
        'rpo_hours'     => $rpo,
        'hours_since'   => $hoursAgo,
    ];
}

/* ─────────────────────────────────────────────────────────────────────
   Scheduler — proof of life, and the log nobody is watching
   ─────────────────────────────────────────────────────────────────────

   Everything above this point describes what the backup system intends.
   This section is the only part that can say whether anything is carrying
   those intentions out, and it exists because the answer used to be
   unobtainable: a schedule row said "daily, active, next run 04:00" for two
   days after that time had passed, and no value anywhere recorded that the
   runner which turns such a row into an archive had never been started.

   Two mechanisms, deliberately independent.

   The heartbeat is in the database, so the dashboard can read it. Every tick
   of the runner writes it, whether or not a backup was due — a scheduler that
   checks and finds nothing to do is working, and a design that only recorded
   runs would be silent for the twenty-three hours a day when a daily schedule
   is not due, which is exactly the window in which somebody disables the task
   by accident.

   The log is on disk, so it survives the database. A scheduled process has no
   console: Windows Task Scheduler and cron both discard stdout, and the first
   thing anybody asks after a missed backup is what the last run said. Writing
   it to a file inside the backup root — already outside the web root, already
   guarded — is the difference between a diagnosis and a shrug.
   ───────────────────────────────────────────────────────────────────── */

/** The active scheduler log file. */
function backupLogPath(): string
{
    return backupDir('logs') . '/scheduler.log';
}

/**
 * Append one line to the scheduler log, rotating it when it gets large.
 *
 * The format is fixed and greppable — timestamp, level, pid, message, then any
 * context as key=value — because these lines are read during an incident by
 * somebody who has never seen them before. Structured JSON would be tidier and
 * slower to read at three in the morning.
 *
 * Never throws. A backup must not fail because its log could not be written,
 * and a caller that had to guard every log call would stop calling it.
 */
function backupLog(string $level, string $message, array $context = []): void
{
    try {
        $path = backupLogPath();

        // Rotate before writing, so the cap is a ceiling rather than a
        // suggestion the last oversized line gets to ignore.
        if (is_file($path) && filesize($path) >= BACKUP_LOG_MAX_BYTES) {
            $oldest = $path . '.' . BACKUP_LOG_KEEP;
            if (is_file($oldest)) {
                @unlink($oldest);
            }
            for ($i = BACKUP_LOG_KEEP - 1; $i >= 1; $i--) {
                if (is_file($path . '.' . $i)) {
                    @rename($path . '.' . $i, $path . '.' . ($i + 1));
                }
            }
            @rename($path, $path . '.1');
        }

        $line = sprintf(
            '%s [%-5s] pid=%d %s',
            (new DateTimeImmutable('now', backupTimezone()))->format('Y-m-d H:i:s T'),
            strtoupper($level),
            function_exists('getmypid') ? (int) getmypid() : 0,
            $message
        );

        foreach ($context as $k => $v) {
            if ($v === null || $v === '') {
                continue;
            }
            // Values are flattened onto one line: a multi-line stderr dump from
            // mysqldump would otherwise break the one-record-per-line rule that
            // makes this file greppable.
            $line .= ' ' . $k . '=' . str_replace(["\r", "\n"], ' ', (string) $v);
        }

        @file_put_contents($path, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
    } catch (Throwable $e) {
        // The only place in this module that swallows an exception, and it
        // earns it: the alternative is a logging failure masking the error it
        // was called to record. error_log() still receives it.
        error_log('Backup log write failed: ' . $e->getMessage());
    }
}

/** How long the scheduler may stay silent before it is presumed stopped. */
function backupSchedulerStaleMinutes(): int
{
    return max(5, BACKUP_SCHEDULER_STALE_MINUTES);
}

/**
 * What the scheduler itself has been doing.
 *
 * Read straight from the table rather than through setting(), which caches for
 * the life of the request: the runner writes its heartbeat during a tick and
 * reads it back afterwards, and a cached value would report the state of the
 * previous run.
 *
 * `installed` is the honest question — has this ever ticked at all — and is
 * what separates "the schedule has not come round yet" from "nothing is
 * listening".
 *
 * @return array{installed:bool, last_tick:?string, ago:string, minutes_since:?int,
 *               stale:bool, last_result:string, tick_count:int, host:string}
 */
function backupSchedulerState(): array
{
    $rows = [];
    try {
        $stmt = getDBConnection()->query("
            SELECT setting_key, setting_value
              FROM settings
             WHERE setting_key IN ('backup_scheduler_last_tick','backup_scheduler_last_result',
                                   'backup_scheduler_tick_count','backup_scheduler_host')
        ");
        foreach ($stmt->fetchAll() as $r) {
            $rows[$r['setting_key']] = (string) $r['setting_value'];
        }
    } catch (Throwable $e) {
        // A missing settings table is a bare install, not a stalled scheduler.
        // Reported below as "never ticked", which is true either way.
    }

    $tick    = trim($rows['backup_scheduler_last_tick'] ?? '');
    $lastTs  = $tick !== '' ? strtotime($tick) : false;
    $minutes = $lastTs === false
        ? null
        : max(0, (int) floor((time() + backupClockSkew() - $lastTs) / 60));

    return [
        'installed'     => $tick !== '',
        'last_tick'     => $tick !== '' ? $tick : null,
        'ago'           => backupAgo($tick !== '' ? $tick : null),
        'minutes_since' => $minutes,
        'stale'         => $tick !== '' && $minutes !== null && $minutes > backupSchedulerStaleMinutes(),
        'last_result'   => trim($rows['backup_scheduler_last_result'] ?? ''),
        'tick_count'    => (int) ($rows['backup_scheduler_tick_count'] ?? 0),
        'host'          => trim($rows['backup_scheduler_host'] ?? ''),
    ];
}

/**
 * Record that the scheduler ran, and what it found.
 *
 * Called on every tick, including the overwhelmingly common one that finds
 * nothing due. INSERT … ON DUPLICATE KEY UPDATE rather than a plain UPDATE so
 * the heartbeat also works on an installation where the migration has not been
 * applied — the first tick creates its own rows, and a diagnostic that needs a
 * migration before it can tell you the migration is missing is not a
 * diagnostic.
 */
function backupSchedulerRecordTick(string $result): void
{
    $host = (function_exists('gethostname') ? (string) gethostname() : 'unknown')
          . ' · ' . (PHP_SAPI === 'cli' ? 'cli' : PHP_SAPI)
          . ' · php ' . PHP_VERSION;

    $values = [
        'backup_scheduler_last_tick'   => backupDbNow(),
        'backup_scheduler_last_result' => mb_substr($result, 0, 500),
        'backup_scheduler_host'        => mb_substr($host, 0, 500),
    ];

    try {
        $db   = getDBConnection();
        $stmt = $db->prepare("
            INSERT INTO settings (setting_key, setting_value, setting_group)
            VALUES (:k, :v, 'backup')
            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
        ");
        foreach ($values as $k => $v) {
            $stmt->execute([':k' => $k, ':v' => $v]);
        }

        // Incremented in SQL rather than read-modify-written in PHP, so two
        // ticks that overlap cannot both write the same number back.
        $db->prepare("
            INSERT INTO settings (setting_key, setting_value, setting_group)
            VALUES ('backup_scheduler_tick_count', '1', 'backup')
            ON DUPLICATE KEY UPDATE setting_value = CAST(setting_value AS UNSIGNED) + 1
        ")->execute();
    } catch (Throwable $e) {
        backupLog('error', 'Could not record the scheduler heartbeat.', ['error' => $e->getMessage()]);
    }
}

/**
 * Active schedules whose next run has already passed, with how late each is.
 *
 * A few minutes late is normal — the runner ticks on an interval, not on the
 * second — so lateness is only interesting once it exceeds one stale window.
 * That keeps this from firing about a schedule due at 04:00 read at 04:03.
 *
 * @return array<int, array{frequency:string, next_run_at:string, minutes_late:int}>
 */
function backupOverdueSchedules(): array
{
    $now  = new DateTimeImmutable('now', backupTimezone());
    $late = [];

    foreach (backupSchedules() as $s) {
        if (empty($s['is_active']) || empty($s['next_run_at'])) {
            continue;
        }
        try {
            $due = new DateTimeImmutable((string) $s['next_run_at'], backupTimezone());
        } catch (Throwable $e) {
            continue;
        }
        $minutes = (int) floor(($now->getTimestamp() - $due->getTimestamp()) / 60);
        if ($minutes > backupSchedulerStaleMinutes()) {
            $late[] = [
                'frequency'    => (string) $s['frequency'],
                'next_run_at'  => (string) $s['next_run_at'],
                'minutes_late' => $minutes,
            ];
        }
    }
    return $late;
}

/**
 * The exact command that runs the scheduler on this installation.
 *
 * Derived from PHP_BINARY when this process is itself a CLI one, and from the
 * platform's convention otherwise, so the string on the settings page is the
 * one that will actually work rather than an example to be adapted. Both parts
 * are absolute: the runner resolves everything from __DIR__, and the command
 * that starts it must not need a working directory either.
 *
 * @return array{php:string, script:string, command:string, task_name:string}
 */
function backupSchedulerCommand(): array
{
    $windows = DIRECTORY_SEPARATOR === '\\';

    // PHP_BINARY under Apache is httpd, not something that can run a script,
    // so it is only trusted when this process is a CLI one.
    $php = PHP_SAPI === 'cli' && PHP_BINARY !== '' && is_file(PHP_BINARY)
        ? PHP_BINARY
        : ($windows ? 'php.exe' : 'php');

    // Under the web server, guess the XAMPP layout — htdocs/<project>/<app>
    // puts php.exe three levels up — and fall back to the documented default
    // so the page always shows something runnable.
    if ($windows && !is_file($php)) {
        foreach ([dirname(BASE_PATH, 3) . '/php/php.exe', 'D:/XAMPP/php/php.exe', 'C:/xampp/php/php.exe'] as $guess) {
            if (is_file($guess)) {
                $php = $guess;
                break;
            }
        }
    }

    $script = BASE_PATH . '/database/tools/run_backups.php';

    if ($windows) {
        $php    = str_replace('/', '\\', $php);
        $script = str_replace('/', '\\', $script);
    }

    return [
        'php'       => $php,
        'script'    => $script,
        'command'   => '"' . $php . '" "' . $script . '"',
        'task_name' => BACKUP_TASK_NAME,
    ];
}

/**
 * Is the Windows scheduled task registered?
 *
 * Returns null on anything that is not Windows, and on any answer schtasks
 * gives that is not a plain yes or no — "we could not check" and "it is not
 * there" are different findings, and the health panel must not print the
 * second when it means the first.
 */
function backupWindowsTaskInstalled(): ?bool
{
    if (DIRECTORY_SEPARATOR !== '\\' || !function_exists('proc_open')) {
        return null;
    }

    $spec  = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $pipes = [];
    $proc  = @proc_open(['schtasks', '/Query', '/TN', BACKUP_TASK_NAME], $spec, $pipes);
    if (!is_resource($proc)) {
        return null;
    }
    foreach ($pipes as $pipe) {
        stream_get_contents($pipe);
        fclose($pipe);
    }

    // 0 = the task exists, 1 = it does not. Anything else — access denied, no
    // schtasks on PATH — is "cannot tell".
    return match (proc_close($proc)) {
        0       => true,
        1       => false,
        default => null,
    };
}

/**
 * What Windows itself says about the last few runs of the scheduled task.
 *
 * There is one failure this module cannot see from the inside: the runner
 * dying before its own error handlers are installed. A parse error in the
 * runner, or in anything it requires, kills PHP at compile time — nothing
 * reaches the scheduler log, and the heartbeat simply stops. The dashboard
 * then reports "the scheduler has stopped" and cannot say why.
 *
 * Windows knows. It records an exit code for every run, and a script PHP
 * cannot compile exits 255. Reading it turns "stopped, cause unknown" into
 * "the task fired at 11:00 and the runner exited 255", which is the difference
 * between an afternoon and a minute.
 *
 * Deliberately NOT called from a page render — it spawns PowerShell, which
 * costs a few hundred milliseconds. It belongs to the on-demand diagnostic,
 * where somebody is already waiting for an answer.
 *
 * PowerShell rather than parsing schtasks output because Get-ScheduledTaskInfo
 * returns fields by name: schtasks prints its column headings in the system
 * language, and a diagnostic that works only on English Windows fails exactly
 * where help is hardest to come by.
 *
 * @return array{last_run:?string, last_result:?int, next_run:?string}|null
 */
function backupWindowsTaskInfo(): ?array
{
    if (DIRECTORY_SEPARATOR !== '\\' || !function_exists('proc_open')) {
        return null;
    }

    $script = "try { Get-ScheduledTaskInfo -TaskName '" . str_replace("'", "''", BACKUP_TASK_NAME) . "' -ErrorAction Stop"
            . " | Select-Object LastRunTime, LastTaskResult, NextRunTime | ConvertTo-Json -Compress } catch { '' }";

    $pipes = [];
    $proc  = @proc_open(
        ['powershell', '-NoProfile', '-NonInteractive', '-Command', $script],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes
    );
    if (!is_resource($proc)) {
        return null;
    }

    $json = (string) stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    proc_close($proc);

    $data = json_decode(trim($json), true);
    if (!is_array($data)) {
        return null;
    }

    /* PowerShell serialises a DateTime as "/Date(1757062800000)/" — milliseconds
       since the epoch. Rendered in the backup timezone, not PHP's: every other
       time the diagnostic prints is on that clock, and two adjacent lines an
       hour apart because one came from Windows would be read as a fault rather
       than as a formatting choice. Anything that does not match the shape is
       passed through as written rather than guessed at. */
    $stamp = static function ($value): ?string {
        if (!is_string($value) || $value === '') {
            return null;
        }
        if (!preg_match('#/Date\((-?\d+)#', $value, $m)) {
            return $value;
        }
        return (new DateTimeImmutable('@' . (int) round(((int) $m[1]) / 1000)))
            ->setTimezone(backupTimezone())
            ->format('Y-m-d H:i:s');
    };

    return [
        'last_run'    => $stamp($data['LastRunTime'] ?? null),
        'last_result' => isset($data['LastTaskResult']) ? (int) $data['LastTaskResult'] : null,
        'next_run'    => $stamp($data['NextRunTime'] ?? null),
    ];
}

/**
 * What a Windows task exit code means, in the terms this module uses.
 *
 * Only the codes the runner itself produces, plus the two Windows contributes
 * for a task that is running or has never run. An unrecognised code is reported
 * as itself rather than guessed at.
 */
function backupTaskResultText(int $code): string
{
    return match ($code) {
        0       => 'success',
        1       => 'a backup failed — see the scheduler log',
        2       => 'the runner could not start (bootstrap, configuration or database)',
        255     => 'PHP could not run the script at all — a parse error, or php.exe cannot read it',
        267009  => 'currently running',
        267011  => 'has not run yet',
        default => 'exit code ' . $code,
    };
}

/* ─────────────────────────────────────────────────────────────────────
   Maintenance mode
   ───────────────────────────────────────────────────────────────────── */

/**
 * The maintenance flag, kept as a file rather than a setting row.
 *
 * It has to work when the database is mid-restore — which is exactly when a
 * settings lookup would either read a half-restored table or fail outright —
 * so it lives on disk, inside the backup root where the web server cannot
 * serve it.
 */
function backupMaintenanceFile(): string
{
    return backupRoot() . '/.maintenance';
}

/** Turn maintenance mode on, recording who did it and why. */
function backupMaintenanceEnable(string $reason, ?int $byUserId = null): bool
{
    backupEnsureStorage();

    return (bool) @file_put_contents(backupMaintenanceFile(), json_encode([
        'reason'     => $reason,
        'by'         => $byUserId,
        'started_at' => date('Y-m-d H:i:s'),
    ], JSON_UNESCAPED_SLASHES));
}

/** Turn it off. Safe to call when it is already off. */
function backupMaintenanceDisable(): void
{
    $file = backupMaintenanceFile();
    if (is_file($file)) {
        @unlink($file);
    }
}

/** Is maintenance mode on, and what was said about it? Null when off. */
function backupMaintenanceInfo(): ?array
{
    $file = backupMaintenanceFile();
    if (!is_file($file)) {
        return null;
    }
    $raw = @file_get_contents($file);
    $data = $raw === false ? null : json_decode($raw, true);

    return is_array($data) ? $data : ['reason' => 'Maintenance in progress.', 'by' => null, 'started_at' => null];
}

/* ─────────────────────────────────────────────────────────────────────
   Audit
   ───────────────────────────────────────────────────────────────────── */

/**
 * Audit a backup action through the application's existing trail.
 *
 * A thin wrapper over logAudit() for one reason: logAudit() takes its actor
 * from the session, and a scheduled run has no session. Left alone, every
 * automatic backup would be recorded against a null user and read exactly
 * like a bug. The actor is therefore named in the payload — "Scheduler (CLI)"
 * — so the audit screen can tell an unattended run from an unattributed one.
 *
 * This is a wrapper, not a second audit system: the rows land in audit_logs
 * and are read by AuditController like everything else.
 */
function backupAudit(string $action, int $entityId = 0, string $old = '', string $new = ''): void
{
    if (PHP_SAPI === 'cli' && empty($_SESSION['user_id'])) {
        $new = trim('Scheduler (CLI) · ' . $new, ' ·');
    }
    logAudit($action, 'backup', $entityId, $old, $new);
}

/**
 * The module's recent activity, read back out of the audit trail.
 *
 * The feed on the dashboard is not a separate activity log — it is a filtered
 * view of audit_logs, which is what keeps the two from ever disagreeing about
 * what happened.
 *
 * @return array<int, array<string, mixed>>
 */
function backupRecentActivity(int $limit = 8): array
{
    $stmt = getDBConnection()->prepare("
        SELECT a.action, a.entity_id, a.new_value, a.created_at, u.full_name, u.avatar
          FROM audit_logs a
          LEFT JOIN users u ON a.user_id = u.id
         WHERE a.entity_type = 'backup'
         ORDER BY a.created_at DESC, a.id DESC
         LIMIT :l
    ");
    $stmt->bindValue(':l', max(1, $limit), PDO::PARAM_INT);
    $stmt->execute();

    return $stmt->fetchAll();
}

/**
 * Presentation for one audit action: icon, tone and a readable verb.
 *
 * Unknown actions fall back to a neutral shape rather than being dropped, so
 * an action added later still appears in the feed while it waits for an entry
 * here.
 */
function backupActivityMeta(string $action): array
{
    return [
        'created_backup'   => ['bi-plus-circle',          'info',    'Backup created'],
        'completed_backup' => ['bi-check-circle',         'success', 'Backup completed'],
        'verified_backup'  => ['bi-patch-check',          'success', 'Backup verified'],
        'failed_backup'    => ['bi-x-octagon',            'danger',  'Backup failed'],
        'downloaded_backup'=> ['bi-download',             'info',    'Backup downloaded'],
        'deleted_backup'   => ['bi-trash',                'warning', 'Backup deleted'],
        'expired_backup'   => ['bi-hourglass-bottom',     'warning', 'Backup expired'],
        'protected_backup' => ['bi-shield-lock',          'info',    'Retention hold changed'],
        'started_restore'  => ['bi-arrow-counterclockwise','warning', 'Restore started'],
        'completed_restore'=> ['bi-check2-circle',        'success', 'Restore completed'],
        'failed_restore'   => ['bi-exclamation-octagon',  'danger',  'Restore failed'],
        'updated_backup_settings' => ['bi-sliders',       'info',    'Backup settings changed'],
    ][$action] ?? ['bi-record-circle', 'info', uiLabel($action)];
}
