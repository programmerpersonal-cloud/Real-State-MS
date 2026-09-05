<?php
/**
 * Backup Settings — schedules, retention, objective, storage.
 *
 * Two forms rather than one. The schedules write to backup_schedules and have
 * to recompute next_run_at; the scalars write to the settings table. Merging
 * them would mean one Save button doing two unrelated things, and a validation
 * failure in either half discarding both.
 *
 * The installation notice at the top is not decoration. Schedules configured
 * here do nothing at all until the command-line runner is installed, and a
 * screen full of active-looking schedules with no scheduler behind them is the
 * single most likely way this module ends up quietly not protecting anything —
 * which is exactly how it was found. The notice therefore reports the runner's
 * real heartbeat rather than repeating the advice unconditionally: red when it
 * has never run, red when it has stopped, green with a timestamp when it is
 * ticking.
 *
 * Vars from BackupController::settings().
 */
$dayNames = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday',
             5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'];

/* The command and the installer path come from backupSchedulerCommand(), which
   resolves php.exe from PHP_BINARY and the script from BASE_PATH, so what is
   printed here is what will actually run on this machine. The previous version
   hard-coded D:\XAMPP\php\php.exe, which was right for one installation and
   quietly wrong for every other. */
$installerPath = str_replace('/', DIRECTORY_SEPARATOR, BASE_PATH . '/database/tools/install_scheduler.bat');

/* Timezones offered as the full IANA list would be 400-odd rows of noise. The
   regions the business plausibly operates in come first; whatever is currently
   set is always included, so a value set by hand is never silently replaced by
   the first option in a list that does not contain it. */
$current   = backupTimezone()->getName();
$tzOptions = array_values(array_unique(array_merge(
    [$current],
    ['Africa/Mogadishu', 'Africa/Nairobi', 'Africa/Addis_Ababa', 'Africa/Djibouti',
     'Europe/London', 'Europe/Istanbul', 'Asia/Dubai', 'Asia/Riyadh', 'UTC']
)));
?>

<?php if ($exposed): ?>
    <div class="alert alert--danger">
        <i class="bi bi-shield-x-fill" aria-hidden="true"></i>
        <div>
            <strong>The backup directory is inside the web root.</strong>
            Archives at <code class="hash"><?= sanitize(backupRoot()) ?></code> may be reachable over HTTP.
            Set <code class="hash">BACKUP_PATH</code> in <code class="hash">.env</code> to a directory
            the web server does not serve, and move the existing archives there.
        </div>
    </div>
<?php endif ?>

<?php
/* The notice is the module's one instruction for making automatic backup work,
   so it reports what is true on this machine rather than describing what ought
   to be done in general. Three states, and the tone of each is the finding:

     never ticked   the runner has never been invoked. Whatever the switches
                    below say, nothing is backing anything up — the state this
                    installation was in, with an enabled daily schedule two
                    days past its next run and no way to tell from any screen.
     stalled        it ran before and has stopped.
     ticking        it checked in recently, and the switches below mean what
                    they say.

   The command shown is generated from this installation's own paths — see
   backupSchedulerCommand() — because an example command that has to be edited
   before it works is an example that gets pasted unedited. */
$schedulerOn  = false;
foreach ($schedules as $s) {
    if (!empty($s['is_active'])) {
        $schedulerOn = true;
        break;
    }
}

$tone  = 'info';
$icon  = 'bi-terminal';
$title = 'Schedules need the runner installed';

if (!$scheduler['installed']) {
    $tone  = $schedulerOn ? 'danger' : 'warning';
    $icon  = 'bi-exclamation-octagon-fill';
    $title = 'The backup scheduler has never run';
} elseif ($scheduler['stale']) {
    $tone  = 'danger';
    $icon  = 'bi-exclamation-triangle-fill';
    $title = 'The backup scheduler has stopped';
} else {
    $tone  = 'success';
    $icon  = 'bi-broadcast';
    $title = 'The backup scheduler is running';
}
?>
<div class="notice notice--<?= $tone ?>">
    <div class="notice__icon"><i class="bi <?= $icon ?>" aria-hidden="true"></i></div>
    <div class="notice__body">
        <div class="notice__title"><?= sanitize($title) ?></div>

        <?php if (!$scheduler['installed']): ?>
            <p>
                Nothing on this page runs by itself. Automatic backups happen when the command below is run
                on a timer, and on this installation it never has been &mdash; so
                <?= $schedulerOn
                        ? 'the schedules below are switched on but are not firing.'
                        : 'no schedule can fire even once one is switched on.' ?>
            </p>
        <?php elseif ($scheduler['stale']): ?>
            <p>
                The runner last checked in <strong><?= sanitize($scheduler['ago']) ?></strong> and should
                check in every few minutes. Until it starts again, no schedule can fire. Check that the
                <code class="hash"><?= sanitize($command['task_name']) ?></code> task is still enabled in
                Windows Task Scheduler.
            </p>
        <?php else: ?>
            <p>
                The runner last checked in <strong><?= sanitize($scheduler['ago']) ?></strong>
                (<?= number_format((int) $scheduler['tick_count']) ?> checks so far). Schedules switched on
                below will fire at the times shown.
            </p>
            <?php if ($scheduler['last_result'] !== ''): ?>
                <p class="text-subtle">Last check: <?= sanitize($scheduler['last_result']) ?></p>
            <?php endif ?>
        <?php endif ?>

        <?php if (!$scheduler['installed'] || $scheduler['stale']): ?>
            <p>
                On Windows, install it once from an elevated command prompt &mdash; one task, ticking every
                five minutes, which is enough for any schedule because the runner works out for itself what
                is due:
            </p>
            <pre class="codeblock"><code><?= sanitize($installerPath) ?></code></pre>
            <p>Or point a Task Scheduler entry at this command directly:</p>
        <?php else: ?>
            <p>Run it by hand at any time:</p>
        <?php endif ?>

        <pre class="codeblock"><code><?= sanitize($command['command']) ?></code></pre>

        <p>
            <?php if ($taskInstalled === true): ?>
                The <code class="hash"><?= sanitize($command['task_name']) ?></code> task is registered with
                Windows Task Scheduler.
            <?php elseif ($taskInstalled === false): ?>
                The <code class="hash"><?= sanitize($command['task_name']) ?></code> task is
                <strong>not</strong> registered with Windows Task Scheduler.
            <?php endif ?>
            Add <code class="hash">--doctor</code> to the command above for a full diagnosis of why automatic
            backup is or is not working, <code class="hash">--status</code> for a read-only summary, or
            <code class="hash">--log</code> for the last runs.
        </p>
    </div>
</div>

<div class="detail-cols">
    <div class="detail-cols__main">
        <form method="POST" action="<?= APP_URL ?>/index.php?page=backup&amp;action=schedules">
            <?= csrfField() ?>
            <div class="card">
                <div class="card__header">
                    <div>
                        <div class="card__title">Automatic Schedules</div>
                        <div class="card__subtitle">
                            Times are wall-clock in the timezone set opposite, not UTC.
                        </div>
                    </div>
                </div>

                <div class="card__body">
                    <?php foreach ($schedules as $s): $f = $s['frequency']; ?>
                        <fieldset class="form-section sched-edit">
                            <legend class="section-title"><?= ucfirst(sanitize($f)) ?></legend>

                            <label class="check-row" for="sched-<?= $f ?>-active">
                                <input type="checkbox" class="check" id="sched-<?= $f ?>-active"
                                       name="schedules[<?= $f ?>][is_active]" value="1"
                                       <?= !empty($s['is_active']) ? 'checked' : '' ?>>
                                <span><strong>Run this schedule automatically</strong></span>
                            </label>

                            <div class="form-grid form-grid--3">
                                <div class="form-group">
                                    <label class="form-label" for="sched-<?= $f ?>-type">Backup type</label>
                                    <select class="form-control" id="sched-<?= $f ?>-type" name="schedules[<?= $f ?>][backup_type]">
                                        <?php foreach (backupTypes() as $v => $l): ?>
                                            <option value="<?= $v ?>" <?= $s['backup_type'] === $v ? 'selected' : '' ?>><?= sanitize($l) ?></option>
                                        <?php endforeach ?>
                                    </select>
                                </div>

                                <div class="form-group">
                                    <label class="form-label" for="sched-<?= $f ?>-time">Run at</label>
                                    <input type="time" class="form-control" id="sched-<?= $f ?>-time"
                                           name="schedules[<?= $f ?>][run_at]"
                                           value="<?= substr((string) $s['run_at'], 0, 5) ?>">
                                </div>

                                <div class="form-group">
                                    <label class="form-label" for="sched-<?= $f ?>-keep">Keep for (days)</label>
                                    <input type="number" class="form-control" id="sched-<?= $f ?>-keep"
                                           name="schedules[<?= $f ?>][retention_days]" min="1" max="3650"
                                           value="<?= (int) $s['retention_days'] ?>">
                                </div>

                                <?php if ($f === 'weekly'): ?>
                                    <div class="form-group">
                                        <label class="form-label" for="sched-weekly-dow">Day of the week</label>
                                        <select class="form-control" id="sched-weekly-dow" name="schedules[weekly][day_of_week]">
                                            <?php foreach ($dayNames as $n => $label): ?>
                                                <option value="<?= $n ?>" <?= (int) $s['day_of_week'] === $n ? 'selected' : '' ?>><?= $label ?></option>
                                            <?php endforeach ?>
                                        </select>
                                    </div>
                                <?php elseif ($f === 'monthly'): ?>
                                    <div class="form-group">
                                        <label class="form-label" for="sched-monthly-dom">Day of the month</label>
                                        <input type="number" class="form-control" id="sched-monthly-dom"
                                               name="schedules[monthly][day_of_month]" min="1" max="31"
                                               value="<?= (int) $s['day_of_month'] ?>">
                                        <p class="form-hint">A day past the end of a short month runs on its last day.</p>
                                    </div>
                                <?php endif ?>
                            </div>

                            <p class="form-hint">
                                <?php if (!empty($s['is_active']) && !empty($s['next_run_at'])): ?>
                                    <i class="bi bi-clock" aria-hidden="true"></i>
                                    Next run <?= backupScheduleWhen($s['next_run_at'], 'D d M Y, H:i') ?>.
                                <?php else: ?>
                                    <i class="bi bi-slash-circle" aria-hidden="true"></i>
                                    Inactive — no run is scheduled.
                                <?php endif ?>
                                <?php if (!empty($s['last_run_at'])): ?>
                                    Last ran <?= backupAgo($s['last_run_at']) ?> (<?= sanitize($s['last_status']) ?>).
                                <?php endif ?>
                            </p>
                        </fieldset>
                    <?php endforeach ?>
                </div>

                <div class="card__footer card__footer--actions">
                    <button type="submit" class="btn btn--primary">
                        <i class="bi bi-check-lg" aria-hidden="true"></i> Save Schedules
                    </button>
                </div>
            </div>
        </form>
    </div>

    <aside class="detail-cols__side">
        <form method="POST" action="<?= APP_URL ?>/index.php?page=backup&amp;action=settings">
            <?= csrfField() ?>
            <div class="card">
                <div class="card__header">
                    <div class="card__title">Objective &amp; Storage</div>
                </div>
                <div class="card__body">
                    <div class="form-group">
                        <label class="form-label" for="backup_timezone">Timezone</label>
                        <select class="form-control" id="backup_timezone" name="backup_timezone">
                            <?php foreach ($tzOptions as $tz): ?>
                                <option value="<?= sanitize($tz) ?>" <?= $tz === $current ? 'selected' : '' ?>><?= sanitize($tz) ?></option>
                            <?php endforeach ?>
                        </select>
                        <p class="form-hint">What the times above mean. Changing it reschedules every run.</p>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="backup_rpo_hours">Recovery point objective (hours)</label>
                        <input type="number" class="form-control" id="backup_rpo_hours" name="backup_rpo_hours"
                               min="1" max="720" value="<?= (int) backupRpoHours() ?>">
                        <p class="form-hint">
                            The most data loss that would be acceptable. Health turns Critical when the newest
                            backup is older than this.
                        </p>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="backup_storage_quota_gb">Storage quota (GB)</label>
                        <input type="number" class="form-control" id="backup_storage_quota_gb"
                               name="backup_storage_quota_gb" min="0" max="4096"
                               value="<?= (int) (backupStorageQuotaBytes() / 1024 / 1024 / 1024) ?>">
                        <p class="form-hint">
                            Zero means no quota. Currently using <?= formatBytes($storage['used']) ?>.
                        </p>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="backup_failure_threshold">Alert after consecutive failures</label>
                        <input type="number" class="form-control" id="backup_failure_threshold"
                               name="backup_failure_threshold" min="1" max="20"
                               value="<?= (int) backupFailureThreshold() ?>">
                        <p class="form-hint">
                            Administrators are notified once, when the streak reaches this number — not on
                            every failure after it.
                        </p>
                    </div>
                </div>
                <div class="card__footer card__footer--actions">
                    <button type="submit" class="btn btn--primary">
                        <i class="bi bi-check-lg" aria-hidden="true"></i> Save Settings
                    </button>
                </div>
            </div>
        </form>

        <div class="card">
            <div class="card__header">
                <div class="card__title">Store</div>
            </div>
            <div class="card__body">
                <div class="profile-meta">
                    <div class="profile-meta__row">
                        <span class="profile-meta__label">Location</span>
                        <span class="profile-meta__value"><code class="hash"><?= sanitize(backupRoot()) ?></code></span>
                    </div>
                    <div class="profile-meta__row">
                        <span class="profile-meta__label">Outside web root</span>
                        <span class="profile-meta__value">
                            <?= $exposed
                                ? '<span class="status status--danger"><span class="status__dot"></span>No</span>'
                                : '<span class="status status--success"><span class="status__dot"></span>Yes</span>' ?>
                        </span>
                    </div>
                    <div class="profile-meta__row">
                        <span class="profile-meta__label">mysqldump</span>
                        <span class="profile-meta__value">
                            <?= backupBinary('mysqldump') !== null
                                ? '<span class="status status--success"><span class="status__dot"></span>Found</span>'
                                : '<span class="status status--danger"><span class="status__dot"></span>Missing</span>' ?>
                        </span>
                    </div>
                    <div class="profile-meta__row">
                        <span class="profile-meta__label">Zip extension</span>
                        <span class="profile-meta__value">
                            <?= class_exists('ZipArchive')
                                ? '<span class="status status--success"><span class="status__dot"></span>Enabled</span>'
                                : '<span class="status status--danger"><span class="status__dot"></span>Missing</span>' ?>
                        </span>
                    </div>
                    <div class="profile-meta__row">
                        <span class="profile-meta__label">Last cleanup</span>
                        <span class="profile-meta__value">
                            <?= $lastSweep ? backupAgo($lastSweep) : '<span class="text-subtle">Never run</span>' ?>
                        </span>
                    </div>
                </div>
            </div>
        </div>
    </aside>
</div>
