<?php
/**
 * The transaction table.
 *
 * The most operationally useful thing on the report: the individual records,
 * newest first, with the two conditions that matter marked on the row rather
 * than left to be inferred — a payment dated ahead, and a payment whose type
 * disagrees with its contract.
 *
 * Sorted by payment date descending.
 *
 * What is deliberately *not* here: future-dated records. Every windowed query
 * in this module ends at `to_capped`, which is min(period end, today) — the
 * cap the comparison logic depends on, since a period still running has to be
 * measured against an equal number of elapsed days. A payment dated after
 * today therefore cannot appear in any windowed result, and an earlier draft
 * of this file carried a "dated ahead" row badge that was provably unreachable
 * for that reason.
 *
 * Rather than uncap this one table — which would make it disagree with the
 * record count above it, the one thing a transaction report must never do —
 * those records get their own panel, which is not window-bounded and always
 * shows them. The note under this table says so, so nobody concludes the
 * ledger is hiding them.
 *
 * Paginated through the application's own paginateUrl(), so the pager here
 * behaves like the pager on every register in the system and carries the
 * period and filters with it.
 *
 * Expects: $records, $recordTotal, $recordPage, $recordPages, $recordPerPage,
 *          $window, $filters, $compare
 *
 * Every local in this file is prefixed. A partial pulled in with require
 * shares the including view's variable scope; see the note in _kpi.php.
 */
$prRows     = $records ?? [];
$prFiltered = reportFilterCount($filters) > 0;
$prCarry    = !empty($compare) ? ['compare' => '1'] : [];

/* The same rule reportPaymentMismatches() applies, so a row flagged here is a
   row counted there. Written once as a closure rather than repeated per row. */
$prMismatch = static function (array $prR): bool {
    $prType = (string) $prR['payment_type'];
    $prRef  = (string) $prR['reference_type'];

    return ($prRef === 'lease' && $prType === 'sale')
        || ($prRef === 'sale' && $prType === 'rent')
        || ($prRef === 'reservation' && in_array($prType, ['rent', 'sale'], true));
};
?>
<div class="table-card">
    <div class="table-head">
        <h4 class="table-head__title">Payment records</h4>
        <span class="table-head__note">
            <?= number_format((int) $recordTotal) ?>
            <?= (int) $recordTotal === 1 ? 'transaction' : 'transactions' ?>
            dated in <?= sanitize($window['label']) ?>, newest first
        </span>
    </div>

    <?php if (!$prRows): ?>
        <?= uiEmptyState([
            'icon'  => 'bi-receipt',
            'title' => 'No payments in this period',
            'desc'  => $prFiltered
                ? 'No payment matching the current filters is dated inside the selected period.'
                : 'No payment carries a date inside the selected period. Widen the period, '
                . 'or check that payments in scope have a payment date recorded.',
            'actions' => $prFiltered ? [[
                'label' => 'Clear filters',
                'icon'  => 'bi-arrow-counterclockwise',
                'class' => 'btn--outline',
                'url'   => reportUrl($window, [], ['tab' => 'payments'] + $prCarry),
            ]] : [],
        ]) ?>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th scope="col">Date</th>
                        <th scope="col">Reference</th>
                        <th scope="col" class="col-mid">Property</th>
                        <th scope="col" class="col-lo">Payer</th>
                        <th scope="col">Type</th>
                        <th scope="col" class="col-mid">Method</th>
                        <th scope="col">Status</th>
                        <th scope="col" class="col-lo">Received by</th>
                        <th scope="col" class="cell-num">Amount</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($prRows as $prR): ?>
                        <?php $prFlag = $prMismatch($prR); ?>
                        <tr>
                            <td class="pr-date">
                                <?= sanitize(formatDate((string) $prR['payment_date'])) ?>
                            </td>
                            <td>
                                <span class="hash"><?= sanitize((string) $prR['payment_code']) ?></span>
                                <div class="tp-code">
                                    <?= sanitize(uiLabel((string) $prR['reference_type'])) ?>
                                    #<?= (int) $prR['reference_id'] ?>
                                </div>
                            </td>
                            <td class="col-mid">
                                <?php if (!empty($prR['property_title'])): ?>
                                    <a class="tp-name" href="<?= sanitize(APP_URL . '/index.php?page=properties&action=show&id=' . (int) $prR['property_id']) ?>">
                                        <?= sanitize((string) $prR['property_title']) ?>
                                    </a>
                                <?php else: ?>
                                    <span class="text-subtle">No property recorded</span>
                                <?php endif ?>
                            </td>
                            <td class="col-lo">
                                <?= !empty($prR['customer_name'])
                                    ? sanitize((string) $prR['customer_name'])
                                    : '<span class="text-subtle">—</span>' ?>
                            </td>
                            <td>
                                <?= sanitize(uiLabel((string) $prR['payment_type'])) ?>
                                <?php if ($prFlag): ?>
                                    <span class="pr-flag pr-flag--mismatch"
                                          title="This type disagrees with the contract the payment was taken against.">
                                        Mismatch
                                    </span>
                                <?php endif ?>
                            </td>
                            <td class="col-mid">
                                <?= $prR['payment_method'] !== null && $prR['payment_method'] !== ''
                                    ? sanitize(uiLabel((string) $prR['payment_method']))
                                    : '<span class="text-subtle">Not recorded</span>' ?>
                            </td>
                            <td><?= uiStatus((string) ($prR['status'] ?? 'pending')) ?></td>
                            <td class="col-lo">
                                <?= !empty($prR['received_by_name'])
                                    ? sanitize((string) $prR['received_by_name'])
                                    : '<span class="text-subtle">—</span>' ?>
                            </td>
                            <td class="cell-num tp-money"><?= sanitize(formatCurrency((float) $prR['amount'])) ?></td>
                        </tr>
                    <?php endforeach ?>
                </tbody>
            </table>
        </div>

        <?php if ((int) $recordPages > 1): ?>
            <?php
            /* The application's own pager, so it carries the period, the
               filters and the comparison toggle through paginateUrl() exactly
               as every register in the system does.

               These two are unprefixed against the convention in this folder,
               because they are the component's published contract: it reads
               $page and $totalPages, and renaming them here would simply mean
               it rendered nothing. Set immediately before the require and used
               by nothing after it. */
            $page       = (int) $recordPage;
            $totalPages = (int) $recordPages;
            require VIEWS_PATH . '/components/pagination.php';
            ?>
        <?php endif ?>

        <div class="table-foot">
            <p class="table-foot__note">
                Showing <?= number_format(count($prRows)) ?> of
                <?= number_format((int) $recordTotal) ?> records dated on or before today.
                <strong>Payments dated after today are not listed here</strong> — every
                figure in these reports stops at today, so those records appear in the
                Future-dated panel above instead, where they are not window-bounded.
            </p>
        </div>
    <?php endif ?>
</div>
