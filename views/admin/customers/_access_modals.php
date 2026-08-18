<?php
/**
 * The two steps that follow "Save Customer".
 *
 *   1. customerAccessAskModal   — should this customer be able to sign in? Yes / No
 *   2. customerAccessSetupModal — the login identity, then a password for it
 *
 * The customer already exists by the time these open, so answering No (or
 * closing the popup) costs nothing: the profile is saved either way, and access
 * can be granted later from the customer's page.
 *
 * Three cases, and the popup says which one it is about to take:
 *   • no account carries this email       → a new one is created, role Customer
 *   • a *customer* account carries it     → that account is adopted, password untouched
 *   • a staff/owner account carries it    → refused, and it says why. Adopting it
 *                                           would demote that account, so the
 *                                           administrator is asked for another email.
 *
 * Expects: $grantCustomer (the customer just created), $accountMatch (users row|null)
 */
$matchRole = $accountMatch['role_name'] ?? '';
$adopting  = $accountMatch && $matchRole === ROLE_CUSTOMER;
$blocked   = $accountMatch && $matchRole !== ROLE_CUSTOMER;

$loginId = $adopting ? $accountMatch['email'] : ($blocked ? '' : ($grantCustomer['email'] ?? ''));
/** Shown so the administrator knows the sign-in name before it exists. */
$suggestedUsername = $adopting
    ? $accountMatch['username']
    : (new User())->suggestUsername((string) $loginId, (string) $grantCustomer['full_name']);

$typeLabel = ucfirst($grantCustomer['customer_type'] ?? 'customer');
?>

<!-- Step 1 — the question -->
<div class="modal" id="customerAccessAskModal" data-modal data-modal-autoopen hidden>
    <div class="modal__backdrop" data-modal-close></div>

    <div class="modal__dialog" role="dialog" aria-modal="true" aria-labelledby="customerAccessAskTitle" tabindex="-1"
         style="max-width:520px">
        <header class="modal__header">
            <div>
                <h3 class="modal__title" id="customerAccessAskTitle"><i class="bi bi-check-circle-fill"></i> Customer Created</h3>
                <p class="modal__subtitle">
                    <strong><?= sanitize($grantCustomer['full_name']) ?></strong> is saved in the Customers module
                    as <strong><?= sanitize($typeLabel) ?></strong>.
                </p>
            </div>
            <button type="button" class="modal__close" data-modal-close aria-label="Close"><i class="bi bi-x-lg"></i></button>
        </header>

        <div class="modal__body">
            <p class="prose">
                Should this customer be able to <strong>sign in</strong> to the portal —
                to see their lease, payments and requests?
            </p>
            <ul class="promise-list mt-2" >
                <li>
                    <i class="bi bi-check-lg"></i>
                    <span><strong>Yes</strong> — one account is created with the role <strong>Customer</strong>, linked to this record.</span>
                </li>
                <li>
                    <i class="bi bi-dash-lg"></i>
                    <span><strong>No</strong> — the customer stays a business record only. Most tenants never sign in, and you can turn access on any time from their page.</span>
                </li>
            </ul>
        </div>

        <footer class="modal__footer">
            <?php /* Closes this popup and opens the credentials one — the modal
                     system takes both attributes on a single control. */ ?>
            <button type="button" class="btn btn--primary" data-modal-close data-modal-open="customerAccessSetupModal">
                <i class="bi bi-key"></i> Yes, allow login
            </button>
            <button type="button" class="btn btn--outline" data-modal-close>No, profile only</button>
        </footer>
    </div>
</div>

<!-- Step 2 — the identity, then the password for it -->
<div class="modal" id="customerAccessSetupModal" data-modal hidden>
    <div class="modal__backdrop" data-modal-close></div>

    <div class="modal__dialog" role="dialog" aria-modal="true" aria-labelledby="customerAccessSetupTitle" tabindex="-1">
        <header class="modal__header">
            <div>
                <h3 class="modal__title" id="customerAccessSetupTitle"><i class="bi bi-person-lock"></i> Create Login</h3>
                <p class="modal__subtitle">For <strong><?= sanitize($grantCustomer['full_name']) ?></strong> — role <strong>Customer</strong>.</p>
            </div>
            <button type="button" class="modal__close" data-modal-close aria-label="Close"><i class="bi bi-x-lg"></i></button>
        </header>

        <form class="modal__form" method="POST" data-validate
              action="<?= APP_URL ?>/index.php?page=customers&amp;action=enable-login&amp;id=<?= (int) $grantCustomer['id'] ?>">
            <?= csrfField() ?>
            <input type="hidden" name="return_to" value="list">

            <div class="modal__body">
                <?php if ($adopting): ?>
                    <div class="alert alert--info">
                        <i class="bi bi-info-circle-fill"></i>
                        <span>
                            A customer account already uses <strong><?= sanitize($accountMatch['email']) ?></strong>
                            (<?= sanitize($accountMatch['full_name']) ?>). It will be linked to this customer —
                            <strong>its password is not changed</strong>, so they keep signing in as they do today.
                        </span>
                    </div>
                    <input type="hidden" name="email" value="<?= sanitize($accountMatch['email']) ?>">
                <?php else: ?>
                    <?php if ($blocked): ?>
                        <div class="alert alert--warning">
                            <i class="bi bi-exclamation-triangle-fill"></i>
                            <span>
                                <strong><?= sanitize($grantCustomer['email']) ?></strong> already belongs to an account
                                with the <strong><?= sanitize($accountMatch['role_display'] ?: $matchRole) ?></strong> role
                                (<?= sanitize($accountMatch['full_name']) ?>). Reusing it would take that access away,
                                so it is not offered here — enter a different email for this customer's login, or
                                change that account's role deliberately under Users &amp; Roles.
                            </span>
                        </div>
                    <?php endif ?>

                    <h4 class="form-section">Login identity</h4>

                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label" for="ca-email">Email <span class="text-subtle">(this is the login)</span> *</label>
                            <input type="email" id="ca-email" name="email" class="form-control" required
                                   value="<?= sanitize($loginId) ?>" data-username-source>
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="ca-username">Username</label>
                            <input type="text" id="ca-username" class="form-control" readonly tabindex="-1"
                                   value="<?= sanitize($suggestedUsername) ?>" data-username-preview
                                   style="background:var(--surface-2);color:var(--text-muted)">
                            <div class="form-hint">Generated automatically. The customer signs in with either.</div>
                        </div>
                    </div>

                    <h4 class="form-section">Password</h4>

                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label" for="ca-password">Create Password *</label>
                            <input type="password" id="ca-password" name="password" class="form-control" required
                                   autocomplete="new-password" minlength="<?= PASSWORD_MIN_LENGTH ?>" data-password>
                            <div class="form-hint">At least <?= PASSWORD_MIN_LENGTH ?> characters. Stored hashed — never in plain text.</div>
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="ca-password2">Confirm Password *</label>
                            <input type="password" id="ca-password2" name="confirm_password" class="form-control" required
                                   autocomplete="new-password" minlength="<?= PASSWORD_MIN_LENGTH ?>" data-password-confirm>
                            <div class="form-error" data-password-mismatch hidden>
                                <i class="bi bi-exclamation-circle"></i> The two passwords do not match.
                            </div>
                        </div>
                    </div>
                <?php endif ?>
            </div>

            <footer class="modal__footer">
                <button type="submit" class="btn btn--primary">
                    <i class="bi bi-check-lg"></i> <?= $adopting ? 'Link Account' : 'Create Login' ?>
                </button>
                <button type="button" class="btn btn--outline" data-modal-close>Not now</button>
            </footer>
        </form>
    </div>
</div>
