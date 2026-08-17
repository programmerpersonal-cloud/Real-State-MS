<?php
/**
 * Quick "New Sale" popup.
 *
 * Posts to the same create action as the full page, carrying
 * return_to=modal so a rejected entry comes back here intact.
 *
 * Expects: $properties, $customers, $agents, $fd, $openCreateModal
 */
$uid = 'sm';
?>
<div class="modal" id="saleCreateModal" data-modal <?= !empty($openCreateModal) ? 'data-modal-autoopen' : '' ?> hidden>
    <div class="modal__backdrop" data-modal-close></div>

    <div class="modal__dialog modal__dialog--lg" role="dialog" aria-modal="true"
         aria-labelledby="saleCreateTitle" tabindex="-1">
        <header class="modal__header">
            <div>
                <h3 class="modal__title" id="saleCreateTitle"><i class="bi bi-cart-check"></i> New Sale</h3>
                <p class="modal__subtitle">Tax and commission follow the rates in Settings → Financial.</p>
            </div>
            <button type="button" class="modal__close" data-modal-close aria-label="Close">
                <i class="bi bi-x-lg"></i>
            </button>
        </header>

        <form class="modal__form" method="post" data-validate
              action="<?= APP_URL ?>/index.php?page=sales&amp;action=create">
            <?= csrfField() ?>
            <input type="hidden" name="return_to" value="modal">

            <div class="modal__body">
                <?php require __DIR__ . '/_form_fields.php'; ?>
            </div>

            <footer class="modal__footer">
                <button type="submit" class="btn btn--primary"><i class="bi bi-check-lg"></i> Save Sale</button>
                <button type="button" class="btn btn--outline" data-modal-close>Cancel</button>
                <span class="modal__footer-note">Need the full form? <a href="<?= APP_URL ?>/index.php?page=sales&amp;action=create">Open the page</a></span>
            </footer>
        </form>
    </div>
</div>
