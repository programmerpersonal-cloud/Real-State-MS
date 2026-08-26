<?php
/**
 * One conversation: header, business context, message history, composer.
 *
 * Deliberately not a chat app. Messages are set as correspondence — a named
 * author, a timestamp, and a readable block of text — rather than as coloured
 * speech bubbles, because these threads are about tenancies, repairs and
 * money and will be read back months later.
 *
 * Sent and received are distinguished three ways, not one: the author's name,
 * the alignment, and the surface. Colour is never the only difference, so the
 * thread survives being read in greyscale or by someone who cannot
 * distinguish the two tints.
 *
 * Expects: $conversation $participants $counterpart $thread $earlierUrl
 *          $canSend $isArchived $contextBlocks $draft $base $listSuffix
 */
$me   = (int) ($_SESSION['user_id'] ?? 0);
$name = (string) ($counterpart['full_name'] ?? 'Former user');
$conversationId = (int) $conversation['id'];
?>
<header class="msg__head">
    <?php /* Mobile only. On a narrow screen this panel is the whole page, so
             it needs a way back to the list — and it is a real link to a real
             URL, which is why the browser's Back button agrees with it. */ ?>
    <a class="msg__back" href="<?= sanitize($base . $listSuffix) ?>">
        <i class="bi bi-arrow-left" aria-hidden="true"></i>
        <span>Conversations</span>
    </a>

    <div class="msg__head-who">
        <?= uiAvatar($name, $counterpart['avatar'] ?? null, 'lg') ?>
        <div class="msg__head-text">
            <h2 class="msg__head-name"><?= sanitize($name) ?></h2>
            <p class="msg__head-role">
                <?= sanitize($counterpart['role_label'] ?? uiLabel((string) ($counterpart['role'] ?? ''))) ?>
                <?php if (isset($counterpart['user_is_active']) && !$counterpart['user_is_active']): ?>
                    <span class="msg__head-flag">· account inactive</span>
                <?php endif; ?>
            </p>
        </div>
    </div>

    <div class="msg__head-actions">
        <?php if (can('messages.archive')): ?>
            <form method="post"
                  action="<?= APP_URL ?>/index.php?page=messages&amp;action=<?= $isArchived ? 'unarchive' : 'archive' ?>">
                <?= csrfField() ?>
                <input type="hidden" name="conversation_id" value="<?= $conversationId ?>">
                <button class="btn btn--outline btn--sm" type="submit">
                    <i class="bi <?= $isArchived ? 'bi-arrow-counterclockwise' : 'bi-archive' ?>" aria-hidden="true"></i>
                    <?= $isArchived ? 'Unarchive' : 'Archive' ?>
                </button>
            </form>
        <?php endif; ?>
    </div>
</header>

<?php /* What the conversation is about, read live from the current records.
         A link is offered only where the destination will actually open —
         both the module permission and the record scope — because an offer
         of a 403 is worse than no offer. */ ?>
<?php require __DIR__ . '/_context_card.php'; ?>

<?php if (($conversation['status'] ?? '') === 'closed'): ?>
    <p class="msg__closed-note">
        <i class="bi bi-lock" aria-hidden="true"></i>
        This conversation is closed. The history stays available to read.
    </p>
<?php endif; ?>

<div class="msg__stream" id="msgStream" tabindex="0" role="log" aria-label="Message history">
    <?php if (empty($thread)): ?>
        <div class="msg__stream-empty">
            <?= uiEmptyState([
                'icon'  => 'bi-chat-square-dots',
                'title' => 'Start the conversation',
                'desc'  => 'Send a message to begin. ' . sanitize($name) . ' will see it in their inbox.',
            ]) ?>
        </div>
    <?php else: ?>

        <?php if (!empty($earlierUrl)): ?>
            <?php /* URL-driven pagination, not AJAX. Every one of these
                     requests re-runs the access check, because
                     ConversationMessage::forConversation() asks it itself. */ ?>
            <p class="msg__earlier">
                <a class="btn btn--outline btn--sm" href="<?= sanitize($earlierUrl) ?>">
                    <i class="bi bi-arrow-up" aria-hidden="true"></i> Load earlier messages
                </a>
            </p>
        <?php endif; ?>

        <ol class="msg__list-messages">
            <?php $lastDay = null; ?>
            <?php foreach ($thread as $m): ?>
                <?php
                $sentAt = strtotime((string) $m['created_at']);
                $day    = date('Y-m-d', $sentAt);
                $mine   = (int) ($m['sender_id'] ?? 0) === $me;
                $author = $mine ? 'You' : (string) ($m['sender_name'] ?? 'Former user');
                $gone   = !empty($m['deleted_at']);

                $dayLabel = match ($day) {
                    date('Y-m-d')                       => 'Today',
                    date('Y-m-d', strtotime('-1 day'))  => 'Yesterday',
                    default                             => date('l, j M Y', $sentAt),
                };
                ?>

                <?php if ($day !== $lastDay): ?>
                    <li class="msg__day"><span><?= sanitize($dayLabel) ?></span></li>
                    <?php $lastDay = $day; ?>
                <?php endif; ?>

                <li class="msg__msg<?= $mine ? ' msg__msg--mine' : '' ?><?= $gone ? ' msg__msg--gone' : '' ?>">
                    <div class="msg__msg-inner">
                        <p class="msg__msg-meta">
                            <span class="msg__msg-author"><?= sanitize($author) ?></span>
                            <time datetime="<?= sanitize(date('c', $sentAt)) ?>"
                                  title="<?= sanitize(formatDateTime($m['created_at'])) ?>"><?=
                                sanitize(date('H:i', $sentAt))
                            ?></time>
                            <?php if (!empty($m['edited_at'])): ?>
                                <span class="msg__msg-edited">edited</span>
                            <?php endif; ?>
                        </p>

                        <div class="msg__msg-body">
                            <?php if ($gone): ?>
                                <em>This message was deleted.</em>
                            <?php else: ?>
                                <?php /* Escaped first, then newlines turned into
                                         breaks — never the other way round, which
                                         would let markup through. */ ?>
                                <?= nl2br(sanitize((string) $m['body'])) ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </li>
            <?php endforeach; ?>
        </ol>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/_composer.php'; ?>
