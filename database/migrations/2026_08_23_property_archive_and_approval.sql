-- ═══════════════════════════════════════════════════════════════════════
--  Property archive & approval workflow
--  2026-08-23
--
--  Two workflows the register already half-had and could not finish.
--
--  Archive was a one-way door: `is_archived = 1` with the status forced to
--  'inactive', and nothing anywhere to bring a property back — the status it
--  held before archiving was overwritten, so even a manual UPDATE could not
--  restore it faithfully. `status_before_archive` keeps that value so a
--  restore returns the property to the register in the state it left it, and
--  archived_at/archived_by record who filed it away and when.
--
--  Approval was a column with no workflow: `approval_status` defaulted to
--  'pending' for everybody, was never gated on, and the only way to move it
--  was a menu item that wrote the word 'approved' and recorded nothing.
--  approved_by/approved_at make the decision auditable, approval_note carries
--  a rejection's reason back to the agent, and created_by records who
--  submitted the listing (agent_id is the assignment, which an administrator
--  can change — it is not a signature).
--
--  The two are deliberately independent: archiving does not touch the
--  approval columns and restoring does not re-approve.
-- ═══════════════════════════════════════════════════════════════════════

ALTER TABLE properties
    ADD COLUMN created_by  INT DEFAULT NULL AFTER branch_id,
    ADD COLUMN approved_by INT DEFAULT NULL AFTER approval_status,
    ADD COLUMN approved_at DATETIME DEFAULT NULL AFTER approved_by,
    ADD COLUMN approval_note TEXT DEFAULT NULL AFTER approved_at,
    ADD COLUMN archived_at DATETIME DEFAULT NULL AFTER is_archived,
    ADD COLUMN archived_by INT DEFAULT NULL AFTER archived_at,
    -- Plain VARCHAR rather than a second copy of the status ENUM: this holds
    -- a historical value, and it must not need an ALTER every time the live
    -- ENUM gains a member.
    ADD COLUMN status_before_archive VARCHAR(20) DEFAULT NULL AFTER archived_by;

-- The archive list and the approval queue are both "one flag, newest first".
-- Without these each is a full scan of the register.
ALTER TABLE properties
    ADD INDEX idx_prop_approval (approval_status, is_archived, created_at),
    ADD INDEX idx_prop_archived (is_archived, archived_at);

ALTER TABLE properties
    ADD CONSTRAINT fk_prop_created_by  FOREIGN KEY (created_by)  REFERENCES users(id) ON DELETE SET NULL,
    ADD CONSTRAINT fk_prop_approved_by FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL,
    ADD CONSTRAINT fk_prop_archived_by FOREIGN KEY (archived_by) REFERENCES users(id) ON DELETE SET NULL;

-- ─── Backfill ──────────────────────────────────────────────────────────
--
-- Every property that existed before this migration was live: the column
-- defaulted to 'pending' but nothing read it, so the register and the public
-- site showed those rows regardless. Now that approval actually gates public
-- visibility, leaving them 'pending' would empty the site and drop the whole
-- back-catalogue into the approval queue. They are marked approved with their
-- creation date, and no approver — nobody actually pressed the button.
UPDATE properties
   SET approval_status = 'approved',
       approved_at = created_at
 WHERE approval_status = 'pending';

-- Archived rows keep the status they now carry; there is no earlier value to
-- recover for them. Recording the archive time as the last update is the
-- closest honest answer, and it stops the archive list showing "—" for every
-- historical row.
UPDATE properties
   SET archived_at = updated_at
 WHERE is_archived = 1 AND archived_at IS NULL;
