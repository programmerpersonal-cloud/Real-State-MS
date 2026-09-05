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

/* Which message, if any, is open in the inline editor. It arrives in the URL
   — ?edit=<id> — so opening the editor is a link and cancelling is the link
   back, with no JavaScript in the path and a real Back button. The id is only
   ever compared against messages already on the page; canEditMessage() decides
   whether the editor is drawn at all, and edit() re-decides on POST. */
$editId = (int) ($_GET['edit'] ?? 0);
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
        <?php
        /* Presence, and only what the server can honestly support. The dot is
           drawn solely when communicationPresence() says Online — which means
           a request within the last two minutes, not "has a session" and not
           "is an active account". Everything else reads "Last seen …". */
        $presence = communicationPresence($counterpart['last_seen_at'] ?? null);
        ?>
        <span class="msg__head-figure<?= $presence['online'] ? ' is-online' : '' ?>">
            <?= uiAvatar($name, $counterpart['avatar'] ?? null, 'xl') ?>
            <?php if ($presence['online']): ?>
                <span class="msg__dot" title="<?= sanitize($presence['title']) ?>"></span>
            <?php endif; ?>
        </span>

        <div class="msg__head-text">
            <h2 class="msg__head-name"><?= sanitize($name) ?></h2>

            <?php /* Presence carries this line, the way a messenger header
                     reads — the role follows it as the quieter half, because
                     "Online" is what the eye is looking for and "Property
                     owner" is what it needs once it has stopped. */ ?>
            <p class="msg__head-role">
                <?php if ($presence['label'] !== ''): ?>
                    <span class="msg__presence<?= $presence['online'] ? ' is-online' : '' ?>"
                          title="<?= sanitize($presence['title']) ?>"><?= sanitize($presence['label']) ?></span>
                    <span class="msg__head-sep" aria-hidden="true">·</span>
                <?php endif; ?>
                <?= sanitize($counterpart['role_label'] ?? uiLabel((string) ($counterpart['role'] ?? ''))) ?>
                <?php if (isset($counterpart['user_is_active']) && !$counterpart['user_is_active']): ?>
                    <span class="msg__head-flag">· account inactive</span>
                <?php endif; ?>
            </p>
        </div>
    </div>

    <div class="msg__head-actions">
        <?php /* In-conversation search. A GET form, so a search is a URL that
                 can be shared and reached with Back — and it never leaves this
                 conversation, because the id travels with it. */ ?>
        <details class="msg__find"<?= ($findTerm ?? '') !== '' ? ' open' : '' ?>>
            <summary class="msg__head-btn" title="Search this conversation">
                <i class="bi bi-search" aria-hidden="true"></i>
                <span class="sr-only">Search this conversation</span>
            </summary>
            <form class="msg__find-form" method="get" action="<?= APP_URL ?>/index.php" role="search">
                <input type="hidden" name="page" value="messages">
                <input type="hidden" name="action" value="show">
                <input type="hidden" name="id" value="<?= $conversationId ?>">
                <label class="sr-only" for="msgFind">Search messages in this conversation</label>
                <input class="form-control" type="search" id="msgFind" name="find"
                       value="<?= sanitize($findTerm ?? '') ?>" placeholder="Search this conversation">
                <button class="btn btn--primary btn--sm" type="submit">Find</button>
                <?php if (($findTerm ?? '') !== ''): ?>
                    <a class="btn btn--outline btn--sm"
                       href="<?= sanitize($base . '&action=show&id=' . $conversationId) ?>">Clear</a>
                <?php endif; ?>
            </form>
        </details>

        <?php
        /* Call is a real telephone link or it is not drawn. The reference
           header carries a call and a video button; only one of them can be
           honest here — the number is a stored column, and nothing in this
           system places a video call, so the video button is absent rather
           than present and dead. */
        $phone = trim((string) ($counterpart['phone'] ?? ''));
        ?>
        <?php if ($phone !== ''): ?>
            <a class="msg__head-btn" href="tel:<?= sanitize(preg_replace('/[^0-9+]/', '', $phone)) ?>"
               title="Call <?= sanitize($name) ?> on <?= sanitize($phone) ?>">
                <i class="bi bi-telephone" aria-hidden="true"></i>
                <span class="sr-only">Call <?= sanitize($name) ?> on <?= sanitize($phone) ?></span>
            </a>
        <?php endif; ?>

        <?php /* Everything else folds into one overflow, so the header holds
                 three controls instead of a row of them. Same <details>
                 pattern as the inbox: click or Enter, no script required. */ ?>
        <details class="msg__more msg__more--head" data-msg-more>
            <summary class="msg__head-btn" title="Conversation options">
                <i class="bi bi-three-dots-vertical" aria-hidden="true"></i>
                <span class="sr-only">Conversation options</span>
            </summary>

            <div class="msg__more-menu" role="menu">
                <?php if (can('messages.archive')): ?>
                    <form method="post"
                          action="<?= APP_URL ?>/index.php?page=messages&amp;action=<?= $isArchived ? 'unarchive' : 'archive' ?>">
                        <?= csrfField() ?>
                        <input type="hidden" name="conversation_id" value="<?= $conversationId ?>">
                        <button class="msg__more-item" type="submit" role="menuitem">
                            <i class="bi <?= $isArchived ? 'bi-arrow-counterclockwise' : 'bi-archive' ?>" aria-hidden="true"></i>
                            <?= $isArchived ? 'Move back to the inbox' : 'Archive for you only' ?>
                        </button>
                    </form>
                <?php endif; ?>

                <?php if ($phone !== ''): ?>
                    <a class="msg__more-item" role="menuitem"
                       href="tel:<?= sanitize(preg_replace('/[^0-9+]/', '', $phone)) ?>">
                        <i class="bi bi-telephone" aria-hidden="true"></i> <?= sanitize($phone) ?>
                    </a>
                <?php endif; ?>

                <?php if (!empty($counterpart['email'])): ?>
                    <a class="msg__more-item" role="menuitem"
                       href="mailto:<?= sanitize((string) $counterpart['email']) ?>">
                        <i class="bi bi-envelope" aria-hidden="true"></i> <?= sanitize((string) $counterpart['email']) ?>
                    </a>
                <?php endif; ?>

                <a class="msg__more-item" role="menuitem" href="<?= sanitize($base . $listSuffix) ?>">
                    <i class="bi bi-inbox" aria-hidden="true"></i> Back to all conversations
                </a>
            </div>
        </details>
    </div>
</header>

<?php if ($isArchived): ?>
    <?php /* Archived is a state, not a disabled control: the thread reads and
             sends exactly as before. Said plainly, because the commonest
             worry about an archive button is that it deleted something. */ ?>
    <p class="msg__archived-note">
        <i class="bi bi-archive" aria-hidden="true"></i>
        <span>You archived this conversation. It is hidden from your inbox only —
        the other participant still sees it, and nothing has been deleted.</span>
    </p>
<?php endif; ?>

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
    <?php /* The history itself lives in _stream.php, because the live updater
             re-renders exactly this and nothing else. The wrapper stays here:
             it is the scroll container, and replacing it would throw away the
             reader's position on every update. */ ?>
    <?php require __DIR__ . '/_stream.php'; ?>
</div>

<?php require __DIR__ . '/_composer.php'; ?>
