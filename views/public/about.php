<?php
/** About (Public) */
?>

<section class="page-hero">
    <div class="site-container">
        <nav aria-label="Breadcrumb">
            <ol class="crumbs crumbs--center">
                <li><a href="<?= APP_URL ?>/index.php?page=home">Home</a></li>
                <li><span aria-current="page">About</span></li>
            </ol>
        </nav>
        <span class="eyebrow">About <?= sanitize(companyName()) ?></span>
        <h1 class="page-hero__title">A modern home for real estate</h1>
        <p class="page-hero__lede">
            We help agencies, landlords and tenants find each other — with software that is
            calm, professional and built for everyday work.
        </p>

        <div class="page-hero__actions">
            <a href="<?= APP_URL ?>/index.php?page=listings" class="btn btn--primary btn--lg">
                <i class="bi bi-search" aria-hidden="true"></i> Browse properties
            </a>
            <a href="#team" class="btn btn--outline btn--lg">
                <i class="bi bi-people" aria-hidden="true"></i> Meet the team
            </a>
        </div>

        <?php require VIEWS_PATH . '/public/components/trust_bar.php'; ?>
    </div>
</section>

<!-- ══ STORY ═══════════════════════════════════════════════ -->
<section class="section">
    <div class="site-container">
        <div class="split">
            <div class="split__media" data-reveal>
                <img src="<?= IMG_URL ?>/property/property-exterior-4.webp"
                     alt="A modern residential development" loading="lazy" decoding="async"
                     width="720" height="560">
            </div>

            <div class="split__prose" data-reveal data-reveal-delay="80">
                <span class="eyebrow">Our story</span>
                <h2 class="section-title" style="margin-bottom:var(--space-4)">
                    Built by people who actually run agencies
                </h2>
                <p>
                    <?= sanitize(companyName()) ?> started as a tool for a single brokerage drowning in
                    spreadsheets, scanned PDFs and phone-tag with tenants. We rebuilt how that
                    team worked — and then other agencies asked for the same thing.
                </p>
                <p>
                    Today we serve property managers and individual owners with a role-based
                    platform that handles listings, leases, payments, maintenance and reporting
                    from end to end. Every listing that reaches the public site has been
                    reviewed by a person first.
                </p>
                <div style="display:flex;gap:var(--space-3);flex-wrap:wrap;margin-top:var(--space-6)">
                    <a href="<?= APP_URL ?>/index.php?page=listings" class="btn btn--primary">
                        Explore properties
                    </a>
                    <a href="<?= APP_URL ?>/index.php?page=contact" class="btn btn--outline">
                        Get in touch
                    </a>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- ══ VALUES ══════════════════════════════════════════════ -->
<section class="section section--muted">
    <div class="site-container">
        <div class="section-head" data-reveal>
            <span class="eyebrow">Our values</span>
            <h2 class="section-title">What we believe</h2>
            <p class="section-lede">
                The principles behind every feature, every line of code and every conversation
                with a customer.
            </p>
        </div>

        <div class="value-grid">
            <?php
            $values = [
                ['bi-eye', '', 'Clarity first', 'Software should make complex work feel calm. No noise, no clutter, no flashing badges competing for attention.'],
                ['bi-hand-thumbs-up', '--green', 'Trust is earned', 'Verified listings, audited changes and clear receipts — for tenants, owners and regulators alike.'],
                ['bi-rocket-takeoff', '--purple', 'Ship every week', 'We listen to customers, prioritise ruthlessly and release improvements continuously.'],
                ['bi-shield-lock', '--gold', 'Security by default', 'Role-based access, encrypted credentials and a complete audit trail — out of the box, not as an upsell.'],
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
            <?php $delay += 60; endforeach; ?>
        </div>
    </div>
</section>

<!-- ══ NUMBERS ═════════════════════════════════════════════ -->
<section class="section section--ink">
    <div class="site-container">
        <div class="section-head" data-reveal>
            <span class="eyebrow">By the numbers</span>
            <h2 class="section-title">Where we are today</h2>
        </div>

        <div class="hero-stats">
            <?php
            $numbers = [
                ['2019', 'Founded'],
                ['200+', 'Agencies served'],
                ['$1.2B', 'Property value managed'],
                ['12', 'Countries'],
            ];
            $delay = 0;
            foreach ($numbers as [$value, $label]): ?>
                <div class="hero-stat" data-reveal data-reveal-delay="<?= $delay ?>"
                     style="background:var(--ink-2);border-color:rgba(255,255,255,.10)">
                    <div class="hero-stat__value" style="color:#fff"><?= $value ?></div>
                    <div class="hero-stat__label" style="color:rgba(255,255,255,.62)"><?= $label ?></div>
                </div>
            <?php $delay += 60; endforeach; ?>
        </div>
    </div>
</section>

<!-- ══ TEAM ════════════════════════════════════════════════ -->
<section class="section" id="team">
    <div class="site-container">
        <div class="section-head" data-reveal>
            <span class="eyebrow">Leadership</span>
            <h2 class="section-title">Meet the team</h2>
            <p class="section-lede">
                A small group with deep experience across real estate, design and engineering.
            </p>
        </div>

        <?php if (!empty($teamMembers)): ?>
            <div class="agent-grid">
                <?php
                // Job titles are derived from the role on the account, so the
                // page can never claim someone holds a position the system
                // does not actually grant them.
                $roleTitles = [
                    'admin'       => 'Director',
                    'agent'       => 'Property agent',
                    'maintenance' => 'Maintenance lead',
                ];
                $delay = 0;
                foreach ($teamMembers as $member):
                    $name    = $member['full_name'];
                    $role    = $roleTitles[$member['role_name']] ?? ucfirst((string) $member['role_name']);
                    $isAgent = $member['role_name'] === 'agent';
                    $profile = $isAgent ? APP_URL . '/index.php?page=agent&id=' . (int) $member['id'] : null;
                ?>
                    <article class="agent-card" data-reveal data-reveal-delay="<?= $delay ?>">
                        <div class="agent-card__media">
                            <?php if ($profile): ?><a href="<?= sanitize($profile) ?>" tabindex="-1" aria-hidden="true"><?php endif; ?>
                                <img src="<?= sanitize(agentImage($member)) ?>"
                                     alt="<?= sanitize($name) ?>, <?= sanitize($role) ?> at <?= sanitize(companyName()) ?>"
                                     loading="lazy" decoding="async" width="320" height="320">
                            <?php if ($profile): ?></a><?php endif; ?>
                        </div>
                        <div class="agent-card__body">
                            <h3 class="agent-card__name">
                                <?php if ($profile): ?>
                                    <a href="<?= sanitize($profile) ?>"><?= sanitize($name) ?></a>
                                <?php else: ?>
                                    <?= sanitize($name) ?>
                                <?php endif; ?>
                            </h3>
                            <p class="agent-card__role">
                                <?= sanitize($role) ?><?= $member['branch_name'] ? ' · ' . sanitize($member['branch_name']) : '' ?>
                            </p>

                            <?php if ($isAgent && (int) $member['listing_count'] > 0): ?>
                                <div class="agent-card__stats">
                                    <div><strong><?= (int) $member['listing_count'] ?></strong> listings</div>
                                </div>
                            <?php endif; ?>

                            <div class="agent-card__contact">
                                <?php if (!empty($member['phone'])): ?>
                                    <a href="tel:<?= sanitize(preg_replace('/\s+/', '', $member['phone'])) ?>"
                                       aria-label="Call <?= sanitize($name) ?>">
                                        <i class="bi bi-telephone" aria-hidden="true"></i>
                                    </a>
                                <?php endif; ?>
                                <?php if (!empty($member['email'])): ?>
                                    <a href="mailto:<?= sanitize($member['email']) ?>"
                                       aria-label="Email <?= sanitize($name) ?>">
                                        <i class="bi bi-envelope" aria-hidden="true"></i>
                                    </a>
                                <?php endif; ?>
                                <?php if ($profile): ?>
                                    <a href="<?= sanitize($profile) ?>" aria-label="View <?= sanitize($name) ?>'s profile">
                                        <i class="bi bi-person-badge" aria-hidden="true"></i>
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </article>
                <?php $delay += 60; endforeach; ?>
            </div>

            <p class="section-note">
                Everyone shown here holds an active <?= sanitize(companyName()) ?> account.
                <a href="<?= APP_URL ?>/index.php?page=agents">See all agents and their current listings</a>.
            </p>
        <?php else: ?>
            <div class="empty-state">
                <span class="empty-state__icon" aria-hidden="true"><i class="bi bi-people"></i></span>
                <h3 class="empty-state__title">Team profiles are being set up</h3>
                <p class="empty-state__desc">
                    Staff profiles appear here once accounts have been created and activated.
                </p>
                <a href="<?= APP_URL ?>/index.php?page=contact" class="btn btn--primary">Contact us</a>
            </div>
        <?php endif; ?>
    </div>
</section>

<!-- ══ CTA ═════════════════════════════════════════════════ -->
<section class="section section--flush-top">
    <div class="site-container">
        <div class="cta" data-reveal>
            <div>
                <h2 class="cta__title">Like what you see?</h2>
                <p class="cta__desc">
                    Browse the properties we have live right now, or talk to us about bringing
                    your agency onto <?= sanitize(companyName()) ?>.
                </p>
            </div>
            <div class="cta__actions">
                <a href="<?= APP_URL ?>/index.php?page=listings" class="btn btn--primary btn--lg">
                    Browse properties
                </a>
                <a href="<?= APP_URL ?>/index.php?page=contact" class="btn btn--outline btn--lg">
                    Contact us
                </a>
            </div>
        </div>
    </div>
</section>
