<?php
/**
 * Testimonials — Add / Edit (admin)
 */
$t = $testimonial ?? ($formData ?? []);
// A rejected edit hands its entry back through the session; without this the
// form would redraw from the database and discard what was typed.
if (!empty($testimonial) && !empty($formData)) {
    $t = array_merge($t, $formData);
}
$id   = (int) ($testimonial['id'] ?? 0);
$errs = $formErrors ?? [];
$uid  = 'tf';
?>

<form method="POST" action="<?= APP_URL ?>/index.php?page=testimonials&action=save" data-validate>
    <?= csrfField() ?>
    <input type="hidden" name="id" value="<?= $id ?>">

    <div class="card card--narrow">
        <div class="card__header">
            <h3 class="card__title"><?= $id ? 'Edit review' : 'Add a review' ?></h3>
            <p class="card__subtitle">
                Record what the customer actually said. Publishing invented reviews alongside
                star-rating markup risks a search penalty and misleads buyers.
            </p>
        </div>

        <div class="card__body">
            <?php require VIEWS_PATH . '/components/ui/error_summary.php'; ?>
            <?php require __DIR__ . '/_form_fields.php'; ?>
        </div>

        <div class="card__footer">
            <div class="form-actions">
                <a class="btn btn--ghost" href="<?= APP_URL ?>/index.php?page=testimonials">Cancel</a>
                <button class="btn btn--primary" type="submit">
                    <i class="bi bi-check-lg"></i> <?= $id ? 'Save changes' : 'Add review' ?>
                </button>
            </div>
        </div>
    </div>
</form>
