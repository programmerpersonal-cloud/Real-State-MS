<?php
/**
 * Quick "Add review" popup.
 *
 * Posts to the same save action as the full page, carrying return_to=modal
 * so a rejected entry comes back here intact.
 *
 * Expects: $fd, $openCreateModal
 */
$uid = 'tm';
$t   = $fd ?? [];
?>
<div class="modal" id="testimonialCreateModal" data-modal <?= !empty($openCreateModal) ? 'data-modal-autoopen' : '' ?> hidden>
    <div class="modal__backdrop" data-modal-close></div>

    <div class="modal__dialog" role="dialog" aria-modal="true"
         aria-labelledby="testimonialCreateTitle" tabindex="-1">
        <header class="modal__header">
            <div>
                <h3 class="modal__title" id="testimonialCreateTitle"><i class="bi bi-chat-quote"></i> Add a review</h3>
                <p class="modal__subtitle">Record what the customer actually said — nothing publishes until you approve it.</p>
            </div>
            <button type="button" class="modal__close" data-modal-close aria-label="Close">
                <i class="bi bi-x-lg"></i>
            </button>
        </header>

        <form class="modal__form" method="POST" data-validate
              action="<?= APP_URL ?>/index.php?page=testimonials&amp;action=save">
            <?= csrfField() ?>
            <input type="hidden" name="id" value="0">
            <input type="hidden" name="return_to" value="modal">

            <div class="modal__body">
                <?php require __DIR__ . '/_form_fields.php'; ?>
            </div>

            <footer class="modal__footer">
                <button type="submit" class="btn btn--primary"><i class="bi bi-check-lg"></i> Add review</button>
                <button type="button" class="btn btn--outline" data-modal-close>Cancel</button>
                <span class="modal__footer-note">Need the full form? <a href="<?= APP_URL ?>/index.php?page=testimonials&amp;action=form">Open the page</a></span>
            </footer>
        </form>
    </div>
</div>
