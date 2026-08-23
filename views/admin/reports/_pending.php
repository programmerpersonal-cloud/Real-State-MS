<?php
/**
 * A report, or a section of one, that is not built yet.
 *
 * Deliberately not a spinner and deliberately not hidden. A tab that exists
 * and states what it will contain is navigation — the reader learns the shape
 * of the workspace and stops looking for the sales report under Payments. A
 * tab that silently appears three weeks later is a surprise, and a tab that
 * shows a plausible empty chart is a lie told in a nicer font.
 *
 * The one rule this file exists to enforce: it never renders a number.
 *
 * Expects $pending: ['icon' => …, 'title' => …, 'desc' => …]
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
$pendC = $pending ?? [];
?>
<div class="rpending">
    <div class="rpending__icon" aria-hidden="true">
        <i class="bi <?= sanitize((string) ($pendC['icon'] ?? 'bi-clipboard-data')) ?>"></i>
    </div>
    <div class="rpending__body">
        <h3 class="rpending__title"><?= sanitize((string) ($pendC['title'] ?? 'Report')) ?></h3>
        <p class="rpending__desc"><?= sanitize((string) ($pendC['desc'] ?? '')) ?></p>
    </div>
</div>
