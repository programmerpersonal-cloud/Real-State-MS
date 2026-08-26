<?php
/**
 * Notifications — the inbox.
 *
 * Not a table: a notification is a short piece of prose with a time on it,
 * and ruling it into columns makes it harder to read rather than easier.
 * Unread ones lead, carry a marker down the left edge and a tinted ground, so
 * the thing that needs attention is findable without reading every line.
 *
 * Vars from NotificationController::index().
 */
$listUrl = APP_URL . '/index.php?page=notifications';

$statusFilter = [
    'param'   => 'state',
    'value'   => $state,
    'options' => $states,
    'counts'  => $counts,
    'total'   => $counts['unread'] + $counts['read'],
    'all'     => 'Everything',
    'tones'   => false,   // read/unread is not a status, and has no tone map
];

/* Type → the glyph and tone the row wears. A notification's `type` is the
   alert vocabulary the rest of the app uses, so it reads from the same names. */
$look = [
    'success' => ['bi-check-circle-fill',        'success'],
    'warning' => ['bi-exclamation-triangle-fill', 'warning'],
    'error'   => ['bi-x-octagon-fill',           'danger'],
    'danger'  => ['bi-x-octagon-fill',           'danger'],
    'info'    => ['bi-info-circle-fill',         'info'],
];

/* Where a notification points, when it names a record. Only the destinations
   that exist as routes — anything else stays plain text rather than becoming
   a link to a 404. */
$targets = [
    'property'     => 'properties',
    'lease'        => 'leases',
    'payment'      => 'payments',
    'maintenance'  => 'maintenance',
    'inquiry'      => 'inquiries',
    'conversation' => 'messages',
    'reservation'  => 'reservations',
    'sale'         => 'sales',
    'document'     => 'documents',
];
?>

<?php require VIEWS_PATH . '/components/ui/status_filter.php'; ?>

<?php if ($unreadCount > 0): ?>
    <div class="toolbar toolbar--plain">
        <p class="toolbar__note">
            <?= number_format($unreadCount) ?> unread
            <?= $unreadCount === 1 ? 'notification' : 'notifications' ?>.
        </p>
        <div class="toolbar__actions">
            <?php /* A state change, so a signed POST rather than a link — a
                     prefetcher must not be able to clear someone's inbox. */ ?>
            <form method="POST" action="<?= $listUrl ?>&amp;action=read-all">
                <?= csrfField() ?>
                <button type="submit" class="btn btn--outline btn--sm"
                        data-confirm="Everything in this inbox is marked as read. The notifications themselves are kept."
                        data-confirm-title="Mark all as read?"
                        data-confirm-action="Mark all read"
                        data-confirm-tone="primary">
                    <i class="bi bi-check2-all" aria-hidden="true"></i> Mark all as read
                </button>
            </form>
        </div>
    </div>
<?php endif ?>

<div class="table-card">
    <?php if (empty($notifications)): ?>
        <?= uiEmptyState([
            'icon'     => 'bi-bell-slash',
            'filtered' => $state !== '',
            'title'    => $state === 'unread' ? 'Nothing unread'
                        : ($state === 'read' ? 'Nothing read yet' : 'No notifications yet'),
            'desc'     => $state === 'unread'
                ? 'You are up to date.'
                : 'The system writes here when something happens that concerns you — a payment recorded, a fault reported, a lease approaching its end.',
            'clearUrl' => $listUrl,
        ]) ?>
    <?php else: ?>
        <ul class="feed">
            <?php foreach ($notifications as $n): ?>
                <?php
                $isRead = (bool) $n['is_read'];
                [$icon, $tone] = $look[$n['type'] ?? 'info'] ?? $look['info'];
                $slug = $targets[$n['reference_type'] ?? ''] ?? null;
                $link = ($slug && (int) ($n['reference_id'] ?? 0) > 0 && canAccessPage($slug))
                    ? APP_URL . '/index.php?page=' . $slug . '&action=show&id=' . (int) $n['reference_id']
                    : null;
                ?>
                <li class="feed__item<?= $isRead ? '' : ' is-unread' ?>">
                    <span class="feed__icon feed__icon--<?= $tone ?>" aria-hidden="true">
                        <i class="bi <?= $icon ?>"></i>
                    </span>

                    <div class="feed__body">
                        <div class="feed__title">
                            <?php if ($link): ?>
                                <a href="<?= sanitize($link) ?>"><?= sanitize($n['title']) ?></a>
                            <?php else: ?>
                                <?= sanitize($n['title']) ?>
                            <?php endif ?>
                            <?php if (!$isRead): ?>
                                <span class="sr-only">(unread)</span>
                            <?php endif ?>
                        </div>
                        <?php if (!empty($n['message'])): ?>
                            <p class="feed__text"><?= sanitize($n['message']) ?></p>
                        <?php endif ?>
                        <div class="feed__meta">
                            <time datetime="<?= sanitize($n['created_at']) ?>">
                                <?= formatDateTime($n['created_at']) ?>
                            </time>
                        </div>
                    </div>

                    <?php if (!$isRead): ?>
                        <form method="POST" action="<?= $listUrl ?>&amp;action=read&amp;id=<?= (int) $n['id'] ?>"
                              class="feed__action">
                            <?= csrfField() ?>
                            <button type="submit" class="btn btn--ghost btn--sm"
                                    title="Mark as read">
                                <i class="bi bi-check2" aria-hidden="true"></i>
                                <span class="sr-only">Mark &ldquo;<?= sanitize($n['title']) ?>&rdquo; as read</span>
                            </button>
                        </form>
                    <?php endif ?>
                </li>
            <?php endforeach ?>
        </ul>

        <?php if ($totalPages > 1): ?>
            <div class="table-foot">
                <span class="table-foot__note">Page <?= $page ?> of <?= $totalPages ?></span>
                <?php require VIEWS_PATH . '/components/pagination.php'; ?>
            </div>
        <?php endif ?>
    <?php endif ?>
</div>
