-- ═══════════════════════════════════════════════════════════════════════
-- Owner ⇄ User account link
--
-- The relationship already exists (owners.user_id → users.id, added with the
-- original schema and already read by the owner portal). Nothing here creates
-- or renames anything: the migration only tightens the existing column so the
-- business rule "one owner → one owner account" is enforced by the database
-- rather than by hope.
--
-- MySQL permits any number of NULLs in a UNIQUE index, which is exactly the
-- semantics wanted: unlimited owners without login access, but a given user
-- account can back at most one owner profile.
--
-- SAFETY
--   * No data is deleted or overwritten.
--   * Run database/tools/reconcile_owner_users.php FIRST — it reports (and,
--     with --apply, links) owners that already have a matching account.
--   * If the ALTER fails with errno 1062 you have two owner rows pointing at
--     the same user; the reconciliation report lists them for manual review.
-- ═══════════════════════════════════════════════════════════════════════

-- Guard: list any duplicate links before the ALTER. Expect an empty result.
SELECT user_id, COUNT(*) AS owner_rows, GROUP_CONCAT(id) AS owner_ids
FROM owners
WHERE user_id IS NOT NULL
GROUP BY user_id
HAVING COUNT(*) > 1;

-- The original foreign key sits on a plain index (`user_id`). The FK has to be
-- dropped before that index can be replaced, then restored unchanged — the
-- column, its type, and the ON DELETE SET NULL behaviour all stay as they were.
ALTER TABLE owners
    DROP FOREIGN KEY owners_ibfk_1;

ALTER TABLE owners
    DROP INDEX user_id,
    ADD UNIQUE KEY uniq_owners_user (user_id);

ALTER TABLE owners
    ADD CONSTRAINT owners_ibfk_1
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL;
