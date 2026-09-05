<?php
/**
 * Future-dated payments.
 *
 * These records started the whole reporting rebuild. The Phase 0 audit found
 * a $500 payment marked paid and dated twenty-four days into the future,
 * being reported as money already in the bank; removing it moved the
 * year-to-date figure from $4,700 to $4,200. The approved revenue definition
 * now caps every total at today, and this panel is where the excluded records
 * are shown so the exclusion is visible rather than silent.
 *
 * What they are: stored payments with a date still ahead.
 *
 * What they are *not*, and the wording matters because all three would be
 * read as accusations: they are not overdue, not failed, and not invalid.
 * Their status is `paid`. Somebody entered a date in the future, which is
 * either a post-dated arrangement or a typo, and either way it is a question
 * for whoever keeps the ledger rather than a fault in the report.
 *
 * Detection is CoreAnalytics::futureDatedPayments(), which uses the same
 * predicate as Phase 4A's futureDatedExcluded() — one rule, counted in one
 * place, listed in another.
 *
 * Expects: $futureDated, $futureRecords
 *
 * Every local in this file is prefixed. A partial pulled in with require
 * shares the including view's variable scope; see the note in _kpi.php.
 */
$fdRows = $futureRecords ?? [];
$fdFrom = null;
$fdTo   = null;
foreach ($fdRows as $fdR) {
    $fdDate = (string) $fdR['payment_date'];
    if ($fdFrom === null || $fdDate < $fdFrom) { $fdFrom = $fdDate; }
    if ($fdTo === null || $fdDate > $fdTo) { $fdTo = $fdDate; }
}
?>
<div class="table-card fd">
    <div class="table-head">
        <h4 class="table-head__title">
            <i class="bi bi-calendar-plus fd__icon" aria-hidden="true"></i>
            Future-dated payments
        </h4>
        <span class="table-head__note">
            <?= number_format((int) $futureDated['count']) ?>
            <?= (int) $futureDated['count'] === 1 ? 'record' : 'records' ?>
            worth <?= sanitize(formatCurrency((float) $futureDated['amount'])) ?>
            <?php if ($fdFrom !== null): ?>
                · dated <?= sanitize(formatDate($fdFrom)) ?><?= $fdTo !== $fdFrom ? ' to ' . sanitize(formatDate($fdTo)) : '' ?>
            <?php endif ?>
        </span>
    </div>

    <div class="fd__lede">
        <p>
            These payments are recorded in the ledger with a date that has not arrived
            yet. They are held out of collected revenue until it does — every revenue
            figure in these reports is capped at today. They are
            <strong>not overdue, not failed and not invalid</strong>: their status is
            paid and the only unusual thing about them is the date.
        </p>
    </div>

    <?php if ($fdRows): ?>
        <div class="table-wrap">
            <table class="table">
                <caption class="sr-only">Paid payments dated after today, held out of collected revenue until their date arrives.</caption>
                <thead>
                    <tr>
                        <th scope="col">Dated</th>
                        <th scope="col">Reference</th>
                        <th scope="col" class="col-mid">Property</th>
                        <th scope="col" class="col-lo">Payer</th>
                        <th scope="col">Type</th>
                        <th scope="col">Status</th>
                        <th scope="col" class="cell-num">Amount</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($fdRows as $fdR): ?>
                        <tr>
                            <td class="pr-date"><?= sanitize(formatDate((string) $fdR['payment_date'])) ?></td>
                            <td>
                                <span class="hash"><?= sanitize((string) $fdR['payment_code']) ?></span>
                                <div class="tp-code"><?= sanitize(uiLabel((string) $fdR['reference_type'])) ?></div>
                            </td>
                            <td class="col-mid">
                                <?= !empty($fdR['property_title'])
                                    ? sanitize((string) $fdR['property_title'])
                                    : '<span class="text-subtle">No property recorded</span>' ?>
                            </td>
                            <td class="col-lo">
                                <?= !empty($fdR['customer_name'])
                                    ? sanitize((string) $fdR['customer_name'])
                                    : '<span class="text-subtle">—</span>' ?>
                            </td>
                            <td><?= sanitize(uiLabel((string) $fdR['payment_type'])) ?></td>
                            <td><?= uiStatus((string) ($fdR['status'] ?? 'pending')) ?></td>
                            <td class="cell-num tp-money"><?= sanitize(formatCurrency((float) $fdR['amount'])) ?></td>
                        </tr>
                    <?php endforeach ?>
                </tbody>
            </table>
        </div>

        <div class="table-foot">
            <p class="table-foot__note">
                Listed regardless of the selected period — a payment dated three months
                out is an item to look at today, whichever window is on screen. Nothing
                here is changed automatically.
            </p>
        </div>
    <?php endif ?>
</div>
