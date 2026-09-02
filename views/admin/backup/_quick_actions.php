<?php
/**
 * Quick Actions.
 *
 * Four, and each one does something. An action the current state cannot
 * support is left out rather than drawn and made inert — a disabled-looking
 * tile that does nothing when clicked teaches people that the panel is
 * decorative, and this is the panel they will use in a hurry.
 *
 * A note on the fourth. The requirement calls it "Test Restore". What is
 * actually offered is a full integrity test of the newest archive: it resolves
 * the file, matches its checksum, opens the zip, reads the manifest back and
 * confirms the dump and every catalogued file are present. It does not restore
 * anything, because a genuine restore rehearsal needs a scratch database to
 * land in and this installation has one database. So it is named for what it
 * does. Calling it a restore test would be the module's own dashboard telling
 * its first lie.
 *
 * Expects: $health, $canCreate, $canRestore, $canManage, $canVerify,
 *          $restorable, $busy, $activeType
 */
$newest  = $health['last_backup'];
$actions = [];

if ($canCreate && !$busy) {
    $actions[] = [
        'label' => 'Create New Backup',
        'hint'  => 'Full, database or files',
        'icon'  => 'bi-plus-circle', 'tone' => 'primary',
        'attrs' => ['data-modal-open' => 'backupCreateModal'],
        'url'   => APP_URL . '/index.php?page=backup&modal=create',
    ];
}

if ($canRestore && !empty($restorable) && !$busy) {
    $actions[] = [
        'label' => 'Restore from Backup',
        'hint'  => count($restorable) . ' verified recovery point' . (count($restorable) === 1 ? '' : 's'),
        'icon'  => 'bi-arrow-counterclockwise', 'tone' => 'warning',
        'attrs' => ['data-modal-open' => 'backupRestoreModal'],
        'url'   => APP_URL . '/index.php?page=backup&modal=restore',
    ];
}

if ($canManage) {
    $actions[] = [
        'label' => 'Backup Settings',
        'hint'  => 'Schedules, retention, objective',
        'icon'  => 'bi-sliders', 'tone' => 'info',
        'url'   => APP_URL . '/index.php?page=backup&action=settings',
    ];
}

/* Only when there is something to test. */
$canTest = $canVerify && $newest && !empty($newest['public_id']) && !$busy;
?>
<div class="card">
    <div class="card__header">
        <div class="card__title">Quick Actions</div>
    </div>
    <div class="card__body">
        <?php if (empty($actions) && !$canTest): ?>
            <p class="form-hint">
                No actions are available right now<?= $busy ? ' — a backup is running.' : '.' ?>
            </p>
        <?php else: ?>
            <div class="quick-actions quick-actions--stack">
                <?php foreach ($actions as $a): ?>
                    <a class="quick-action" href="<?= $a['url'] ?>"
                       <?php foreach ($a['attrs'] ?? [] as $k => $v): ?><?= sanitize($k) ?>="<?= sanitize($v) ?>" <?php endforeach ?>>
                        <span class="quick-action__icon quick-action__icon--<?= $a['tone'] ?>">
                            <i class="bi <?= $a['icon'] ?>" aria-hidden="true"></i>
                        </span>
                        <span class="quick-action__text">
                            <span class="quick-action__label"><?= sanitize($a['label']) ?></span>
                            <span class="quick-action__hint"><?= sanitize($a['hint']) ?></span>
                        </span>
                        <span class="quick-action__go"><i class="bi bi-arrow-right" aria-hidden="true"></i></span>
                    </a>
                <?php endforeach ?>

                <?php if ($canTest): ?>
                    <form method="POST" action="<?= APP_URL ?>/index.php?page=backup&amp;action=verify" class="quick-action__form">
                        <?= csrfField() ?>
                        <input type="hidden" name="id" value="<?= sanitize($newest['public_id']) ?>">
                        <input type="hidden" name="return_type" value="<?= sanitize($activeType) ?>">
                        <button type="submit" class="quick-action">
                            <span class="quick-action__icon quick-action__icon--success">
                                <i class="bi bi-clipboard-check" aria-hidden="true"></i>
                            </span>
                            <span class="quick-action__text">
                                <span class="quick-action__label">Test Latest Backup</span>
                                <span class="quick-action__hint">Full integrity check of <?= sanitize(truncate((string) $newest['name'], 28)) ?></span>
                            </span>
                            <span class="quick-action__go"><i class="bi bi-arrow-right" aria-hidden="true"></i></span>
                        </button>
                    </form>
                <?php endif ?>
            </div>
        <?php endif ?>
    </div>
</div>
