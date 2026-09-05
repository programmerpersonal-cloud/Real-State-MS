<?php
/**
 * Backup Management — the workspace.
 *
 * Two columns on desktop: the register on the left, the state of the system on
 * the right. The right column is what somebody reads when they are worried;
 * the left is what they act on. Below 1024px .detail-cols collapses the side
 * panels underneath, which is the right order — schedules and storage are
 * context, and on a phone context belongs after the thing it describes.
 *
 * Every figure on this page comes from the controller, which got it from
 * backupHealth(), backupStorageUsage(), backupSchedulerState() and the Backup
 * model. Nothing here queries, computes a percentage from a guess, or shows a
 * placeholder that looks like data.
 *
 * That rule reaches the scheduler too. A stored next_run_at is a plan, and a
 * plan is only a fact about the future if something is running plans, so the
 * "Next Scheduled Backup" card is qualified by the runner's heartbeat rather
 * than printed on its own authority.
 *
 * Vars from BackupController::index().
 */
$listUrl = APP_URL . '/index.php?page=backup';
$keepTab = $activeType !== '' ? '&type=' . $activeType : '';

$canCreate  = can('backup.create');
$canRestore = can('backup.restore');
$canDelete  = can('backup.delete');
$canVerify  = can('backup.verify');
$canManage  = can('backup.manage');

$busy = !empty($running) || $lockHolder !== null;

/* Set here rather than in the controller because layout.php renders this view
   into a buffer before it draws the header, so a variable set at the top of a
   view is already in scope by the time page_header.php runs. Each carries its
   own `can`, which the header re-checks — the button mirrors the permission,
   authorize() in the controller enforces it.

   Create is disabled outright while a run is in flight. The lock would refuse
   a second run anyway; saying so before the click is better than a flash
   message explaining that nothing happened. */
$actionButtons = [
    ['label' => 'Backup Settings', 'icon' => 'bi-sliders', 'can' => 'backup.manage',
     'url'   => APP_URL . '/index.php?page=backup&action=settings'],
    ['label' => 'Create Backup', 'icon' => 'bi-plus-lg', 'can' => 'backup.create',
     'class' => 'btn--primary' . ($busy ? ' is-disabled' : ''),
     'url'   => $listUrl . '&modal=create',
     'attrs' => $busy
        ? ['aria-disabled' => 'true', 'title' => 'A backup is already running']
        : ['data-modal-open' => 'backupCreateModal']],
];

/* ── The six figures ──────────────────────────────────────────────────
   Each is a fact with a fallback that says "we do not know" rather than a
   zero that reads as "nothing is wrong". "Never" and "Not scheduled" are
   answers; "0" would be a lie in both cases. */
$lastBackup   = $health['last_backup'];
$lastVerified = $health['last_verified'];

$nextRun = null;
foreach ($schedules as $s) {
    if (!empty($s['is_active']) && !empty($s['next_run_at'])
        && ($nextRun === null || $s['next_run_at'] < $nextRun['next_run_at'])) {
        $nextRun = $s;
    }
}

$statCards = [
    [
        'label' => 'Last Successful Backup',
        'value' => $lastBackup ? backupAgo($lastBackup['completed_at']) : 'Never',
        'meta'  => $lastBackup ? backupWhen($lastBackup['completed_at']) : 'No backup has completed',
        'icon'  => 'bi-clock-history',
        'tone'  => $lastBackup ? 'success' : 'danger',
    ],
    [
        'label' => 'Last Verified Backup',
        'value' => $lastVerified ? backupAgo($lastVerified['verified_at']) : 'Never',
        'meta'  => $lastVerified ? sanitize($lastVerified['name']) : 'Nothing has passed verification',
        'icon'  => 'bi-patch-check',
        'tone'  => $lastVerified ? 'success' : 'warning',
    ],
    /* This card is the one that lied. It read "Next: Sep 03, 04:00 · Daily"
       for two days after that time had passed, because a stored next_run_at is
       a plan and nothing here knew whether anything executes plans. The date is
       still shown — it is what the schedule says — but a date the system cannot
       act on is no longer dressed in the calm blue of a promise being kept. */
    [
        'label' => 'Next Scheduled Backup',
        'value' => $nextRun
            ? ($scheduler['installed'] && !$scheduler['stale']
                ? backupScheduleWhen($nextRun['next_run_at'], 'M d, H:i')
                : 'Will not run')
            : 'Not scheduled',
        'meta'  => $nextRun
            ? (!$scheduler['installed']
                ? 'Scheduled for ' . backupScheduleWhen($nextRun['next_run_at'], 'M d, H:i')
                    . ', but the scheduler has never run'
                : ($scheduler['stale']
                    ? 'Scheduled for ' . backupScheduleWhen($nextRun['next_run_at'], 'M d, H:i')
                        . ', but the scheduler stopped ' . $scheduler['ago']
                    : ucfirst($nextRun['frequency']) . ' · ' . (backupTypes()[$nextRun['backup_type']] ?? '')))
            : 'No schedule is enabled',
        'icon'  => 'bi-calendar-event',
        'tone'  => $nextRun
            ? ($scheduler['installed'] && !$scheduler['stale'] ? 'info' : 'danger')
            : 'warning',
    ],
    [
        'label' => 'Total Backups',
        'value' => number_format((int) $tabCounts['']),
        'meta'  => $storage['count'] . ' with an archive on disk',
        'icon'  => 'bi-archive',
        'tone'  => 'primary',
    ],
    [
        'label' => 'Storage Used',
        'value' => formatBytes($storage['used']),
        'meta'  => $storage['quota'] > 0
            ? 'of ' . formatBytes($storage['quota']) . ' quota'
            : ($storage['disk_free'] !== null
                ? formatBytes((int) $storage['disk_free']) . ' free on disk'
                : 'no quota set'),
        'icon'  => 'bi-hdd',
        'tone'  => 'purple',
    ],
    [
        'label' => 'Backup Health',
        'value' => $health['label'],
        'meta'  => 'Recovery objective ' . $health['rpo_hours'] . 'h',
        'icon'  => $health['level'] === 'healthy' ? 'bi-shield-check'
                 : ($health['level'] === 'warning' ? 'bi-shield-exclamation' : 'bi-shield-x'),
        'tone'  => $health['tone'],
    ],
];

/* Tabs reuse the status filter component: plain links carrying the rest of the
   query string, so the tab strip works with scripting off and every tab is a
   shareable URL. The options are the same array the controller validates
   against, so a tab cannot offer a value the query would refuse. */
$statusFilter = [
    'param'   => 'type',
    'value'   => $activeType,
    'options' => array_filter(Backup::TABS, static fn(string $k): bool => $k !== '', ARRAY_FILTER_USE_KEY),
    'counts'  => $tabCounts,
    'total'   => (int) $tabCounts[''],
    'all'     => 'All Backups',
    'tones'   => false,
];
?>

<?php /* The health panel leads only when something is wrong. On a healthy
         system it would be a green box explaining that nothing has happened,
         which is exactly the kind of banner people learn to scroll past — and
         then miss the day it turns red. */ ?>
<?php if ($health['level'] !== 'healthy'): ?>
    <div class="alert alert--<?= $health['level'] === 'critical' ? 'danger' : 'warning' ?> backup-health">
        <i class="bi bi-<?= $health['level'] === 'critical' ? 'shield-x' : 'shield-exclamation' ?>-fill" aria-hidden="true"></i>
        <div>
            <strong>Backup health: <?= sanitize($health['label']) ?></strong>
            <ul class="backup-health__list">
                <?php foreach ($health['findings'] as $f): ?>
                    <li><?= sanitize($f['text']) ?></li>
                <?php endforeach ?>
            </ul>
        </div>
    </div>
<?php endif ?>

<?php /* A run in progress. No percentage and no time estimate: mysqldump
         reports neither, and inventing one is how a progress bar ends up
         sitting at 94% for ten minutes. The page polls for real state and
         reloads when the run ends — see assets/js/backup.js. */ ?>
<?php if ($busy): ?>
    <div class="notice backup-busy" role="status" data-backup-poll
         data-poll-url="<?= APP_URL ?>/index.php?page=backup&amp;action=status">
        <div class="notice__icon"><i class="bi bi-arrow-repeat spin" aria-hidden="true"></i></div>
        <div class="notice__body">
            <div class="notice__title">A backup is running</div>
            <?php $first = $running[0] ?? null; ?>
            <p>
                <?php if ($first): ?>
                    <?= sanitize($first['name']) ?> — started <?= backupAgo($first['started_at']) ?>.
                <?php elseif ($lockHolder): ?>
                    Started by <?= sanitize($lockHolder['owner']) ?>.
                <?php endif ?>
                This page updates itself when the run finishes.
            </p>
        </div>
    </div>
<?php endif ?>

<div class="stats">
    <?php foreach ($statCards as $sc): ?>
        <div class="stat-card">
            <div class="stat-card__icon stat-card__icon--<?= $sc['tone'] ?>">
                <i class="bi <?= $sc['icon'] ?>" aria-hidden="true"></i>
            </div>
            <div class="stat-card__body">
                <div class="stat-card__label"><?= sanitize($sc['label']) ?></div>
                <div class="stat-card__value stat-card__value--compact"><?= $sc['value'] ?></div>
                <div class="stat-card__trend stat-card__trend--muted"><?= $sc['meta'] ?></div>
            </div>
        </div>
    <?php endforeach ?>
</div>

<div class="detail-cols backup-cols">
    <div class="detail-cols__main">
        <div class="table-card">
            <?php require VIEWS_PATH . '/components/ui/status_filter.php'; ?>

            <?php if (!empty($backups)): ?>
                <div class="table-head">
                    <div class="table-head__title">
                        <?= number_format($totalCount) ?> <?= $totalCount === 1 ? 'backup' : 'backups' ?>
                        <?php if ($activeType !== ''): ?>
                            <span class="table-head__count"><?= sanitize(Backup::TABS[$activeType]) ?></span>
                        <?php endif ?>
                    </div>
                    <span class="table-head__note">
                        <i class="bi bi-shield-lock" aria-hidden="true"></i>
                        Stored outside the web root, downloaded only through an authorised endpoint
                    </span>
                </div>
            <?php endif ?>

            <?php require __DIR__ . '/_list.php'; ?>

            <?php if (!empty($backups) && $totalPages > 1): ?>
                <div class="table-foot">
                    <span class="table-foot__note">Page <?= $page ?> of <?= $totalPages ?></span>
                    <?php require VIEWS_PATH . '/components/pagination.php'; ?>
                </div>
            <?php endif ?>
        </div>
    </div>

    <aside class="detail-cols__side">
        <?php require __DIR__ . '/_schedule_card.php'; ?>
        <?php require __DIR__ . '/_storage_card.php'; ?>
        <?php require __DIR__ . '/_activity_card.php'; ?>
        <?php require __DIR__ . '/_quick_actions.php'; ?>
    </aside>
</div>

<?php if ($canCreate) require __DIR__ . '/_create_modal.php'; ?>
<?php if ($canRestore) require __DIR__ . '/_restore_modal.php'; ?>
