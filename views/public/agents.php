<?php
/**
 * Agents Directory (Public)
 *
 * Provided by index.php: $agents (active agents with listing counts),
 * $agentTotal, $agentListingTotal, $branchTotal.
 *
 * Real staff records rather than invented profiles — an agency with three
 * agents shows three, and the page degrades to an honest empty state rather
 * than padding the grid.
 */
$agents = $agents ?? [];
?>

<section class="page-hero">
    <div class="site-container">
        <nav aria-label="Breadcrumb">
            <ol class="crumbs crumbs--center">
                <li><a href="<?= APP_URL ?>/index.php?page=home">Home</a></li>
                <li><span aria-current="page">Agents</span></li>
            </ol>
        </nav>
        <span class="eyebrow">Our team</span>
        <h1 class="page-hero__title">The people behind the listings</h1>
        <p class="page-hero__lede">
            Every property on <?= sanitize(companyName()) ?> is handled by a named agent who has walked it.
            Find the right person and talk to them directly.
        </p>

        <div class="page-hero__actions">
            <a href="#agent-list" class="btn btn--primary btn--lg">
                <i class="bi bi-people" aria-hidden="true"></i> Meet the agents
            </a>
            <a href="tel:<?= sanitize(preg_replace('/\s+/', '', BIZ_PHONE)) ?>" class="btn btn--outline btn--lg">
                <i class="bi bi-telephone" aria-hidden="true"></i> <?= sanitize(BIZ_PHONE) ?>
            </a>
        </div>

        <?php require VIEWS_PATH . '/public/components/trust_bar.php'; ?>
    </div>
</section>

<?php if (!empty($agents)): ?>

<!-- ══ TEAM STATS ══════════════════════════════════════════ -->
<section class="section section--tight">
    <div class="site-container">
        <div class="hero-stats">
            <div class="hero-stat">
                <div class="hero-stat__value"><?= number_format((int) ($agentTotal ?? count($agents))) ?></div>
                <div class="hero-stat__label">Agents on the team</div>
            </div>
            <div class="hero-stat">
                <div class="hero-stat__value"><?= number_format((int) ($agentListingTotal ?? 0)) ?></div>
                <div class="hero-stat__label">Properties represented</div>
            </div>
            <div class="hero-stat">
                <div class="hero-stat__value"><?= number_format((int) ($branchTotal ?? 0)) ?></div>
                <div class="hero-stat__label">Branch offices</div>
            </div>
            <div class="hero-stat">
                <div class="hero-stat__value"><?= BIZ_RESPONSE_HOURS ?>h</div>
                <div class="hero-stat__label">Reply time promise</div>
            </div>
        </div>
    </div>
</section>

<!-- ══ AGENT GRID ══════════════════════════════════════════ -->
<section class="section section--flush-top">
    <div class="site-container">
        <div class="section-head" data-reveal>
            <span class="eyebrow">Directory</span>
            <h2 class="section-title">Meet the agents</h2>
            <p class="section-lede">
                Sorted by how many properties each agent currently represents.
            </p>
        </div>

        <div class="agent-grid" id="agent-list">
            <?php $delay = 0; foreach ($agents as $agent):
                $name = $agent['full_name'];
                $url  = APP_URL . '/index.php?page=agent&id=' . (int) $agent['id'];
            ?>
                <article class="agent-card" data-reveal data-reveal-delay="<?= $delay ?>">
                    <div class="agent-card__media">
                        <a href="<?= sanitize($url) ?>" tabindex="-1" aria-hidden="true">
                            <img src="<?= sanitize(agentImage($agent)) ?>" alt="<?= sanitize($name) ?>"
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
                            <a href="<?= sanitize($url) ?>"><?= sanitize($name) ?></a>
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
                            <a href="<?= sanitize($url) ?>" aria-label="View <?= sanitize($name) ?>'s profile">
                                <i class="bi bi-person-badge" aria-hidden="true"></i>
                            </a>
                        </div>
                    </div>
                </article>
            <?php $delay += 60; endforeach; ?>
        </div>
    </div>
</section>

<?php else: ?>

<section class="section">
    <div class="site-container">
        <div class="empty-state">
            <span class="empty-state__icon" aria-hidden="true"><i class="bi bi-people"></i></span>
            <h2 class="empty-state__title">No agents published yet</h2>
            <p class="empty-state__desc">
                Agent profiles appear here once accounts have been created and activated.
                In the meantime, the team is reachable through the contact page.
            </p>
            <a href="<?= APP_URL ?>/index.php?page=contact" class="btn btn--primary">Contact us</a>
        </div>
    </div>
</section>

<?php endif; ?>

<!-- ══ CTA ═════════════════════════════════════════════════ -->
<section class="section section--flush-top">
    <div class="site-container">
        <div class="cta" data-reveal>
            <div>
                <h2 class="cta__title">Want to join the team?</h2>
                <p class="cta__desc">
                    We are always interested in agents who know their area properly.
                </p>
            </div>
            <div class="cta__actions">
                <a href="<?= APP_URL ?>/index.php?page=contact" class="btn btn--primary btn--lg">Get in touch</a>
                <a href="<?= APP_URL ?>/index.php?page=about" class="btn btn--outline btn--lg">About us</a>
            </div>
        </div>
    </div>
</section>
