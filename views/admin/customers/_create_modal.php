<?php
/**
 * Quick "Add Customer" popup.
 *
 * Posts to the same create action as the full page, carrying
 * return_to=modal so a rejected entry comes back here intact.
 *
 * Expects: $fd, $formErrors, $openCreateModal
 * Optional: $modalHost  name of the screen hosting this popup, when it is not
 *                       the customers list — so a reject comes back there
 */
$uid = 'cm';
$showAccount = true;   // shows the "you will be asked next" note
?>
<div class="modal" id="customerCreateModal" data-modal <?= !empty($openCreateModal) ? 'data-modal-autoopen' : '' ?> hidden>
    <div class="modal__backdrop" data-modal-close></div>

    <div class="modal__dialog modal__dialog--lg" role="dialog" aria-modal="true"
         aria-labelledby="customerCreateTitle" tabindex="-1">
        <header class="modal__header">
            <div>
                <h3 class="modal__title" id="customerCreateTitle"><i class="bi bi-person-plus"></i> Add Customer</h3>
                <p class="modal__subtitle">Name and phone are enough to start — the rest can follow.</p>
            </div>
            <button type="button" class="modal__close" data-modal-close aria-label="Close">
                <i class="bi bi-x-lg"></i>
            </button>
        </header>

        <form class="modal__form" method="POST" enctype="multipart/form-data" data-validate
              action="<?= APP_URL ?>/index.php?page=customers&amp;action=create">
            <?= csrfField() ?>
            <input type="hidden" name="return_to" value="<?= modalReturnTo($modalHost ?? null) ?>">

            <div class="modal__body">
                <?php require __DIR__ . '/_form_fields.php'; ?>
            </div>

            <footer class="modal__footer">
                <button type="submit" class="btn btn--primary"><i class="bi bi-check-lg"></i> Create Customer</button>
                <button type="button" class="btn btn--outline" data-modal-close>Cancel</button>
                <span class="modal__footer-note">Need the full form? <a href="<?= APP_URL ?>/index.php?page=customers&amp;action=create">Open the page</a></span>
            </footer>
        </form>
    </div>
</div>
