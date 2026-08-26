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
    public function forConversation(int $conversationId, ?int $beforeId = null, int $limit = self::PAGE_SIZE): array
    {
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

        $stmt = $this->db->prepare("
            SELECT cm.id, cm.conversation_id, cm.sender_id, cm.message_type,
                   cm.reply_to_message_id, cm.created_at, cm.edited_at, cm.deleted_at,
                   CASE WHEN cm.deleted_at IS NULL THEN cm.body ELSE NULL END AS body,
                   u.full_name AS sender_name, u.avatar AS sender_avatar,
                   r.name AS sender_role,
                   rt.body AS reply_to_body, rt.deleted_at AS reply_to_deleted_at,
                   ru.full_name AS reply_to_sender_name
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

        return array_reverse($stmt->fetchAll());
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
     * @return int The new message id, or 0 when refused or invalid.
     */
    public function create(int $conversationId, string $body, ?int $replyToId = null): int
    {
        $actor = communicationActor();
        if ($actor === null || !canSendToConversation($conversationId)) {
            return 0;
        }

        $body = trim($body);
        if ($body === '' || mb_strlen($body) > self::MAX_LENGTH) {
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
