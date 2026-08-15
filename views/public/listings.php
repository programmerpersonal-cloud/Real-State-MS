<?php
/**
 * Property Listings (Public)
 *
 * Provided by index.php:
 *   $properties, $covers, $totalCount, $currentPage, $totalPages, $filters
 */

$savedIds = savedPropertyIds();
$covers   = $covers ?? [];

$categories = ['apartment', 'house', 'villa', 'office', 'commercial', 'warehouse', 'land'];
$sortLabels = [
    'newest'     => 'Newest first',
    'price_asc'  => 'Price: low to high',
    'price_desc' => 'Price: high to low',
    'rooms_desc' => 'Most bedrooms',
    'size_desc'  => 'Largest area',
];
$activeSort = array_key_exists($filters['sort'] ?? '', $sortLabels) ? $filters['sort'] : 'newest';

/**
 * Build a listings URL, preserving current filters.
 * Passing null for a key drops it, which is how the filter chips remove
 * a single facet without discarding the rest of the search.
 */
$listingsUrl = function (array $overrides = []) use ($filters): string {
    $params = array_merge($filters, $overrides);
    unset($params['status']); // always 'available' on the public site

    $params = array_filter(
        $params,
        fn($v) => $v !== null && $v !== '' && $v !== 'newest'
    );
    // Escaped so the result can be dropped straight into an href.
    return sanitize(APP_URL . '/index.php?' . http_build_query(array_merge(['page' => 'listings'], $params)));
};

// Human-readable summary of what is currently narrowing the results.
$activeChips = [];
if (!empty($filters['search'])) {
    $activeChips[] = ['search', '“' . $filters['search'] . '”'];
}
if (!empty($filters['property_type'])) {
    $activeChips[] = ['property_type', $filters['property_type'] === 'rent' ? 'For rent' : 'For sale'];
}
if (!empty($filters['category'])) {
    $activeChips[] = ['category', categoryLabel((string) $filters['category'])];
}
if (!empty($filters['min_rooms'])) {
    $activeChips[] = ['min_rooms', (int) $filters['min_rooms'] . '+ beds'];
}
if (!empty($filters['min_baths'])) {
    $activeChips[] = ['min_baths', (int) $filters['min_baths'] . '+ baths'];
}
if (!empty($filters['min_price'])) {
    $activeChips[] = ['min_price', 'From ' . formatCurrency((float) $filters['min_price'])];
}
if (!empty($filters['max_price'])) {
    $activeChips[] = ['max_price', 'Up to ' . formatCurrency((float) $filters['max_price'])];
}

$firstResult = $totalCount > 0 ? (($currentPage - 1) * 12) + 1 : 0;
$lastResult  = $firstResult + count($properties) - 1;
?>

<section class="page-hero">
    <div class="site-container">
        <nav aria-label="Breadcrumb">
            <ol class="crumbs crumbs--center">
                <li><a href="<?= APP_URL ?>/index.php?page=home">Home</a></li>
                <li><span aria-current="page">Properties</span></li>
            </ol>
        </nav>
        <span class="eyebrow">Marketplace</span>
        <h1 class="page-hero__title">Properties for sale &amp; rent</h1>
        <p class="page-hero__lede">
            Homes, apartments, offices and land — every listing verified by our team
            before it reaches this page.
        </p>

        <div class="page-hero__actions">
            <a href="#results" class="btn btn--primary btn--lg">
                <i class="bi bi-list-ul" aria-hidden="true"></i> View all properties
            </a>
            <a href="<?= APP_URL ?>/index.php?page=contact" class="btn btn--outline btn--lg">
                <i class="bi bi-chat-dots" aria-hidden="true"></i> Tell us what you're after
            </a>
        </div>

        <?php require VIEWS_PATH . '/public/components/trust_bar.php'; ?>
    </div>
</section>

<section class="section section--tight">
    <div class="site-container">

        <!-- ── Filters ──────────────────────────────────────────
             A plain GET form: results are always a shareable URL, and
             the whole page works with JavaScript disabled. -->
        <form class="filters" method="GET" action="<?= APP_URL ?>/index.php" role="search"
              aria-label="Filter properties">
            <input type="hidden" name="page" value="listings">
            <input type="hidden" name="sort" value="<?= sanitize($activeSort) ?>">

            <div class="filters__grid">
                <div class="filters__field filters__field--wide">
                    <label class="filters__label" for="f-search">Search</label>
                    <div class="filters__control">
                        <i class="bi bi-search" aria-hidden="true"></i>
                        <input type="search" id="f-search" name="search"
                               placeholder="City, area or property code"
                               value="<?= sanitize($filters['search'] ?? '') ?>">
                    </div>
                </div>

                <div class="filters__field">
                    <label class="filters__label" for="f-type">Buy or rent</label>
                    <select id="f-type" name="property_type">
                        <option value="">All</option>
                        <option value="sale" <?= ($filters['property_type'] ?? '') === 'sale' ? 'selected' : '' ?>>For sale</option>
                        <option value="rent" <?= ($filters['property_type'] ?? '') === 'rent' ? 'selected' : '' ?>>For rent</option>
                    </select>
                </div>

                <div class="filters__field">
                    <label class="filters__label" for="f-category">Property type</label>
                    <select id="f-category" name="category">
                        <option value="">All types</option>
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?= $cat ?>" <?= ($filters['category'] ?? '') === $cat ? 'selected' : '' ?>>
                                <?= categoryLabel($cat) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="filters__field">
                    <label class="filters__label" for="f-beds">Bedrooms</label>
                    <select id="f-beds" name="min_rooms">
                        <option value="">Any</option>
                        <?php foreach ([1, 2, 3, 4, 5] as $n): ?>
                            <option value="<?= $n ?>" <?= (int) ($filters['min_rooms'] ?? 0) === $n ? 'selected' : '' ?>><?= $n ?>+</option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="filters__field">
                    <label class="filters__label" for="f-baths">Bathrooms</label>
                    <select id="f-baths" name="min_baths">
                        <option value="">Any</option>
                        <?php foreach ([1, 2, 3, 4] as $n): ?>
                            <option value="<?= $n ?>" <?= (int) ($filters['min_baths'] ?? 0) === $n ? 'selected' : '' ?>><?= $n ?>+</option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="filters__field">
                    <label class="filters__label" for="f-min">Min price</label>
                    <input type="number" id="f-min" name="min_price" min="0" step="100"
                           placeholder="Any" inputmode="numeric"
                           value="<?= sanitize($filters['min_price'] ?? '') ?>">
                </div>

                <div class="filters__field">
                    <label class="filters__label" for="f-max">Max price</label>
                    <input type="number" id="f-max" name="max_price" min="0" step="100"
                           placeholder="Any" inputmode="numeric"
                           value="<?= sanitize($filters['max_price'] ?? '') ?>">
                </div>

                <div class="filters__field filters__actions">
                    <button type="submit" class="btn btn--primary">
                        <i class="bi bi-search" aria-hidden="true"></i> Search
                    </button>
                    <a href="<?= APP_URL ?>/index.php?page=listings" class="btn btn--outline btn--icon"
                       title="Clear all filters" aria-label="Clear all filters">
                        <i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i>
                    </a>
                </div>
            </div>
        </form>

        <!-- ── Active filters ───────────────────────────────────
             Always visible when narrowing is in effect, so nobody
             wonders why a property they expected is missing. -->
        <?php if ($activeChips): ?>
            <div class="chips">
                <span class="chips__label">Filtered by:</span>
                <?php foreach ($activeChips as [$key, $label]): ?>
                    <span class="chip">
                        <?= sanitize($label) ?>
                        <a class="chip__x" href="<?= $listingsUrl([$key => null, 'p' => null]) ?>"
                           aria-label="Remove filter: <?= sanitize($label) ?>">
                            <i class="bi bi-x-lg" aria-hidden="true"></i>
                        </a>
                    </span>
                <?php endforeach; ?>
                <a class="chip chip--clear" href="<?= APP_URL ?>/index.php?page=listings">Clear all</a>
            </div>
        <?php endif; ?>

        <!-- ── Result toolbar ───────────────────────────────────  -->
        <div class="toolbar" id="results">
            <p class="toolbar__count">
                <?php if ($totalCount > 0): ?>
                    Showing <strong><?= number_format($firstResult) ?>–<?= number_format($lastResult) ?></strong>
                    of <strong><?= number_format($totalCount) ?></strong>
                    <?= $totalCount === 1 ? 'property' : 'properties' ?>
                <?php else: ?>
                    <strong>No properties</strong> match your search
                <?php endif; ?>
            </p>

            <div class="toolbar__right">
                <form method="GET" action="<?= APP_URL ?>/index.php"
                      style="display:flex;align-items:center;gap:8px">
                    <input type="hidden" name="page" value="listings">
                    <?php foreach ($filters as $k => $v):
                        if ($k === 'sort' || $k === 'status' || $v === '' || $v === null) continue; ?>
                        <input type="hidden" name="<?= sanitize($k) ?>" value="<?= sanitize((string) $v) ?>">
                    <?php endforeach; ?>

                    <label class="filters__label" for="f-sort" style="margin:0;white-space:nowrap">Sort by</label>
                    <select id="f-sort" name="sort" onchange="this.form.submit()"
                            style="height:38px;border:1px solid var(--border-strong);border-radius:var(--radius-sm);padding:0 32px 0 12px;font-size:var(--fs-sm);background:var(--surface);appearance:none;background-image:url(&quot;data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='none' stroke='%236b7688' stroke-width='1.6' stroke-linecap='round' stroke-linejoin='round' d='M3 4.5L6 7.5l3-3'/%3E%3C/svg%3E&quot;);background-repeat:no-repeat;background-position:right 10px center">
                        <?php foreach ($sortLabels as $value => $label): ?>
                            <option value="<?= $value ?>" <?= $activeSort === $value ? 'selected' : '' ?>><?= $label ?></option>
                        <?php endforeach; ?>
                    </select>
                    <noscript><button type="submit" class="btn btn--outline btn--sm">Apply</button></noscript>
                </form>

                <div class="viewswitch" data-viewswitch role="group" aria-label="Result layout">
                    <button type="button" data-view="grid" aria-pressed="true" aria-label="Grid view" title="Grid view">
                        <i class="bi bi-grid-3x3-gap-fill" aria-hidden="true"></i>
                    </button>
                    <button type="button" data-view="list" aria-pressed="false" aria-label="List view" title="List view">
                        <i class="bi bi-list-ul" aria-hidden="true"></i>
                    </button>
                </div>
            </div>
        </div>

        <!-- ── Results ──────────────────────────────────────────  -->
        <?php if (empty($properties)): ?>
            <div class="state">
                <span class="state__icon" aria-hidden="true"><i class="bi bi-search"></i></span>
                <p class="state__title">No matching properties</p>
                <p class="state__desc">
                    Nothing matches this combination of filters right now. Try widening the
                    price range, or clearing a filter or two.
                </p>
                <div style="display:flex;gap:var(--space-2);flex-wrap:wrap;justify-content:center;margin-top:var(--space-3)">
                    <a href="<?= APP_URL ?>/index.php?page=listings" class="btn btn--primary">Clear filters</a>
                    <a href="<?= APP_URL ?>/index.php?page=contact" class="btn btn--outline">Tell us what you need</a>
                </div>
            </div>
        <?php else: ?>
            <div class="pgrid" id="resultsGrid">
                <?php foreach ($properties as $i => $p): ?>
                    <?php renderPropertyCard($p, $covers[(int) $p['id']] ?? null, $savedIds, $i < 3); ?>
                <?php endforeach; ?>
            </div>

            <?php if ($totalPages > 1): ?>
                <nav class="pager" aria-label="Property results pages">
                    <a class="pager__link <?= $currentPage <= 1 ? 'is-disabled' : '' ?>"
                       href="<?= $listingsUrl(['p' => max(1, $currentPage - 1)]) ?>"
                       <?= $currentPage <= 1 ? 'aria-disabled="true" tabindex="-1"' : '' ?>
                       aria-label="Previous page">
                        <i class="bi bi-chevron-left" aria-hidden="true"></i>
                    </a>

                    <?php for ($i = 1; $i <= $totalPages; $i++):
                        $isEdge = $i === 1 || $i === $totalPages;
                        $isNear = abs($i - $currentPage) <= 1;
                        if ($isEdge || $isNear): ?>
                            <a class="pager__link <?= $i === $currentPage ? 'is-active' : '' ?>"
                               href="<?= $listingsUrl(['p' => $i]) ?>"
                               <?= $i === $currentPage ? 'aria-current="page"' : '' ?>
                               aria-label="Page <?= $i ?>"><?= $i ?></a>
                        <?php elseif (abs($i - $currentPage) === 2): ?>
                            <span class="pager__gap" aria-hidden="true">…</span>
                        <?php endif;
                    endfor; ?>

                    <a class="pager__link <?= $currentPage >= $totalPages ? 'is-disabled' : '' ?>"
                       href="<?= $listingsUrl(['p' => min($totalPages, $currentPage + 1)]) ?>"
                       <?= $currentPage >= $totalPages ? 'aria-disabled="true" tabindex="-1"' : '' ?>
                       aria-label="Next page">
                        <i class="bi bi-chevron-right" aria-hidden="true"></i>
                    </a>
                </nav>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</section>
