<?php
/**
 * Backup Controller — the backup and restore workspace.
 *
 * Every action here follows the house pattern: authorize() first, requirePost()
 * and enforceCSRF() on anything that writes, then a redirect with a flash. Two
 * things are stricter than the rest of the application, and both are
 * deliberate.
 *
 * **Downloads are gated on backup.restore, not backup.view.** An archive is
 * the entire database and every uploaded file in one object. Anyone who can
 * take a copy can restore it onto a machine they control and read everything
 * in it at leisure, so the permission that governs restoring the system is the
 * permission that governs taking it away. Reading the dashboard is a different,
 * lesser thing and keeps backup.view.
 *
 * **Restore and delete re-authenticate.** The permission matrix has one
 * administrator role holding `*`, so it cannot express "an administrator,
 * deliberately, right now" — which is the actual requirement for an operation
 * that overwrites production. A typed confirmation phrase proves intent and
 * the account password proves it is still the right person at the keyboard.
 * Neither replaces the permission check; both come after it.
 *
 * Nothing here computes a statistic. The dashboard's six figures, the health
 * verdict, the storage breakdown and the activity feed all come from
 * includes/backup.php reading real rows and real disk state, so a number on
 * screen can always be traced to something that happened.
 */
require_once BASE_PATH . '/models/Backup.php';
require_once BASE_PATH . '/includes/backup_engine.php';

class BackupController
{
    /** The phrase an operator must type before a destructive restore runs. */
    private const RESTORE_PHRASE = 'RESTORE';

    private Backup $model;

    public function __construct()
    {
        $this->model = new Backup();
    }

    /* ─────────────────────────────────────────────────────────────────
       Dashboard
       ───────────────────────────────────────────────────────────────── */

    public function index(): void
    {
        authorize('backup.view');

        // Creates the store and its guards on first visit, so an administrator
        // never has to make a directory by hand. Any problem it reports comes
        // back through backupHealth() as a finding rather than an exception.
        backupEnsureStorage();

        $type = uiPick($_GET['type'] ?? '', array_keys(Backup::TABS));
        $sort = uiSortValue(array_keys(Backup::SORTS), 'newest');

        $page   = max(1, (int) ($_GET['p'] ?? 1));
        $offset = ($page - 1) * ITEMS_PER_PAGE;

        $filters = ['type' => $type, 'sort' => $sort];

        $total   = $this->model->count($filters);
        $backups = $this->model->getAll($filters, ITEMS_PER_PAGE, $offset);

        renderPage(VIEWS_PATH . '/admin/backup/index.php', [
            'backups'      => $backups,
            'totalCount'   => $total,
            'totalPages'   => (int) ceil($total / ITEMS_PER_PAGE),
            'page'         => $page,
            'tabCounts'    => $this->model->countsByType(),
            'activeType'   => $type,
            'health'       => backupHealth(),
            'storage'      => backupStorageUsage(),
            'schedules'    => backupSchedules(),
            // Whether anything is actually acting on those schedules. Passed
            // beside them rather than folded into them because a schedule and
            // the scheduler fail independently, and the page has to be able to
            // say "configured, but nothing is running it".
            'scheduler'    => backupSchedulerState(),
            'activity'     => backupRecentActivity(8),
            'restorable'   => can('backup.restore') ? $this->model->restorable() : [],
            'lastRestore'  => $this->model->lastRestore(),
            // Drives the honest processing state: the page polls only while
            // something is genuinely in flight, and stops when it is not.
            'running'      => $this->model->running(),
            'lockHolder'   => backupLockHolder(),
            'pageTitle'    => 'Backup Management',
            'pageSubtitle' => 'Create, manage and restore system backups securely.',
            'breadcrumbs'  => [['label' => 'Backup Management']],
            'pageStyles'   => ['pages/backup'],
            'extraScripts' => ['backup'],
        ]);
    }

    /**
     * Real state, as JSON, for the page to poll while a run is in flight.
     *
     * Reports only what the database says. There is no percentage here and no
     * estimated time, because neither is knowable: mysqldump does not report
     * progress and a zip of ten thousand files gives no honest fraction. The
     * page shows "working" until this says otherwise, which is the truth.
     */
    public function status(): void
    {
        authorize('backup.view');

        $running = $this->model->running();
        $health  = backupHealth();

        header('Content-Type: application/json; charset=UTF-8');
        header('Cache-Control: no-store');

        echo json_encode([
            'running'      => array_map(static fn(array $r): array => [
                'name'       => $r['name'],
                'type'       => $r['type'],
                'started_at' => $r['started_at'],
            ], $running),
            'busy'         => $running !== [] || backupLockHolder() !== null,
            'health'       => $health['level'],
            'health_label' => $health['label'],
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    /* ─────────────────────────────────────────────────────────────────
       Creating
       ───────────────────────────────────────────────────────────────── */

    /**
     * Start a manual backup.
     *
     * Runs inline rather than queueing: this application has no worker, and a
     * job written to a table that nothing drains is worse than a slow request,
     * because it looks like it worked. The request is given the full backup
     * runtime, and the dialog warns that the page will wait.
     */
    public function create(): void
    {
        authorize('backup.create');
        requirePost();
        enforceCSRF();

        $type = (string) ($_POST['type'] ?? '');
        if (!array_key_exists($type, backupTypes())) {
            setFlash('error', 'Choose which kind of backup to make.');
            redirect($this->backUrl());
        }

        $result = BackupManager::run([
            'type'      => $type,
            'name'      => (string) ($_POST['name'] ?? ''),
            'source'    => 'manual',
            'protected' => !empty($_POST['is_protected']),
            'user_id'   => $_SESSION['user_id'] ?? null,
            'owner'     => 'user ' . ($_SESSION['user_id'] ?? '?'),
        ]);

        if ($result['ok']) {
            $b = $result['backup'];
            setFlash('success', sprintf(
                '“%s” completed and passed verification — %s.',
                $b['name'], formatBytes((int) $b['file_size'])
            ));
        } else {
            // The real reason, not a generic apology: the operator is the
            // person who can act on "mysqldump was not found".
            setFlash('error', 'The backup failed: ' . $result['error']);
        }

        redirect($this->backUrl());
    }

    /* ─────────────────────────────────────────────────────────────────
       Verification
       ───────────────────────────────────────────────────────────────── */

    public function verify(): void
    {
        authorize('backup.verify');
        requirePost();
        enforceCSRF();

        $row = $this->requireBackup();

        $result = BackupManager::verify((int) $row['id']);

        if ($result['ok']) {
            setFlash('success', sprintf('“%s” passed all %d integrity checks.',
                                        $row['name'], count($result['checks'])));
        } else {
            // A failed verification has already taken the row to `failed`.
            // Saying so plainly matters: the operator must not go on believing
            // they hold a recovery point they do not.
            setFlash('error', sprintf(
                '“%s” failed verification and is no longer counted as a usable backup: %s',
                $row['name'], $result['error']
            ));
        }

        redirect($this->backUrl());
    }

    /* ─────────────────────────────────────────────────────────────────
       Download
       ───────────────────────────────────────────────────────────────── */

    /**
     * Stream an archive to the browser.
     *
     * Reuses streamStoredFile() — the same delivery path the document store
     * uses, with the same nosniff/sandbox/no-referrer headers, so there is one
     * implementation of "send a private file" in the codebase rather than two
     * that drift.
     *
     * The path comes from backupArchivePath(), which rebuilds it from the row's
     * type and basename and re-checks the result with realpath(). Nothing the
     * request supplies reaches the filesystem: the only request-derived value
     * is a UUID, matched exactly against a unique column.
     */
    public function download(): void
    {
        // See the class comment: taking the archive is equivalent to being
        // able to restore it somewhere else.
        authorize('backup.restore');

        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        if (!in_array($method, ['GET', 'HEAD'], true)) {
            http_response_code(405);
            header('Allow: GET, HEAD');
            exit('Method not allowed.');
        }

        $row  = $this->requireBackup();
        $path = backupArchivePath($row);

        if ($path === null) {
            setFlash('error', 'That archive is no longer on disk. The record has been kept, but the file is gone.');
            redirect($this->backUrl());
        }

        // Audited before the transfer starts, so an aborted download is still
        // on record — the interesting event is that someone took a copy, not
        // that the copy arrived.
        backupAudit('downloaded_backup', (int) $row['id'], '', $row['name'] . ' · ' . formatBytes((int) $row['file_size']));

        streamStoredFile(
            $path,
            'application/zip',
            (string) $row['file_name'],
            ['application/zip'],
            [],                 // never inline: an archive is always a download
            false
        );
    }

    /* ─────────────────────────────────────────────────────────────────
       Retention hold
       ───────────────────────────────────────────────────────────────── */

    /** Hold a backup back from automatic cleanup, or release it. */
    public function protect(): void
    {
        authorize('backup.manage');
        requirePost();
        enforceCSRF();

        $row     = $this->requireBackup();
        $protect = !empty($_POST['protect']);

        if ($this->model->setProtection((int) $row['id'], $protect)) {
            backupAudit('protected_backup', (int) $row['id'],
                        $row['is_protected'] ? 'protected' : 'expires',
                        $protect ? 'protected' : 'expires');
            setFlash('success', $protect
                ? sprintf('“%s” is protected and will not be removed automatically.', $row['name'])
                : sprintf('“%s” will now expire under the normal retention rules.', $row['name']));
        } else {
            setFlash('error', 'That retention setting could not be changed.');
        }

        redirect($this->backUrl());
    }

    /* ─────────────────────────────────────────────────────────────────
       Deletion
       ───────────────────────────────────────────────────────────────── */

    public function delete(): void
    {
        authorize('backup.delete');
        requirePost();
        enforceCSRF();

        $row = $this->requireBackup();

        // A protected backup is protected from the operator as well as from
        // the sweep. Releasing the hold first is one extra click and makes
        // "delete the emergency copy" a decision rather than a slip.
        if (!empty($row['is_protected'])) {
            setFlash('error', sprintf(
                '“%s” is a protected recovery point. Release the retention hold first if you really mean to delete it.',
                $row['name']
            ));
            redirect($this->backUrl());
        }

        if (BackupManager::deleteBackup($row)) {
            setFlash('success', sprintf('“%s” was deleted and %s freed.',
                                        $row['name'], formatBytes((int) $row['file_size'])));
        } else {
            setFlash('error', 'The archive could not be removed from disk, so the record was kept.');
        }

        redirect($this->backUrl());
    }

    /** Run the retention sweep by hand. */
    public function sweep(): void
    {
        authorize('backup.manage');
        requirePost();
        enforceCSRF();

        $swept = BackupManager::sweepRetention();

        if ($swept['deleted'] === 0) {
            setFlash('info', 'Nothing has expired. No backups were removed.');
        } else {
            setFlash('success', sprintf('%d expired backup%s removed, %s freed.',
                $swept['deleted'], $swept['deleted'] === 1 ? '' : 's', formatBytes($swept['freed'])));
        }

        redirect($this->backUrl());
    }

    /* ─────────────────────────────────────────────────────────────────
       Restore
       ───────────────────────────────────────────────────────────────── */

    /**
     * Restore the system from a backup.
     *
     * Three gates before BackupManager::restore() is even called, in
     * increasing order of cost to the operator:
     *
     *   1. the permission
     *   2. the typed phrase — proves this was meant, not mis-clicked
     *   3. the account password — proves it is still the right person
     *
     * The engine then adds its own: it re-verifies the archive against the
     * file on disk and takes a protected emergency copy of the current system,
     * and it refuses to proceed if either fails.
     */
    public function restore(): void
    {
        authorize('backup.restore');
        requirePost();
        enforceCSRF();

        $row  = $this->requireBackup();
        $type = (string) ($_POST['restore_type'] ?? '');

        if (!in_array($type, ['database', 'files', 'full'], true)) {
            setFlash('error', 'Choose what to restore.');
            redirect($this->backUrl());
        }

        // Compared exactly, including case. A phrase that accepts "restore"
        // is a phrase people type without reading.
        if (($_POST['confirm_phrase'] ?? '') !== self::RESTORE_PHRASE) {
            setFlash('error', 'The restore was not started: type ' . self::RESTORE_PHRASE . ' exactly to confirm.');
            redirect($this->backUrl());
        }

        if (!$this->passwordConfirmed((string) ($_POST['password'] ?? ''))) {
            backupAudit('failed_restore', (int) $row['id'], '', 'Password confirmation failed.');
            setFlash('error', 'That password was not correct. The restore was not started.');
            redirect($this->backUrl());
        }

        $result = BackupManager::restore((int) $row['id'], $type, $_SESSION['user_id'] ?? null);

        if ($result['ok']) {
            setFlash('success', sprintf(
                'Restore complete. The system was rolled back to “%s”. A protected safety copy of the previous state was taken first and is in the list below.',
                $row['name']
            ));
        } else {
            setFlash('error', 'The restore failed and was rolled back where possible: ' . $result['error']);
        }

        redirect($this->backUrl());
    }

    /**
     * Re-authenticate the signed-in operator.
     *
     * Read fresh from the database rather than trusted from the session,
     * because the session was established at login and says nothing about who
     * is at the keyboard now.
     */
    private function passwordConfirmed(string $password): bool
    {
        if ($password === '') {
            return false;
        }

        $stmt = getDBConnection()->prepare("SELECT password FROM users WHERE id = :id AND is_active = 1");
        $stmt->execute([':id' => $_SESSION['user_id'] ?? 0]);
        $hash = (string) ($stmt->fetchColumn() ?: '');

        return $hash !== '' && password_verify($password, $hash);
    }

    /* ─────────────────────────────────────────────────────────────────
       Settings & schedules
       ───────────────────────────────────────────────────────────────── */

    public function settings(): void
    {
        authorize('backup.manage');

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            $this->saveSettings();
            return;
        }

        renderPage(VIEWS_PATH . '/admin/backup/settings.php', [
            'schedules'    => backupSchedules(),
            'storage'      => backupStorageUsage(),
            'health'       => backupHealth(),
            'exposed'      => backupRootIsExposed(),
            'lastSweep'    => setting('backup_retention_last_run', ''),
            // The installation notice on this page is the module's main
            // instruction for making automatic backup work at all, so it shows
            // the real command for this installation and the real state of the
            // task, not an example to be adapted.
            'scheduler'    => backupSchedulerState(),
            'command'      => backupSchedulerCommand(),
            'taskInstalled' => backupWindowsTaskInstalled(),
            'pageTitle'    => 'Backup Settings',
            'pageSubtitle' => 'Schedules, retention, recovery objective and storage.',
            'breadcrumbs'  => [
                ['label' => 'Backup Management', 'url' => APP_URL . '/index.php?page=backup'],
                ['label' => 'Settings'],
            ],
            'pageStyles'   => ['pages/backup'],
            'pageHeaderVariant' => 'form',
            'backLink'     => ['url' => APP_URL . '/index.php?page=backup', 'label' => 'Back to backups'],
        ]);
    }

    /**
     * Write the scalar settings.
     *
     * Each value is clamped to a range that makes sense rather than merely
     * cast: an RPO of zero hours would put the health tile permanently in
     * Critical, and a negative retention would expire every backup the moment
     * it was written.
     */
    private function saveSettings(): void
    {
        requirePost();
        enforceCSRF();

        $tz = (string) ($_POST['backup_timezone'] ?? '');
        if (!in_array($tz, DateTimeZone::listIdentifiers(), true)) {
            $tz = (string) setting('backup_timezone', 'UTC');
        }

        $values = [
            'backup_timezone'          => $tz,
            'backup_rpo_hours'         => (string) max(1, min(720, (int) ($_POST['backup_rpo_hours'] ?? 24))),
            'backup_storage_quota_gb'  => (string) max(0, min(4096, (int) ($_POST['backup_storage_quota_gb'] ?? 0))),
            'backup_failure_threshold' => (string) max(1, min(20, (int) ($_POST['backup_failure_threshold'] ?? 2))),
        ];

        $db   = getDBConnection();
        $stmt = $db->prepare("UPDATE settings SET setting_value = :v WHERE setting_key = :k AND setting_group = 'backup'");
        foreach ($values as $key => $value) {
            $stmt->execute([':v' => $value, ':k' => $key]);
        }

        // The timezone decides what "02:00" means, so every stored next_run_at
        // is stale the moment it changes.
        backupRefreshNextRuns();

        backupAudit('updated_backup_settings', 0, '', implode(', ', array_map(
            static fn(string $k, string $v): string => $k . '=' . $v,
            array_keys($values), $values
        )));

        setFlash('success', 'Backup settings saved.');
        redirect(APP_URL . '/index.php?page=backup&action=settings');
    }

    /**
     * Write the three schedules.
     *
     * next_run_at is recomputed here rather than left for the runner to work
     * out, so the screen can show the operator the consequence of what they
     * just saved instead of "—" until the next tick.
     */
    public function saveSchedules(): void
    {
        authorize('backup.manage');
        requirePost();
        enforceCSRF();

        $posted = is_array($_POST['schedules'] ?? null) ? $_POST['schedules'] : [];
        $db     = getDBConnection();

        $stmt = $db->prepare("
            UPDATE backup_schedules
               SET is_active = :active, backup_type = :type, run_at = :run_at,
                   day_of_week = :dow, day_of_month = :dom, retention_days = :keep
             WHERE frequency = :freq
        ");

        $changed = [];

        foreach (backupSchedules() as $existing) {
            $freq = $existing['frequency'];
            $in   = is_array($posted[$freq] ?? null) ? $posted[$freq] : [];

            $type = (string) ($in['backup_type'] ?? $existing['backup_type']);
            if (!array_key_exists($type, backupTypes())) {
                $type = $existing['backup_type'];
            }

            // HH:MM from a time input; anything else keeps the stored value
            // rather than silently becoming midnight.
            $time = (string) ($in['run_at'] ?? '');
            $runAt = preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $time)
                ? $time . ':00'
                : $existing['run_at'];

            $stmt->execute([
                ':active' => !empty($in['is_active']) ? 1 : 0,
                ':type'   => $type,
                ':run_at' => $runAt,
                ':dow'    => max(1, min(7,  (int) ($in['day_of_week']  ?? $existing['day_of_week']))),
                ':dom'    => max(1, min(31, (int) ($in['day_of_month'] ?? $existing['day_of_month']))),
                ':keep'   => max(1, min(3650, (int) ($in['retention_days'] ?? $existing['retention_days']))),
                ':freq'   => $freq,
            ]);

            $changed[] = $freq . '=' . (!empty($in['is_active']) ? 'on' : 'off');
        }

        backupRefreshNextRuns();
        backupAudit('updated_backup_settings', 0, '', 'schedules: ' . implode(' ', $changed));

        setFlash('success', 'Schedules saved. They run through the command-line runner — see the note on this page.');
        redirect(APP_URL . '/index.php?page=backup&action=settings');
    }

    /* ─────────────────────────────────────────────────────────────────
       Internals
       ───────────────────────────────────────────────────────────────── */

    /**
     * The backup this request names, or a refusal.
     *
     * The only route from a request parameter to a backup row, and it takes a
     * UUID. There is no id-based lookup anywhere in this controller, so there
     * is nothing to enumerate: a wrong or forged handle is indistinguishable
     * from one that has been deleted.
     */
    private function requireBackup(): array
    {
        $row = $this->model->findByPublicId((string) ($_POST['id'] ?? $_GET['id'] ?? ''));

        if (!$row) {
            setFlash('error', 'That backup could not be found.');
            redirect($this->backUrl());
        }
        return $row;
    }

    /** Back to the list, keeping the tab the operator was on. */
    private function backUrl(): string
    {
        $url  = APP_URL . '/index.php?page=backup';
        $type = uiPick($_POST['return_type'] ?? $_GET['type'] ?? '', array_keys(Backup::TABS));

        return $type !== '' ? $url . '&type=' . $type : $url;
    }
}
