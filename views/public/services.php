<?php
/**
 * Services (Public)
 *
 * Index of what the agency does, as distinct from what it has listed.
 * Copy comes from siteServices() in includes/content.php so this page and
 * the detail page can never describe the same service differently.
 */
$services = siteServices();
?>

<section class="page-hero">
    <div class="site-container">
        <nav aria-label="Breadcrumb">
            <ol class="crumbs crumbs--center">
                <li><a href="<?= APP_URL ?>/index.php?page=home">Home</a></li>
                <li><span aria-current="page">Services</span></li>
            </ol>
        </nav>
        <span class="eyebrow">What we do</span>
        <h1 class="page-hero__title">Services built around the whole transaction</h1>
        <p class="page-hero__lede">
            Buying, selling, letting, managing and advising — handled by one team, on one
            system, with a named person answering for every step.
        </p>

        <div class="page-hero__actions">
            <a href="<?= APP_URL ?>/index.php?page=contact" class="btn btn--primary btn--lg">
                <i class="bi bi-chat-dots" aria-hidden="true"></i> Talk to an advisor
            </a>
            <a href="#services-list" class="btn btn--outline btn--lg">
                <i class="bi bi-grid" aria-hidden="true"></i> See all services
            </a>
        </div>

        <?php require VIEWS_PATH . '/public/components/trust_bar.php'; ?>
    </div>
</section>

<!-- ══ SERVICE GRID ════════════════════════════════════════ -->
<section class="section">
    <div class="site-container">
        <div class="section-head" data-reveal>
            <span class="eyebrow">Our services</span>
            <h2 class="section-title">Pick the part you need help with</h2>
            <p class="section-lede">
                Every service works on its own. Most clients start with one and add others
                as their portfolio grows.
            </p>
        </div>

        <div class="svc-grid" id="services-list">
            <?php $delay = 0; foreach ($services as $slug => $svc):
                $url = APP_URL . '/index.php?page=service&id=' . urlencode($slug); ?>
                <article class="svc-card" data-reveal data-reveal-delay="<?= $delay ?>">
                    <a class="svc-card__media" href="<?= sanitize($url) ?>" tabindex="-1" aria-hidden="true">
                        <img src="<?= IMG_URL ?>/property/<?= sanitize($svc['image']) ?>" alt=""
                             loading="lazy" decoding="async" width="480" height="320">
                    </a>
                    <div class="svc-card__body">
                        <span class="value-card__icon value-card__icon<?= $svc['tone'] ?>" aria-hidden="true">
                            <i class="<?= sanitize($svc['icon']) ?>"></i>
                        </span>
                        <h3 class="svc-card__title">
                            <a href="<?= sanitize($url) ?>"><?= sanitize($svc['title']) ?></a>
                        </h3>
                        <p class="svc-card__desc"><?= sanitize($svc['lede']) ?></p>
                        <span class="svc-card__more">
                            Learn more <i class="bi bi-arrow-right" aria-hidden="true"></i>
                        </span>
                    </div>
                </article>
            <?php $delay += 60; endforeach; ?>
        </div>
    </div>
</section>

<!-- ══ HOW WE WORK ═════════════════════════════════════════ -->
<section class="section section--muted">
    <div class="site-container">
        <div class="section-head" data-reveal>
            <span class="eyebrow">How we work</span>
            <h2 class="section-title">The same four steps, whatever you need</h2>
            <p class="section-lede">
                No service starts before we understand what you are actually trying to achieve.
            </p>
        </div>

        <ol class="step-list">
            <?php
            $steps = [
                ['Talk it through', 'A short conversation about your position, your timeline and your constraints. No obligation and no charge.'],
                ['Get a written plan', 'What we would do, what it costs and what you should expect — in writing, before anything starts.'],
                ['We do the work', 'One named agent owns your case. Every document, message and decision is on the record.'],
                ['Review the outcome', 'We measure the result against the plan and tell you honestly how it went.'],
            ];
            $delay = 0;
            foreach ($steps as $i => [$title, $desc]): ?>
                <li class="step" data-reveal data-reveal-delay="<?= $delay ?>">
                    <span class="step__num" aria-hidden="true"><?= $i + 1 ?></span>
                    <div>
                        <h3 class="step__title"><?= $title ?></h3>
                        <p class="step__desc"><?= $desc ?></p>
                    </div>
                </li>
            <?php $delay += 60; endforeach; ?>
        </ol>
    </div>
</section>

<!-- ══ CTA ═════════════════════════════════════════════════ -->
<section class="section section--flush-top">
    <div class="site-container">
        <div class="cta" data-reveal>
            <div>
                <h2 class="cta__title">Not sure which one you need?</h2>
                <p class="cta__desc">
                    Tell us the situation and we will point you at the right service — or tell you
                    that you do not need one.
                </p>
            </div>
            <div class="cta__actions">
                <a href="<?= APP_URL ?>/index.php?page=contact" class="btn btn--primary btn--lg">
                    Talk to us
                </a>
                <a href="<?= APP_URL ?>/index.php?page=listings" class="btn btn--outline btn--lg">
                    Browse properties
                </a>
            </div>
        </div>
    </div>
</section>
