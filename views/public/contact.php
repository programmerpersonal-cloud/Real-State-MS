<?php
/**
 * Contact (Public)
 * The form POSTs back to ?page=contact, which stores an Inquiry record
 * and redirects with a flash message. See index.php.
 */
?>

<section class="page-hero">
    <div class="site-container">
        <nav aria-label="Breadcrumb">
            <ol class="crumbs crumbs--center">
                <li><a href="<?= APP_URL ?>/index.php?page=home">Home</a></li>
                <li><span aria-current="page">Contact</span></li>
            </ol>
        </nav>
        <span class="eyebrow">Contact</span>
        <h1 class="page-hero__title">We'd love to hear from you</h1>
        <p class="page-hero__lede">
            Questions about a listing, a viewing, onboarding your agency or support —
            send us a line and we'll reply within <?= BIZ_RESPONSE_HOURS ?> hours on business days.
        </p>

        <div class="page-hero__actions">
            <a href="#contact-form" class="btn btn--primary btn--lg">
                <i class="bi bi-send" aria-hidden="true"></i> Send a message
            </a>
            <a href="tel:<?= sanitize(preg_replace('/\s+/', '', BIZ_PHONE)) ?>" class="btn btn--outline btn--lg">
                <i class="bi bi-telephone" aria-hidden="true"></i> <?= sanitize(BIZ_PHONE) ?>
            </a>
        </div>

        <?php require VIEWS_PATH . '/public/components/trust_bar.php'; ?>
    </div>
</section>

<section class="section section--tight">
    <div class="site-container">

        <!-- ══ CONTACT DETAILS ══════════════════════════════
             Every value reads from the BIZ_* constants so the address here,
             in the footer and in the LocalBusiness schema is byte-identical.
             Inconsistent NAP is the most common reason a local business fails
             to rank in map results. -->
        <div class="info-grid" style="margin-bottom:var(--space-8)">
            <div class="info-card" data-reveal>
                <span class="info-card__icon" aria-hidden="true"><i class="bi bi-geo-alt"></i></span>
                <span class="info-card__label">Head office</span>
                <span class="info-card__value"><?= BIZ_STREET ?><br><?= sanitize(BIZ_CITY) ?>, <?= BIZ_REGION ?></span>
                <span class="info-card__note"><a href="#find-us">Get directions</a></span>
            </div>

            <div class="info-card" data-reveal data-reveal-delay="60">
                <span class="info-card__icon" aria-hidden="true"><i class="bi bi-telephone"></i></span>
                <span class="info-card__label">Phone</span>
                <span class="info-card__value">
                    <a href="tel:<?= sanitize(preg_replace('/\s+/', '', BIZ_PHONE)) ?>"><?= sanitize(BIZ_PHONE) ?></a>
                </span>
                <span class="info-card__note">Mon–Thu &amp; Sat, 08:00–17:00</span>
            </div>

            <div class="info-card" data-reveal data-reveal-delay="120">
                <span class="info-card__icon" aria-hidden="true"><i class="bi bi-envelope"></i></span>
                <span class="info-card__label">Email</span>
                <span class="info-card__value"><a href="mailto:<?= sanitize(BIZ_EMAIL) ?>"><?= sanitize(BIZ_EMAIL) ?></a></span>
                <span class="info-card__note">Replies within <?= BIZ_RESPONSE_HOURS ?> hours</span>
            </div>

            <div class="info-card" data-reveal data-reveal-delay="180">
                <span class="info-card__icon" aria-hidden="true"><i class="bi bi-clock"></i></span>
                <span class="info-card__label">Office hours</span>
                <span class="info-card__value">Mon–Thu &amp; Sat · 08:00–17:00</span>
                <span class="info-card__note">Sunday 09:00–13:00 · Friday closed</span>
            </div>
        </div>

        <!-- ══ FORM + SIDE PANEL ════════════════════════════ -->
        <div class="contact-layout">

            <div class="panel" id="contact-form" data-reveal>
                <h2 class="panel__title">Send us a message</h2>
                <p class="panel__lede">
                    Tell us about the property you're after, your team, or anything you'd like
                    to ask.
                </p>

                <form method="POST" action="<?= APP_URL ?>/index.php?page=contact" data-validate>
                    <div class="form-grid--2">
                        <div class="form-group">
                            <label class="form-label" for="c-name">Full name</label>
                            <input type="text" id="c-name" name="name" class="form-control"
                                   autocomplete="name" placeholder="Your full name" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="c-email">Email</label>
                            <input type="email" id="c-email" name="email" class="form-control"
                                   autocomplete="email" placeholder="you@example.com" required>
                        </div>
                    </div>

                    <div class="form-grid--2">
                        <div class="form-group">
                            <label class="form-label" for="c-phone">
                                Phone <span class="text-subtle" style="font-weight:400">(optional)</span>
                            </label>
                            <input type="tel" id="c-phone" name="phone" class="form-control"
                                   autocomplete="tel" placeholder="+252 63 331 1945">
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="c-subject">Subject</label>
                            <select id="c-subject" name="subject" class="form-control">
                                <option>General inquiry</option>
                                <option>Book a viewing</option>
                                <option>List my property</option>
                                <option>Partnership</option>
                                <option>Sales &amp; pricing</option>
                                <option>Support</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="c-msg">Message</label>
                        <textarea id="c-msg" name="message" class="form-control" rows="6"
                                  placeholder="How can we help?" required></textarea>
                    </div>

                    <div class="form-checkbox">
                        <input type="checkbox" id="c-consent" required>
                        <label for="c-consent">I agree to be contacted regarding my inquiry.</label>
                    </div>

                    <div style="display:flex;gap:var(--space-3);flex-wrap:wrap;margin-top:var(--space-5)">
                        <button type="submit" class="btn btn--primary btn--lg">
                            <i class="bi bi-send" aria-hidden="true"></i> Send message
                        </button>
                        <a href="<?= APP_URL ?>/index.php?page=listings" class="btn btn--outline btn--lg">
                            Browse properties instead
                        </a>
                    </div>
                </form>
            </div>

            <aside style="display:flex;flex-direction:column;gap:var(--space-4)">
                <div class="panel" data-reveal data-reveal-delay="80">
                    <h2 class="panel__title" style="font-size:var(--fs-h4)">Our promise</h2>
                    <ul class="promise-list">
                        <li>
                            <i class="bi bi-clock-history" aria-hidden="true"></i>
                            <span><strong>Replies within <?= BIZ_RESPONSE_HOURS ?> hours</strong>
                            on business days — usually the same working day.</span>
                        </li>
                        <li>
                            <i class="bi bi-person-check" aria-hidden="true"></i>
                            <span><strong>A named agent</strong>, not a shared inbox. Your enquiry is
                            assigned to the person who represents the property.</span>
                        </li>
                        <li>
                            <i class="bi bi-shield-check" aria-hidden="true"></i>
                            <span><strong>No cold-call selling.</strong> We answer what you asked and
                            leave it there unless you want more.</span>
                        </li>
                    </ul>
                </div>

                <div class="cta" style="padding:var(--space-8) var(--space-6);flex-direction:column;align-items:flex-start;text-align:left"
                     data-reveal data-reveal-delay="140">
                    <div>
                        <h2 class="cta__title" style="font-size:var(--fs-h4)">Prefer to talk it through?</h2>
                        <p class="cta__desc" style="font-size:var(--fs-sm)">
                            Call and speak to an agent directly — no phone menu.
                        </p>
                    </div>
                    <a href="tel:<?= sanitize(preg_replace('/\s+/', '', BIZ_PHONE)) ?>" class="btn btn--primary">
                        <i class="bi bi-telephone" aria-hidden="true"></i> <?= sanitize(BIZ_PHONE) ?>
                    </a>
                </div>
            </aside>
        </div>
    </div>
</section>

<!-- ══ FIND US — map + directions ══════════════════════════
     The embed is loaded lazily and carries a title, so it does not
     block first paint and is announced properly by screen readers.
     Every route out of here opens the visitor's own maps app. -->
<?php
$mapQuery  = rawurlencode(BIZ_LEGAL_NAME . ', ' . BIZ_STREET . ', ' . BIZ_CITY . ', ' . BIZ_REGION);
$mapCoords = BIZ_LAT . ',' . BIZ_LNG;
?>
<section class="section section--muted" id="find-us">
    <div class="site-container">
        <div class="section-head" data-reveal>
            <span class="eyebrow">Find us</span>
            <h2 class="section-title">Visit the office</h2>
            <p class="section-lede">
                We're in the centre of <?= sanitize(BIZ_CITY) ?>. Drop in during office hours, or
                get directions straight to the door.
            </p>
        </div>

        <div class="map-panel" data-reveal>
            <div class="map-panel__map">
                <iframe
                    title="Map showing the location of <?= sanitize(BIZ_LEGAL_NAME) ?> in <?= sanitize(BIZ_CITY) ?>"
                    src="https://www.google.com/maps?q=<?= $mapCoords ?>&hl=en&z=15&output=embed"
                    width="100%" height="100%" style="border:0"
                    loading="lazy" referrerpolicy="no-referrer-when-downgrade"
                    allowfullscreen></iframe>
            </div>

            <div class="map-panel__side">
                <h3 class="map-panel__title"><?= sanitize(BIZ_LEGAL_NAME) ?></h3>

                <address class="map-panel__address">
                    <?= BIZ_STREET ?><br>
                    <?= sanitize(BIZ_CITY) ?>, <?= BIZ_REGION ?><br>
                    Somaliland
                </address>

                <dl class="map-panel__hours">
                    <div><dt>Mon – Thu</dt><dd>08:00 – 17:00</dd></div>
                    <div><dt>Saturday</dt><dd>08:00 – 17:00</dd></div>
                    <div><dt>Sunday</dt><dd>09:00 – 13:00</dd></div>
                    <div><dt>Friday</dt><dd>Closed</dd></div>
                </dl>

                <div class="map-panel__actions">
                    <a class="btn btn--primary btn--block"
                       href="https://www.google.com/maps/dir/?api=1&destination=<?= $mapCoords ?>&destination_place_id=<?= $mapQuery ?>"
                       target="_blank" rel="noopener noreferrer">
                        <i class="bi bi-signpost-2" aria-hidden="true"></i> Get directions
                    </a>
                    <a class="btn btn--outline btn--block"
                       href="https://www.google.com/maps/search/?api=1&query=<?= $mapCoords ?>"
                       target="_blank" rel="noopener noreferrer">
                        <i class="bi bi-map" aria-hidden="true"></i> Open in Maps
                    </a>
                    <a class="btn btn--outline btn--block"
                       href="tel:<?= sanitize(preg_replace('/\s+/', '', BIZ_PHONE)) ?>">
                        <i class="bi bi-telephone" aria-hidden="true"></i> Call the office
                    </a>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- ══ FAQ — shared source with the FAQPage structured data ═ -->
<section class="section">
    <div class="site-container site-container--narrow">
        <div class="section-head" data-reveal>
            <span class="eyebrow">Questions</span>
            <h2 class="section-title">Frequently asked questions</h2>
            <p class="section-lede">
                The things people ask us most. Anything else —
                <a href="#contact-form">send us a message</a>.
            </p>
        </div>
        <?php require VIEWS_PATH . '/public/components/faq.php'; ?>
    </div>
</section>
