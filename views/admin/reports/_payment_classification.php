<?php
/**
 * Payment classification — type against reference.
 *
 * Two columns answering different questions: `payment_type` is what kind of
 * money somebody said this was, `reference_type` is which contract it was
 * actually taken against. Approved decision 3 settles which one revenue
 * believes — the reference, because it is written by the code that creates
 * the payment rather than chosen on a form — and this table is where the
 * disagreements become visible instead of being quietly resolved.
 *
 * A table rather than a chart, deliberately. The useful output is "these
 * three rows are fine and this one is not", which is a list; drawn as a
 * matrix of coloured cells it would look more analytical and say less. Of the
 * eighteen possible combinations only the ones that occur are shown, because
 * a grid of fifteen empty cells is not information.
 *
 * Expects: $classification (from CoreAnalytics::paymentClassificationMatrix())
 *
 * Every local in this file is prefixed. A partial pulled in with require
 * shares the including view's variable scope; see the note in _kpi.php.
 */
$pcRows     = $classification ?? [];
$pcRecords  = 0;
$pcAmount   = 0.0;
$pcFlagged  = 0;
foreach ($pcRows as $pcR) {
    $pcRecords += (int) $pcR['records'];
    $pcAmount  += (float) $pcR['amount'];
    if ($pcR['mismatch']) {
        $pcFlagged += (int) $pcR['records'];
    }
}
?>
<section class="card rcard" aria-labelledby="pclass-title">
    <div class="card__header">
        <div class="rcard__titles">
            <h3 class="card__title" id="pclass-title">Classification</h3>
            <p class="card__subtitle">How each payment is typed, against the contract it names</p>
        </div>
    </div>

    <div class="card__body card__body--flush">
        <?php if (!$pcRows): ?>
            <?= uiEmptyState([
                'icon'  => 'bi-tags',
                'title' => 'No payments to classify',
                'desc'  => 'No payment was dated in this period.',
            ]) ?>
        <?php else: ?>
            <div class="table-wrap">
                <table class="table pclass__table">
                    <thead>
                        <tr>
                            <th scope="col">Payment type</th>
                            <th scope="col">Taken against</th>
                            <th scope="col" class="cell-num">Records</th>
                            <th scope="col" class="cell-num">Amount</th>
                            <th scope="col">Reading</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($pcRows as $pcR): ?>
                            <tr>
                                <th scope="row"><?= sanitize((string) $pcR['type_label']) ?></th>
                                <td><?= sanitize((string) $pcR['ref_label']) ?></td>
                                <td class="cell-num"><?= number_format((int) $pcR['records']) ?></td>
                                <td class="cell-num tp-money"><?= sanitize(formatCurrency((float) $pcR['amount'])) ?></td>
                                <td>
                                    <?php if ($pcR['mismatch']): ?>
                                        <?php /* Named as a mismatch and nothing stronger. The
                                                 record is not invalid and the money is not
                                                 missing — the two labels on it disagree, and
                                                 revenue counts it by the reference. */ ?>
                                        <span class="status status--warning">
                                            <span class="status__dot" aria-hidden="true"></span>
                                            Mismatch
                                        </span>
                                    <?php else: ?>
                                        <span class="status status--muted">
                                            <span class="status__dot" aria-hidden="true"></span>
                                            Consistent
                                        </span>
                                    <?php endif ?>
                                </td>
                            </tr>
                        <?php endforeach ?>
                    </tbody>
                    <tfoot>
                        <tr>
                            <th scope="row" colspan="2">Total</th>
                            <td class="cell-num"><?= number_format($pcRecords) ?></td>
                            <td class="cell-num tp-money"><?= sanitize(formatCurrency($pcAmount)) ?></td>
                            <td>
                                <?= $pcFlagged > 0
                                    ? sanitize(number_format($pcFlagged) . ' flagged')
                                    : '<span class="text-subtle">None flagged</span>' ?>
                            </td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            <p class="rcard__footnote">
                A mismatch means the payment's own type contradicts the contract it was
                recorded against — a sale payment filed under a tenancy, for instance.
                Revenue counts it by the contract, and the row is left exactly as it was
                found. Nothing here is corrected automatically.
            </p>
        <?php endif ?>
    </div>
</section>
