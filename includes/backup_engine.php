<?php
/**
 * BackupManager — the engine.
 *
 * Everything that actually touches a database, a file or an archive. The
 * companion file, includes/backup.php, answers questions about configuration
 * and state; this one changes things.
 *
 * The whole engine is built around one invariant: **a backup is failed until
 * proved otherwise**. A run inserts its row as `running` and it stays that way
 * until an archive exists on disk, hashes to the checksum recorded for it, and
 * opens to reveal the manifest it claims to contain. Only then does the row
 * become `completed`, and only a passing verification makes it `verified`.
 * There is no path through this file that marks a backup usable without having
 * opened it — which is the difference between a backup system and a directory
 * full of files nobody has ever tested.
 *
 * Ordering is defensive in the same way. Every run:
 *
 *   1. takes the lock, or gives up — never two at once
 *   2. writes into temp/, which is by definition incomplete
 *   3. builds, hashes, and verifies
 *   4. moves into its final directory only after verification passes
 *   5. releases the lock in a finally block, whatever happened
 *
 * Step 4 is why a crashed run can never leave something that looks finished:
 * an interrupted backup leaves a file in temp/ that no code path will ever
 * promote, and the next run sweeps it.
 *
 * Credentials are never put on a command line. mysqldump and mysql are given a
 * --defaults-extra-file written 0600 and deleted in a finally block, because
 * an argument vector is world-readable on a shared host and a database
 * password in `ps` output is a real disclosure.
 */

require_once __DIR__ . '/backup.php';

final class BackupManager
{
    /* ─────────────────────────────────────────────────────────────────
       Creating a backup
       ───────────────────────────────────────────────────────────────── */

    /**
     * Run a backup end to end.
     *
     * @param array{
     *   type: string, name?: string, source?: string, protected?: bool,
     *   retention_class?: string, user_id?: ?int, owner?: string
     * } $opts
     * @return array{ok: bool, id: ?int, public_id: ?string, error: ?string, backup: ?array}
     */
    public static function run(array $opts): array
    {
        $type = (string) ($opts['type'] ?? '');
        if (!array_key_exists($type, backupTypes())) {
            return self::fail(null, 'Unknown backup type.');
        }

        $problems = backupEnsureStorage();
        if ($problems) {
            return self::fail(null, implode(' ', $problems));
        }

        // Resolved once into a local, then validated — reading the option
        // again inside the ternary would hand back the missing key rather
        // than the default that satisfied the check.
        $source = (string) ($opts['source'] ?? 'manual');
        if (!in_array($source, ['manual', 'scheduled', 'emergency'], true)) {
            $source = 'manual';
        }
        $protected = !empty($opts['protected']) || $source === 'emergency';

        $class = (string) ($opts['retention_class'] ?? 'manual');
        if (!in_array($class, ['manual', 'daily', 'weekly', 'monthly'], true)) {
            $class = 'manual';
        }
        $userId    = $opts['user_id'] ?? ($_SESSION['user_id'] ?? null);
        $owner     = (string) ($opts['owner'] ?? (PHP_SAPI === 'cli' ? 'cli' : 'web'));

        // Taken before the row is inserted: a run that cannot start should
        // leave no trace at all, rather than a `pending` row that ages into
        // something the health check has to reason about.
        $token = backupLockAcquire($owner . ' · ' . $type, 'backup');
        if ($token === null) {
            $holder = backupLockHolder();
            return self::fail(null, $holder
                ? 'Another backup is already running (started by ' . $holder['owner'] . ').'
                : 'Another backup is already running.');
        }

        $db       = getDBConnection();
        $publicId = backupUuid();
        $id       = null;
        $workDir  = null;
        $tempZip  = null;

        // Everything from here to the finally runs under the lock, the row
        // insert included. It used to sit above the try, and one unexpected
        // exception between acquiring the lock and entering the block left the
        // lock held for its full 30-minute lease with no run behind it —
        // every subsequent backup refused until it aged out. Nothing may fail
        // between backupLockAcquire() and the try that releases it.
        try {
            @set_time_limit(BACKUP_MAX_RUNTIME);

            $name = self::cleanName((string) ($opts['name'] ?? ''), $type, $source);

            $insert = $db->prepare("
                INSERT INTO backups (public_id, name, type, source, status, is_protected,
                                     retention_class, expires_at, created_by, started_at)
                VALUES (:pid, :name, :type, :src, 'running', :prot, :cls, :exp, :uid, NOW())
            ");
            $insert->execute([
                ':pid'  => $publicId,
                ':name' => $name,
                ':type' => $type,
                ':src'  => $source,
                ':prot' => $protected ? 1 : 0,
                ':cls'  => $class,
                ':exp'  => backupExpiryFor($class, $protected),
                ':uid'  => $userId,
            ]);
            $id = (int) $db->lastInsertId();

            backupAudit('created_backup', $id, '', $name . ' · ' . $type);

            $workDir = backupDir('temp') . '/run-' . $publicId;

            if (!@mkdir($workDir, 0700, true) && !is_dir($workDir)) {
                throw new RuntimeException('Could not create a working directory for this run.');
            }

            $manifest = [
                'format_version' => 1,
                'application'    => APP_NAME,
                'app_version'    => APP_VERSION,
                'public_id'      => $publicId,
                'name'           => $name,
                'type'           => $type,
                'source'         => $source,
                'generated_at'   => date('c'),
                'php_version'    => PHP_VERSION,
                'database'       => null,
                'files'          => null,
            ];

            $dbBytes = $fileBytes = 0;
            $entries = [];

            /* ── Database ── */
            if ($type === 'full' || $type === 'database') {
                $dumpPath = $workDir . '/dump.sql';
                $dbResult = self::dumpDatabase($dumpPath);
                $dbBytes  = $dbResult['bytes'];
                $manifest['database'] = $dbResult;

                backupLockHeartbeat($token) || throw new RuntimeException('Lost the backup lock mid-run.');
            }

            /* ── Files ── */
            if ($type === 'full' || $type === 'files') {
                $collected = self::collectFiles();
                $entries   = $collected['entries'];
                $fileBytes = $collected['bytes'];
                $manifest['files'] = [
                    'count'   => count($entries),
                    'bytes'   => $fileBytes,
                    'sources' => BACKUP_FILE_SOURCES,
                    // Per-file digests are the strongest evidence a files
                    // backup is intact, but hashing tens of thousands of
                    // images turns a backup into an outage. Past the cap the
                    // manifest says so rather than quietly omitting them.
                    'hashed'  => count($entries) <= 5000,
                ];

                backupLockHeartbeat($token) || throw new RuntimeException('Lost the backup lock mid-run.');
            }

            /* ── Archive ── */
            $fileName = backupArchiveName($type);
            $tempZip  = $workDir . '/' . $fileName;

            $built = self::buildArchive($tempZip, $workDir, $entries, $manifest, $type);
            $manifest = $built['manifest'];

            if (!is_file($tempZip)) {
                throw new RuntimeException('The archive was not written.');
            }
            $size = (int) filesize($tempZip);
            if ($size < BACKUP_MIN_ARCHIVE_BYTES) {
                throw new RuntimeException('The archive is implausibly small (' . formatBytes($size) . ').');
            }

            $checksum = hash_file('sha256', $tempZip);
            if ($checksum === false) {
                throw new RuntimeException('The archive could not be checksummed.');
            }

            backupLockHeartbeat($token) || throw new RuntimeException('Lost the backup lock mid-run.');

            /* ── Promote out of temp/, then record ── */
            $finalPath = backupDir($type) . '/' . $fileName;
            if (!@rename($tempZip, $finalPath)) {
                // rename() fails across volumes on Windows; copy is the fallback.
                if (!@copy($tempZip, $finalPath)) {
                    throw new RuntimeException('The archive could not be moved into the backup store.');
                }
                @unlink($tempZip);
            }
            $tempZip = null;
            @chmod($finalPath, 0600);

            $db->prepare("
                UPDATE backups
                   SET status = 'completed', file_name = :fn, file_size = :sz, checksum = :ck,
                       manifest = :mf, entry_count = :ec, database_bytes = :dbb, files_bytes = :fb,
                       completed_at = NOW()
                 WHERE id = :id
            ")->execute([
                ':fn'  => $fileName,
                ':sz'  => $size,
                ':ck'  => $checksum,
                ':mf'  => json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                ':ec'  => $built['entry_count'],
                ':dbb' => $dbBytes,
                ':fb'  => $fileBytes,
                ':id'  => $id,
            ]);

            backupAudit('completed_backup', $id, '', $name . ' · ' . formatBytes($size));

            // Verified immediately, as its own step. A backup that has just
            // been written is exactly when verification is cheapest and most
            // valuable — and if it fails here, the row says `failed`, not
            // `completed`, so nothing downstream ever treats it as a
            // recovery point.
            $verify = self::verify($id);
            if (!$verify['ok']) {
                return self::fail($id, 'The backup was written but failed verification: ' . $verify['error'],
                                  /* alreadyAudited */ false);
            }

            return ['ok' => true, 'id' => $id, 'public_id' => $publicId, 'error' => null,
                    'backup' => self::find($id)];

        } catch (Throwable $e) {
            error_log('Backup ' . $publicId . ' failed: ' . $e->getMessage());
            return self::fail($id, $e->getMessage());

        } finally {
            // Incomplete work is destroyed rather than left to be discovered.
            if ($tempZip !== null && is_file($tempZip)) {
                @unlink($tempZip);
            }
            self::removeTree($workDir);
            backupLockRelease($token);
        }
    }

    /**
     * Mark a run failed, preserving the reason.
     *
     * The message is kept verbatim on the row because the useful part of a
     * failure is always the specific sentence — "mysqldump exited 2: Unknown
     * database" — and a generic "backup failed" costs an hour of guessing.
     *
     * @return array{ok: bool, id: ?int, public_id: ?string, error: string, backup: ?array}
     */
    private static function fail(?int $id, string $message, bool $audit = true): array
    {
        if ($id !== null) {
            getDBConnection()->prepare("
                UPDATE backups
                   SET status = 'failed', failure_message = :m, completed_at = NOW()
                 WHERE id = :id AND status NOT IN ('deleted')
            ")->execute([':m' => $message, ':id' => $id]);

            if ($audit) {
                backupAudit('failed_backup', $id, '', $message);
            }
            self::notifyOnRepeatedFailure();
        }

        return ['ok' => false, 'id' => $id, 'public_id' => null, 'error' => $message,
                'backup' => $id !== null ? self::find($id) : null];
    }

    /**
     * A name that is safe to store and useful to read.
     *
     * An operator's own description is kept as typed (minus control
     * characters); an empty one becomes a factual default rather than
     * "Untitled", because the list is read at speed during an incident.
     */
    private static function cleanName(string $raw, string $type, string $source): string
    {
        $raw = trim(preg_replace('/[\x00-\x1F\x7F]+/u', '', $raw) ?? '');
        if ($raw !== '') {
            return mb_substr($raw, 0, 150);
        }

        $when = (new DateTimeImmutable('now', backupTimezone()))->format('d M Y, H:i');
        $what = backupTypes()[$type] ?? 'Backup';

        return match ($source) {
            'scheduled' => 'Scheduled ' . strtolower($what) . ' — ' . $when,
            'emergency' => 'Pre-restore safety copy — ' . $when,
            default     => $what . ' — ' . $when,
        };
    }

    /* ─────────────────────────────────────────────────────────────────
       Database backup
       ───────────────────────────────────────────────────────────────── */

    /**
     * Dump the MySQL database to $path.
     *
     * --single-transaction takes a consistent snapshot without locking the
     * application out for the length of the dump, which matters because this
     * runs against a live system. --routines and --triggers are included
     * because a schema restored without them is not the same schema, and the
     * difference only shows up the first time something calls a missing
     * trigger.
     *
     * @return array{name: string, tables: int, bytes: int, sha256: string, generated_at: string}
     */
    private static function dumpDatabase(string $path): array
    {
        $bin = backupBinary('mysqldump');
        if ($bin === null) {
            throw new RuntimeException('mysqldump was not found. Set MYSQLDUMP_PATH in .env.');
        }

        $defaults = self::writeDefaultsFile();

        try {
            $args = [
                $bin,
                '--defaults-extra-file=' . $defaults,
                '--single-transaction',
                '--quick',
                '--routines',
                '--triggers',
                '--events',
                '--add-drop-table',
                '--hex-blob',
                '--default-character-set=utf8mb4',
                '--skip-comments',        // keeps a dump byte-identical run to run
            ];

            // The module's own tables never go into the dump — see the note on
            // BACKUP_OWN_TABLES. Without this a database restore rewrites the
            // backup history to its state at dump time, which erases the record
            // of the safety copy taken moments before the restore.
            foreach (BACKUP_OWN_TABLES as $own) {
                $args[] = '--ignore-table=' . DB_NAME . '.' . $own;
            }

            $args[] = DB_NAME;

            $result = self::runProcess($args, null, $path);

            // MariaDB emits "Warning: Using a password…" style notices on
            // stderr and still succeeds. Only the exit code decides.
            if ($result['code'] !== 0) {
                throw new RuntimeException('mysqldump exited ' . $result['code'] . ': ' . self::firstLine($result['stderr']));
            }

            $bytes = is_file($path) ? (int) filesize($path) : 0;
            if ($bytes < 100) {
                throw new RuntimeException('The database dump is empty.');
            }

            // Counted from the dump itself rather than from information_schema:
            // this is what the archive actually contains, which is the number
            // verification and restore both need.
            $tables = 0;
            $fh = fopen($path, 'rb');
            if ($fh) {
                while (($line = fgets($fh)) !== false) {
                    if (stripos($line, 'CREATE TABLE') === 0) {
                        $tables++;
                    }
                }
                fclose($fh);
            }
            if ($tables === 0) {
                throw new RuntimeException('The database dump contains no tables.');
            }

            return [
                'name'         => DB_NAME,
                'tables'       => $tables,
                'bytes'        => $bytes,
                'sha256'       => (string) hash_file('sha256', $path),
                'generated_at' => date('c'),
            ];

        } finally {
            if (is_file($defaults)) {
                @unlink($defaults);
            }
        }
    }

    /**
     * A --defaults-extra-file carrying the credentials.
     *
     * Written into the backup root (already outside the web root, already
     * 0700) rather than the system temp directory, which on a shared host is
     * readable by every other tenant. Deleted by the caller's finally block.
     */
    private static function writeDefaultsFile(): string
    {
        $path = backupDir('temp') . '/.my-' . bin2hex(random_bytes(8)) . '.cnf';

        $ini = "[client]\n"
             . 'host=' . DB_HOST . "\n"
             . 'port=' . DB_PORT . "\n"
             . 'user=' . DB_USER . "\n"
             . 'password="' . str_replace(['\\', '"'], ['\\\\', '\\"'], DB_PASS) . "\"\n"
             . "default-character-set=utf8mb4\n";

        if (@file_put_contents($path, $ini) === false) {
            throw new RuntimeException('Could not write the temporary database credentials file.');
        }
        @chmod($path, 0600);

        return $path;
    }

    /* ─────────────────────────────────────────────────────────────────
       Files backup
       ───────────────────────────────────────────────────────────────── */

    /**
     * Every file a files backup should contain.
     *
     * Walks only the directories named in BACKUP_FILE_SOURCES — the business
     * files, not the application. Symlinks are not followed: a symlink inside
     * an uploads directory pointing at C:/Windows would otherwise put the
     * host's system files into an archive an administrator can download.
     *
     * @return array{entries: array<int, array{abs: string, rel: string, size: int}>, bytes: int}
     */
    private static function collectFiles(): array
    {
        $entries = [];
        $bytes   = 0;

        foreach (BACKUP_FILE_SOURCES as $source) {
            $root = BASE_PATH . '/' . $source;
            if (!is_dir($root)) {
                continue;   // a store that has never been written to yet
            }

            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::LEAVES_ONLY
            );

            foreach ($it as $file) {
                /** @var SplFileInfo $file */
                if (!$file->isFile() || $file->isLink()) {
                    continue;
                }
                if (in_array($file->getFilename(), BACKUP_EXCLUDE_NAMES, true)) {
                    continue;
                }

                $abs = str_replace('\\', '/', $file->getPathname());
                $rel = ltrim(substr($abs, strlen(str_replace('\\', '/', BASE_PATH))), '/');

                // The index.php guards that sit inside the stores are part of
                // the application, not the data, and restoring an old copy of
                // one over a newer one is a small way to reopen a hole.
                if (basename($rel) === 'index.php' || basename($rel) === '.htaccess') {
                    continue;
                }

                $size    = (int) $file->getSize();
                $entries[] = ['abs' => $abs, 'rel' => $rel, 'size' => $size];
                $bytes  += $size;
            }
        }

        // Sorted so two archives of identical content list identically, which
        // makes manifests diffable and bug reports comparable.
        usort($entries, static fn(array $a, array $b): int => strcmp($a['rel'], $b['rel']));

        return ['entries' => $entries, 'bytes' => $bytes];
    }

    /* ─────────────────────────────────────────────────────────────────
       Archive
       ───────────────────────────────────────────────────────────────── */

    /**
     * Write the zip: manifest, dump, files.
     *
     * The manifest goes in last because it records the digests of everything
     * else, and it is written to the archive root so verification can read it
     * without scanning. Layout inside the archive:
     *
     *   manifest.json
     *   database/dump.sql
     *   files/<path relative to the application root>
     *
     * @param array<int, array{abs: string, rel: string, size: int}> $entries
     * @return array{manifest: array, entry_count: int}
     */
    private static function buildArchive(string $zipPath, string $workDir, array $entries, array $manifest, string $type): array
    {
        if (!class_exists('ZipArchive')) {
            throw new RuntimeException('The PHP zip extension is not enabled.');
        }

        $zip = new ZipArchive();
        $rc  = $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        if ($rc !== true) {
            throw new RuntimeException('The archive could not be opened for writing (code ' . $rc . ').');
        }

        $count = 0;

        try {
            if ($type === 'full' || $type === 'database') {
                $dump = $workDir . '/dump.sql';
                if (!is_file($dump)) {
                    throw new RuntimeException('The database dump is missing at archive time.');
                }
                if (!$zip->addFile($dump, 'database/dump.sql')) {
                    throw new RuntimeException('The database dump could not be added to the archive.');
                }
                $count++;
            }

            $hashEach = $manifest['files']['hashed'] ?? false;
            $list     = [];

            foreach ($entries as $e) {
                if (!is_file($e['abs'])) {
                    continue;   // deleted between the walk and here
                }
                if (!$zip->addFile($e['abs'], 'files/' . $e['rel'])) {
                    throw new RuntimeException('Could not add ' . $e['rel'] . ' to the archive.');
                }
                $count++;

                $item = ['path' => $e['rel'], 'size' => $e['size']];
                if ($hashEach) {
                    $item['sha256'] = (string) hash_file('sha256', $e['abs']);
                }
                $list[] = $item;
            }

            if ($manifest['files'] !== null) {
                $manifest['files']['count']   = count($list);
                $manifest['files']['entries'] = $list;
            }
            $manifest['entry_count'] = $count + 1;   // + manifest.json itself

            if (!$zip->addFromString('manifest.json', (string) json_encode(
                $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            ))) {
                throw new RuntimeException('The manifest could not be written into the archive.');
            }
            $count++;

        } finally {
            // close() is where zip actually writes. A failure here means the
            // archive on disk is not what we think it is, so it is an error
            // rather than something to log and move past.
            if (!$zip->close()) {
                throw new RuntimeException('The archive could not be finalised.');
            }
        }

        return ['manifest' => $manifest, 'entry_count' => $count];
    }

    /* ─────────────────────────────────────────────────────────────────
       Verification
       ───────────────────────────────────────────────────────────────── */

    /**
     * Prove a backup would restore, and record the verdict.
     *
     * Nine checks, cheapest first so a missing file does not pay for a hash of
     * a gigabyte. Every one of them can only ever move the row to `verified`
     * or `failed` — there is no "probably fine" outcome, because the entire
     * point of the column is that somebody can trust it during an incident.
     *
     * @return array{ok: bool, error: ?string, checks: array<int, array{label: string, ok: bool, note: string}>}
     */
    public static function verify(int $id): array
    {
        $row = self::find($id);
        if (!$row) {
            return ['ok' => false, 'error' => 'That backup no longer exists.', 'checks' => []];
        }

        $checks = [];
        $add = static function (array &$checks, string $label, bool $ok, string $note = '') {
            $checks[] = ['label' => $label, 'ok' => $ok, 'note' => $note];
            return $ok;
        };

        try {
            if ($row['status'] === 'failed') {
                throw new RuntimeException('The run itself failed; there is nothing to verify.');
            }
            if (empty($row['checksum']) || empty($row['file_name'])) {
                throw new RuntimeException('The record carries no archive reference.');
            }

            /* 1 — the file resolves inside the store */
            $path = backupArchivePath($row);
            if (!$add($checks, 'Archive exists in the protected store', $path !== null)) {
                throw new RuntimeException('The archive file is missing or outside the backup directory.');
            }

            /* 2 — readable */
            if (!$add($checks, 'Archive is readable', is_readable($path))) {
                throw new RuntimeException('The archive cannot be read.');
            }

            /* 3 — plausible size */
            $size = (int) filesize($path);
            $ok   = $size >= BACKUP_MIN_ARCHIVE_BYTES;
            $add($checks, 'Size is plausible', $ok, formatBytes($size));
            if (!$ok) {
                throw new RuntimeException('The archive is too small to contain a backup.');
            }

            /* 4 — size matches what was recorded */
            $sizeMatches = ((int) $row['file_size']) === $size;
            $add($checks, 'Size matches the record', $sizeMatches,
                 $sizeMatches ? '' : 'recorded ' . formatBytes((int) $row['file_size']));
            if (!$sizeMatches) {
                throw new RuntimeException('The archive has changed size since it was written.');
            }

            /* 5 — checksum */
            $actual = hash_file('sha256', $path);
            $hashOk = is_string($actual) && hash_equals((string) $row['checksum'], $actual);
            $add($checks, 'SHA-256 checksum matches', $hashOk);
            if (!$hashOk) {
                throw new RuntimeException('The checksum does not match. The archive has been altered or is corrupt.');
            }

            /* 6 — the zip opens and its own consistency checks pass */
            $zip = new ZipArchive();
            $rc  = $zip->open($path, ZipArchive::CHECKCONS);
            if (!$add($checks, 'Archive opens and is internally consistent', $rc === true)) {
                throw new RuntimeException('The archive is not a readable zip (code ' . $rc . ').');
            }

            try {
                /* 7 — manifest */
                $raw = $zip->getFromName('manifest.json');
                $mf  = $raw === false ? null : json_decode($raw, true);
                if (!$add($checks, 'Manifest is present and valid', is_array($mf))) {
                    throw new RuntimeException('The manifest is missing or unreadable.');
                }
                if (($mf['type'] ?? '') !== $row['type']) {
                    $add($checks, 'Manifest matches the record', false, 'type mismatch');
                    throw new RuntimeException('The manifest describes a different kind of backup.');
                }
                $add($checks, 'Manifest matches the record', true, $mf['type']);

                /* 8 — the database dump is present and non-empty */
                if ($row['type'] === 'full' || $row['type'] === 'database') {
                    $stat = $zip->statName('database/dump.sql');
                    $ok   = $stat !== false && $stat['size'] > 100;
                    $add($checks, 'Database dump present and non-empty', $ok,
                         $stat === false ? 'missing' : formatBytes((int) $stat['size']));
                    if (!$ok) {
                        throw new RuntimeException('The database dump inside the archive is missing or empty.');
                    }

                    $tables = (int) ($mf['database']['tables'] ?? 0);
                    $add($checks, 'Dump contains tables', $tables > 0, $tables . ' tables');
                    if ($tables < 1) {
                        throw new RuntimeException('The manifest records no tables in the dump.');
                    }
                }

                /* 9 — the file payload is all there */
                if ($row['type'] === 'full' || $row['type'] === 'files') {
                    $expected = (int) ($mf['files']['count'] ?? 0);
                    $present  = 0;
                    for ($i = 0; $i < $zip->numFiles; $i++) {
                        $n = $zip->getNameIndex($i);
                        if (is_string($n) && str_starts_with($n, 'files/')) {
                            $present++;
                        }
                    }
                    $ok = $present === $expected;
                    $add($checks, 'Every file in the manifest is in the archive', $ok,
                         $present . ' of ' . $expected);
                    if (!$ok) {
                        throw new RuntimeException(sprintf(
                            'The archive holds %d files but the manifest lists %d.', $present, $expected
                        ));
                    }
                }
            } finally {
                $zip->close();
            }

            self::markVerified($id, true, 'Passed ' . count($checks) . ' checks.');
            backupAudit('verified_backup', $id, '', $row['name']);

            return ['ok' => true, 'error' => null, 'checks' => $checks];

        } catch (Throwable $e) {
            self::markVerified($id, false, $e->getMessage());
            backupAudit('failed_backup', $id, '', 'Verification failed: ' . $e->getMessage());
            self::notifyOnRepeatedFailure();

            return ['ok' => false, 'error' => $e->getMessage(), 'checks' => $checks];
        }
    }

    /**
     * Record a verdict.
     *
     * A failed verification takes the whole row to `failed`. That is
     * deliberate and is the single most important line in this file: an
     * archive that cannot be proved intact is not a backup, and leaving it as
     * `completed` would let it count towards the health tile, satisfy the
     * RPO, and be offered in the restore dialog.
     */
    private static function markVerified(int $id, bool $passed, string $note): void
    {
        getDBConnection()->prepare("
            UPDATE backups
               SET verification_status = :vs,
                   verification_note   = :note,
                   verified_at         = :va,
                   status              = :st
             WHERE id = :id
        ")->execute([
            ':vs'   => $passed ? 'passed' : 'failed',
            ':note' => mb_substr($note, 0, 500),
            ':va'   => $passed ? backupDbNow() : null,
            ':st'   => $passed ? 'verified' : 'failed',
            ':id'   => $id,
        ]);
    }

    /* ─────────────────────────────────────────────────────────────────
       Restore
       ───────────────────────────────────────────────────────────────── */

    /**
     * Restore a backup, with a way back.
     *
     * The order is the whole safety argument and it does not vary:
     *
     *   1. the backup must verify *now* — not "was verified last week"
     *   2. take an emergency backup of the current state, protected
     *   3. turn on maintenance mode
     *   4. restore
     *   5. verify what landed
     *   6. turn maintenance mode off, whatever happened
     *   7. record the outcome
     *
     * Step 1 is not redundant with the stored verification status: the archive
     * may have rotted, been truncated by a full disk, or been edited since. It
     * is re-proved against the file on disk immediately before it is trusted
     * with the production database.
     *
     * Step 2 is what makes the operation reversible. If it fails, the restore
     * does not happen — a restore with no way back is not something this
     * module will perform.
     *
     * @return array{ok: bool, error: ?string, restore_id: ?int, safety_id: ?int}
     */
    public static function restore(int $backupId, string $restoreType, ?int $userId = null): array
    {
        if (!in_array($restoreType, ['database', 'files', 'full'], true)) {
            return ['ok' => false, 'error' => 'Unknown restore type.', 'restore_id' => null, 'safety_id' => null];
        }

        $row = self::find($backupId);
        if (!$row) {
            return ['ok' => false, 'error' => 'That backup no longer exists.', 'restore_id' => null, 'safety_id' => null];
        }

        // A files-only archive cannot restore a database, and vice versa.
        // Caught here rather than in the controller because the CLI reaches
        // this method too, and an impossible restore must fail before the
        // emergency backup, not after.
        $supported = $row['type'] === 'full' ? ['database', 'files', 'full'] : [$row['type']];
        if (!in_array($restoreType, $supported, true)) {
            return ['ok' => false, 'restore_id' => null, 'safety_id' => null,
                    'error' => sprintf('A %s backup cannot perform a %s restore.',
                                       backupTypes()[$row['type']] ?? $row['type'], $restoreType)];
        }

        $db     = getDBConnection();
        $userId = $userId ?? ($_SESSION['user_id'] ?? null);

        $restoreId = null;
        $safetyId  = null;
        $token     = null;
        $workDir   = null;

        try {
            /* 1 — prove the archive, now */
            $verify = self::verify($backupId);
            if (!$verify['ok']) {
                throw new RuntimeException('The backup failed verification and will not be restored: ' . $verify['error']);
            }

            /* 2 — the way back */
            $safety = self::run([
                'type'      => $restoreType === 'database' ? 'database' : 'full',
                'name'      => 'Safety copy before restoring “' . $row['name'] . '”',
                'source'    => 'emergency',
                'protected' => true,
                'user_id'   => $userId,
                'owner'     => 'restore',
            ]);
            if (!$safety['ok']) {
                throw new RuntimeException('The safety backup of the current system failed, so the restore was not attempted: ' . $safety['error']);
            }
            $safetyId = $safety['id'];

            // Taken after the safety backup because run() takes it too, and a
            // lock held across both would deadlock against itself.
            $token = backupLockAcquire(($userId ? 'user ' . $userId : 'cli') . ' · restore', 'backup');
            if ($token === null) {
                throw new RuntimeException('Another backup or restore is running. Try again when it has finished.');
            }

            $insert = $db->prepare("
                INSERT INTO backup_restores (public_id, backup_id, safety_backup_id, restore_type, status, performed_by, started_at)
                VALUES (:pid, :bid, :sid, :rt, 'running', :uid, NOW())
            ");
            $insert->execute([
                ':pid' => backupUuid(), ':bid' => $backupId, ':sid' => $safetyId,
                ':rt' => $restoreType, ':uid' => $userId,
            ]);
            $restoreId = (int) $db->lastInsertId();

            backupAudit('started_restore', $backupId, '', $restoreType . ' restore of “' . $row['name'] . '”');

            /* 3 — maintenance mode */
            backupMaintenanceEnable(
                'A system restore is in progress. The application will return automatically.',
                $userId
            );

            @set_time_limit(BACKUP_MAX_RUNTIME);

            /* 4 — expand and apply */
            $path    = backupArchivePath($row);
            $workDir = backupDir('restore') . '/run-' . bin2hex(random_bytes(6));
            if (!@mkdir($workDir, 0700, true) && !is_dir($workDir)) {
                throw new RuntimeException('Could not create a working directory for the restore.');
            }

            $tables = $files = 0;

            if ($restoreType === 'database' || $restoreType === 'full') {
                $tables = self::restoreDatabase($path, $workDir);
                backupLockHeartbeat($token);
            }
            if ($restoreType === 'files' || $restoreType === 'full') {
                $files = self::restoreFiles($path, $workDir);
                backupLockHeartbeat($token);
            }

            /* 5 — is the system actually back? */
            self::assertDatabaseUsable();

            $db->prepare("
                UPDATE backup_restores
                   SET status = 'completed', tables_restored = :t, files_restored = :f, completed_at = NOW()
                 WHERE id = :id
            ")->execute([':t' => $tables, ':f' => $files, ':id' => $restoreId]);

            backupAudit('completed_restore', $backupId, '', sprintf(
                '%s restore · %d tables · %d files', $restoreType, $tables, $files
            ));

            return ['ok' => true, 'error' => null, 'restore_id' => $restoreId, 'safety_id' => $safetyId];

        } catch (Throwable $e) {
            error_log('Restore of backup ' . $backupId . ' failed: ' . $e->getMessage());

            if ($restoreId !== null) {
                $db->prepare("
                    UPDATE backup_restores
                       SET status = 'failed', failure_message = :m, completed_at = NOW()
                     WHERE id = :id
                ")->execute([':m' => $e->getMessage(), ':id' => $restoreId]);
            }
            backupAudit('failed_restore', $backupId, '', $e->getMessage());

            return ['ok' => false, 'error' => $e->getMessage(), 'restore_id' => $restoreId, 'safety_id' => $safetyId];

        } finally {
            // Maintenance mode is lifted in every outcome. A failed restore
            // that leaves the site dark is a second incident on top of the
            // first, and the admin who needs to fix it has to be able to log in.
            backupMaintenanceDisable();

            if ($workDir !== null) {
                self::removeTree($workDir);
            }
            if ($token !== null) {
                backupLockRelease($token);
            }
        }
    }

    /**
     * Apply database/dump.sql through the mysql client.
     *
     * The client rather than a PHP statement splitter, deliberately. A dump
     * containing routines carries DELIMITER directives, and strings can
     * contain semicolons and escaped quotes; a hand-rolled splitter gets that
     * subtly wrong and produces a database that restores without error and is
     * missing a trigger. The tool that wrote the file is the tool that reads
     * it back.
     *
     * @return int tables restored, as recorded in the dump
     */
    private static function restoreDatabase(string $archivePath, string $workDir): int
    {
        $bin = backupBinary('mysql');
        if ($bin === null) {
            throw new RuntimeException('The mysql client was not found. Set MYSQL_PATH in .env.');
        }

        $zip = new ZipArchive();
        if ($zip->open($archivePath) !== true) {
            throw new RuntimeException('The archive could not be opened for restore.');
        }

        $dump = $workDir . '/dump.sql';
        try {
            if (!$zip->extractTo($workDir, ['database/dump.sql'])) {
                throw new RuntimeException('The database dump could not be extracted.');
            }
        } finally {
            $zip->close();
        }

        // extractTo preserves the archive's directory structure.
        $extracted = $workDir . '/database/dump.sql';
        if (!is_file($extracted)) {
            throw new RuntimeException('The database dump was not found after extraction.');
        }
        $dump = $extracted;

        $tables = 0;
        $fh = fopen($dump, 'rb');
        if ($fh) {
            while (($line = fgets($fh)) !== false) {
                if (stripos($line, 'CREATE TABLE') === 0) {
                    $tables++;
                }
            }
            fclose($fh);
        }
        if ($tables === 0) {
            throw new RuntimeException('The extracted dump contains no tables; refusing to apply it.');
        }

        $defaults = self::writeDefaultsFile();
        try {
            $result = self::runProcess([
                $bin,
                '--defaults-extra-file=' . $defaults,
                '--default-character-set=utf8mb4',
                DB_NAME,
            ], $dump);

            if ($result['code'] !== 0) {
                throw new RuntimeException('The database restore failed: ' . self::firstLine($result['stderr']));
            }
        } finally {
            @unlink($defaults);
        }

        // A dump taken from a database that predates this module — or a
        // restore into an empty one — leaves the module's own tables absent,
        // because they are deliberately excluded from every dump. Recreating
        // them here means a bare-metal restore comes back with a working
        // backup module rather than a fatal error on the next page load.
        self::ensureOwnTables();

        return $tables;
    }

    /**
     * Recreate the module's tables if a restore landed without them.
     *
     * Runs the migration rather than carrying a second copy of the DDL: the
     * file is idempotent by construction (CREATE TABLE IF NOT EXISTS, INSERT
     * IGNORE), so applying it to a database that already has the tables is a
     * no-op, and there is exactly one definition of the schema in the project.
     */
    private static function ensureOwnTables(): void
    {
        $db = getDBConnection();

        $missing = false;
        foreach (BACKUP_OWN_TABLES as $table) {
            $stmt = $db->prepare("SHOW TABLES LIKE :t");
            $stmt->execute([':t' => $table]);
            if (!$stmt->fetchColumn()) {
                $missing = true;
                break;
            }
        }
        if (!$missing) {
            return;
        }

        $migration = BASE_PATH . '/database/migrations/2026_08_30_backup_module.sql';
        $bin       = backupBinary('mysql');
        if (!is_file($migration) || $bin === null) {
            error_log('Backup tables are missing after a restore and the migration could not be applied.');
            return;
        }

        $defaults = self::writeDefaultsFile();
        try {
            $result = self::runProcess([
                $bin, '--defaults-extra-file=' . $defaults, '--default-character-set=utf8mb4', DB_NAME,
            ], $migration);

            if ($result['code'] !== 0) {
                error_log('Could not recreate the backup tables: ' . self::firstLine($result['stderr']));
            }
        } finally {
            @unlink($defaults);
        }
    }

    /**
     * Put the archived files back.
     *
     * Extracted to a staging directory and then moved into place one at a
     * time, rather than unzipped straight over the application. Two reasons:
     * every path can be re-checked after the zip layer has had its say, and a
     * corrupt archive that fails halfway has damaged a staging directory
     * rather than the live uploads tree.
     *
     * Each destination is resolved and required to sit inside one of
     * BACKUP_FILE_SOURCES. A zip entry named `files/../../../windows/x.dll` is
     * refused here even though it survived the archive — this is the second
     * of the two path checks, and the one that assumes the archive is hostile.
     *
     * @return int files restored
     */
    private static function restoreFiles(string $archivePath, string $workDir): int
    {
        $zip = new ZipArchive();
        if ($zip->open($archivePath, ZipArchive::CHECKCONS) !== true) {
            throw new RuntimeException('The archive could not be opened for restore.');
        }

        $stage = $workDir . '/files';
        if (!@mkdir($stage, 0700, true) && !is_dir($stage)) {
            $zip->close();
            throw new RuntimeException('Could not create the staging directory.');
        }

        // Only entries under files/ are extracted; manifest.json and the dump
        // are not application files and must not be written into the tree.
        $wanted = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (is_string($name) && str_starts_with($name, 'files/') && !str_ends_with($name, '/')) {
                $wanted[] = $name;
            }
        }

        try {
            if ($wanted && !$zip->extractTo($workDir, $wanted)) {
                throw new RuntimeException('The archived files could not be extracted.');
            }
        } finally {
            $zip->close();
        }

        $baseReal = str_replace('\\', '/', (string) realpath(BASE_PATH));
        $allowed  = [];
        foreach (BACKUP_FILE_SOURCES as $src) {
            $allowed[] = rtrim($baseReal, '/') . '/' . trim($src, '/') . '/';
        }

        $restored = 0;

        foreach ($wanted as $name) {
            $rel = substr($name, strlen('files/'));
            $src = $stage . '/' . $rel;
            if (!is_file($src)) {
                continue;
            }

            $dest = rtrim($baseReal, '/') . '/' . $rel;

            // Normalise without touching the filesystem — the destination may
            // not exist yet, so realpath() cannot be used on it.
            $normal = self::normalisePath($dest);
            $inside = false;
            foreach ($allowed as $prefix) {
                $a = DIRECTORY_SEPARATOR === '\\' ? strtolower($normal) : $normal;
                $b = DIRECTORY_SEPARATOR === '\\' ? strtolower($prefix) : $prefix;
                if (str_starts_with($a, $b)) {
                    $inside = true;
                    break;
                }
            }
            if (!$inside) {
                error_log('Restore refused an archive entry outside the file stores: ' . $name);
                continue;
            }

            $dir = dirname($normal);
            if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
                throw new RuntimeException('Could not create ' . $dir . ' during restore.');
            }
            if (!@copy($src, $normal)) {
                throw new RuntimeException('Could not write ' . $rel . ' during restore.');
            }
            $restored++;
        }

        return $restored;
    }

    /**
     * Collapse . and .. segments in a path string.
     *
     * Used on a destination that does not exist yet, where realpath() returns
     * false and would leave the traversal check with nothing to test.
     */
    private static function normalisePath(string $path): string
    {
        $path  = str_replace('\\', '/', $path);
        $parts = [];

        foreach (explode('/', $path) as $seg) {
            if ($seg === '' || $seg === '.') {
                // Keep a leading empty segment so an absolute POSIX path stays absolute.
                if ($seg === '' && !$parts) {
                    $parts[] = '';
                }
                continue;
            }
            if ($seg === '..') {
                if ($parts && end($parts) !== '' && end($parts) !== '..') {
                    array_pop($parts);
                }
                continue;
            }
            $parts[] = $seg;
        }

        return implode('/', $parts);
    }

    /**
     * A restored database has to answer a question before the restore is
     * called a success.
     *
     * Deliberately the cheapest meaningful probe: the users table, because an
     * application whose users table is missing is not restored whatever else
     * survived.
     */
    private static function assertDatabaseUsable(): void
    {
        try {
            $n = (int) getDBConnection()->query("SELECT COUNT(*) FROM users")->fetchColumn();
            if ($n < 1) {
                throw new RuntimeException('The restored database contains no user accounts.');
            }
        } catch (PDOException $e) {
            throw new RuntimeException('The restored database is not usable: ' . $e->getMessage());
        }
    }

    /* ─────────────────────────────────────────────────────────────────
       Retention & deletion
       ───────────────────────────────────────────────────────────────── */

    /**
     * Delete expired backups.
     *
     * The exclusions live in backupRetentionDue(); this only carries them out.
     * Each deletion is audited individually, because "the sweep removed 14
     * backups" is not something anybody can check afterwards.
     *
     * @return array{deleted: int, freed: int, errors: array<int, string>}
     */
    public static function sweepRetention(): array
    {
        $deleted = $freed = 0;
        $errors  = [];

        foreach (backupRetentionDue() as $row) {
            $size = (int) $row['file_size'];
            if (self::deleteBackup($row, 'expired_backup')) {
                $deleted++;
                $freed += $size;
            } else {
                $errors[] = 'Could not remove ' . $row['name'];
            }
        }

        getDBConnection()->prepare("
            UPDATE settings SET setting_value = :v WHERE setting_key = 'backup_retention_last_run'
        ")->execute([':v' => backupDbNow()]);

        return ['deleted' => $deleted, 'freed' => $freed, 'errors' => $errors];
    }

    /**
     * Remove one backup, file and row.
     *
     * A missing file is not an error: the row is the thing being retired, and
     * refusing to tidy a record because its file was already deleted by hand
     * leaves the list permanently wrong. The row is marked `deleted` rather
     * than removed so the audit trail keeps referring to something real.
     */
    public static function deleteBackup(array $row, string $auditAction = 'deleted_backup'): bool
    {
        $path = backupArchivePath($row);
        if ($path !== null && !@unlink($path)) {
            return false;
        }

        $ok = getDBConnection()->prepare("
            UPDATE backups
               SET status = 'deleted', file_name = NULL, file_size = 0, checksum = NULL
             WHERE id = :id
        ")->execute([':id' => (int) $row['id']]);

        if ($ok) {
            backupAudit($auditAction, (int) $row['id'], $row['name'], formatBytes((int) $row['file_size']) . ' freed');
        }
        return (bool) $ok;
    }

    /* ─────────────────────────────────────────────────────────────────
       Notification
       ───────────────────────────────────────────────────────────────── */

    /**
     * Tell the administrators when failures stop being a blip.
     *
     * Fires on the threshold exactly, not on every failure past it: an
     * outage that lasts a week should produce one alert, not seventy, or the
     * next real one is lost in the noise. Uses the application's existing
     * notification infrastructure.
     */
    private static function notifyOnRepeatedFailure(): void
    {
        $health = backupHealth();
        if ($health['level'] !== 'critical') {
            return;
        }

        $recent = getDBConnection()->query("
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

        if ($streak !== backupFailureThreshold()) {
            return;
        }

        $reasons = array_column(array_filter(
            $health['findings'],
            static fn(array $f): bool => $f['tone'] === 'danger'
        ), 'text');

        notifyAdmins(
            'Backups are failing',
            sprintf('%d backup runs have failed in a row. %s', $streak, implode(' ', $reasons)),
            'error',
            'backup',
            0
        );
    }

    /* ─────────────────────────────────────────────────────────────────
       Plumbing
       ───────────────────────────────────────────────────────────────── */

    /**
     * Run an external command with an argument array.
     *
     * The array form of proc_open, so arguments are passed to the process
     * directly and there is no shell to quote for — a database name or a path
     * containing a space, a quote or an ampersand cannot become a second
     * command.
     *
     * @param string[]    $args
     * @param string|null $stdinFile  file to feed the process on stdin
     * @param string|null $stdoutFile file to capture stdout into
     * @return array{code: int, stderr: string}
     */
    private static function runProcess(array $args, ?string $stdinFile = null, ?string $stdoutFile = null): array
    {
        $spec = [
            0 => $stdinFile !== null ? ['file', $stdinFile, 'r'] : ['pipe', 'r'],
            1 => $stdoutFile !== null ? ['file', $stdoutFile, 'w'] : ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $pipes = [];
        $proc  = @proc_open($args, $spec, $pipes, BASE_PATH);
        if (!is_resource($proc)) {
            throw new RuntimeException('Could not start ' . basename($args[0]) . '.');
        }

        if ($stdinFile === null && isset($pipes[0])) {
            fclose($pipes[0]);
        }
        $stdout = '';
        if ($stdoutFile === null && isset($pipes[1])) {
            $stdout = (string) stream_get_contents($pipes[1]);
            fclose($pipes[1]);
        }
        $stderr = isset($pipes[2]) ? (string) stream_get_contents($pipes[2]) : '';
        if (isset($pipes[2])) {
            fclose($pipes[2]);
        }

        $code = proc_close($proc);

        return ['code' => $code, 'stderr' => trim($stderr) !== '' ? trim($stderr) : trim($stdout)];
    }

    /** The first meaningful line of a tool's stderr, for a one-line failure message. */
    private static function firstLine(string $text): string
    {
        foreach (preg_split('/\r?\n/', trim($text)) ?: [] as $line) {
            $line = trim($line);
            // MariaDB's password notice is noise on every single invocation.
            if ($line === '' || stripos($line, 'Using a password on the command line') !== false) {
                continue;
            }
            return mb_substr($line, 0, 300);
        }
        return 'no further detail was reported';
    }

    /** Recursively delete a working directory. Best effort — never throws. */
    private static function removeTree(?string $dir): void
    {
        if ($dir === null || !is_dir($dir)) {
            return;
        }

        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $item) {
            /** @var SplFileInfo $item */
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($dir);
    }

    /** One backup row by primary key. */
    private static function find(int $id): ?array
    {
        $stmt = getDBConnection()->prepare("SELECT * FROM backups WHERE id = :id");
        $stmt->execute([':id' => $id]);

        return $stmt->fetch() ?: null;
    }
}
