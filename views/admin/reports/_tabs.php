<?php
/**
 * The report strip.
 *
 * Written as navigation rather than as a tab widget, and it matters which.
 * Each report is a full page load at its own URL, so `role="tablist"` would
 * be a lie: ARIA tabs promise a panel that swaps in place, and a screen
 * reader told to expect that and then handed a new document is worse off
 * than one that was simply told these are links. `<nav>` with
 * `aria-current="page"` describes what actually happens.
 *
 * The same reasoning as views/admin/properties/_register_tabs.php, which is
 * where this pattern comes from — the workspace should not invent a second
 * kind of tab for the same job.
 *
 * Phase 8 gave the strip a recessed track and made the active report a
 * raised chip on it rather than an underlined word. The rule it is following
 * is that the active state should be legible at a glance without being a
 * heavy filled block: the chip is the surface colour the cards below are
 * drawn in, lifted off a slightly darker track, with a hairline accent under
 * it. That reads as "you are here" from across a desk and still sits quietly
 * beside the figures.
 *
 * One hairline separates Overview from the seven reports that follow. It is
 * the only division the eight tabs honestly support — Overview is the
 * executive summary and the rest are the detail it points into — and the
 * required order of the tabs is untouched by it.
 *
 * Every link carries the reader's period, comparison and filters, so moving
 * between reports never silently resets what they set up.
 *
 * Expects: $window, $filters, $reportTab, $compare
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
$tabsActive = $reportTab ?? 'overview';
$tabsCarry  = !empty($compare) ? ['compare' => '1'] : [];
?>
<nav class="rtabs" aria-label="Reports">
    <ul class="rtabs__list">
        <?php foreach (ReportController::TABS as $tabsKey => $tabsMeta): ?>
            <?php $tabsIsActive = $tabsKey === $tabsActive; ?>
            <?php if (!empty($tabsMeta['starts_group'])): ?>
                <li class="rtabs__sep" aria-hidden="true"></li>
            <?php endif ?>
            <li class="rtabs__item">
                <a class="rtabs__link<?= $tabsIsActive ? ' is-active' : '' ?>"
                   href="<?= sanitize(reportUrl($window, $filters, ['tab' => $tabsKey] + $tabsCarry)) ?>"
                   <?= $tabsIsActive ? 'aria-current="page" data-report-tab-active' : '' ?>>
                    <i class="bi <?= sanitize($tabsMeta['icon']) ?>" aria-hidden="true"></i>
                    <span class="rtabs__name"><?= sanitize($tabsMeta['label']) ?></span>
                </a>
            </li>
        <?php endforeach ?>
    </ul>
</nav>
