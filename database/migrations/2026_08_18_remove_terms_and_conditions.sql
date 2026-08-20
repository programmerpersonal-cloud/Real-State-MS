-- ─────────────────────────────────────────────────────────────────────────
-- Remove Terms & Conditions — 2026-08-18
--
-- Rolls back 2026_08_16_terms_and_conditions.sql. The module it backed (the
-- admin "Terms & Legal" page, its controller, models and views) has been
-- removed from the codebase, so these tables and settings no longer have a
-- reader. Left in place they would keep appearing in the Settings screen as
-- an unlabelled group, and keep three tables in the schema that nothing
-- writes to.
--
-- WHAT THIS DELETES — read before running:
--   terms_acceptances holds the record of who accepted which exact wording.
--   If the business has a retention obligation over that, export it first:
--
--     SELECT a.*, v.version_code, v.title, d.slug
--       FROM terms_acceptances a
--       JOIN terms_versions  v ON v.id = a.terms_version_id
--       JOIN terms_documents d ON d.id = v.terms_document_id;
--
--   The audit_logs rows written by the module (created_terms_version,
--   accepted_terms, …) are deliberately NOT touched. The trail is a record
--   of what happened, and what happened is not undone by a feature being
--   withdrawn. They render as plain text now that the entity has no page.
--
-- Drop order follows the foreign keys: acceptances reference versions, and
-- versions reference documents, both ON DELETE RESTRICT.
--
-- Import by hand (phpMyAdmin → SQL tab). Safe to run more than once.
-- ─────────────────────────────────────────────────────────────────────────

-- ── 1. Tables ───────────────────────────────────────────────────────────
DROP TABLE IF EXISTS terms_acceptances;
DROP TABLE IF EXISTS terms_versions;
DROP TABLE IF EXISTS terms_documents;

-- ── 2. Settings ─────────────────────────────────────────────────────────
-- Both keys were read only by the removed module. Deleting the rows is what
-- takes the "Legal" group off the Settings screen: that screen renders every
-- group present in this table, whether or not the controller declares one.
DELETE FROM settings
 WHERE setting_key IN ('terms_require_on_reservation', 'terms_public_slug');
