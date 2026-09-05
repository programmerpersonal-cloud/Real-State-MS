<?php
/**
 * Performance — what each agent manages, lets, sells and collects.
 *
 * Approved decision 6 in visual form: eight independently sourced measures
 * and no composite score. A single "performance" number would have to weight
 * a closed sale against a signed tenancy against rent collected, and nobody
 * has decided what those weights are — inventing them here would smuggle a
 * business decision in as arithmetic.
 *
 * Which is exactly why every heading carries its own tense. Two of these
 * columns are *standing* quantities — the book as it is right now — and five
 * are events inside the reporting period, and a plain row of headings said
 * nothing about that: a reader comparing "Managed" against "Sales closed" was
 * comparing a stock against a flow without being told. Each heading now says
 * "now", "in period" or "to date" underneath itself, and the note beneath the
 * table says why two of the revenue columns will never agree.
 *
 * Phase 8 also gave the table a ranking treatment, and it is the only kind
 * this report can honestly carry: the highest value *within a column* is
 * marked, per column, with the word "highest" behind it for anyone not
 * reading the emphasis. There is no order, no position and no total across
 * the columns, because there is no defensible way to combine them.
 *
 * An agent reading this sees one row, their own — enforced in the query, not
 * by hiding a column.
 *
 * Vars from ReportController::performanceData().
 */
$rows = $agentPerf ?? [];

/* A zero in a performance column is a fact, not a gap — but a wall of noughts
   reads as a broken page, so nothing is drawn where nothing happened. */
$perfCarry = !empty($compare) ? ['compare' => '1'] : [];

/* Each column drills to its own records, and the eight attributions stay
   eight: "rent collected" opens the payments on that agent's listings and
   "received at desk" opens the ones they personally took in, because the two
   answer different questions and the panels must not be the same set. */
$perfDrill = static fn(string $metric, int $agentId): string
    => reportDrillUrl($window, $filters, 'performance', $metric, (string) $agentId, $perfCarry);

$num = static fn($n): string => (int) $n > 0
    ? number_format((int) $n)
    : '<span class="text-subtle" aria-label="none">—</span>';
$money = static fn($v): string => (float) $v > 0
    ? sanitize(formatCurrency((float) $v))
    : '<span class="text-subtle" aria-label="none">—</span>';

/* A figure worth opening becomes a link; a nought does not. There is nothing
   behind a nought to show, and a panel that can only say "no records" is a
   promise the tile should not have made. */
$perfCell = static function (string $text, string $metric, int $agentId, $value) use ($perfDrill): string {
    if ((float) $value <= 0) {
        return $text;
    }

    return '<a class="is-drill" data-drill href="' . sanitize($perfDrill($metric, $agentId)) . '">'
         . $text . '</a>';
};

/* The leading value in each column, computed only where it means something:
   one row cannot lead a field of one, and a column of zeroes has no leader.
   Ties are all marked, because breaking a tie would need a tiebreaker and
   this report has deliberately refused to invent one. */
$leaders = [];
if (count($rows) > 1) {
    foreach ([
        'properties_managed', 'active_leases', 'leases_created', 'sales_completed',
        'rental_revenue', 'sales_revenue', 'revenue_received', 'commission_pending',
    ] as $col) {
        $max = 0.0;
        foreach ($rows as $r) { $max = max($max, (float) $r[$col]); }
        if ($max > 0) { $leaders[$col] = $max; }
    }
}
$lead = static function (string $col, $value) use ($leaders): string {
    return (isset($leaders[$col]) && (float) $value >= $leaders[$col])
        ? ' is-leader'
        : '';
};
$leadNote = static function (string $col, $value) use ($leaders): string {
    return (isset($leaders[$col]) && (float) $value >= $leaders[$col])
        ? '<span class="sr-only"> — highest in this column</span>'
        : '';
};
?>

<?php $section = [
    'title' => 'Agent performance',
    'desc'  => 'Eight measures, each from its own source. Nothing here is weighted into a score.',
]; require dirname(__DIR__) . '/_section.php'; ?>

<?php if (!empty($unattributed) && $unattributed['count'] > 0): ?>
    <?php /* Without this the table below reads as a desk that collected
             nothing. It is not — the money simply belongs to properties with
             no agent on them, so it lands in the company total and on nobody's
             row. Stated here rather than left for someone to work out. */ ?>
    <div class="rnote rnote--info" role="note">
        <span class="rnote__icon" aria-hidden="true"><i class="bi bi-person-slash"></i></span>
        <div class="rnote__body">
            <p class="rnote__title">
                <?= sanitize(formatCurrency($unattributed['amount'])) ?> of collected revenue
                belongs to no agent
            </p>
            <?= number_format($unattributed['count']) ?>
            <?= $unattributed['count'] === 1 ? 'payment was' : 'payments were' ?>
            taken on properties with nobody assigned, so
            <?= $unattributed['count'] === 1 ? 'it appears' : 'they appear' ?>
            in company totals but on no row below. No column here is missing money;
            the money is missing an owner.
        </div>
    </div>
<?php endif ?>

<div class="table-card">
    <div class="table-head">
        <h4 class="table-head__title">The desk, agent by agent</h4>
        <span class="table-head__note">
            <i class="bi bi-info-circle" aria-hidden="true"></i>
            Book as it stands now · activity and revenue within <?= sanitize($window['label']) ?>
        </span>
    </div>

    <?php if (!$rows): ?>
        <?= uiEmptyState([
            'icon'  => 'bi-person-badge',
            'title' => 'No active agents',
            'desc'  => 'Give an account the Agent role and its figures appear here. '
                     . 'Nothing is estimated in the meantime.',
            'actions' => [[
                'label' => 'Users & roles', 'icon' => 'bi-people', 'can' => 'users.view',
                'class' => 'btn--outline', 'url' => APP_URL . '/index.php?page=users',
            ]],
        ]) ?>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table perf__table">
                <caption class="sr-only">
                    Agent performance. Two columns describe the book as it stands today;
                    the remaining columns describe activity and revenue within
                    <?= sanitize($window['label']) ?>. No column is combined into a score.
                </caption>
                <thead>
                    <?php /* Every heading carries its own tense.
                             This started as a two-tier header with column
                             groups — "Book, as it stands" over two columns,
                             "Activity in period" over two more — which read
                             beautifully and was wrong: the register's column
                             manager (assets/js/main.js) hides .col-mid and
                             .col-lo when a table cannot fit, and a colspan
                             cannot shrink with them. At 1440px it hid four of
                             the nine columns and the group row was left
                             spanning air.
                             A qualifier on each heading survives any number of
                             columns being dropped, and says the same thing at
                             the moment it is needed. */ ?>
                    <tr>
                        <th scope="col" class="perf__who">Agent</th>
                        <th scope="col" class="cell-num">Managed<span class="perf__when">now</span></th>
                        <th scope="col" class="cell-num">Active leases<span class="perf__when">now</span></th>
                        <th scope="col" class="cell-num col-mid">Leases written<span class="perf__when">in period</span></th>
                        <th scope="col" class="cell-num col-lo">Sales closed<span class="perf__when">in period</span></th>
                        <th scope="col" class="cell-num">Rent collected<span class="perf__when">in period</span></th>
                        <th scope="col" class="cell-num col-mid">Sales collected<span class="perf__when">in period</span></th>
                        <th scope="col" class="cell-num col-lo">Received at desk<span class="perf__when">in period</span></th>
                        <th scope="col" class="cell-num">Commission due<span class="perf__when">to date</span></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $a): ?>
                        <tr>
                            <th scope="row" class="perf__who">
                                <?php /* Wrapped rather than handed a url:
                                         uiPersonCell() renders its own anchor
                                         when given one, and an anchor inside
                                         an anchor is not markup a browser
                                         agrees about. Without a url it
                                         renders a span, which is safe to
                                         wrap and keeps the drawer behaviour
                                         every other drill link has. */ ?>
                                <a class="is-drill" data-drill
                                   href="<?= sanitize($perfDrill('agent_listings', (int) $a['id'])) ?>">
                                    <?= uiPersonCell($a['full_name'], $a['avatar'] ?? null) ?>
                                </a>
                            </th>
                            <td class="cell-num<?= $lead('properties_managed', $a['properties_managed']) ?>">
                                <?= $perfCell($num($a['properties_managed']), 'agent_listings', (int) $a['id'], $a['properties_managed']) ?><?= $leadNote('properties_managed', $a['properties_managed']) ?>
                            </td>
                            <td class="cell-num<?= $lead('active_leases', $a['active_leases']) ?>">
                                <?= $perfCell($num($a['active_leases']), 'agent_leases', (int) $a['id'], $a['active_leases']) ?><?= $leadNote('active_leases', $a['active_leases']) ?>
                            </td>
                            <td class="cell-num col-mid<?= $lead('leases_created', $a['leases_created']) ?>">
                                <?= $perfCell($num($a['leases_created']), 'agent_written', (int) $a['id'], $a['leases_created']) ?><?= $leadNote('leases_created', $a['leases_created']) ?>
                            </td>
                            <td class="cell-num col-lo<?= $lead('sales_completed', $a['sales_completed']) ?>">
                                <?= $perfCell($num($a['sales_completed']), 'agent_sales', (int) $a['id'], $a['sales_completed']) ?><?= $leadNote('sales_completed', $a['sales_completed']) ?>
                                <?php if ((int) $a['sales_completed'] > 0 && (float) $a['sales_value'] > 0): ?>
                                    <?php /* Contract value, not money received — the two
                                             are different questions and the revenue
                                             columns answer the second one. */ ?>
                                    <div class="tp-code"><?= sanitize(formatCurrency((float) $a['sales_value'])) ?> contracted</div>
                                <?php endif ?>
                            </td>
                            <td class="cell-num tp-money<?= $lead('rental_revenue', $a['rental_revenue']) ?>">
                                <?= $perfCell($money($a['rental_revenue']), 'agent_rental_revenue', (int) $a['id'], $a['rental_revenue']) ?><?= $leadNote('rental_revenue', $a['rental_revenue']) ?>
                            </td>
                            <td class="cell-num col-mid tp-money<?= $lead('sales_revenue', $a['sales_revenue']) ?>">
                                <?= $perfCell($money($a['sales_revenue']), 'agent_sales_revenue', (int) $a['id'], $a['sales_revenue']) ?><?= $leadNote('sales_revenue', $a['sales_revenue']) ?>
                            </td>
                            <td class="cell-num col-lo tp-money<?= $lead('revenue_received', $a['revenue_received']) ?>">
                                <?= $perfCell($money($a['revenue_received']), 'agent_received', (int) $a['id'], $a['revenue_received']) ?><?= $leadNote('revenue_received', $a['revenue_received']) ?>
                            </td>
                            <td class="cell-num tp-money<?= $lead('commission_pending', $a['commission_pending']) ?>">
                                <?= $money($a['commission_pending']) ?><?= $leadNote('commission_pending', $a['commission_pending']) ?>
                            </td>
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
                not agree. A marked figure is the highest in its own column and nothing more:
                no column is weighted into a score, and there is no overall ranking.
            </p>
        </div>
    <?php endif ?>
</div>

<?php $section = [
    'title' => 'How attribution works',
    'desc'  => 'Which record each measure is counted from.',
]; require dirname(__DIR__) . '/_section.php'; ?>

<?php /* Four transparent sources rather than one opaque number — the whole
         of approved decision 6, written where the reader can check it
         against the columns above. */ ?>
<div class="card rcard">
    <div class="card__body">
        <dl class="attrib">
            <div class="attrib__row">
                <dt class="attrib__term">Listings and the active book</dt>
                <dd class="attrib__def">
                    Counted from <strong>the property record's assigned agent</strong>, and from live
                    leases on those properties. Both describe the desk as it stands today and do not
                    move with the reporting period.
                </dd>
            </div>
            <div class="attrib__row">
                <dt class="attrib__term">Leases written</dt>
                <dd class="attrib__def">
                    Counted from <strong>who created the lease record</strong>, within the period. It
                    measures paperwork, not ownership of the property — an agent can write a lease on
                    a colleague's listing.
                </dd>
            </div>
            <div class="attrib__row">
                <dt class="attrib__term">Sales closed</dt>
                <dd class="attrib__def">
                    Counted from <strong>the agent named on the sale</strong>, where the sale has
                    completed and its date falls in the period. A deal with no agent recorded against
                    it is counted by the company and by nobody here.
                </dd>
            </div>
            <div class="attrib__row">
                <dt class="attrib__term">Revenue and commission</dt>
                <dd class="attrib__def">
                    Revenue is <strong>paid payments dated on or before today</strong>, matched either
                    to the agent's own listings or to the person who received the money. Commission is
                    the <strong>pending balance on the commission ledger</strong>, which is a running
                    total and not a figure for this period.
                </dd>
            </div>
        </dl>
    </div>
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
