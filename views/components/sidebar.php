<?php
/**
 * Sidebar Component
 * Light SaaS sidebar with profile-on-top, search, and active-link detection.
 * Expects: $currentUser (from getCurrentUser())
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

        <!-- Main -->
        <div class="sidebar__section">
            <div class="sidebar__label">Main Menu</div>
            <a href="<?= APP_URL ?>/index.php?page=dashboard" class="<?= sb_link_class($page, ['dashboard']) ?>">
                <i class="bi bi-grid-1x2"></i> Dashboard
            </a>
        </div>

        <?php if (in_array($role, ['admin', 'agent'])): ?>
        <!-- Property -->
        <div class="sidebar__section">
            <div class="sidebar__label">Property</div>
            <a href="<?= APP_URL ?>/index.php?page=properties" class="<?= sb_link_class($page, ['properties']) ?>">
                <i class="bi bi-buildings"></i> Properties
            </a>
            <a href="<?= APP_URL ?>/index.php?page=reservations" class="<?= sb_link_class($page, ['reservations']) ?>">
                <i class="bi bi-calendar-check"></i> Reservations
            </a>
        </div>

        <!-- People -->
        <div class="sidebar__section">
            <div class="sidebar__label">People</div>
            <a href="<?= APP_URL ?>/index.php?page=customers" class="<?= sb_link_class($page, ['customers']) ?>">
                <i class="bi bi-people"></i> Customers
            </a>
            <a href="<?= APP_URL ?>/index.php?page=owners" class="<?= sb_link_class($page, ['owners']) ?>">
                <i class="bi bi-person-badge"></i> Owners
            </a>
        </div>

        <!-- Transactions -->
        <div class="sidebar__section">
            <div class="sidebar__label">Transactions</div>
            <a href="<?= APP_URL ?>/index.php?page=leases" class="<?= sb_link_class($page, ['leases']) ?>">
                <i class="bi bi-file-earmark-text"></i> Leases
            </a>
            <a href="<?= APP_URL ?>/index.php?page=payments" class="<?= sb_link_class($page, ['payments']) ?>">
                <i class="bi bi-credit-card"></i> Payments
            </a>
            <a href="<?= APP_URL ?>/index.php?page=sales" class="<?= sb_link_class($page, ['sales']) ?>">
                <i class="bi bi-cart-check"></i> Sales
            </a>
        </div>
        <?php endif; ?>

        <?php if ($role === 'customer'): ?>
        <div class="sidebar__section">
            <div class="sidebar__label">My Account</div>
            <a href="<?= APP_URL ?>/index.php?page=my-lease" class="<?= sb_link_class($page, ['my-lease']) ?>">
                <i class="bi bi-file-earmark-text"></i> My Lease
            </a>
            <a href="<?= APP_URL ?>/index.php?page=my-payments" class="<?= sb_link_class($page, ['my-payments']) ?>">
                <i class="bi bi-credit-card"></i> My Payments
            </a>
            <a href="<?= APP_URL ?>/index.php?page=favorites" class="<?= sb_link_class($page, ['favorites']) ?>">
                <i class="bi bi-heart"></i> Favorites
            </a>
        </div>
        <?php endif; ?>

        <?php if ($role === 'owner'): ?>
        <div class="sidebar__section">
            <div class="sidebar__label">My Properties</div>
            <a href="<?= APP_URL ?>/index.php?page=my-properties" class="<?= sb_link_class($page, ['my-properties']) ?>">
                <i class="bi bi-buildings"></i> Properties
            </a>
            <a href="<?= APP_URL ?>/index.php?page=my-income" class="<?= sb_link_class($page, ['my-income']) ?>">
                <i class="bi bi-graph-up"></i> Income
            </a>
        </div>
        <?php endif; ?>

        <!-- Operations -->
        <div class="sidebar__section">
            <div class="sidebar__label">Operations</div>
            <a href="<?= APP_URL ?>/index.php?page=maintenance" class="<?= sb_link_class($page, ['maintenance']) ?>">
                <i class="bi bi-wrench-adjustable"></i> Maintenance
            </a>
            <a href="<?= APP_URL ?>/index.php?page=inquiries" class="<?= sb_link_class($page, ['inquiries']) ?>">
                <i class="bi bi-chat-left-text"></i> Inquiries
            </a>
            <a href="<?= APP_URL ?>/index.php?page=notifications" class="<?= sb_link_class($page, ['notifications']) ?>">
                <i class="bi bi-bell"></i> Notifications
            </a>
        </div>

        <?php if ($role === 'admin'): ?>
        <!-- Admin -->
        <div class="sidebar__section">
            <div class="sidebar__label">Administration</div>
            <a href="<?= APP_URL ?>/index.php?page=users" class="<?= sb_link_class($page, ['users']) ?>">
                <i class="bi bi-shield-lock"></i> Users &amp; Roles
            </a>
            <a href="<?= APP_URL ?>/index.php?page=testimonials" class="<?= sb_link_class($page, ['testimonials']) ?>">
                <i class="bi bi-chat-quote"></i> Testimonials
            </a>
            <a href="<?= APP_URL ?>/index.php?page=branches" class="<?= sb_link_class($page, ['branches']) ?>">
                <i class="bi bi-diagram-3"></i> Branches
            </a>
            <a href="<?= APP_URL ?>/index.php?page=reports" class="<?= sb_link_class($page, ['reports']) ?>">
                <i class="bi bi-bar-chart-line"></i> Reports
            </a>
            <a href="<?= APP_URL ?>/index.php?page=audit-logs" class="<?= sb_link_class($page, ['audit-logs']) ?>">
                <i class="bi bi-journal-text"></i> Audit Logs
            </a>
            <a href="<?= APP_URL ?>/index.php?page=settings" class="<?= sb_link_class($page, ['settings']) ?>">
                <i class="bi bi-gear"></i> Settings
            </a>
        </div>
        <?php endif; ?>
    </nav>

    <div class="sidebar__footer">
        <i class="bi bi-shield-check"></i>
        <span><?= sanitize(companyName()) ?> &copy; <?= date('Y') ?></span>
    </div>
</aside>
