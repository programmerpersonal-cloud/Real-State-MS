<?php
/**
 * A KPI tile.
 *
 * The one place a headline figure is drawn, so a number cannot end up
 * formatted one way on the overview and another on the financial report.
 *
 * Two rules are worth stating because they are easy to lose:
 *
 *  · Direction is never carried by colour alone. Every movement gets an
 *    arrow glyph and a sentence — "+12.4% vs previous period" — so a
 *    red-green colour blindness, a greyscale print and a screen reader all
 *    get the same answer as a designer looking at the tile.
 *
 *  · A rate with no denominator prints an em dash, not 0%. reportShare()
 *    returns null for "nothing to take a share of", and rendering that as
 *    zero would state something false about an empty portfolio.
 *
 * Expects $kpi:
 *   label    string   what the figure is
 *   value    string   already formatted — currency, percentage, count
 *   icon     string   bootstrap-icons name
 *   tone     string   primary|success|info|warning|danger|purple
 *   context  string   optional line under the value
 *   delta    array    optional, from reportDelta()
 *   delta_format callable optional formatter for the absolute difference
 *   previous_label string optional, the baseline the delta moved from
 *   spark    float[]  optional series for the sparkline
 *   url      string   optional; makes the whole tile a link
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
$kpiC       = $kpi ?? [];
$kpiDelta   = $kpiC['delta'] ?? null;
$kpiSpark   = array_values(array_filter($kpiC['spark'] ?? [], 'is_numeric'));
$kpiTone    = $kpiC['tone'] ?? 'primary';
$kpiHasLink = !empty($kpiC['url']);
$kpiTag     = $kpiHasLink ? 'a' : 'div';

/* The sparkline is inline SVG rather than a chart instance. Eight of these
   would be eight Chart.js objects for sixty pixels of trend each, and none of
   them would survive a printed page. It is decorative — every value it draws
   is stated in words elsewhere on the tile or the report — so it is hidden
   from assistive technology rather than described badly. */
$kpiPoints = '';
if (count($kpiSpark) > 1) {
    $kpiMin = min($kpiSpark);
    $kpiMax = max($kpiSpark);
    $kpiRange = ($kpiMax - $kpiMin) ?: 1.0;
    $kpiStep = 100 / (count($kpiSpark) - 1);
    $kpiCoords = [];
    foreach ($kpiSpark as $kpiI => $kpiV) {
        $kpiX = round($kpiI * $kpiStep, 2);
        // Inverted: SVG y grows downward, and 2px of padding keeps the
        // stroke from being clipped at the extremes.
        $kpiY = round(26 - (($kpiV - $kpiMin) / $kpiRange * 22) - 2, 2);
        $kpiCoords[] = $kpiX . ',' . $kpiY;
    }
    $kpiPoints = implode(' ', $kpiCoords);
}
?>
<<?= $kpiTag ?> class="kpi kpi--<?= sanitize($kpiTone) ?><?= $kpiHasLink ? ' kpi--link' : '' ?>"
    <?= $kpiHasLink ? 'href="' . sanitize((string) $kpiC['url']) . '"' : '' ?>>

    <div class="kpi__top">
        <span class="kpi__icon" aria-hidden="true"><i class="bi <?= sanitize($kpiC['icon'] ?? 'bi-dot') ?>"></i></span>
        <span class="kpi__label"><?= sanitize((string) ($kpiC['label'] ?? '')) ?></span>
    </div>

    <div class="kpi__value"><?= sanitize((string) ($kpiC['value'] ?? '—')) ?></div>

    <?php if (!empty($kpiC['context'])): ?>
        <div class="kpi__context"><?= sanitize((string) $kpiC['context']) ?></div>
    <?php endif ?>

    <?php if ($kpiDelta): ?>
        <?php
        $kpiArrow = ['up' => 'bi-arrow-up-right', 'down' => 'bi-arrow-down-right', 'flat' => 'bi-dash'][$kpiDelta['direction']];
        ?>
        <div class="kpi__delta kpi__delta--<?= sanitize($kpiDelta['direction']) ?>">
            <i class="bi <?= $kpiArrow ?>" aria-hidden="true"></i>
            <span><?= sanitize($kpiDelta['label']) ?></span>
        </div>
        <?php if (!empty($kpiC['previous_label'])): ?>
            <?php /* The absolute movement and the baseline it moved from,
                     always — not only when the baseline is non-zero. A
                     percentage is meaningless against zero and this line is
                     what carries the answer in that case: "+$4,200.00 from
                     $0.00" is a complete statement where "New this period"
                     alone is only half of one. */ ?>
            <div class="kpi__previous">
                <?php if (abs((float) $kpiDelta['difference']) >= 0.005 && !empty($kpiC['delta_format'])): ?>
                    <?= sanitize(
                        ((float) $kpiDelta['difference'] > 0 ? '+' : '−')
                        . ($kpiC['delta_format'])(abs((float) $kpiDelta['difference']))
                    ) ?>
                    <span class="kpi__from">from</span>
                <?php endif ?>
                <?= sanitize((string) $kpiC['previous_label']) ?>
            </div>
        <?php endif ?>
    <?php endif ?>

    <?php if ($kpiPoints !== ''): ?>
        <svg class="kpi__spark" viewBox="0 0 100 26" preserveAspectRatio="none"
             aria-hidden="true" focusable="false">
            <polyline points="<?= $kpiPoints ?>" fill="none" stroke="currentColor"
                      stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" vector-effect="non-scaling-stroke" />
        </svg>
    <?php endif ?>
</<?= $kpiTag ?>>
