<?php
/**
 * Public Header — utility topbar + sticky primary navigation.
 * Expects optional: $publicView (current page key, used for active state)
 */
$active   = $publicView ?? '';
$loggedIn = isLoggedIn();
$me       = $loggedIn ? getCurrentUser() : null;

$navLinks = [
    'home'     => ['label' => 'Home',       'url' => APP_URL . '/index.php?page=home'],
    'listings' => ['label' => 'Properties', 'url' => APP_URL . '/index.php?page=listings'],
    'services' => ['label' => 'Services',   'url' => APP_URL . '/index.php?page=services'],
    'agents'   => ['label' => 'Agents',     'url' => APP_URL . '/index.php?page=agents'],
    'about'    => ['label' => 'About',      'url' => APP_URL . '/index.php?page=about'],
    'contact'  => ['label' => 'Contact',    'url' => APP_URL . '/index.php?page=contact'],
];

// Detail pages keep their parent section lit in the nav.
$activeParent = ['service' => 'services', 'agent' => 'agents', 'listing' => 'listings'];
$active       = $activeParent[$active] ?? $active;

/** Renders the shared nav list; used for both desktop bar and mobile drawer. */
$renderNavList = function (string $active) use ($navLinks): void { ?>
    <ul class="navmenu__list">
        <?php foreach ($navLinks as $key => $link): ?>
            <li>
                <a href="<?= $link['url'] ?>"
                   class="navmenu__link <?= $active === $key ? 'is-active' : '' ?>"
                   <?= $active === $key ? 'aria-current="page"' : '' ?>><?= $link['label'] ?></a>
            </li>
        <?php endforeach; ?>
    </ul>
<?php };
?>
<a class="skip-link" href="#main">Skip to main content</a>

<header class="site-header" id="siteHeader">

    <!-- Utility strip. Collapses away on scroll to reclaim vertical space. -->
    <div class="topbar">
        <div class="site-container topbar__inner">
            <div class="topbar__contact">
                <a href="mailto:<?= sanitize(BIZ_EMAIL) ?>">
                    <i class="bi bi-envelope" aria-hidden="true"></i>
                    <span><?= sanitize(BIZ_EMAIL) ?></span>
                </a>
                <a href="tel:<?= sanitize(preg_replace('/\s+/', '', BIZ_PHONE)) ?>">
                    <i class="bi bi-telephone" aria-hidden="true"></i>
                    <span><?= sanitize(BIZ_PHONE) ?></span>
                </a>
            </div>
            <?php /* Four hand-written links to "#" used to sit here: they looked
                     like controls, announced themselves as "Marko Real Estate on
                     Facebook, link", and went nowhere — on every public page.

                     social_links.php is the definition this site already had.
                     Each network's URL comes from the settings table, falls back
                     to the BIZ_SOCIAL defaults, and a network with neither is
                     left out of the row entirely rather than rendered dead. The
                     footer and the sign-in screen have been using it all along;
                     this is the last place that kept its own copy. */ ?>
            <?php
            $socialClass = 'topbar__social';
            $socialLabel = 'Follow ' . companyName();
            require VIEWS_PATH . '/components/social_links.php';
            ?>
        </div>
    </div>

    <div class="navbar">
        <nav class="site-container navbar__inner" aria-label="Primary">

            <a href="<?= APP_URL ?>/index.php?page=home" class="brand">
                <?php $brandLogo = companyLogoUrl(); ?>
                <span class="brand__mark<?= $brandLogo !== '' ? ' brand__mark--image' : '' ?>" aria-hidden="true">
                    <?php if ($brandLogo !== ''): ?>
                        <img src="<?= sanitize($brandLogo) ?>" alt="">
                    <?php else: ?>
                        <i class="bi bi-buildings-fill"></i>
                    <?php endif ?>
                </span>
                <span class="brand__text">
                    <span class="brand__name"><?= sanitize(companyName()) ?></span>
                    <span class="brand__tag">Real Estate</span>
                </span>
            </a>

            <!-- Same list serves desktop and drawer; CSS repositions it. -->
            <div class="navmenu" id="navmenu">
                <div class="navmenu__head">
                    <span class="brand__name"><?= sanitize(companyName()) ?></span>
                    <button type="button" class="navmenu__close" id="navClose" aria-label="Close menu">
                        <i class="bi bi-x-lg" aria-hidden="true"></i>
                    </button>
                </div>

                <?php $renderNavList($active); ?>

                <div class="navmenu__footer">
                    <?php if ($loggedIn): ?>
                        <a href="<?= APP_URL ?>/index.php?page=dashboard" class="btn btn--primary">
                            <i class="bi bi-speedometer2" aria-hidden="true"></i> Go to dashboard
                        </a>
                    <?php else: ?>
                        <a href="<?= APP_URL ?>/index.php?page=login" class="btn btn--outline">Sign in</a>
                        <a href="<?= APP_URL ?>/index.php?page=register" class="btn btn--primary">Create account</a>
                    <?php endif; ?>
                </div>
            </div>

            <div class="navbar__actions">
                <?php if ($loggedIn): ?>
                    <a href="<?= APP_URL ?>/index.php?page=dashboard" class="btn btn--primary btn--sm">
                        <span><?= sanitize(explode(' ', trim($me['full_name'] ?: 'Dashboard'))[0]) ?></span>
                        <i class="bi bi-arrow-right" aria-hidden="true"></i>
                    </a>
                <?php else: ?>
                    <a href="<?= APP_URL ?>/index.php?page=login" class="btn btn--outline btn--sm">Sign in</a>
                    <?php /* Hidden on narrow phones, where brand + CTA + menu button
                             cannot share a row without one of them being squeezed.
                             The drawer below carries the same two actions. */ ?>
                    <a href="<?= APP_URL ?>/index.php?page=register" class="btn btn--primary btn--sm nav-cta">List a property</a>
                <?php endif; ?>

                <button type="button" class="nav-toggle" id="navToggle"
                        aria-label="Open menu" aria-expanded="false" aria-controls="navmenu">
                    <i class="bi bi-list" aria-hidden="true"></i>
                </button>
            </div>
        </nav>
    </div>
</header>

<div class="nav-scrim" id="navScrim" hidden></div>
