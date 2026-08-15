<?php
/**
 * Customer Portal — My Lease
 */
?>
<?php if (!$activeLease): ?>
    <div class="empty-state">
        <div class="empty-state__icon"><i class="bi bi-file-earmark-text"></i></div>
        <div class="empty-state__title">No active lease</div>
        <div class="empty-state__desc">Once you sign a lease, it will appear here.</div>
    </div>
<?php else: $l = $activeLease; ?>
<div class="grid-2">
    <div class="card">
        <div class="card__header"><h3 class="card__title">Active Lease</h3><span class="badge <?= getStatusBadgeClass($l['status']) ?>"><?= ucfirst($l['status']) ?></span></div>
        <div class="card__body">
            <div class="profile-meta">
                <div class="profile-meta__row"><span class="profile-meta__label">Property</span><span class="profile-meta__value"><?= sanitize($l['property_title']) ?></span></div>
                <div class="profile-meta__row"><span class="profile-meta__label">Code</span><span class="profile-meta__value"><?= sanitize($l['lease_code']) ?></span></div>
                <div class="profile-meta__row"><span class="profile-meta__label">Address</span><span class="profile-meta__value"><?= sanitize($l['property_address']) ?></span></div>
                <div class="profile-meta__row"><span class="profile-meta__label">Start Date</span><span class="profile-meta__value"><?= formatDate($l['start_date']) ?></span></div>
                <div class="profile-meta__row"><span class="profile-meta__label">End Date</span><span class="profile-meta__value"><?= formatDate($l['end_date']) ?></span></div>
                <div class="profile-meta__row"><span class="profile-meta__label">Monthly Rent</span><span class="profile-meta__value"><?= formatCurrency((float)$l['rent_amount']) ?></span></div>
                <div class="profile-meta__row"><span class="profile-meta__label">Deposit</span><span class="profile-meta__value"><?= formatCurrency((float)$l['deposit_amount']) ?></span></div>
            </div>
            <?php if ($l['contract_file']): ?>
            <a class="btn btn--outline btn--block mt-2" href="<?= APP_URL . '/' . $l['contract_file'] ?>" target="_blank"><i class="bi bi-file-earmark-pdf"></i> View Contract</a>
            <?php endif ?>
        </div>
    </div>

    <div class="card">
        <div class="card__header"><h3 class="card__title">Payment Schedule</h3></div>
        <div class="card__body" style="padding:0;max-height:500px;overflow-y:auto">
            <?php if (empty($schedule)): ?>
                <div class="empty-state"><div class="empty-state__desc">No schedule.</div></div>
            <?php else: ?>
            <table class="table">
                <thead><tr><th>Due</th><th>Amount</th><th>Status</th></tr></thead>
                <tbody>
                <?php foreach ($schedule as $s): ?>
                <tr>
                    <td><?= formatDate($s['due_date']) ?></td>
                    <td><?= formatCurrency((float)$s['amount']) ?></td>
                    <td><span class="badge <?= getStatusBadgeClass($s['status']) ?>"><?= ucfirst($s['status']) ?></span></td>
                </tr>
                <?php endforeach ?>
                </tbody>
            </table>
            <?php endif ?>
        </div>
    </div>
</div>
<?php endif ?>

<?php if (count($leases) > 1): ?>
<div class="card mt-3">
    <div class="card__header"><h3 class="card__title">Lease History</h3></div>
    <div class="card__body" style="padding:0">
        <table class="table">
            <thead><tr><th>Code</th><th>Property</th><th>Period</th><th>Rent</th><th>Status</th></tr></thead>
            <tbody>
            <?php foreach ($leases as $l): ?>
            <tr>
                <td><?= sanitize($l['lease_code']) ?></td>
                <td><?= sanitize($l['property_title']) ?></td>
                <td><?= formatDate($l['start_date']) ?> – <?= formatDate($l['end_date']) ?></td>
                <td><?= formatCurrency((float)$l['rent_amount']) ?></td>
                <td><span class="badge <?= getStatusBadgeClass($l['status']) ?>"><?= ucfirst($l['status']) ?></span></td>
            </tr>
            <?php endforeach ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif ?>
