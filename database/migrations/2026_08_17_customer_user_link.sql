-- ═══════════════════════════════════════════════════════════════════════
-- Customer ⇄ User account link
--
-- The tenant/buyer half of 2026_08_16_owner_user_link.sql, and it exists for
-- the same reason. The relationship already exists (customers.user_id →
-- users.id, part of the original schema and already read by currentCustomerId()
-- in includes/property_access.php). Nothing here creates or renames anything:
-- the migration only tightens the existing column so the business rule
-- "one customer → one customer account" is enforced by the database rather
-- than by hope.
--
-- Without it, two customer rows may point at the same account. That is exactly
-- how the Customers list and the Users & Roles list came to describe the same
-- person twice — and worse, currentCustomerId() picks whichever row the engine
-- returns first, so a tenant could be shown another tenant's lease.
--
-- MySQL permits any number of NULLs in a UNIQUE index, which is exactly the
-- semantics wanted: unlimited customers without login access — most tenants
-- never sign in — but a given user account backs at most one customer profile.
--
-- SAFETY
--   * No data is deleted or overwritten.
--   * Run database/tools/reconcile_customer_users.php FIRST — it reports (and,
--     with --apply, links) customers that already have a matching account.
--   * If the ALTER fails with errno 1062 you have two customer rows pointing at
--     the same user; the guard query below lists them for manual review.
-- ═══════════════════════════════════════════════════════════════════════

-- Guard 1: list any duplicate links before the ALTER. Expect an empty result.
SELECT user_id, COUNT(*) AS customer_rows, GROUP_CONCAT(id) AS customer_ids
FROM customers
WHERE user_id IS NOT NULL
GROUP BY user_id
HAVING COUNT(*) > 1;

-- Guard 2: confirm the foreign key's name on this install before dropping it.
-- The schema declares user_id first, so MySQL normally names it
-- `customers_ibfk_1` — verify rather than assume.
SELECT CONSTRAINT_NAME, COLUMN_NAME
FROM information_schema.KEY_COLUMN_USAGE
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'customers'
  AND REFERENCED_TABLE_NAME = 'users'
  AND COLUMN_NAME = 'user_id';

-- The original foreign key sits on a plain index (`user_id`). The FK has to be
-- dropped before that index can be replaced, then restored unchanged — the
-- column, its type, and the ON DELETE SET NULL behaviour all stay as they were.
-- ON DELETE SET NULL is what keeps a deleted account from taking the tenancy,
-- the payment history and the lease down with it: the customer record survives,
-- it simply stops having a login.
ALTER TABLE customers
    DROP FOREIGN KEY customers_ibfk_1;

ALTER TABLE customers
    DROP INDEX user_id,
    ADD UNIQUE KEY uniq_customers_user (user_id);

ALTER TABLE customers
    ADD CONSTRAINT customers_ibfk_1
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL;
