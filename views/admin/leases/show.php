<?php
/**
 * Lease — Show
 */
$l = $lease;
?>
<div class="mini-stats">
    <div class="mini-stat"><div class="mini-stat__label">Monthly Rent</div><div class="mini-stat__value"><?= formatCurrency((float)$l['rent_amount']) ?></div></div>
    <div class="mini-stat"><div class="mini-stat__label">Deposit Held</div><div class="mini-stat__value"><?= formatCurrency((float)$l['deposit_amount']) ?></div></div>
    <div class="mini-stat"><div class="mini-stat__label">Arrears</div><div class="mini-stat__value" style="color:<?= $arrears > 0 ? 'var(--danger)' : 'var(--success)' ?>"><?= formatCurrency($arrears) ?></div></div>
    <div class="mini-stat"><div class="mini-stat__label">Status</div><div class="mini-stat__value"><span class="badge <?= getStatusBadgeClass($l['status']) ?>"><?= ucfirst($l['status']) ?></span></div></div>
</div>

<div style="display:flex;gap:8px;margin-bottom:18px;flex-wrap:wrap" class="no-print">
    <?php if ($l['status'] === 'active'): ?>
    <a class="btn btn--primary btn--sm" href="<?= APP_URL ?>/index.php?page=payments&action=create&customer_id=<?= $l['customer_id'] ?>&property_id=<?= $l['property_id'] ?>&lease_id=<?= $l['id'] ?>">
        <i class="bi bi-cash"></i> Record Payment
    </a>
    <a class="btn btn--outline btn--sm" href="<?= APP_URL ?>/index.php?page=leases&action=renew&id=<?= $l['id'] ?>">
        <i class="bi bi-arrow-clockwise"></i> Renew
    </a>
    <button class="btn btn--danger btn--sm" onclick="document.getElementById('terminateModal').style.display='flex'">
        <i class="bi bi-x-circle"></i> Terminate
    </button>
    <?php endif ?>
    <?php if ($l['contract_file']): ?>
    <a class="btn btn--outline btn--sm" href="<?= APP_URL . '/' . $l['contract_file'] ?>" target="_blank">
        <i class="bi bi-file-earmark-pdf"></i> Contract
    </a>
    <?php endif ?>
</div>

<div class="grid-2">
    <div class="card">
        <div class="card__header"><h3 class="card__title">Lease Details</h3></div>
        <div class="card__body">
            <div class="profile-meta">
                <div class="profile-meta__row"><span class="profile-meta__label">Code</span><span class="profile-meta__value"><?= sanitize($l['lease_code']) ?></span></div>
                <div class="profile-meta__row"><span class="profile-meta__label">Customer</span><span class="profile-meta__value"><a href="<?= APP_URL ?>/index.php?page=customers&action=show&id=<?= $l['customer_id'] ?>"><?= sanitize($l['customer_name']) ?></a></span></div>
                <div class="profile-meta__row"><span class="profile-meta__label">Property</span><span class="profile-meta__value"><a href="<?= APP_URL ?>/index.php?page=properties&action=show&id=<?= $l['property_id'] ?>"><?= sanitize($l['property_title']) ?></a></span></div>
                <div class="profile-meta__row"><span class="profile-meta__label">Period</span><span class="profile-meta__value"><?= formatDate($l['start_date']) ?> – <?= formatDate($l['end_date']) ?></span></div>
                <div class="profile-meta__row"><span class="profile-meta__label">Move-in</span><span class="profile-meta__value"><?= formatDate($l['move_in_date']) ?></span></div>
                <?php if ($l['move_out_date']): ?>
                <div class="profile-meta__row"><span class="profile-meta__label">Move-out</span><span class="profile-meta__value"><?= formatDate($l['move_out_date']) ?></span></div>
                <?php endif ?>
                <div class="profile-meta__row"><span class="profile-meta__label">Schedule</span><span class="profile-meta__value"><?= ucfirst($l['payment_schedule']) ?></span></div>
                <div class="profile-meta__row"><span class="profile-meta__label">Late Fee</span><span class="profile-meta__value"><?= $l['late_fee_rate'] ?>%</span></div>
                <?php if ($l['owner_name']): ?>
                <div class="profile-meta__row"><span class="profile-meta__label">Owner</span><span class="profile-meta__value"><?= sanitize($l['owner_name']) ?></span></div>
                <?php endif ?>
            </div>
            <?php if ($l['terms']): ?>
                <div class="section-title">Terms</div>
                <div style="font-size:.85rem;line-height:1.6"><?= nl2br(sanitize($l['terms'])) ?></div>
            <?php endif ?>
            <?php if ($l['termination_reason']): ?>
                <div class="section-title">Termination Reason</div>
                <div style="font-size:.85rem;color:var(--danger)"><?= nl2br(sanitize($l['termination_reason'])) ?></div>
            <?php endif ?>
        </div>
    </div>

    <div class="card">
        <div class="card__header"><h3 class="card__title">Payment Schedule</h3></div>
        <div class="card__body" style="padding:0;max-height:500px;overflow-y:auto">
            <?php if (empty($schedule)): ?>
                <div class="empty-state"><div class="empty-state__desc">No schedule yet.</div></div>
            <?php else: ?>
            <table class="table">
                <thead><tr><th>Due Date</th><th>Amount</th><th>Status</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($schedule as $s): ?>
                    <tr>
                        <td><?= formatDate($s['due_date']) ?></td>
                        <td><?= formatCurrency((float)$s['amount']) ?>
                            <?php if ($s['penalty'] > 0): ?>
                                <div class="text-muted" style="font-size:.7rem">+<?= formatCurrency((float)$s['penalty']) ?> penalty</div>
                            <?php endif ?>
                        </td>
                        <td><span class="badge <?= getStatusBadgeClass($s['status']) ?>"><?= ucfirst($s['status']) ?></span></td>
                        <td>
                        <?php if ($s['status'] !== 'paid'): ?>
                            <a class="btn btn--outline btn--sm" href="<?= APP_URL ?>/index.php?page=payments&action=create&schedule=<?= $s['id'] ?>">Pay</a>
                        <?php else: ?>
                            <span class="text-muted" style="font-size:.75rem"><?= formatDate($s['paid_date']) ?></span>
                        <?php endif ?>
                        </td>
                    </tr>
                <?php endforeach ?>
                </tbody>
            </table>
            <?php endif ?>
        </div>
    </div>
</div>

<!-- Terminate Modal -->
<?php if ($l['status'] === 'active'): ?>
<div id="terminateModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:300;align-items:center;justify-content:center" class="no-print">
    <div class="card" style="width:90%;max-width:480px">
        <div class="card__header"><h3 class="card__title">Terminate Lease</h3></div>
        <div class="card__body">
            <form method="post" action="<?= APP_URL ?>/index.php?page=leases&action=terminate&id=<?= $l['id'] ?>">
                <?= csrfField() ?>
                <div class="form-group">
                    <label class="form-label">Move-out Date</label>
                    <input type="date" class="form-control" name="move_out_date" value="<?= date('Y-m-d') ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">Reason *</label>
                    <textarea class="form-control" name="reason" rows="3" required></textarea>
                </div>
                <div class="form-actions">
                    <button type="button" class="btn btn--outline" onclick="document.getElementById('terminateModal').style.display='none'">Cancel</button>
                    <button type="submit" class="btn btn--danger">Terminate Lease</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif ?>
