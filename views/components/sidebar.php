<?php
/**
 * Sidebar Component
 * Light SaaS sidebar with profile-on-top, search, and active-link detection.
 * Expects: $currentUser (from getCurrentUser())
 *
 * Every entry is drawn only when can() says the signed-in user may open it, so
 * the menu is a projection of the permission matrix rather than a second copy
 * of it kept in step by hand. That is what the previous hard-coded role checks
 * could not guarantee: Operations was rendered unconditionally while
 * InquiryController admitted only staff, so owners and tenants were shown a
 * link that bounced them to the dashboard.
 *
 * Sections disappear on their own once every item inside them is hidden, so a
 * role never sees an empty heading.
 */
$role = $currentUser['role'] ?? '';
$page = $_GET['page'] ?? 'dashboard';

/** Mark link active when the current ?page matches any of $matches */
function sb_link_class(string $current, array $matches): string {
    return in_array($current, $matches, true) ? 'sidebar__link sidebar__link--active' : 'sidebar__link';
}

/**
 * Navigation, as data.
 *
 * Each item is [page slug, label, icon]. The permission checked is
 * "{slug}.view" — the same string canAccessPage() and the controller use.
 *
 * Labels are per-role where the same module means a different thing to
 * different people: an agent's Inquiries is the agency's lead inbox, an
 * owner's is interest in their properties, a tenant's is their own
 * correspondence. Same page, same permission, honest name.
 */
$sbInquiryLabel = match ($role) {
    ROLE_OWNER    => 'Property Inquiries',
    ROLE_CUSTOMER => 'My Inquiries',
    default       => 'Inquiries',
};
$sbMaintenanceLabel = match ($role) {
    ROLE_CUSTOMER    => 'My Requests',
    ROLE_MAINTENANCE => 'My Jobs',
    default          => 'Maintenance',
};

$sbSections = [
    ['Main Menu', [
        ['dashboard', 'Dashboard', 'bi-grid-1x2'],
    ]],

    ['Property', [
        ['properties',   'Properties',   'bi-buildings'],
        ['reservations', 'Reservations', 'bi-calendar-check'],
        ['documents',    'Documents',    'bi-folder2-open'],
    ]],

    ['People', [
        ['customers', 'Customers', 'bi-people'],
        ['owners',    'Owners',    'bi-person-badge'],
    ]],

    ['Transactions', [
        ['leases',   'Leases',   'bi-file-earmark-text'],
        ['payments', 'Payments', 'bi-credit-card'],
        ['sales',    'Sales',    'bi-cart-check'],
    ]],

    ['My Account', [
        ['my-lease',    'My Lease',    'bi-file-earmark-text'],
        ['my-payments', 'My Payments', 'bi-credit-card'],
        ['favorites',   'Favorites',   'bi-heart'],
    ]],

    ['My Portfolio', [
        ['my-properties', 'Properties', 'bi-buildings'],
        ['my-income',     'Income',     'bi-graph-up'],
    ]],

    ['Operations', [
        ['maintenance',   $sbMaintenanceLabel, 'bi-wrench-adjustable'],
        ['inquiries',     $sbInquiryLabel,     'bi-chat-left-text'],
        ['notifications', 'Notifications',     'bi-bell'],
    ]],

    ['Administration', [
        ['users',               'Users & Roles',       'bi-shield-lock'],
        ['testimonials',        'Testimonials',        'bi-chat-quote'],
        ['branches',            'Branches',            'bi-diagram-3'],
        ['reports',             'Reports',             'bi-bar-chart-line'],
        ['document-categories', 'Document Categories', 'bi-tags'],
        ['legal',               'Terms & Legal',       'bi-file-earmark-check'],
        ['audit-logs',          'Audit Logs',          'bi-journal-text'],
        ['settings',            'Settings',            'bi-gear'],
    ]],
];
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

    <!-- Profile card -->
    <a href="<?= APP_URL ?>/index.php?page=profile" class="sidebar__profile" title="View profile">
        <img src="<?= getAvatarUrl($currentUser['avatar'] ?? null) ?>" alt="Avatar" class="sidebar__profile-avatar">
        <div class="sidebar__profile-info">
            <div class="sidebar__profile-name"><?= sanitize($currentUser['full_name'] ?? 'User') ?></div>
            <div class="sidebar__profile-email"><?= sanitize($currentUser['email'] ?? ucfirst($role)) ?></div>
        </div>
        <i class="bi bi-chevron-down sidebar__profile-chevron"></i>
    </a>

    <!-- Search -->
    <div class="sidebar__search">
        <i class="bi bi-search"></i>
        <input type="text" class="sidebar__search-input" placeholder="Search" id="globalSearch" autocomplete="off">
        <span class="sidebar__search-kbd">Ctrl + K</span>
    </div>

    <nav class="sidebar__nav">
        <?php foreach ($sbSections as [$sectionLabel, $items]): ?>
            <?php
            // Resolve the section before opening any markup: a heading is only
            // worth printing once we know something sits under it.
            $visible = array_values(array_filter(
                $items,
                fn(array $item): bool => canAccessPage($item[0])
            ));
            if (!$visible) {
                continue;
            }
            ?>
            <div class="sidebar__section">
                <div class="sidebar__label"><?= sanitize($sectionLabel) ?></div>
                <?php foreach ($visible as [$slug, $label, $icon]): ?>
                    <a href="<?= APP_URL ?>/index.php?page=<?= sanitize($slug) ?>"
                       class="<?= sb_link_class($page, [$slug]) ?>">
                        <i class="bi <?= sanitize($icon) ?>"></i> <?= sanitize($label) ?>
                    </a>
                <?php endforeach ?>
            </div>
        <?php endforeach ?>
    </nav>

    <div class="sidebar__footer">
        <i class="bi bi-shield-check"></i>
        <span><?= sanitize(companyName()) ?> &copy; <?= date('Y') ?></span>
    </div>
</aside>
