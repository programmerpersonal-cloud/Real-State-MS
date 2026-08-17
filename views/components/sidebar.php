<?php
/**
 * Sidebar Component
 * Light SaaS sidebar — brand, menu, and the signed-in account at the foot.
 * Expects: $currentUser (from getCurrentUser())
 *
 * The account sits under the menu rather than above it. The top of a rail is
 * the most valuable space in the column and identity does not earn it: the
 * menu is what people came for, so it starts directly under the brand and
 * gets every row the viewport can give it. Putting the account last also
 * keeps the route to Sign Out away from a mis-tap on a navigation row.
 *
 * The menu itself lives in includes/navigation.php, where the header's global
 * search reads the same list — the rail renders that list rather than owning
 * a copy of it. Every entry is drawn only when can() says the signed-in user
 * may open it, so the menu is a projection of the permission matrix rather
 * than a second copy kept in step by hand.
 *
 * Search is not in the rail: it is a page-level action, not a destination,
 * and it now sits in the topbar beside the other application-wide controls.
 */
$role = $currentUser['role'] ?? '';
$page = $_GET['page'] ?? 'dashboard';

/** Mark link active when the current ?page matches any of $matches */
function sb_link_class(string $current, array $matches): string {
    return in_array($current, $matches, true) ? 'sidebar__link sidebar__link--active' : 'sidebar__link';
}
?>
<div class="sidebar-overlay" id="sidebarOverlay"></div>
<aside class="app__sidebar" id="sidebar">

    <!-- Brand -->
    <div class="sidebar__brand">
        <?php $brandLogo = companyLogoUrl(); ?>
        <div class="sidebar__logo<?= $brandLogo !== '' ? ' sidebar__logo--image' : '' ?>">
            <?php if ($brandLogo !== ''): ?>
                <img src="<?= sanitize($brandLogo) ?>" alt="<?= sanitize(companyName()) ?>">
            <?php else: ?>
                <i class="bi bi-buildings-fill"></i>
            <?php endif ?>
        </div>
        <div>
            <div class="sidebar__title"><?= sanitize(companyName()) ?></div>
            <div class="sidebar__subtitle">Real Estate</div>
        </div>
    </div>

    <nav class="sidebar__nav">
        <?php foreach (appNavSections() as [$sectionLabel, $items]): ?>
            <div class="sidebar__section">
                <div class="sidebar__label"><?= sanitize($sectionLabel) ?></div>
                <?php foreach ($items as [$slug, $label, $icon]): ?>
                    <a href="<?= APP_URL ?>/index.php?page=<?= sanitize($slug) ?>"
                       class="<?= sb_link_class($page, [$slug]) ?>">
                        <i class="bi <?= sanitize($icon) ?>"></i> <?= sanitize($label) ?>
                    </a>
                <?php endforeach ?>
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
        // The full address goes in the title: at 248px the rail always
        // ellipses it, so hovering has to be able to finish the sentence.
        $acctName  = $currentUser['full_name'] ?: 'User';
        $acctEmail = $currentUser['email'] ?: '';
        ?>
        <a href="<?= APP_URL ?>/index.php?page=profile"
           class="sidebar__account"
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
