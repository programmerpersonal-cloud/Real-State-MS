<?php
/**
 * The message composer.
 *
 * A plain form that POSTs and redirects, like every other write in this
 * application. Everything JavaScript adds here — the attachment menu, the
 * emoji picker, recording, auto-growing, Enter-to-send — sits on top of a form
 * that works without any of it:
 *
 *   attach   a real <input type="file">, reachable through its own label
 *   emoji    a <details> disclosure; without script it simply lists nothing
 *   voice    hidden until the browser proves it can record
 *   reply    a hidden field set from the URL, cancelled by a link
 *   send     a real submit button
 *
 * The conversation id in the hidden field is a hint about where the user
 * thinks they are, not a grant: canSendToConversation() decides, in the
 * controller and again inside ConversationMessage::create().
 *
 * Expects: $conversation $canSend $isArchived $draft $counterpart
 * Optional: $replyTo $base $listSuffix
 */
$conversationId = (int) $conversation['id'];
$closed         = ($conversation['status'] ?? '') === 'closed';
$name           = (string) ($counterpart['full_name'] ?? 'this person');
$reply          = $replyTo ?? null;
?>

<?php if (!$canSend): ?>
    <?php /* Read access without send access is a real state, not an error, and
             it has two ordinary causes worth telling apart. */ ?>
    <div class="msg__composer msg__composer--locked">
        <i class="bi bi-lock" aria-hidden="true"></i>
        <p>
            <?php if ($closed): ?>
                This conversation is closed. The history stays available to read.
            <?php else: ?>
                You can no longer send messages here — the working relationship behind
                this conversation has ended. The history stays available to read.
            <?php endif; ?>
        </p>
    </div>

<?php else: ?>
    <?php /* enctype is what makes the file input work at all — without it the
             browser posts the filename as a string and $_FILES arrives empty. */ ?>
    <form class="msg__composer" method="post" enctype="multipart/form-data"
          action="<?= APP_URL ?>/index.php?page=messages&amp;action=send"
          data-msg-composer>
        <?= csrfField() ?>
        <input type="hidden" name="conversation_id" value="<?= $conversationId ?>">

        <?php if ($reply): ?>
            <?php /* Replying to. The id travels as a hidden field and is
                     re-validated on POST — create() refuses a target from
                     another conversation, so a hand-edited value quotes
                     nothing. Cancel is a link back to the plain thread. */ ?>
            <input type="hidden" name="reply_to" value="<?= (int) $reply['id'] ?>">
            <div class="msg__replying">
                <i class="bi bi-reply-fill" aria-hidden="true"></i>
                <div class="msg__replying-text">
                    <span class="msg__replying-who">
                        Replying to <?= $reply['is_mine'] ? 'yourself' : sanitize((string) ($reply['sender_name'] ?? 'Former user')) ?>
                    </span>
                    <span class="msg__replying-body">
                        <?php if (trim((string) $reply['body']) !== ''): ?>
                            <?= sanitize(truncate((string) $reply['body'], 90)) ?>
                        <?php elseif ((int) ($reply['attachments'] ?? 0) > 0): ?>
                            <em>Attachment</em>
                        <?php else: ?>
                            <em>Message</em>
                        <?php endif; ?>
                    </span>
                </div>
                <a class="msg__replying-x"
                   href="<?= sanitize(($base ?? '') . '&action=show&id=' . $conversationId . ($listSuffix ?? '')) ?>"
                   title="Cancel reply">
                    <i class="bi bi-x-lg" aria-hidden="true"></i>
                    <span class="sr-only">Cancel this reply</span>
                </a>
            </div>
        <?php endif; ?>

        <?php /* One rounded surface holding every control, and a <label> that
                 covers the whole middle of it — clicking the empty space beside
                 the placeholder focuses the field, because the label *is* the
                 field's label rather than a decorative box. No script needed
                 for that; it is what `for` has always done. */ ?>
        <?php /* Three things in the bar and no more: attach, the writing area,
                 send. Everything else lives behind the paperclip.

                 This was a three-column grid holding six children, so the
                 extras wrapped onto rows of their own and became a permanent
                 toolbar under the composer — the whole point of a composer
                 being that it is a place to write, not a shelf of buttons. */ ?>
        <div class="msg__bar" data-msg-bar>

            <div class="msg__attach">
                <?php /* One disclosure holding every way of adding something.
                         Camera, Gallery and Document are three real file inputs
                         with their own accept/capture — "Camera" is the camera
                         on a phone and a file dialog on a laptop, which is the
                         honest degradation rather than a broken button.

                         Voice and Emoji join them here rather than sitting in
                         the bar: both are ways of composing a message, and a
                         reader looking for "how do I add something" should find
                         one control, not four. Both ship hidden and are
                         revealed by script only where they can actually work,
                         so a browser without MediaRecorder is never shown a
                         microphone that cannot record. */ ?>
                <details data-msg-attach>
                    <summary class="msg__round" title="Attach a photo, document or voice note">
                        <i class="bi bi-plus-lg" aria-hidden="true"></i>
                        <span class="sr-only">Attach a photo, document or voice note</span>
                    </summary>

                    <div class="msg__attach-menu" role="menu">
                        <label class="msg__attach-opt" role="menuitem">
                            <i class="bi bi-camera" aria-hidden="true"></i>
                            <span>Camera<small>Take a photo</small></span>
                            <input type="file" name="attachments[]" accept="image/*" capture="environment"
                                   data-msg-files>
                        </label>

                        <label class="msg__attach-opt" role="menuitem">
                            <i class="bi bi-images" aria-hidden="true"></i>
                            <span>Gallery<small>JPEG, PNG or WebP</small></span>
                            <input type="file" name="attachments[]" accept="image/jpeg,image/png,image/webp"
                                   multiple data-msg-files>
                        </label>

                        <label class="msg__attach-opt" role="menuitem">
                            <i class="bi bi-file-earmark-text" aria-hidden="true"></i>
                            <span>Document<small>PDF, up to <?= formatBytes(MESSAGE_ATTACHMENT_MAX_SIZE) ?></small></span>
                            <input type="file" name="attachments[]" accept="application/pdf"
                                   multiple data-msg-files>
                        </label>

                        <button class="msg__attach-opt" type="button" role="menuitem"
                                data-msg-record hidden>
                            <i class="bi bi-mic" aria-hidden="true"></i>
                            <span>Voice<small>Record a note</small></span>
                        </button>
                    </div>
                </details>
            </div>

            <?php /* Emoji is its own control beside the +, not an item inside
                     the attachment menu. Inserting a symbol into the sentence
                     you are writing is part of writing; attaching a file is
                     not, and burying the first behind the second put two
                     clicks between the writer and a smiley.

                     The grid is built by script from a curated list — no
                     library, no network request — so the button ships hidden
                     and is revealed only once the grid holds something. */ ?>
            <div class="msg__emoji">
                <button class="msg__round" type="button" title="Insert an emoji"
                        data-msg-emoji-open aria-expanded="false" hidden>
                    <i class="bi bi-emoji-smile" aria-hidden="true"></i>
                    <span class="sr-only">Insert an emoji</span>
                </button>

                <div class="msg__emoji-menu" role="menu" data-msg-emoji-panel hidden>
                    <div class="msg__emoji-grid" data-msg-emoji-grid></div>
                </div>
            </div>

            <label class="msg__field" for="msgBody">
                <span class="sr-only">Message to <?= sanitize($name) ?></span>
                <textarea class="msg__textarea"
                          id="msgBody"
                          name="body"
                          rows="1"
                          maxlength="<?= ConversationMessage::MAX_LENGTH ?>"
                          placeholder="Write a message…"
                          aria-describedby="msgHint"
                          data-msg-grow><?= sanitize($draft ?? '') ?></textarea>
            </label>

            <?php /* The recorded blob is put into this input and the ordinary
                     form carries it — no fetch, no JSON, the same POST as
                     everything else. */ ?>
            <input class="msg__voice-input" type="file" name="voice" hidden data-msg-voice-input>

            <?php /* Both are permanent, and send is always one of them.

                     These used to swap — microphone while the field was
                     empty, send once there was something to send. That is
                     the messenger pattern, and it is genuinely tidier, but
                     it means the send control does not exist until after
                     you have typed, and someone looking for "where do I
                     press to send" before typing simply cannot find it.

                     So send keeps the filled brand circle and is always
                     there. The microphone is the quiet one beside it, and
                     is still revealed only where the browser can actually
                     record — a mic that cannot record is never drawn. */ ?>
            <button class="msg__mic" type="button" title="Record a voice note"
                    data-msg-record data-msg-mic hidden>
                <i class="bi bi-mic-fill" aria-hidden="true"></i>
                <span class="sr-only">Record a voice note</span>
            </button>

            <button class="msg__send" type="submit" title="Send message" data-msg-send>
                <i class="bi bi-send-fill" aria-hidden="true"></i>
                <span class="sr-only">Send message</span>
            </button>
        </div>

        <?php /* The recording bar. Replaces the composer while a recording is
                 running; hidden and inert otherwise. */ ?>
        <div class="msg__rec" data-msg-rec hidden>
            <button class="btn btn--outline btn--sm" type="button" data-msg-rec-cancel>Cancel</button>
            <span class="msg__rec-dot" aria-hidden="true"></span>
            <span class="msg__rec-time" data-msg-rec-time role="timer" aria-live="off">0:00</span>
            <span class="msg__rec-wave" aria-hidden="true"><i></i><i></i><i></i><i></i><i></i><i></i><i></i></span>
            <button class="btn btn--primary btn--sm" type="button" data-msg-rec-stop>Use recording</button>
        </div>

        <ul class="msg__picked" data-msg-picked hidden></ul>

        <p class="msg__hint" id="msgHint">
            <span class="msg__hint-keys" data-msg-hint hidden>
                <kbd>Enter</kbd> to send · <kbd>Shift</kbd>+<kbd>Enter</kbd> for a new line ·
            </span>
            <span id="msgAttachHint">Up to <?= MESSAGE_ATTACHMENT_MAX_COUNT ?> files,
                <?= formatBytes(MESSAGE_ATTACHMENT_MAX_SIZE) ?> each — JPEG, PNG, WebP or PDF.</span>
        </p>
    </form>
<?php endif; ?>
