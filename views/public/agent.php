<?php
/**
 * Agent Profile (Public)
 *
 * Provided by index.php: $agent, $agentProperties, $agentCovers, $agentStats.
 *
 * Same two-column detail shell as the property and service pages: the story
 * on the left, the ways to reach the person on the right.
 */
$name     = $agent['full_name'];
$branch   = $agent['branch_name'] ?? '';
$savedIds = savedPropertyIds();
?>

<section class="detail-page">
    <div class="site-container">

        <nav aria-label="Breadcrumb">
            <ol class="crumbs">
                <li><a href="<?= APP_URL ?>/index.php?page=home">Home</a></li>
                <li><a href="<?= APP_URL ?>/index.php?page=agents">Agents</a></li>
                <li><span aria-current="page"><?= sanitize($name) ?></span></li>
            </ol>
        </nav>

        <!-- ══ PROFILE HEADER ═══════════════════════════════ -->
        <div class="agent-hero">
            <img class="agent-hero__photo" src="<?= sanitize(agentImage($agent)) ?>"
                 alt="<?= sanitize($name) ?>" loading="eager" fetchpriority="high"
                 decoding="async" width="220" height="220">

            <div class="agent-hero__body">
                <span class="eyebrow">Property agent<?= $branch ? ' · ' . sanitize($branch) : '' ?></span>
                <h1 class="detail-title"><?= sanitize($name) ?></h1>
                <p class="agent-hero__bio">
                    <?= sanitize($name) ?> represents
                    <?= (int) $agentStats['listings'] ?>
                    <?= (int) $agentStats['listings'] === 1 ? 'property' : 'properties' ?>
                    on <?= sanitize(companyName()) ?><?= $branch ? ', working out of the ' . sanitize($branch) . ' office' : '' ?>.
                    Every listing below has been visited and verified in person before publication.
                </p>

                <div class="agent-hero__actions">
                    <?php if (!empty($agent['phone'])): ?>
                        <a class="btn btn--primary" href="tel:<?= sanitize(preg_replace('/\s+/', '', $agent['phone'])) ?>">
                            <i class="bi bi-telephone" aria-hidden="true"></i> Call
                        </a>
                    <?php endif; ?>
                    <?php if (!empty($agent['email'])): ?>
                        <a class="btn btn--outline" href="mailto:<?= sanitize($agent['email']) ?>">
                            <i class="bi bi-envelope" aria-hidden="true"></i> Email
                        </a>
                    <?php endif; ?>
                    <a class="btn btn--outline" href="#agent-enquiry">
                        <i class="bi bi-chat-dots" aria-hidden="true"></i> Send a message
                    </a>
                </div>
            </div>
        </div>

        <div class="detail-layout" style="margin-top:var(--space-8)">

            <!-- ══ LISTINGS ═════════════════════════════════ -->
            <div>
                <div class="panel">
                    <h2 class="panel__title">
                        Listings by <?= sanitize(explode(' ', trim($name))[0]) ?>
                    </h2>

                    <?php if (!empty($agentProperties)): ?>
                        <p class="panel__lede">
                            Currently available. Saved searches update as new properties are added.
                        </p>
                        <div class="pgrid" style="margin-top:var(--space-6)">
                            <?php foreach ($agentProperties as $p): ?>
                                <?php renderPropertyCard($p, $agentCovers[(int) $p['id']] ?? null, $savedIds); ?>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="empty-state">
                            <span class="empty-state__icon" aria-hidden="true"><i class="bi bi-house"></i></span>
                            <h3 class="empty-state__title">No available listings right now</h3>
                            <p class="empty-state__desc">
                                <?= sanitize(explode(' ', trim($name))[0]) ?> has nothing on the market
                                at the moment. Send a message and you will hear first when that changes.
                            </p>
                            <a href="<?= APP_URL ?>/index.php?page=listings" class="btn btn--outline">
                                Browse all properties
                            </a>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="panel" style="margin-top:var(--space-6)">
                    <h2 class="panel__title">How <?= sanitize(explode(' ', trim($name))[0]) ?> works</h2>
                    <div class="value-grid value-grid--2">
                        <?php
                        $traits = [
                            ['bi-geo-alt',      '',         'Knows the area',    'Every listing is walked in person before it reaches the site.'],
                            ['bi-chat-square-text', '--green',  'Answers quickly',   'Enquiries are logged against the property and answered within a day.'],
                            ['bi-clipboard-check',  '--purple', 'Keeps the record',  'Viewings, offers and documents all tracked in one place.'],
                            ['bi-people',       '--gold',   'One point of contact', 'The same agent from first viewing through to handover.'],
                        ];
                        foreach ($traits as [$icon, $tone, $title, $desc]): ?>
                            <div class="value-card">
                                <span class="value-card__icon value-card__icon<?= $tone ?>" aria-hidden="true">
                                    <i class="<?= $icon ?>"></i>
                                </span>
                                <h3 class="value-card__title"><?= $title ?></h3>
                                <p class="value-card__desc"><?= $desc ?></p>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <!-- ══ ASIDE ════════════════════════════════════ -->
            <aside class="detail-aside">

                <div class="sidecard">
                    <h2 class="sidecard__title">At a glance</h2>
                    <div class="sidecard__rows">
                        <div class="sidecard__row">
                            <span>Available listings</span>
                            <strong><?= (int) $agentStats['listings'] ?></strong>
                        </div>
                        <div class="sidecard__row">
                            <span>Total represented</span>
                            <strong><?= (int) $agentStats['total'] ?></strong>
                        </div>
                        <?php if ($branch): ?>
                            <div class="sidecard__row">
                                <span>Office</span>
                                <strong><?= sanitize($branch) ?></strong>
                            </div>
                        <?php endif; ?>
                        <?php if (!empty($agentStats['since'])): ?>
                            <div class="sidecard__row">
                                <span>With <?= sanitize(companyName()) ?> since</span>
                                <strong><?= sanitize($agentStats['since']) ?></strong>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="sidecard" style="margin-top:var(--space-5)">
                    <h2 class="sidecard__title">Contact</h2>
                    <div class="sidecard__rows">
                        <?php if (!empty($agent['phone'])): ?>
                            <div class="sidecard__row">
                                <span><i class="bi bi-telephone" aria-hidden="true"></i> Phone</span>
                                <a href="tel:<?= sanitize(preg_replace('/\s+/', '', $agent['phone'])) ?>">
                                    <?= sanitize($agent['phone']) ?>
                                </a>
                            </div>
                        <?php endif; ?>
                        <?php if (!empty($agent['email'])): ?>
                            <div class="sidecard__row">
                                <span><i class="bi bi-envelope" aria-hidden="true"></i> Email</span>
                                <a href="mailto:<?= sanitize($agent['email']) ?>"><?= sanitize($agent['email']) ?></a>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="sidecard" id="agent-enquiry" style="margin-top:var(--space-5)">
                    <h2 class="sidecard__title">Send a message</h2>
                    <p class="sidecard__agent-role" style="margin-bottom:var(--space-4)">
                        Goes straight to <?= sanitize(explode(' ', trim($name))[0]) ?>'s enquiry list.
                    </p>

                    <form method="POST" action="<?= APP_URL ?>/index.php?page=contact" data-validate>
                        <input type="hidden" name="subject"
                               value="Enquiry for agent: <?= sanitize($name) ?>">

                        <div class="form-group">
                            <label class="form-label" for="ag-name">Your name</label>
                            <input class="form-control" id="ag-name" name="name" required
                                   autocomplete="name" placeholder="Full name">
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="ag-email">Email</label>
                            <input class="form-control" id="ag-email" name="email" type="email" required
                                   autocomplete="email" placeholder="you@example.com">
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="ag-phone">Phone <span class="text-subtle">(optional)</span></label>
                            <input class="form-control" id="ag-phone" name="phone" type="tel"
                                   autocomplete="tel" placeholder="+252 …">
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="ag-msg">Message</label>
                            <textarea class="form-control" id="ag-msg" name="message" rows="4" required
                                      placeholder="Which property are you interested in?"></textarea>
                        </div>

                        <button type="submit" class="btn btn--primary btn--block">
                            <i class="bi bi-send" aria-hidden="true"></i> Send message
                        </button>
                    </form>
                </div>
            </aside>
        </div>
    </div>
</section>
