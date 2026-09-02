<?php
/**
 * ConversationMessage Model
 *
 * What was said, and who has read how far.
 *
 * Named for the table, and the table is named `conversation_messages` because
 * `messages` was already taken — by the inquiry reply log that
 * Inquiry::addMessage() writes and views/admin/inquiries/show.php renders.
 * That table is a different feature with a different shape and it is left
 * alone.
 *
 * Reads are scoped through includes/communication_access.php. Deletion is
 * soft: `deleted_at` is set, the body is withheld from the renderer, and the
 * row stays so the thread keeps its shape and the audit trail keeps its
 * record.
 */
class ConversationMessage
{
    /**
     * The ceiling on a single message.
     *
     * Long enough for a detailed maintenance note, short enough that the
     * TEXT column and the composer agree about what will fit. Enforced here
     * as well as in the composer, because the composer is advice.
     */
    public const MAX_LENGTH = 5000;

    /** How many messages one page of a thread carries. */
    public const PAGE_SIZE = 30;

    private PDO $db;

    public function __construct()
    {
        $this->db = getDBConnection();
    }

    // ─── Reading ───────────────────────────────────────────────────────

    /**
     * One page of a thread, oldest first.
     *
     * Paginated backwards from the newest message, because that is the end
     * people read: `$beforeId` fetches the page above the one already on
     * screen, which is what "Load earlier messages" asks for. The rows are
     * reversed before returning so the caller always renders top-to-bottom in
     * chronological order regardless of which page it asked for.
     *
     * Refuses outright unless the signed-in user may open the conversation —
     * a message list is exactly the thing an unauthorised `?id=` would be
     * after.
     *
     * Deleted messages are returned with their body replaced rather than
     * omitted: a gap in a thread is confusing, "this message was deleted" is
     * not, and the reply above it still needs something to point at.
     *
     * @return array<int, array<string, mixed>>
     */
    public function forConversation(
        int $conversationId,
        ?int $beforeId = null,
        int $limit = self::PAGE_SIZE,
        string $find = ''
    ): array {
        if (!canAccessConversation($conversationId)) {
            return [];
        }

        $limit  = max(1, min(100, $limit));
        $params = [':cid' => $conversationId];

        $where = 'cm.conversation_id = :cid';
        if ($beforeId !== null && $beforeId > 0) {
            $where .= ' AND cm.id < :before';
            $params[':before'] = $beforeId;
        }

        /* In-conversation search. The conversation id stays in the WHERE
           clause above it, so a search can only ever narrow this thread — it
           has no way to reach another one. Withdrawn messages are excluded:
           their body is not shown, so matching on it would let someone
           confirm what a deleted message said by watching which searches
           return a "message deleted" row. */
        $find = trim($find);
        if ($find !== '') {
            $where .= ' AND cm.deleted_at IS NULL AND cm.body LIKE :find';
            $params[':find'] = '%' . $find . '%';
        }

        $stmt = $this->db->prepare("
            SELECT cm.id, cm.conversation_id, cm.sender_id, cm.message_type,
                   cm.reply_to_message_id, cm.created_at, cm.edited_at, cm.deleted_at,
                   CASE WHEN cm.deleted_at IS NULL THEN cm.body ELSE NULL END AS body,
                   u.full_name AS sender_name, u.avatar AS sender_avatar,
                   r.name AS sender_role,
                   rt.body AS reply_to_body, rt.deleted_at AS reply_to_deleted_at,
                   ru.full_name AS reply_to_sender_name,
                   /* Whether the quoted message carried files, so the preview
                      can name the attachment rather than showing an empty
                      quote for a message that was all photograph. */
                   (SELECT COUNT(*) FROM message_attachments ra
                     WHERE ra.message_id = rt.id) AS reply_to_attachments
            FROM conversation_messages cm
            LEFT JOIN users u ON cm.sender_id = u.id
            LEFT JOIN roles r ON u.role_id = r.id
            LEFT JOIN conversation_messages rt ON cm.reply_to_message_id = rt.id
            LEFT JOIN users ru ON rt.sender_id = ru.id
            WHERE {$where}
            ORDER BY cm.id DESC
            LIMIT {$limit}
        ");
        $stmt->execute($params);
        $rows = array_reverse($stmt->fetchAll());

        // One query for the page's attachments rather than one per message.
        // A soft-deleted message keeps its rows in the table — the audit trail
        // is the point — but is served none of them, for the same reason its
        // body is withheld.
        $visible = [];
        foreach ($rows as $row) {
            if (empty($row['deleted_at'])) {
                $visible[] = (int) $row['id'];
            }
        }

        // Two more queries for the whole page, not two per message. A
        // withdrawn message is served neither its files nor its reactions, for
        // the same reason it is not served its body.
        $files     = $visible ? (new MessageAttachment())->forMessages($visible) : [];
        $reactions = $visible ? (new MessageReaction())->forMessages($visible) : [];

        foreach ($rows as $i => $row) {
            $id = (int) $row['id'];
            $rows[$i]['attachments'] = $files[$id] ?? [];
            $rows[$i]['reactions']   = $reactions[$id] ?? [];
        }

        return $rows;
    }

    /** Are there older messages above the page just fetched? */
    public function hasEarlierThan(int $conversationId, int $messageId): bool
    {
        if (!canAccessConversation($conversationId)) {
            return false;
        }

        $stmt = $this->db->prepare("
            SELECT 1 FROM conversation_messages
            WHERE conversation_id = :cid AND id < :mid LIMIT 1
        ");
        $stmt->execute([':cid' => $conversationId, ':mid' => $messageId]);

        return (bool) $stmt->fetchColumn();
    }

    // ─── Writing ───────────────────────────────────────────────────────

    /**
     * Send a message.
     *
     * Transactional, and the reason is that four things have to stay in step:
     * the message row, the conversation's `last_message_id`/`last_message_at`,
     * the sender's own read watermark (you have read what you just wrote), and
     * the conversation's `updated_at`. A message that exists but never became
     * the conversation's last message is invisible in the inbox — the worst
     * possible failure, because nothing looks broken.
     *
     * canSendToConversation() is asked here, inside the write, not only by the
     * controller. It is strictly narrower than read access: a closed thread
     * stays legible and stays closed.
     *
     * $stored carries files that storeDocumentFile() has ALREADY written to
     * the private store — validated, sniffed and renamed. They are passed in
     * rather than uploaded here because validation must happen before the
     * transaction opens: holding a database transaction open across several
     * megabytes of file writes is how a busy server ends up with lock waits.
     * The bargain is that this method owns the cleanup, and it does: any
     * failure below unlinks every one of them.
     *
     * @param array<int, array<string,mixed>> $stored From MessageAttachment::storeAll().
     * @return int The new message id, or 0 when refused or invalid.
     */
    public function create(int $conversationId, string $body, ?int $replyToId = null, array $stored = []): int
    {
        $actor = communicationActor();
        if ($actor === null || !canSendToConversation($conversationId)) {
            MessageAttachment::discard(array_column($stored, 'file_path'));
            return 0;
        }

        $body = trim($body);

        // A message must carry something. Text or a file will do — requiring
        // someone to type "see attached" beside a photograph of a broken pipe
        // is the kind of small indignity that makes a tool feel unfinished.
        if ($body === '' && !$stored) {
            return 0;
        }

        if (mb_strlen($body) > self::MAX_LENGTH) {
            MessageAttachment::discard(array_column($stored, 'file_path'));
            return 0;
        }

        // A reply may only point at a message in the same conversation.
        // Without this, reply_to_message_id is a way to pull a quoted line out
        // of a thread the sender cannot read.
        if ($replyToId !== null && $replyToId > 0) {
            $stmt = $this->db->prepare("
                SELECT 1 FROM conversation_messages
                WHERE id = :mid AND conversation_id = :cid LIMIT 1
            ");
            $stmt->execute([':mid' => $replyToId, ':cid' => $conversationId]);
            if (!$stmt->fetchColumn()) {
                $replyToId = null;
            }
        } else {
            $replyToId = null;
        }

        try {
            $this->db->beginTransaction();

            $stmt = $this->db->prepare("
                INSERT INTO conversation_messages
                    (conversation_id, sender_id, body, message_type, reply_to_message_id)
                VALUES (:cid, :sender, :body, 'text', :reply)
            ");
            $stmt->execute([
                ':cid'    => $conversationId,
                ':sender' => $actor['id'],
                ':body'   => $body,
                ':reply'  => $replyToId,
            ]);

            $messageId = (int) $this->db->lastInsertId();

            // Metadata for files already on disk. If this throws, the catch
            // below rolls the message back and unlinks them, so a row can
            // never name a missing file and a file is never orphaned by a
            // failed row.
            (new MessageAttachment())->attachAll($messageId, $stored, $actor['id']);

            (new Conversation())->touchLastMessage($conversationId, $messageId);

            // The sender has, by definition, read their own message. Without
            // this the composer would leave its author with an unread badge
            // for their own words.
            $this->markReadUpTo($conversationId, $messageId);

            // Who is genuinely owed this message, decided live rather than
            // from the participant rows alone.
            $recipients = conversationDeliverableRecipients($conversationId, $actor['id']);

            $this->unarchiveFor($conversationId, array_merge($recipients, [$actor['id']]));
            $this->notifyRecipients($conversationId, $recipients, $actor['full_name']);

            $this->db->commit();

            return $messageId;
        } catch (PDOException $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            // The rollback undoes the rows; nothing undoes a file. Both halves
            // are needed or a failed send leaves bytes in the private store
            // that no record points at.
            MessageAttachment::discard(array_column($stored, 'file_path'));

            error_log('Conversation message error: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * Return a conversation to these participants' active inboxes.
     *
     * The archive rule, stated once: a conversation with new activity is not
     * filed away. It is applied per participant row and to nobody else —
     *
     *   recipients  because a message arrived for them;
     *   the sender  because they just wrote in it, and a thread that vanishes
     *               from your own inbox the moment you reply is a bug wearing
     *               the costume of a preference.
     *
     * No other participant, conversation or account is touched. A third party
     * who filed this thread away and was not part of this exchange keeps it
     * filed away.
     *
     * @param int[] $userIds
     */
    private function unarchiveFor(int $conversationId, array $userIds): void
    {
        $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds))));
        if (!$userIds) {
            return;
        }

        $in = implode(',', array_fill(0, count($userIds), '?'));
        $stmt = $this->db->prepare("
            UPDATE conversation_participants
               SET archived_at = NULL
             WHERE conversation_id = ? AND archived_at IS NOT NULL AND user_id IN ({$in})
        ");
        $stmt->execute(array_merge([$conversationId], $userIds));
    }

    /**
     * Tell each recipient there is a new message.
     *
     * Uses the application's existing notify(), which writes to the same
     * `notifications` table the bell already reads — there is no second
     * notification system here. Because notify() borrows the same PDO
     * connection, these INSERTs join the transaction this method is called
     * from: the message, the conversation pointer, the watermarks, the
     * un-archiving and the notifications all land together or not at all.
     * That is what makes a duplicate or an orphan impossible.
     *
     * The body is deliberately NOT included. A notification outlives the
     * access that produced it — a row in `notifications` has no relationship
     * to revoke — so a preview would leave a copy of private correspondence
     * readable by someone the access layer has since shut out. The title names
     * the sender; the message itself is one authorised click away.
     *
     * @param int[] $recipients
     */
    private function notifyRecipients(int $conversationId, array $recipients, string $senderName): void
    {
        $senderName = trim($senderName) !== '' ? $senderName : 'a colleague';

        foreach ($recipients as $userId) {
            notify(
                (int) $userId,
                'New message from ' . $senderName,
                '',
                'info',
                'conversation',
                $conversationId
            );
        }
    }

    // ─── Changing a message after it was sent ──────────────────────────

    /**
     * Rewrite the body of a message the signed-in user wrote.
     *
     * canEditMessage() decides, and it is asked here as well as in the
     * controller — the authorization is the model's, not the caller's.
     *
     * The previous text is written to audit_logs before it is overwritten.
     * That is the whole reason an edit is allowed at all: the record of what
     * was originally said survives, so "they changed it afterwards" is a
     * question the system can answer rather than a suspicion nobody can
     * settle. `edited_at` is what tells the reader an edit happened.
     *
     * @return bool|string true, or a reason the edit was refused.
     */
    public function edit(int $messageId, string $body): bool|string
    {
        if (!canEditMessage($messageId)) {
            return 'That message can no longer be edited.';
        }

        $existing = messageForAuthorship($messageId);
        $body     = trim($body);

        // The same rule sending obeys: a message must carry something. An
        // attachment-only message may legitimately be edited back to no text.
        if ($body === '' && (int) ($existing['attachment_count'] ?? 0) === 0) {
            return 'A message cannot be left empty. Delete it instead.';
        }
        if (mb_strlen($body) > self::MAX_LENGTH) {
            return 'That message is too long.';
        }
        if ($body === (string) $existing['body']) {
            return true;                       // nothing changed; not an error
        }

        try {
            $this->db->beginTransaction();

            $stmt = $this->db->prepare("
                UPDATE conversation_messages
                   SET body = :body, edited_at = NOW()
                 WHERE id = :id
            ");
            $stmt->execute([':body' => $body, ':id' => $messageId]);

            // Inside the transaction: the old wording and the new one are
            // recorded together or neither is.
            logAudit('edited_message', 'conversation',
                (int) $existing['conversation_id'], (string) $existing['body'], $body);

            $this->db->commit();

            return true;
        } catch (PDOException $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            error_log('Message edit error: ' . $e->getMessage());
            return 'That message could not be edited. Please try again.';
        }
    }

    /**
     * Withdraw a message.
     *
     * Soft, always. `deleted_at` is stamped and everything else stays exactly
     * where it was: the row, its body, its attachments and its place in the
     * thread. The renderer withholds the body and the files, the inbox preview
     * says "Message deleted", and the unread count stops counting it — but the
     * correspondence keeps its shape, replies keep their target, and the audit
     * trail keeps its record.
     *
     * The conversation's last_message_id is deliberately not moved. Repointing
     * it at the previous message would make the inbox claim the conversation's
     * last activity was earlier than it was; leaving it is what produces the
     * honest "Message deleted" preview.
     *
     * @return bool|string true, or a reason the deletion was refused.
     */
    public function softDelete(int $messageId): bool|string
    {
        if (!canDeleteMessage($messageId)) {
            return 'That message can no longer be deleted.';
        }

        $existing = messageForAuthorship($messageId);

        try {
            $this->db->beginTransaction();

            $stmt = $this->db->prepare("
                UPDATE conversation_messages SET deleted_at = NOW() WHERE id = :id
            ");
            $stmt->execute([':id' => $messageId]);

            // The withdrawn text is preserved in the log, not in the thread.
            logAudit('deleted_message', 'conversation',
                (int) $existing['conversation_id'], (string) $existing['body'], '');

            $this->db->commit();

            return true;
        } catch (PDOException $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            error_log('Message delete error: ' . $e->getMessage());
            return 'That message could not be deleted. Please try again.';
        }
    }

    /**
     * Move the signed-in user's read watermark forward.
     *
     * A watermark rather than a flag per message, so marking a thread read is
     * one UPDATE whatever its length. Never moves backwards: opening an old
     * page of a long thread must not resurrect unread counts that were
     * already cleared.
     *
     * With no message id, the watermark goes to the newest message in the
     * conversation — which is what opening a thread means.
     */
    public function markReadUpTo(int $conversationId, ?int $messageId = null): bool
    {
        $actor = communicationActor();
        if ($actor === null) {
            return false;
        }

        if ($messageId === null) {
            $stmt = $this->db->prepare("
                SELECT MAX(id) FROM conversation_messages WHERE conversation_id = :cid
            ");
            $stmt->execute([':cid' => $conversationId]);
            $messageId = (int) $stmt->fetchColumn();
        }

        if ($messageId <= 0) {
            return true;   // nothing said yet: nothing to mark
        }

        $stmt = $this->db->prepare("
            UPDATE conversation_participants
               SET last_read_message_id = GREATEST(COALESCE(last_read_message_id, 0), :mid),
                   last_read_at = NOW()
             WHERE conversation_id = :cid AND user_id = :ca_me
        ");

        return $stmt->execute([
            ':mid'    => $messageId,
            ':cid'    => $conversationId,
            ':ca_me'  => $actor['id'],
        ]);
    }

    /**
     * Move the signed-in user's watermark to the end of every conversation
     * they are in.
     *
     * One statement rather than a loop: the watermark is per participant row,
     * so "mark everything read" is a single correlated UPDATE. Archived
     * conversations are included — they are still theirs, and leaving unread
     * counts behind in the archive is how a badge starts lying.
     *
     * Nobody else's read state is touched: `user_id = :me` is on the UPDATE.
     */
    public function markAllRead(): int
    {
        $actor = communicationActor();
        if ($actor === null) {
            return 0;
        }

        $stmt = $this->db->prepare("
            UPDATE conversation_participants cp
               SET cp.last_read_message_id = (
                       SELECT MAX(cm.id) FROM conversation_messages cm
                        WHERE cm.conversation_id = cp.conversation_id
                   ),
                   cp.last_read_at = NOW()
             WHERE cp.user_id = :me
               AND cp.is_active = 1
        ");
        $stmt->execute([':me' => $actor['id']]);

        return $stmt->rowCount();
    }

    // ─── Counting ──────────────────────────────────────────────────────

    /**
     * Unread totals for the signed-in user, keyed by conversation id.
     *
     * One query for the whole inbox rather than one per row. A message counts
     * as unread when it is above this participant's watermark, was not sent by
     * them, and has not been deleted.
     *
     * @return array<int, int>
     */
    public function unreadCountsFor(): array
    {
        $actor = communicationActor();
        if ($actor === null) {
            return [];
        }

        $stmt = $this->db->prepare("
            SELECT cm.conversation_id, COUNT(*) AS n
            FROM conversation_messages cm
            JOIN conversation_participants cp
              ON cp.conversation_id = cm.conversation_id
             AND cp.user_id = :ca_me AND cp.is_active = 1
            WHERE cm.deleted_at IS NULL
              AND cm.sender_id <> :ca_me
              AND (cp.last_read_message_id IS NULL OR cm.id > cp.last_read_message_id)
            GROUP BY cm.conversation_id
        ");
        $stmt->execute([':ca_me' => $actor['id']]);

        return array_map('intval', array_column($stmt->fetchAll(), 'n', 'conversation_id'));
    }

    /**
     * One number for the whole account — the badge the header will carry.
     *
     * Archived conversations are excluded: filing a thread away and then being
     * counted for it is the behaviour that makes people stop trusting a badge.
     */
    public function totalUnreadFor(): int
    {
        $actor = communicationActor();
        if ($actor === null) {
            return 0;
        }

        $stmt = $this->db->prepare("
            SELECT COUNT(*)
            FROM conversation_messages cm
            JOIN conversation_participants cp
              ON cp.conversation_id = cm.conversation_id
             AND cp.user_id = :ca_me AND cp.is_active = 1 AND cp.archived_at IS NULL
            WHERE cm.deleted_at IS NULL
              AND cm.sender_id <> :ca_me
              AND (cp.last_read_message_id IS NULL OR cm.id > cp.last_read_message_id)
        ");
        $stmt->execute([':ca_me' => $actor['id']]);

        return (int) $stmt->fetchColumn();
    }
}
