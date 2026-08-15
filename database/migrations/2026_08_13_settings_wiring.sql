-- ─────────────────────────────────────────────────────────────────────────
-- Settings wiring — 2026-08-13
--
-- Connects the settings table to the code that should have been reading it,
-- and adjusts the stored rows to match:
--   1. company_legal_name  — new; used by contracts and schema.org markup
--   2. sales.tax_amount    — new; makes Settings → Financial → Tax Rate real
--   3. notification_email_enabled — removed; there is no mail layer to gate
--   4. un-escapes identity values written by the old double-escaping save
--
-- Safe to run more than once.
-- ─────────────────────────────────────────────────────────────────────────

-- ── 1. Registered/legal company name ────────────────────────────────────
INSERT INTO settings (setting_key, setting_value, setting_group)
VALUES ('company_legal_name', '', 'general')
ON DUPLICATE KEY UPDATE setting_key = setting_key;

-- ── 2. Tax on sales ─────────────────────────────────────────────────────
-- tax_rate is stored per sale, not just the amount, so a receipt reprinted
-- after the configured rate changes still shows what was actually charged.
SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sales'
               AND COLUMN_NAME = 'tax_amount');
SET @sql := IF(@col = 0,
    'ALTER TABLE sales
        ADD COLUMN tax_amount DECIMAL(15,2) DEFAULT 0 AFTER sale_amount,
        ADD COLUMN tax_rate   DECIMAL(5,2)  DEFAULT 0 AFTER tax_amount',
    'SELECT "sales.tax_amount already present" AS note');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ── 3. Drop the setting with no consumer ────────────────────────────────
DELETE FROM settings WHERE setting_key = 'notification_email_enabled';

-- ── 4. Undo the old double-escaping ─────────────────────────────────────
-- The settings table stores raw text; values are escaped when printed. The
-- previous save handler ran them through htmlspecialchars() on the way in,
-- so "Ali & Sons" accumulated &amp; on every save.
UPDATE settings
   SET setting_value = REPLACE(
         REPLACE(
           REPLACE(
             REPLACE(setting_value, '&amp;', '&'),
           '&quot;', '"'),
         '&#039;', ''''),
       '&lt;', '<')
 WHERE setting_key IN ('company_name', 'company_legal_name', 'company_address',
                       'company_phone', 'company_email', 'currency_symbol')
   AND setting_value REGEXP '&(amp|quot|#039|lt);';
