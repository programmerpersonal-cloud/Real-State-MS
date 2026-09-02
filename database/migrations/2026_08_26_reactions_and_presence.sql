-- ═══════════════════════════════════════════════════════════════════════
-- Message reactions, and honest presence
--
-- Two additions, and deliberately only two. The audit before writing this
-- found that most of what the interactive phase needs already exists:
--
--   edited_at             already on conversation_messages
--   deleted_at            already on conversation_messages
--   reply_to_message_id   already on conversation_messages, already
--                         validated same-conversation on insert
--   attachments           already stored privately and served through an
--                         authorising endpoint
--
-- So editing, deletion, replies and voice notes need no schema at all. A
-- voice note in particular is not a new kind of record: it is an ordinary
-- message carrying an audio attachment, which means it inherits the whole of
-- the attachment security model — sniffed MIME, derived extension, random
-- private filename, authorised delivery — instead of getting a parallel one.
--
-- What genuinely does not exist is a place to put a reaction, and a column
-- that can honestly answer "when was this person last here".
--
-- SAFETY
--   * No existing table is dropped or rewritten.
--   * The legacy inquiry `messages` table is not touched.
--   * Both guards below make a second run a no-op rather than an error.
--   * Existing rows are untouched: last_seen_at is added NULL, which reads
--     as "never seen", which is the truth for everyone until they next load
--     a page.
-- ═══════════════════════════════════════════════════════════════════════

-- ─── Guards ────────────────────────────────────────────────────────────
-- Expect zero rows. Any row means that half has already run.
SELECT TABLE_NAME FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'message_reactions';

SELECT COLUMN_NAME FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'last_seen_at';


-- ═══ 1. Reactions ══════════════════════════════════════════════════════
--
-- One row per (message, user, emoji). The unique key is the whole feature:
-- it is what makes a reaction a toggle rather than a counter. Pressing 👍
-- twice does not produce two thumbs, because the second insert cannot exist
-- — the application deletes the row instead, and the database is what
-- guarantees that even if a double-submitted form gets past it.
--
-- The emoji is stored as the character itself in a utf8mb4 column, not as a
-- name or a shortcode. utf8mb4 is exactly the encoding that makes this safe
-- (it is why the schema has used it from the start), and storing the
-- character means no lookup table has to be kept in step with the picker.
-- What may be *sent* is still restricted server-side to a curated list —
-- see MESSAGE_REACTIONS in config/app.php — so this column cannot become a
-- dumping ground for arbitrary user text.
CREATE TABLE message_reactions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    message_id INT NOT NULL,
    user_id INT NOT NULL,

    -- Room for a multi-codepoint emoji (skin tone, ZWJ sequences) without
    -- room for a sentence.
    emoji VARCHAR(16) NOT NULL,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    -- CASCADE on both, and for the same reason the participant rows cascade:
    -- a reaction to a message that no longer exists, or by an account that no
    -- longer exists, is not history worth keeping. Note that a message is
    -- soft-deleted rather than removed, so withdrawing a message keeps its
    -- reactions — the renderer withholds them, exactly as it withholds the
    -- body.
    CONSTRAINT fk_mr_message
        FOREIGN KEY (message_id) REFERENCES conversation_messages(id) ON DELETE CASCADE,
    CONSTRAINT fk_mr_user
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,

    -- The toggle, enforced by the database.
    UNIQUE KEY uniq_mr_message_user_emoji (message_id, user_id, emoji),

    -- The only read path: "the reactions on these messages", asked once per
    -- rendered page of a thread.
    INDEX idx_mr_message (message_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ═══ 2. Presence ═══════════════════════════════════════════════════════
--
-- WHAT THIS COLUMN MEANS, EXACTLY
--   It is stamped when a signed-in user makes a request to this application.
--   That is all it can honestly claim, and the interface is written to say
--   only that much: someone who loaded a page ninety seconds ago is shown as
--   "Online", and someone who has been reading the same page for ten minutes
--   is shown as "Last seen 10m ago" — because as far as the server knows,
--   they left.
--
--   It is NOT a live presence signal. There is no socket, no heartbeat and no
--   polling, because this application has none of that infrastructure and
--   inventing it to colour a dot green would be a poor trade. A green dot
--   that lies is worse than an honest timestamp, which is why the display
--   rules in communicationPresence() are deliberately conservative.
--
--   last_login_at already exists and is a different fact: the last time
--   credentials were checked. It answers "when did they sign in", not "are
--   they here now", and using it for presence would show someone as online
--   for as long as their session lived.
--
-- Nullable, with no default: NULL means "not seen since this column was
-- added", which is true of every existing row and is rendered as no presence
-- information at all rather than as a guess.
ALTER TABLE users
    ADD COLUMN last_seen_at DATETIME DEFAULT NULL AFTER last_login_at,
    ADD INDEX idx_users_last_seen (last_seen_at);


-- ═══ Verification ══════════════════════════════════════════════════════
-- Expect one row.
SELECT TABLE_NAME, ENGINE, TABLE_COLLATION FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'message_reactions';

-- Expect two foreign keys and the unique key.
SELECT CONSTRAINT_NAME, COLUMN_NAME, REFERENCED_TABLE_NAME
FROM information_schema.KEY_COLUMN_USAGE
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'message_reactions'
ORDER BY CONSTRAINT_NAME, ORDINAL_POSITION;

-- Expect one row.
SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'last_seen_at';

-- Expect the legacy inquiry table untouched: 8 columns.
SELECT COUNT(*) AS legacy_messages_columns FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'messages';
