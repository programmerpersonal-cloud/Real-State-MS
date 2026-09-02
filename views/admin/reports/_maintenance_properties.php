<?php
/**
 * Properties that generate maintenance, and what kind of issue is logged.
 *
 * Two small panels rather than charts, and both for the same reason: the data
 * is real but thin, and a chart would give it more authority than it has
 * earned.
 *
 * The property list is a count, not a league table. With a handful of
 * requests across a couple of properties, calling it a ranking would invite a
 * conclusion the sample cannot support — so it is presented as what it is,
 * with the sample size stated.
 *
 * The issue-type list is the same judgement the location analysis makes.
 * `issue_type` is free text with no vocabulary behind it; when it holds close
 * to one distinct value per request it is prose, not a dimension, and
 * charting it would draw a bar per request and call it analysis.
 *
 * Expects: $byProperty, $issueTypes
 *
 * Every local in this file is prefixed. A partial pulled in with require
 * shares the including view's variable scope; see the note in _kpi.php.
 */
$mpRows   = $byProperty ?? [];
$mpTypes  = $issueTypes ?? [];
$mpTRows  = $mpTypes['rows'] ?? [];
$mpUsable = !empty($mpTypes['usable']);

if (!$mpRows && !$mpTRows) {
    return;
}
?>
<div class="rgrid rgrid--wide">

    <?php if ($mpRows): ?>
        <section class="card rcard" aria-labelledby="mp-prop-title">
            <div class="card__header">
                <div class="rcard__titles">
                    <h4 class="card__title" id="mp-prop-title">Properties generating maintenance</h4>
                    <p class="card__subtitle">
                        <?= count($mpRows) ?>
                        <?= count($mpRows) === 1 ? 'property has' : 'properties have' ?>
                        logged a request
                    </p>
                </div>
            </div>
            <div class="card__body card__body--flush">
                <div class="table-wrap">
                    <table class="table ploc__table">
                        <thead>
                            <tr>
                                <th scope="col">Property</th>
                                <th scope="col" class="cell-num">Requests</th>
                                <th scope="col" class="cell-num">Open</th>
                                <th scope="col" class="cell-num col-mid">High</th>
                                <th scope="col" class="cell-num col-lo">Cost</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($mpRows as $mpR): ?>
                                <tr>
                                    <th scope="row">
                                        <a class="tp-name" href="<?= sanitize(APP_URL . '/index.php?page=properties&action=show&id=' . (int) $mpR['id']) ?>">
                                            <?= sanitize((string) $mpR['title']) ?>
                                        </a>
                                        <div class="tp-code"><?= sanitize(categoryLabel((string) $mpR['category'])) ?></div>
                                    </th>
                                    <td class="cell-num"><?= number_format((int) $mpR['requests']) ?></td>
                                    <td class="cell-num">
                                        <?= (int) $mpR['open_requests'] > 0
                                            ? sanitize(number_format((int) $mpR['open_requests']))
                                            : '<span class="text-subtle" aria-label="none">—</span>' ?>
                                    </td>
                                    <td class="cell-num col-mid">
                                        <?= (int) $mpR['urgent_requests'] > 0
                                            ? '<span class="text-danger">' . sanitize(number_format((int) $mpR['urgent_requests'])) . '</span>'
                                            : '<span class="text-subtle" aria-label="none">—</span>' ?>
                                    </td>
                                    <td class="cell-num col-lo tp-money">
                                        <?= (float) $mpR['cost'] > 0
                                            ? sanitize(formatCurrency((float) $mpR['cost']))
                                            : '<span class="text-subtle" aria-label="none">—</span>' ?>
                                    </td>
                                </tr>
                            <?php endforeach ?>
                        </tbody>
                    </table>
                </div>
                <p class="rcard__footnote">
                    A count of requests, not a ranking. With this many records the order says
                    little about which property is genuinely troublesome — it is here so the
                    concentration is visible, not to draw a conclusion from.
                </p>
            </div>
        </section>
    <?php endif ?>

    <?php if ($mpTRows): ?>
        <section class="card rcard" aria-labelledby="mp-type-title">
            <div class="card__header">
                <div class="rcard__titles">
                    <h4 class="card__title" id="mp-type-title">Issue types</h4>
                    <p class="card__subtitle">What is being reported, as recorded</p>
                </div>
            </div>
            <div class="card__body card__body--flush">
                <?php if (!$mpUsable): ?>
                    <?php /* Same measurement the location panel uses. Near one
                             distinct value per request means free text, not a
                             dimension worth charting. */ ?>
                    <div class="ploc__note">
                        <i class="bi bi-info-circle" aria-hidden="true"></i>
                        <p>
                            <strong><?= number_format((int) $mpTypes['distinct']) ?> distinct
                            <?= (int) $mpTypes['distinct'] === 1 ? 'value' : 'values' ?>
                            across <?= number_format((int) $mpTypes['total']) ?>
                            <?= (int) $mpTypes['total'] === 1 ? 'request' : 'requests' ?></strong> —
                            issue type is stored as free text with no fixed vocabulary, so these
                            are close to one value per request. They are listed as recorded and
                            not charted: grouping them would mean inventing categories the data
                            does not contain.
                        </p>
                    </div>
                <?php endif ?>

                <div class="table-wrap">
                    <table class="table ploc__table">
                        <thead>
                            <tr>
                                <th scope="col">Type as recorded</th>
                                <th scope="col" class="cell-num">Requests</th>
                                <th scope="col" class="cell-num">Open</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($mpTRows as $mpT): ?>
                                <tr>
                                    <th scope="row" class="ploc__value"><?= sanitize((string) $mpT['issue_type']) ?></th>
                                    <td class="cell-num"><?= number_format((int) $mpT['requests']) ?></td>
                                    <td class="cell-num">
                                        <?= (int) $mpT['open_requests'] > 0
                                            ? sanitize(number_format((int) $mpT['open_requests']))
                                            : '<span class="text-subtle" aria-label="none">—</span>' ?>
                                    </td>
                                </tr>
                            <?php endforeach ?>
                        </tbody>
                    </table>
                </div>

                <?php if ((int) ($mpTypes['blank'] ?? 0) > 0): ?>
                    <p class="rcard__footnote">
                        <?= number_format((int) $mpTypes['blank']) ?>
                        <?= (int) $mpTypes['blank'] === 1 ? 'request has' : 'requests have' ?>
                        no issue type recorded and are absent from this list.
                    </p>
                <?php endif ?>
            </div>
        </section>
    <?php endif ?>
</div>
