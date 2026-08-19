<?php
/**
 * Customer Portal — my tenancy.
 *
 * A tenant opens this to answer three things: what am I paying, until when,
 * and what is due next. The next instalment is therefore called out rather
 * than left to be found in the schedule.
 */
?>
<?php if (!$activeLease): ?>
    <div class="table-card">
        <?= uiEmptyState([
            'icon'  => 'bi-file-earmark-text',
            'title' => 'No tenancy on record',
            'desc'  => 'When the office records a lease in your name it appears here, with its schedule of payments.',
        ]) ?>
    </div>
<?php else: ?>
    <?php
    $l = $activeLease;
    // The first instalment still outstanding — the one that matters today.
    $next = null;
    foreach ($schedule as $s) {
        if (in_array($s['status'], ['pending', 'overdue', 'partial'], true)) {
            $next = $s;
            break;
        }
    }
    $daysLeft = (int) (new DateTimeImmutable('today'))
        ->diff(new DateTimeImmutable($l['end_date'] . ' 00:00:00'))->format('%r%a');
    ?>

    <div class="detail-header">
        <div class="detail-header__body">
            <div class="detail-header__eyebrow"><?= sanitize($l['lease_code']) ?></div>
            <h2 class="detail-header__title"><?= sanitize($l['property_title']) ?></h2>
            <?php if (!empty($l['property_address'])): ?>
                <p class="detail-header__lede">
                    <i class="bi bi-geo-alt" aria-hidden="true"></i> <?= sanitize($l['property_address']) ?>
                </p>
            <?php endif ?>

            <div class="detail-stats">
                <div class="detail-stat">
                    <div class="detail-stat__label">Rent</div>
                    <div class="detail-stat__value"><?= formatCurrency((float) $l['rent_amount']) ?></div>
                </div>
                <div class="detail-stat">
                    <div class="detail-stat__label">Deposit held</div>
                    <div class="detail-stat__value"><?= formatCurrency((float) $l['deposit_amount']) ?></div>
                </div>
                <div class="detail-stat">
                    <div class="detail-stat__label">Runs until</div>
                    <div class="detail-stat__value"><?= formatDate($l['end_date']) ?></div>
                </div>
                <?php if ($next): ?>
                    <div class="detail-stat">
                        <div class="detail-stat__label">Next due</div>
                        <div class="detail-stat__value"><?= formatDate($next['due_date']) ?></div>
                    </div>
                <?php endif ?>
            </div>
        </div>

        <div class="detail-header__actions">
            <?= uiStatus($l['status']) ?>
            <?php if ($l['contract_file']): ?>
                <a class="btn btn--outline btn--sm"
                   href="<?= APP_URL . '/' . sanitize($l['contract_file']) ?>" target="_blank" rel="noopener">
                    <i class="bi bi-file-earmark-pdf" aria-hidden="true"></i> Your contract
                </a>
            <?php endif ?>
        </div>
    </div>

    <?php if ($l['status'] === 'active' && $daysLeft >= 0 && $daysLeft <= 60): ?>
        <div class="alert alert--warning">
            <i class="bi bi-hourglass-split" aria-hidden="true"></i>
            <div>
                Your tenancy ends <?= $daysLeft === 0 ? 'today' : 'in ' . $daysLeft . ' day' . ($daysLeft === 1 ? '' : 's') ?>,
                on <?= formatDate($l['end_date']) ?>. Speak to the office if you would like to renew.
            </div>
        </div>
    <?php endif ?>

    <div class="detail-cols">
        <div class="detail-cols__main">
            <div class="table-card">
                <div class="table-head">
                    <div class="table-head__title">Payment schedule</div>
                    <span class="table-head__note"><?= count($schedule) ?> instalments</span>
                </div>
                <?php if (empty($schedule)): ?>
                    <?= uiEmptyState([
                        'icon'  => 'bi-calendar3',
                        'title' => 'No schedule recorded',
                        'desc'  => 'The office has not written a schedule for this tenancy yet.',
                    ]) ?>
                <?php else: ?>
                    <div class="table-wrap">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th class="cell-date">Due</th>
                                    <th class="cell-num">Amount</th>
                                    <th class="cell-num col-lo">Penalty</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($schedule as $s): ?>
                                    <tr>
                                        <td class="cell-date"><?= formatDate($s['due_date']) ?></td>
                                        <td class="cell-num"><?= formatCurrency((float) $s['amount']) ?></td>
                                        <td class="cell-num col-lo">
                                            <?php if ((float) ($s['penalty'] ?? 0) > 0): ?>
                                                <span class="text-danger"><?= formatCurrency((float) $s['penalty']) ?></span>
                                            <?php else: ?>
                                                <span class="text-subtle">—</span>
                                            <?php endif ?>
                                        </td>
                                        <td>
                                            <?= uiStatus($s['status']) ?>
                                            <?php if (!empty($s['paid_date'])): ?>
                                                <div class="person__meta">paid <?= formatDate($s['paid_date']) ?></div>
                                            <?php endif ?>
                                        </td>
                                    </tr>
                                <?php endforeach ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif ?>
            </div>
        </div>

        <aside class="detail-cols__side">
            <div class="card">
                <div class="card__header"><h3 class="card__title">The terms</h3></div>
                <div class="card__body">
                    <dl class="datalist">
                        <div class="datalist__row"><dt>Lease</dt><dd><?= sanitize($l['lease_code']) ?></dd></div>
                        <div class="datalist__row"><dt>From</dt>
                            <dd class="num"><?= formatDate($l['start_date']) ?></dd>
                        </div>
                        <div class="datalist__row"><dt>Until</dt>
                            <dd class="num"><?= formatDate($l['end_date']) ?></dd>
                        </div>
                        <div class="datalist__row"><dt>Rent falls due</dt>
                            <dd><?= sanitize(uiLabel((string) ($l['payment_schedule'] ?? 'monthly'))) ?></dd>
                        </div>
                        <div class="datalist__row"><dt>Late fee</dt>
                            <dd class="num"><?= number_format((float) ($l['late_fee_rate'] ?? 0), 2) ?>%</dd>
                        </div>
                    </dl>
                </div>
            </div>
        </aside>
    </div>
<?php endif ?>

<?php if (count($leases) > 1): ?>
    <div class="table-card mt-3">
        <div class="table-head">
            <div class="table-head__title">Your earlier tenancies</div>
        </div>
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th class="col-lo">Lease</th>
                        <th>Property</th>
                        <th class="cell-date">Period</th>
                        <th class="cell-num col-mid">Rent</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($leases as $past): ?>
                        <tr>
                            <td class="cell-tight col-lo"><span class="table__id"><?= sanitize($past['lease_code']) ?></span></td>
                            <td><?= sanitize($past['property_title']) ?></td>
                            <td class="cell-date">
                                <?= formatDate($past['start_date']) ?> – <?= formatDate($past['end_date']) ?>
                            </td>
                            <td class="cell-num col-mid"><?= formatCurrency((float) $past['rent_amount']) ?></td>
                            <td><?= uiStatus($past['status']) ?></td>
                        </tr>
                    <?php endforeach ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif ?>
