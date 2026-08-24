<?php
/**
 * The message composer.
 *
 * A plain form that POSTs and redirects, like every other write in this
 * application. assets/js/messages.js adds Enter-to-send on top, but the form
 * works completely without it — the Send button is a real submit button, and
 * turning JavaScript off costs the keyboard shortcut and nothing else.
 *
 * The conversation id in the hidden field is a hint about where the user
 * thinks they are, not a grant: canSendToConversation() decides, in the
 * controller and again inside ConversationMessage::create().
 *
 * Expects: $conversation $canSend $isArchived $draft $counterpart
 */
$conversationId = (int) $conversation['id'];
$closed         = ($conversation['status'] ?? '') === 'closed';
$name           = (string) ($counterpart['full_name'] ?? 'this person');
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
    <form class="msg__composer" method="post"
          action="<?= APP_URL ?>/index.php?page=messages&amp;action=send"
          data-msg-composer>
        <?= csrfField() ?>
        <input type="hidden" name="conversation_id" value="<?= $conversationId ?>">

        <label class="sr-only" for="msgBody">Message to <?= sanitize($name) ?></label>
        <textarea class="form-control msg__textarea"
                  id="msgBody"
                  name="body"
                  rows="2"
                  maxlength="<?= ConversationMessage::MAX_LENGTH ?>"
                  placeholder="Write a message…"
                  aria-describedby="msgHint"><?= sanitize($draft ?? '') ?></textarea>

        <button class="btn btn--primary msg__send" type="submit">
            <i class="bi bi-send" aria-hidden="true"></i>
            <span>Send</span>
        </button>

        <?php /* The hint is persistent rather than a placeholder, because a
                 placeholder disappears the moment it becomes relevant. */ ?>
        <p class="msg__hint" id="msgHint">
            <span class="msg__hint-keys" data-msg-hint hidden>
                <kbd>Enter</kbd> to send · <kbd>Shift</kbd>+<kbd>Enter</kbd> for a new line ·
            </span>
            Up to <?= number_format(ConversationMessage::MAX_LENGTH) ?> characters.
        </p>
    </form>
<?php endif; ?>
