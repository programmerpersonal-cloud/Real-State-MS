<?php
/**
 * Service Detail (Public)
 *
 * Provided by index.php: $service (the catalogue entry), $serviceSlug.
 * Layout mirrors the property detail page — content column plus a sticky
 * enquiry aside — so the two detail templates feel like the same site.
 */
$others = array_diff_key(siteServices(), [$serviceSlug => true]);
?>

<section class="detail-page">
    <div class="site-container">

        <nav aria-label="Breadcrumb">
            <ol class="crumbs">
                <li><a href="<?= APP_URL ?>/index.php?page=home">Home</a></li>
                <li><a href="<?= APP_URL ?>/index.php?page=services">Services</a></li>
                <li><span aria-current="page"><?= sanitize($service['title']) ?></span></li>
            </ol>
        </nav>

        <div class="svc-hero">
            <img src="<?= IMG_URL ?>/property/<?= sanitize($service['image']) ?>" alt=""
                 loading="eager" fetchpriority="high" decoding="async" width="1280" height="620">
            <div class="svc-hero__overlay">
                <span class="value-card__icon value-card__icon<?= $service['tone'] ?>" aria-hidden="true">
                    <i class="<?= sanitize($service['icon']) ?>"></i>
                </span>
                <h1 class="detail-title"><?= sanitize($service['title']) ?></h1>
                <p class="svc-hero__lede"><?= sanitize($service['lede']) ?></p>
            </div>
        </div>

        <div class="detail-layout" style="margin-top:var(--space-8)">

            <!-- ══ CONTENT ══════════════════════════════════ -->
            <div>
                <div class="panel">
                    <h2 class="panel__title">Overview</h2>
                    <div class="panel__lede">
                        <p><?= sanitize($service['intro']) ?></p>
                        <p style="margin-top:var(--space-4)"><?= sanitize($service['body']) ?></p>
                    </div>
                </div>

                <div class="panel" style="margin-top:var(--space-6)">
                    <h2 class="panel__title">What's included</h2>
                    <div class="value-grid value-grid--2">
                        <?php foreach ($service['includes'] as [$icon, $title, $desc]): ?>
                            <div class="value-card">
                                <span class="value-card__icon value-card__icon<?= $service['tone'] ?>" aria-hidden="true">
                                    <i class="<?= sanitize($icon) ?>"></i>
                                </span>
                                <h3 class="value-card__title"><?= sanitize($title) ?></h3>
                                <p class="value-card__desc"><?= sanitize($desc) ?></p>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="panel" style="margin-top:var(--space-6)">
                    <h2 class="panel__title">How it works</h2>
                    <ol class="step-list step-list--tight">
                        <?php foreach ($service['process'] as $i => [$title, $desc]): ?>
                            <li class="step">
                                <span class="step__num" aria-hidden="true"><?= $i + 1 ?></span>
                                <div>
                                    <h3 class="step__title"><?= sanitize($title) ?></h3>
                                    <p class="step__desc"><?= sanitize($desc) ?></p>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ol>
                </div>

                <div class="panel" style="margin-top:var(--space-6)">
                    <h2 class="panel__title">Other services</h2>
                    <div class="svc-mini-grid">
                        <?php foreach (array_slice($others, 0, 3, true) as $slug => $other): ?>
                            <a class="svc-mini" href="<?= APP_URL ?>/index.php?page=service&amp;id=<?= urlencode($slug) ?>">
                                <span class="value-card__icon value-card__icon<?= $other['tone'] ?>" aria-hidden="true">
                                    <i class="<?= sanitize($other['icon']) ?>"></i>
                                </span>
                                <span>
                                    <strong><?= sanitize($other['title']) ?></strong>
                                    <small><?= sanitize($other['lede']) ?></small>
                                </span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <!-- ══ ASIDE ════════════════════════════════════ -->
            <aside class="detail-aside">

                <div class="sidecard">
                    <h2 class="sidecard__title">By the numbers</h2>
                    <div class="sidecard__rows">
                        <?php foreach ($service['stats'] as [$value, $label]): ?>
                            <div class="sidecard__row">
                                <span><?= sanitize($label) ?></span>
                                <strong><?= sanitize($value) ?></strong>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="sidecard" id="enquiry" style="margin-top:var(--space-5)">
                    <h2 class="sidecard__title">Ask about this service</h2>
                    <p class="sidecard__agent-role" style="margin-bottom:var(--space-4)">
                        We reply within one working day.
                    </p>

                    <form method="POST" action="<?= APP_URL ?>/index.php?page=contact" data-validate>
                        <input type="hidden" name="subject"
                               value="Service enquiry: <?= sanitize($service['title']) ?>">

                        <div class="form-group">
                            <label class="form-label" for="svc-name">Your name</label>
                            <input class="form-control" id="svc-name" name="name" required
                                   autocomplete="name" placeholder="Full name">
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="svc-email">Email</label>
                            <input class="form-control" id="svc-email" name="email" type="email" required
                                   autocomplete="email" placeholder="you@example.com">
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="svc-phone">Phone <span class="text-subtle">(optional)</span></label>
                            <input class="form-control" id="svc-phone" name="phone" type="tel"
                                   autocomplete="tel" placeholder="+252 …">
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="svc-msg">What do you need?</label>
                            <textarea class="form-control" id="svc-msg" name="message" rows="4" required
                                      placeholder="A sentence or two about your situation."></textarea>
                        </div>

                        <button type="submit" class="btn btn--primary btn--block">
                            <i class="bi bi-send" aria-hidden="true"></i> Send enquiry
                        </button>
                    </form>
                </div>

                <div class="sidecard" style="margin-top:var(--space-5)">
                    <h2 class="sidecard__title">Prefer to talk?</h2>
                    <div class="sidecard__rows">
                        <div class="sidecard__row">
                            <span><i class="bi bi-telephone" aria-hidden="true"></i> Phone</span>
                            <a href="tel:<?= sanitize(preg_replace('/\s+/', '', BIZ_PHONE)) ?>"><?= sanitize(BIZ_PHONE) ?></a>
                        </div>
                        <div class="sidecard__row">
                            <span><i class="bi bi-envelope" aria-hidden="true"></i> Email</span>
                            <a href="mailto:<?= sanitize(BIZ_EMAIL) ?>"><?= sanitize(BIZ_EMAIL) ?></a>
                        </div>
                    </div>
                </div>
            </aside>
        </div>
    </div>
</section>
