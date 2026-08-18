<?php
/**
 * The two steps that follow "Save Owner".
 *
 *   1. ownerAccessAskModal   — should this owner be able to sign in? Yes / No
 *   2. ownerAccessSetupModal — the login identity, then a password for it
 *
 * The owner already exists by the time these open, so answering No (or closing
 * the popup) costs nothing: the profile is saved either way, and access can be
 * granted later from the owner's page.
 *
 * Expects: $grantOwner (the owner just created), $accountMatch (users row|null)
 */
$adopting = !empty($accountMatch);
$loginId  = $adopting ? $accountMatch['email'] : ($grantOwner['email'] ?? '');
/** Shown so the administrator knows the sign-in name before it exists. */
$suggestedUsername = $adopting
    ? $accountMatch['username']
    : (new User())->suggestUsername((string) $loginId, (string) $grantOwner['full_name']);
?>

<!-- Step 1 — the question -->
<div class="modal" id="ownerAccessAskModal" data-modal data-modal-autoopen hidden>
    <div class="modal__backdrop" data-modal-close></div>

    <div class="modal__dialog" role="dialog" aria-modal="true" aria-labelledby="ownerAccessAskTitle" tabindex="-1"
         style="max-width:520px">
        <header class="modal__header">
            <div>
                <h3 class="modal__title" id="ownerAccessAskTitle"><i class="bi bi-check-circle-fill"></i> Owner Created</h3>
                <p class="modal__subtitle"><strong><?= sanitize($grantOwner['full_name']) ?></strong> is saved in the Owners module.</p>
            </div>
            <button type="button" class="modal__close" data-modal-close aria-label="Close"><i class="bi bi-x-lg"></i></button>
        </header>

        <div class="modal__body">
            <p class="prose">
                Should this owner be able to <strong>sign in</strong> to the portal —
                to see their properties, income and statements?
            </p>
            <ul class="promise-list mt-2" >
                <li>
                    <i class="bi bi-check-lg"></i>
                    <span><strong>Yes</strong> — an account is created with the role <strong>Owner</strong>, using the email below.</span>
                </li>
                <li>
                    <i class="bi bi-dash-lg"></i>
                    <span><strong>No</strong> — the owner stays a business record only. You can turn access on any time from their page.</span>
                </li>
            </ul>
        </div>

        <footer class="modal__footer">
            <?php /* Closes this popup and opens the credentials one — the modal
                     system takes both attributes on a single control. */ ?>
            <button type="button" class="btn btn--primary" data-modal-close data-modal-open="ownerAccessSetupModal">
                <i class="bi bi-key"></i> Yes, allow login
            </button>
            <button type="button" class="btn btn--outline" data-modal-close>No, profile only</button>
        </footer>
    </div>
</div>

<!-- Step 2 — the identity, then the password for it -->
<div class="modal" id="ownerAccessSetupModal" data-modal hidden>
    <div class="modal__backdrop" data-modal-close></div>

    <div class="modal__dialog" role="dialog" aria-modal="true" aria-labelledby="ownerAccessSetupTitle" tabindex="-1">
        <header class="modal__header">
            <div>
                <h3 class="modal__title" id="ownerAccessSetupTitle"><i class="bi bi-person-lock"></i> Create Login</h3>
                <p class="modal__subtitle">For <strong><?= sanitize($grantOwner['full_name']) ?></strong> — role <strong>Owner</strong>.</p>
            </div>
            <button type="button" class="modal__close" data-modal-close aria-label="Close"><i class="bi bi-x-lg"></i></button>
        </header>

        <form class="modal__form" method="POST" data-validate
              action="<?= APP_URL ?>/index.php?page=owners&amp;action=enable-login&amp;id=<?= (int) $grantOwner['id'] ?>">
            <?= csrfField() ?>
            <input type="hidden" name="return_to" value="list">

            <div class="modal__body">
                <?php if ($adopting): ?>
                    <div class="alert alert--info">
                        <i class="bi bi-info-circle-fill"></i>
                        <span>
                            An account already uses <strong><?= sanitize($accountMatch['email']) ?></strong>
                            (<?= sanitize($accountMatch['full_name']) ?>). It will be linked to this owner and set to the
                            Owner role — <strong>its password is not changed</strong>, so they keep signing in as they do today.
                        </span>
                    </div>
                    <input type="hidden" name="email" value="<?= sanitize($accountMatch['email']) ?>">
                <?php else: ?>
                    <h4 class="form-section">Login identity</h4>

                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label" for="oa-email">Email <span class="text-subtle">(this is the login)</span> *</label>
                            <input type="email" id="oa-email" name="email" class="form-control" required
                                   value="<?= sanitize($loginId) ?>" data-username-source>
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="oa-username">Username</label>
                            <input type="text" id="oa-username" class="form-control" readonly tabindex="-1"
                                   value="<?= sanitize($suggestedUsername) ?>" data-username-preview
                                   style="background:var(--surface-2);color:var(--text-muted)">
                            <div class="form-hint">Generated automatically. The owner signs in with either.</div>
                        </div>
                    </div>

                    <h4 class="form-section">Password</h4>

                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label" for="oa-password">Create Password <span class="req" aria-hidden="true">*</span></label>
                            <input type="password" id="oa-password" name="password" class="form-control" required
                                   autocomplete="new-password" minlength="<?= PASSWORD_MIN_LENGTH ?>" data-password>
                            <div class="form-hint">At least <?= PASSWORD_MIN_LENGTH ?> characters. Stored hashed — never in plain text.</div>
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="oa-password2">Confirm Password <span class="req" aria-hidden="true">*</span></label>
                            <input type="password" id="oa-password2" name="confirm_password" class="form-control" required
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
