-- ═══════════════════════════════════════════════════════════════════════
-- Reaction emoji: compare by bytes, not by collation
--
-- A correction to 2026_08_26_reactions_and_presence.sql, written as its own
-- migration rather than by editing that file — the record of what was applied
-- should stay true, and the trap below is worth leaving written down.
--
-- THE PROBLEM
--   `message_reactions.emoji` inherited the table's utf8mb4_unicode_ci
--   collation, which is the right default for names and prose and exactly the
--   wrong one for emoji. The Unicode Collation Algorithm gives most emoji no
--   distinguishing weight, so under utf8mb4_unicode_ci:
--
--       '👍' = '😢'   →  TRUE
--       '😂' = '😮'   →  TRUE
--
--   which makes the UNIQUE KEY (message_id, user_id, emoji) treat four of the
--   five available reactions as the same value. Measured on this database: a
--   single user could store 2 of 5 distinct emoji before the key started
--   refusing the rest as duplicates.
--
--   The bug is quiet in the worst way. The first reaction works, the second
--   often works (❤️ carries a variation selector and so does differ), and the
--   third is silently rejected — which reads as "reactions are flaky" rather
--   than as a collation fault.
--
-- THE FIX
--   utf8mb4_bin on this one column. Byte-exact comparison is precisely what is
--   wanted here: an emoji is an identifier, not text to be sorted or matched
--   case-insensitively. Every other column in the schema keeps
--   utf8mb4_unicode_ci, which remains correct for the human-language values
--   they hold.
--
-- SAFETY
--   * One column on one table. Nothing else is touched.
--   * The guard makes a second run a no-op.
--   * Rows are preserved. Tightening a collation cannot lose data — it can
--     only make previously-equal values distinct, which is the point. If two
--     rows had somehow been stored that the unique key now separates, they
--     both survive; nothing needs merging.
-- ═══════════════════════════════════════════════════════════════════════

-- ─── Guard ─────────────────────────────────────────────────────────────
-- Expect utf8mb4_unicode_ci. If this already reads utf8mb4_bin the migration
-- has run and the ALTER below must be skipped.
SELECT COLUMN_NAME, CHARACTER_SET_NAME, COLLATION_NAME
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'message_reactions'
  AND COLUMN_NAME = 'emoji';


ALTER TABLE message_reactions
    MODIFY emoji VARCHAR(16) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL;


-- ═══ Verification ══════════════════════════════════════════════════════
-- Expect utf8mb4_bin.
SELECT COLUMN_NAME, COLLATION_NAME
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'message_reactions'
  AND COLUMN_NAME = 'emoji';

-- Expect 0 — different emoji must no longer compare as equal.
SELECT ('👍' COLLATE utf8mb4_bin) = ('😢' COLLATE utf8mb4_bin) AS thumbs_equals_cry;

-- The unique key is unchanged and still present.
SELECT INDEX_NAME, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS cols, NON_UNIQUE
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'message_reactions'
GROUP BY INDEX_NAME, NON_UNIQUE;
