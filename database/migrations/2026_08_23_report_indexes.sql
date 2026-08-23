-- ═══════════════════════════════════════════════════════════════════════
--  Reporting indexes
--  2026-08-23
--
--  Additive only. No column is added, altered or dropped, no row is touched,
--  and no existing index is removed — every statement below is a CREATE INDEX
--  and nothing else. Running this changes what the database has to read to
--  answer a report; it cannot change what any report answers.
--
--  Chosen from the queries that now exist, not from a list of columns that
--  looked important. Each one was checked with EXPLAIN first, and two
--  candidates were dropped from the plan because EXPLAIN showed they would
--  never be used — see the note at the foot of this file.
--
--  A word on what EXPLAIN currently says: with nine payments and seventeen
--  properties on file, the optimiser rejects every index here and scans the
--  table, because at this size a scan genuinely is cheaper. That is not an
--  argument against the indexes. Every access path below is one whose cost
--  grows linearly with the business, and the moment there are ten thousand
--  payments the report page is the first thing that stops being instant. The
--  index is written now, while the query shape is fresh and the reasoning is
--  written down, rather than during the incident.
--
--  IF NOT EXISTS is available from MariaDB 10.1.4; this instance is 10.4.32.
-- ═══════════════════════════════════════════════════════════════════════

-- ─── payments ──────────────────────────────────────────────────────────
--
-- The revenue definition approved after the audit is, in every report:
--
--     status = 'paid'  AND  payment_date <= today
--                      AND  payment_date BETWEEN … AND …
--                      AND  payment_type IN (…)
--
-- Equality on status, range on payment_date, so status leads: a composite
-- ordered the other way could not use the status predicate to seek. There is
-- an idx_pay_due on due_date and an idx_pay_status on status, and neither is
-- any use here — due_date is a different question, and status alone selects
-- most of the table.
--
-- Note that idx_pay_status becomes a left-prefix of this index and is now
-- redundant. It is deliberately left in place: dropping an index is not an
-- additive change and was not part of what was approved. It is listed in the
-- Phase 1 report as a candidate for a separate cleanup.
CREATE INDEX IF NOT EXISTS idx_pay_status_date ON payments (status, payment_date);

-- Splitting revenue into rental and sales is `reference_type = 'lease'` or
-- `= 'sale'` (approved decision 3), and walking from a lease or a sale to the
-- payments taken against it is the same pair of columns the other way round.
-- Neither was indexed at all.
CREATE INDEX IF NOT EXISTS idx_pay_reference ON payments (reference_type, reference_id);

-- ─── leases ────────────────────────────────────────────────────────────
--
-- The hottest new access path in the whole rebuild. Occupancy, the inventory
-- breakdown and every agent's active book all ask the same question, once per
-- property:
--
--     EXISTS (SELECT 1 FROM leases
--              WHERE property_id = ? AND status = 'active' AND end_date >= ?)
--
-- EXPLAIN currently reports `possible_keys: property_id, idx_lease_status` and
-- `key: NULL` — it can see both single-column indexes and use neither, because
-- it can only pick one. This composite answers the whole predicate from the
-- index, and with the end_date on the tail it never touches the row at all.
CREATE INDEX IF NOT EXISTS idx_lease_prop_status_end ON leases (property_id, status, end_date);

-- ─── sales ─────────────────────────────────────────────────────────────
--
-- Approved decision 5 makes a completed sale the reliable proof that a
-- property is sold, so both occupancy and the inventory breakdown now run an
-- EXISTS on (property_id, status) for every property. `property_id` alone
-- exists; the status has to be checked against the row without this.
CREATE INDEX IF NOT EXISTS idx_sale_prop_status ON sales (property_id, status);

-- Completed sales inside a window, for agent performance and for the sales
-- analytics of a later phase. Equality on status, range on sale_date, so
-- status leads for the same reason it does on payments.
CREATE INDEX IF NOT EXISTS idx_sale_status_date ON sales (status, sale_date);

-- ─── reservations ──────────────────────────────────────────────────────
--
-- "Reserved" as a commercial state is a live hold — active or confirmed, and
-- not yet expired — which is three columns and one existing index on the
-- first of them.
CREATE INDEX IF NOT EXISTS idx_resv_prop_status_expiry ON reservations (property_id, status, expiry_date);

-- ═══════════════════════════════════════════════════════════════════════
--  Considered and deliberately NOT created
--
--  payment_schedules (due_date, status)
--      Proposed in the Phase 1 brief, and EXPLAIN says it would never be
--      used. The rent ledger query joins payment_schedules to leases to
--      properties and then aggregates the whole scoped set with conditional
--      SUMs — expected, settled, arrears and outstanding all come out of one
--      pass. There is no WHERE predicate on due_date or status to seek on;
--      the query is driven by the lease join, which EXPLAIN shows already
--      resolving through the existing `lease_id` index with `Using index`.
--      Adding this would be adding an index for a query nobody has written.
--
--  maintenance_requests (status, created_at)
--      Also proposed, also premature. Nothing in Phase 1 reads
--      maintenance_requests at all. The index belongs with the maintenance
--      analytics, where its shape can be chosen from the query that needs it
--      rather than guessed at now.
--
--  properties (is_archived, status)
--      The register's own breakdown scans a seventeen-row table and will
--      scan a ten-thousand-row one just as happily, because it GROUPs the
--      whole scoped set rather than selecting part of it. idx_prop_archived
--      already leads on is_archived for the archive list, which is the query
--      that actually filters.
-- ═══════════════════════════════════════════════════════════════════════
