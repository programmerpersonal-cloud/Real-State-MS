<?php
/**
 * Sidebar — the application rail.
 *
 * Rebuilt in Phase 6. The rail previously listed every destination a role
 * could reach as one flat run: twenty-six rows for an administrator, squeezed
 * to 30px each to make them fit. That is below comfortable for a primary
 * navigation target, and it was the single biggest reason the shell read as an
 * admin panel rather than a product.
 *
 * Three changes fix it:
 *
 *   1. Sections became collapsible. A typical session now shows eight to
 *      twelve rows instead of twenty-six, which buys back the height to make
 *      rows 36px at a readable size.
 *   2. A collapsed rail mode reduces the column to 68px of icons, for anyone
 *      working in a wide table.
 *   3. Active state is carried by a left indicator bar as well as a fill, so
 *      "where am I" survives being read by someone who cannot separate the
 *      two tones.
 *
 * What did NOT change: the menu is still rendered from includes/navigation.php,
 * still filtered by canAccessPage() per item, and still drops a section that
 * has nothing under it. The rail remains a projection of the permission matrix
 * — collapsing a group hides a row from view, never from the check behind it.
 *
 * Expects: $currentUser (from getCurrentUser()), optional $notif ['count'=>int]
 */
$role = $currentUser['role'] ?? '';
$page = $_GET['page'] ?? 'dashboard';

// The bell count is already fetched for the topbar; the rail borrows it so the
// Notifications row can carry a number without a second query.
$railUnread = (int) ($notif['count'] ?? 0);
?>
<div class="sidebar-overlay" id="sidebarOverlay"></div>

<aside class="app__sidebar" id="sidebar" data-rail>

    <!-- Brand. Doubles as the rail's collapse control on desktop: the logo
         stays put and the toggle sits where the title ends, so nothing moves
         between the two states except the width. -->
    <div class="sidebar__brand">
        <?php $brandLogo = companyLogoUrl(); ?>
        <div class="sidebar__logo<?= $brandLogo !== '' ? ' sidebar__logo--image' : '' ?>">
            <?php if ($brandLogo !== ''): ?>
                <img src="<?= sanitize($brandLogo) ?>" alt="<?= sanitize(companyName()) ?>">
            <?php else: ?>
                <i class="bi bi-buildings-fill" aria-hidden="true"></i>
            <?php endif ?>
        </div>
        <div class="sidebar__brand-text">
            <div class="sidebar__title"><?= sanitize(companyName()) ?></div>
            <div class="sidebar__subtitle">Real Estate</div>
        </div>

        <button type="button" class="sidebar__collapse" data-rail-toggle
                aria-label="Collapse the navigation rail" aria-pressed="false"
                title="Collapse rail">
            <i class="bi bi-chevron-double-left" aria-hidden="true"></i>
        </button>
    </div>

    <nav class="sidebar__nav" aria-label="Main">
        <?php foreach (appNavSections() as [$sectionLabel, $items, $sectionKey]): ?>
            <?php
            /* A group holding the current page opens regardless of what the
               browser remembered. A stored preference that hides the page you
               are on is a bug wearing the costume of a preference. */
            $holdsCurrent = navSectionHoldsPage($items, $page);
            /* Single-item groups have nothing to collapse — rendering a
               disclosure around one row is chrome for its own sake. */
            $collapsible  = count($items) > 1;
            $panelId      = 'nav-' . $sectionKey;
            ?>
            <div class="sidebar__section<?= $holdsCurrent ? ' is-current' : '' ?>"
                 data-nav-section="<?= sanitize($sectionKey) ?>"
                 <?= $holdsCurrent ? 'data-holds-current' : '' ?>>

                <?php if ($collapsible): ?>
                    <button type="button" class="sidebar__label sidebar__label--toggle"
                            data-nav-toggle aria-expanded="true" aria-controls="<?= $panelId ?>">
                        <span><?= sanitize($sectionLabel) ?></span>
                        <i class="bi bi-chevron-down sidebar__label-chev" aria-hidden="true"></i>
                    </button>
                <?php else: ?>
                    <div class="sidebar__label"><span><?= sanitize($sectionLabel) ?></span></div>
                <?php endif ?>

                <div class="sidebar__items" id="<?= $panelId ?>">
                    <?php foreach ($items as [$slug, $label, $icon]): ?>
                        <?php $isActive = $slug === $page; ?>
                        <a href="<?= APP_URL ?>/index.php?page=<?= sanitize($slug) ?>"
                           class="sidebar__link<?= $isActive ? ' sidebar__link--active' : '' ?>"
                           <?= $isActive ? 'aria-current="page"' : '' ?>
                           data-rail-label="<?= sanitize($label) ?>">
                            <i class="bi <?= sanitize($icon) ?>" aria-hidden="true"></i>
                            <span class="sidebar__link-text"><?= sanitize($label) ?></span>
                            <?php if ($slug === 'notifications' && $railUnread > 0): ?>
                                <span class="sidebar__link-count"><?= $railUnread > 99 ? '99+' : $railUnread ?></span>
                            <?php endif ?>
                        </a>
                    <?php endforeach ?>
                </div>
            </div>
        <?php endforeach ?>
    </nav>

    <!--
      Account. The copyright strip that used to sit here is gone: the company
      name is already the brand at the top of this rail and the full notice is
      in the page footer, so a third copy was spending rail height the menu
      needed more.
    -->
    <div class="sidebar__footer">
        <?php
        // ?: rather than ??, because getCurrentUser() always returns these
        // keys and defaults them to '' — an account with no email on file
        // would otherwise render a blank second line and an empty tooltip.
        $acctName  = $currentUser['full_name'] ?: 'User';
        $acctEmail = $currentUser['email'] ?: '';
        ?>
        <a href="<?= APP_URL ?>/index.php?page=profile"
           class="sidebar__account"
           data-rail-label="<?= sanitize($acctName) ?>"
           title="<?= sanitize($acctEmail ?: 'View profile') ?>">
            <img src="<?= getAvatarUrl($currentUser['avatar'] ?? null) ?>" alt="" class="sidebar__account-avatar">
            <div class="sidebar__account-info">
                <div class="sidebar__account-name"><?= sanitize($acctName) ?></div>
                <div class="sidebar__account-meta"><?= sanitize($acctEmail ?: ucfirst($role)) ?></div>
            </div>
            <i class="bi bi-chevron-right sidebar__account-chevron" aria-hidden="true"></i>
        </a>
    </div>
</aside>
