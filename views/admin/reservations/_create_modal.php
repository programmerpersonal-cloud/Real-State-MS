<?php
/**
 * Quick "New Reservation" popup.
 *
 * Posts to the same create action as the full page, carrying
 * return_to=modal so a rejected entry comes back here with the selection
 * intact instead of dumping the user on another screen.
 *
 * Expects: $properties, $customers, $fd, $openCreateModal
 */
$uid = 'rm';
?>
<div class="modal" id="reservationCreateModal" data-modal <?= !empty($openCreateModal) ? 'data-modal-autoopen' : '' ?> hidden>
    <div class="modal__backdrop" data-modal-close></div>

    <div class="modal__dialog" role="dialog" aria-modal="true"
         aria-labelledby="reservationCreateTitle" tabindex="-1">
        <header class="modal__header">
            <div>
                <h3 class="modal__title" id="reservationCreateTitle"><i class="bi bi-calendar-plus"></i> New Reservation</h3>
                <p class="modal__subtitle">Holds the property until the expiry date, or until it is confirmed.</p>
            </div>
            <button type="button" class="modal__close" data-modal-close aria-label="Close">
                <i class="bi bi-x-lg"></i>
            </button>
        </header>

        <form class="modal__form" method="post" data-validate
              action="<?= APP_URL ?>/index.php?page=reservations&amp;action=create">
            <?= csrfField() ?>
            <input type="hidden" name="return_to" value="modal">

            <div class="modal__body">
                <?php require __DIR__ . '/_form_fields.php'; ?>
            </div>

            <footer class="modal__footer">
                <button type="submit" class="btn btn--primary"><i class="bi bi-check-lg"></i> Reserve</button>
                <button type="button" class="btn btn--outline" data-modal-close>Cancel</button>
                <span class="modal__footer-note">Need the full form? <a href="<?= APP_URL ?>/index.php?page=reservations&amp;action=create">Open the page</a></span>
            </footer>
        </form>
    </div>
</div>
