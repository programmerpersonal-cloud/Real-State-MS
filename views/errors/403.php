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
<div class="card card--narrow">
    <div class="card__body">
        <?= uiEmptyState([
            'icon'  => 'bi-shield-lock',
            'title' => 'You do not have access to this',
            'desc'  => accessDeniedReason(),
            'actions' => [[
                'label' => $suggestion['label'],
                'icon'  => 'bi-arrow-right',
                'url'   => $suggestion['url'],
            ]],
        ]) ?>

        <p class="form-hint text-center"><?= sanitize($suggestion['hint']) ?></p>

        <?php if ($account): ?>
            <div class="deny-account">
                <div class="form-hint">
                    Signed in as <strong><?= sanitize($account['full_name'] ?: $account['email']) ?></strong>
                    · <?= sanitize(uiLabel((string) $account['role'])) ?>
                </div>
                <div class="form-hint">
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
