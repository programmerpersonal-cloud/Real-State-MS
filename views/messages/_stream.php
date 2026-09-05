<?php
/**
 * The message history itself — everything inside <div id="msgStream">.
 *
 * Split out of _thread.php so there is exactly one renderer for a bubble.
 * The live updater (CommunicationController::poll()) re-renders this file and
 * nothing else, so a message that arrives without a page reload is built by
 * the same code, with the same escaping and the same grouping rules, as one
 * that arrives with it. Two renderers would drift, and the one nobody looks
 * at would be the one that drifts into an XSS.
 *
 * The wrapper element stays in _thread.php: it is the scroll container, and
 * replacing it would throw away the reader's position on every update.
 *
 * Expects: $conversation $counterpart $thread $earlierUrl $canSend $draft
 *          $base $listSuffix $theirWatermark
 *
 * The four locals below are recomputed rather than inherited, so this file
 * renders correctly whether it is required from _thread.php or on its own.
 */
$me             = (int) ($_SESSION['user_id'] ?? 0);
$conversationId = (int) $conversation['id'];
$name           = (string) ($counterpart['full_name'] ?? 'Former user');
$editId         = (int) ($_GET['edit'] ?? 0);
?>
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
