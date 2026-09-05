<?php
/**
 * The inbox rows themselves — the list and its pager, and nothing above them.
 *
 * Split out of _conversation_list.php for the same reason _stream.php was
 * split out of _thread.php: the live updater re-renders exactly this and
 * swaps it in, so a row whose preview, timestamp or unread badge changes
 * without a page reload is built by the same code as one that arrives with
 * it. The search field, the filters and the overflow menu deliberately stay
 * outside it — replacing them would take the caret out of a search someone
 * was in the middle of typing.
 *
 * Expects: $conversations $totalCount $page $perPage $filter $search $base
 *          $listSuffix $conversation $emptyDetail $emptyMessage
 *
 * The three locals below are recomputed rather than inherited, so this file
 * renders correctly whether it is required from _conversation_list.php or on
 * its own.
 */
$openId     = (int) ($conversation['id'] ?? 0);
$activeF    = $filter ?? 'all';
$totalPages = (int) ceil(($totalCount ?? 0) / max(1, $perPage ?? 20));
?>
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
