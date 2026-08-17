-- ═══════════════════════════════════════════════════════════════════════
-- Maintenance request scope — database-level guard
--
-- The application already refuses to file a request against a property the
-- reporting user has no relationship with: MaintenanceRequest::create()
-- writes through an INSERT ... SELECT whose WHERE clause carries the scope
-- from includes/property_access.php, so an out-of-scope property id matches
-- no row and nothing is inserted.
--
-- This trigger is the second lock on the same door. It re-checks the rule
-- inside the server, against whatever wrote the row — the app, a console,
-- an import script, a future report tool. `reported_by` is stored on every
-- request, so the database can answer "is this property that user's?"
-- without knowing anything about PHP sessions.
--
-- WHAT IT ENFORCES  (owner and tenant are the rules that matter; both are
-- refused outright when the property is not theirs)
--   owner     → properties.owner_id must resolve to their owners row
--   customer  → an ACTIVE lease must link them to the property
--   agent     → properties.agent_id must be them
--   admin     → unrestricted
--   maintenance / NULL reporter → not checked here; the app scopes those to
--     the technician's open jobs. Left out on purpose so a back-office
--     script that files on behalf of the system is not blocked.
--
-- SAFETY
--   * Adds nothing to any table; no column, row or index is touched.
--   * Existing rows are never revalidated — BEFORE INSERT only, so historical
--     data that predates the rule stays exactly as it is.
--   * Updates are not covered: the app's update path cannot move a request
--     between properties (property_id is not in the model's writable column
--     whitelist).
--   * Fully reversible — see the rollback at the bottom.
--
-- RUNNING IT
--   phpMyAdmin handles the DELIMITER lines itself. From the CLI:
--     mysql -u root saxane_realestate < 2026_08_16_maintenance_request_scope.sql
-- ═══════════════════════════════════════════════════════════════════════

DROP TRIGGER IF EXISTS trg_maintenance_requests_scope_bi;

DELIMITER $$

CREATE TRIGGER trg_maintenance_requests_scope_bi
BEFORE INSERT ON maintenance_requests
FOR EACH ROW
BEGIN
    DECLARE v_role  VARCHAR(50) DEFAULT NULL;
    DECLARE v_match INT DEFAULT 0;

    IF NEW.reported_by IS NOT NULL THEN
        SELECT r.name INTO v_role
        FROM users u
        JOIN roles r ON u.role_id = r.id
        WHERE u.id = NEW.reported_by
        LIMIT 1;

        IF v_role = 'owner' THEN
            SELECT COUNT(*) INTO v_match
            FROM properties p
            JOIN owners o ON p.owner_id = o.id
            WHERE p.id = NEW.property_id
              AND o.user_id = NEW.reported_by;

            IF v_match = 0 THEN
                SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = 'Maintenance request refused: an owner may only report issues for a property they own.';
            END IF;

        ELSEIF v_role = 'customer' THEN
            SELECT COUNT(*) INTO v_match
            FROM leases l
            JOIN customers c ON l.customer_id = c.id
            WHERE l.property_id = NEW.property_id
              AND c.user_id = NEW.reported_by
              AND l.status = 'active';

            IF v_match = 0 THEN
                SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = 'Maintenance request refused: a tenant may only report issues for a property they currently lease.';
            END IF;

        ELSEIF v_role = 'agent' THEN
            SELECT COUNT(*) INTO v_match
            FROM properties p
            WHERE p.id = NEW.property_id
              AND p.agent_id = NEW.reported_by;

            IF v_match = 0 THEN
                SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = 'Maintenance request refused: an agent may only report issues for a property assigned to them.';
            END IF;
        END IF;
    END IF;
END$$

DELIMITER ;

-- ─── Verification ──────────────────────────────────────────────────────
-- Expect one row named trg_maintenance_requests_scope_bi.
SHOW TRIGGERS LIKE 'maintenance_requests';


-- ═══════════════════════════════════════════════════════════════════════
-- PART 2 — one customer profile per account  (run this second, and only
--          after the reconciliation below reports no duplicates)
--
-- A tenant's property is resolved through customers.user_id. If two customer
-- rows ever pointed at the same account, that resolution becomes arbitrary —
-- the tenant would be granted whichever tenancy the database happened to
-- return first. This is the same rule already applied to owners in
-- 2026_08_16_owner_user_link.sql, and MySQL's treatment of NULL in a UNIQUE
-- index gives exactly the semantics wanted: unlimited customers without
-- login access, but at most one customer profile per account.
--
-- ORDER OF OPERATIONS
--   1. php database/tools/reconcile_customer_users.php          (report only)
--   2. php database/tools/reconcile_customer_users.php --apply  (write links)
--   3. the guard query below — expect an empty result
--   4. the ALTER
--
-- If the ALTER fails with errno 1062, two customer rows share an account;
-- the guard query lists them for manual review.
-- ═══════════════════════════════════════════════════════════════════════

-- Guard: expect zero rows.
SELECT user_id, COUNT(*) AS customer_rows, GROUP_CONCAT(id) AS customer_ids
FROM customers
WHERE user_id IS NOT NULL
GROUP BY user_id
HAVING COUNT(*) > 1;

-- The existing foreign key sits on a plain index and has to be dropped before
-- that index can be replaced, then restored unchanged — the column, its type
-- and the ON DELETE SET NULL behaviour all stay as they were.
ALTER TABLE customers
    DROP FOREIGN KEY customers_ibfk_1;

ALTER TABLE customers
    DROP INDEX user_id,
    ADD UNIQUE KEY uniq_customers_user (user_id);

ALTER TABLE customers
    ADD CONSTRAINT customers_ibfk_1
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL;


-- ─── Rollback ──────────────────────────────────────────────────────────
-- The application-level scope keeps working without either part.
--   DROP TRIGGER IF EXISTS trg_maintenance_requests_scope_bi;
--
--   ALTER TABLE customers DROP FOREIGN KEY customers_ibfk_1;
--   ALTER TABLE customers
--       DROP INDEX uniq_customers_user,
--       ADD INDEX user_id (user_id);
--   ALTER TABLE customers
--       ADD CONSTRAINT customers_ibfk_1
--           FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL;
