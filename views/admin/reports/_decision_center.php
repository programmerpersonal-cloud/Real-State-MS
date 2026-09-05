<?php
/**
 * The Executive Decision Center — what happened, why it matters, what next.
 *
 * The Overview already answers the first question well. What it could not do
 * was tell a reader which three of its twenty accurate figures are the story
 * today, and that is what this band is for. Four columns, in the order the
 * questions actually get asked:
 *
 *   Performance  what is going well, stated as measurements
 *   Attention    what somebody has to deal with
 *   Risk         where the records contradict each other
 *   Next         the actionable half of the two middle columns, worst first
 *
 * Everything in it comes from ReportIntelligence, which reads the payload the
 * report already built. No figure here is computed in this file, and none is
 * computed twice: a finding that disagreed with the tile below it would be
 * worse than no Decision Center at all.
 *
 * Three deliberate restraints:
 *
 *  · Severity is printed as a word, not carried by colour. The tone is a
 *    hairline and a label; a reader who cannot separate the tones loses
 *    nothing, which is the only way a status system is worth having.
 *
 *  · An empty column says so. "Nothing needs attention" is a finding, and a
 *    panel that invented an alert to fill the space would teach people to
 *    stop reading it.
 *
 *  · Every actionable line is a Phase 10 drill-down link — the same URL the
 *    tile below would open, carrying the same period and filters. Nothing
 *    here is a second route into the records.
 *
 * Expects: $decision (from ReportIntelligence::assess()), $window, $filters
 *
 * Every local in this file is prefixed. A partial pulled in with require
 * shares the including view's variable scope; see the note in _kpi.php.
 */
$dcData = $decision ?? null;
if (!$dcData) {
    return;
}

/* Severity to design token. Named here rather than in the model, because
   which token carries "warning" is a presentation question and the rules
   should not have an opinion about it. */
$dcTone = [
    ReportIntelligence::POSITIVE  => 'success',
    ReportIntelligence::NEUTRAL   => 'info',
    ReportIntelligence::ATTENTION => 'info',
    ReportIntelligence::WARNING   => 'warning',
    ReportIntelligence::CRITICAL  => 'danger',
];

/* One finding, as a row. Used by all four columns so a finding looks the
   same wherever it appears. */
$dcRow = static function (array $dcF) use ($dcTone): void {
    $dcT = $dcTone[$dcF['severity']] ?? 'info';
    ?>
    <li class="dcitem dcitem--<?= sanitize($dcT) ?>">
        <div class="dcitem__head">
            <span class="dcitem__title"><?= sanitize($dcF['title']) ?></span>
            <span class="dcitem__value"><?= sanitize($dcF['value']) ?></span>
        </div>

        <p class="dcitem__text"><?= sanitize($dcF['text']) ?></p>

        <div class="dcitem__foot">
            <?php /* The severity in words. Colour is the second channel here,
                     never the only one. */ ?>
            <span class="dcitem__sev dcitem__sev--<?= sanitize($dcT) ?>">
                <span class="dcitem__dot" aria-hidden="true"></span>
                <?= sanitize(ucfirst($dcF['severity'])) ?>
            </span>

            <?php if ($dcF['records'] !== null): ?>
                <span class="dcitem__count">
                    <?= number_format((int) $dcF['records']) ?>
                    <?= (int) $dcF['records'] === 1 ? 'record' : 'records' ?>
                </span>
            <?php endif ?>

            <?php if ($dcF['detail'] !== null): ?>
                <span class="dcitem__detail"><?= sanitize($dcF['detail']) ?></span>
            <?php endif ?>

            <?php if ($dcF['drill'] !== null): ?>
                <a class="dcitem__link" data-drill href="<?= sanitize($dcF['drill']) ?>">
                    <span>See the records</span>
                    <i class="bi bi-arrow-right-short" aria-hidden="true"></i>
                    <span class="sr-only">for <?= sanitize($dcF['title']) ?></span>
                </a>
            <?php endif ?>
        </div>
    </li>
    <?php
};

/* A column, with its own honest empty state. */
$dcColumn = static function (
    string $dcId,
    string $dcHeading,
    string $dcLead,
    array $dcItems,
    string $dcEmpty,
    string $dcIcon
) use ($dcRow): void {
    ?>
    <section class="dccol" aria-labelledby="<?= sanitize($dcId) ?>">
        <header class="dccol__head">
            <h4 class="dccol__title" id="<?= sanitize($dcId) ?>">
                <i class="bi <?= sanitize($dcIcon) ?> dccol__icon" aria-hidden="true"></i>
                <?= sanitize($dcHeading) ?>
                <?php if ($dcItems): ?>
                    <span class="dccol__count"><?= count($dcItems) ?></span>
                <?php endif ?>
            </h4>
            <p class="dccol__lead"><?= sanitize($dcLead) ?></p>
        </header>

        <?php if (!$dcItems): ?>
            <p class="dccol__empty"><?= sanitize($dcEmpty) ?></p>
        <?php else: ?>
            <ul class="dccol__list">
                <?php foreach ($dcItems as $dcF) { $dcRow($dcF); } ?>
            </ul>
        <?php endif ?>
    </section>
    <?php
};
?>

<?php $section = [
    'title' => 'Executive decision center',
    'desc'  => 'What is performing, what needs attention, and what to look at next — '
             . 'every line derived from the figures in this report.',
]; require __DIR__ . '/_section.php'; ?>

<div class="dc">

    <?php /* The headline. One sentence saying whether anything is wrong,
             because that is the question the band exists to answer and a
             reader should not have to count four columns to find out. */ ?>
    <div class="dc__summary" role="status">
        <?php if ($dcData['clean']): ?>
            <span class="dc__summary-mark dc__summary-mark--clear" aria-hidden="true">
                <i class="bi bi-check2-circle"></i>
            </span>
            <p class="dc__summary-text">
                <strong>No critical issues detected.</strong>
                All monitored areas are currently clear for
                <?= sanitize($window['label']) ?><?php if (reportFilterCount($filters) > 0): ?>
                    under the filters applied<?php endif ?>.
            </p>
        <?php else: ?>
            <span class="dc__summary-mark" aria-hidden="true">
                <i class="bi bi-clipboard-check"></i>
            </span>
            <p class="dc__summary-text">
                <strong>
                    <?= (int) $dcData['counts']['attention'] ?>
                    <?= $dcData['counts']['attention'] === 1 ? 'item needs' : 'items need' ?>
                    attention<?php if ($dcData['counts']['risk'] > 0): ?>,
                        and <?= (int) $dcData['counts']['risk'] ?>
                        <?= $dcData['counts']['risk'] === 1
                            ? 'record set contradicts itself'
                            : 'record sets contradict themselves' ?><?php endif ?>.
                </strong>
                Each one is measured over <?= sanitize($window['label']) ?> and opens the records behind it.
            </p>
        <?php endif ?>
    </div>

    <div class="dc__grid">
        <?php
        $dcColumn(
            'dcPerformance',
            'Performance',
            'Measured, not scored.',
            $dcData['performance'],
            'Nothing in this period can be reported as performance yet.',
            'bi-graph-up-arrow'
        );

        $dcColumn(
            'dcAttention',
            'Attention',
            'Operational, and somebody\'s job today.',
            $dcData['attention'],
            'Nothing needs attention in this period.',
            'bi-exclamation-circle'
        );

        $dcColumn(
            'dcRisk',
            'Risk',
            'Where the records disagree with each other.',
            $dcData['risk'],
            'No record set contradicts itself.',
            'bi-shield-exclamation'
        );
        ?>

        <?php /* Not a fifth set of rules — the actionable half of the two
                 columns beside it, worst first. A reader who wants one list
                 rather than three reads this one. */ ?>
        <section class="dccol dccol--action" aria-labelledby="dcAction">
            <header class="dccol__head">
                <h4 class="dccol__title" id="dcAction">
                    <i class="bi bi-list-check dccol__icon" aria-hidden="true"></i>
                    Look at next
                    <?php if ($dcData['action']): ?>
                        <span class="dccol__count"><?= count($dcData['action']) ?></span>
                    <?php endif ?>
                </h4>
                <p class="dccol__lead">The above, ranked by severity.</p>
            </header>

            <?php if (!$dcData['action']): ?>
                <p class="dccol__empty">Nothing is waiting on a decision.</p>
            <?php else: ?>
                <ol class="dcnext">
                    <?php foreach ($dcData['action'] as $dcI => $dcF): ?>
                        <li class="dcnext__item">
                            <a class="dcnext__link" data-drill href="<?= sanitize((string) $dcF['drill']) ?>">
                                <span class="dcnext__rank" aria-hidden="true"><?= $dcI + 1 ?></span>
                                <span class="dcnext__body">
                                    <span class="dcnext__title"><?= sanitize($dcF['title']) ?></span>
                                    <?php /* The count is dropped where it is
                                             the same number as the value:
                                             "3 · 3 records" says one thing
                                             twice. It stays wherever the two
                                             differ, which is every money
                                             figure. */ ?>
                                    <?php $dcCount = $dcF['records'] !== null
                                        && number_format((int) $dcF['records']) !== $dcF['value']; ?>
                                    <span class="dcnext__value">
                                        <?= sanitize($dcF['value']) ?>
                                        <?php if ($dcCount): ?>
                                            · <?= number_format((int) $dcF['records']) ?>
                                            <?= (int) $dcF['records'] === 1 ? 'record' : 'records' ?>
                                        <?php endif ?>
                                    </span>
                                </span>
                                <span class="sr-only"><?= sanitize(ucfirst($dcF['severity'])) ?>. Open the records.</span>
                                <i class="bi bi-chevron-right dcnext__chev" aria-hidden="true"></i>
                            </a>
                        </li>
                    <?php endforeach ?>
                </ol>
            <?php endif ?>
        </section>
    </div>

    <?php /* Cross-report reconciliation.

             The point is not to merge these. Several are deliberately
             different quantities — expected rent is not collected revenue,
             contract value is not cash — and the module has spent five phases
             keeping them apart. What this does is state each figure beside
             the record set that produces it, so a reader moving between tabs
             knows which of two similar numbers they are looking at. */ ?>
    <?php if (!empty($dcData['reconciliation'])): ?>
        <div class="dcx">
            <h4 class="dcx__title">The same figures, seen from the other reports</h4>
            <div class="table-wrap">
                <table class="table dcx__table">
                    <caption class="sr-only">
                        Headline figures with the record set each is produced from, and a link
                        to those records.
                    </caption>
                    <thead>
                        <tr>
                            <th scope="col">Figure</th>
                            <th scope="col" class="cell-num">Value</th>
                            <th scope="col" class="col-mid">Produced from</th>
                            <th scope="col">What it is</th>
                            <th scope="col"><span class="sr-only">Records</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($dcData['reconciliation'] as $dcR): ?>
                            <tr>
                                <th scope="row"><?= sanitize($dcR['label']) ?></th>
                                <td class="cell-num tp-money"><?= sanitize($dcR['value']) ?></td>
                                <td class="col-mid"><?= sanitize($dcR['against']) ?></td>
                                <td class="dcx__note"><?= sanitize($dcR['note']) ?></td>
                                <td class="cell-num">
                                    <a class="is-drill" data-drill href="<?= sanitize((string) $dcR['drill']) ?>">
                                        <span>Records</span>
                                        <span class="sr-only">for <?= sanitize($dcR['label']) ?></span>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif ?>
</div>
