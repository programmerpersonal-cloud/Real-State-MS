<?php
/**
 * Property Detail (Public)
 *
 * Provided by index.php: $property, $images, $similar, $similarCovers
 *
 * Structure follows the order a buyer actually evaluates a property:
 *   see it → place it → check the numbers → read the detail → contact.
 */

$pid      = (int) $property['id'];
$price    = propertyPrice($property);
$status   = (string) $property['status'];
$category = (string) $property['category'];
$savedIds = savedPropertyIds();
$isSaved  = in_array($pid, $savedIds, true);

// Gallery works on plain URLs so uploaded photos and the seeded stock
// fallback flow through exactly the same carousel markup below.
$photos = array_map(
    fn($img) => uploadUrl($img['file_path']),
    array_values(array_filter($images ?? [], fn($img) => !empty($img['file_path'])))
);
if (!$photos) {
    $photos = stockPropertyGallery($pid);
}
$photoCount = count($photos);

$fullAddress = trim(implode(', ', array_filter([
    $property['address'] ?? '',
    $property['location'] ?? '',
])));

// Amenities. Absent features are shown greyed rather than hidden, so a
// visitor can tell "no parking" apart from "not stated".
$amenities = [
    ['bi-house-gear',        'Furnished',           !empty($property['is_furnished'])],
    ['bi-p-square',          'Parking available',   !empty($property['has_parking'])],
    ['bi-shield-check',      '24/7 security',       !empty($property['has_security'])],
    ['bi-lightning-charge',  'Utilities included',  !empty($property['utilities_included'])],
];
?>

<section class="detail-page">
    <div class="site-container">

        <nav aria-label="Breadcrumb" style="margin-bottom:var(--space-5)">
            <ol class="crumbs">
                <li><a href="<?= APP_URL ?>/index.php?page=home">Home</a></li>
                <li><a href="<?= APP_URL ?>/index.php?page=listings">Properties</a></li>
                <?php if ($category): ?>
                    <li>
                        <a href="<?= APP_URL ?>/index.php?page=listings&amp;category=<?= sanitize($category) ?>">
                            <?= categoryLabel($category) ?>
                        </a>
                    </li>
                <?php endif; ?>
                <li><span aria-current="page"><?= sanitize($property['title']) ?></span></li>
            </ol>
        </nav>

        <!-- ══ GALLERY ══════════════════════════════════════════
             Stage + thumbnail strip. Keyboard navigable via arrow
             keys; click the stage to open the lightbox. Without JS
             the first photo still renders at full size. -->
        <div class="pgallery" data-gallery tabindex="0"
             role="region" aria-roledescription="carousel" aria-label="Property photos">
            <div class="pgallery__stage">
                    <?php foreach ($photos as $i => $photoUrl): ?>
                        <div class="pgallery__slide <?= $i === 0 ? 'is-active' : '' ?>"
                             aria-hidden="<?= $i === 0 ? 'false' : 'true' ?>"
                             role="group" aria-roledescription="slide"
                             aria-label="Photo <?= $i + 1 ?> of <?= $photoCount ?>">
                            <img src="<?= sanitize($photoUrl) ?>"
                                 alt="<?= sanitize($property['title']) ?> — photo <?= $i + 1 ?>"
                                 loading="<?= $i === 0 ? 'eager' : 'lazy' ?>"
                                 <?= $i === 0 ? 'fetchpriority="high"' : '' ?>
                                 decoding="async" width="1280" height="720">
                        </div>
                    <?php endforeach; ?>

                    <?php if ($photoCount > 1): ?>
                        <button type="button" class="pgallery__nav pgallery__nav--prev"
                                data-gallery-nav="prev" aria-label="Previous photo">
                            <i class="bi bi-chevron-left" aria-hidden="true"></i>
                        </button>
                        <button type="button" class="pgallery__nav pgallery__nav--next"
                                data-gallery-nav="next" aria-label="Next photo">
                            <i class="bi bi-chevron-right" aria-hidden="true"></i>
                        </button>
                    <?php endif; ?>

                    <span class="pgallery__counter">
                        <i class="bi bi-images" aria-hidden="true"></i>
                        <span data-gallery-current>1</span> / <?= $photoCount ?>
                    </span>

                <div class="pgallery__badges">
                    <span class="tag tag--solid"><?= $price['label'] ?></span>
                    <span class="tag <?= statusTagClass($status) ?>"><?= ucfirst(sanitize($status)) ?></span>
                </div>
            </div>

            <?php if ($photoCount > 1): ?>
                <div class="pgallery__thumbs" role="tablist" aria-label="Choose a photo">
                    <?php foreach ($photos as $i => $photoUrl): ?>
                        <button type="button" role="tab"
                                class="pgallery__thumb <?= $i === 0 ? 'is-active' : '' ?>"
                                aria-selected="<?= $i === 0 ? 'true' : 'false' ?>"
                                aria-label="Show photo <?= $i + 1 ?>">
                            <img src="<?= sanitize($photoUrl) ?>" alt=""
                                 loading="lazy" decoding="async" width="160" height="120">
                        </button>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <div class="detail-layout" style="margin-top:var(--space-8)">

            <!-- ══ MAIN COLUMN ═════════════════════════════════  -->
            <div>
                <header class="detail-head">
                    <span class="tag tag--accent"><?= ucfirst(sanitize($category)) ?></span>
                    <h1 class="detail-title"><?= sanitize($property['title']) ?></h1>
                    <p class="detail-loc">
                        <i class="bi bi-geo-alt" aria-hidden="true"></i>
                        <span><?= sanitize($fullAddress ?: 'Location available on request') ?></span>
                    </p>
                </header>

                <!-- Facts a buyer scans first. -->
                <div class="quickstats">
                    <span class="quickstat">
                        <i class="bi bi-door-open" aria-hidden="true"></i>
                        <strong><?= (int) $property['num_rooms'] ?></strong> Bedrooms
                    </span>
                    <span class="quickstat">
                        <i class="bi bi-droplet" aria-hidden="true"></i>
                        <strong><?= (int) $property['num_bathrooms'] ?></strong> Bathrooms
                    </span>
                    <?php if (!empty($property['size_sqm'])): ?>
                        <span class="quickstat">
                            <i class="bi bi-rulers" aria-hidden="true"></i>
                            <strong><?= number_format((float) $property['size_sqm']) ?></strong> m²
                        </span>
                    <?php endif; ?>
                    <span class="quickstat">
                        <i class="bi bi-layers" aria-hidden="true"></i>
                        <strong><?= (int) ($property['num_floors'] ?: 1) ?></strong>
                        <?= (int) ($property['num_floors'] ?: 1) === 1 ? 'Floor' : 'Floors' ?>
                    </span>
                    <?php if (!empty($property['has_parking'])): ?>
                        <span class="quickstat">
                            <i class="bi bi-p-square" aria-hidden="true"></i> Parking
                        </span>
                    <?php endif; ?>
                </div>

                <!-- ══ TABS ═══════════════════════════════════════
                     Progressive enhancement: with JS off every panel
                     renders stacked and readable. -->
                <div data-tabs>
                    <div class="dtabs" role="tablist" aria-label="Property information">
                        <button type="button" class="dtab" role="tab" id="tab-overview"
                                aria-controls="panel-overview" aria-selected="true">Overview</button>
                        <button type="button" class="dtab" role="tab" id="tab-features"
                                aria-controls="panel-features" aria-selected="false" tabindex="-1">Features</button>
                        <button type="button" class="dtab" role="tab" id="tab-specs"
                                aria-controls="panel-specs" aria-selected="false" tabindex="-1">Specifications</button>
                        <button type="button" class="dtab" role="tab" id="tab-location"
                                aria-controls="panel-location" aria-selected="false" tabindex="-1">Location</button>
                        <?php /* Appended last, and only when there is something to show: public.js
                                 selects tab 0 on load, so a conditional tab must never come first. */ ?>
                        <?php if (!empty($publicDocuments)): ?>
                        <button type="button" class="dtab" role="tab" id="tab-documents"
                                aria-controls="panel-documents" aria-selected="false" tabindex="-1">Documents</button>
                        <?php endif ?>
                    </div>

                    <div class="dpanel" id="panel-overview" role="tabpanel" aria-labelledby="tab-overview">
                        <h3>About this property</h3>
                        <p><?= sanitize($property['description'] ?: 'The agent has not added a written description for this property yet. Get in touch using the form and we will send you the full details.') ?></p>
                    </div>

                    <div class="dpanel" id="panel-features" role="tabpanel" aria-labelledby="tab-features">
                        <h3>Features &amp; amenities</h3>
                        <ul class="amenity-list">
                            <?php foreach ($amenities as [$icon, $label, $has]): ?>
                                <li class="<?= $has ? '' : 'is-off' ?>">
                                    <i class="bi <?= $has ? 'bi-check-circle-fill' : 'bi-dash-circle' ?>" aria-hidden="true"></i>
                                    <span><?= $label ?></span>
                                    <span class="sr-only"><?= $has ? '(included)' : '(not included)' ?></span>
                                </li>
                            <?php endforeach; ?>
                        </ul>

                        <?php if (!empty($property['utilities_included'])): ?>
                            <h3 style="margin-top:var(--space-6)">Utilities included</h3>
                            <p><?= sanitize($property['utilities_included']) ?></p>
                        <?php endif; ?>
                    </div>

                    <div class="dpanel" id="panel-specs" role="tabpanel" aria-labelledby="tab-specs">
                        <h3>Full specifications</h3>
                        <div class="facts">
                            <?php
                            $facts = [
                                'Property code'  => $property['property_code'] ?: '—',
                                'Property type'  => ucfirst($category),
                                'Listing type'   => $price['label'],
                                'Status'         => ucfirst($status),
                                'Bedrooms'       => (int) $property['num_rooms'],
                                'Bathrooms'      => (int) $property['num_bathrooms'],
                                'Floors'         => (int) ($property['num_floors'] ?: 1),
                                'Floor area'     => !empty($property['size_sqm'])
                                                        ? number_format((float) $property['size_sqm']) . ' m²' : '—',
                                'Furnished'      => !empty($property['is_furnished']) ? 'Yes' : 'No',
                                'Parking'        => !empty($property['has_parking']) ? 'Yes' : 'No',
                                'Security'       => !empty($property['has_security']) ? 'Yes' : 'No',
                                'Listed on'      => formatDate($property['created_at']),
                            ];
                            if (!empty($property['deposit_amount'])) {
                                $facts['Security deposit'] = formatCurrency((float) $property['deposit_amount']);
                            }
                            if (!empty($property['branch_name'])) {
                                $facts['Managed by'] = $property['branch_name'];
                            }
                            foreach ($facts as $label => $value): ?>
                                <div class="facts__row">
                                    <span class="facts__label"><?= $label ?></span>
                                    <span class="facts__value"><?= sanitize((string) $value) ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="dpanel" id="panel-location" role="tabpanel" aria-labelledby="tab-location">
                        <h3>Where it is</h3>
                        <p><strong><?= sanitize($fullAddress ?: 'Exact address available on request') ?></strong></p>

                        <?php require VIEWS_PATH . '/components/property_map.php'; ?>
                    </div>

                    <?php if (!empty($publicDocuments)): ?>
                    <div class="dpanel" id="panel-documents" role="tabpanel" aria-labelledby="tab-documents">
                        <h3>Documents</h3>
                        <p>Plans and paperwork published for this property.</p>
                        <?php require VIEWS_PATH . '/public/components/property_documents.php'; ?>
                    </div>
                    <?php endif ?>
                </div>
            </div>

            <!-- ══ SIDEBAR ═════════════════════════════════════  -->
            <aside class="detail-aside">

                <div class="sidecard">
                    <div class="sidecard__price-row">
                        <div class="sidecard__price">
                            <?= formatCurrency($price['amount']) ?>
                            <?php if ($price['suffix']): ?><small>per month</small><?php endif; ?>
                        </div>
                        <span class="tag <?= statusTagClass($status) ?>"><?= ucfirst(sanitize($status)) ?></span>
                    </div>

                    <dl class="sidecard__rows">
                        <div class="sidecard__row">
                            <dt>Property code</dt>
                            <dd><?= sanitize($property['property_code'] ?: '—') ?></dd>
                        </div>
                        <div class="sidecard__row">
                            <dt>Type</dt>
                            <dd><?= ucfirst(sanitize($category)) ?> · <?= $price['label'] ?></dd>
                        </div>
                        <?php if (!empty($property['deposit_amount'])): ?>
                            <div class="sidecard__row">
                                <dt>Security deposit</dt>
                                <dd><?= formatCurrency((float) $property['deposit_amount']) ?></dd>
                            </div>
                        <?php endif; ?>
                        <div class="sidecard__row">
                            <dt>Listed</dt>
                            <dd><?= formatDate($property['created_at']) ?></dd>
                        </div>
                    </dl>

                    <div class="sidecard__split">
                        <?php // Permission, not merely a session — see property_card.php. ?>
                        <?php if (can('favorites.add') || can('favorites.remove')): ?>
                            <form method="POST"
                                  action="<?= APP_URL ?>/index.php?page=favorites&amp;action=<?= $isSaved ? 'remove' : 'add' ?>&amp;property_id=<?= $pid ?>">
                                <button type="submit" class="btn btn--outline btn--block"
                                        aria-pressed="<?= $isSaved ? 'true' : 'false' ?>">
                                    <i class="bi <?= $isSaved ? 'bi-heart-fill' : 'bi-heart' ?>" aria-hidden="true"></i>
                                    <?= $isSaved ? 'Saved' : 'Save' ?>
                                </button>
                            </form>
                        <?php elseif (!isLoggedIn()): ?>
                            <a href="<?= APP_URL ?>/index.php?page=login" class="btn btn--outline btn--block">
                                <i class="bi bi-heart" aria-hidden="true"></i> Save
                            </a>
                        <?php endif; ?>

                        <a href="#inquiry" class="btn btn--primary btn--block">
                            <i class="bi bi-calendar-check" aria-hidden="true"></i> Book viewing
                        </a>
                    </div>
                </div>

                <!-- Agent + enquiry. Posts to the existing contact handler,
                     which stores the message as an Inquiry record. -->
                <div class="sidecard" id="inquiry">
                    <?php
                    $agentId   = (int) ($property['agent_id'] ?? 0);
                    $agentName = $property['agent_name'] ?: companyName() . ' Sales Team';
                    $agentPic  = agentImage([
                        'avatar'    => $property['agent_avatar'] ?? null,
                        'id'        => $agentId,
                        'full_name' => $agentName,
                    ]);
                    ?>
                    <div class="sidecard__agent">
                        <img class="sidecard__avatar" src="<?= sanitize($agentPic) ?>"
                             alt="" loading="lazy" decoding="async" width="52" height="52">
                        <div>
                            <p class="sidecard__agent-role">Listing agent</p>
                            <p class="sidecard__agent-name">
                                <?php if ($agentId): ?>
                                    <a href="<?= APP_URL ?>/index.php?page=agent&amp;id=<?= $agentId ?>"><?= sanitize($agentName) ?></a>
                                <?php else: ?>
                                    <?= sanitize($agentName) ?>
                                <?php endif; ?>
                            </p>
                        </div>
                    </div>

                    <div class="sidecard__divider"></div>

                    <h2 class="sidecard__title">Request a viewing</h2>

                    <form method="POST" action="<?= APP_URL ?>/index.php?page=contact" data-validate>
                        <input type="hidden" name="property_id" value="<?= $pid ?>">
                        <input type="hidden" name="subject"
                               value="Viewing request: <?= sanitize($property['title']) ?> (<?= sanitize($property['property_code']) ?>)">

                        <div class="form-group">
                            <label class="form-label" for="iq-name">Full name</label>
                            <input type="text" id="iq-name" name="name" class="form-control"
                                   autocomplete="name" placeholder="Your name" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="iq-email">Email</label>
                            <input type="email" id="iq-email" name="email" class="form-control"
                                   autocomplete="email" placeholder="you@example.com" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="iq-phone">Phone <span class="text-subtle">(optional)</span></label>
                            <input type="tel" id="iq-phone" name="phone" class="form-control"
                                   autocomplete="tel" placeholder="+252 63 331 1945">
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="iq-msg">Message</label>
                            <textarea id="iq-msg" name="message" class="form-control" rows="4" required
                                      placeholder="I'd like to arrange a viewing for this property."
                            >I'd like to arrange a viewing for <?= sanitize($property['title']) ?>.</textarea>
                        </div>

                        <button type="submit" class="btn btn--primary btn--block btn--lg">
                            <i class="bi bi-send" aria-hidden="true"></i> Send enquiry
                        </button>
                    </form>

                    <p class="trust-note">
                        <i class="bi bi-shield-check" aria-hidden="true"></i>
                        Your details go to our team only — never to third parties.
                    </p>
                </div>
            </aside>
        </div>
    </div>
</section>

<!-- ══ SIMILAR PROPERTIES ═══════════════════════════════════  -->
<?php if (!empty($similar)): ?>
<section class="section section--muted">
    <div class="site-container">
        <div class="section-head section-head--split">
            <div class="section-head__body">
                <span class="eyebrow">Keep looking</span>
                <h2 class="section-title">Similar properties you might like</h2>
            </div>
            <a href="<?= APP_URL ?>/index.php?page=listings&amp;category=<?= sanitize($category) ?>"
               class="btn btn--outline">
                More <?= strtolower(categoryLabel($category)) ?> <i class="bi bi-arrow-right" aria-hidden="true"></i>
            </a>
        </div>

        <div class="pgrid">
            <?php foreach ($similar as $s): ?>
                <?php renderPropertyCard($s, $similarCovers[(int) $s['id']] ?? null, $savedIds); ?>
            <?php endforeach; ?>
        </div>
    </div>
</section>
<?php endif; ?>
