<?php
/**
 * Create Backup dialog.
 *
 * Three choices, a name, a retention hold, and a summary that changes as the
 * selection changes. The summary is the point of the dialog: it says what will
 * be captured and warns that the page waits, so nobody starts a full backup of
 * a large uploads tree expecting it to return instantly.
 *
 * Expects: $activeType, $storage
 */
$types = backupTypes();
$descs = backupTypeDescriptions();

$icons = ['full' => 'bi-box-seam', 'database' => 'bi-database', 'files' => 'bi-folder2-open'];

/* An honest scale cue, from what the last backups of each type actually
   weighed. Absent until there is a real figure — an estimate invented from
   nothing is exactly the sort of number this module must not print. */
$typical = [];
foreach ($storage['by_type'] as $t => $row) {
    if ($row['count'] > 0) {
        $typical[$t] = formatBytes((int) round($row['bytes'] / $row['count']));
    }
}
?>
<div class="modal" id="backupCreateModal" data-modal
     <?= (($_GET['modal'] ?? '') === 'create') ? 'data-modal-autoopen' : '' ?> hidden>
    <div class="modal__backdrop" data-modal-close></div>
    <div class="modal__dialog modal__dialog--lg" role="dialog" aria-modal="true"
         aria-labelledby="backupCreateTitle" tabindex="-1">
        <header class="modal__header">
            <div>
                <h3 class="modal__title" id="backupCreateTitle">
                    <i class="bi bi-shield-plus" aria-hidden="true"></i> Create Backup
                </h3>
                <p class="modal__subtitle">
                    Written to protected storage outside the web root, then checksummed and verified before it counts.
                </p>
            </div>
            <button type="button" class="modal__close" data-modal-close aria-label="Close">
                <i class="bi bi-x-lg" aria-hidden="true"></i>
            </button>
        </header>

        <form class="modal__form" method="POST"
              action="<?= APP_URL ?>/index.php?page=backup&amp;action=create"
              data-backup-create>
            <?= csrfField() ?>
            <input type="hidden" name="return_type" value="<?= sanitize($activeType) ?>">

            <div class="modal__body">
                <div class="form-group">
                    <span class="form-label">What should this backup contain?</span>
                    <div class="choice-grid">
                        <?php foreach ($types as $value => $label): ?>
                            <label class="choice" for="btype-<?= $value ?>">
                                <input type="radio" name="type" id="btype-<?= $value ?>" value="<?= $value ?>"
                                       class="choice__input" <?= $value === 'full' ? 'checked' : '' ?>
                                       data-choice-label="<?= sanitize($label) ?>">
                                <span class="choice__box">
                                    <span class="choice__icon"><i class="bi <?= $icons[$value] ?>" aria-hidden="true"></i></span>
                                    <span class="choice__text">
                                        <span class="choice__title"><?= sanitize($label) ?></span>
                                        <span class="choice__desc"><?= sanitize($descs[$value]) ?></span>
                                        <?php if (isset($typical[$value])): ?>
                                            <span class="choice__meta">Recent backups of this kind: about <?= $typical[$value] ?></span>
                                        <?php endif ?>
                                    </span>
                                    <span class="choice__check"><i class="bi bi-check-lg" aria-hidden="true"></i></span>
                                </span>
                            </label>
                        <?php endforeach ?>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label" for="backup-name">Description <span class="form-hint form-hint--inline">optional</span></label>
                    <input type="text" class="form-control" id="backup-name" name="name" maxlength="150"
                           placeholder="Before system update"
                           autocomplete="off">
                    <p class="form-hint">
                        Left blank, the backup is named after its kind and the time it was taken. A description
                        is worth writing when the backup marks a moment — before an upgrade, before a migration.
                    </p>
                </div>

                <div class="form-group">
                    <label class="check-row" for="backup-protected">
                        <input type="checkbox" class="check" id="backup-protected" name="is_protected" value="1">
                        <span>
                            <strong>Protect from automatic cleanup</strong>
                            <span class="form-hint">
                                Retention never removes this backup. Use it for recovery points you must be able
                                to return to months from now.
                            </span>
                        </span>
                    </label>
                </div>

                <div class="notice notice--info">
                    <div class="notice__icon"><i class="bi bi-info-circle" aria-hidden="true"></i></div>
                    <div class="notice__body">
                        <div class="notice__title">
                            About to create: <span data-create-summary>Full Backup</span>
                        </div>
                        <p>
                            The backup runs while you wait — this page will not respond until it finishes,
                            which can take a few minutes on a large system. Do not close the tab.
                        </p>
                    </div>
                </div>
            </div>

            <footer class="modal__footer">
                <button type="submit" class="btn btn--primary" data-submit-busy="Backing up…">
                    <i class="bi bi-shield-check" aria-hidden="true"></i> Start Backup
                </button>
                <button type="button" class="btn btn--outline" data-modal-close>Cancel</button>
                <span class="modal__footer-note">
                    <i class="bi bi-lock" aria-hidden="true"></i> Never served from a public URL
                </span>
            </footer>
        </form>
    </div>
</div>
