<?php
/**
 * Header / Topbar
 * Minimal SaaS header — breadcrumb on the left, search in the middle, actions
 * on the right.
 * Expects: $currentUser, optional $breadcrumbs ([['label'=>..., 'url'=>...], ...])
 *
 * Search sits here rather than in the rail because it is a way of *acting* on
 * the application, not a place inside it: the topbar is where the page-level
 * controls live, it stays put while the rail scrolls, and one width serves it
 * on every screen where the rail is a drawer the user has to open first.
 */
$notifCount = getUnreadNotificationCount();
$role       = $currentUser['role'] ?? '';

// What the search can find is exactly what this user is allowed to open —
// the navigation itself, flattened. Emitted as a JSON island rather than
// inlined into a script literal so a role-specific label can never break out
// of the tag; JSON_HEX_TAG keeps "</script>" impossible in the payload.
$searchIndexJson = json_encode(
    appNavSearchIndex(),
    JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE
);
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

    <!--
      Combobox, not a decorative field: the input owns the query, the panel
      below it is the listbox, and aria-activedescendant is what lets the
      arrow keys walk the results while focus and typing stay in the input.
    -->
    <div class="header__search" data-global-search>
        <i class="bi bi-search header__search-icon" aria-hidden="true"></i>
        <input type="text"
               id="globalSearch"
               class="header__search-input"
               placeholder="Search pages and modules"
               aria-label="Search pages and modules"
               autocomplete="off"
               spellcheck="false"
               role="combobox"
               aria-expanded="false"
               aria-controls="globalSearchList"
               aria-autocomplete="list"
               aria-haspopup="listbox">
        <button type="button" class="header__search-clear" aria-label="Clear search" hidden>
            <i class="bi bi-x-lg" aria-hidden="true"></i>
        </button>
        <kbd class="header__search-kbd" aria-hidden="true">Ctrl K</kbd>

        <!--
          The listbox is the result list alone. The dead-end message and the
          shortcut legend are siblings rather than children of it: a listbox
          that contains anything other than options is read back as a broken
          list, and the legend is decoration a screen reader has no use for.
        -->
        <div class="header__search-panel" id="globalSearchPanel" hidden>
            <div class="gs-list" id="globalSearchList" role="listbox" aria-label="Search results"></div>
            <div class="gs-empty" data-search-empty role="status" hidden>
                <div class="gs-empty__icon"><i class="bi bi-search" aria-hidden="true"></i></div>
                <div class="gs-empty__title">No page matches &ldquo;<span data-empty-query></span>&rdquo;</div>
                <div class="gs-empty__desc">Search by module &mdash; try &ldquo;properties&rdquo;, &ldquo;payments&rdquo; or &ldquo;reports&rdquo;.</div>
            </div>
            <div class="gs-foot" aria-hidden="true">
                <span><kbd>&uarr;</kbd><kbd>&darr;</kbd> Move</span>
                <span><kbd>&crarr;</kbd> Open</span>
                <span><kbd>Esc</kbd> Close</span>
            </div>
        </div>
        <script type="application/json" data-search-index><?= $searchIndexJson ?></script>
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
