<?php
/**
 * Header / Topbar
 * Minimal SaaS header — breadcrumb on the left, actions on the right.
 * Expects: $currentUser, optional $breadcrumbs ([['label'=>..., 'url'=>...], ...])
 */
$notifCount = getUnreadNotificationCount();
$role       = $currentUser['role'] ?? '';
?>
<header class="app__header">
    <div class="header__left">
        <button class="header__toggle" id="sidebarToggle" aria-label="Toggle navigation">
            <i class="bi bi-list"></i>
        </button>

        <nav class="header__breadcrumb" aria-label="Breadcrumb">
            <a href="<?= APP_URL ?>/index.php?page=dashboard" title="Dashboard">
                <i class="bi bi-house-door"></i>
            </a>
            <?php if (!empty($breadcrumbs)): ?>
                <?php foreach ($breadcrumbs as $i => $crumb): ?>
                    <i class="bi bi-chevron-right sep"></i>
                    <?php if (!empty($crumb['url']) && $i < count($breadcrumbs) - 1): ?>
                        <a href="<?= $crumb['url'] ?>"><?= sanitize($crumb['label']) ?></a>
                    <?php else: ?>
                        <span><?= sanitize($crumb['label']) ?></span>
                    <?php endif; ?>
                <?php endforeach; ?>
            <?php elseif (!empty($pageTitle)): ?>
                <i class="bi bi-chevron-right sep"></i>
                <span><?= sanitize($pageTitle) ?></span>
            <?php endif; ?>
        </nav>
    </div>

    <div class="header__right">
        <a href="<?= APP_URL ?>/index.php?page=home" class="header__visit" title="View the public website">
            <i class="bi bi-globe2"></i>
            <span>Visit Site</span>
        </a>
        <a href="<?= APP_URL ?>/index.php?page=notifications" class="header__icon-btn" title="Notifications" aria-label="Notifications">
            <i class="bi bi-bell"></i>
            <?php if ($notifCount > 0): ?>
                <span class="header__badge"><?= $notifCount > 9 ? '9+' : $notifCount ?></span>
            <?php endif; ?>
        </a>
        <a href="<?= APP_URL ?>/index.php?page=settings" class="header__icon-btn" title="Settings" aria-label="Settings">
            <i class="bi bi-gear"></i>
        </a>

        <div class="dropdown">
            <div class="header__user" data-dropdown role="button" tabindex="0">
                <img src="<?= getAvatarUrl($currentUser['avatar'] ?? null) ?>" alt="Avatar">
                <div class="header__user-info">
                    <div class="header__user-name"><?= sanitize($currentUser['full_name'] ?? 'User') ?></div>
                    <div class="header__user-role"><?= ucfirst($role) ?></div>
                </div>
                <i class="bi bi-chevron-down" style="color:var(--text-subtle);font-size:.7rem;margin-left:2px"></i>
            </div>
            <div class="dropdown__menu">
                <a href="<?= APP_URL ?>/index.php?page=profile" class="dropdown__item">
                    <i class="bi bi-person"></i> My Profile
                </a>
                <a href="<?= APP_URL ?>/index.php?page=settings" class="dropdown__item">
                    <i class="bi bi-gear"></i> Settings
                </a>
                <div class="dropdown__divider"></div>
                <a href="<?= APP_URL ?>/index.php?page=logout" class="dropdown__item dropdown__item--danger">
                    <i class="bi bi-box-arrow-right"></i> Sign Out
                </a>
            </div>
        </div>
    </div>
</header>
