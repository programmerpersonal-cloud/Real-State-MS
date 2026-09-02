<?php
/**
 * The sale register — one row per deal.
 *
 * Two money columns sit side by side and mean different things, which is the
 * whole reason both are here. <strong>Value</strong> is the contract amount:
 * what the deal is written at. <strong>Collected</strong> is cash actually
 * received against it, under the approved revenue definition. A completed
 * sale with nothing collected is a real and important state, and a report
 * showing only the first would report it as money in the bank.
 *
 * On the current data every row collects zero, because no payment has ever
 * been recorded with a sale reference. That is the truth rather than a gap in
 * the query, and the footnote says so.
 *
 * Expects: $register, $registerTotal, $registerPage, $registerPages,
 *          $window, $filters, $compare
 *
 * Every local in this file is prefixed. A partial pulled in with require
 * shares the including view's variable scope; see the note in _kpi.php.
 */
$srRows     = $register ?? [];
$srFiltered = reportFilterCount($filters) > 0;
$srCarry    = !empty($compare) ? ['compare' => '1'] : [];
$srToday    = $window['today'];

$srValue = $srCollected = 0.0;
foreach ($srRows as $srR) {
    $srValue     += (float) $srR['sale_amount'];
    $srCollected += (float) $srR['collected'];
}

$srTone = ['completed' => 'success', 'pending' => 'warning', 'cancelled' => 'muted'];
?>
<div class="table-card">
    <div class="table-head">
        <h4 class="table-head__title">Sale register</h4>
        <span class="table-head__note">
            <?= number_format((int) $registerTotal) ?>
            <?= (int) $registerTotal === 1 ? 'deal' : 'deals' ?>
            dated in <?= sanitize($window['label']) ?>, newest first
        </span>
    </div>

    <?php if (!$srRows): ?>
        <?= uiEmptyState([
            'icon'  => 'bi-briefcase',
            'title' => 'No sale recorded in this period',
            'desc'  => $srFiltered
                ? 'No sale matching the current filters carries a date inside the selected period.'
                : 'No sale carries a date inside the selected period. Widen the period, or '
                . 'check that sales in scope have a sale date recorded.',
            'actions' => $srFiltered ? [[
                'label' => 'Clear filters', 'icon' => 'bi-arrow-counterclockwise',
                'class' => 'btn--outline',
                'url'   => reportUrl($window, [], ['tab' => 'sales'] + $srCarry),
            ]] : [],
        ]) ?>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th scope="col">Sale date</th>
                        <th scope="col">Property</th>
                        <th scope="col" class="col-mid">Buyer</th>
                        <th scope="col" class="col-lo">Category</th>
                        <th scope="col">Status</th>
                        <th scope="col" class="col-mid">Agent</th>
                        <th scope="col" class="cell-num">Value</th>
                        <th scope="col" class="cell-num col-lo">Collected</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($srRows as $srR): ?>
                        <?php
                        $srStatus = (string) $srR['status'];
                        $srFuture = $srR['sale_date'] > $srToday;
                        ?>
                        <tr>
                            <td class="pr-date">
                                <?= sanitize(formatDate((string) $srR['sale_date'])) ?>
                                <?php if ($srFuture && $srStatus === 'completed'): ?>
                                    <?php /* A completed sale dated ahead has not completed
                                             yet, so it is held out of the completed totals
                                             above. Marked here so the row and the KPI cannot
                                             be read as contradicting each other. */ ?>
                                    <span class="pr-flag pr-flag--mismatch"
                                          title="Dated after today, so it is not counted in completed sales.">
                                        Dated ahead
                                    </span>
                                <?php endif ?>
                            </td>
                            <td>
                                <a class="tp-name" href="<?= sanitize(APP_URL . '/index.php?page=properties&action=show&id=' . (int) $srR['property_id']) ?>">
                                    <?= sanitize((string) $srR['property_title']) ?>
                                </a>
                                <div class="tp-code tp-code--id"><?= sanitize((string) $srR['sale_code']) ?></div>
                            </td>
                            <td class="col-mid">
                                <?= !empty($srR['buyer_name'])
                                    ? sanitize((string) $srR['buyer_name'])
                                    : '<span class="text-subtle">—</span>' ?>
                            </td>
                            <td class="col-lo">
                                <i class="bi <?= sanitize(categoryIcon((string) $srR['category'])) ?> tp-cat-icon" aria-hidden="true"></i>
                                <?= sanitize(categoryLabel((string) $srR['category'])) ?>
                            </td>
                            <td>
                                <span class="status status--<?= sanitize($srTone[$srStatus] ?? 'muted') ?>">
                                    <span class="status__dot" aria-hidden="true"></span>
                                    <?= sanitize(uiLabel($srStatus)) ?>
                                </span>
                            </td>
                            <td class="col-mid">
                                <?php if (!empty($srR['agent_name'])): ?>
                                    <?= sanitize((string) $srR['agent_name']) ?>
                                <?php else: ?>
                                    <span class="text-subtle" title="No agent is recorded against this sale.">Unassigned</span>
                                <?php endif ?>
                            </td>
                            <td class="cell-num tp-money"><?= sanitize(formatCurrency((float) $srR['sale_amount'])) ?></td>
                            <td class="cell-num col-lo tp-money">
                                <?php /* A real zero: the deal exists and nothing has been
                                         paid against it. Not an em dash, which would read
                                         as "not applicable". */ ?>
                                <?= sanitize(formatCurrency((float) $srR['collected'])) ?>
                            </td>
                        </tr>
                    <?php endforeach ?>
                </tbody>
                <tfoot>
                    <tr>
                        <th scope="row" colspan="6">Total across <?= count($srRows) ?>
                            <?= count($srRows) === 1 ? 'deal' : 'deals' ?> shown</th>
                        <td class="cell-num tp-money"><?= sanitize(formatCurrency($srValue)) ?></td>
                        <td class="cell-num col-lo tp-money"><?= sanitize(formatCurrency($srCollected)) ?></td>
                    </tr>
                </tfoot>
            </table>
        </div>

        <?php if ((int) $registerPages > 1): ?>
            <?php
            /* Unprefixed against this folder's convention because they are the
               pagination component's published contract. */
            $page       = (int) $registerPage;
            $totalPages = (int) $registerPages;
            require VIEWS_PATH . '/components/pagination.php';
            ?>
        <?php endif ?>

        <div class="table-foot">
            <p class="table-foot__note">
                <strong>Value</strong> is the contract amount the deal is written at, whatever
                its status. <strong>Collected</strong> is cash received against it under the
                approved revenue definition — the two are never added.
                <?php if ($srCollected <= 0 && $srValue > 0): ?>
                    Nothing has been collected against any of these deals: no payment in the
                    ledger carries a sale reference.
                <?php endif ?>
            </p>
        </div>
    <?php endif ?>
</div>
