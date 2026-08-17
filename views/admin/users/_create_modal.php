<?php
/**
 * Quick "Add User" popup.
 *
 * Posts to the same create action as the full page, carrying
 * return_to=modal so a rejected entry comes back here intact. The password
 * is never carried back — it is not kept in the session.
 *
 * Expects: $roles, $branches, $fd, $openCreateModal
 */
$uid    = 'um';
$u      = $fd ?? [];
$isEdit = false;
?>
<div class="modal" id="userCreateModal" data-modal <?= !empty($openCreateModal) ? 'data-modal-autoopen' : '' ?> hidden>
    <div class="modal__backdrop" data-modal-close></div>

    <div class="modal__dialog" role="dialog" aria-modal="true"
         aria-labelledby="userCreateTitle" tabindex="-1">
        <header class="modal__header">
            <div>
                <h3 class="modal__title" id="userCreateTitle"><i class="bi bi-person-gear"></i> Add User</h3>
                <p class="modal__subtitle">The role decides what this account can reach.</p>
            </div>
            <button type="button" class="modal__close" data-modal-close aria-label="Close">
                <i class="bi bi-x-lg"></i>
            </button>
        </header>

        <form class="modal__form" method="post" data-validate
              action="<?= APP_URL ?>/index.php?page=users&amp;action=create">
            <?= csrfField() ?>
            <input type="hidden" name="return_to" value="modal">

            <div class="modal__body">
                <?php require __DIR__ . '/_form_fields.php'; ?>
            </div>

            <footer class="modal__footer">
                <button type="submit" class="btn btn--primary"><i class="bi bi-check-lg"></i> Create User</button>
                <button type="button" class="btn btn--outline" data-modal-close>Cancel</button>
                <span class="modal__footer-note">Need the full form? <a href="<?= APP_URL ?>/index.php?page=users&amp;action=create">Open the page</a></span>
            </footer>
        </form>
    </div>
</div>
