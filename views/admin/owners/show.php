<?php
/**
 * Owner — detail.
 *
 * The account panel reads the linked users row (owners.user_id), so what it
 * reports is the same record the Users & Roles page lists — there is no second
 * copy of this person anywhere.
 */
$o = $owner;
$hasAccount = !empty($o['account_id']);
// Granting or revoking a login is an administrator action; editing the profile
// is staff work. Both read the matrix rather than naming roles, so an owner
// reading their own page is offered neither.
$canManage  = can('owners.enable-login');
$canEdit    = can('owners.edit');
?>
<div class="mini-stats">
    <div class="mini-stat"><div class="mini-stat__label">Properties</div><div class="mini-stat__value"><?= count($properties) ?></div></div>
    <div class="mini-stat"><div class="mini-stat__label">Commission Rate</div><div class="mini-stat__value"><?= $o['commission_rate'] ?>%</div></div>
    <div class="mini-stat"><div class="mini-stat__label">Total Income</div><div class="mini-stat__value"><?= formatCurrency((float)($totalIncome ?? 0)) ?></div></div>
</div>
<?php if ($canEdit): ?>
<div style="display:flex;gap:8px;margin-bottom:14px">
    <a href="<?= APP_URL ?>/index.php?page=owners&action=edit&id=<?= $o['id'] ?>" class="btn btn--outline btn--sm"><i class="bi bi-pencil"></i> Edit</a>
</div>
<?php endif ?>
<div style="display:grid;grid-template-columns:1fr 2fr;gap:20px">
    <div class="card">
        <div class="card__header"><h3 class="card__title">Owner Details</h3></div>
        <div class="card__body">
            <table style="width:100%;font-size:.875rem">
                <tr><td class="text-muted" style="padding:8px 0">Phone</td><td><?= sanitize($o['phone']) ?></td></tr>
                <tr><td class="text-muted" style="padding:8px 0">Email</td><td><?= sanitize($o['email'] ?: '—') ?></td></tr>
                <tr><td class="text-muted" style="padding:8px 0">National ID</td><td><?= sanitize($o['national_id'] ?: '—') ?></td></tr>
                <tr><td class="text-muted" style="padding:8px 0">Address</td><td><?= sanitize($o['address'] ?: '—') ?></td></tr>
                <tr><td class="text-muted" style="padding:8px 0">Bank</td><td><?= sanitize($o['bank_name'] ?: '—') ?></td></tr>
                <tr><td class="text-muted" style="padding:8px 0">Account</td><td><?= sanitize($o['bank_account'] ?: '—') ?></td></tr>
                <tr><td class="text-muted" style="padding:8px 0">Commission</td><td><?= $o['commission_rate'] ?>%</td></tr>
                <tr><td class="text-muted" style="padding:8px 0">Since</td><td><?= formatDate($o['created_at']) ?></td></tr>
            </table>
        </div>
    </div>

    <div class="card" style="grid-column:1">
        <div class="card__header"><h3 class="card__title">Account Information</h3></div>
        <div class="card__body">
            <table style="width:100%;font-size:.875rem">
                <tr>
                    <td class="text-muted" style="padding:8px 0">Login Access</td>
                    <td>
                        <?php if (!$hasAccount): ?>
                            <span class="badge badge--muted">Disabled — no account</span>
                        <?php elseif ($o['account_active']): ?>
                            <span class="badge badge--success">Enabled</span>
                        <?php else: ?>
                            <span class="badge badge--warning">Disabled</span>
                        <?php endif ?>
                    </td>
                </tr>
                <?php if ($hasAccount): ?>
                    <tr><td class="text-muted" style="padding:8px 0">Signs in with</td><td><?= sanitize($o['account_email']) ?></td></tr>
                    <tr><td class="text-muted" style="padding:8px 0">Username</td><td><code><?= sanitize($o['account_username']) ?></code></td></tr>
                    <tr><td class="text-muted" style="padding:8px 0">Role</td><td><span class="badge badge--primary"><?= sanitize($o['account_role_display'] ?? 'Owner') ?></span></td></tr>
                    <tr><td class="text-muted" style="padding:8px 0">Last login</td><td><?= $o['account_last_login'] ? formatDateTime($o['account_last_login']) : 'Never' ?></td></tr>
                    <tr>
                        <td class="text-muted" style="padding:8px 0">User record</td>
                        <td>
                            <?php if ($canManage): ?>
                                <a href="<?= APP_URL ?>/index.php?page=users&action=edit&id=<?= (int) $o['account_id'] ?>">#<?= (int) $o['account_id'] ?> in Users &amp; Roles</a>
                            <?php else: ?>
                                #<?= (int) $o['account_id'] ?>
                            <?php endif ?>
                        </td>
                    </tr>
                <?php endif ?>
            </table>

            <?php if ($canManage): ?>
                <?php if (!$hasAccount): ?>
                    <p class="form-hint" style="margin:10px 0 12px">
                        This owner is a business record only. Giving them access creates one account
                        with the role <strong>Owner</strong> — or adopts the existing account that
                        already carries their email.
                    </p>
                    <button type="button" class="btn btn--primary btn--sm" data-modal-open="ownerLoginModal">
                        <i class="bi bi-key"></i> Enable Login Access
                    </button>
                <?php elseif ($o['account_active']): ?>
                    <form method="POST" action="<?= APP_URL ?>/index.php?page=owners&amp;action=disable-login&amp;id=<?= (int) $o['id'] ?>"
                          onsubmit="return confirm('Disable login for this owner? Their profile, properties and payment history are kept.')">
                        <?= csrfField() ?>
                        <button type="submit" class="btn btn--outline btn--sm"><i class="bi bi-lock"></i> Disable Login Access</button>
                    </form>
                <?php else: ?>
                    <form method="POST" action="<?= APP_URL ?>/index.php?page=owners&amp;action=enable-login&amp;id=<?= (int) $o['id'] ?>">
                        <?= csrfField() ?>
                        <button type="submit" class="btn btn--primary btn--sm"><i class="bi bi-unlock"></i> Re-enable Login Access</button>
                    </form>
                <?php endif ?>
            <?php endif ?>
        </div>
    </div>
    <div class="card">
        <div class="card__header"><h3 class="card__title">Owned Properties (<?= count($properties) ?>)</h3></div>
        <div class="card__body" style="padding:0">
            <?php if (empty($properties)): ?>
                <div class="empty-state"><div class="empty-state__desc">No properties linked to this owner.</div></div>
            <?php else: ?>
            <div class="table-wrap"><table class="table">
                <thead><tr><th>Code</th><th>Title</th><th>Type</th><th>Rent/Price</th><th>Status</th></tr></thead>
                <tbody>
                <?php foreach ($properties as $p): ?>
                <tr>
                    <td><?= sanitize($p['property_code']) ?></td>
                    <td><a href="<?= APP_URL ?>/index.php?page=properties&action=show&id=<?= $p['id'] ?>"><?= sanitize($p['title']) ?></a></td>
                    <td><?= ucfirst($p['property_type']) ?></td>
                    <td><?= $p['rent_amount'] ? formatCurrency($p['rent_amount']).'/mo' : ($p['price'] ? formatCurrency($p['price']) : '—') ?></td>
                    <td><span class="badge <?= getStatusBadgeClass($p['status']) ?>"><?= ucfirst($p['status']) ?></span></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php if ($canManage && !$hasAccount): ?>
    <?php require __DIR__ . '/_login_modal.php'; ?>
<?php endif ?>
