<?php
/**
 * Performance — what each agent manages, lets, sells and collects.
 *
 * Approved decision 6 in visual form: seven independently sourced measures
 * and no composite score. A single "performance" number would have to weight
 * a closed sale against a signed tenancy against rent collected, and nobody
 * has decided what those weights are — inventing them here would smuggle a
 * business decision in as arithmetic.
 *
 * Which means the column headings have to work harder than usual, because
 * two of these columns are standing quantities (a book, as it is now) and
 * three are events inside the reporting period. The note under the table
 * says so; without it a reader would reasonably assume all five describe the
 * same window.
 *
 * An agent reading this sees one row, their own — enforced in the query, not
 * by hiding a column.
 *
 * Vars from ReportController::performanceData().
 */
$rows = $agentPerf ?? [];

/* A zero in a performance column is a fact, not a gap — but a wall of noughts
   reads as a broken page, so nothing is drawn where nothing happened. */
$num = static fn($n): string => (int) $n > 0
    ? number_format((int) $n)
    : '<span class="text-subtle" aria-label="none">—</span>';
$money = static fn($v): string => (float) $v > 0
    ? sanitize(formatCurrency((float) $v))
    : '<span class="text-subtle" aria-label="none">—</span>';
?>

<?php if (!empty($unattributed) && $unattributed['count'] > 0): ?>
    <?php /* Without this the table below reads as a desk that collected
             nothing. It is not — the money simply belongs to properties with
             no agent on them, so it lands in the company total and on nobody's
             row. Stated here rather than left for someone to work out. */ ?>
    <div class="notice notice--info mb-2">
        <div class="notice__icon"><i class="bi bi-person-slash" aria-hidden="true"></i></div>
        <div class="notice__body">
            <div class="notice__title">
                <?= sanitize(formatCurrency($unattributed['amount'])) ?> of collected revenue
                belongs to no agent
            </div>
            <?= number_format($unattributed['count']) ?>
            <?= $unattributed['count'] === 1 ? 'payment was' : 'payments were' ?>
            taken on properties with nobody assigned, so
            <?= $unattributed['count'] === 1 ? 'it appears' : 'they appear' ?>
            in company totals but on no row below.
        </div>
    </div>
<?php endif ?>

<div class="table-card">
    <div class="table-head">
        <div class="table-head__title">Agent performance</div>
        <span class="table-head__note">
            Portfolio and active book as they stand now; revenue and deals within <?= sanitize($window['label']) ?>
        </span>
    </div>

    <?php if (!$rows): ?>
        <?= uiEmptyState([
            'icon'  => 'bi-person-badge',
            'title' => 'No active agents',
            'desc'  => 'Give an account the Agent role and its figures appear here.',
            'actions' => [[
                'label' => 'Users & roles', 'icon' => 'bi-people', 'can' => 'users.view',
                'class' => 'btn--outline', 'url' => APP_URL . '/index.php?page=users',
            ]],
        ]) ?>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th scope="col">Agent</th>
                        <th scope="col" class="cell-num">Managed</th>
                        <th scope="col" class="cell-num">Active leases</th>
                        <th scope="col" class="cell-num col-mid">Leases written</th>
                        <th scope="col" class="cell-num col-lo">Sales closed</th>
                        <th scope="col" class="cell-num">Rent collected</th>
                        <th scope="col" class="cell-num col-mid">Sales collected</th>
                        <th scope="col" class="cell-num col-lo">Received at desk</th>
                        <th scope="col" class="cell-num">Commission due</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $a): ?>
                        <tr>
                            <td><?= uiPersonCell($a['full_name'], $a['avatar'] ?? null) ?></td>
                            <td class="cell-num"><?= $num($a['properties_managed']) ?></td>
                            <td class="cell-num"><?= $num($a['active_leases']) ?></td>
                            <td class="cell-num col-mid"><?= $num($a['leases_created']) ?></td>
                            <td class="cell-num col-lo"><?= $num($a['sales_completed']) ?></td>
                            <td class="cell-num"><?= $money($a['rental_revenue']) ?></td>
                            <td class="cell-num col-mid"><?= $money($a['sales_revenue']) ?></td>
                            <td class="cell-num col-lo"><?= $money($a['revenue_received']) ?></td>
                            <td class="cell-num"><?= $money($a['commission_pending']) ?></td>
                        </tr>
                    <?php endforeach ?>
                </tbody>
            </table>
        </div>

        <div class="table-foot">
            <p class="table-foot__note">
                <strong>Rent collected</strong> is money taken on that agent's own listings.
                <strong>Received at desk</strong> is money they personally took in, including
                rent on a colleague's property — the two answer different questions and will
                not agree. No column is weighted into a score.
            </p>
        </div>
    <?php endif ?>
</div>

<?php
$pending = [
    'icon'  => 'bi-graph-up',
    'title' => 'Owner performance',
    'desc'  => 'Revenue, occupancy and outstanding balances per landlord, under the '
             . 'same access scope that governs the owner directory. It arrives with the '
             . 'property and rental reports, which supply the per-property figures it sums.',
];
require dirname(__DIR__) . '/_pending.php';
?>
