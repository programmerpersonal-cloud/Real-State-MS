<?php
/**
 * "Enable login access" popup for a customer who has no account yet.
 *
 * The same three cases as _access_modals.php, reached from the customer's own
 * page rather than straight after creation.
 *
 * Expects: $customer, $accountMatch (users row or null)
 */
$matchRole = $accountMatch['role_name'] ?? '';
$adopting  = $accountMatch && $matchRole === ROLE_CUSTOMER;
$blocked   = $accountMatch && $matchRole !== ROLE_CUSTOMER;
?>
<div class="modal" id="customerLoginModal" data-modal hidden>
    <div class="modal__backdrop" data-modal-close></div>

    <div class="modal__dialog" role="dialog" aria-modal="true" aria-labelledby="customerLoginTitle" tabindex="-1">
        <header class="modal__header">
            <div>
                <h3 class="modal__title" id="customerLoginTitle"><i class="bi bi-key"></i> Enable Login Access</h3>
                <p class="modal__subtitle">For <strong><?= sanitize($customer['full_name']) ?></strong> — role will be <strong>Customer</strong>.</p>
            </div>
            <button type="button" class="modal__close" data-modal-close aria-label="Close"><i class="bi bi-x-lg"></i></button>
        </header>

        <form class="modal__form" method="POST" data-validate
              action="<?= APP_URL ?>/index.php?page=customers&amp;action=enable-login&amp;id=<?= (int) $customer['id'] ?>">
            <?= csrfField() ?>

            <div class="modal__body">
                <?php if ($adopting): ?>
                    <div class="alert alert--info">
                        <i class="bi bi-info-circle-fill"></i>
                        <span>
                            A customer account already uses <strong><?= sanitize($accountMatch['email']) ?></strong>
                            (<?= sanitize($accountMatch['full_name']) ?>). It will be linked to this customer.
                            <strong>Its password is not changed</strong> — they keep signing in as they do today.
                        </span>
                    </div>
                    <input type="hidden" name="email" value="<?= sanitize($accountMatch['email']) ?>">
                <?php else: ?>
                    <?php if ($blocked): ?>
                        <div class="alert alert--warning">
                            <i class="bi bi-exclamation-triangle-fill"></i>
                            <span>
                                <strong><?= sanitize($customer['email']) ?></strong> already belongs to an account
                                with the <strong><?= sanitize($accountMatch['role_display'] ?: $matchRole) ?></strong> role
                                (<?= sanitize($accountMatch['full_name']) ?>). Reusing it would take that access away,
                                so it is not offered here — enter a different email below, or change that account's
                                role deliberately under Users &amp; Roles.
                            </span>
                        </div>
                    <?php endif ?>

                    <div class="form-group">
                        <label class="form-label" for="cl-email">Email / login *</label>
                        <input type="email" id="cl-email" name="email" class="form-control" required
                               value="<?= sanitize($blocked ? '' : ($customer['email'] ?? '')) ?>">
                        <div class="form-hint">This is what the customer types to sign in.</div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label" for="cl-password">Password *</label>
                            <input type="password" id="cl-password" name="password" class="form-control"
                                   autocomplete="new-password" required minlength="<?= PASSWORD_MIN_LENGTH ?>">
                            <div class="form-hint">At least <?= PASSWORD_MIN_LENGTH ?> characters. Stored hashed.</div>
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="cl-password2">Confirm Password *</label>
                            <input type="password" id="cl-password2" name="confirm_password" class="form-control"
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
