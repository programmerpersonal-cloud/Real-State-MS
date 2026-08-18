<?php
/**
 * "Enable login access" popup for an owner who has no account yet.
 *
 * Two paths, and the form says which one it is about to take:
 *   • no account carries this email  → a new one is created, role Owner
 *   • an account already carries it  → that account is adopted, password untouched
 *
 * Expects: $owner, $accountMatch (users row or null)
 */
$adopting = !empty($accountMatch);
?>
<div class="modal" id="ownerLoginModal" data-modal hidden>
    <div class="modal__backdrop" data-modal-close></div>

    <div class="modal__dialog" role="dialog" aria-modal="true" aria-labelledby="ownerLoginTitle" tabindex="-1">
        <header class="modal__header">
            <div>
                <h3 class="modal__title" id="ownerLoginTitle"><i class="bi bi-key"></i> Enable Login Access</h3>
                <p class="modal__subtitle">For <strong><?= sanitize($owner['full_name']) ?></strong> — role will be <strong>Owner</strong>.</p>
            </div>
            <button type="button" class="modal__close" data-modal-close aria-label="Close"><i class="bi bi-x-lg"></i></button>
        </header>

        <form class="modal__form" method="POST" data-validate
              action="<?= APP_URL ?>/index.php?page=owners&amp;action=enable-login&amp;id=<?= (int) $owner['id'] ?>">
            <?= csrfField() ?>

            <div class="modal__body">
                <?php if ($adopting): ?>
                    <div class="alert alert--info">
                        <i class="bi bi-info-circle-fill"></i>
                        <span>
                            An account already uses <strong><?= sanitize($accountMatch['email']) ?></strong>
                            (<?= sanitize($accountMatch['full_name']) ?>). It will be linked to this owner and set to
                            the Owner role. <strong>Its password is not changed</strong> — they keep signing in as they do today.
                        </span>
                    </div>
                    <input type="hidden" name="email" value="<?= sanitize($accountMatch['email']) ?>">
                <?php else: ?>
                    <div class="form-group">
                        <label class="form-label" for="ol-email">Email / login <span class="req" aria-hidden="true">*</span></label>
                        <input type="email" id="ol-email" name="email" class="form-control" required
                               value="<?= sanitize($owner['email'] ?? '') ?>">
                        <div class="form-hint">This is what the owner types to sign in.</div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label" for="ol-password">Password <span class="req" aria-hidden="true">*</span></label>
                            <input type="password" id="ol-password" name="password" class="form-control"
                                   autocomplete="new-password" required minlength="<?= PASSWORD_MIN_LENGTH ?>">
                            <div class="form-hint">At least <?= PASSWORD_MIN_LENGTH ?> characters. Stored hashed.</div>
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="ol-password2">Confirm Password <span class="req" aria-hidden="true">*</span></label>
                            <input type="password" id="ol-password2" name="confirm_password" class="form-control"
                                   autocomplete="new-password" required minlength="<?= PASSWORD_MIN_LENGTH ?>">
                        </div>
                    </div>
                <?php endif ?>
            </div>

            <footer class="modal__footer">
                <button type="submit" class="btn btn--primary">
                    <i class="bi bi-check-lg"></i> <?= $adopting ? 'Link Account' : 'Enable Login' ?>
                </button>
                <button type="button" class="btn btn--outline" data-modal-close>Cancel</button>
            </footer>
        </form>
    </div>
</div>
