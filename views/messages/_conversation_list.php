<?php
/**
 * The inbox panel: search, filters, and the conversation list.
 *
 * Everything here is a link or a plain GET form. There is no client-side
 * filtering, and no conversation is fetched that the access layer did not
 * return — Conversation::forUser() applies conversationViewScope() itself, so
 * this file renders what it is given and asks no questions of its own.
 *
 * Expects: $conversations $totalCount $page $perPage $filter $filters $search
 *          $base $listSuffix $conversation $contacts $unreadTotal
 */
$openId    = (int) ($conversation['id'] ?? 0);
$activeF   = $filter ?? 'all';
$totalPages = (int) ceil(($totalCount ?? 0) / max(1, $perPage ?? 20));
?>
<div class="msg__list-head">
    <div class="msg__list-title">
        <h1 class="msg__list-heading">Messages</h1>

        <?php /* The count is announced as a phrase rather than a bare number:
                 a screen reader meeting "3" on its own has been told nothing.
                 role=status so a changed total is spoken without stealing
                 focus from whatever the reader was doing. */ ?>
        <span class="msg__total" role="status" aria-atomic="true">
            <?php if (($unreadTotal ?? 0) > 0): ?>
                <?= number_format($unreadTotal) ?> unread message<?= $unreadTotal === 1 ? '' : 's' ?>
            <?php else: ?>
                <span class="sr-only">No unread messages</span>
                <span aria-hidden="true"><?= number_format($totalCount ?? 0) ?> conversation<?= (int) ($totalCount ?? 0) === 1 ? '' : 's' ?></span>
            <?php endif; ?>
        </span>
    </div>

    <?php /* The overflow menu replaces a permanent New message button: the
             inbox header should say what this is, not carry the widest control
             on the panel. A <details>, so it opens with a click and with Enter
             and needs no script; every item is a real link or a signed POST.

             Nothing dead is offered — "Starred" is absent because there is no
             star in the schema, and a menu item that cannot work is worse than
             one that is missing. */ ?>
    <details class="msg__more" data-msg-more>
        <summary class="msg__round msg__round--sm" title="Inbox options">
            <i class="bi bi-three-dots-vertical" aria-hidden="true"></i>
            <span class="sr-only">Inbox options</span>
        </summary>

        <div class="msg__more-menu" role="menu">
            <?php if (($unreadTotal ?? 0) > 0): ?>
                <form method="post" action="<?= APP_URL ?>/index.php?page=messages&amp;action=read-all">
                    <?= csrfField() ?>
                    <button class="msg__more-item" type="submit" role="menuitem">
                        <i class="bi bi-check2-all" aria-hidden="true"></i> Mark all as read
                    </button>
                </form>
            <?php else: ?>
                <span class="msg__more-item is-disabled" role="menuitem" aria-disabled="true">
                    <i class="bi bi-check2-all" aria-hidden="true"></i> Nothing unread
                </span>
            <?php endif; ?>

            <a class="msg__more-item" role="menuitem"
               href="<?= APP_URL ?>/index.php?page=messages&amp;filter=archived">
                <i class="bi bi-archive" aria-hidden="true"></i> Archived conversations
            </a>

            <?php if (!empty($contacts) && can('messages.create')): ?>
                <a class="msg__more-item" role="menuitem" href="<?= sanitize($base . '&compose=1') ?>">
                    <i class="bi bi-pencil-square" aria-hidden="true"></i> New message
                </a>
            <?php endif; ?>
        </div>
    </details>
</div>

<?php /* One rounded field. The separate Search button is gone: the form still
         submits on Enter, which is how anyone actually searches, and a button
         as wide as the field was the loudest thing on the panel. */ ?>
<form class="msg__search" method="get" action="<?= APP_URL ?>/index.php" role="search">
    <input type="hidden" name="page" value="messages">
    <?php if ($activeF !== 'all'): ?>
        <input type="hidden" name="filter" value="<?= sanitize($activeF) ?>">
    <?php endif; ?>

    <label class="sr-only" for="msgSearch">Search conversations by name, property or request</label>
    <div class="input-icon">
        <i class="bi bi-search" aria-hidden="true"></i>
        <input class="form-control" type="search" id="msgSearch" name="search"
               value="<?= sanitize($search ?? '') ?>"
               placeholder="Search name, property or request">
    </div>
    <button class="sr-only" type="submit">Search conversations</button>
</form>

<?php /* Filters are links, not buttons, because each is a real URL that can be
         bookmarked and reached with Back. aria-current marks the one in force. */ ?>
<nav class="msg__filters" aria-label="Filter conversations">
    <?php foreach (($filters ?? []) as $key => $label): ?>
        <?php
        $q = array_filter([
            'page'   => 'messages',
            'filter' => $key !== 'all' ? $key : null,
            'search' => ($search ?? '') !== '' ? $search : null,
        ]);
        $isOn = $key === $activeF;
        ?>
        <a class="msg__filter<?= $isOn ? ' is-active' : '' ?>"
           href="<?= APP_URL ?>/index.php?<?= sanitize(http_build_query($q)) ?>"
           <?= $isOn ? 'aria-current="true"' : '' ?>><?= sanitize($label) ?></a>
    <?php endforeach; ?>
</nav>

<?php if (empty($conversations)): ?>
    <div class="msg__items msg__items--empty">
        <?= uiEmptyState([
            'icon'     => ($search ?? '') !== '' ? 'bi-search' : 'bi-chat-dots',
            'filtered' => ($search ?? '') !== '' || $activeF !== 'all',
            'title'    => $activeF === 'archived' ? 'Nothing archived'
                          : (($search ?? '') !== '' ? 'No conversations found' : 'No conversations yet'),
            'desc'     => $activeF === 'archived'
                ? 'Conversations you file away appear here. They stay in the other participant\'s inbox.'
                /* The explanation, not the whole sentence: the title above
                   already says there are no conversations yet, and printing
                   that twice spends the only line that had something to add
                   on repeating the heading. */
                : (($search ?? '') !== '' || $activeF !== 'all' ? null : ($emptyDetail ?? $emptyMessage ?? null)),
            'clearUrl' => $base,
        ]) ?>
    </div>
<?php else: ?>
    <ul class="msg__items">
        <?php foreach ($conversations as $c): ?>
            <?php
            $isActive = (int) $c['id'] === $openId;
            $unread   = (int) ($c['unread_count'] ?? 0);
            $name     = (string) ($c['other_user_name'] ?? 'Former user');

            /* The context line. Only what the conversation itself carries —
               the access layer has already refused any conversation whose
               context this user may not see, so there is nothing to filter
               here, but nothing extra is fetched either. */
            $context = null;
            if (!empty($c['request_code'])) {
                $context = ['bi-wrench-adjustable', 'Maintenance', trim(($c['issue_type'] ?: 'Request') . ' · ' . $c['request_code'], ' ·')];
            } elseif (!empty($c['lease_code'])) {
                $context = ['bi-file-earmark-text', 'Rental', (string) $c['lease_code']];
            } elseif (!empty($c['property_code'])) {
                $context = ['bi-building', 'Property', trim(($c['property_code'] ?: '') . ' · ' . ($c['property_title'] ?: ''), ' ·')];
            }

            /* Preview. A deleted message keeps its place in the thread but
               must not keep its words anywhere, including here. */
            $preview = '';
            if (!empty($c['last_message_deleted_at'])) {
                $preview = 'Message deleted';
            } elseif (($c['last_message_body'] ?? '') !== '') {
                $mine    = (int) ($c['last_message_sender_id'] ?? 0) === (int) $_SESSION['user_id'];
                $preview = ($mine ? 'You: ' : '') . truncate((string) $c['last_message_body'], 70);
            }
            ?>
            <li class="msg__item<?= $isActive ? ' is-active' : '' ?><?= $unread > 0 ? ' is-unread' : '' ?>">
                <a class="msg__item-link"
                   href="<?= sanitize($base . '&action=show&id=' . (int) $c['id'] . $listSuffix) ?>"
                   <?= $isActive ? 'aria-current="true"' : '' ?>>

                    <?php
                    /* Presence on the row, from the same honest rule the chat
                       header uses — a dot only for a request inside the last
                       couple of minutes, never for "has an account". */
                    $rowPresence = communicationPresence($c['other_last_seen'] ?? null);
                    ?>
                    <span class="msg__item-figure<?= $rowPresence['online'] ? ' is-online' : '' ?>">
                        <?= uiAvatar($name, $c['other_user_avatar'] ?? null, 'lg') ?>
                        <?php if ($rowPresence['online']): ?>
                            <span class="msg__dot msg__dot--sm" title="<?= sanitize($rowPresence['title']) ?>"></span>
                        <?php endif; ?>
                    </span>

                    <span class="msg__item-body">
                        <?php /* Row one: who, and when. The two things the eye
                                 needs to decide whether to stop scanning. */ ?>
                        <span class="msg__item-top">
                            <span class="msg__item-name"><?= sanitize($name) ?></span>
                            <?php if ($rowPresence['online']): ?>
                                <span class="msg__item-live">Online</span>
                            <?php endif; ?>
                            <?php if (!empty($c['last_message_at'])): ?>
                                <time class="msg__item-time"
                                      datetime="<?= sanitize(date('c', strtotime((string) $c['last_message_at']))) ?>"
                                      title="<?= sanitize(formatDateTime($c['last_message_at'])) ?>"><?=
                                    sanitize(uiChatTime($c['last_message_at']))
                                ?></time>
                            <?php endif; ?>
                        </span>

                        <?php /* Row two: what was said, and the unread count.
                                 The preview carries the weight of the row, so
                                 the role moved down to the context line —
                                 three lines of metadata above a preview is a
                                 directory entry, not a conversation. */ ?>
                        <span class="msg__item-line">
                            <?php if ($preview !== ''): ?>
                                <span class="msg__item-preview"><?= sanitize($preview) ?></span>
                            <?php else: ?>
                                <span class="msg__item-preview msg__item-preview--none">No messages yet</span>
                            <?php endif; ?>

                            <?php /* Never colour alone: an unread row also
                                     carries a heavier name, a darker preview
                                     and a counted badge with a spoken label. */ ?>
                            <?php if ($unread > 0): ?>
                                <span class="msg__item-badge">
                                    <span aria-hidden="true"><?= $unread > 9 ? '9+' : $unread ?></span>
                                    <span class="sr-only"><?= $unread ?> unread message<?= $unread === 1 ? '' : 's' ?></span>
                                </span>
                            <?php endif; ?>
                        </span>

                        <?php /* Row three: role, and the record this is about.
                                 Secondary by design — it answers "which
                                 conversation is this" once the first two lines
                                 have already caught the eye. */ ?>
                        <span class="msg__item-meta">
                            <span class="msg__item-role">
                                <?= sanitize($c['other_user_role_label'] ?? uiLabel((string) ($c['other_user_role'] ?? ''))) ?>
                            </span>
                            <?php if ($context): ?>
                                <span class="msg__item-dot" aria-hidden="true">·</span>
                                <span class="msg__item-context">
                                    <i class="bi <?= $context[0] ?>" aria-hidden="true"></i>
                                    <span class="sr-only"><?= sanitize($context[1]) ?>:</span>
                                    <?= sanitize($context[2]) ?>
                                </span>
                            <?php endif; ?>
                        </span>
                    </span>
                </a>
            </li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>

<?php if ($totalPages > 1): ?>
    <div class="msg__list-foot">
        <?php
        $pageLink = static function (int $n) use ($activeF, $search): string {
            return APP_URL . '/index.php?' . http_build_query(array_filter([
                'page'   => 'messages',
                'filter' => $activeF !== 'all' ? $activeF : null,
                'search' => ($search ?? '') !== '' ? $search : null,
                'p'      => $n > 1 ? $n : null,
            ]));
        };
        ?>
        <?php if ($page > 1): ?>
            <a class="btn btn--outline btn--sm" href="<?= sanitize($pageLink($page - 1)) ?>">
                <i class="bi bi-chevron-left" aria-hidden="true"></i> Newer
            </a>
        <?php endif; ?>

        <span class="msg__list-count">Page <?= (int) $page ?> of <?= $totalPages ?></span>

        <?php if ($page < $totalPages): ?>
            <a class="btn btn--outline btn--sm" href="<?= sanitize($pageLink($page + 1)) ?>">
                Older <i class="bi bi-chevron-right" aria-hidden="true"></i>
            </a>
        <?php endif; ?>
    </div>
<?php endif; ?>
