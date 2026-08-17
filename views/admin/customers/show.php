<?php
/**
 * Customer — detail.
 *
 * The account panel reads the linked users row (customers.user_id), so what it
 * reports is the same record the Users & Roles page lists — there is no second
 * copy of this person anywhere.
 */
$pageTitle = sanitize($customer['full_name']);
$breadcrumbs = [['label' => 'Customers', 'url' => APP_URL . '/index.php?page=customers'], ['label' => $customer['full_name']]];
$actionButton = ['label' => 'Edit', 'icon' => 'bi-pencil', 'url' => APP_URL . '/index.php?page=customers&action=edit&id=' . $customer['id']];
$c = $customer;
$hasAccount = !empty($c['account_id']);
// Granting or revoking a login is an administrator action; editing the profile
// is staff work. Both read the matrix rather than naming roles.
$canManage = can('customers.enable-login');
?>
<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px">
    <!-- Profile -->
    <div class="card mb-3">
        <div class="card__header"><h3 class="card__title">Profile</h3></div>
        <div class="card__body">
            <table style="width:100%;font-size:.875rem">
                <tr><td class="text-muted" style="padding:8px 0;width:40%">Phone</td><td style="padding:8px 0"><?= sanitize($c['phone']) ?></td></tr>
                <tr><td class="text-muted" style="padding:8px 0">Email</td><td><?= sanitize($c['email'] ?: '—') ?></td></tr>
                <tr><td class="text-muted" style="padding:8px 0">National ID</td><td><?= sanitize($c['national_id'] ?: '—') ?></td></tr>
                <tr><td class="text-muted" style="padding:8px 0">Address</td><td><?= sanitize($c['address'] ?: '—') ?></td></tr>
                <tr><td class="text-muted" style="padding:8px 0">Type</td><td><?= ucfirst($c['customer_type']) ?></td></tr>
                <tr><td class="text-muted" style="padding:8px 0">Risk</td><td><span class="badge badge--<?= $c['risk_level'] === 'high' ? 'danger' : ($c['risk_level'] === 'medium' ? 'warning' : 'success') ?>"><?= ucfirst($c['risk_level']) ?></span></td></tr>
                <tr><td class="text-muted" style="padding:8px 0">Occupation</td><td><?= sanitize($c['occupation'] ?: '—') ?></td></tr>
                <tr><td class="text-muted" style="padding:8px 0">Guarantor</td><td><?= sanitize($c['guarantor_name'] ?: '—') ?> <?= $c['guarantor_contact'] ? '(' . sanitize($c['guarantor_contact']) . ')' : '' ?></td></tr>
                <?php if ($c['is_blacklisted']): ?><tr><td class="text-muted" style="padding:8px 0">Status</td><td><span class="badge badge--danger">Blacklisted</span></td></tr><?php endif; ?>
            </table>
            <?php if ($c['notes']): ?><div class="mt-2" style="font-size:.875rem;padding:12px;background:var(--body-bg);border-radius:var(--radius-sm)"><strong>Notes:</strong> <?= nl2br(sanitize($c['notes'])) ?></div><?php endif; ?>
        </div>
    </div>

    <!-- Account & login access -->
    <div class="card mb-3">
        <div class="card__header"><h3 class="card__title">Account Information</h3></div>
        <div class="card__body">
            <table style="width:100%;font-size:.875rem">
                <tr>
                    <td class="text-muted" style="padding:8px 0;width:40%">Login Access</td>
                    <td>
                        <?php if (!$hasAccount): ?>
                            <span class="badge badge--muted">Disabled — no account</span>
                        <?php elseif ($c['account_active']): ?>
                            <span class="badge badge--success">Enabled</span>
                        <?php else: ?>
                            <span class="badge badge--warning">Disabled</span>
                        <?php endif ?>
                    </td>
                </tr>
                <?php if ($hasAccount): ?>
                    <tr><td class="text-muted" style="padding:8px 0">Signs in with</td><td><?= sanitize($c['account_email']) ?></td></tr>
                    <tr><td class="text-muted" style="padding:8px 0">Username</td><td><code><?= sanitize($c['account_username']) ?></code></td></tr>
                    <tr><td class="text-muted" style="padding:8px 0">Role</td><td><span class="badge badge--primary"><?= sanitize($c['account_role_display'] ?? 'Customer') ?></span></td></tr>
                    <tr><td class="text-muted" style="padding:8px 0">Last login</td><td><?= $c['account_last_login'] ? formatDateTime($c['account_last_login']) : 'Never' ?></td></tr>
                    <tr>
                        <td class="text-muted" style="padding:8px 0">User record</td>
                        <td>
                            <?php if ($canManage): ?>
                                <a href="<?= APP_URL ?>/index.php?page=users&action=edit&id=<?= (int) $c['account_id'] ?>">#<?= (int) $c['account_id'] ?> in Users &amp; Roles</a>
                            <?php else: ?>
                                #<?= (int) $c['account_id'] ?>
                            <?php endif ?>
                        </td>
                    </tr>
                <?php endif ?>
            </table>

            <?php if ($canManage): ?>
                <?php if (!$hasAccount): ?>
                    <p class="form-hint" style="margin:10px 0 12px">
                        This customer is a business record only. Giving them access creates one account
                        with the role <strong>Customer</strong> — or adopts the existing customer account
                        that already carries their email.
                    </p>
                    <button type="button" class="btn btn--primary btn--sm" data-modal-open="customerLoginModal">
                        <i class="bi bi-key"></i> Enable Login Access
                    </button>
                <?php elseif ($c['account_active']): ?>
                    <form method="POST" action="<?= APP_URL ?>/index.php?page=customers&amp;action=disable-login&amp;id=<?= (int) $c['id'] ?>"
                          onsubmit="return confirm('Disable login for this customer? Their profile, leases and payment history are kept.')">
                        <?= csrfField() ?>
                        <button type="submit" class="btn btn--outline btn--sm"><i class="bi bi-lock"></i> Disable Login Access</button>
                    </form>
                <?php else: ?>
                    <form method="POST" action="<?= APP_URL ?>/index.php?page=customers&amp;action=enable-login&amp;id=<?= (int) $c['id'] ?>">
                        <?= csrfField() ?>
                        <button type="submit" class="btn btn--primary btn--sm"><i class="bi bi-unlock"></i> Re-enable Login Access</button>
                    </form>
                <?php endif ?>
            <?php endif ?>
        </div>
    </div>

    <!-- Rental History -->
    <div class="card mb-3">
        <div class="card__header"><h3 class="card__title">Rental History</h3></div>
        <div class="card__body" style="padding:0">
            <?php if (empty($rentalHistory)): ?>
                <div class="empty-state"><div class="empty-state__desc">No rental history.</div></div>
            <?php else: ?>
            <div class="table-wrap">
                <table class="table">
                    <thead><tr><th>Property</th><th>Period</th><th>Rent</th><th>Status</th></tr></thead>
                    <tbody>
                    <?php foreach ($rentalHistory as $r): ?>
                    <tr>
                        <td><?= sanitize($r['property_title']) ?></td>
                        <td><?= formatDate($r['start_date']) ?> — <?= formatDate($r['end_date']) ?></td>
                        <td><?= formatCurrency($r['rent_amount']) ?></td>
                        <td><span class="badge <?= getStatusBadgeClass($r['status']) ?>"><?= ucfirst($r['status']) ?></span></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Payment History -->
<div class="card">
    <div class="card__header"><h3 class="card__title">Payment History</h3></div>
    <div class="card__body" style="padding:0">
        <?php if (empty($paymentHistory)): ?>
            <div class="empty-state"><div class="empty-state__desc">No payments recorded.</div></div>
        <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>Code</th><th>Type</th><th>Property</th><th>Amount</th><th>Date</th><th>Status</th></tr></thead>
                <tbody>
                <?php foreach ($paymentHistory as $py): ?>
                <tr>
                    <td><?= sanitize($py['payment_code']) ?></td>
                    <td><?= ucfirst(str_replace('_',' ',$py['payment_type'])) ?></td>
                    <td><?= sanitize($py['property_title'] ?? '—') ?></td>
                    <td><?= formatCurrency($py['amount']) ?></td>
                    <td><?= formatDate($py['payment_date']) ?></td>
                    <td><span class="badge <?= getStatusBadgeClass($py['status']) ?>"><?= ucfirst($py['status']) ?></span></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php if ($canManage && !$hasAccount): ?>
    <?php require __DIR__ . '/_login_modal.php'; ?>
<?php endif ?>
