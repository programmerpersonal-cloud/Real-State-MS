<?php
/**
 * Portfolio by location — or an honest account of why there isn't one.
 *
 * The brief asked for a location chart "only if the existing location data is
 * sufficiently consistent". It is not, and the interesting part is that the
 * report can tell.
 *
 * There is one `location` column and no city, district or region field to
 * group on. It holds free text: seventeen properties across fourteen distinct
 * strings, several differing only in punctuation — "jarka , borama" and
 * "jarka borama" are the same street written twice, and one value is
 * keyboard noise. A bar chart of that is fourteen bars of one, dressed up as
 * geographic analysis.
 *
 * Normalising it would be worse. Folding those two into "Borama" means
 * inventing a grouping the data does not contain, and every such decision is
 * a guess presented to the reader as a fact.
 *
 * So: a table of what is actually stored, and a plain statement of how
 * concentrated it is. If somebody later adds a structured location field, the
 * spread measure below will cross its threshold on its own and the chart can
 * be built on something real.
 *
 * Expects: $locations (from CoreAnalytics::portfolioLocations())
 *
 * Every local in this file is prefixed. A partial pulled in with require
 * shares the including view's variable scope; see the note in _kpi.php.
 */
$plData   = $locations ?? [];
$plRows   = $plData['rows'] ?? [];
$plTotal  = (int) ($plData['total'] ?? 0);
$plUsable = !empty($plData['usable']);
?>
<section class="card rcard" aria-labelledby="ploc-title">
    <div class="card__header">
        <div class="rcard__titles">
            <h3 class="card__title" id="ploc-title">Location</h3>
            <p class="card__subtitle">Where the portfolio sits, as recorded</p>
        </div>
    </div>

    <div class="card__body card__body--flush">
        <?php if (!$plRows): ?>
            <?= uiEmptyState([
                'icon'  => 'bi-geo-alt',
                'title' => 'No location recorded',
                'desc'  => 'No property in scope carries a location.',
            ]) ?>
        <?php else: ?>
            <?php if (!$plUsable): ?>
                <?php /* Stated before the table, so nobody reads the list as a
                         ranking of regions. */ ?>
                <div class="ploc__note">
                    <i class="bi bi-info-circle" aria-hidden="true"></i>
                    <p>
                        <strong><?= number_format((int) $plData['distinct']) ?> distinct values
                        across <?= number_format($plTotal) ?>
                        <?= $plTotal === 1 ? 'property' : 'properties' ?></strong> —
                        location is stored as free-text address rather than as a structured
                        region, so these are close to one value per property. They are listed
                        as recorded and not charted: grouping them into regions would mean
                        inventing groupings the data does not contain.
                    </p>
                </div>
            <?php endif ?>

            <div class="table-wrap">
                <table class="table ploc__table">
                    <thead>
                        <tr>
                            <th scope="col">Location as recorded</th>
                            <th scope="col" class="cell-num">Properties</th>
                            <th scope="col" class="cell-num">Share</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($plRows as $plRow): ?>
                            <tr>
                                <th scope="row" class="ploc__value"><?= sanitize((string) $plRow['location']) ?></th>
                                <td class="cell-num"><?= number_format((int) $plRow['properties']) ?></td>
                                <td class="cell-num">
                                    <?= sanitize(reportPercent(reportShare(
                                        (float) $plRow['properties'],
                                        (float) $plTotal
                                    ))) ?>
                                </td>
                            </tr>
                        <?php endforeach ?>
                    </tbody>
                </table>
            </div>

            <?php if ((int) ($plData['blank'] ?? 0) > 0): ?>
                <p class="rcard__footnote">
                    <?= number_format((int) $plData['blank']) ?>
                    <?= (int) $plData['blank'] === 1 ? 'property has' : 'properties have' ?>
                    no location recorded and are absent from this list.
                </p>
            <?php endif ?>
        <?php endif ?>
    </div>
</section>
