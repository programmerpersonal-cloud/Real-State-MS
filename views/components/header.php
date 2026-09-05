<?php
/**
 * Header / Topbar — the application's command bar.
 *
 * Rebuilt in Phase 6. The bar previously carried six things, one of which was
 * duplicated: breadcrumb, search, Visit Site, notifications, Settings, and an
 * account menu that also contained Settings. The duplicate is gone — a control
 * offered twice in one bar makes neither instance look like the real one.
 *
 * What is here now, left to right:
 *   · the drawer toggle (mobile) and the breadcrumb — where you are
 *   · the command surface — a search that reads as something you press
 *   · notifications, with the newest unread readable without a page load
 *   · the account menu — profile, settings, sign out
 *
 * Search stays here rather than in the rail because it is a way of *acting* on
 * the application, not a place inside it: the topbar is where page-level
 * controls live, it stays put while the rail scrolls, and one width serves it
 * on every screen where the rail is a drawer the user has to open first.
 *
 * Expects: $currentUser, $notif (from notificationBell()), optional $breadcrumbs
 */
$notif      = $notif ?? notificationBell();
$notifCount = (int) $notif['count'];
$role       = $currentUser['role'] ?? '';

// What the search can find is exactly what this user is allowed to open —
// the navigation itself, flattened. Emitted as a JSON island rather than
// inlined into a script literal so a role-specific label can never break out
// of the tag; JSON_HEX_TAG keeps "</script>" impossible in the payload.
$searchIndexJson = json_encode(
    appNavSearchIndex(),
    JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE
);

/* Where a notification points, when it names a record it can reach. Mirrors
   the map the notifications page uses; anything unlisted stays unlinked
   rather than becoming a link to a 404. */
$notifTargets = [
    'property' => 'properties', 'lease' => 'leases', 'payment' => 'payments',
    'maintenance' => 'maintenance', 'inquiry' => 'inquiries',
    'conversation' => 'messages',
    'reservation' => 'reservations', 'sale' => 'sales', 'document' => 'documents',
];
$notifLook = [
    'success' => ['bi-check-circle-fill', 'success'],
    'warning' => ['bi-exclamation-triangle-fill', 'warning'],
    'error'   => ['bi-x-octagon-fill', 'danger'],
    'danger'  => ['bi-x-octagon-fill', 'danger'],
    'info'    => ['bi-info-circle-fill', 'info'],
];
?>
<header class="app__header">
    <div class="header__left">
        <button class="header__toggle" id="sidebarToggle" aria-label="Toggle navigation">
            <i class="bi bi-list" aria-hidden="true"></i>
        </button>

        <?php /* The rail's collapse control, in the one place it can hold
                 still. It used to sit in the rail's brand row, hidden until
                 the rail was hovered, and had to be re-anchored over the logo
                 once the column narrowed — so the control that changes the
                 width moved every time the width changed.

                 Here it is always visible and never moves. It is the desktop
                 counterpart of #sidebarToggle beside it: below 768px the rail
                 is a drawer, collapsing means nothing, and responsive.css
                 swaps which of the two is shown. */ ?>
        <button type="button" class="header__rail-toggle" data-rail-toggle
                aria-label="Collapse the navigation rail" aria-pressed="false"
                title="Collapse rail">
            <i class="bi bi-chevron-double-left" aria-hidden="true"></i>
        </button>

        <nav class="header__breadcrumb" aria-label="Breadcrumb">
            <a href="<?= APP_URL ?>/index.php?page=dashboard" title="Dashboard" class="header__crumb-home">
                <i class="bi bi-house-door" aria-hidden="true"></i>
                <span class="sr-only">Dashboard</span>
            </a>
            <?php if (!empty($breadcrumbs)): ?>
                <?php foreach ($breadcrumbs as $i => $crumb): ?>
                    <i class="bi bi-chevron-right sep" aria-hidden="true"></i>
                    <?php if (!empty($crumb['url']) && $i < count($breadcrumbs) - 1): ?>
                        <a href="<?= $crumb['url'] ?>"><?= sanitize($crumb['label']) ?></a>
                    <?php else: ?>
                        <span aria-current="page"><?= sanitize($crumb['label']) ?></span>
                    <?php endif; ?>
                <?php endforeach; ?>
            <?php elseif (!empty($pageTitle)): ?>
                <i class="bi bi-chevron-right sep" aria-hidden="true"></i>
                <span aria-current="page"><?= sanitize($pageTitle) ?></span>
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
            <i class="bi bi-globe2" aria-hidden="true"></i>
            <span>Visit site</span>
        </a>

        <?php /* Notifications. The newest unread arrive with the page from the
                 same query that produces the badge, so opening this costs
                 nothing extra — see notificationBell(). */ ?>
        <div class="dropdown dropdown--panel">
            <button type="button" class="header__icon-btn" data-dropdown
                    aria-haspopup="true" aria-expanded="false"
                    aria-label="Notifications<?= $notifCount > 0 ? ', ' . $notifCount . ' unread' : '' ?>">
                <i class="bi bi-bell" aria-hidden="true"></i>
                <?php if ($notifCount > 0): ?>
                    <span class="header__badge"><?= $notifCount > 9 ? '9+' : $notifCount ?></span>
                <?php endif; ?>
            </button>

            <div class="dropdown__menu notif-panel">
                <div class="notif-panel__head">
                    <span class="notif-panel__title">Notifications</span>
                    <?php if ($notifCount > 0): ?>
                        <span class="notif-panel__count"><?= number_format($notifCount) ?> unread</span>
                    <?php endif ?>
                </div>

                <?php if (empty($notif['items'])): ?>
                    <div class="notif-panel__empty">
                        <i class="bi bi-check2-circle" aria-hidden="true"></i>
                        <span>You are up to date.</span>
                    </div>
                <?php else: ?>
                    <ul class="notif-panel__list">
                        <?php foreach ($notif['items'] as $n): ?>
                            <?php
                            [$icon, $tone] = $notifLook[$n['type'] ?? 'info'] ?? $notifLook['info'];
                            $slug = $notifTargets[$n['reference_type'] ?? ''] ?? null;
                            $link = ($slug && (int) ($n['reference_id'] ?? 0) > 0 && canAccessPage($slug))
                                ? APP_URL . '/index.php?page=' . $slug . '&action=show&id=' . (int) $n['reference_id']
                                : APP_URL . '/index.php?page=notifications';
                            ?>
                            <li>
                                <a class="notif-item" href="<?= sanitize($link) ?>">
                                    <span class="notif-item__icon notif-item__icon--<?= $tone ?>">
                                        <i class="bi <?= $icon ?>" aria-hidden="true"></i>
                                    </span>
                                    <span class="notif-item__body">
                                        <span class="notif-item__title"><?= sanitize($n['title']) ?></span>
                                        <?php if (!empty($n['message'])): ?>
                                            <span class="notif-item__text"><?= sanitize(truncate($n['message'], 64)) ?></span>
                                        <?php endif ?>
                                        <span class="notif-item__time"><?= formatDateTime($n['created_at']) ?></span>
                                    </span>
                                </a>
                            </li>
                        <?php endforeach ?>
                    </ul>
                <?php endif ?>

                <div class="notif-panel__foot">
                    <a href="<?= APP_URL ?>/index.php?page=notifications">
                        Open notifications <i class="bi bi-arrow-right" aria-hidden="true"></i>
                    </a>
                </div>
            </div>
        </div>

        <?php /* The standalone Settings icon that used to sit here is gone. It
                 duplicated the entry in the account menu below. */ ?>

        <div class="dropdown">
            <div class="header__user" data-dropdown role="button" tabindex="0"
                 aria-haspopup="true" aria-expanded="false">
                <img src="<?= getAvatarUrl($currentUser['avatar'] ?? null) ?>" alt="">
                <div class="header__user-info">
                    <div class="header__user-name"><?= sanitize($currentUser['full_name'] ?? 'User') ?></div>
                    <div class="header__user-role"><?= sanitize(uiLabel((string) $role)) ?></div>
                </div>
                <i class="bi bi-chevron-down header__user-chev" aria-hidden="true"></i>
            </div>
            <div class="dropdown__menu">
                <a href="<?= APP_URL ?>/index.php?page=profile" class="dropdown__item">
                    <i class="bi bi-person" aria-hidden="true"></i> My profile
                </a>
                <?php if (canAccessPage('settings')): ?>
                    <a href="<?= APP_URL ?>/index.php?page=settings" class="dropdown__item">
                        <i class="bi bi-gear" aria-hidden="true"></i> Settings
                    </a>
                <?php endif ?>
                <div class="dropdown__divider"></div>
                <a href="<?= APP_URL ?>/index.php?page=logout" class="dropdown__item dropdown__item--danger">
                    <i class="bi bi-box-arrow-right" aria-hidden="true"></i> Sign out
                </a>
            </div>
        </div>
    </div>
</header>
