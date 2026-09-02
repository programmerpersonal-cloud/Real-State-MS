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

        <?php
        /* ── Grouping, decided here and nowhere else ────────────────────
           Consecutive messages from one person become a run: the avatar and
           the name are drawn once at the top, the timestamp once at the
           bottom, and the bubbles in between tuck together. It is what makes
           a thread read as conversation rather than as a list of records.

           A run ends when the sender changes, when the day changes, or after
           a five-minute gap — the last because two messages an hour apart are
           two thoughts, however close together they sit in the table.

           This is presentation only. No message data is altered, and the
           run boundaries are recomputed on every render from created_at. */
        $GAP = 300;   // seconds

        $runs   = [];
        $current = null;
        foreach ($thread as $m) {
            $sentAt = strtotime((string) $m['created_at']);
            $sender = (int) ($m['sender_id'] ?? 0);
            $day    = date('Y-m-d', $sentAt);

            $continues = $current !== null
                && $current['sender'] === $sender
                && $current['day'] === $day
                && ($sentAt - $current['last']) <= $GAP;

            if (!$continues) {
                if ($current !== null) { $runs[] = $current; }
                $current = ['sender' => $sender, 'day' => $day, 'first' => $sentAt,
                            'last' => $sentAt, 'items' => []];
            }

            $current['last']    = $sentAt;
            $current['items'][] = $m;
        }
        if ($current !== null) { $runs[] = $current; }
        ?>

        <ol class="msg__list-messages">
            <?php $lastDay = null; ?>
            <?php foreach ($runs as $run): ?>
                <?php
                $mine    = $run['sender'] === $me;
                $firstIn = $run['items'][0];
                $author  = $mine ? 'You' : (string) ($firstIn['sender_name'] ?? 'Former user');
                $avatar  = $firstIn['sender_avatar'] ?? null;
                $count   = count($run['items']);

                $dayLabel = match ($run['day']) {
                    date('Y-m-d')                      => 'Today',
                    date('Y-m-d', strtotime('-1 day')) => 'Yesterday',
                    default                            => date('j F Y', $run['first']),
                };
                ?>

                <?php if ($run['day'] !== $lastDay): ?>
                    <li class="msg__day"><span><?= sanitize($dayLabel) ?></span></li>
                    <?php $lastDay = $run['day']; ?>
                <?php endif; ?>

                <li class="msg__run<?= $mine ? ' msg__run--mine' : '' ?>">
                    <?php /* The avatar appears once per run, on the incoming
                             side only: on your own messages it would be a
                             picture of yourself repeated down the page. */ ?>
                    <?php if (!$mine): ?>
                        <div class="msg__run-avatar"><?= uiAvatar($author, $avatar, 'sm') ?></div>
                    <?php endif; ?>

                    <div class="msg__run-body">
                        <?php /* Named once. Screen readers get the author on
                                 every bubble through the sr-only line below,
                                 because a run is a visual grouping and does not
                                 exist in the reading order. */ ?>
                        <p class="msg__run-author"><?= sanitize($author) ?></p>

                        <?php foreach ($run['items'] as $i => $m): ?>
                            <?php
                            $sentAt = strtotime((string) $m['created_at']);
                            $gone   = !empty($m['deleted_at']);
                            $pos    = $count === 1 ? 'only' : ($i === 0 ? 'first' : ($i === $count - 1 ? 'last' : 'mid'));
                            ?>
                            <?php
                            $mid       = (int) $m['id'];
                            $mayEdit   = !$gone && $mine && canEditMessage($mid);
                            $mayDelete = !$gone && $mine && canDeleteMessage($mid);

                            /* The editor opens only where an edit would
                               actually be accepted. Without the $mayEdit half,
                               ?edit=<any id on the page> would draw a form over
                               somebody else's message — refused on POST, but a
                               form that exists only to fail is worse than no
                               form at all. */
                            $editing   = $mid === $editId && $mayEdit;
                            ?>

                            <?php if ($editing): ?>
                                <?php /* The editor replaces the bubble in place, so the
                                         message stays where the reader was looking. It is
                                         a plain form: opening it is a link, saving is a
                                         POST, cancelling is a link back. */ ?>
                                <form class="msg__edit" method="post"
                                      action="<?= APP_URL ?>/index.php?page=messages&amp;action=edit">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="message_id" value="<?= $mid ?>">
                                    <label class="sr-only" for="editBody<?= $mid ?>">Edit your message</label>
                                    <textarea class="form-control msg__edit-field" id="editBody<?= $mid ?>"
                                              name="body" rows="3"
                                              maxlength="<?= ConversationMessage::MAX_LENGTH ?>"
                                              autofocus><?= sanitize($draft !== '' ? $draft : (string) $m['body']) ?></textarea>
                                    <div class="msg__edit-actions">
                                        <a class="btn btn--outline btn--sm"
                                           href="<?= sanitize($base . '&action=show&id=' . $conversationId . $listSuffix) ?>">Cancel</a>
                                        <button class="btn btn--primary btn--sm" type="submit">Save changes</button>
                                    </div>
                                </form>
                            <?php else: ?>

                            <div class="msg__msg msg__msg--<?= $pos ?><?= $gone ? ' msg__msg--gone' : '' ?>"
                                 id="m<?= $mid ?>"
                                 data-msg="<?= $mid ?>"
                                 data-mine="<?= $mine ? '1' : '0' ?>">
                                <span class="sr-only"><?= sanitize($author) ?>, <?= sanitize(formatDateTime($m['created_at'])) ?>:</span>

                                <?php /* What this message answers. Rendered from the row's
                                         own join, so a quote can only ever show a message
                                         from this conversation. A withdrawn original says
                                         so rather than showing a blank. */ ?>
                                <?php if (!$gone && !empty($m['reply_to_message_id'])): ?>
                                    <a class="msg__quote" href="#m<?= (int) $m['reply_to_message_id'] ?>">
                                        <span class="msg__quote-who">
                                            <?= sanitize($m['reply_to_sender_name'] ?? 'Former user') ?>
                                        </span>
                                        <span class="msg__quote-text">
                                            <?php if (!empty($m['reply_to_deleted_at'])): ?>
                                                <em>Original message deleted</em>
                                            <?php elseif (trim((string) ($m['reply_to_body'] ?? '')) !== ''): ?>
                                                <?= sanitize(truncate((string) $m['reply_to_body'], 90)) ?>
                                            <?php elseif ((int) ($m['reply_to_attachments'] ?? 0) > 0): ?>
                                                <em>Attachment</em>
                                            <?php else: ?>
                                                <em>Message</em>
                                            <?php endif; ?>
                                        </span>
                                    </a>
                                <?php endif; ?>

                                <?php if (!$gone): ?>
                                    <?php /* The action affordance. A <details> rather than a
                                             scripted popup, so it opens with a click, with
                                             Enter, and with no JavaScript at all — the script
                                             only adds right-click and long-press as ways to
                                             reach the same menu. Every item inside is a link
                                             or a POST form; the server re-decides all of it. */ ?>
                                    <details class="msg__acts" data-msg-menu>
                                        <summary class="msg__tool" title="Message actions">
                                            <i class="bi bi-three-dots" aria-hidden="true"></i>
                                            <span class="sr-only">Actions for the message sent at <?= sanitize(date('H:i', $sentAt)) ?></span>
                                        </summary>

                                        <div class="msg__menu" role="menu">
                                            <?php if (!empty($quickReactions)): ?>
                                                <div class="msg__menu-react">
                                                    <?php foreach ($quickReactions as $emoji): ?>
                                                        <form method="post"
                                                              action="<?= APP_URL ?>/index.php?page=messages&amp;action=react">
                                                            <?= csrfField() ?>
                                                            <input type="hidden" name="message_id" value="<?= $mid ?>">
                                                            <input type="hidden" name="emoji" value="<?= sanitize($emoji) ?>">
                                                            <button class="msg__react-btn" type="submit"
                                                                    title="React with <?= sanitize($emoji) ?>">
                                                                <span aria-hidden="true"><?= sanitize($emoji) ?></span>
                                                                <span class="sr-only">React with <?= sanitize($emoji) ?></span>
                                                            </button>
                                                        </form>
                                                    <?php endforeach; ?>
                                                </div>
                                            <?php endif; ?>

                                            <a class="msg__menu-item" role="menuitem"
                                               href="<?= sanitize($base . '&action=show&id=' . $conversationId . '&reply=' . $mid . $listSuffix) ?>#msgBody">
                                                <i class="bi bi-reply" aria-hidden="true"></i> Reply
                                            </a>

                                            <?php if ($mayEdit): ?>
                                                <a class="msg__menu-item" role="menuitem"
                                                   href="<?= sanitize($base . '&action=show&id=' . $conversationId . '&edit=' . $mid . $listSuffix) ?>">
                                                    <i class="bi bi-pencil" aria-hidden="true"></i> Edit
                                                </a>
                                            <?php endif; ?>

                                            <?php if ($mayDelete): ?>
                                                <form method="post"
                                                      action="<?= APP_URL ?>/index.php?page=messages&amp;action=delete">
                                                    <?= csrfField() ?>
                                                    <input type="hidden" name="message_id" value="<?= $mid ?>">
                                                    <button class="msg__menu-item msg__menu-item--danger" type="submit"
                                                            role="menuitem"
                                                            <?= uiConfirmAttrs([
                                                                'title'  => 'Delete this message?',
                                                                'body'   => 'It is withdrawn from the conversation. '
                                                                          . sanitize($name) . ' will see that a message was deleted, '
                                                                          . 'and the original text is kept in the audit log.',
                                                                'action' => 'Delete message',
                                                                'tone'   => 'danger',
                                                            ]) ?>>
                                                        <i class="bi bi-trash3" aria-hidden="true"></i> Delete
                                                    </button>
                                                </form>
                                            <?php endif; ?>
                                        </div>
                                    </details>
                                <?php endif; ?>

                        <?php if ($gone): ?>
                            <div class="msg__msg-body"><em>This message was deleted.</em></div>
                        <?php else: ?>
                            <?php if (trim((string) $m['body']) !== ''): ?>
                                <div class="msg__msg-body">
                                    <?php /* Escaped first, then newlines turned into
                                             breaks — never the other way round, which
                                             would let markup through. */ ?>
                                    <?= nl2br(sanitize((string) $m['body'])) ?>
                                </div>
                            <?php endif; ?>

                            <?php /* An attachment-only message is a real message:
                                     a photograph of a broken pipe says everything
                                     it needs to, and the body block is simply
                                     absent rather than empty. */ ?>
                            <?php if (!empty($m['attachments'])): ?>
                                <ul class="msg__files">
                                    <?php foreach ($m['attachments'] as $a): ?>
                                        <?php
                                        $aid   = (int) $a['id'];
                                        $aname = (string) $a['original_name'];
                                        $amime = (string) $a['mime_type'];
                                        $image = MessageAttachment::isInlineImage($amime);
                                        ?>
                                        <?php $voice = MessageAttachment::isVoice($amime); ?>
                                        <li class="msg__file<?= $image ? ' msg__file--image' : '' ?><?= $voice ? ' msg__file--voice' : '' ?>">
                                            <?php if ($voice): ?>
                                                <?php /* The browser's own player, pointed at the
                                                         authorising endpoint — every seek and
                                                         replay re-runs the same conversation
                                                         check the thread did. `preload="none"`
                                                         so opening a thread does not fetch every
                                                         recording in it. */ ?>
                                                <?php /* The custom transport is progressive enhancement
                                                         over the browser's own player, never a replacement
                                                         for it: the <audio> ships with `controls`, and the
                                                         script removes them only once it has taken over.
                                                         With JavaScript off, this is exactly the player it
                                                         was before. */ ?>
                                                <div class="msg__voice" data-msg-voice>
                                                    <audio class="msg__voice-player" controls preload="metadata"
                                                           data-msg-voice-audio
                                                           src="<?= sanitize(MessageAttachment::url($aid, true)) ?>">
                                                        <a href="<?= sanitize(MessageAttachment::url($aid)) ?>">Download the voice note</a>
                                                    </audio>

                                                    <?php /* Hidden until the script proves it can drive the
                                                             audio element, so a broken enhancement can never
                                                             leave a dead play button on the page. */ ?>
                                                    <div class="msg__voice-ui" data-msg-voice-ui hidden>
                                                        <button class="msg__voice-play" type="button"
                                                                data-msg-voice-play aria-pressed="false">
                                                            <i class="bi bi-play-fill" aria-hidden="true"></i>
                                                            <span class="sr-only">Play the voice note</span>
                                                        </button>

                                                        <?php /* A seek bar, not decoration: the bars are the
                                                                 visual, the range input underneath is the
                                                                 control, and it is a real input so it works
                                                                 with a keyboard and reads as a slider. */ ?>
                                                        <span class="msg__wave" aria-hidden="true">
                                                            <?php
                                                            /* Deterministic heights from the attachment id —
                                                               the same note draws the same shape on every
                                                               render. It is a waveform-styled scrubber, not a
                                                               decode of the audio, and it is aria-hidden so it
                                                               never claims to be one. */
                                                            mt_srand($aid);
                                                            for ($b = 0; $b < 28; $b++) {
                                                                $h = 22 + (($aid * ($b + 7)) % 62);
                                                                echo '<i style="--h:' . $h . '%"></i>';
                                                            }
                                                            ?>
                                                        </span>

                                                        <label class="sr-only" for="seek<?= $aid ?>">Seek within the voice note</label>
                                                        <input class="msg__voice-seek" type="range" id="seek<?= $aid ?>"
                                                               min="0" max="100" step="0.5" value="0"
                                                               data-msg-voice-seek>

                                                        <span class="msg__voice-time">
                                                            <span data-msg-voice-now>0:00</span><span
                                                                aria-hidden="true"> / </span><span data-msg-voice-total>--:--</span>
                                                        </span>

                                                        <i class="bi bi-mic-fill msg__voice-icon" aria-hidden="true"></i>
                                                    </div>
                                                </div>
                                            <?php elseif ($image): ?>
                                                <?php /* The <img> points at the delivery
                                                         endpoint, never at the store. Every
                                                         request for it re-runs the same live
                                                         conversation check the thread did. */ ?>
                                                <a class="msg__file-shot"
                                                   href="<?= sanitize(MessageAttachment::url($aid, true)) ?>"
                                                   target="_blank" rel="noopener noreferrer">
                                                    <img src="<?= sanitize(MessageAttachment::url($aid, true)) ?>"
                                                         alt="<?= sanitize($aname) ?>"
                                                         loading="lazy" decoding="async">
                                                </a>
                                                <a class="msg__file-name"
                                                   href="<?= sanitize(MessageAttachment::url($aid)) ?>">
                                                    <?= sanitize($aname) ?>
                                                    <span class="msg__file-size"><?= formatBytes((int) $a['file_size']) ?></span>
                                                </a>
                                            <?php else: ?>
                                                <?php /* Documents get a card and a download,
                                                         never an inline frame: nothing stored
                                                         here is handed to the page as
                                                         renderable markup. */ ?>
                                                <a class="msg__file-card"
                                                   href="<?= sanitize(MessageAttachment::url($aid)) ?>">
                                                    <i class="bi <?= sanitize(fileTypeIcon($amime)) ?> msg__file-icon"
                                                       aria-hidden="true"></i>
                                                    <span class="msg__file-text">
                                                        <span class="msg__file-label"><?= sanitize($aname) ?></span>
                                                        <span class="msg__file-meta">
                                                            <?= sanitize(fileTypeLabel($amime)) ?> ·
                                                            <?= formatBytes((int) $a['file_size']) ?>
                                                        </span>
                                                    </span>
                                                    <i class="bi bi-download" aria-hidden="true"></i>
                                                    <span class="sr-only">Download</span>
                                                </a>
                                            <?php endif; ?>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>

                            <?php /* Reactions. Real stored rows, aggregated — the count is
                                     how many people used that emoji, and `mine` is what
                                     renders the chip as pressed. Pressing it again removes
                                     it, because the control is one toggle rather than an
                                     add and a remove. */ ?>
                            <?php if (!empty($m['reactions'])): ?>
                                <ul class="msg__reactions">
                                    <?php foreach ($m['reactions'] as $r): ?>
                                        <li>
                                            <form method="post"
                                                  action="<?= APP_URL ?>/index.php?page=messages&amp;action=react">
                                                <?= csrfField() ?>
                                                <input type="hidden" name="message_id" value="<?= $mid ?>">
                                                <input type="hidden" name="emoji" value="<?= sanitize($r['emoji']) ?>">
                                                <button class="msg__reaction<?= $r['mine'] ? ' is-mine' : '' ?>"
                                                        type="submit"
                                                        aria-pressed="<?= $r['mine'] ? 'true' : 'false' ?>">
                                                    <span aria-hidden="true"><?= sanitize($r['emoji']) ?></span>
                                                    <span class="msg__reaction-n" aria-hidden="true"><?= (int) $r['count'] ?></span>
                                                    <span class="sr-only">
                                                        <?= (int) $r['count'] ?> reacted with <?= sanitize($r['emoji']) ?>.
                                                        <?= $r['mine'] ? 'Press to remove yours.' : 'Press to add yours.' ?>
                                                    </span>
                                                </button>
                                            </form>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                        <?php endif; ?>
                            </div>
                            <?php endif; /* editing */ ?>
                        <?php endforeach; ?>

                        <?php /* One timestamp per run, at its foot — the time
                                 the last thing was said. Every individual
                                 message still carries its own full date and
                                 time in the sr-only line above it, so nothing
                                 is lost to the grouping. */ ?>
                        <p class="msg__run-time">
                            <time datetime="<?= sanitize(date('c', $run['last'])) ?>"
                                  title="<?= sanitize(formatDateTime(date('Y-m-d H:i:s', $run['last']))) ?>"><?=
                                sanitize(date('H:i', $run['last']))
                            ?></time>
                            <?php foreach ($run['items'] as $m): ?>
                                <?php if (!empty($m['edited_at'])): ?>
                                    <span class="msg__msg-edited">edited</span>
                                    <?php break; ?>
                                <?php endif; ?>
                            <?php endforeach; ?>

                            <?php
                            /* ── Read receipt ────────────────────────────
                               Two states, and both are things the server
                               actually knows. A stored row means sent; the
                               other participant's read watermark reaching
                               this id means read. There is no "delivered"
                               tick, because nothing here can observe a
                               recipient's device, and a tick that guesses is
                               worse than a tick that is missing.

                               $theirWatermark is the counterpart's
                               conversation_participants.last_read_message_id,
                               so it moves only when they actually open the
                               thread. */
                            if ($mine) {
                                $lastId = (int) end($run['items'])['id'];
                                $seen   = ($theirWatermark ?? 0) >= $lastId;
                                ?>
                                <span class="msg__ticks<?= $seen ? ' is-read' : '' ?>">
                                    <i class="bi <?= $seen ? 'bi-check2-all' : 'bi-check2' ?>" aria-hidden="true"></i>
                                    <span class="sr-only"><?= $seen
                                        ? 'Read by ' . sanitize($name)
                                        : 'Sent. Not read yet.' ?></span>
                                </span>
                                <?php
                            }
                            ?>
                        </p>
                    </div>
                </li>
            <?php endforeach; ?>
        </ol>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/_composer.php'; ?>
