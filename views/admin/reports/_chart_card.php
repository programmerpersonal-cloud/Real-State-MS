<?php
/**
 * A chart card — the frame, the data, and the answer for anyone who cannot
 * see the picture.
 *
 * Three states, and the card decides which one honestly:
 *
 *   data       a canvas, plus the same figures as a table
 *   empty      a sentence saying why there is nothing, and a way back if a
 *              filter caused it. Never an axis frame with no line in it, and
 *              never a chart of zeroes — "no data" and "all zero" are
 *              different claims and only one of them is usually true.
 *   loading    a skeleton at the card's real proportions
 *
 * The table is not a fallback, it is part of the card. A chart alone is not
 * readable by a screen reader, does not survive a printed page and cannot be
 * copied into an email — and the numbers are already here, so withholding
 * them would be a choice rather than a limitation. It is collapsed behind a
 * <details> so it costs a line of height and is one click from being read.
 *
 * Data reaches Chart.js as JSON in a <script type="application/json"> block,
 * the same way scripts.php already hands the browser its validation rules.
 * Nothing executable is written into the page, and assets/js/reports.js
 * reads the block by id.
 *
 * Expects $chart:
 *   id       string  unique on the page; ties canvas to its data block
 *   title    string
 *   subtitle string  optional
 *   type     string  Chart.js type — line|bar|doughnut
 *   labels   string[]
 *   series   array   [['label'=>…, 'data'=>float[], 'tone'=>token], …]
 *   unit     string  'currency'|'number'|'percent'
 *   empty    string  the sentence shown when there is nothing
 *   height   int     canvas aspect height; defaults to 190
 *   actions  string  optional pre-escaped HTML for the card header
 *   loading  bool    render the skeleton instead
 *   filtered bool    whether filters are narrowing this card
 *   resetUrl string  offered when filtered and empty
 *   share      bool  add a percent-of-total column to the data table
 *   stacked    bool  bar charts: stack the datasets
 *   horizontal bool  bar charts: lay the categories down the y axis
 *   footnote string  a caveat printed under the card, always visible
 *
 * Every local in this file is prefixed, and that is not house style — it is a
 * bug fix. A partial pulled in with require shares the including view's
 * variable scope, so a plain $series or $meta here silently overwrites the
 * one the report was using. It cost exactly that: the KPI tiles clobbered the
 * overview's $spark and the revenue chart rendered "nothing to chart" over a
 * period with revenue in it, while the tab strip clobbered $meta and titled
 * the Overview "Performance". Prefixes are what make these safe to require
 * more than once, and in any order.
 */
$ccC        = $chart ?? [];
$ccId       = (string) ($ccC['id'] ?? 'chart');
$ccLabels   = $ccC['labels'] ?? [];
$ccSeries   = $ccC['series'] ?? [];
$ccUnit     = $ccC['unit'] ?? 'number';
$ccHeight   = (int) ($ccC['height'] ?? 190);

/* When is a card empty?
 *
 * For an absolute quantity — money, a count — a series of nothing but zeroes
 * is as empty as no series at all: drawing a flat line along the axis implies
 * a measurement was taken and came back zero, which on a portfolio with no
 * transactions is not what happened.
 *
 * For a *rate* the opposite is true, and getting this wrong hid a real
 * finding. The collection-performance chart plots settled ÷ expected, with
 * null where nothing was scheduled and a number where something was. A period
 * in which $1,100 of rent fell due and none of it was paid is 0% — a
 * measurement, and the worst one there is. Treating it as "no data" printed
 * "no rent fell due in this period" over a month where rent very much fell
 * due and nobody paid it.
 *
 * So a rate is empty only when every bucket is null, and an absolute is empty
 * when every bucket is null or zero. The distinction between null and zero is
 * already carried faithfully from the model; this is where it earns its keep. */
$ccRate    = $ccUnit === 'percent';
$ccHasData = false;
foreach ($ccSeries as $ccS) {
    foreach ($ccS['data'] ?? [] as $ccV) {
        if ($ccV === null) { continue; }
        if ($ccRate || (float) $ccV != 0.0) { $ccHasData = true; break 2; }
    }
}

/* The percentage each slice is of the whole, for the proportion charts. Read
   from the same array the chart is drawn from, so the legend, the picture and
   the table cannot quote three different figures. */
$ccShare = !empty($ccC['share']);
$ccTotal = 0.0;
if ($ccShare) {
    foreach (($ccSeries[0]['data'] ?? []) as $ccV) {
        if ($ccV !== null) { $ccTotal += (float) $ccV; }
    }
}

$ccFormat = static function ($ccV) use ($ccUnit): string {
    // A gap is a gap. Formatting null as $0.00 or 0.0% would state that a
    // measurement was taken and came back empty, which is the opposite of
    // what a null in these series means.
    if ($ccV === null) return '—';
    if ($ccUnit === 'currency') return formatCurrency((float) $ccV);
    if ($ccUnit === 'percent')  return reportPercent((float) $ccV);
    return number_format((float) $ccV);
};
?>
<section class="card rcard" aria-labelledby="<?= sanitize($ccId) ?>-title">
    <div class="card__header">
        <div class="rcard__titles">
            <h3 class="card__title" id="<?= sanitize($ccId) ?>-title"><?= sanitize((string) ($ccC['title'] ?? '')) ?></h3>
            <?php if (!empty($ccC['subtitle'])): ?>
                <p class="card__subtitle"><?= sanitize((string) $ccC['subtitle']) ?></p>
            <?php endif ?>
        </div>
        <?php if (!empty($ccC['actions'])): ?>
            <div class="rcard__actions"><?= $ccC['actions'] ?></div>
        <?php endif ?>
    </div>

    <div class="card__body">
        <?php if (!empty($ccC['loading'])): ?>
            <?php /* Proportioned to the chart it stands in for, so nothing
                     jumps when the real thing arrives. */ ?>
            <div class="skeleton skeleton--chart" style="--sk-h:<?= $ccHeight ?>px" aria-hidden="true"></div>
            <p class="sr-only">Loading chart data.</p>

        <?php elseif (!$ccHasData): ?>
            <?= uiEmptyState([
                'icon'  => 'bi-bar-chart-line',
                'title' => 'Nothing to chart',
                'desc'  => (string) ($ccC['empty'] ?? 'No data was recorded in the selected period.'),
                'actions' => (!empty($ccC['filtered']) && !empty($ccC['resetUrl'])) ? [[
                    'label' => 'Clear filters',
                    'icon'  => 'bi-arrow-counterclockwise',
                    'class' => 'btn--outline',
                    'url'   => (string) $ccC['resetUrl'],
                ]] : [],
            ]) ?>

        <?php else: ?>
            <?php $GLOBALS['reportHasChart'] = true; ?>
            <?php /* The container reserves the chart's height before a pixel
                     of it is drawn, so the page does not reflow as Chart.js
                     comes up. */ ?>
            <div class="rchart" style="--rchart-h:<?= $ccHeight ?>px">
                <canvas id="<?= sanitize($ccId) ?>"
                        data-report-chart="<?= sanitize($ccId) ?>"
                        role="img"
                        aria-label="<?= sanitize((string) ($ccC['title'] ?? 'Chart')) ?>. The same figures are listed in the data table below this chart."></canvas>
            </div>

            <script type="application/json" id="<?= sanitize($ccId) ?>-data"><?= json_encode([
                'type'       => $ccC['type'] ?? 'line',
                'unit'       => $ccUnit,
                'labels'     => $ccLabels,
                'series'     => $ccSeries,
                'stacked'    => !empty($ccC['stacked']),
                'horizontal' => !empty($ccC['horizontal']),
            ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>

            <details class="rdata">
                <summary class="rdata__toggle">
                    <i class="bi bi-table" aria-hidden="true"></i>
                    View as table
                </summary>
                <div class="table-wrap">
                    <table class="table rdata__table">
                        <caption class="sr-only"><?= sanitize((string) ($ccC['title'] ?? 'Chart data')) ?></caption>
                        <thead>
                            <tr>
                                <th scope="col"><?= sanitize((string) ($ccC['label_heading'] ?? 'Period')) ?></th>
                                <?php foreach ($ccSeries as $ccS): ?>
                                    <th scope="col" class="cell-num"><?= sanitize((string) ($ccS['label'] ?? 'Value')) ?></th>
                                <?php endforeach ?>
                                <?php if ($ccShare): ?>
                                    <th scope="col" class="cell-num">Share</th>
                                <?php endif ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($ccLabels as $ccI => $ccLabel): ?>
                                <tr>
                                    <th scope="row"><?= sanitize((string) $ccLabel) ?></th>
                                    <?php foreach ($ccSeries as $ccS): ?>
                                        <?php /* `?? null`, never `?? 0`. A null in these series
                                                 means "nothing was scheduled", and ?? 0 collapsed
                                                 it into a formatted zero — so the table printed
                                                 0.0% collection against months where no rent was
                                                 ever due, contradicting the chart beside it,
                                                 which correctly left them blank. */ ?>
                                        <td class="cell-num"><?= sanitize($ccFormat($ccS['data'][$ccI] ?? null)) ?></td>
                                    <?php endforeach ?>
                                    <?php if ($ccShare): ?>
                                        <td class="cell-num"><?= sanitize(
                                            ($ccSeries[0]['data'][$ccI] ?? null) === null
                                                ? '—'
                                                : reportPercent(reportShare(
                                                    (float) $ccSeries[0]['data'][$ccI], $ccTotal
                                                  ))
                                        ) ?></td>
                                    <?php endif ?>
                                </tr>
                            <?php endforeach ?>
                        </tbody>
                    </table>
                </div>
            </details>
        <?php endif ?>

        <?php if (!empty($ccC['footnote'])): ?>
            <?php /* Always visible, never behind the table toggle. A caveat
                     that qualifies what a chart means is part of reading it,
                     not a detail to go looking for. */ ?>
            <p class="rcard__footnote"><?= sanitize((string) $ccC['footnote']) ?></p>
        <?php endif ?>
    </div>
</section>
