<?php
/**
 * Backup runner — the one scheduled entry point.
 *
 * Run from Windows Task Scheduler, cron, or a hosting panel's scheduler. It is
 * the only way an automatic backup ever happens: nothing in the web
 * application starts a scheduled run, because a backup that depends on
 * somebody having a browser tab open is not a schedule.
 *
 *   Windows — install the task once, from an elevated prompt:
 *     database\tools\install_scheduler.bat
 *
 *   or by hand:
 *     Program:   D:\XAMPP\php\php.exe
 *     Arguments: "D:\XAMPP\htdocs\Real-State-MS\Real-State-MS\database\tools\run_backups.php"
 *     Start in:  D:\XAMPP\htdocs\Real-State-MS\Real-State-MS      (optional — see below)
 *
 *   Linux (crontab, every 5 minutes):
 *     [asterisk]/5 * * * * /usr/bin/php /var/www/app/database/tools/run_backups.php
 *
 * Run it often — every 5 to 15 minutes. It is cheap when nothing is due (a
 * handful of indexed queries and an exit) and it is what gives a schedule set
 * for 02:00 a chance to fire at 02:00 rather than whenever the next tick
 * happens to land.
 *
 * Every path in this file and everything it loads is resolved from __DIR__, so
 * the working directory is irrelevant. "Start in" is a convenience for reading
 * relative paths off the command line, not a requirement — the runner is
 * tested from an unrelated directory precisely because Task Scheduler's
 * default working directory is C:\Windows\System32.
 *
 * ── Commands ────────────────────────────────────────────────────────────
 *   (none)            run whatever is due, then sweep retention
 *   --force           run every active schedule now, ignoring next_run_at
 *   --run=TYPE        run one backup now: full | database | files
 *   --name="…"        description for --run
 *   --sweep           run retention cleanup only
 *   --verify=UUID     re-verify one backup
 *   --status          health, schedules and storage — changes nothing
 *   --due             which schedules are due right now, and why
 *   --doctor          full diagnosis of why automatic backup is or is not working
 *   --check           with --doctor: judge capability only, ignoring whether the
 *                     scheduler is installed (the installer's pre-flight)
 *   --log[=N]         last N lines of the scheduler log (default 40)
 *   --quiet           suppress ordinary output; failures still go to stderr
 *
 * ── Exit codes ──────────────────────────────────────────────────────────
 *   0  success, or nothing was due
 *   1  a failure worth alerting on
 *   2  the runner could not start at all (bootstrap, configuration, database)
 *
 * A non-zero exit is what lets Windows Task Scheduler's "Last Run Result"
 * column and an external monitor notice that the backups themselves have
 * stopped working. Every path also writes a line to
 * <BACKUP_PATH>/logs/scheduler.log, because Task Scheduler discards stdout and
 * a failure nobody can read is a failure nobody will fix.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(2);
}

/* ─── Nothing may fail silently ───────────────────────────────────────
   Three handlers, installed before anything else runs.

   A scheduled process has no console and no operator. Left to itself PHP
   would print a fatal to a stdout that Task Scheduler throws away, exit 255,
   and leave the schedule row looking exactly as it did before — which is the
   shape of every "the backups just stopped and nobody noticed" story. These
   turn each class of failure into a log line and an exit code.

   Warnings are logged and execution continues: a notice from a shared helper
   is not a reason to abandon tonight's backup, and promoting every warning to
   an exception would make the runner more fragile than the thing it protects.
   Suppressed diagnostics (@) are left alone — error_reporting() is already
   masked for those in PHP 8. */

error_reporting(E_ALL);
ini_set('display_errors', 'stderr');
ini_set('log_errors', '1');

/** Set once bootstrap has succeeded, so the handlers know whether they can log. */
$SCHED_BOOTED = false;

/** The scheduler lock token, so a fatal still releases it. */
$SCHED_LOCK = null;

/** Where to write when the application's own logger is not available yet. */
$SCHED_FALLBACK_LOG = __DIR__ . '/run_backups.bootstrap.log';

/**
 * Log through the application when it is loaded, and to a file beside this
 * script when it is not. Bootstrap failures are exactly the ones worth
 * recording, and they are the ones that happen before the logger exists.
 */
function schedLog(string $level, string $message, array $context = []): void
{
    global $SCHED_BOOTED, $SCHED_FALLBACK_LOG;

    if ($SCHED_BOOTED && function_exists('backupLog')) {
        backupLog($level, $message, $context);
        return;
    }

    $line = date('Y-m-d H:i:s') . ' [' . strtoupper($level) . '] ' . $message;
    foreach ($context as $k => $v) {
        if ($v !== null && $v !== '') {
            $line .= ' ' . $k . '=' . str_replace(["\r", "\n"], ' ', (string) $v);
        }
    }
    @file_put_contents($SCHED_FALLBACK_LOG, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
}

set_error_handler(static function (int $no, string $str, string $file = '', int $line = 0): bool {
    if (!(error_reporting() & $no)) {
        return false;               // @-suppressed: the caller has already decided
    }
    schedLog('warn', 'PHP: ' . $str, ['at' => basename($file) . ':' . $line]);

    return true;                    // handled — keep it out of stdout
});

set_exception_handler(static function (Throwable $e): void {
    global $SCHED_LOCK;

    schedLog('error', 'Uncaught ' . get_class($e) . ': ' . $e->getMessage(), [
        'at' => basename($e->getFile()) . ':' . $e->getLine(),
    ]);
    fwrite(STDERR, 'FATAL: ' . $e->getMessage() . PHP_EOL);

    if ($SCHED_LOCK !== null && function_exists('backupLockRelease')) {
        backupLockRelease($SCHED_LOCK, 'scheduler');
    }
    exit(2);
});

register_shutdown_function(static function (): void {
    global $SCHED_LOCK;

    $last = error_get_last();
    if ($last === null || !in_array($last['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        return;
    }

    schedLog('error', 'Fatal: ' . $last['message'], [
        'at' => basename((string) $last['file']) . ':' . (int) $last['line'],
    ]);

    // A fatal between acquiring the lock and releasing it would otherwise hold
    // the scheduler shut for the full lease. The lease would clear it in the
    // end; releasing now means the next tick works rather than the one in half
    // an hour.
    if ($SCHED_LOCK !== null && function_exists('backupLockRelease')) {
        backupLockRelease($SCHED_LOCK, 'scheduler');
    }
    exit(2);
});

/* ─── Bootstrap ────────────────────────────────────────────────────────
   From __DIR__, never from the working directory. includes/init.php loads
   config/app.php, which loads .env through env_load() — so the database
   credentials reach a CLI process by exactly the same route they reach a web
   request, and there is no CLI-only configuration to drift. */

try {
    require_once __DIR__ . '/../../includes/init.php';
    require_once BASE_PATH . '/includes/backup_engine.php';
    $SCHED_BOOTED = true;
} catch (Throwable $e) {
    schedLog('error', 'Bootstrap failed: ' . $e->getMessage(), [
        'at'   => basename($e->getFile()) . ':' . $e->getLine(),
        'root' => dirname(__DIR__, 2),
    ]);
    fwrite(STDERR, 'FATAL: the application could not be loaded — ' . $e->getMessage() . PHP_EOL);
    exit(2);
}

/* A scheduled run has no session and therefore no actor. backupAudit() names
   it in the payload instead, so an unattended run is distinguishable from an
   unattributed one in the audit trail. */
$_SESSION['user_id'] = $_SESSION['user_id'] ?? null;

/* The database has to be reachable before anything else is worth trying, and
   a connection failure has to be a logged exit rather than a stack trace on a
   console nobody is reading. */
try {
    getDBConnection()->query('SELECT 1');
} catch (Throwable $e) {
    schedLog('error', 'The database is unreachable.', ['error' => $e->getMessage(), 'db' => DB_NAME . '@' . DB_HOST]);
    fwrite(STDERR, 'FATAL: the database is unreachable — ' . $e->getMessage() . PHP_EOL);
    exit(2);
}

$options = getopt('', ['run::', 'sweep', 'verify::', 'status', 'due', 'doctor', 'check',
                       'log::', 'name::', 'force', 'quiet', 'help']);
$quiet   = isset($options['quiet']);

function out(string $line = ''): void
{
    global $quiet;
    if (!$quiet) {
        fwrite(STDOUT, $line . PHP_EOL);
    }
}

function err(string $line): void
{
    fwrite(STDERR, $line . PHP_EOL);
}

function stamp(): string
{
    return '[' . (new DateTimeImmutable('now', backupTimezone()))->format('Y-m-d H:i:s') . '] ';
}

/** A path in this platform's own separators, for output a person will retype. */
function native(string $path): string
{
    return DIRECTORY_SEPARATOR === '\\' ? str_replace('/', DIRECTORY_SEPARATOR, $path) : $path;
}

/** N of something, pluralised. Small, but this output is read under pressure. */
function plural(int $n, string $word): string
{
    return $n . ' ' . $word . ($n === 1 ? '' : 's');
}

/** A duration in the form a log line can carry and a person can read. */
function took(float $since): string
{
    $s = microtime(true) - $since;

    return $s < 1 ? round($s * 1000) . 'ms' : round($s, 1) . 's';
}

/* ─── --help ───────────────────────────────────────────────────────── */
if (isset($options['help'])) {
    $doc = (string) file_get_contents(__FILE__);
    if (preg_match('/── Commands ──.*?(?=\*\/)/s', $doc, $m)) {
        out(preg_replace('/^ \* ?/m', '', trim($m[0])));
    }
    exit(0);
}

/* ─── --log[=N] ─────────────────────────────────────────────────────────
   The first question after a missed backup, answerable without a text editor
   and without knowing where the log lives. */
if (isset($options['log'])) {
    $lines = max(1, min(2000, (int) ($options['log'] !== false ? $options['log'] : 40)));
    $path  = backupLogPath();

    if (!is_file($path)) {
        out('No scheduler log yet at ' . $path);
        out('The runner writes one on its first tick — it has never run on this installation.');
        exit(0);
    }

    // Read the tail rather than the file: this is bounded by rotation, but a
    // 2 MB file into an array to print forty lines is still the wrong shape.
    $fh    = fopen($path, 'rb');
    $size  = (int) filesize($path);
    $chunk = min($size, 200 * $lines + 4096);
    fseek($fh, -$chunk, SEEK_END);
    $tail = explode("\n", rtrim((string) fread($fh, $chunk), "\n"));
    fclose($fh);

    out('── ' . native($path));
    foreach (array_slice($tail, -$lines) as $line) {
        out($line);
    }
    exit(0);
}

/* ─── --status ─────────────────────────────────────────────────────────
   Read-only. Safe to run at any time, including while a backup is going,
   which is exactly when somebody wants to look. */
if (isset($options['status'])) {
    $health    = backupHealth();
    $storage   = backupStorageUsage();
    $lock      = backupLockHolder();
    $scheduler = backupSchedulerState();

    out('Backup health: ' . strtoupper($health['level']));
    foreach ($health['findings'] as $f) {
        out('  · ' . $f['text']);
    }
    out('');
    out('Scheduler: ' . ($scheduler['installed']
        ? 'last tick ' . $scheduler['ago'] . ' (' . $scheduler['last_tick'] . '), '
          . plural((int) $scheduler['tick_count'], 'tick')
        : 'HAS NEVER RUN — no automatic backup can happen until it is installed'));
    if ($scheduler['last_result'] !== '') {
        out('           ' . $scheduler['last_result']);
    }
    out('');
    out('Storage:  ' . formatBytes($storage['used']) . ' across ' . $storage['count'] . ' archives');
    out('Root:     ' . native(backupRoot()) . (backupRootIsExposed() ? '   ** INSIDE THE WEB ROOT **' : ''));
    if ($storage['disk_free'] !== null) {
        out('Disk:     ' . formatBytes((int) $storage['disk_free']) . ' free');
    }
    out('');
    out('Schedules (times in ' . backupTimezone()->getName() . '):');
    foreach (backupSchedules() as $s) {
        out(sprintf(
            '  %-8s %-8s %-9s next: %-20s last: %s (%s)',
            $s['frequency'],
            $s['is_active'] ? 'active' : 'off',
            $s['backup_type'],
            $s['next_run_at'] ?: '—',
            $s['last_run_at'] ?: 'never',
            $s['last_status']
        ));
    }
    if ($lock) {
        out('');
        out('LOCK HELD by ' . $lock['owner'] . ' since ' . $lock['acquired_at']);
    }

    exit($health['level'] === 'critical' ? 1 : 0);
}

/* ─── --due ────────────────────────────────────────────────────────────
   Answers "should something have happened by now?" without running anything,
   and says why for each schedule rather than only listing the ones that
   qualify — the interesting row is usually the one that did not. */
if (isset($options['due'])) {
    $now = new DateTimeImmutable('now', backupTimezone());
    out('Now: ' . $now->format('Y-m-d H:i:s T') . '   (backup timezone: ' . backupTimezone()->getName() . ')');
    out('');

    $any = false;
    foreach (backupSchedules() as $s) {
        if (empty($s['is_active'])) {
            $why = 'not due — schedule is switched off';
        } elseif (empty($s['next_run_at'])) {
            $why = 'DUE — no next run has ever been computed';
            $any  = true;
        } elseif ($s['next_run_at'] <= $now->format('Y-m-d H:i:s')) {
            $why = 'DUE — was scheduled for ' . $s['next_run_at'];
            $any  = true;
        } else {
            $why = 'not due — next run ' . $s['next_run_at'];
        }
        out(sprintf('  %-8s %-9s %s', $s['frequency'], $s['backup_type'], $why));
    }

    out('');
    out($any ? 'At least one schedule is due; a tick would run it now.' : 'Nothing is due.');
    exit(0);
}

/* ─── --doctor ─────────────────────────────────────────────────────────
   One command that answers "why didn't my automatic backup run?" without
   anybody opening a source file. Every line is a fact read at the moment it
   is printed; nothing here is inferred from configuration alone. */
if (isset($options['doctor'])) {
    /* Two verdicts, because they answer different questions and have different
       cures. $ok is capability: could a backup be produced right now if one were
       asked for — binaries, extensions, database, a writable store. $opsOk is
       operation: is anything actually asking. A machine can be perfectly capable
       and completely unprotected, which is exactly the state this module was
       found in, so neither verdict may mask the other.

       --check reports capability alone. The installer uses it for its
       pre-flight, where "no scheduled task is registered" is not a fault worth
       reporting — it is the thing about to be fixed. */
    $ok    = true;
    $opsOk = true;

    $say = static function (bool $good, string $label, string $detail, bool $operational = false) use (&$ok, &$opsOk): void {
        if (!$good) {
            if ($operational) {
                $opsOk = false;
            } else {
                $ok = false;
            }
        }
        out(sprintf('  [%s] %-28s %s', $good ? ' ok ' : 'FAIL', $label, $detail));
    };
    $note = static function (string $label, string $detail): void {
        out(sprintf('  [ .. ] %-28s %s', $label, $detail));
    };

    $cmd = backupSchedulerCommand();

    out('Saxane backup scheduler — diagnosis');
    out('');
    out('Environment');
    $note('php binary', PHP_BINARY . '  (' . PHP_VERSION . ', ' . PHP_SAPI . ')');
    $note('project root', native(BASE_PATH));
    $note('working directory', (string) getcwd());
    $say(is_file(BASE_PATH . '/.env') || DB_NAME !== '', '.env / configuration',
         is_file(BASE_PATH . '/.env')
            ? 'loaded from ' . native(BASE_PATH . '/.env')
            : 'no .env file — using defaults and the real environment');
    $say(class_exists('ZipArchive'), 'php zip extension',
         class_exists('ZipArchive') ? 'present' : 'MISSING — archives cannot be created');
    $say(extension_loaded('pdo_mysql'), 'php pdo_mysql extension',
         extension_loaded('pdo_mysql') ? 'present' : 'MISSING');

    out('');
    out('Database');
    $say(true, 'connection', DB_USER . '@' . DB_HOST . ':' . DB_PORT . '/' . DB_NAME . ' — reachable');
    foreach (BACKUP_OWN_TABLES as $t) {
        $exists = (bool) getDBConnection()->query("SHOW TABLES LIKE " . getDBConnection()->quote($t))->fetchColumn();
        $say($exists, 'table ' . $t, $exists ? 'present' : 'MISSING — apply database/migrations/2026_08_30_backup_module.sql');
    }

    out('');
    out('Binaries');
    $dump = backupBinary('mysqldump');
    $say($dump !== null, 'mysqldump', $dump ?? 'NOT FOUND at "' . MYSQLDUMP_BIN . '" — set MYSQLDUMP_PATH in .env');
    $mysql = backupBinary('mysql');
    $say($mysql !== null, 'mysql', $mysql ?? 'NOT FOUND at "' . MYSQL_BIN . '" — set MYSQL_PATH in .env (restore only)');

    out('');
    out('Storage');
    $problems = backupEnsureStorage();
    $say($problems === [], 'backup root', $problems === [] ? native(backupRoot()) . ' — writable' : implode(' ', $problems));
    foreach (BACKUP_DIRS as $kind) {
        $dir = backupRoot() . '/' . $kind;
        $say(is_dir($dir) && is_writable($dir), $kind . '/', is_dir($dir) ? (is_writable($dir) ? 'writable' : 'NOT WRITABLE') : 'missing');
    }
    $free = @disk_free_space(backupRoot());
    $note('free space', $free === false ? 'unknown' : formatBytes((int) $free));

    out('');
    out('Clock');
    $note('php timezone', date_default_timezone_get() . '  ' . date('Y-m-d H:i:s'));
    $note('backup timezone', backupTimezone()->getName() . '  ' . (new DateTimeImmutable('now', backupTimezone()))->format('Y-m-d H:i:s'));
    $note('mysql NOW()', (string) getDBConnection()->query('SELECT NOW()')->fetchColumn()
                       . '  (php is ' . backupClockSkew() . 's behind)');

    out('');
    out('Scheduler');
    $scheduler = backupSchedulerState();
    $say($scheduler['installed'], 'has ever run',
         $scheduler['installed']
            ? 'yes — ' . plural((int) $scheduler['tick_count'], 'tick') . ', last ' . $scheduler['ago'] . ' (' . $scheduler['last_tick'] . ')'
            : 'NO — nothing has ever invoked this runner, so no schedule has ever fired', true);
    if ($scheduler['installed']) {
        $say(!$scheduler['stale'], 'still ticking',
             $scheduler['stale']
                ? 'NO — silent for ' . plural((int) $scheduler['minutes_since'], 'minute')
                    . ' (limit ' . plural(backupSchedulerStaleMinutes(), 'minute') . ')'
                : 'yes — last tick ' . plural((int) $scheduler['minutes_since'], 'minute') . ' ago', true);
        $note('last result', $scheduler['last_result'] !== '' ? $scheduler['last_result'] : '—');
        $note('host', $scheduler['host'] !== '' ? $scheduler['host'] : '—');
    }

    /* What Windows itself has to say. It is the only witness to a run that died
       before the runner's own handlers were installed — a parse error in the
       script exits 255 and writes nothing to the log, so without this the
       diagnosis stops at "the scheduler stopped" with no reason attached. */
    $info = backupWindowsTaskInfo();
    if ($info !== null) {
        $note('task last ran', ($info['last_run'] ?? 'never')
            . ($info['last_result'] !== null ? '  → ' . backupTaskResultText($info['last_result']) : ''));
        $note('task next runs', $info['next_run'] ?? 'not scheduled');

        /* A task that fires and fails is worse than one that is missing,
           because everything else on screen says it is installed. Reported as a
           capability failure: the runner cannot produce a backup in the
           environment the task starts it in, whatever it does from a shell. */
        if ($info['last_result'] !== null && !in_array($info['last_result'], [0, 1, 267009, 267011], true)) {
            $say(false, 'task last result', 'the task ran and the runner exited '
                . $info['last_result'] . ' — ' . backupTaskResultText($info['last_result']));
        }
    }

    $task = backupWindowsTaskInstalled();
    if ($task === null) {
        $note('scheduled task', DIRECTORY_SEPARATOR === '\\'
            ? 'could not query schtasks — check by hand'
            : 'not Windows — check crontab');
    } else {
        $say($task, 'scheduled task', $task
            ? '"' . BACKUP_TASK_NAME . '" is registered with Windows Task Scheduler'
            : '"' . BACKUP_TASK_NAME . '" is NOT registered — run database\\tools\\install_scheduler.bat as administrator', true);
    }
    $note('log file', native(backupLogPath()) . (is_file(backupLogPath()) ? '' : '  (not written yet)'));

    out('');
    out('Schedules (times in ' . backupTimezone()->getName() . ')');
    $active = 0;
    foreach (backupSchedules() as $s) {
        if (!empty($s['is_active'])) {
            $active++;
        }
        $note($s['frequency'] . ' ' . ($s['is_active'] ? '(on)' : '(off)'), sprintf(
            '%-9s at %s   next: %-20s last: %s (%s)',
            $s['backup_type'], substr((string) $s['run_at'], 0, 5),
            $s['next_run_at'] ?: '—', $s['last_run_at'] ?: 'never', $s['last_status']
        ));
    }
    $say($active > 0, 'automatic backup', $active > 0
        ? 'ON — ' . $active . ' schedule' . ($active === 1 ? '' : 's') . ' enabled'
        : 'OFF — no schedule is enabled, so nothing is meant to run automatically', true);
    foreach (backupOverdueSchedules() as $late) {
        $say(false, 'overdue', $late['frequency'] . ' was due ' . $late['next_run_at']
            . ' — ' . plural((int) $late['minutes_late'], 'minute') . ' late', true);
    }

    out('');
    out('Locks');
    foreach (['backup', 'scheduler'] as $name) {
        $holder = backupLockHolder($name);
        $note($name . ' lock', $holder
            ? 'held by ' . $holder['owner'] . ' for ' . (int) $holder['held_seconds'] . 's, expires ' . $holder['expires_at']
            : 'free');
    }

    out('');
    out('Last backup');
    $health = backupHealth();
    if ($health['last_backup']) {
        $b = $health['last_backup'];
        $note('most recent', $b['name'] . ' — ' . $b['type'] . ', ' . $b['status']
            . ', ' . $b['completed_at'] . ' (' . (int) $b['hours_ago'] . 'h ago)');
    } else {
        $say(false, 'most recent', 'none — nothing could be restored right now', true);
    }
    out('');
    out('Health: ' . strtoupper($health['level']));
    foreach ($health['findings'] as $f) {
        out('  · ' . $f['text']);
    }

    out('');
    out('To run the scheduler by hand:');
    out('  ' . $cmd['command']);

    exit(isset($options['check']) ? ($ok ? 0 : 1) : (($ok && $opsOk) ? 0 : 1));
}

/* ─── --verify=UUID ────────────────────────────────────────────────── */
if (isset($options['verify']) && $options['verify'] !== false) {
    require_once BASE_PATH . '/models/Backup.php';

    $row = (new Backup())->findByPublicId((string) $options['verify']);
    if (!$row) {
        err(stamp() . 'No backup with that identifier.');
        exit(1);
    }

    $result = BackupManager::verify((int) $row['id']);
    foreach ($result['checks'] as $c) {
        out(sprintf('  [%s] %s%s', $c['ok'] ? ' ok ' : 'FAIL', $c['label'], $c['note'] !== '' ? '  — ' . $c['note'] : ''));
    }
    out(stamp() . ($result['ok'] ? 'Verified: ' . $row['name'] : 'FAILED: ' . $result['error']));

    backupLog($result['ok'] ? 'info' : 'error', 'Manual verification of ' . $row['public_id'],
              ['result' => $result['ok'] ? 'passed' : 'failed', 'error' => $result['ok'] ? '' : $result['error']]);

    exit($result['ok'] ? 0 : 1);
}

/* ─── --sweep ──────────────────────────────────────────────────────── */
if (isset($options['sweep'])) {
    $swept = BackupManager::sweepRetention();
    out(stamp() . sprintf('Retention: %d removed, %s freed.', $swept['deleted'], formatBytes($swept['freed'])));
    backupLog($swept['errors'] ? 'error' : 'info', 'Retention sweep (manual)', [
        'removed' => $swept['deleted'],
        'freed'   => formatBytes($swept['freed']),
        'errors'  => implode('; ', $swept['errors']),
    ]);
    foreach ($swept['errors'] as $e) {
        err(stamp() . $e);
    }
    exit($swept['errors'] ? 1 : 0);
}

/* ─── --run=TYPE ───────────────────────────────────────────────────── */
if (isset($options['run']) && $options['run'] !== false) {
    $type = (string) $options['run'];
    if (!array_key_exists($type, backupTypes())) {
        err('Unknown type "' . $type . '". Use full, database or files.');
        exit(1);
    }

    $began = microtime(true);
    out(stamp() . 'Starting ' . $type . ' backup…');
    backupLog('info', 'Manual CLI backup starting.', ['type' => $type]);

    $result = BackupManager::run([
        'type'   => $type,
        'name'   => isset($options['name']) && $options['name'] !== false ? (string) $options['name'] : '',
        'source' => 'manual',
        'owner'  => 'cli',
    ]);

    if ($result['ok']) {
        $b = $result['backup'];
        out(stamp() . 'Done: ' . $b['name']);
        out('  ' . $b['public_id'] . '  ' . formatBytes((int) $b['file_size']) . '  ' . $b['status']);
        backupLog('info', 'Manual CLI backup finished.', [
            'type'         => $type,
            'backup'       => $b['public_id'],
            'destination'  => backupArchivePath($b) ?? '?',
            'size'         => formatBytes((int) $b['file_size']),
            'verification' => $b['verification_status'],
            'duration'     => took($began),
            'result'       => 'success',
        ]);
        exit(0);
    }

    err(stamp() . 'FAILED: ' . $result['error']);
    backupLog('error', 'Manual CLI backup failed.', [
        'type'     => $type,
        'error'    => $result['error'],
        'duration' => took($began),
        'result'   => 'failure',
    ]);
    exit(1);
}

/* ═══ Default: run what is due, then sweep ═════════════════════════════
   The order matters. Backups first so a disk that is nearly full still gets
   tonight's copy written before old ones are removed — sweeping first would
   free space by deleting the only backups that exist, and then fail to make a
   new one. */

$tickBegan = microtime(true);
$force     = isset($options['force']);

/* ─── One tick at a time ───────────────────────────────────────────────
   A lock around the whole tick, distinct from the lock a backup takes.

   Without it, a schedule that takes longer to back up than the interval
   between ticks is seen as due by the next tick too. That second tick cannot
   take the backup lock, so its run is refused — and the code that then
   advanced next_run_at would push the occurrence forward and stamp the
   schedule `failed` while the first tick was still successfully writing the
   archive. One overlapping tick was enough to make a working backup look
   broken and skip the following day.

   The loser exits 0, not 1. Two ticks overlapping is the scheduler doing
   exactly what it was asked to; it is not a fault, and a monitor alerted by a
   non-zero exit must not be woken for it. */
$SCHED_LOCK = backupLockAcquire('scheduler · ' . (function_exists('gethostname') ? gethostname() : 'cli'), 'scheduler');

if ($SCHED_LOCK === null) {
    $holder = backupLockHolder('scheduler');
    backupLog('info', 'Tick skipped — another scheduler run is in progress.', [
        'holder' => $holder['owner'] ?? '?',
        'since'  => $holder['acquired_at'] ?? '?',
    ]);
    out(stamp() . 'Another scheduler run is in progress; nothing to do.');
    exit(0);
}

$failed   = 0;
$ran      = 0;
$deferred = 0;
$summary  = '';

try {
    $due = $force
        ? array_values(array_filter(backupSchedules(), static fn(array $s): bool => !empty($s['is_active'])))
        : backupDueSchedules();

    if ($force && $due) {
        out(stamp() . '--force: treating ' . count($due) . ' active schedule(s) as due.');
        backupLog('info', 'Forced tick — every active schedule treated as due.', ['count' => count($due)]);
    }

    if (!$due) {
        // Silent on the common path. A cron entry that prints on every tick
        // fills a mailbox until somebody switches it off, and then nobody sees
        // the messages that matter. The heartbeat below is the record that
        // this tick happened, which is what the dashboard reads.
        $summary = 'Nothing due.';
    } else {
        /* A manual backup started from the web interface holds the backup
           lock. Every scheduled run would be refused for as long as it lasts,
           so the occurrence is deferred rather than burned: next_run_at stays
           where it is and the following tick tries again. */
        $busy = backupLockHolder('backup');
        if ($busy !== null) {
            $deferred = count($due);
            $summary  = 'Deferred ' . $deferred . ' schedule(s) — a backup is already running.';
            out(stamp() . $summary);
            backupLog('info', 'Schedules deferred — the backup lock is held.', [
                'holder'    => $busy['owner'],
                'since'     => $busy['acquired_at'],
                'deferred'  => $deferred,
            ]);
            $due = [];
        }
    }

    $db   = getDBConnection();
    $done = [];

    foreach ($due as $schedule) {
        $began = microtime(true);
        $line  = 'Schedule "' . $schedule['frequency'] . '" is due — running a ' . $schedule['backup_type'] . ' backup.';

        out(stamp() . $line);
        backupLog('info', 'Scheduled backup starting.', [
            'schedule'    => $schedule['frequency'],
            'schedule_id' => $schedule['id'],
            'type'        => $schedule['backup_type'],
            'due_at'      => $schedule['next_run_at'] ?: '(never computed)',
            'destination' => backupRoot() . '/' . $schedule['backup_type'],
        ]);

        $result = BackupManager::run([
            'type'            => $schedule['backup_type'],
            'source'          => 'scheduled',
            'retention_class' => $schedule['frequency'],
            'owner'           => 'scheduler · ' . $schedule['frequency'],
        ]);

        /* Did the run actually begin? BackupManager::run() inserts the row as
           its first act under the lock, so an id is the evidence that a run
           started; its absence means the attempt was refused before anything
           happened — an unwritable store, a missing binary, a lock taken in
           the moment between the check above and here.

           The distinction decides what happens to the schedule. A run that
           started and failed has had its turn: the occurrence is spent, and
           retrying it on every tick would turn one fault into a few hundred
           failure rows and a notification storm. A run that never started has
           not had its turn, so next_run_at is left alone and the next tick
           tries again — which is also what makes a transient fault heal
           without anybody intervening. */
        $started = $result['id'] !== null;

        if (!$started) {
            $failed++;
            err(stamp() . '  DID NOT START — ' . $result['error']);
            backupLog('error', 'Scheduled backup could not start; the occurrence is kept.', [
                'schedule' => $schedule['frequency'],
                'type'     => $schedule['backup_type'],
                'error'    => $result['error'],
                'duration' => took($began),
                'result'   => 'not-started',
            ]);
            $done[] = $schedule['frequency'] . ': did not start';
            continue;
        }

        $backup = $result['backup'] ?? null;
        $next   = backupNextRun($schedule, new DateTimeImmutable('now', backupTimezone()));

        $db->prepare("
            UPDATE backup_schedules
               SET last_run_at = NOW(), last_backup_id = :bid, last_status = :st, next_run_at = :next
             WHERE id = :id
        ")->execute([
            ':bid'  => $result['id'],
            ':st'   => $result['ok'] ? ($backup['status'] ?? 'completed') : 'failed',
            ':next' => $next,
            ':id'   => $schedule['id'],
        ]);

        if ($result['ok']) {
            $ran++;
            $size = formatBytes((int) ($backup['file_size'] ?? 0));
            out(stamp() . '  done — ' . $size . ', ' . ($backup['status'] ?? '?'));
            backupLog('info', 'Scheduled backup finished.', [
                'schedule'     => $schedule['frequency'],
                'type'         => $schedule['backup_type'],
                'backup'       => $backup['public_id'] ?? '?',
                'destination'  => $backup ? (backupArchivePath($backup) ?? '?') : '?',
                'size'         => $size,
                'status'       => $backup['status'] ?? '?',
                'verification' => $backup['verification_status'] ?? '?',
                'duration'     => took($began),
                'next_run_at'  => $next ?? '(inactive)',
                'result'       => 'success',
            ]);
            $done[] = $schedule['frequency'] . ': ' . $size;
        } else {
            $failed++;
            err(stamp() . '  FAILED — ' . $result['error']);
            backupLog('error', 'Scheduled backup failed.', [
                'schedule'    => $schedule['frequency'],
                'type'        => $schedule['backup_type'],
                'backup'      => $backup['public_id'] ?? ('#' . $result['id']),
                'error'       => $result['error'],
                'duration'    => took($began),
                'next_run_at' => $next ?? '(inactive)',
                'result'      => 'failure',
            ]);
            $done[] = $schedule['frequency'] . ': FAILED';
        }

        // The tick can outlast its own lease when a schedule takes a long
        // time; extending it here keeps a second tick from stealing the lock
        // out from under a run that is still going.
        backupLockHeartbeat($SCHED_LOCK, 'scheduler');
    }

    /* ── Retention ── */
    $swept = BackupManager::sweepRetention();
    if ($swept['deleted'] > 0 || $swept['errors']) {
        out(stamp() . sprintf('Retention: %d removed, %s freed.', $swept['deleted'], formatBytes($swept['freed'])));
        backupLog($swept['errors'] ? 'error' : 'info', 'Retention sweep.', [
            'removed' => $swept['deleted'],
            'freed'   => formatBytes($swept['freed']),
            'errors'  => implode('; ', $swept['errors']),
        ]);
    }
    foreach ($swept['errors'] as $e) {
        err(stamp() . $e);
        $failed++;
    }

    if ($done) {
        $summary = implode(', ', $done)
                 . ($swept['deleted'] > 0 ? '; swept ' . $swept['deleted'] : '');
    } elseif ($summary === '') {
        $summary = 'Nothing due.';
    }

} finally {
    /* The heartbeat is written whatever happened, including on the way out of
       an exception. It is the dashboard's only evidence that a scheduler
       exists at all, and a tick that failed is still a tick that ran — a
       heartbeat written only on success would report a broken scheduler as an
       absent one, which sends the next person to fix the wrong thing. */
    $note = sprintf(
        '%s (%d run, %d failed, %d deferred, %s)',
        $summary !== '' ? $summary : 'tick',
        $ran, $failed, $deferred, took($tickBegan)
    );

    backupSchedulerRecordTick($note);

    /* Every tick leaves a line, including the great majority that find nothing
       to do. It is the only way to answer "was the scheduler alive at 03:00
       last night?" after the fact, and one short line every five minutes is
       roughly 30 KB a day against a rotation that keeps six files of 2 MB. A
       log that only recorded the interesting ticks could not distinguish a
       quiet night from a dead scheduler, which is the distinction the whole
       section exists to make. */
    backupLog($failed > 0 ? 'error' : 'info', 'Tick complete.', [
        'ran'      => $ran,
        'failed'   => $failed,
        'deferred' => $deferred,
        'duration' => took($tickBegan),
        'summary'  => $summary !== '' ? $summary : 'tick',
    ]);

    backupLockRelease($SCHED_LOCK, 'scheduler');
    $SCHED_LOCK = null;
}

exit($failed > 0 ? 1 : 0);
