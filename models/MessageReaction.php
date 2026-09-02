<?php
/**
 * MessageReaction Model
 *
 * A reaction is one row per (message, user, emoji), and the unique key on
 * those three columns is the whole feature rather than a safety net: it is
 * what makes reacting a toggle instead of a counter. Pressing 👍 twice cannot
 * produce two thumbs, because the second row cannot exist.
 *
 * Which emoji may be stored is decided by MESSAGE_REACTIONS in config, checked
 * server-side — the picker is a convenience, not the boundary. Who may react
 * is decided by canReactToMessage() in the access layer, which walks the
 * message back to its conversation and asks the same live check the thread
 * does.
 */
class MessageReaction
{
    private PDO $db;

    public function __construct()
    {
        $this->db = getDBConnection();
    }

    /**
     * Add this reaction, or take it back if it is already there.
     *
     * One call, both directions — a reaction control is a toggle and modelling
     * it as two actions would mean the UI had to know which one to send, which
     * is a race waiting to happen when the same person has the thread open
     * twice.
     *
     * @return bool|string true, or a reason it was refused.
     */
    public function toggle(int $messageId, string $emoji): bool|string
    {
        $actor = communicationActor();
        if ($actor === null) {
            return 'You are not signed in.';
        }
        if (!isAllowedReaction($emoji)) {
            return 'That reaction is not available.';
        }
        if (!canReactToMessage($messageId)) {
            return 'You cannot react to that message.';
        }

        try {
            // Delete-then-insert rather than a read followed by a branch: the
            // affected-row count tells us which way the toggle went without a
            // second query, and without a window in which another tab could
            // change the answer between the read and the write.
            $stmt = $this->db->prepare("
                DELETE FROM message_reactions
                WHERE message_id = :m AND user_id = :u AND emoji = :e
            ");
            $stmt->execute([':m' => $messageId, ':u' => $actor['id'], ':e' => $emoji]);

            if ($stmt->rowCount() > 0) {
                return true;                       // it was on; now it is off
            }

            $stmt = $this->db->prepare("
                INSERT INTO message_reactions (message_id, user_id, emoji)
                VALUES (:m, :u, :e)
            ");
            $stmt->execute([':m' => $messageId, ':u' => $actor['id'], ':e' => $emoji]);

            return true;
        } catch (PDOException $e) {
            // A duplicate here means two submissions crossed. The end state is
            // the one the user asked for, so it is not an error worth showing.
            if (($e->errorInfo[1] ?? 0) === 1062) {
                return true;
            }
            error_log('Reaction toggle error: ' . $e->getMessage());
            return 'That reaction could not be saved.';
        }
    }

    /**
     * Reactions for a page of messages, aggregated, keyed by message id.
     *
     * One query for the whole page. Each entry carries the emoji, how many
     * people used it, and whether the signed-in user is one of them — the last
     * is what lets the control render as pressed, and it is computed in SQL so
     * the caller never has to fetch the individual rows.
     *
     * @param int[] $messageIds
     * @return array<int, array<int, array{emoji:string, count:int, mine:bool}>>
     */
    public function forMessages(array $messageIds): array
    {
        $actor = communicationActor();
        $ids   = array_values(array_filter(array_map('intval', $messageIds)));
        if (!$ids || $actor === null) {
            return [];
        }

        $in = implode(',', array_fill(0, count($ids), '?'));

        $stmt = $this->db->prepare("
            SELECT message_id, emoji,
                   COUNT(*) AS n,
                   SUM(user_id = ?) AS mine
            FROM message_reactions
            WHERE message_id IN ({$in})
            GROUP BY message_id, emoji
            ORDER BY n DESC, emoji ASC
        ");
        // The `mine` parameter is bound first in the SELECT list, so it leads.
        $stmt->execute(array_merge([$actor['id']], $ids));

        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $out[(int) $row['message_id']][] = [
                'emoji' => (string) $row['emoji'],
                'count' => (int) $row['n'],
                'mine'  => ((int) $row['mine']) > 0,
            ];
        }

        return $out;
    }

    /**
     * Who reacted with what, for one message — the tooltip behind a count.
     *
     * @return array<int, array{emoji:string, name:string}>
     */
    public function peopleFor(int $messageId): array
    {
        if (!canReactToMessage($messageId)) {
            return [];
        }

        $stmt = $this->db->prepare("
            SELECT r.emoji, u.full_name AS name
            FROM message_reactions r
            JOIN users u ON r.user_id = u.id
            WHERE r.message_id = ?
            ORDER BY r.emoji, u.full_name
        ");
        $stmt->execute([$messageId]);

        return $stmt->fetchAll();
    }
}
