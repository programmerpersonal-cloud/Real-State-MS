<?php
/**
 * Public Marketing Layout
 * Expects: $publicView (one of: home, listings, listing, about, contact, 404)
 * Other variables (data) are set in index.php before requiring this layout.
 */
$publicView = $publicView ?? 'home';

$pageTitleMap = [
    'home'     => 'Find your next home',
    'listings' => 'Properties for sale & rent',
    'listing'  => $property['title'] ?? 'Property',
    'about'    => 'About us',
    'services' => 'Our services',
    'service'  => $service['title'] ?? 'Service',
    'agents'   => 'Our agents',
    'agent'    => $agent['full_name'] ?? 'Agent',
    'contact'  => 'Contact us',
    'privacy'  => 'Privacy policy',
    'terms'    => 'Terms of service',
    'thanks'   => 'Thank you — enquiry received',
    '404'      => 'Page not found',
];
$publicTitle = $pageTitleMap[$publicView] ?? companyName();

// Per-page meta description, so each page has its own search snippet
// rather than every URL sharing one generic sentence.
$metaMap = [
    'home'     => 'Browse verified homes, apartments and commercial property for sale and rent. Search by location, type and price, and speak to a trusted ' . companyName() . ' agent.',
    'listings' => 'Search verified properties for sale and rent. Filter by location, property type, price, bedrooms and bathrooms to find your next home.',
    'about'    => 'Meet the team behind ' . companyName() . ' — a modern real estate platform for agencies, owners, tenants and buyers.',
    'services' => 'Buying advisory, selling and marketing, leasing, property management, investment advisory and valuation — handled end to end by one ' . companyName() . ' team.',
    'agents'   => 'Browse the ' . companyName() . ' agents, see how many properties each one represents, and contact them directly.',
    'contact'  => 'Get in touch with the ' . companyName() . ' team about a listing, a viewing, onboarding your agency, or support.',
    'privacy'  => 'How ' . companyName() . ' collects, uses, shares and retains your personal data, and the rights you have over it.',
    'terms'    => 'The terms governing use of the ' . companyName() . ' website, accounts, listings and services.',
    'thanks'   => 'Your enquiry has been received. A ' . companyName() . ' agent will reply within ' . BIZ_RESPONSE_HOURS . ' hours on business days.',
];
$metaDescription = $metaMap[$publicView] ?? '';

if ($publicView === 'service' && !empty($service)) {
    $metaDescription = $service['lede'];
}
if ($publicView === 'agent' && !empty($agent)) {
    $metaDescription = sprintf(
        '%s is a property agent at %s%s. See their available listings and get in touch directly.',
        $agent['full_name'],
        companyName(),
        !empty($agent['branch_name']) ? ', ' . $agent['branch_name'] : ''
    );
}
if ($publicView === 'listing' && !empty($property)) {
    $metaDescription = trim(sprintf(
        '%s in %s — %d bed, %d bath%s. View photos, full specifications and contact the listing agent on %s.',
        $property['title'] ?? 'Property',
        $property['location'] ?: 'a prime location',
        (int) ($property['num_rooms'] ?? 0),
        (int) ($property['num_bathrooms'] ?? 0),
        !empty($property['size_sqm']) ? ', ' . (int) $property['size_sqm'] . ' m²' : '',
        companyName()
    ));
}

$viewPath = VIEWS_PATH . '/public/' . $publicView . '.php';

/* ─── Canonical URL ──────────────────────────────────────────────────────
   Built from the route rather than echoed from REQUEST_URI, so tracking
   parameters (?utm_source, ?fbclid) and filter permutations all consolidate
   onto one indexable address instead of splitting ranking signals. */
$canonicalParams = [];
if ($publicView === 'listing' && !empty($property)) $canonicalParams = ['id' => (int) $property['id']];
if ($publicView === 'agent'   && !empty($agent))    $canonicalParams = ['id' => (int) $agent['id']];
if ($publicView === 'service' && !empty($serviceSlug)) $canonicalParams = ['id' => $serviceSlug];
$canonicalUrl = $publicView === '404'
    ? APP_URL . '/index.php?page=home'
    : pageUrl($publicView === '404' ? 'home' : $publicView, $canonicalParams);

/* ─── Social share image ─────────────────────────────────────────────────
   A listing shares its own cover photo; everything else shares the brand
   card. Absolute URLs only — social crawlers ignore relative paths. */
$shareImage = SOCIAL_IMAGE;
if ($publicView === 'listing' && !empty($property)) {
    $shareImage = propertyImage($property, ['path' => $images[0]['file_path'] ?? null]);
} elseif ($publicView === 'agent' && !empty($agent)) {
    $shareImage = agentImage($agent);
}

/* ─── Structured data ────────────────────────────────────────────────────
   One @graph per page. The organisation node is always present and every
   other node references it by @id, so the entity is declared once. */
$crumbTrail = ['Home' => pageUrl('home')];
switch ($publicView) {
    case 'listings': $crumbTrail['Properties'] = null; break;
    case 'listing':
        $crumbTrail['Properties'] = pageUrl('listings');
        $crumbTrail[$property['title'] ?: 'Property'] = null;
        break;
    case 'services': $crumbTrail['Services'] = null; break;
    case 'service':
        $crumbTrail['Services'] = pageUrl('services');
        $crumbTrail[$service['title']] = null;
        break;
    case 'agents': $crumbTrail['Agents'] = null; break;
    case 'agent':
        $crumbTrail['Agents'] = pageUrl('agents');
        $crumbTrail[$agent['full_name']] = null;
        break;
    case 'about':   $crumbTrail['About'] = null; break;
    case 'contact': $crumbTrail['Contact'] = null; break;
    case 'privacy': $crumbTrail['Privacy'] = null; break;
    case 'terms':   $crumbTrail['Terms'] = null; break;
    case 'thanks':  $crumbTrail['Thank you'] = null; break;
}

$schemaNodes = [schemaOrganization()];

// WebSite node enables the sitelinks search box for the listings search.
if ($publicView === 'home') {
    $schemaNodes[] = [
        '@type'  => 'WebSite',
        '@id'    => APP_URL . '/#website',
        'url'    => APP_URL . '/',
        'name'   => BIZ_LEGAL_NAME,
        'publisher' => ['@id' => APP_URL . '/#organization'],
        'potentialAction' => [
            '@type'       => 'SearchAction',
            'target'      => [
                '@type'       => 'EntryPoint',
                'urlTemplate' => APP_URL . '/index.php?page=listings&search={search_term_string}',
            ],
            'query-input' => 'required name=search_term_string',
        ],
    ];
    // Ratings are attached to the organisation only when real approved
    // reviews exist — never as decoration.
    if (!empty($homeReviews)) {
        $schemaNodes[0]['aggregateRating'] = schemaAggregateRating($homeReviews);
        $schemaNodes[0]['review']          = schemaReviews($homeReviews);
    }
}
if (count($crumbTrail) > 1) {
    $schemaNodes[] = schemaBreadcrumbs($crumbTrail);
}
if ($publicView === 'listing' && !empty($property)) {
    $schemaNodes[] = schemaListing($property, $photos ?? [$shareImage]);
}
if ($publicView === 'agent' && !empty($agent)) {
    $schemaNodes[] = schemaAgent($agent, $shareImage);
}
if (!empty($pageFaqs)) {
    $schemaNodes[] = schemaFaq($pageFaqs);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= sanitize($publicTitle) ?> — <?= sanitize(companyName()) ?></title>
    <meta name="description" content="<?= sanitize($metaDescription) ?>">
    <meta name="theme-color" content="#0075c0">

    <link rel="canonical" href="<?= sanitize($canonicalUrl) ?>">
    <?php /* A 404 must never be indexed, whatever else it links to. */ ?>
    <?php if ($publicView === '404' || $publicView === 'thanks'): ?>
        <meta name="robots" content="noindex, follow">
    <?php else: ?>
        <meta name="robots" content="index, follow, max-image-preview:large">
    <?php endif; ?>

    <!-- Open Graph — Facebook, LinkedIn, WhatsApp, Slack -->
    <meta property="og:type" content="<?= $publicView === 'listing' ? 'product' : 'website' ?>">
    <meta property="og:site_name" content="<?= sanitize(companyName()) ?>">
    <meta property="og:locale" content="en_US">
    <meta property="og:url" content="<?= sanitize($canonicalUrl) ?>">
    <meta property="og:title" content="<?= sanitize($publicTitle) ?> — <?= sanitize(companyName()) ?>">
    <meta property="og:description" content="<?= sanitize($metaDescription) ?>">
    <meta property="og:image" content="<?= sanitize($shareImage) ?>">
    <meta property="og:image:width" content="<?= SOCIAL_IMAGE_W ?>">
    <meta property="og:image:height" content="<?= SOCIAL_IMAGE_H ?>">
    <meta property="og:image:alt" content="<?= sanitize($publicTitle) ?> — <?= sanitize(BIZ_LEGAL_NAME) ?>">

    <!-- X / Twitter -->
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="<?= sanitize($publicTitle) ?> — <?= sanitize(companyName()) ?>">
    <meta name="twitter:description" content="<?= sanitize($metaDescription) ?>">
    <meta name="twitter:image" content="<?= sanitize($shareImage) ?>">

    <!-- Structured data: one @graph, organisation declared once by @id. -->
    <?= renderSchemaGraph($schemaNodes) ?>

    <!-- Fonts and icons are self-hosted; preloaded so first paint isn't
         blocked waiting on a font swap. -->
    <link rel="preload" href="<?= VENDOR_URL ?>/inter/fonts/inter-latin.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="preload" href="<?= VENDOR_URL ?>/raleway/fonts/raleway-latin.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="preload" href="<?= VENDOR_URL ?>/bootstrap-icons/fonts/bootstrap-icons.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="stylesheet" href="<?= VENDOR_URL ?>/bootstrap-icons/bootstrap-icons.min.css">
    <link rel="stylesheet" href="<?= CSS_URL ?>/style.css">

    <?php /* Google Analytics 4.
             Only emitted when GA_MEASUREMENT_ID is actually set, so a dev
             machine loads no third-party script and never pollutes the
             property with local traffic. IP anonymisation is on and ad
             signals are off — this is a property site, not an ad network. */ ?>
    <?php if (GA_MEASUREMENT_ID !== ''): ?>
        <link rel="preconnect" href="https://www.googletagmanager.com" crossorigin>
        <script async src="https://www.googletagmanager.com/gtag/js?id=<?= rawurlencode(GA_MEASUREMENT_ID) ?>"></script>
        <script>
          window.dataLayer = window.dataLayer || [];
          function gtag(){dataLayer.push(arguments);}
          gtag('js', new Date());
          gtag('config', <?= json_encode(GA_MEASUREMENT_ID) ?>, {
            anonymize_ip: true,
            allow_google_signals: false,
            allow_ad_personalization_signals: false
          });
        </script>
    <?php endif; ?>
</head>
<body class="public-body">

    <?php require VIEWS_PATH . '/public/components/navbar.php'; ?>

    <main id="main">
        <?php $flashHtml = renderFlash(); ?>
        <?php if ($flashHtml): ?>
            <div class="site-container" style="padding-top:var(--space-4)"><?= $flashHtml ?></div>
        <?php endif; ?>

        <?php if (file_exists($viewPath)): ?>
            <?php require $viewPath; ?>
        <?php else: ?>
            <section class="page-hero">
                <div class="site-container">
                    <span class="eyebrow">Error 404</span>
                    <h1 class="page-hero__title">We couldn't find that page</h1>
                    <p class="page-hero__lede">
                        The page you're looking for doesn't exist, or the listing may have been
                        taken down. Try browsing our current properties instead.
                    </p>
                    <div style="display:flex;gap:var(--space-3);justify-content:center;flex-wrap:wrap;margin-top:var(--space-6)">
                        <a href="<?= APP_URL ?>/index.php?page=listings" class="btn btn--primary btn--lg">
                            <i class="bi bi-search" aria-hidden="true"></i> Browse properties
                        </a>
                        <a href="<?= APP_URL ?>/index.php?page=home" class="btn btn--outline btn--lg">Back to home</a>
                    </div>
                </div>
            </section>
        <?php endif; ?>
    </main>

    <?php require VIEWS_PATH . '/public/components/footer.php'; ?>

    <?php require VIEWS_PATH . '/public/components/sticky_cta.php'; ?>

    <!-- Lightbox shell. Empty until the gallery script fills it. -->
    <div class="lightbox" id="lightbox" role="dialog" aria-modal="true" aria-label="Property photo" hidden>
        <button type="button" class="lightbox__close" aria-label="Close photo viewer">
            <i class="bi bi-x-lg" aria-hidden="true"></i>
        </button>
        <button type="button" class="lightbox__nav lightbox__nav--prev" data-lightbox-nav="prev" aria-label="Previous photo">
            <i class="bi bi-chevron-left" aria-hidden="true"></i>
        </button>
        <img src="" alt="">
        <button type="button" class="lightbox__nav lightbox__nav--next" data-lightbox-nav="next" aria-label="Next photo">
            <i class="bi bi-chevron-right" aria-hidden="true"></i>
        </button>
        <span class="lightbox__count" data-lightbox-count></span>
    </div>

    <script src="<?= JS_URL ?>/public.js" defer></script>
</body>
</html>
