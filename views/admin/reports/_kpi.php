<?php
/**
 * A KPI tile.
 *
 * The one place a headline figure is drawn, so a number cannot end up
 * formatted one way on the overview and another on the financial report.
 *
 * Four rules, and each of them is here because the alternative was worse:
 *
 *  · The number is the loudest thing on the tile. Phase 8 removed the
 *    decorative icon chip that used to sit beside every label: eight tiles
 *    each carrying a coloured glyph made a row of badges with figures in
 *    them, and the figure is the point. An icon is now drawn only on a tile
 *    whose tone is an *attention* state, where the glyph is doing work — it
 *    marks the one tile in the row that wants acting on.
 *
 *  · Direction is never carried by colour alone. Every movement gets an
 *    arrow glyph and a sentence — "+12.4% vs previous period" — so a
 *    red-green colour blindness, a greyscale print and a screen reader all
 *    get the same answer as a designer looking at the tile.
 *
 *  · A movement is coloured only when the caller has said which direction is
 *    good. Before Phase 8 every rise was green, which meant a period with
 *    50% more maintenance requests raised, or more payments flagged for
 *    review, congratulated the reader on it. Tiles that pass `good` get the
 *    semantic ramp; every other tile states its movement in neutral ink and
 *    leaves the reader to decide what it means.
 *
 *  · A rate with no denominator prints an em dash, not 0%. reportShare()
 *    returns null for "nothing to take a share of", and rendering that as
 *    zero would state something false about an empty portfolio. A tile in
 *    that state says "Not available" rather than showing a figure at all.
 *
 * Expects $kpi:
 *   label    string   what the figure is
 *   value    string   already formatted — currency, percentage, count
 *   icon     string   bootstrap-icons name; drawn only on an attention tone
 *   tone     string   primary|success|info|warning|danger|purple
 *   context  string   optional line under the value
 *   delta    array    optional, from reportDelta()
 *   good     string   optional 'up'|'down' — which direction earns colour
 *   delta_format callable optional formatter for the absolute difference
 *   previous_label string optional, the baseline the delta moved from
 *   spark    float[]  optional series for the sparkline
 *   unavailable bool  the measure cannot be derived; render it as such
 *   url      string   optional; makes the whole tile a link
 *   drill    string   optional; a drill-down URL. Takes precedence over url,
 *                     and opens the records behind the figure in the drawer.
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
/* A tile can lead somewhere in one of two ways, and drill wins where both
   are set. `url` sends the reader to another report — useful navigation, and
   what these tiles did before Phase 10. `drill` opens the records behind the
   figure, which is what somebody questioning a number actually wants; sending
   them to a second page of aggregates instead would be answering a question
   they did not ask. */
$kpiDrill   = (string) ($kpiC['drill'] ?? '');
$kpiHref    = $kpiDrill !== '' ? $kpiDrill : (string) ($kpiC['url'] ?? '');
$kpiHasLink = $kpiHref !== '';
$kpiTag     = $kpiHasLink ? 'a' : 'div';
$kpiValue   = (string) ($kpiC['value'] ?? '—');

/* An unavailable measure must never look like a measured zero. The caller
   says so explicitly; the em-dash check is the safety net for a value that
   reached here through reportPercent(null) without the flag being set. */
$kpiUnavail = !empty($kpiC['unavailable'])
    || $kpiValue === '—'
    || $kpiValue === 'Not available';

/* An icon earns its place on an attention tile and nowhere else. */
$kpiAlert = in_array($kpiTone, ['warning', 'danger'], true);
$kpiIcon  = ($kpiAlert && !empty($kpiC['icon'])) ? (string) $kpiC['icon'] : '';

/* Colour on a movement is opt-in — see the docblock. */
$kpiGood = $kpiC['good'] ?? null;
$kpiMood = 'neutral';
if ($kpiDelta && $kpiGood !== null && $kpiDelta['direction'] !== 'flat') {
    $kpiMood = $kpiDelta['direction'] === $kpiGood ? 'good' : 'bad';
}

/* The sparkline is inline SVG rather than a chart instance. Eight of these
   would be eight Chart.js objects for sixty pixels of trend each, and none of
   them would survive a printed page. It is decorative — every value it draws
   is stated in words elsewhere on the tile or the report — so it is hidden
   from assistive technology rather than described badly. */
$kpiPoints = '';
$kpiArea   = '';
if (count($kpiSpark) > 1) {
    $kpiMin   = min($kpiSpark);
    $kpiMax   = max($kpiSpark);
    $kpiRange = ($kpiMax - $kpiMin) ?: 1.0;
    $kpiStep  = 100 / (count($kpiSpark) - 1);
    $kpiCoords = [];
    foreach ($kpiSpark as $kpiI => $kpiV) {
        $kpiX = round($kpiI * $kpiStep, 2);
        // Inverted: SVG y grows downward, and 2px of padding keeps the
        // stroke from being clipped at the extremes.
        $kpiY = round(26 - (($kpiV - $kpiMin) / $kpiRange * 22) - 2, 2);
        $kpiCoords[] = $kpiX . ',' . $kpiY;
    }
    $kpiPoints = implode(' ', $kpiCoords);

    /* The wash under the line, and only where there is a shape to wash. A
       thirty-day series with revenue on one of them fills as a single narrow
       spike, which at 30px tall reads as a rendering fault rather than as a
       month with one payment in it. Three readings is the point at which a
       filled area starts describing a trend instead of an incident; below
       that the line alone tells the truth with less furniture. */
    $kpiShape = count(array_filter($kpiSpark, static fn($kpiV): bool => (float) $kpiV != 0.0));
    if ($kpiShape >= 3) {
        $kpiArea = '0,26 ' . $kpiPoints . ' 100,26';
    }
}

$kpiClasses = 'kpi kpi--' . $kpiTone
    . ($kpiHasLink ? ' kpi--link' : '')
    . ($kpiDrill !== '' ? ' kpi--drill' : '')
    . ($kpiAlert ? ' kpi--alert' : '')
    . ($kpiUnavail ? ' kpi--unavailable' : '');
?>
<<?= $kpiTag ?> class="<?= sanitize($kpiClasses) ?>"<?= $kpiHasLink ? ' href="' . sanitize($kpiHref) . '"' : '' ?><?= $kpiDrill !== '' ? ' data-drill' : '' ?>>

    <div class="kpi__label">
        <?php if ($kpiIcon !== ''): ?>
            <i class="bi <?= sanitize($kpiIcon) ?> kpi__label-icon" aria-hidden="true"></i>
        <?php endif ?>
        <span><?= sanitize((string) ($kpiC['label'] ?? '')) ?></span>
    </div>

    <div class="kpi__figure">
        <span class="kpi__value"><?= $kpiUnavail ? 'Not available' : sanitize($kpiValue) ?></span>

        <?php if ($kpiDelta): ?>
            <?php
            $kpiArrow = [
                'up'   => 'bi-arrow-up-right',
                'down' => 'bi-arrow-down-right',
                'flat' => 'bi-dash',
            ][$kpiDelta['direction']];

            /* The pill carries the movement, not the sentence.
               reportDelta() writes a complete statement — "New this period —
               nothing recorded previously" — which is the right thing for a
               screen reader and four times too long for a chip on a 210px
               tile; before Phase 8 it simply ran off the edge and was clipped.
               The chip is now the figure alone, the full sentence rides on it
               for assistive technology, and the line underneath carries the
               baseline in words for everyone. Nothing was dropped. */
            $kpiShort = match (true) {
                $kpiDelta['direction'] === 'flat' => 'No change',
                $kpiDelta['percentage'] === null  => 'New',
                default => (($kpiDelta['percentage'] > 0 ? '+' : '−')
                            . number_format(abs((float) $kpiDelta['percentage']), 1) . '%'),
            };
            ?>
            <span class="kpi__delta kpi__delta--<?= sanitize($kpiMood) ?>">
                <i class="bi <?= $kpiArrow ?>" aria-hidden="true"></i>
                <span aria-hidden="true"><?= sanitize($kpiShort) ?></span>
                <span class="sr-only"><?= sanitize($kpiDelta['label']) ?></span>
            </span>
        <?php endif ?>
    </div>

    <?php if (!empty($kpiC['context'])): ?>
        <p class="kpi__context"><?= sanitize((string) $kpiC['context']) ?></p>
    <?php endif ?>

    <?php if ($kpiDelta): ?>
        <?php /* The absolute movement and the baseline it moved from, always
                 — not only when the baseline is non-zero. A percentage is
                 meaningless against zero and this line is what carries the
                 answer in that case: "+$4,200.00 from $0.00" is a complete
                 statement where "New this period" alone is half of one.

                 A tile that supplied no baseline still gets the line, because
                 a chip reading "+12.4%" with nothing under it does not say
                 what the twelve percent is measured against. */ ?>
        <p class="kpi__baseline">
            <?php if (!empty($kpiC['previous_label'])): ?>
                <?php if (abs((float) $kpiDelta['difference']) >= 0.005 && !empty($kpiC['delta_format'])): ?>
                    <span class="kpi__baseline-diff"><?= sanitize(
                        ((float) $kpiDelta['difference'] > 0 ? '+' : '−')
                        . ($kpiC['delta_format'])(abs((float) $kpiDelta['difference']))
                    ) ?></span>
                    <span class="kpi__from">from</span>
                <?php endif ?>
                <?= sanitize((string) $kpiC['previous_label']) ?>
            <?php else: ?>
                Against the previous period
            <?php endif ?>
        </p>
    <?php endif ?>

    <?php if ($kpiPoints !== ''): ?>
        <svg class="kpi__spark" viewBox="0 0 100 26" preserveAspectRatio="none"
             aria-hidden="true" focusable="false">
            <polygon class="kpi__spark-area" points="<?= $kpiArea ?>" />
            <polyline points="<?= $kpiPoints ?>" fill="none" stroke="currentColor"
                      stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"
                      vector-effect="non-scaling-stroke" />
        </svg>
    <?php endif ?>
</<?= $kpiTag ?>>
