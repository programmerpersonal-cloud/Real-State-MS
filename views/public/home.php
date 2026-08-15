<?php
/**
 * Homepage (Public)
 *
 * Provided by index.php:
 *   $spotlightProperty, $featuredProperties, $homeCovers, $categoryCounts,
 *   $featuredAgents, $statTotal, $statAvailable, $statCustomers, $statBranches
 *
 * Everything on this page is driven by real records. Where the database
 * is empty, sections degrade to an honest empty state rather than
 * inventing placeholder listings.
 */

$savedIds = savedPropertyIds();
$covers   = $homeCovers ?? [];

/** Cover metadata for a property id. */
$coverOf = fn(int $id): ?array => $covers[$id] ?? null;

// Hero imagery reuses listings already on the page — no extra queries.
// Every listing resolves to a picture (uploaded cover, else seeded stock),
// so the hero no longer collapses on a fresh install with no uploads.
$heroPool = array_values(array_filter(
    array_merge([$spotlightProperty ?? null], $featuredProperties ?? []),
    fn($p) => (bool) $p
));
$heroLead   = $heroPool[0] ?? null;
$heroSide   = array_slice($heroPool, 1, 2);
$leadAgent  = $featuredAgents[0] ?? null;

$categoryOrder = ['apartment', 'house', 'villa', 'office', 'commercial', 'land'];
$hasListings   = !empty($spotlightProperty) || !empty($featuredProperties);
?>

<!-- ══════════════════════════════════════════════════════════
     HERO — search is the primary call to action
     ══════════════════════════════════════════════════════════ -->
<section class="hero">
    <div class="site-container hero__inner">

        <div class="hero__head">
            <span class="hero__badge">
                <span class="pip">Verified</span>
                <span>Every listing reviewed before it goes live</span>
            </span>
            <h1 class="hero__title">Find a place you'll love to <em>call home</em></h1>
            <p class="hero__lede">
                Browse verified homes, apartments and commercial space for sale and rent —
                and talk directly to the agent who knows the property.
            </p>
        </div>

        <!-- Search. Submits straight to the listings page, so results are
             a real, shareable, bookmarkable URL. -->
        <form class="searchbar" method="GET" action="<?= APP_URL ?>/index.php" role="search"
              aria-label="Property search">
            <input type="hidden" name="page" value="listings">
            <div class="searchbar__grid">

                <div class="searchbar__field searchbar__field--wide">
                    <label class="searchbar__label" for="hs-location">Location</label>
                    <div class="searchbar__control">
                        <i class="bi bi-geo-alt" aria-hidden="true"></i>
                        <input type="search" id="hs-location" name="search"
                               placeholder="City, area or property code">
                    </div>
                </div>

                <div class="searchbar__field">
                    <label class="searchbar__label" for="hs-category">Property type</label>
                    <div class="searchbar__control">
                        <i class="bi bi-house" aria-hidden="true"></i>
                        <select id="hs-category" name="category">
                            <option value="">Any type</option>
                            <?php foreach ($categoryOrder as $cat): ?>
                                <option value="<?= $cat ?>"><?= categoryLabel($cat) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="searchbar__field">
                    <label class="searchbar__label" for="hs-type">Buy or rent</label>
                    <div class="searchbar__control">
                        <i class="bi bi-tag" aria-hidden="true"></i>
                        <select id="hs-type" name="property_type">
                            <option value="">Buy or rent</option>
                            <option value="sale">For sale</option>
                            <option value="rent">For rent</option>
                        </select>
                    </div>
                </div>

                <div class="searchbar__field">
                    <label class="searchbar__label" for="hs-beds">Bedrooms</label>
                    <div class="searchbar__control">
                        <i class="bi bi-door-open" aria-hidden="true"></i>
                        <select id="hs-beds" name="min_rooms">
                            <option value="">Any</option>
                            <?php foreach ([1, 2, 3, 4, 5] as $n): ?>
                                <option value="<?= $n ?>"><?= $n ?>+ beds</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <button type="submit" class="searchbar__submit">
                    <i class="bi bi-search" aria-hidden="true"></i> Search
                </button>
            </div>
        </form>

        <?php /* Sits directly under the primary CTA, where it answers the
                 "can I trust this?" objection at the moment of the click. */ ?>
        <?php require VIEWS_PATH . '/public/components/trust_bar.php'; ?>

        <?php if ($heroLead): ?>
            <!-- Hero gallery: the newest available listing, its neighbours,
                 and the agent responsible for it. -->
            <div class="hero-gallery">
                <?php
                $leadPrice = propertyPrice($heroLead);
                $leadImg   = propertyImage($heroLead, $coverOf((int) $heroLead['id']));
                ?>
                <a class="hero-shot hero-shot--lead"
                   href="<?= APP_URL ?>/index.php?page=listing&amp;id=<?= (int) $heroLead['id'] ?>">
                    <img src="<?= sanitize($leadImg) ?>"
                         alt="<?= sanitize($heroLead['title']) ?>"
                         loading="eager" fetchpriority="high" decoding="async"
                         width="820" height="560">
                    <span class="hero-shot__overlay">
                        <span class="hero-shot__flag"><?= $leadPrice['label'] ?></span>
                        <span class="hero-shot__price">
                            <?= formatCurrency($leadPrice['amount']) ?>
                        </span>
                    </span>
                    <span class="hero-shot__caption">
                        <strong><?= sanitize($heroLead['title']) ?></strong>
                        <span>
                            <i class="bi bi-geo-alt" aria-hidden="true"></i>
                            <?= sanitize($heroLead['location'] ?: 'Location on request') ?>
                        </span>
                    </span>
                </a>

                <div class="hero-gallery__aside">
                    <?php foreach ($heroSide as $side):
                        $sideImg = propertyImage($side, $coverOf((int) $side['id'])); ?>
                        <a class="hero-shot hero-shot--sm"
                           href="<?= APP_URL ?>/index.php?page=listing&amp;id=<?= (int) $side['id'] ?>"
                           aria-label="<?= sanitize($side['title']) ?>">
                            <img src="<?= sanitize($sideImg) ?>" alt="<?= sanitize($side['title']) ?>"
                                 loading="eager" decoding="async" width="420" height="300">
                        </a>
                    <?php endforeach; ?>

                    <?php if ($leadAgent): ?>
                        <div class="hero-agent">
                            <div class="hero-agent__top">
                                <img class="hero-agent__avatar" src="<?= sanitize(agentImage($leadAgent)) ?>"
                                     alt="" loading="lazy" decoding="async" width="46" height="46">
                                <div>
                                    <div class="hero-agent__name"><?= sanitize($leadAgent['full_name']) ?></div>
                                    <div class="hero-agent__role">
                                        Listing agent<?= $leadAgent['branch_name'] ? ' · ' . sanitize($leadAgent['branch_name']) : '' ?>
                                    </div>
                                </div>
                            </div>
                            <div class="hero-agent__meta">
                                <span><strong><?= (int) $leadAgent['listing_count'] ?></strong> active listings</span>
                                <a href="<?= APP_URL ?>/index.php?page=contact">Get in touch</a>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>

        <!-- Live figures from the database. -->
        <div class="hero-stats">
            <div class="hero-stat">
                <div class="hero-stat__value"><?= number_format((int) $statTotal) ?></div>
                <div class="hero-stat__label">Properties listed</div>
            </div>
            <div class="hero-stat">
                <div class="hero-stat__value"><?= number_format((int) $statAvailable) ?></div>
                <div class="hero-stat__label">Available now</div>
            </div>
            <div class="hero-stat">
                <div class="hero-stat__value"><?= number_format((int) $statCustomers) ?></div>
                <div class="hero-stat__label">Registered clients</div>
            </div>
            <div class="hero-stat">
                <div class="hero-stat__value"><?= number_format((int) $statBranches) ?></div>
                <div class="hero-stat__label">Branch offices</div>
            </div>
        </div>
    </div>
</section>

<?php if ($spotlightProperty):
    $spot      = $spotlightProperty;
    $spotId    = (int) $spot['id'];
    $spotPrice = propertyPrice($spot);
    $spotImg   = propertyImage($spot, $coverOf($spotId));
    $spotUrl   = sanitize(APP_URL . '/index.php?page=listing&id=' . $spotId);
?>
<!-- ══════════════════════════════════════════════════════════
     SPOTLIGHT — the newest available property, given room
     ══════════════════════════════════════════════════════════ -->
<section class="section section--muted">
    <div class="site-container">
        <div class="section-head section-head--split" data-reveal>
            <div class="section-head__body">
                <span class="eyebrow">Spotlight</span>
                <h2 class="section-title">The latest addition to our portfolio</h2>
                <p class="section-lede">
                    Freshly listed and ready to view — book a tour with the agent handling it.
                </p>
            </div>
            <a href="<?= APP_URL ?>/index.php?page=listings" class="btn btn--outline">
                View all properties <i class="bi bi-arrow-right" aria-hidden="true"></i>
            </a>
        </div>

        <article class="spotlight" data-reveal>
            <div class="spotlight__media">
                <img src="<?= sanitize($spotImg) ?>" alt="<?= sanitize($spot['title']) ?>"
                     loading="lazy" decoding="async" width="760" height="560">
                <div class="spotlight__tags">
                    <span class="tag tag--solid"><?= $spotPrice['label'] ?></span>
                    <span class="tag tag--glass"><?= ucfirst(sanitize($spot['category'])) ?></span>
                </div>
            </div>

            <div class="spotlight__body">
                <div class="spotlight__meta">
                    <span class="tag <?= statusTagClass((string) $spot['status']) ?>">
                        <?= ucfirst(sanitize($spot['status'])) ?>
                    </span>
                    <span style="font-size:var(--fs-xs);color:var(--text-subtle)">
                        Listed <?= formatDate($spot['created_at']) ?>
                    </span>
                </div>

                <h3 class="spotlight__title"><a href="<?= $spotUrl ?>"><?= sanitize($spot['title']) ?></a></h3>

                <p class="pcard__loc">
                    <i class="bi bi-geo-alt" aria-hidden="true"></i>
                    <span><?= sanitize($spot['location'] ?: 'Location on request') ?></span>
                </p>

                <?php if (!empty($spot['description'])): ?>
                    <p class="spotlight__desc"><?= sanitize($spot['description']) ?></p>
                <?php endif; ?>

                <ul class="spec-rail">
                    <li><i class="bi bi-door-open" aria-hidden="true"></i> <?= (int) $spot['num_rooms'] ?> bedrooms</li>
                    <li><i class="bi bi-droplet" aria-hidden="true"></i> <?= (int) $spot['num_bathrooms'] ?> bathrooms</li>
                    <?php if (!empty($spot['size_sqm'])): ?>
                        <li><i class="bi bi-rulers" aria-hidden="true"></i> <?= number_format((float) $spot['size_sqm']) ?> m²</li>
                    <?php endif; ?>
                </ul>

                <div class="spotlight__footer">
                    <div class="spotlight__price">
                        <?= formatCurrency($spotPrice['amount']) ?>
                        <?php if ($spotPrice['suffix']): ?><small><?= $spotPrice['suffix'] ?></small><?php endif; ?>
                    </div>
                    <div class="spotlight__actions">
                        <a href="<?= $spotUrl ?>" class="btn btn--primary">
                            <i class="bi bi-calendar-check" aria-hidden="true"></i> Book a viewing
                        </a>
                        <a href="<?= $spotUrl ?>" class="btn btn--outline">Details</a>
                    </div>
                </div>
            </div>
        </article>
    </div>
</section>
<?php endif; ?>

<!-- ══════════════════════════════════════════════════════════
     BROWSE BY TYPE — counts come straight from the database
     ══════════════════════════════════════════════════════════ -->
<?php if (!empty($categoryCounts)): ?>
<section class="section">
    <div class="site-container">
        <div class="section-head" data-reveal>
            <span class="eyebrow">Browse by type</span>
            <h2 class="section-title">What kind of space are you looking for?</h2>
            <p class="section-lede">Jump straight to the category that fits — every count is live.</p>
        </div>

        <div class="cat-grid">
            <?php $delay = 0; foreach ($categoryOrder as $cat):
                $count = $categoryCounts[$cat] ?? 0;
                if ($count === 0) continue; ?>
                <a class="cat-tile"
                   href="<?= APP_URL ?>/index.php?page=listings&amp;category=<?= $cat ?>"
                   data-reveal data-reveal-delay="<?= $delay ?>">
                    <span class="cat-tile__icon" aria-hidden="true"><i class="<?= categoryIcon($cat) ?>"></i></span>
                    <span class="cat-tile__name"><?= categoryLabel($cat) ?></span>
                    <span class="cat-tile__count">
                        <?= number_format($count) ?> available
                    </span>
                </a>
            <?php $delay += 60; endforeach; ?>
        </div>
    </div>
</section>
<?php endif; ?>

<!-- ══════════════════════════════════════════════════════════
     FEATURED PROPERTIES
     ══════════════════════════════════════════════════════════ -->
<section class="section <?= empty($categoryCounts) ? '' : 'section--muted' ?>">
    <div class="site-container">
        <div class="section-head section-head--split" data-reveal>
            <div class="section-head__body">
                <span class="eyebrow">Featured</span>
                <h2 class="section-title">Hand-picked properties</h2>
                <p class="section-lede">
                    Recently listed homes and commercial space, each one verified by our team.
                </p>
            </div>
            <a href="<?= APP_URL ?>/index.php?page=listings" class="btn btn--outline">
                Browse all <i class="bi bi-arrow-right" aria-hidden="true"></i>
            </a>
        </div>

        <?php if (empty($featuredProperties)): ?>
            <div class="state">
                <span class="state__icon" aria-hidden="true"><i class="bi bi-buildings"></i></span>
                <p class="state__title">No properties published yet</p>
                <p class="state__desc">
                    New listings appear here the moment an agent publishes and approves them.
                </p>
                <?php if (!isLoggedIn()): ?>
                    <a href="<?= APP_URL ?>/index.php?page=register" class="btn btn--primary" style="margin-top:var(--space-3)">
                        List your property
                    </a>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="pgrid">
                <?php $delay = 0; foreach ($featuredProperties as $p): ?>
                    <div data-reveal data-reveal-delay="<?= $delay ?>">
                        <?php renderPropertyCard($p, $coverOf((int) $p['id']), $savedIds); ?>
                    </div>
                <?php $delay += 60; endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</section>

<!-- ══════════════════════════════════════════════════════════
     WHY US
     ══════════════════════════════════════════════════════════ -->
<section class="section <?= empty($categoryCounts) ? 'section--muted' : '' ?>">
    <div class="site-container">
        <div class="section-head" data-reveal>
            <span class="eyebrow">Why <?= sanitize(companyName()) ?></span>
            <h2 class="section-title">Property search without the guesswork</h2>
            <p class="section-lede">
                From first search to signed lease, every step is handled on one platform —
                with a named person accountable at each stage.
            </p>
        </div>

        <div class="value-grid">
            <?php
            $values = [
                ['bi-patch-check', '', 'Verified listings', 'Every property is reviewed and approved by our team before it appears publicly. No ghost listings, no bait pricing.'],
                ['bi-person-badge', '--green', 'A named agent', 'Each listing shows the agent responsible for it, with direct contact details. You always know who you are dealing with.'],
                ['bi-file-earmark-text', '--gold', 'Paperwork handled', 'Leases, deposits, receipts and renewals are generated and tracked in the system, so nothing depends on someone\'s inbox.'],
                ['bi-tools', '--purple', 'Maintenance that closes', 'Tenants log an issue, we triage it, and the request is tracked through to completion with full history.'],
                ['bi-shield-lock', '--warm', 'Your data stays yours', 'Role-based access, encrypted credentials and a complete audit trail on every record change.'],
                ['bi-graph-up-arrow', '', 'Clear reporting', 'Owners see occupancy, income and arrears at a glance — exportable and audit-ready whenever you need it.'],
            ];
            $delay = 0;
            foreach ($values as [$icon, $tone, $title, $desc]): ?>
                <div class="value-card" data-reveal data-reveal-delay="<?= $delay ?>">
                    <span class="value-card__icon value-card__icon<?= $tone ?>" aria-hidden="true">
                        <i class="<?= $icon ?>"></i>
                    </span>
                    <h3 class="value-card__title"><?= $title ?></h3>
                    <p class="value-card__desc"><?= $desc ?></p>
                </div>
            <?php $delay += 50; endforeach; ?>
        </div>
    </div>
</section>

<!-- ══════════════════════════════════════════════════════════
     AGENTS — real users with the agent role
     ══════════════════════════════════════════════════════════ -->
<?php if (!empty($featuredAgents)): ?>
<section class="section section--muted">
    <div class="site-container">
        <div class="section-head" data-reveal>
            <span class="eyebrow">Our team</span>
            <h2 class="section-title">Meet the agents behind the listings</h2>
            <p class="section-lede">
                Talk to the person who has actually walked the property.
            </p>
        </div>

        <div class="agent-grid">
            <?php $delay = 0; foreach ($featuredAgents as $agent):
                $avatar = agentImage($agent);
                $name   = $agent['full_name'];
            ?>
                <article class="agent-card" data-reveal data-reveal-delay="<?= $delay ?>">
                    <div class="agent-card__media">
                        <a href="<?= APP_URL ?>/index.php?page=agent&amp;id=<?= (int) $agent['id'] ?>">
                            <img src="<?= sanitize($avatar) ?>" alt="<?= sanitize($name) ?>"
                                 loading="lazy" decoding="async" width="320" height="320">
                        </a>
                        <?php if ((int) $agent['listing_count'] > 0): ?>
                            <span class="tag tag--glass agent-card__badge">
                                <i class="bi bi-star-fill" aria-hidden="true" style="color:var(--gold)"></i>
                                Active
                            </span>
                        <?php endif; ?>
                    </div>

                    <div class="agent-card__body">
                        <h3 class="agent-card__name">
                            <a href="<?= APP_URL ?>/index.php?page=agent&amp;id=<?= (int) $agent['id'] ?>"><?= sanitize($name) ?></a>
                        </h3>
                        <p class="agent-card__role">
                            Property agent<?= $agent['branch_name'] ? ' · ' . sanitize($agent['branch_name']) : '' ?>
                        </p>

                        <div class="agent-card__stats">
                            <div>
                                <strong><?= (int) $agent['listing_count'] ?></strong>
                                listings
                            </div>
                        </div>

                        <div class="agent-card__contact">
                            <?php if (!empty($agent['phone'])): ?>
                                <a href="tel:<?= sanitize(preg_replace('/\s+/', '', $agent['phone'])) ?>"
                                   aria-label="Call <?= sanitize($name) ?>">
                                    <i class="bi bi-telephone" aria-hidden="true"></i>
                                </a>
                            <?php endif; ?>
                            <?php if (!empty($agent['email'])): ?>
                                <a href="mailto:<?= sanitize($agent['email']) ?>"
                                   aria-label="Email <?= sanitize($name) ?>">
                                    <i class="bi bi-envelope" aria-hidden="true"></i>
                                </a>
                            <?php endif; ?>
                            <a href="<?= APP_URL ?>/index.php?page=contact"
                               aria-label="Send an enquiry to <?= sanitize($name) ?>">
                                <i class="bi bi-chat-dots" aria-hidden="true"></i>
                            </a>
                        </div>
                    </div>
                </article>
            <?php $delay += 60; endforeach; ?>
        </div>
    </div>
</section>
<?php endif; ?>

<!-- ══════════════════════════════════════════════════════════
     TESTIMONIALS
     ══════════════════════════════════════════════════════════ -->
<?php /* Only rendered when approved reviews exist. An empty testimonial rail
         is better than a fabricated one — and the AggregateRating in the
         structured data is built from this same set, so the two agree. */ ?>
<?php if (!empty($homeReviews)): ?>
<section class="section">
    <div class="site-container">
        <div class="section-head" data-reveal>
            <span class="eyebrow">Testimonials</span>
            <h2 class="section-title">What our customers say</h2>
            <?php if (!empty($reviewSummary['count'])): ?>
                <p class="section-lede">
                    <span class="rating-inline">
                        <span class="rating-inline__stars" aria-hidden="true">
                            <?php for ($i = 1; $i <= 5; $i++): ?>
                                <i class="bi bi-star<?= $i <= round($reviewSummary['average']) ? '-fill' : '' ?>"></i>
                            <?php endfor; ?>
                        </span>
                        <strong><?= number_format($reviewSummary['average'], 1) ?></strong>
                        out of 5 from <?= (int) $reviewSummary['count'] ?>
                        verified <?= $reviewSummary['count'] === 1 ? 'review' : 'reviews' ?>
                    </span>
                </p>
            <?php endif; ?>
        </div>

        <div class="quote-grid">
            <?php $delay = 0; foreach ($homeReviews as $r):
                $rating = max(1, min(5, (int) $r['rating']));
                $photo  = uploadUrl($r['author_photo'] ?? null) ?? stockPortraitImage($r['author_name']);
            ?>
                <figure class="quote-card" data-reveal data-reveal-delay="<?= $delay ?>">
                    <div class="quote-card__stars" role="img"
                         aria-label="Rated <?= $rating ?> out of 5">
                        <?php for ($i = 1; $i <= 5; $i++): ?>
                            <i class="bi bi-star<?= $i <= $rating ? '-fill' : '' ?>" aria-hidden="true"></i>
                        <?php endfor; ?>
                    </div>
                    <blockquote class="quote-card__text"><?= sanitize($r['body']) ?></blockquote>
                    <figcaption class="quote-card__author">
                        <img class="quote-card__avatar" src="<?= sanitize($photo) ?>"
                             alt="" loading="lazy" decoding="async" width="44" height="44">
                        <span>
                            <span class="quote-card__name"><?= sanitize($r['author_name']) ?></span><br>
                            <span class="quote-card__role">
                                <?= sanitize($r['author_role'] ?: 'Verified customer') ?>
                            </span>
                        </span>
                    </figcaption>
                </figure>
            <?php $delay += 70; endforeach; ?>
        </div>
    </div>
</section>
<?php endif; ?>

<!-- ══════════════════════════════════════════════════════════
     CASE STUDIES — built from closed sales and active leases.
     Hidden entirely when nothing has completed yet, so the page
     never claims a track record the records don't support.
     ══════════════════════════════════════════════════════════ -->
<?php if (!empty($caseStudies)): ?>
<section class="section section--muted">
    <div class="site-container">
        <div class="section-head" data-reveal>
            <span class="eyebrow">Case studies</span>
            <h2 class="section-title">Recent results</h2>
            <p class="section-lede">
                Completed transactions from our own records — what the property was,
                what it achieved and how long it took.
            </p>
        </div>

        <div class="case-grid">
            <?php $delay = 0; foreach ($caseStudies as $cs): ?>
                <article class="case-card" data-reveal data-reveal-delay="<?= $delay ?>">
                    <div class="case-card__head">
                        <span class="value-card__icon value-card__icon<?= $cs['tone'] ?>" aria-hidden="true">
                            <i class="<?= sanitize($cs['icon']) ?>"></i>
                        </span>
                        <span class="tag <?= $cs['outcome'] === 'Sold' ? 'tag--green' : 'tag--accent' ?>">
                            <?= sanitize($cs['outcome']) ?>
                        </span>
                    </div>

                    <h3 class="case-card__title">
                        <a href="<?= APP_URL ?>/index.php?page=listing&amp;id=<?= (int) $cs['property_id'] ?>">
                            <?= sanitize($cs['property']) ?>
                        </a>
                    </h3>
                    <p class="case-card__loc">
                        <i class="bi bi-geo-alt" aria-hidden="true"></i>
                        <?= sanitize($cs['location']) ?>
                        <?= $cs['specs'] ? ' · ' . sanitize($cs['specs']) : '' ?>
                    </p>

                    <dl class="case-card__stats">
                        <div>
                            <dt><?= sanitize($cs['headline_label']) ?></dt>
                            <dd><?= sanitize($cs['headline']) ?></dd>
                        </div>
                        <?php if ($cs['days'] !== null): ?>
                            <div>
                                <dt>Time to close</dt>
                                <dd>
                                    <?php /* A same-day close is real but "0 days" reads like a bug. */ ?>
                                    <?php if ($cs['days'] === 0): ?>
                                        Same day
                                    <?php else: ?>
                                        <?= (int) $cs['days'] ?> <?= $cs['days'] === 1 ? 'day' : 'days' ?>
                                    <?php endif; ?>
                                </dd>
                            </div>
                        <?php endif; ?>
                        <div>
                            <dt>Type</dt>
                            <dd><?= sanitize($cs['category']) ?></dd>
                        </div>
                    </dl>

                    <?php if ($cs['agent'] || $cs['date']): ?>
                        <p class="case-card__foot">
                            <?php if ($cs['agent']): ?>
                                Handled by <?= sanitize($cs['agent']) ?><?= $cs['date'] ? ' · ' : '' ?>
                            <?php endif; ?>
                            <?= $cs['date'] ? sanitize($cs['date']) : '' ?>
                        </p>
                    <?php endif; ?>
                </article>
            <?php $delay += 60; endforeach; ?>
        </div>

        <p class="section-note">
            Figures come directly from completed transaction records.
            <a href="<?= APP_URL ?>/index.php?page=services">See how we work</a>.
        </p>
    </div>
</section>
<?php endif; ?>

<!-- ══════════════════════════════════════════════════════════
     FAQ — same source array as the FAQPage structured data
     ══════════════════════════════════════════════════════════ -->
<section class="section">
    <div class="site-container site-container--narrow">
        <div class="section-head" data-reveal>
            <span class="eyebrow">Questions</span>
            <h2 class="section-title">Frequently asked questions</h2>
            <p class="section-lede">
                Still unsure? <a href="<?= APP_URL ?>/index.php?page=contact">Ask us directly</a> —
                we reply within <?= BIZ_RESPONSE_HOURS ?> hours.
            </p>
        </div>
        <?php require VIEWS_PATH . '/public/components/faq.php'; ?>
    </div>
</section>

<!-- ══════════════════════════════════════════════════════════
     CTA
     ══════════════════════════════════════════════════════════ -->
<section class="section section--flush-top">
    <div class="site-container">
        <div class="cta" data-reveal>
            <div>
                <h2 class="cta__title">
                    <?= $hasListings ? 'Ready to find your next address?' : 'Ready to list your first property?' ?>
                </h2>
                <p class="cta__desc">
                    Create a free account to save properties, request viewings and track your
                    enquiries — or talk to our team about listing with <?= sanitize(companyName()) ?>.
                </p>
            </div>
            <div class="cta__actions">
                <?php if (isLoggedIn()): ?>
                    <a href="<?= APP_URL ?>/index.php?page=listings" class="btn btn--primary btn--lg">Browse properties</a>
                    <a href="<?= APP_URL ?>/index.php?page=dashboard" class="btn btn--outline btn--lg">Go to dashboard</a>
                <?php else: ?>
                    <a href="<?= APP_URL ?>/index.php?page=register" class="btn btn--primary btn--lg">Create free account</a>
                    <a href="<?= APP_URL ?>/index.php?page=contact" class="btn btn--outline btn--lg">Talk to our team</a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</section>
