<?php
/**
 * Restore dialog — the most dangerous control in the application.
 *
 * Everything about it is shaped to slow the operator down at the right moment
 * and to make the consequence unmissable before the button is reachable:
 *
 *   - only verified backups are listed; an unproved archive is not offered
 *   - what will be overwritten is stated in plain words, not implied
 *   - the confirmation phrase must be typed exactly, case included
 *   - the account password is required, proving who is at the keyboard
 *   - the submit button stays disabled until both are supplied
 *
 * The disabled button is a courtesy, not a control. BackupController::restore()
 * re-checks the permission, the phrase and the password server-side, and the
 * engine re-verifies the archive and takes a protected safety copy before it
 * touches anything.
 *
 * Expects: $restorable, $activeType
 */
$phrase = 'RESTORE';
?>
<div class="modal" id="backupRestoreModal" data-modal
     <?= (($_GET['modal'] ?? '') === 'restore') ? 'data-modal-autoopen' : '' ?> hidden>
    <div class="modal__backdrop" data-modal-close></div>
    <div class="modal__dialog modal__dialog--lg modal__dialog--danger" role="dialog" aria-modal="true"
         aria-labelledby="backupRestoreTitle" tabindex="-1">
        <header class="modal__header">
            <div>
                <h3 class="modal__title" id="backupRestoreTitle">
                    <i class="bi bi-exclamation-octagon-fill" aria-hidden="true"></i> Restore System
                </h3>
                <p class="modal__subtitle">
                    This replaces live data with the contents of a backup. A protected safety copy of the
                    current system is taken first, automatically.
                </p>
            </div>
            <button type="button" class="modal__close" data-modal-close aria-label="Close">
                <i class="bi bi-x-lg" aria-hidden="true"></i>
            </button>
        </header>

        <?php if (empty($restorable)): ?>
            <div class="modal__body">
                <?= uiEmptyState([
                    'icon'  => 'bi-shield-exclamation',
                    'title' => 'No verified backup to restore from',
                    'desc'  => 'Only backups that have passed verification can be restored. Create a backup, or verify an existing one, and it will appear here.',
                ]) ?>
            </div>
            <footer class="modal__footer">
                <button type="button" class="btn btn--outline" data-modal-close>Close</button>
            </footer>
        <?php else: ?>
        <form class="modal__form" method="POST"
              action="<?= APP_URL ?>/index.php?page=backup&amp;action=restore"
              data-backup-restore data-restore-phrase="<?= $phrase ?>">
            <?= csrfField() ?>
            <input type="hidden" name="return_type" value="<?= sanitize($activeType) ?>">

            <div class="modal__body">
                <div class="alert alert--danger">
                    <i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i>
                    <div>
                        <strong>Current data will be overwritten.</strong>
                        Records created or changed since the selected backup was taken will be lost.
                        The safety copy taken beforehand is your way back, and it is protected from cleanup.
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label" for="restore-backup">Restore from</label>
                    <select class="form-control" id="restore-backup" name="id" required data-restore-source>
                        <?php foreach ($restorable as $r): ?>
                            <option value="<?= sanitize($r['public_id']) ?>" data-backup-type="<?= sanitize($r['type']) ?>">
                                <?= sanitize($r['name']) ?>
                                — <?= sanitize(backupTypes()[$r['type']] ?? $r['type']) ?>,
                                <?= formatBytes((int) $r['file_size']) ?>,
                                <?= backupWhen($r['completed_at'], 'd M Y H:i') ?>
                            </option>
                        <?php endforeach ?>
                    </select>
                    <p class="form-hint">Only backups that have passed verification are listed.</p>
                </div>

                <div class="form-group">
                    <span class="form-label">What should be restored?</span>
                    <?php /* Options are filtered in the browser to what the
                             selected archive can actually provide — a files
                             backup cannot restore a database. The controller
                             and the engine both re-check, so the filtering is
                             a convenience rather than the guarantee. */ ?>
                    <div class="choice-grid choice-grid--tight" data-restore-scope>
                        <label class="choice" for="rtype-database" data-scope="database">
                            <input type="radio" name="restore_type" id="rtype-database" value="database" class="choice__input">
                            <span class="choice__box">
                                <span class="choice__icon"><i class="bi bi-database" aria-hidden="true"></i></span>
                                <span class="choice__text">
                                    <span class="choice__title">Database only</span>
                                    <span class="choice__desc">Records return to their state in the backup. Uploaded files are untouched.</span>
                                </span>
                                <span class="choice__check"><i class="bi bi-check-lg" aria-hidden="true"></i></span>
                            </span>
                        </label>
                        <label class="choice" for="rtype-files" data-scope="files">
                            <input type="radio" name="restore_type" id="rtype-files" value="files" class="choice__input">
                            <span class="choice__box">
                                <span class="choice__icon"><i class="bi bi-folder2-open" aria-hidden="true"></i></span>
                                <span class="choice__text">
                                    <span class="choice__title">Files only</span>
                                    <span class="choice__desc">Uploads, documents and attachments are written back. The database is untouched.</span>
                                </span>
                                <span class="choice__check"><i class="bi bi-check-lg" aria-hidden="true"></i></span>
                            </span>
                        </label>
                        <label class="choice choice--danger" for="rtype-full" data-scope="full">
                            <input type="radio" name="restore_type" id="rtype-full" value="full" class="choice__input">
                            <span class="choice__box">
                                <span class="choice__icon"><i class="bi bi-box-seam" aria-hidden="true"></i></span>
                                <span class="choice__text">
                                    <span class="choice__title">Full system restore</span>
                                    <span class="choice__desc">
                                        Database <em>and</em> files. The application goes into maintenance mode
                                        and everyone except you is signed out of it until this finishes.
                                    </span>
                                </span>
                                <span class="choice__check"><i class="bi bi-check-lg" aria-hidden="true"></i></span>
                            </span>
                        </label>
                    </div>
                </div>

                <div class="form-grid form-grid--2">
                    <div class="form-group">
                        <label class="form-label" for="restore-phrase">
                            Type <code class="hash"><?= $phrase ?></code> to confirm
                        </label>
                        <input type="text" class="form-control form-control--code" id="restore-phrase"
                               name="confirm_phrase" autocomplete="off" spellcheck="false"
                               placeholder="<?= $phrase ?>" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="restore-password">Your account password</label>
                        <input type="password" class="form-control" id="restore-password"
                               name="password" autocomplete="current-password" required>
                        <p class="form-hint">Confirms it is still you at the keyboard.</p>
                    </div>
                </div>
            </div>

            <footer class="modal__footer">
                <button type="submit" class="btn btn--danger" data-restore-submit disabled
                        data-submit-busy="Restoring…">
                    <i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i> Restore System
                </button>
                <button type="button" class="btn btn--outline" data-modal-close>Cancel</button>
                <span class="modal__footer-note">
                    <i class="bi bi-life-preserver" aria-hidden="true"></i> A safety copy is taken first
                </span>
            </footer>
        </form>
        <?php endif ?>
    </div>
</div>
