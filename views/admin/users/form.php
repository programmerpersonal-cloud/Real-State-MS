<?php
/**
 * User — Form (create / edit)
 */
$u = $user ?? ($formData ?? []);
// A rejected edit hands its entry back through the session; without this the
// form would redraw from the database and quietly discard what was typed.
if (!empty($user) && !empty($formData)) {
    $u = array_merge($u, $formData);
}
$isEdit = !empty($user);
$errs   = $formErrors ?? [];
$uid    = 'uf';
?>
<div class="card card--narrow">
    <div class="card__header">
        <h3 class="card__title"><?= $isEdit ? 'Account details' : 'New account' ?></h3>
        <span class="text-subtle">
            <?= $isEdit ? 'Changes take effect at their next request' : 'They can sign in as soon as this is saved' ?>
        </span>
    </div>
    <div class="card__body">
        <form method="post" data-validate>
            <?= csrfField() ?>

            <?php require VIEWS_PATH . '/components/ui/error_summary.php'; ?>

            <?php require __DIR__ . '/_form_fields.php'; ?>

            <div class="form-actions">
                <a href="<?= APP_URL ?>/index.php?page=users" class="btn btn--outline">Cancel</a>
                <button type="submit" class="btn btn--primary">
                    <i class="bi bi-check-lg" aria-hidden="true"></i>
                    <?= $isEdit ? 'Save changes' : 'Create account' ?>
                </button>
            </div>
        </form>
    </div>
</div>
