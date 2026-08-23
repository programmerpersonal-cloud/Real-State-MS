<?php
/**
 * Top performing properties.
 *
 * "Performance" is one measure, stated in the table's own subtitle: collected
 * eligible revenue inside the selected period, under the same definition the
 * revenue KPI uses. Nothing here is weighted, scored or blended. A composite
 * of revenue, occupancy and collection rate would need somebody to decide
 * what each is worth relative to the others, and until that decision is a
 * business one it has no place being made in a template.
 *
 * Two consequences worth being explicit about, because both look like bugs
 * and neither is:
 *
 *  · Only properties that took money appear. The table is short when the
 *    period was quiet, and padding it to five rows with properties that
 *    earned nothing would turn a ranking into a list.
 *
 *  · The agent column is frequently empty, and says "Unassigned" rather than
 *    showing a blank. Eight of the nine payments on file sit on properties
 *    with no agent, so a blank cell would read as an agent who did nothing.
 *
 * Every row links to the property it names — a route that already exists.
 *
 * Expects: $topProperties, $window, $filters, $compare
 *
 * Every local in this file is prefixed. A partial pulled in with require
 * shares the including view's variable scope, so a plain $rows here would
 * overwrite the one its host was using — which has already cost this module
 * two bugs. See the note in _kpi.php.
 */
$tpRows     = $topProperties ?? [];
$tpFiltered = reportFilterCount($filters) > 0;
$tpCarry    = !empty($compare) ? ['compare' => '1'] : [];
$tpTotal    = 0.0;
foreach ($tpRows as $tpR) {
    $tpTotal += (float) $tpR['collected'];
}

/* Commercial state from the record that proves it, in the order that a
   property can only be one of. Mirrors the portfolio chart above so a
   property described as occupied there is described as occupied here. */
$tpState = static function (array $tpR): array {
    if ((int) $tpR['is_sold'])     return ['Sold', 'purple'];
    if ((int) $tpR['is_occupied']) return ['Occupied', 'success'];
    if ((int) $tpR['is_reserved']) return ['Reserved', 'warning'];
    return ['Vacant', 'muted'];
};
?>
<div class="table-card">
    <div class="table-head">
        <div class="table-head__title">Top performing properties</div>
        <span class="table-head__note">
            By collected revenue in <?= sanitize($window['label']) ?>
        </span>
    </div>

    <?php if (!$tpRows): ?>
        <?= uiEmptyState([
            'icon'  => 'bi-trophy',
            'title' => 'No property generated revenue in this period',
            'desc'  => $tpFiltered
                ? 'No property matching the current filters took an eligible payment inside the selected period.'
                : 'No eligible payment was received against any property inside the selected period. '
                . 'Widen the period, or record a payment against a property.',
            'actions' => $tpFiltered ? [[
                'label' => 'Clear filters',
                'icon'  => 'bi-arrow-counterclockwise',
                'class' => 'btn--outline',
                'url'   => reportUrl($window, [], ['tab' => 'overview'] + $tpCarry),
            ]] : [],
        ]) ?>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th scope="col" class="tp-rank">#</th>
                        <th scope="col">Property</th>
                        <th scope="col" class="col-mid">Category</th>
                        <th scope="col" class="col-lo">Location</th>
                        <th scope="col">State</th>
                        <th scope="col" class="col-mid">Agent</th>
                        <th scope="col" class="cell-num col-lo">Payments</th>
                        <th scope="col" class="cell-num">Collected</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($tpRows as $tpI => $tpR): ?>
                        <?php [$tpLabel, $tpTone] = $tpState($tpR); ?>
                        <tr>
                            <td class="tp-rank"><?= $tpI + 1 ?></td>
                            <td>
                                <a class="tp-name" href="<?= sanitize(APP_URL . '/index.php?page=properties&action=show&id=' . (int) $tpR['id']) ?>">
                                    <?= sanitize((string) $tpR['title']) ?>
                                </a>
                                <div class="tp-code"><?= sanitize((string) $tpR['property_code']) ?></div>
                            </td>
                            <td class="col-mid">
                                <i class="bi <?= sanitize(categoryIcon((string) $tpR['category'])) ?> tp-cat-icon" aria-hidden="true"></i>
                                <?= sanitize(categoryLabel((string) $tpR['category'])) ?>
                            </td>
                            <td class="col-lo">
                                <?= $tpR['location'] !== null && $tpR['location'] !== ''
                                    ? sanitize((string) $tpR['location'])
                                    : '<span class="text-subtle">—</span>' ?>
                            </td>
                            <td><span class="status status--<?= sanitize($tpTone) ?>"><span class="status__dot" aria-hidden="true"></span><?= sanitize($tpLabel) ?></span></td>
                            <td class="col-mid">
                                <?php if (!empty($tpR['agent_name'])): ?>
                                    <?= sanitize((string) $tpR['agent_name']) ?>
                                <?php else: ?>
                                    <?php /* Named, not blank. A blank cell reads as an agent
                                             who earned nothing; this is a property nobody was
                                             assigned to, which is a different problem and one
                                             the data-quality panel is already counting. */ ?>
                                    <span class="text-subtle" title="This property has no agent assigned, so its revenue is not attributable.">Unassigned</span>
                                <?php endif ?>
                            </td>
                            <td class="cell-num col-lo"><?= number_format((int) $tpR['payments']) ?></td>
                            <td class="cell-num tp-money"><?= sanitize(formatCurrency((float) $tpR['collected'])) ?></td>
                        </tr>
                    <?php endforeach ?>
                </tbody>
            </table>
        </div>

        <div class="table-foot">
            <p class="table-foot__note">
                <?= count($tpRows) === 1 ? 'One property' : count($tpRows) . ' properties' ?>
                took an eligible payment in this period, totalling
                <strong><?= sanitize(formatCurrency($tpTotal)) ?></strong>.
                Ranked on collected revenue alone — the same definition the revenue figure
                above uses. No other measure is weighted into the order.
            </p>
        </div>
    <?php endif ?>
</div>
