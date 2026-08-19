<?php
/**
 * Reservations — the full create page.
 *
 * The same fields as the quick-add popup on the list, for the times a
 * reservation is being set up carefully rather than in passing.
 */
$fd   = $formData ?? [];
$errs = $formErrors ?? [];
$uid  = 'rc';
?>
<div class="card card--narrow">
    <div class="card__header">
        <h2 class="card__title">Reservation details</h2>
        <span class="text-subtle">Holds the property off the market</span>
    </div>
    <div class="card__body">
        <form method="post" data-validate>
            <?= csrfField() ?>

            <?php require VIEWS_PATH . '/components/ui/error_summary.php'; ?>

            <?php require __DIR__ . '/_form_fields.php'; ?>

            <div class="form-actions">
                <a href="<?= APP_URL ?>/index.php?page=reservations" class="btn btn--outline">Cancel</a>
                <button type="submit" class="btn btn--primary">
                    <i class="bi bi-check-lg" aria-hidden="true"></i> Reserve property
                </button>
            </div>
        </form>
    </div>
</div>
