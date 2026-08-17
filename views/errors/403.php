<?php
/**
 * 403 — Access restricted
 *
 * Rendered by denyAccess() for every refusal, at both levels: "your role does
 * not include this module" and "this record is not yours". The two are not
 * distinguished on screen on purpose — telling someone their colleague's
 * record exists but is off-limits is itself a disclosure — so the page
 * explains what the account *does* cover and points at it.
 *
 * Optional: $deniedPermission  the permission that was missing, for support
 *
 * The signed-in account is named because the commonest innocent cause of a
 * 403 is being logged in as the wrong one, and nothing on the page tells you
 * that otherwise.
 */
$suggestion = accessDeniedSuggestion();
$account    = getCurrentUser();
?>
<div class="card" style="max-width:560px;margin:0 auto">
    <div class="card__body">
        <div class="empty-state">
            <div class="empty-state__icon"><i class="bi bi-shield-lock"></i></div>
            <div class="empty-state__title">You do not have access to this</div>
            <p class="empty-state__desc"><?= sanitize(accessDeniedReason()) ?></p>

            <div style="margin-top:20px">
                <a href="<?= sanitize($suggestion['url']) ?>" class="btn btn--primary">
                    <i class="bi bi-arrow-right"></i> <?= sanitize($suggestion['label']) ?>
                </a>
                <div class="form-hint" style="margin-top:8px"><?= sanitize($suggestion['hint']) ?></div>
            </div>
        </div>

        <?php if ($account): ?>
            <div style="border-top:1px solid var(--border);margin-top:8px;padding-top:14px;text-align:center">
                <div class="form-hint">
                    Signed in as <strong><?= sanitize($account['full_name'] ?: $account['email']) ?></strong>
                    · <?= sanitize(ucfirst($account['role'])) ?>
                </div>
                <div class="form-hint" style="margin-top:6px">
                    If this should be part of your job, ask an administrator to update your access<?php
                        // The permission name is the one thing that makes a support
                        // request actionable instead of "a page said no".
                        if (!empty($deniedPermission)): ?> —
                        quote <code><?= sanitize($deniedPermission) ?></code><?php endif ?>.
                </div>
            </div>
        <?php endif ?>
    </div>
</div>
