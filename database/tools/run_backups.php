<?php
/**
 * Backup runner — the scheduled entry point.
 *
 * Run from Windows Task Scheduler, cron, or a hosting panel's scheduler. It
 * is the only way an automatic backup ever happens: nothing in the web
 * application starts a scheduled run, because a backup that depends on
 * somebody having a browser tab open is not a schedule.
 *
 *   Windows (Task Scheduler, daily, every 15 minutes):
 *     Program:   D:\XAMPP\php\php.exe
 *     Arguments: "D:\XAMPP\htdocs\Real-State-MS\Real-State-MS\database\tools\run_backups.php"
 *
 *   Linux (crontab, every 15 minutes):
 *     [asterisk]/15 * * * * /usr/bin/php /var/www/app/database/tools/run_backups.php >> /var/log/saxane-backup.log 2>&1
 *
 * Run it often — every 5 to 15 minutes. It is cheap when nothing is due (two
 * indexed queries and an exit) and it is what gives a schedule set for 02:00
 * a chance to actually fire at 02:00 rather than whenever the next tick
 * happens to land.
 *
 * Commands:
 *   (none)            run whatever is due, then sweep retention
 *   --run=TYPE        run one backup now: full | database | files
 *   --sweep           run retention cleanup only
 *   --verify=UUID     re-verify one backup
 *   --status          print health, schedules and storage, change nothing
 *   --name="…"        description for --run
 *
 * Exit codes: 0 success or nothing to do, 1 a failure worth alerting on. A
 * non-zero exit is what lets an external monitor notice that the backups
 * themselves have stopped working.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../../includes/init.php';
require_once BASE_PATH . '/includes/backup_engine.php';

/* A scheduled run has no session and therefore no actor. backupAudit() names
   it in the payload instead, so an unattended run is distinguishable from an
   unattributed one in the audit trail. */
$_SESSION['user_id'] = $_SESSION['user_id'] ?? null;

$options = getopt('', ['run::', 'sweep', 'verify::', 'status', 'name::', 'help']);

function out(string $line = ''): void
{
    fwrite(STDOUT, $line . PHP_EOL);
}

function err(string $line): void
{
    fwrite(STDERR, $line . PHP_EOL);
}

function stamp(): string
{
    return '[' . date('Y-m-d H:i:s') . '] ';
}

if (isset($options['help'])) {
    out(trim(implode("\n", array_slice(explode("\n", (string) file_get_contents(__FILE__)), 1, 30))));
    exit(0);
}

/* ─── --status ─────────────────────────────────────────────────────────
   Read-only. Safe to run at any time, including while a backup is going,
   which is exactly when somebody wants to look. */
if (isset($options['status'])) {
    $health  = backupHealth();
    $storage = backupStorageUsage();
    $lock    = backupLockHolder();

    out('Backup health: ' . strtoupper($health['level']));
    foreach ($health['findings'] as $f) {
        out('  · ' . $f['text']);
    }
    out('');
    out('Storage:  ' . formatBytes($storage['used']) . ' across ' . $storage['count'] . ' archives');
    out('Root:     ' . backupRoot() . (backupRootIsExposed() ? '   ** INSIDE THE WEB ROOT **' : ''));
    if ($storage['disk_free'] !== null) {
        out('Disk:     ' . formatBytes((int) $storage['disk_free']) . ' free');
    }
    out('');
    out('Schedules:');
    foreach (backupSchedules() as $s) {
        out(sprintf(
            '  %-8s %-8s %-9s next: %s   last: %s (%s)',
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

    exit($result['ok'] ? 0 : 1);
}

/* ─── --sweep ──────────────────────────────────────────────────────── */
if (isset($options['sweep'])) {
    $swept = BackupManager::sweepRetention();
    out(stamp() . sprintf('Retention: %d removed, %s freed.', $swept['deleted'], formatBytes($swept['freed'])));
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

    out(stamp() . 'Starting ' . $type . ' backup…');
    $result = BackupManager::run([
        'type'    => $type,
        'name'    => isset($options['name']) && $options['name'] !== false ? (string) $options['name'] : '',
        'source'  => 'manual',
        'owner'   => 'cli',
    ]);

    if ($result['ok']) {
        $b = $result['backup'];
        out(stamp() . 'Done: ' . $b['name']);
        out('  ' . $b['public_id'] . '  ' . formatBytes((int) $b['file_size']) . '  ' . $b['status']);
        exit(0);
    }

    err(stamp() . 'FAILED: ' . $result['error']);
    exit(1);
}

/* ─── Default: run what is due, then sweep ─────────────────────────────
   The order matters. Backups first so a disk that is nearly full still gets
   tonight's copy written before old ones are removed — sweeping first would
   free space by deleting the only backups that exist, and then fail to make
   a new one. */
$due = backupDueSchedules();

if (!$due) {
    // Silent on the common path. A cron entry that prints on every tick fills
    // a mailbox until somebody switches it off, and then nobody sees the
    // messages that matter.
    $swept = BackupManager::sweepRetention();
    if ($swept['deleted'] > 0) {
        out(stamp() . sprintf('Retention: %d removed, %s freed.', $swept['deleted'], formatBytes($swept['freed'])));
    }
    exit(0);
}

$failed = 0;
$db     = getDBConnection();

foreach ($due as $schedule) {
    out(stamp() . 'Schedule "' . $schedule['frequency'] . '" is due — running a ' . $schedule['backup_type'] . ' backup.');

    $result = BackupManager::run([
        'type'            => $schedule['backup_type'],
        'source'          => 'scheduled',
        'retention_class' => $schedule['frequency'],
        'owner'           => 'scheduler · ' . $schedule['frequency'],
    ]);

    // The schedule is advanced whether the run worked or not. A failing
    // schedule that never moves its next_run_at would retry on every tick for
    // as long as the fault lasts, turning one broken backup into a few hundred
    // failure rows and a notification storm — and the next scheduled attempt
    // is the right time to try again anyway.
    $db->prepare("
        UPDATE backup_schedules
           SET last_run_at = NOW(), last_backup_id = :bid, last_status = :st, next_run_at = :next
         WHERE id = :id
    ")->execute([
        ':bid'  => $result['id'],
        ':st'   => $result['ok'] ? ($result['backup']['status'] ?? 'completed') : 'failed',
        ':next' => backupNextRun($schedule, new DateTimeImmutable('now', backupTimezone())),
        ':id'   => $schedule['id'],
    ]);

    if ($result['ok']) {
        out(stamp() . '  done — ' . formatBytes((int) ($result['backup']['file_size'] ?? 0))
            . ', ' . ($result['backup']['status'] ?? '?'));
    } else {
        err(stamp() . '  FAILED — ' . $result['error']);
        $failed++;
    }
}

$swept = BackupManager::sweepRetention();
if ($swept['deleted'] > 0) {
    out(stamp() . sprintf('Retention: %d removed, %s freed.', $swept['deleted'], formatBytes($swept['freed'])));
}

exit($failed > 0 ? 1 : 0);
