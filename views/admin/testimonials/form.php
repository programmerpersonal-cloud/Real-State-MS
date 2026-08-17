<?php
/**
 * Testimonials — Add / Edit (admin)
 */
$t    = $testimonial ?? ($formData ?? []);
$id   = (int) ($testimonial['id'] ?? 0);
$uid  = 'tf';
?>

<form method="POST" action="<?= APP_URL ?>/index.php?page=testimonials&action=save">
    <?= csrfField() ?>
    <input type="hidden" name="id" value="<?= $id ?>">

    <div class="card">
        <div class="card__header">
            <h3 class="card__title"><?= $id ? 'Edit review' : 'Add a review' ?></h3>
            <p class="card__subtitle">
                Record what the customer actually said. Publishing invented reviews alongside
                star-rating markup risks a search penalty and misleads buyers.
            </p>
        </div>

        <div class="card__body">
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
