<?php
/**
 * The portfolio, one row per property.
 *
 * Not the Overview's ranked table. That one answers "which properties earned
 * the most" and lists only properties that earned something. This one is an
 * inventory register: every property in scope appears, earning or not, with
 * what it is, what state it is in, who holds it and what it took in.
 *
 * Two columns sit side by side that a reader would assume agree, and the
 * whole reason they are both here is that sometimes they do not:
 *
 *   State     derived — a lease, an unexpired hold, a completed sale
 *   Recorded  what properties.status says
 *
 * Where they differ the row is marked. The audit found two properties let
 * while their record said available, and one recorded sold with no completed
 * sale behind it. Showing only the derived state would hide that; showing
 * only the recorded one would repeat it as fact.
 *
 * Revenue is the single window-bounded column, and it carries a scope the
 * rest of the row does not: rows are chosen by property visibility, revenue
 * is summed under payment visibility. For an agent those rules can disagree —
 * a payment taken on their property by a colleague is not theirs to see — so
 * a property may show less revenue than the company's books hold. That is the
 * existing access model applied consistently, and the footnote says so.
 *
 * Expects: $portfolio, $portfolioTotal, $portfolioPage, $portfolioPages,
 *          $window, $filters, $compare
 *
 * Every local in this file is prefixed. A partial pulled in with require
 * shares the including view's variable scope; see the note in _kpi.php.
 */
$ptRows     = $portfolio ?? [];
$ptFiltered = reportFilterCount($filters) > 0;
$ptCarry    = !empty($compare) ? ['compare' => '1'] : [];
$ptRevenue  = 0.0;
foreach ($ptRows as $ptR) {
    $ptRevenue += (float) $ptR['revenue'];
}

/* Derived state, in the order a property can only be one of. Mirrors
   portfolioState() exactly so the table and the chart cannot disagree. */
$ptState = static function (array $ptR): array {
    if ((int) $ptR['is_sold'])     return ['Sold', 'purple'];
    if ((int) $ptR['is_occupied']) return ['Occupied', 'success'];
    if ((int) $ptR['is_reserved']) return ['Reserved', 'warning'];
    return ['Available', 'info'];
};

/* Does the property's own status column agree with what its records prove?
   Compared on the derived label, lowercased — 'rented' is the register's word
   for what the leases call occupied. */
$ptAgrees = static function (array $ptR, string $ptDerived): bool {
    $ptRecorded = strtolower((string) $ptR['recorded_status']);
    $ptMap = [
        'Sold'      => ['sold'],
        'Occupied'  => ['rented'],
        'Reserved'  => ['reserved'],
        'Available' => ['available', 'inactive', 'maintenance'],
    ];

    return in_array($ptRecorded, $ptMap[$ptDerived] ?? [], true);
};
?>
<div class="table-card">
    <div class="table-head">
        <div class="table-head__title">Portfolio</div>
        <span class="table-head__note">
            <?= number_format((int) $portfolioTotal) ?>
            <?= (int) $portfolioTotal === 1 ? 'property' : 'properties' ?>
            · state as at today, revenue within <?= sanitize($window['label']) ?>
        </span>
    </div>

    <?php if (!$ptRows): ?>
        <?= uiEmptyState([
            'icon'  => 'bi-buildings',
            'title' => 'No properties in scope',
            'desc'  => $ptFiltered
                ? 'No property matches the current filters.'
                : 'There is no property you are able to see in this portfolio.',
            'actions' => $ptFiltered ? [[
                'label' => 'Clear filters',
                'icon'  => 'bi-arrow-counterclockwise',
                'class' => 'btn--outline',
                'url'   => reportUrl($window, [], ['tab' => 'properties'] + $ptCarry),
            ]] : [],
        ]) ?>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th scope="col">Property</th>
                        <th scope="col" class="col-mid">Category</th>
                        <th scope="col" class="col-lo">Location</th>
                        <th scope="col">State</th>
                        <th scope="col" class="col-mid">Recorded</th>
                        <th scope="col" class="col-lo">Intent</th>
                        <th scope="col" class="col-mid">Agent</th>
                        <th scope="col" class="cell-num">Revenue</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($ptRows as $ptR): ?>
                        <?php
                        [$ptLabel, $ptTone] = $ptState($ptR);
                        $ptOk = $ptAgrees($ptR, $ptLabel);
                        ?>
                        <tr>
                            <td>
                                <a class="tp-name" href="<?= sanitize(APP_URL . '/index.php?page=properties&action=show&id=' . (int) $ptR['id']) ?>">
                                    <?= sanitize((string) $ptR['title']) ?>
                                </a>
                                <div class="tp-code"><?= sanitize((string) $ptR['property_code']) ?></div>
                            </td>
                            <td class="col-mid">
                                <i class="bi <?= sanitize(categoryIcon((string) $ptR['category'])) ?> tp-cat-icon" aria-hidden="true"></i>
                                <?= sanitize(categoryLabel((string) $ptR['category'])) ?>
                            </td>
                            <td class="col-lo">
                                <?= $ptR['location'] !== null && trim((string) $ptR['location']) !== ''
                                    ? sanitize((string) $ptR['location'])
                                    : '<span class="text-subtle">Not recorded</span>' ?>
                            </td>
                            <td>
                                <span class="status status--<?= sanitize($ptTone) ?>">
                                    <span class="status__dot" aria-hidden="true"></span>
                                    <?= sanitize($ptLabel) ?>
                                </span>
                            </td>
                            <td class="col-mid">
                                <?= sanitize(uiLabel((string) $ptR['recorded_status'])) ?>
                                <?php if (!$ptOk): ?>
                                    <?php /* The disagreement, marked on the row it happens on.
                                             Reports read the derived state; the register still
                                             shows the other one, and somebody has to fix it at
                                             source. Nothing is changed here. */ ?>
                                    <span class="pr-flag pr-flag--mismatch"
                                          title="The property record disagrees with its leases, reservations and sales. Reporting uses the derived state.">
                                        Disagrees
                                    </span>
                                <?php endif ?>
                            </td>
                            <td class="col-lo">
                                <?= sanitize([
                                    'rent' => 'For rent',
                                    'sale' => 'For sale',
                                    'both' => 'Rent or sale',
                                ][(string) $ptR['property_type']] ?? uiLabel((string) $ptR['property_type'])) ?>
                            </td>
                            <td class="col-mid">
                                <?php if (!empty($ptR['agent_name'])): ?>
                                    <?= sanitize((string) $ptR['agent_name']) ?>
                                <?php else: ?>
                                    <?php /* Missing attribution, named. Never used for a
                                             revenue figure — a property with no agent can
                                             still have earned money. */ ?>
                                    <span class="text-subtle" title="No agent is assigned to this property.">Unassigned</span>
                                <?php endif ?>
                            </td>
                            <td class="cell-num tp-money">
                                <?php /* A real zero, not an em dash: the property is in scope
                                         and took nothing in this period, which is a fact
                                         worth stating plainly. */ ?>
                                <?= sanitize(formatCurrency((float) $ptR['revenue'])) ?>
                            </td>
                        </tr>
                    <?php endforeach ?>
                </tbody>
                <tfoot>
                    <tr>
                        <th scope="row" colspan="7">
                            Revenue across <?= count($ptRows) ?>
                            <?= count($ptRows) === 1 ? 'property' : 'properties' ?> shown
                        </th>
                        <td class="cell-num tp-money"><?= sanitize(formatCurrency($ptRevenue)) ?></td>
                    </tr>
                </tfoot>
            </table>
        </div>

        <?php if ((int) $portfolioPages > 1): ?>
            <?php
            /* Unprefixed against this folder's convention because they are the
               pagination component's published contract — it reads $page and
               $totalPages. Set immediately before the require, used by nothing
               after it. */
            $page       = (int) $portfolioPage;
            $totalPages = (int) $portfolioPages;
            require VIEWS_PATH . '/components/pagination.php';
            ?>
        <?php endif ?>

        <div class="table-foot">
            <p class="table-foot__note">
                <strong>State</strong> is derived from leases, reservations and completed
                sales. <strong>Recorded</strong> is what the property row says; where the two
                disagree the row is marked and nothing is changed.
                Revenue is bounded by the reporting period and is summed under payment
                visibility, which for an agent can be narrower than their property
                visibility — a payment taken on their property by a colleague is not
                theirs to see, so a row can show less than the company's books hold.
            </p>
        </div>
    <?php endif ?>
</div>
