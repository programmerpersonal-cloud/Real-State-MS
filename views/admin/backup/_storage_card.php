<?php
/**
 * Storage Usage — what the archives actually occupy.
 *
 * The meter shows a percentage only when there is something real to be a
 * percentage of: a configured quota, or a disk whose size the platform will
 * report. With neither, the card shows the total and the breakdown and no bar
 * at all — a bar with an invented denominator is worse than no bar, because it
 * looks like a measurement.
 *
 * Expects: $storage, $canManage
 */
$pct   = $storage['pct'];
$quota = $storage['quota'];

$basis = $quota > 0
    ? 'of ' . formatBytes($quota) . ' quota'
    : ($storage['disk_total'] !== null ? 'of ' . formatBytes((int) $storage['disk_total']) . ' volume' : '');

$tone = $pct === null ? 'primary' : ($pct >= 90 ? 'danger' : ($pct >= 75 ? 'warning' : 'primary'));

$legend = [
    'full'     => ['Full Backups',     'purple'],
    'database' => ['Database Backups', 'info'],
    'files'    => ['Files Backups',    'orange'],
];
?>
<div class="card">
    <div class="card__header">
        <div>
            <div class="card__title">Storage Usage</div>
            <div class="card__subtitle"><?= sanitize(backupRoot()) ?></div>
        </div>
    </div>

    <div class="card__body">
        <div class="meter__head">
            <span class="meter__value"><?= formatBytes($storage['used']) ?></span>
            <?php if ($basis !== ''): ?>
                <span class="meter__basis"><?= $basis ?></span>
            <?php endif ?>
        </div>

        <?php if ($pct !== null): ?>
            <div class="meter meter--<?= $tone ?>"
                 role="meter" aria-valuenow="<?= $pct ?>" aria-valuemin="0" aria-valuemax="100"
                 aria-label="Backup storage used">
                <span class="meter__fill" style="width: <?= max(1.5, min(100, $pct)) ?>%"></span>
            </div>
            <div class="meter__foot">
                <span><?= $pct ?>% used</span>
                <?php if ($storage['disk_free'] !== null): ?>
                    <span><?= formatBytes((int) $storage['disk_free']) ?> free</span>
                <?php endif ?>
            </div>
        <?php else: ?>
            <p class="form-hint">
                No quota is set and this platform does not report volume size, so there is no
                percentage to show. Set a quota in Backup Settings to get one.
            </p>
        <?php endif ?>

        <ul class="breakdown">
            <?php foreach ($legend as $type => [$label, $toneVar]): ?>
                <?php $row = $storage['by_type'][$type] ?? ['count' => 0, 'bytes' => 0]; ?>
                <li class="breakdown__row">
                    <span class="breakdown__dot breakdown__dot--<?= $toneVar ?>" aria-hidden="true"></span>
                    <span class="breakdown__label"><?= $label ?></span>
                    <span class="breakdown__count"><?= (int) $row['count'] ?></span>
                    <span class="breakdown__value"><?= formatBytes((int) $row['bytes']) ?></span>
                </li>
            <?php endforeach ?>
        </ul>
    </div>

    <?php /* "Manage Storage" would be a button that opens nothing, so this is
             the real action instead: running the retention sweep is the only
             thing an operator can actually do to storage from here, and it
             does something measurable. */ ?>
    <?php if ($canManage): ?>
        <div class="card__footer card__footer--actions">
            <form method="POST" action="<?= APP_URL ?>/index.php?page=backup&amp;action=sweep" class="inline-form">
                <?= csrfField() ?>
                <button type="submit" class="btn btn--outline btn--sm"
                        data-confirm="Expired backups will be deleted from disk. Protected recovery points and anything still in use are never touched."
                        data-confirm-title="Run retention cleanup?"
                        data-confirm-action="Run cleanup"
                        data-confirm-tone="warning">
                    <i class="bi bi-recycle" aria-hidden="true"></i> Run retention cleanup
                </button>
            </form>
        </div>
    <?php endif ?>
</div>
