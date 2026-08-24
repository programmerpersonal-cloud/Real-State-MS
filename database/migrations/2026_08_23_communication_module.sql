-- ═══════════════════════════════════════════════════════════════════════
-- Communication module — data foundation
--
-- Three tables that let authorised users hold a business conversation with
-- each other: `conversations` (the thread and what it is about),
-- `conversation_participants` (who is in it and what each of them has read)
-- and `conversation_messages` (what was said).
--
-- WHY NOT THE EXISTING `messages` TABLE
--   `messages` already exists and is NOT free. It is the inquiry reply log —
--   written by Inquiry::addMessage(), read by Inquiry::getMessages(), rendered
--   by views/admin/inquiries/show.php. It has sender/receiver/inquiry_id and a
--   single is_read flag: no conversation, no per-participant read state, no
--   business context, no soft delete. Re-shaping it would break the enquiry
--   screen for the sake of saving one CREATE TABLE.
--
--   So the new message table is `conversation_messages`. The name is the
--   difference in one word: a row here belongs to a conversation, not to a
--   pair of people. `messages` is left exactly as it is and keeps doing its
--   job.
--
-- WHAT IS DELIBERATELY ABSENT
--   `message_attachments` is not created here. It belongs to the attachments
--   phase, and a table nothing writes to is a liability rather than a head
--   start — it invites a later reader to assume a feature exists.
--
-- SAFETY
--   * No existing table is altered, renamed or dropped.
--   * No existing row is read, written or deleted.
--   * The guard below stops the migration dead if it has already been run, so
--     executing it twice costs nothing and destroys nothing.
--   * Every foreign key names a table that already exists in this schema:
--     properties, leases, maintenance_requests, users.
-- ═══════════════════════════════════════════════════════════════════════

-- ─── Guard ─────────────────────────────────────────────────────────────
-- Expect an empty result. Any row means this migration has already run and
-- the statements below must NOT be executed a second time.
SELECT TABLE_NAME
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME IN ('conversations', 'conversation_participants', 'conversation_messages');

-- Expect exactly one row, `messages`, with 8 columns. This is the table the
-- migration must leave alone; run it again afterwards and compare.
SELECT COUNT(*) AS legacy_messages_columns
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'messages';


-- ═══ 1. conversations ══════════════════════════════════════════════════
--
-- One row per thread. The three context columns are what make this a
-- business communication system rather than a chat app: a conversation is
-- usually *about* something, and the thing it is about decides who may read
-- it. All three are nullable because a plain agent↔owner conversation is
-- about nothing but itself.
--
-- `lease_id`, not `rental_id`. The access layer scopes tenants on `leases`
-- (leases.status = 'active' — see includes/property_access.php); `rentals`
-- is downstream of a lease and carries no tenant relationship the lease does
-- not already carry. Pointing at `rentals` would mean joining back through
-- it to answer every authorisation question.
--
-- `last_message_id` carries NO foreign key at this point. It cannot: the
-- table it references does not exist yet, and the table that does not exist
-- yet references this one. The constraint is added in step 4 below, once
-- both ends are real. It is not omitted, only deferred.
CREATE TABLE conversations (
    id INT AUTO_INCREMENT PRIMARY KEY,

    -- What kind of thread this is, which is also what the header renders.
    -- 'direct' carries no context; the other three each name one record.
    conversation_type ENUM('direct','property','rental','maintenance') NOT NULL DEFAULT 'direct',

    -- The business context. At most one of these is meaningful for a given
    -- conversation_type, but a rental conversation legitimately carries both
    -- lease_id and the property_id that lease sits on, which saves a join on
    -- every render of the conversation list.
    property_id INT DEFAULT NULL,
    lease_id INT DEFAULT NULL,
    maintenance_request_id INT DEFAULT NULL,

    -- Optional human title. Left NULL for direct conversations, where the
    -- other participant's name is the only title that means anything.
    subject VARCHAR(200) DEFAULT NULL,

    created_by INT DEFAULT NULL,

    -- 'closed' ends a conversation for everyone: it stays readable, nothing
    -- more can be sent. Distinct from archiving, which is per-participant
    -- and lives on conversation_participants.archived_at.
    status ENUM('active','closed') NOT NULL DEFAULT 'active',

    -- Denormalised so the inbox can be ordered and previewed without
    -- touching conversation_messages. Both are NULL until the first message
    -- is sent, which is a real state: a conversation exists from the moment
    -- it is created.
    last_message_id INT DEFAULT NULL,
    last_message_at DATETIME DEFAULT NULL,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    -- ON DELETE SET NULL throughout, matching the convention the rest of the
    -- schema uses for context columns (properties.agent_id,
    -- documents.uploaded_by). Deleting a property must not delete the record
    -- of what was said about it; the conversation simply loses its context
    -- and the authorisation layer treats a missing context as "no longer in
    -- scope", which fails closed.
    CONSTRAINT fk_conv_property
        FOREIGN KEY (property_id) REFERENCES properties(id) ON DELETE SET NULL,
    CONSTRAINT fk_conv_lease
        FOREIGN KEY (lease_id) REFERENCES leases(id) ON DELETE SET NULL,
    CONSTRAINT fk_conv_maintenance
        FOREIGN KEY (maintenance_request_id) REFERENCES maintenance_requests(id) ON DELETE SET NULL,
    CONSTRAINT fk_conv_creator
        FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,

    -- The duplicate-prevention lookup: "is there already an active thread of
    -- this type about this record?" Leading with conversation_type because
    -- it is the column always supplied.
    INDEX idx_conv_context (conversation_type, property_id, lease_id, maintenance_request_id),

    -- The inbox sort. Descending order is served by an ascending index.
    INDEX idx_conv_recent (last_message_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ═══ 2. conversation_participants ══════════════════════════════════════
--
-- Who is in the conversation, and what each of them has read. Read state is
-- per participant and always has been the point: one shared is_read flag —
-- which is what the legacy `messages` table has — cannot answer "is this
-- unread *for me*".
--
-- `role_at_join` is a HISTORICAL SNAPSHOT AND NOTHING ELSE. It exists so a
-- year-old thread still reads as "the tenant said this, the agent replied
-- that" after the tenant has moved out. It is written once, on insert, and
-- read only by the presentation layer.
--
--   It is never an authorisation input. Every access decision re-derives the
--   answer from the CURRENT users.role_id, the CURRENT users.is_active and
--   the CURRENT business relationship. A tenant whose lease ends loses
--   access to the conversation even though this row, and the word 'customer'
--   in it, survive for the audit trail. If you ever find yourself reading
--   this column to decide whether someone may do something, the bug is the
--   read, not the column.
--
-- `last_read_message_id` carries no foreign key yet, for the same reason
-- conversations.last_message_id does not: conversation_messages is created
-- after this table. Added in step 5.
CREATE TABLE conversation_participants (
    id INT AUTO_INCREMENT PRIMARY KEY,
    conversation_id INT NOT NULL,
    user_id INT NOT NULL,

    -- Snapshot. Display only. See the note above.
    role_at_join VARCHAR(50) DEFAULT NULL,

    -- The read watermark: everything up to and including this message has
    -- been seen by this participant. A watermark rather than a per-message
    -- flag, so marking a thread read is one UPDATE regardless of how long
    -- the thread is.
    last_read_message_id INT DEFAULT NULL,
    last_read_at DATETIME DEFAULT NULL,

    joined_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    -- Archiving is per participant, by design: one person filing a thread
    -- away must not remove it from the other person's inbox.
    archived_at DATETIME DEFAULT NULL,

    -- Whether this participant is still in the conversation. Left as a
    -- column rather than a delete so a departed participant's messages keep
    -- a sender the UI can name.
    is_active TINYINT(1) NOT NULL DEFAULT 1,

    -- CASCADE here, unlike the SET NULL above, and deliberately: a
    -- participant row is meaningless without its conversation or its user.
    -- There is no audit value in "somebody was in a conversation that no
    -- longer exists".
    CONSTRAINT fk_cp_conversation
        FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE CASCADE,
    CONSTRAINT fk_cp_user
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,

    -- Enrolling the same person twice would double every unread count and
    -- give them two read watermarks that disagree. The database refuses it
    -- rather than trusting every future caller to check first.
    UNIQUE KEY uniq_cp_conversation_user (conversation_id, user_id),

    -- The inbox query: this user's live, unarchived conversations.
    INDEX idx_cp_inbox (user_id, is_active, archived_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ═══ 3. conversation_messages ══════════════════════════════════════════
--
-- What was said. Both ends of every foreign key now exist, so all three are
-- declared inline.
--
-- Deletion is soft. `deleted_at` is set and the body is withheld from the
-- renderer; the row stays so the thread keeps its shape, replies keep their
-- target, and the audit trail keeps its record. This is business
-- correspondence about tenancies and money — a message someone can make
-- vanish without trace is worth less than one they cannot.
CREATE TABLE conversation_messages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    conversation_id INT NOT NULL,

    -- Nullable so deleting an account does not delete the correspondence.
    -- The UI renders a NULL sender as a former user.
    sender_id INT DEFAULT NULL,

    body TEXT NOT NULL,

    -- 'system' is for lines the application writes itself — "this request
    -- was marked complete" — so they can be styled apart from what a person
    -- typed and excluded from unread counts if that is ever wanted.
    message_type ENUM('text','system') NOT NULL DEFAULT 'text',

    reply_to_message_id INT DEFAULT NULL,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    -- NULL until edited / deleted. Both are audit facts, which is why they
    -- are timestamps rather than flags: "changed" is worth less than
    -- "changed at 14:22 on the 3rd".
    edited_at DATETIME DEFAULT NULL,
    deleted_at DATETIME DEFAULT NULL,

    CONSTRAINT fk_cm_conversation
        FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE CASCADE,
    CONSTRAINT fk_cm_sender
        FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE SET NULL,

    -- Self-reference: a reply whose target is removed becomes an ordinary
    -- message rather than a dangling pointer.
    CONSTRAINT fk_cm_reply_to
        FOREIGN KEY (reply_to_message_id) REFERENCES conversation_messages(id) ON DELETE SET NULL,

    -- Thread pagination: one conversation, in order, newest page first.
    INDEX idx_cm_thread (conversation_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ═══ 4 & 5. Closing the circle ═════════════════════════════════════════
--
-- conversations.last_message_id → conversation_messages.id, while
-- conversation_messages.conversation_id → conversations.id. That is a
-- genuine cycle, and no ordering of CREATE TABLE can satisfy both: whichever
-- table is built first would reference one that does not exist.
--
-- The integrity model is NOT weakened to avoid it. Both constraints are
-- declared — they are simply applied now, after both tables are real. This
-- is the standard resolution and it costs nothing: the tables are empty, so
-- each ALTER is instantaneous.
--
-- ON DELETE SET NULL on both. In practice neither will fire often, because
-- messages are soft-deleted rather than removed; when it does fire, a
-- conversation with no last message and a participant with no read watermark
-- are both states the application already handles — they are what a brand
-- new conversation looks like.

ALTER TABLE conversations
    ADD CONSTRAINT fk_conv_last_message
        FOREIGN KEY (last_message_id) REFERENCES conversation_messages(id) ON DELETE SET NULL;

ALTER TABLE conversation_participants
    ADD CONSTRAINT fk_cp_last_read
        FOREIGN KEY (last_read_message_id) REFERENCES conversation_messages(id) ON DELETE SET NULL;


-- ═══ Verification ══════════════════════════════════════════════════════
-- Expect three rows.
SELECT TABLE_NAME
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME IN ('conversations', 'conversation_participants', 'conversation_messages')
ORDER BY TABLE_NAME;

-- Expect eleven foreign keys across the three new tables, including the two
-- added by ALTER above.
SELECT TABLE_NAME, CONSTRAINT_NAME, COLUMN_NAME, REFERENCED_TABLE_NAME
FROM information_schema.KEY_COLUMN_USAGE
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME IN ('conversations', 'conversation_participants', 'conversation_messages')
  AND REFERENCED_TABLE_NAME IS NOT NULL
ORDER BY TABLE_NAME, CONSTRAINT_NAME;

-- Expect 8 — the legacy inquiry reply table, untouched.
SELECT COUNT(*) AS legacy_messages_columns
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'messages';
