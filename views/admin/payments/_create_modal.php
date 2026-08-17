<?php
/**
 * Quick "Record Payment" popup.
 *
 * Posts to the same create action as the full page, carrying
 * return_to=modal so a rejected entry comes back here intact.
 *
 * Expects: $leases, $fd, $openCreateModal
 * Optional: $modalHost  name of the screen hosting this popup, when it is not
 *                       the payments list — so a reject comes back there
 */
$uid    = 'pym';
$preset = null;   // the schedule shortcut is a full-page entry point
?>
<div class="modal" id="paymentCreateModal" data-modal <?= !empty($openCreateModal) ? 'data-modal-autoopen' : '' ?> hidden>
    <div class="modal__backdrop" data-modal-close></div>

    <div class="modal__dialog" role="dialog" aria-modal="true"
         aria-labelledby="paymentCreateTitle" tabindex="-1">
        <header class="modal__header">
            <div>
                <h3 class="modal__title" id="paymentCreateTitle"><i class="bi bi-cash-coin"></i> Record Payment</h3>
                <p class="modal__subtitle">A receipt is generated as soon as the payment is saved.</p>
            </div>
            <button type="button" class="modal__close" data-modal-close aria-label="Close">
                <i class="bi bi-x-lg"></i>
            </button>
        </header>

        <form class="modal__form" method="post" data-validate
              action="<?= APP_URL ?>/index.php?page=payments&amp;action=create">
            <?= csrfField() ?>
            <input type="hidden" name="return_to" value="<?= modalReturnTo($modalHost ?? null) ?>">

            <div class="modal__body">
                <?php require __DIR__ . '/_form_fields.php'; ?>
            </div>

            <footer class="modal__footer">
                <button type="submit" class="btn btn--primary"><i class="bi bi-check-lg"></i> Record Payment</button>
                <button type="button" class="btn btn--outline" data-modal-close>Cancel</button>
                <span class="modal__footer-note">Need the full form? <a href="<?= APP_URL ?>/index.php?page=payments&amp;action=create">Open the page</a></span>
            </footer>
        </form>
    </div>
</div>
