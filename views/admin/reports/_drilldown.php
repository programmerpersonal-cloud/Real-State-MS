<?php
/**
 * The drill-down panel — which records produced that figure.
 *
 * Rendered twice from one file: on its own into the drawer, and inside
 * drilldown.php as a full page when scripting is off or somebody opened the
 * link directly. That is deliberate. A panel that only exists inside a
 * JavaScript drawer is not a URL, and a figure somebody is questioning is
 * exactly the thing they want to send to whoever has to answer for it.
 *
 * Three things are always on it, in this order:
 *
 *   the figure     the total this panel adds up to, printed beside the count,
 *                  so a reader can check it against the tile they clicked
 *                  without leaving the page
 *   the definition the sentence the catalogue carries for this metric. The
 *                  question a drill-down usually answers is not "which rows"
 *                  but "why is that number what it is", and half the time the
 *                  answer is the definition
 *   the records    the rows themselves, paged
 *
 * The reconciliation line is the point of the whole feature. A total here
 * that disagrees with the tile it was opened from is a bug worth finding, and
 * printing both is what makes it findable by anyone rather than only by
 * somebody with database access.
 *
 * Expects: $spec, $result, $metric, $drillKey, $keyLabel, $window, $filters,
 *          $reportTab, $compare
 *
 * Every local is prefixed. A partial pulled in with require shares the
 * including view's variable scope; see the note in _kpi.php.
 */
$ddSpec   = $spec ?? null;
$ddResult = $result ?? null;
$ddTab    = $reportTab ?? 'overview';
$ddMeta   = ReportController::TABS[$ddTab] ?? ReportController::TABS['overview'];
$ddKey    = (string) ($drillKey ?? '');
$ddCarry  = !empty($compare) ? ['compare' => '1'] : [];
?>

<?php if ($ddSpec === null || $ddResult === null): ?>

    <?php /* Not a failure and not an empty table. The metric asked for is not
             one this report knows how to trace to records, and saying so is a
             different statement from "there are none". §12. */ ?>
    <div class="drill" data-drill-panel>
        <header class="drill__head">
            <div class="drill__titles">
                <p class="drill__eyebrow"><?= sanitize($ddMeta['label']) ?></p>
                <h2 class="drill__title" id="drillTitle">Drill-down unavailable</h2>
            </div>
        </header>
        <div class="drill__body">
            <?= uiEmptyState([
                'icon'  => 'bi-question-circle',
                'title' => 'This figure cannot be traced to records',
                'desc'  => 'Either the metric is not one this report can break down, or the link '
                         . 'was written before it existed. Every figure that can honestly be '
                         . 'traced to rows is clickable on the report itself.',
                'actions' => [[
                    'label' => 'Back to the ' . $ddMeta['label'] . ' report',
                    'icon'  => 'bi-arrow-left',
                    'class' => 'btn--outline',
                    'url'   => reportUrl($window, $filters, ['tab' => $ddTab] + $ddCarry),
                ]],
            ]) ?>
        </div>
    </div>

<?php else: ?>

    <?php
    $ddRows  = $ddResult['rows'];
    $ddTotal = (int) $ddResult['total'];
    $ddMoney = $ddResult['unit'] === 'money';

    /* The heading names the slice where there is one: "Revenue by stream"
       alone says less than "Revenue by stream — Rentals", and the reader
       clicked the second thing. */
    $ddHeading = $ddResult['label'] . ($keyLabel !== '' ? ' — ' . $keyLabel : '');

    /* Pagination keeps every parameter it arrived with. A drill-down that
       reset the period on page two would be reporting a different figure
       from the one on page one, which is the sort of bug nobody finds for a
       year. */
    $ddPageUrl = static fn(int $ddP): string => reportDrillUrl(
        $window, $filters, $ddTab, (string) $metric, $ddKey, ['dp' => $ddP > 1 ? $ddP : null] + $ddCarry
    );

    $ddFilters = reportFilterCount($filters);
    ?>

    <div class="drill" data-drill-panel>
        <header class="drill__head">
            <div class="drill__titles">
                <p class="drill__eyebrow">
                    <?= sanitize($ddMeta['label']) ?> ·
                    <?= sanitize($window['label']) ?>
                    <?php if ($ddFilters > 0): ?>
                        · <?= (int) $ddFilters ?> <?= $ddFilters === 1 ? 'filter' : 'filters' ?>
                    <?php endif ?>
                </p>
                <h2 class="drill__title" id="drillTitle"><?= sanitize($ddHeading) ?></h2>
            </div>

            <?php /* The reconciliation line. Both halves are printed because
                     either one alone can be the finding: a count with no money
                     hides an amount that does not add up, and money with no
                     count hides a duplicated row. */ ?>
            <p class="drill__figure">
                <span class="drill__figure-value">
                    <?= $ddMoney ? sanitize(formatCurrency((float) $ddResult['amount'])) : number_format($ddTotal) ?>
                </span>
                <span class="drill__figure-note">
                    <?= number_format($ddTotal) ?>
                    <?= $ddTotal === 1 ? 'record' : 'records' ?>
                    <?= $ddMoney ? 'totalling this amount' : 'in scope' ?>
                </span>
            </p>
        </header>

        <p class="drill__explain"><?= sanitize($ddResult['explain']) ?></p>

        <div class="drill__body">
            <?php if (!$ddRows): ?>

                <?php /* A metric with no rows behind it. Not an error — the
                         figure is nought and this is what nought looks like
                         when you ask it to show its working. */ ?>
                <?= uiEmptyState([
                    'icon'  => 'bi-inbox',
                    'title' => 'No records match this metric',
                    'desc'  => $ddFilters > 0
                        ? 'Nothing in this period matches, under the ' . $ddFilters
                          . ($ddFilters === 1 ? ' filter' : ' filters') . ' currently applied.'
                        : 'Nothing in this period matches. The figure this panel was opened from '
                          . 'is genuinely nought rather than unavailable.',
                ]) ?>

            <?php else: ?>

                <div class="table-wrap">
                    <?php
                    /* One table per record family. The column sets are the
                       ones the report's own tables use, so a row looks the
                       same wherever a reader meets it. */
                    require __DIR__ . '/_drill_table.php';
                    ?>
                </div>

                <?php if ($ddResult['pages'] > 1): ?>
                    <nav class="drill__pager" aria-label="Drill-down pages">
                        <?php if ($ddResult['page'] > 1): ?>
                            <a class="btn btn--outline btn--sm" data-drill
                               href="<?= sanitize($ddPageUrl($ddResult['page'] - 1)) ?>">
                                <i class="bi bi-chevron-left" aria-hidden="true"></i>
                                <span>Previous</span>
                            </a>
                        <?php endif ?>

                        <span class="drill__pager-state">
                            Page <?= (int) $ddResult['page'] ?> of <?= (int) $ddResult['pages'] ?>
                        </span>

                        <?php if ($ddResult['page'] < $ddResult['pages']): ?>
                            <a class="btn btn--outline btn--sm" data-drill
                               href="<?= sanitize($ddPageUrl($ddResult['page'] + 1)) ?>">
                                <span>Next</span>
                                <i class="bi bi-chevron-right" aria-hidden="true"></i>
                            </a>
                        <?php endif ?>
                    </nav>
                <?php endif ?>

            <?php endif ?>
        </div>

        <footer class="drill__foot">
            <?php if ($ddRows): ?>
                <?php /* The Phase 9 export engine, handed this slice instead
                         of the whole report. Same three writers, same
                         masthead, same period and filter statement — a
                         drill-down is a report with one table on it, which
                         is a shape the document model already described. */ ?>
                <div class="drill__exports">
                    <span class="drill__exports-label">Export these records</span>
                    <?php foreach (['pdf' => 'PDF', 'xlsx' => 'Excel', 'csv' => 'CSV'] as $ddFmt => $ddName): ?>
                        <a class="btn btn--outline btn--sm"
                           href="<?= sanitize(reportUrl($window, $filters, [
                                    'tab'    => $ddTab,
                                    'action' => 'export',
                                    'drill'  => '1',
                                    'metric' => (string) $metric,
                                    'key'    => $ddKey === '' ? null : $ddKey,
                                    'format' => $ddFmt,
                                ] + $ddCarry)) ?>"><?= sanitize($ddName) ?></a>
                    <?php endforeach ?>
                </div>
            <?php else: ?>
                <p class="drill__foot-note">
                    Nothing to export — this metric has no records behind it.
                </p>
            <?php endif ?>

            <a class="btn btn--ghost btn--sm"
               href="<?= sanitize(reportUrl($window, $filters, ['tab' => $ddTab] + $ddCarry)) ?>">
                <i class="bi bi-arrow-left" aria-hidden="true"></i>
                <span>Back to the report</span>
            </a>
        </footer>
    </div>

<?php endif ?>
