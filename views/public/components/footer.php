<footer class="site-footer">
    <div class="site-container">
        <div class="site-footer__grid">

            <div>
                <div class="brand">
                    <span class="brand__mark" aria-hidden="true"><i class="bi bi-buildings-fill"></i></span>
                    <span class="brand__text">
                        <span class="brand__name"><?= sanitize(companyName()) ?></span>
                        <span class="brand__tag">Real Estate</span>
                    </span>
                </div>

                <p class="site-footer__about">
                    A modern real estate platform for finding, listing and managing property —
                    built for agencies, owners and the people looking for their next home.
                </p>

                <?php /* NAP reads from config so it is byte-identical to the
                         contact page and the LocalBusiness structured data. */ ?>
                <ul class="site-footer__contact">
                    <li>
                        <i class="bi bi-geo-alt" aria-hidden="true"></i>
                        <address><?= BIZ_STREET ?><br><?= sanitize(BIZ_CITY) ?>, <?= BIZ_REGION ?></address>
                    </li>
                    <li>
                        <i class="bi bi-telephone" aria-hidden="true"></i>
                        <a href="tel:<?= sanitize(preg_replace('/\s+/', '', BIZ_PHONE)) ?>"><?= sanitize(BIZ_PHONE) ?></a>
                    </li>
                    <li>
                        <i class="bi bi-envelope" aria-hidden="true"></i>
                        <a href="mailto:<?= sanitize(BIZ_EMAIL) ?>"><?= sanitize(BIZ_EMAIL) ?></a>
                    </li>
                    <li>
                        <i class="bi bi-clock" aria-hidden="true"></i>
                        <span>Replies within <?= BIZ_RESPONSE_HOURS ?> hours</span>
                    </li>
                </ul>

                <?php /* Was four links to "#" — the same dead row the utility strip
                         carried. Both now render from social_links.php, which reads
                         the configured accounts and omits any network that has none,
                         so the footer can no longer promise a profile that is not
                         there. */ ?>
                <?php
                $socialClass = 'site-footer__social';
                $socialLabel = 'Follow ' . companyName();
                require VIEWS_PATH . '/components/social_links.php';
                ?>
            </div>

            <nav aria-labelledby="ft-explore">
                <h2 class="site-footer__title" id="ft-explore">Explore</h2>
                <ul class="site-footer__links">
                    <li><a href="<?= APP_URL ?>/index.php?page=listings">All properties</a></li>
                    <li><a href="<?= APP_URL ?>/index.php?page=listings&amp;property_type=sale">For sale</a></li>
                    <li><a href="<?= APP_URL ?>/index.php?page=listings&amp;property_type=rent">For rent</a></li>
                    <li><a href="<?= APP_URL ?>/index.php?page=listings&amp;category=apartment">Apartments</a></li>
                    <li><a href="<?= APP_URL ?>/index.php?page=listings&amp;category=villa">Villas</a></li>
                </ul>
            </nav>

            <nav aria-labelledby="ft-services">
                <h2 class="site-footer__title" id="ft-services">Services</h2>
                <ul class="site-footer__links">
                    <?php foreach (array_slice(siteServices(), 0, 5, true) as $slug => $svc): ?>
                        <li>
                            <a href="<?= APP_URL ?>/index.php?page=service&amp;id=<?= urlencode($slug) ?>">
                                <?= sanitize($svc['title']) ?>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </nav>

            <nav aria-labelledby="ft-company">
                <h2 class="site-footer__title" id="ft-company">Company</h2>
                <ul class="site-footer__links">
                    <li><a href="<?= APP_URL ?>/index.php?page=about">About us</a></li>
                    <li><a href="<?= APP_URL ?>/index.php?page=agents">Our agents</a></li>
                    <li><a href="<?= APP_URL ?>/index.php?page=services">All services</a></li>
                    <li><a href="<?= APP_URL ?>/index.php?page=contact">Contact</a></li>
                </ul>
            </nav>

            <nav aria-labelledby="ft-account">
                <h2 class="site-footer__title" id="ft-account">Account</h2>
                <ul class="site-footer__links">
                    <?php if (isLoggedIn()): ?>
                        <li><a href="<?= APP_URL ?>/index.php?page=dashboard">Dashboard</a></li>
                        <li><a href="<?= APP_URL ?>/index.php?page=favorites">Saved properties</a></li>
                        <li><a href="<?= APP_URL ?>/index.php?page=profile">My profile</a></li>
                        <li><a href="<?= APP_URL ?>/index.php?page=logout">Sign out</a></li>
                    <?php else: ?>
                        <li><a href="<?= APP_URL ?>/index.php?page=login">Sign in</a></li>
                        <li><a href="<?= APP_URL ?>/index.php?page=register">Create account</a></li>
                        <li><a href="<?= APP_URL ?>/index.php?page=register">List a property</a></li>
                        <li><a href="<?= APP_URL ?>/index.php?page=contact">Support</a></li>
                    <?php endif; ?>
                </ul>
            </nav>
        </div>

        <div class="site-footer__bottom">
            <div>&copy; <?= date('Y') ?> <?= sanitize(companyName()) ?>. All rights reserved.</div>
            <nav class="site-footer__legal" aria-label="Legal">
                <a href="<?= APP_URL ?>/index.php?page=privacy">Privacy</a>
                <a href="<?= APP_URL ?>/index.php?page=terms">Terms</a>
                <a href="<?= APP_URL ?>/index.php?page=contact">Support</a>
            </nav>
            <div><?= APP_TAGLINE ?></div>
        </div>
    </div>
</footer>
