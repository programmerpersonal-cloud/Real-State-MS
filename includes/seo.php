<?php
/**
 * SEO output helpers — robots.txt, sitemap.xml and JSON-LD structured data.
 *
 * Everything here emits absolute URLs built from APP_URL, so the same code is
 * correct on localhost, a LAN IP and production without per-environment edits.
 */

/** Absolute URL for a public page, correctly escaped for XML/HTML output. */
function pageUrl(string $page, array $params = []): string
{
    $q = array_merge(['page' => $page], $params);
    return APP_URL . '/index.php?' . http_build_query($q);
}

/* ─── robots.txt ──────────────────────────────────────────────────────── */

/**
 * Emits robots.txt. Authenticated areas are disallowed because they redirect
 * anonymous crawlers to a sign-in screen — indexing them burns crawl budget
 * and can surface a login page in results.
 */
function renderRobotsTxt(): void
{
    header('Content-Type: text/plain; charset=UTF-8');

    $private = [
        'dashboard', 'login', 'register', 'logout', 'profile', 'favorites',
        'notifications', 'settings', 'users', 'audit', 'reports', 'payments',
        'leases', 'sales', 'maintenance', 'inquiries', 'customers', 'owners',
        'branches', 'reservations',
        // Post-conversion page: no standalone search value, and organic
        // entrances here would corrupt goal reporting.
        'thanks',
    ];

    echo "# " . companyName() . " — crawler policy\n\n";
    echo "User-agent: *\n";
    echo "Allow: /\n\n";

    echo "# Authenticated areas — nothing here is useful in an index.\n";
    foreach ($private as $p) {
        echo "Disallow: /index.php?page={$p}\n";
    }

    echo "\n# Application source and private file stores.\n";
    foreach (['/config/', '/includes/', '/controllers/', '/models/', '/database/',
              '/assets/uploads/documents/'] as $dir) {
        echo "Disallow: {$dir}\n";
    }

    echo "\n# Faceted filters produce near-duplicates; the unfiltered listings\n";
    echo "# page and each individual listing are the canonical entry points.\n";
    echo "Disallow: /*?*sort=\n";
    echo "Disallow: /*?*p=\n";

    echo "\nSitemap: " . APP_URL . "/sitemap.xml\n";
}

/* ─── sitemap.xml ─────────────────────────────────────────────────────── */

/**
 * Emits a sitemap covering the marketing pages, every service, every active
 * agent and every published property. Only URLs that return 200 to an
 * anonymous visitor are included — a sitemap listing redirects or 404s is
 * treated as a quality signal against the site.
 */
function renderSitemapXml(): void
{
    header('Content-Type: application/xml; charset=UTF-8');

    $urls = [];
    $add = function (string $loc, string $changefreq, string $priority, ?string $lastmod = null) use (&$urls) {
        $urls[] = compact('loc', 'changefreq', 'priority', 'lastmod');
    };

    // Static marketing pages, ordered by importance.
    $add(pageUrl('home'),     'daily',   '1.0');
    $add(pageUrl('listings'), 'daily',   '0.9');
    $add(pageUrl('services'), 'monthly', '0.8');
    $add(pageUrl('agents'),   'weekly',  '0.7');
    $add(pageUrl('about'),    'monthly', '0.6');
    $add(pageUrl('contact'),  'monthly', '0.6');
    $add(pageUrl('privacy'),  'yearly',  '0.3');
    $add(pageUrl('terms'),    'yearly',  '0.3');

    foreach (array_keys(siteServices()) as $slug) {
        $add(pageUrl('service', ['id' => $slug]), 'monthly', '0.7');
    }

    try {
        $db = getDBConnection();

        // Published, non-archived properties only.
        $stmt = $db->query("
            SELECT id, updated_at
            FROM properties
            WHERE is_archived = 0 AND status = 'available'
            ORDER BY updated_at DESC
            LIMIT 5000
        ");
        foreach ($stmt as $row) {
            $add(
                pageUrl('listing', ['id' => (int) $row['id']]),
                'weekly', '0.8',
                !empty($row['updated_at']) ? date('Y-m-d', strtotime((string) $row['updated_at'])) : null
            );
        }

        // Active agents with a real name — the profile page 404s otherwise.
        $stmt = $db->query("
            SELECT u.id
            FROM users u
            JOIN roles r ON u.role_id = r.id
            WHERE r.name = 'agent' AND u.is_active = 1
              AND TRIM(COALESCE(u.full_name, '')) <> ''
        ");
        foreach ($stmt as $row) {
            $add(pageUrl('agent', ['id' => (int) $row['id']]), 'weekly', '0.6');
        }
    } catch (Throwable $e) {
        // A database outage should still yield a valid sitemap of static pages.
    }

    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
    foreach ($urls as $u) {
        echo "  <url>\n";
        echo '    <loc>' . htmlspecialchars($u['loc'], ENT_XML1) . "</loc>\n";
        if ($u['lastmod']) {
            echo '    <lastmod>' . $u['lastmod'] . "</lastmod>\n";
        }
        echo '    <changefreq>' . $u['changefreq'] . "</changefreq>\n";
        echo '    <priority>' . $u['priority'] . "</priority>\n";
        echo "  </url>\n";
    }
    echo '</urlset>' . "\n";
}

/* ─── Structured data (JSON-LD) ───────────────────────────────────────── */

/** Encode a schema graph node the way Google's parser expects. */
function jsonLd(array $data): string
{
    return json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
}

/**
 * The organisation node, reused as the publisher/provider reference by every
 * other schema type on the site. RealEstateAgent is a subtype of LocalBusiness,
 * so this satisfies both the "local business" and "who published this" needs
 * with one consistent entity and one @id.
 */
function schemaOrganization(): array
{
    $hours = [];
    foreach (BIZ_HOURS as [$days, $opens, $closes]) {
        $hours[] = [
            '@type'     => 'OpeningHoursSpecification',
            'dayOfWeek' => $days,
            'opens'     => $opens,
            'closes'    => $closes,
        ];
    }

    return [
        '@type'  => 'RealEstateAgent',
        '@id'    => APP_URL . '/#organization',
        'name'   => BIZ_LEGAL_NAME,
        'url'    => APP_URL . '/',
        'logo'   => SOCIAL_IMAGE,
        'image'  => SOCIAL_IMAGE,
        'email'  => BIZ_EMAIL,
        'telephone' => BIZ_PHONE,
        'priceRange' => BIZ_PRICE_RANGE,
        'address' => array_filter([
            '@type'           => 'PostalAddress',
            'streetAddress'   => BIZ_STREET,
            'addressLocality' => BIZ_CITY,
            'addressRegion'   => BIZ_REGION,
            'postalCode'      => BIZ_POSTAL,
            'addressCountry'  => BIZ_COUNTRY,
        ]),
        'geo' => [
            '@type'     => 'GeoCoordinates',
            'latitude'  => BIZ_LAT,
            'longitude' => BIZ_LNG,
        ],
        'openingHoursSpecification' => $hours,
        'areaServed' => [
            '@type' => 'City',
            'name'  => BIZ_CITY,
        ],
    ];
}

/** BreadcrumbList from an ordered [label => url|null] trail. */
function schemaBreadcrumbs(array $trail): array
{
    $items = [];
    $pos   = 1;
    foreach ($trail as $label => $url) {
        $item = [
            '@type'    => 'ListItem',
            'position' => $pos++,
            'name'     => $label,
        ];
        // The current page is the last crumb and carries no item URL.
        if ($url) $item['item'] = $url;
        $items[] = $item;
    }
    return ['@type' => 'BreadcrumbList', 'itemListElement' => $items];
}

/** FAQPage from [question => answer] pairs. */
function schemaFaq(array $faqs): array
{
    $items = [];
    foreach ($faqs as $q => $a) {
        $items[] = [
            '@type'          => 'Question',
            'name'           => $q,
            'acceptedAnswer' => ['@type' => 'Answer', 'text' => $a],
        ];
    }
    return ['@type' => 'FAQPage', 'mainEntity' => $items];
}

/**
 * A property listing. Uses Product + Offer because that is what search
 * engines actually parse for price, availability and images; RealEstateListing
 * is emitted alongside it for the property-specific attributes.
 */
function schemaListing(array $property, array $photoUrls): array
{
    $price = propertyPrice($property);
    $avail = ($property['status'] ?? '') === 'available'
        ? 'https://schema.org/InStock'
        : 'https://schema.org/SoldOut';

    return [
        '@type'       => 'Product',
        'name'        => $property['title'] ?: 'Property',
        'description' => mb_substr(trim((string) ($property['description'] ?? '')), 0, 500)
                         ?: 'Property available through ' . BIZ_LEGAL_NAME . '.',
        'image'       => array_slice($photoUrls, 0, 6),
        'category'    => categoryLabel((string) ($property['category'] ?? '')),
        'offers'      => [
            '@type'         => 'Offer',
            'price'         => (string) $price['amount'],
            'priceCurrency' => currencyCode(),
            'availability'  => $avail,
            'url'           => pageUrl('listing', ['id' => (int) $property['id']]),
            'seller'        => ['@id' => APP_URL . '/#organization'],
        ],
        'additionalProperty' => array_values(array_filter([
            !empty($property['num_rooms']) ? [
                '@type' => 'PropertyValue', 'name' => 'Bedrooms', 'value' => (int) $property['num_rooms'],
            ] : null,
            !empty($property['num_bathrooms']) ? [
                '@type' => 'PropertyValue', 'name' => 'Bathrooms', 'value' => (int) $property['num_bathrooms'],
            ] : null,
            !empty($property['size_sqm']) ? [
                '@type' => 'PropertyValue', 'name' => 'Floor area',
                'value' => (float) $property['size_sqm'], 'unitCode' => 'MTK',
            ] : null,
        ])),
    ];
}

/** A person node for an agent profile. */
function schemaAgent(array $agent, string $photo): array
{
    return array_filter([
        '@type'       => 'RealEstateAgent',
        'name'        => $agent['full_name'],
        'image'       => $photo,
        'email'       => $agent['email'] ?? null,
        'telephone'   => $agent['phone'] ?? null,
        'url'         => pageUrl('agent', ['id' => (int) $agent['id']]),
        'worksFor'    => ['@id' => APP_URL . '/#organization'],
        'areaServed'  => ['@type' => 'City', 'name' => BIZ_CITY],
    ]);
}

/**
 * Aggregate rating from approved testimonials. Returned only when there is
 * something real to aggregate — emitting a rating with no reviews behind it
 * is a manual-action risk, not a shortcut.
 */
function schemaAggregateRating(array $reviews): ?array
{
    $n = count($reviews);
    if ($n === 0) return null;

    $sum = array_sum(array_map(fn($r) => (int) $r['rating'], $reviews));
    return [
        '@type'       => 'AggregateRating',
        'ratingValue' => round($sum / $n, 1),
        'reviewCount' => $n,
        'bestRating'  => 5,
        'worstRating' => 1,
    ];
}

/** Review nodes for approved testimonials. */
function schemaReviews(array $reviews): array
{
    return array_map(fn($r) => array_filter([
        '@type'         => 'Review',
        'reviewRating'  => [
            '@type' => 'Rating', 'ratingValue' => (int) $r['rating'],
            'bestRating' => 5, 'worstRating' => 1,
        ],
        'author'        => ['@type' => 'Person', 'name' => $r['author_name']],
        'reviewBody'    => $r['body'],
        'datePublished' => !empty($r['created_at'])
            ? date('Y-m-d', strtotime((string) $r['created_at'])) : null,
    ]), $reviews);
}

/**
 * Wraps every node for the current page into a single @graph document.
 * One script tag beats several: the parser resolves @id references between
 * nodes, so the organisation is declared once and pointed at from the rest.
 */
function renderSchemaGraph(array $nodes): string
{
    $graph = array_values(array_filter($nodes));
    if (!$graph) return '';

    return '<script type="application/ld+json">'
        . jsonLd(['@context' => 'https://schema.org', '@graph' => $graph])
        . '</script>';
}
