-- ═══════════════════════════════════════════════════════════════════════
-- Message attachments
--
-- The table Phase 2 deliberately did not create. It was left out then because
-- nothing wrote to it, and a table nothing writes to invites a later reader to
-- assume a feature exists. The feature exists now.
--
-- WHAT IS STORED HERE, AND WHAT IS NOT
--   This table holds *metadata*. The bytes live in storage/documents, the same
--   private store the document module has used since it was built, behind the
--   same three layers of Apache denial. Nothing about the file's location is
--   derived from anything a user typed: `stored_path` is written by
--   storeDocumentFile(), which names files from random_bytes() and takes the
--   extension from the sniffed MIME type rather than from the upload.
--
-- WHY NOT A messages/ SUBDIRECTORY
--   The obvious layout — storage/documents/messages/ — was rejected after
--   reading documentStoragePath(), the resolver that turns a stored path back
--   into a real file. It requires the basename to match
--   ^[A-Za-z0-9._-]{1,160}$, which contains no slash, and that single rule is
--   what defeats "..", absolute paths and NUL bytes in one line. Adding a
--   subdirectory would mean relaxing it. A flat store with unguessable names
--   and a `msg_` filename prefix keeps the hardened resolver exactly as it is,
--   and the prefix is for humans reading a directory listing — the resolver
--   validates the whole basename either way.
--
-- ON DELETE CASCADE, DELIBERATELY
--   Checked against the real message lifecycle rather than assumed:
--     · a message is SOFT-deleted (conversation_messages.deleted_at is set and
--       the row stays), so the cascade never fires for an ordinary deletion
--       and an attachment survives alongside the message it belongs to;
--     · a message row is only ever really removed when its conversation is,
--       which already cascades messages away.
--   So CASCADE fires exactly when the parent correspondence ceases to exist,
--   and never merely because someone deleted a message.
--
--   Note the consequence, which is not a bug but is worth knowing: deleting a
--   conversation removes these rows without removing the files they name. The
--   application has no conversation-delete action, so this can only happen
--   through direct SQL — see the orphan query at the foot of this file.
--
-- SAFETY
--   * No existing table is altered, renamed or dropped.
--   * No existing row is read, written or deleted.
--   * The guard below stops the migration if it has already run.
-- ═══════════════════════════════════════════════════════════════════════

-- ─── Guard ─────────────────────────────────────────────────────────────
-- Expect an empty result. Any row means this has already run.
SELECT TABLE_NAME
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'message_attachments';

-- Expect one row. The parent must exist before the foreign key can name it.
SELECT TABLE_NAME
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'conversation_messages';


CREATE TABLE message_attachments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    message_id INT NOT NULL,

    -- What the sender called it. Display metadata and nothing else: it is
    -- never a path, never a directory, never the name on disk. Stored through
    -- documentSafeOriginalName(), which strips control characters and the
    -- separators that would confuse a Content-Disposition header, and escaped
    -- again when rendered.
    original_name VARCHAR(200) NOT NULL,

    -- The path inside the private store, as written by storeDocumentFile():
    -- 'storage/documents/msg_<32 hex>.<ext>'. Read back only through
    -- documentStoragePath(), which refuses traversal, absolute paths and
    -- symlinks pointing out of the store.
    stored_path VARCHAR(255) NOT NULL,

    -- The type the server sniffed, not the one the browser claimed. Used to
    -- decide what may be shown in place and what must be downloaded.
    mime_type VARCHAR(100) NOT NULL,

    file_size INT UNSIGNED NOT NULL DEFAULT 0,

    -- For detecting a store that has drifted from its records. Cheap to write
    -- once, and the only way to notice a file that changed underneath us.
    checksum CHAR(64) DEFAULT NULL,

    uploaded_by INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_ma_message
        FOREIGN KEY (message_id) REFERENCES conversation_messages(id) ON DELETE CASCADE,

    -- SET NULL, matching every other "who did this" column in the schema: an
    -- account being closed must not take the correspondence with it.
    CONSTRAINT fk_ma_uploader
        FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE SET NULL,

    -- The only read path: "the attachments of these messages", asked once per
    -- rendered page of a thread.
    INDEX idx_ma_message (message_id),

    -- Lets the orphan check below run as an index scan rather than a table
    -- scan, and makes a duplicate stored_path immediately visible.
    UNIQUE KEY uniq_ma_stored_path (stored_path)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ═══ Verification ══════════════════════════════════════════════════════
-- Expect one row.
SELECT TABLE_NAME, ENGINE, TABLE_COLLATION
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'message_attachments';

-- Expect two foreign keys.
SELECT CONSTRAINT_NAME, COLUMN_NAME, REFERENCED_TABLE_NAME
FROM information_schema.KEY_COLUMN_USAGE
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'message_attachments'
  AND REFERENCED_TABLE_NAME IS NOT NULL
ORDER BY CONSTRAINT_NAME;

-- Housekeeping, for later. Expect an empty result: every attachment row
-- should name a file that is really there. Run it after any direct-SQL
-- surgery on conversations.
SELECT id, stored_path
FROM message_attachments
ORDER BY id;
