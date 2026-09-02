<?php
/**
 * Conversation Model
 *
 * Every read in here is scoped by includes/communication_access.php, and that
 * is deliberate and it is the security boundary: the scope is applied where
 * the SQL is built, not where a controller happens to remember to check, so a
 * caller added later cannot accidentally read a conversation the signed-in
 * user is not party to.
 *
 * The same arrangement MaintenanceRequest uses with property_access.php.
 *
 * One rule worth stating twice: nothing in this file reads
 * conversation_participants.role_at_join in order to decide anything. That
 * column is a snapshot of who someone was when they joined, kept so an old
 * thread renders honestly. Authorization is re-derived live, every time, by
 * the access layer.
 */
class Conversation
{
    /**
     * The conversation types, and what each one is about.
     *
     * The key is stored in `conversation_type`; the value is the context
     * column that gives it meaning. 'direct' maps to null because a direct
     * conversation is about nothing but the two people in it.
     */
    public const TYPES = [
        'direct'      => null,
        'property'    => 'property_id',
        'rental'      => 'lease_id',
        'maintenance' => 'maintenance_request_id',
    ];

    /**
     * Sortable orders for the inbox, keyed by the token a request may ask for.
     *
     * The default is 'recent', because an inbox is read newest-first and a
     * conversation that has never been used sorts last rather than first —
     * `last_message_at` is NULL until something is said, and NULLS sort low on
     * a DESC ordering in MariaDB, which is the behaviour wanted.
     *
     * Nothing here is built from request text: the request names a key and
     * this constant supplies the clause.
     */
    public const SORTS = [
        'recent' => 'c.last_message_at DESC, c.created_at DESC',
        'oldest' => 'c.last_message_at ASC, c.created_at ASC',
    ];

    private PDO $db;

    public function __construct()
    {
        $this->db = getDBConnection();
    }

    // ─── Reading ───────────────────────────────────────────────────────

    /**
     * A single conversation with its business context attached, so the
     * row-level access checks and the header can both run without a second
     * query.
     *
     * Returns the row regardless of who is asking — this is the data layer.
     * The caller must pass the id through canAccessConversation() first;
     * every one of them does.
     */
    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare("
            SELECT c.*,
                   p.title AS property_title, p.property_code, p.status AS property_status,
                   p.location AS property_location, p.is_archived AS property_archived,
                   p.owner_id AS property_owner_id, p.agent_id AS property_agent_id,
                   /* The cover photograph for the thread's context card. A
                      correlated subquery rather than a join, so a property
                      with twelve photographs still contributes one row and
                      the LEFT JOINs below cannot multiply. Falls back to
                      whichever image sorts first when none is marked cover. */
                   (SELECT pi.file_path FROM property_images pi
                     WHERE pi.property_id = p.id
                     ORDER BY pi.is_cover DESC, pi.sort_order ASC, pi.id ASC
                     LIMIT 1) AS property_image,
                   l.lease_code, l.status AS lease_status,
                   l.start_date AS lease_start, l.end_date AS lease_end,
                   m.request_code, m.issue_type, m.priority AS maintenance_priority,
                   m.status AS maintenance_status,
                   u.full_name AS created_by_name
            FROM conversations c
            LEFT JOIN properties p ON c.property_id = p.id
            LEFT JOIN leases l ON c.lease_id = l.id
            LEFT JOIN maintenance_requests m ON c.maintenance_request_id = m.id
            LEFT JOIN users u ON c.created_by = u.id
            WHERE c.id = :id
        ");
        $stmt->execute([':id' => $id]);

        return $stmt->fetch() ?: null;
    }

    /**
     * The signed-in user's inbox: conversations they are an active participant
     * of, newest first, with the other party and the unread count attached.
     *
     * The scope comes from conversationViewScope() rather than a `user_id`
     * this method was handed, so a caller cannot ask for somebody else's
     * inbox by passing a different id — there is no id to pass.
     *
     * Avoids N+1 by resolving the counterpart and the unread count as
     * correlated subqueries in the same statement. Both are keyed lookups:
     * `uniq_cp_conversation_user` serves the first, `idx_cm_thread` the
     * second.
     *
     * @param array{archived?:bool, unread?:bool, search?:string, sort?:string,
     *              limit?:int, offset?:int} $options
     * @return array<int, array<string, mixed>>
     */
    public function forUser(array $options = []): array
    {
        $actor = communicationActor();
        if ($actor === null) {
            return [];
        }

        [$scope, $params] = conversationViewScope('c');
        $params[':ca_me'] = $actor['id'];

        // Archived is a property of the *participant row*, not the
        // conversation: one person filing a thread away must not remove it
        // from anyone else's inbox.
        $where = ["({$scope})"];
        $where[] = !empty($options['archived'])
            ? 'me.archived_at IS NOT NULL'
            : 'me.archived_at IS NULL';

        if (!empty($options['unread'])) {
            $where[] = 'unread.n > 0';
        }

        if (!empty($options['search'])) {
            // Participant name, property title/code, and maintenance request
            // title — the three things someone actually remembers. Message
            // bodies are deliberately not searched here; that is a later
            // phase and it carries its own authorization question.
            $where[] = "(other.full_name LIKE :ca_q OR p.title LIKE :ca_q
                         OR p.property_code LIKE :ca_q OR m.issue_type LIKE :ca_q
                         OR c.subject LIKE :ca_q)";
            $params[':ca_q'] = '%' . $options['search'] . '%';
        }

        $order  = self::SORTS[$options['sort'] ?? ''] ?? self::SORTS['recent'];
        $limit  = max(1, (int) ($options['limit'] ?? ITEMS_PER_PAGE));
        $offset = max(0, (int) ($options['offset'] ?? 0));

        $sql = "
            SELECT c.*,
                   me.archived_at, me.last_read_message_id, me.last_read_at,
                   other.id AS other_user_id, other.full_name AS other_user_name,
                   other.avatar AS other_user_avatar,
                   other.last_seen_at AS other_last_seen,
                   other_role.name AS other_user_role,
                   other_role.display_name AS other_user_role_label,
                   p.title AS property_title, p.property_code,
                   l.lease_code, l.status AS lease_status,
                   m.request_code, m.issue_type, m.status AS maintenance_status,
                   lm.body AS last_message_body, lm.sender_id AS last_message_sender_id,
                   lm.deleted_at AS last_message_deleted_at,
                   COALESCE(unread.n, 0) AS unread_count
            FROM conversations c

            /* The signed-in user's own participant row: read state and
               archive state both live here. */
            JOIN conversation_participants me
              ON me.conversation_id = c.id AND me.user_id = :ca_me AND me.is_active = 1

            /* The other party. LEFT JOIN because a conversation whose
               counterpart has been deactivated still belongs in the inbox —
               it simply cannot be replied to. */
            LEFT JOIN conversation_participants op
              ON op.conversation_id = c.id AND op.user_id <> :ca_me AND op.is_active = 1
            LEFT JOIN users other ON op.user_id = other.id
            LEFT JOIN roles other_role ON other.role_id = other_role.id

            LEFT JOIN properties p ON c.property_id = p.id
            LEFT JOIN leases l ON c.lease_id = l.id
            LEFT JOIN maintenance_requests m ON c.maintenance_request_id = m.id
            LEFT JOIN conversation_messages lm ON c.last_message_id = lm.id

            /* Unread is per participant and always has been the point: a
               single shared flag cannot answer 'is this unread for me'. */
            LEFT JOIN (
                SELECT cm.conversation_id, COUNT(*) AS n
                FROM conversation_messages cm
                JOIN conversation_participants cp
                  ON cp.conversation_id = cm.conversation_id AND cp.user_id = :ca_me
                WHERE cm.deleted_at IS NULL
                  AND cm.sender_id <> :ca_me
                  AND (cp.last_read_message_id IS NULL OR cm.id > cp.last_read_message_id)
                GROUP BY cm.conversation_id
            ) unread ON unread.conversation_id = c.id

            WHERE " . implode(' AND ', $where) . "
            GROUP BY c.id
            ORDER BY {$order}
            LIMIT {$limit} OFFSET {$offset}
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    /**
     * How many conversations the same filters would return, for pagination.
     *
     * Deliberately a second, cheaper statement rather than SQL_CALC_FOUND_ROWS
     * — the previews and unread joins above are not needed to count rows.
     */
    public function countForUser(array $options = []): int
    {
        $actor = communicationActor();
        if ($actor === null) {
            return 0;
        }

        [$scope, $params] = conversationViewScope('c');
        $params[':ca_me'] = $actor['id'];

        $where = ["({$scope})"];
        $where[] = !empty($options['archived'])
            ? 'me.archived_at IS NOT NULL'
            : 'me.archived_at IS NULL';

        $stmt = $this->db->prepare("
            SELECT COUNT(*)
            FROM conversations c
            JOIN conversation_participants me
              ON me.conversation_id = c.id AND me.user_id = :ca_me AND me.is_active = 1
            WHERE " . implode(' AND ', $where));
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Everyone in a conversation, with their current identity alongside the
     * role they held when they joined.
     *
     * Both are returned and they are different things. `role` is what this
     * person is now and is the only one any decision may be based on;
     * `role_at_join` is what they were then and exists so a year-old thread
     * can still be labelled honestly.
     */
    public function participants(int $conversationId): array
    {
        $stmt = $this->db->prepare("
            SELECT cp.*, u.full_name, u.email, u.phone, u.avatar, u.is_active AS user_is_active,
                   u.last_seen_at,
                   r.name AS role, r.display_name AS role_label
            FROM conversation_participants cp
            JOIN users u ON cp.user_id = u.id
            JOIN roles r ON u.role_id = r.id
            WHERE cp.conversation_id = :id
            ORDER BY cp.joined_at ASC, cp.id ASC
        ");
        $stmt->execute([':id' => $conversationId]);

        return $stmt->fetchAll();
    }

    // ─── Writing ───────────────────────────────────────────────────────

    /**
     * An existing active conversation with exactly this shape, or null.
     *
     * "Message my agent about Villa V-102" pressed twice must reach the same
     * thread, not create a second one — a duplicate splits the correspondence
     * and gives each half its own unread count.
     *
     * Equivalence is all four of: same type, same context ids, same
     * participant set exactly (no more and no fewer), and still active. The
     * `<=>` operator is MariaDB's NULL-safe equality, which is what makes
     * "both have no property" match rather than compare NULL to NULL and
     * evaluate to NULL.
     *
     * @param int[] $userIds The full intended participant set.
     */
    public function findEquivalent(string $type, array $context, array $userIds): ?array
    {
        $userIds = array_values(array_unique(array_map('intval', $userIds)));
        if (!$userIds || !array_key_exists($type, self::TYPES)) {
            return null;
        }

        $placeholders = [];
        $params = [
            ':type' => $type,
            ':pid'  => $context['property_id'] ?? null,
            ':lid'  => $context['lease_id'] ?? null,
            ':mid'  => $context['maintenance_request_id'] ?? null,
            ':n'    => count($userIds),
        ];
        foreach ($userIds as $i => $id) {
            $placeholders[] = ":u{$i}";
            $params[":u{$i}"] = $id;
        }
        $inList = implode(',', $placeholders);

        $stmt = $this->db->prepare("
            SELECT c.*
            FROM conversations c
            WHERE c.conversation_type = :type
              AND c.status = 'active'
              AND c.property_id <=> :pid
              AND c.lease_id <=> :lid
              AND c.maintenance_request_id <=> :mid
              /* the set is no larger … */
              AND (SELECT COUNT(*) FROM conversation_participants x
                    WHERE x.conversation_id = c.id AND x.is_active = 1) = :n
              /* … and no smaller */
              AND (SELECT COUNT(*) FROM conversation_participants y
                    WHERE y.conversation_id = c.id AND y.is_active = 1
                      AND y.user_id IN ({$inList})) = :n
            ORDER BY c.id ASC
            LIMIT 1
        ");
        $stmt->execute($params);

        return $stmt->fetch() ?: null;
    }

    /**
     * Create a conversation and enrol its participants, or return the
     * equivalent one that already exists.
     *
     * Transactional: a conversation with no participants would be unreachable
     * by everyone including its creator, so the row and the enrolments succeed
     * together or not at all.
     *
     * Authorization is the caller's job and is asserted here rather than
     * assumed — canMessageUser() for every counterpart and
     * canCreateContextConversation() for the context. Doing it inside the
     * write is what makes a hand-edited recipient_id or property_id fail to
     * produce a row rather than fail to be noticed.
     *
     * @param int[] $participantUserIds Counterparts, excluding the creator.
     * @return int The conversation id, or 0 when refused.
     */
    public function create(string $type, array $context, array $participantUserIds): int
    {
        $actor = communicationActor();
        if ($actor === null || !array_key_exists($type, self::TYPES)) {
            return 0;
        }

        $propertyId    = (int) ($context['property_id'] ?? 0);
        $leaseId       = (int) ($context['lease_id'] ?? 0);
        $maintenanceId = (int) ($context['maintenance_request_id'] ?? 0);

        if (!canCreateContextConversation($propertyId, $leaseId, $maintenanceId)) {
            return 0;
        }

        $counterparts = array_values(array_unique(array_map('intval', $participantUserIds)));
        if (!$counterparts) {
            return 0;
        }
        foreach ($counterparts as $userId) {
            if (!canMessageUser($userId)) {
                return 0;
            }
        }

        $everyone = array_values(array_unique(array_merge([$actor['id']], $counterparts)));

        // Reuse before create. Checked inside the transaction below as well
        // as here, because two rapid submissions could otherwise both pass
        // this test before either had written a row.
        $existing = $this->findEquivalent($type, $context, $everyone);
        if ($existing) {
            return (int) $existing['id'];
        }

        try {
            $this->db->beginTransaction();

            $stmt = $this->db->prepare("
                INSERT INTO conversations
                    (conversation_type, property_id, lease_id, maintenance_request_id,
                     subject, created_by, status)
                VALUES (:type, :pid, :lid, :mid, :subject, :creator, 'active')
            ");
            $stmt->execute([
                ':type'    => $type,
                ':pid'     => $propertyId ?: null,
                ':lid'     => $leaseId ?: null,
                ':mid'     => $maintenanceId ?: null,
                ':subject' => $context['subject'] ?? null,
                ':creator' => $actor['id'],
            ]);

            $conversationId = (int) $this->db->lastInsertId();

            foreach ($everyone as $userId) {
                $this->addParticipant($conversationId, $userId);
            }

            $this->db->commit();

            logAudit('conversation_created', 'conversation', $conversationId, '', $type);

            return $conversationId;
        } catch (PDOException $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            error_log('Conversation create error: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * Enrol one user, stamping the role they hold *at this moment*.
     *
     * `role_at_join` is written from the live roles table here and is never
     * read back for a decision. Re-enrolling someone already present
     * reactivates their row rather than failing on the unique key — leaving
     * and rejoining a conversation should not lose the read watermark.
     */
    public function addParticipant(int $conversationId, int $userId): bool
    {
        $stmt = $this->db->prepare("
            INSERT INTO conversation_participants (conversation_id, user_id, role_at_join)
            SELECT :cid, u.id, r.name
            FROM users u
            JOIN roles r ON u.role_id = r.id
            WHERE u.id = :uid
            ON DUPLICATE KEY UPDATE is_active = 1, archived_at = NULL
        ");

        return $stmt->execute([':cid' => $conversationId, ':uid' => $userId]);
    }

    /**
     * Point the conversation at its newest message.
     *
     * Denormalised so the inbox can order and preview without touching
     * conversation_messages. Called from ConversationMessage::create() inside
     * that method's transaction, never on its own.
     */
    public function touchLastMessage(int $conversationId, int $messageId): void
    {
        $stmt = $this->db->prepare("
            UPDATE conversations
               SET last_message_id = :mid, last_message_at = NOW()
             WHERE id = :cid
        ");
        $stmt->execute([':mid' => $messageId, ':cid' => $conversationId]);
    }

    /**
     * Archive or unarchive a conversation for the signed-in user alone.
     *
     * The `user_id = :ca_me` on the statement is what makes this
     * participant-specific: there is no way to spell "archive this for
     * everybody" through this method, which is the correct shape for the
     * feature. Conversation history is never deleted.
     */
    public function setArchived(int $conversationId, bool $archived): bool
    {
        $actor = communicationActor();
        if ($actor === null || !canAccessConversation($conversationId)) {
            return false;
        }

        $stmt = $this->db->prepare("
            UPDATE conversation_participants
               SET archived_at = " . ($archived ? 'NOW()' : 'NULL') . "
             WHERE conversation_id = :cid AND user_id = :ca_me
        ");

        return $stmt->execute([':cid' => $conversationId, ':ca_me' => $actor['id']]);
    }
}
