<?php
/**
 * Branch — Form (create/edit)
 */
$b = $branch ?? ($formData ?? []);
// A rejected edit hands its entry back through the session; without this the
// form would redraw from the database and discard what was typed.
if (!empty($branch) && !empty($formData)) {
    $b = array_merge($b, $formData);
}
$errs = $formErrors ?? [];
$uid  = 'bf';
?>
<div class="card card--narrow">
    <div class="card__header">
        <h3 class="card__title"><?= !empty($branch) ? 'Branch details' : 'New branch' ?></h3>
    </div>
    <div class="card__body">
        <form method="post" data-validate>
            <?= csrfField() ?>

            <?php require VIEWS_PATH . '/components/ui/error_summary.php'; ?>

            <?php require __DIR__ . '/_form_fields.php'; ?>

            <div class="form-actions">
                <a href="<?= APP_URL ?>/index.php?page=branches" class="btn btn--outline">Cancel</a>
                <button type="submit" class="btn btn--primary">
                    <i class="bi bi-check-lg" aria-hidden="true"></i>
                    <?= !empty($branch) ? 'Save changes' : 'Create branch' ?>
                </button>
            </div>
        </form>
    </div>
</div>
