-- ─────────────────────────────────────────────────────────────────────────
-- Document Management — 2026-08-16
--
-- Turns the `documents` table (present since the first schema, never wired to
-- any code) into a working module:
--   1. document_categories — configurable, replaces the hard-coded doc_type ENUM
--   2. documents           — metadata, visibility, status and archive columns
--   3. settings            — expiry warning window, size cap, public switch
--
-- There is no migration runner in this project: import by hand
-- (phpMyAdmin → SQL tab). Safe to run more than once.
--
-- FILESYSTEM STEP — already applied in the same change, listed here so a
-- fresh deployment knows the database alone is not enough:
--   storage/.htaccess, storage/documents/.htaccess, storage/documents/index.php
--   and  RewriteRule ^storage/ - [F,L]  in the root .htaccess.
-- Uploaded documents live in storage/documents/ and are NEVER served by
-- Apache — index.php?page=documents&action=download authorises every read.
-- ─────────────────────────────────────────────────────────────────────────

-- ── 1. Configurable categories ──────────────────────────────────────────
-- Admins add, rename, reorder and deactivate these without touching code,
-- which is the whole reason doc_type stops being an ENUM.
CREATE TABLE IF NOT EXISTS document_categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    slug VARCHAR(100) NOT NULL,
    description VARCHAR(255) DEFAULT '',
    icon VARCHAR(50) DEFAULT 'bi-file-earmark-text',
    default_visibility ENUM('private','staff','public') NOT NULL DEFAULT 'staff',
    requires_expiry TINYINT(1) NOT NULL DEFAULT 0,
    sort_order INT NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_doccat_slug (slug),
    INDEX idx_doccat_active (is_active, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- The collation is pinned rather than inherited: this database defaults to
-- utf8mb4_general_ci while every table in schema.sql is utf8mb4_unicode_ci,
-- so an inherited default would make `slug = doc_type` in the backfill below
-- fail with "illegal mix of collations".

-- The seed mirrors the old doc_type ENUM one-for-one so a legacy row can be
-- mapped by slug, plus the document types the request named that the ENUM
-- never had (title deed, permit, valuation, tax, floor plan).
INSERT INTO document_categories (name, slug, description, icon, default_visibility, requires_expiry, sort_order) VALUES
('Legal Document',         'legal_document',     'General legal paperwork held on file.',        'bi-bank',                     'private', 0,  10),
('Title Deed',             'title_deed',         'Registered title to the property.',            'bi-file-earmark-lock2',       'private', 0,  20),
('Ownership Certificate',  'ownership',          'Proof of ownership.',                          'bi-patch-check',              'private', 0,  30),
('Rental Agreement',       'rental_agreement',   'Signed tenancy agreements.',                   'bi-file-earmark-text',        'staff',   1,  40),
('Sale Contract',          'sale_contract',      'Executed contracts of sale.',                  'bi-file-earmark-ruled',       'private', 0,  50),
('Permit',                 'permit',             'Building, occupancy and trading permits.',     'bi-card-checklist',           'staff',   1,  60),
('Inspection Report',      'inspection',         'Condition, safety and handover reports.',      'bi-clipboard-check',          'staff',   1,  70),
('Valuation Report',       'valuation',          'Professional valuations and appraisals.',      'bi-graph-up-arrow',           'staff',   1,  80),
('Tax Document',           'tax_document',       'Assessments, receipts and filings.',           'bi-receipt',                  'private', 1,  90),
('Floor Plan',             'floor_plan',         'Plans published with the listing.',            'bi-rulers',                   'public',  0, 100),
('ID Copy',                'id_copy',            'Identity documents.',                          'bi-person-vcard',             'private', 0, 110),
('Invoice',                'invoice',            'Issued invoices.',                             'bi-file-earmark-spreadsheet', 'staff',   0, 120),
('Maintenance Report',     'maintenance_report', 'Works carried out on site.',                   'bi-tools',                    'staff',   0, 130),
('Correspondence',         'letter',             'Letters and formal notices.',                  'bi-envelope-paper',           'staff',   0, 140),
('Other',                  'other',              'Anything that does not fit a category.',       'bi-file-earmark',             'staff',   0, 999)
ON DUPLICATE KEY UPDATE slug = slug;

-- ── 2a. New columns on `documents` ──────────────────────────────────────
-- category_id is the sentinel for the whole block: it is one ALTER, so either
-- every column arrives or none does, and a re-run is a no-op.
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'documents'
             AND COLUMN_NAME = 'category_id');
SET @s := IF(@c = 0,
'ALTER TABLE documents
   ADD COLUMN category_id   INT DEFAULT NULL                    AFTER id,
   ADD COLUMN document_code VARCHAR(20) DEFAULT NULL            AFTER category_id,
   ADD COLUMN description   TEXT NULL                           AFTER title,
   ADD COLUMN doc_number    VARCHAR(100) DEFAULT NULL           AFTER description,
   ADD COLUMN document_date DATE DEFAULT NULL                   AFTER doc_number,
   ADD COLUMN file_name     VARCHAR(255) NOT NULL DEFAULT ''''  AFTER file_path,
   ADD COLUMN file_type     VARCHAR(100) NOT NULL DEFAULT ''''  AFTER file_name,
   ADD COLUMN file_ext      VARCHAR(10)  NOT NULL DEFAULT ''''  AFTER file_type,
   ADD COLUMN file_size     INT UNSIGNED NOT NULL DEFAULT 0     AFTER file_ext,
   ADD COLUMN checksum      CHAR(64) DEFAULT NULL               AFTER file_size,
   ADD COLUMN visibility    ENUM(''private'',''staff'',''public'') NOT NULL DEFAULT ''staff'' AFTER expiry_date,
   ADD COLUMN status        ENUM(''active'',''archived'')          NOT NULL DEFAULT ''active'' AFTER visibility,
   ADD COLUMN archived_at   DATETIME DEFAULT NULL               AFTER status,
   ADD COLUMN archived_by   INT DEFAULT NULL                    AFTER archived_at,
   ADD COLUMN updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at',
'SELECT "documents: metadata columns already present" AS note');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ── 2b. doc_type becomes a legacy, nullable column ──────────────────────
-- It is NOT NULL with no default today, so every insert would fail until this
-- runs. Kept rather than dropped so a pre-existing row still shows what it was
-- originally filed as; category_id is what the code reads from now on.
SET @c := (SELECT IS_NULLABLE FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'documents'
             AND COLUMN_NAME = 'doc_type');
SET @s := IF(@c = 'NO',
'ALTER TABLE documents MODIFY COLUMN doc_type
   ENUM(''ownership'',''lease'',''contract'',''id_copy'',''receipt'',''invoice'',
        ''inspection'',''maintenance_report'',''letter'',''other'') NULL DEFAULT NULL',
'SELECT "documents.doc_type already nullable" AS note');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ── 2c. Indexes ─────────────────────────────────────────────────────────
SET @c := (SELECT COUNT(*) FROM information_schema.STATISTICS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'documents' AND INDEX_NAME = 'uq_doc_code');
SET @s := IF(@c = 0, 'ALTER TABLE documents ADD UNIQUE KEY uq_doc_code (document_code)',
                     'SELECT "uq_doc_code already present" AS note');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (SELECT COUNT(*) FROM information_schema.STATISTICS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'documents' AND INDEX_NAME = 'idx_doc_reference');
SET @s := IF(@c = 0, 'ALTER TABLE documents ADD INDEX idx_doc_reference (reference_type, reference_id)',
                     'SELECT "idx_doc_reference already present" AS note');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (SELECT COUNT(*) FROM information_schema.STATISTICS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'documents' AND INDEX_NAME = 'idx_doc_category');
SET @s := IF(@c = 0, 'ALTER TABLE documents ADD INDEX idx_doc_category (category_id)',
                     'SELECT "idx_doc_category already present" AS note');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (SELECT COUNT(*) FROM information_schema.STATISTICS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'documents' AND INDEX_NAME = 'idx_doc_status_expiry');
SET @s := IF(@c = 0, 'ALTER TABLE documents ADD INDEX idx_doc_status_expiry (status, expiry_date)',
                     'SELECT "idx_doc_status_expiry already present" AS note');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (SELECT COUNT(*) FROM information_schema.STATISTICS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'documents' AND INDEX_NAME = 'idx_doc_visibility');
SET @s := IF(@c = 0, 'ALTER TABLE documents ADD INDEX idx_doc_visibility (visibility)',
                     'SELECT "idx_doc_visibility already present" AS note');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ── 2d. Foreign keys ────────────────────────────────────────────────────
-- RESTRICT on the category: a category holding documents can be deactivated,
-- never deleted out from under them.
SET @c := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
           WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'documents'
             AND CONSTRAINT_NAME = 'fk_doc_category');
SET @s := IF(@c = 0,
'ALTER TABLE documents ADD CONSTRAINT fk_doc_category
   FOREIGN KEY (category_id) REFERENCES document_categories(id) ON DELETE RESTRICT',
'SELECT "fk_doc_category already present" AS note');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
           WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'documents'
             AND CONSTRAINT_NAME = 'fk_doc_archiver');
SET @s := IF(@c = 0,
'ALTER TABLE documents ADD CONSTRAINT fk_doc_archiver
   FOREIGN KEY (archived_by) REFERENCES users(id) ON DELETE SET NULL',
'SELECT "fk_doc_archiver already present" AS note');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ── 3. Backfill any pre-existing rows ───────────────────────────────────
-- The table has had no writer, so this is normally a no-op; it exists so an
-- install that hand-inserted rows is not left with nulls.
UPDATE documents d
  JOIN document_categories c ON c.slug = d.doc_type
   SET d.category_id = c.id
 WHERE d.category_id IS NULL AND d.doc_type IS NOT NULL;

UPDATE documents
   SET category_id = (SELECT id FROM document_categories WHERE slug = 'other')
 WHERE category_id IS NULL;

-- is_restricted was the old, unenforced flag; visibility is what the code
-- reads now, so an existing restricted document stays restricted.
UPDATE documents
   SET visibility = 'private'
 WHERE is_restricted = 1 AND visibility = 'staff';

UPDATE documents
   SET document_code = CONCAT('DOC-', DATE_FORMAT(created_at, '%Y%m%d'), '-', LPAD(id, 5, '0'))
 WHERE document_code IS NULL OR document_code = '';

-- ── 4. Settings ─────────────────────────────────────────────────────────
-- SettingsController::update() only writes keys that already exist in this
-- table, so these rows are what makes the new Documents panel editable at all.
INSERT INTO settings (setting_key, setting_value, setting_group) VALUES
('document_expiry_warning_days', '30', 'documents'),
('document_max_size_mb',         '10', 'documents'),
('document_public_enabled',      '1',  'documents')
ON DUPLICATE KEY UPDATE setting_key = setting_key;
