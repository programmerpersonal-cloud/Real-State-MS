-- ─────────────────────────────────────────────────────────────────────────
-- Terms & Conditions versioning — 2026-08-16
--
--   terms_documents   — the configurable legal types (rental, sale, viewing,
--                       booking, general). Admins manage these; the slug is
--                       the contract with the code and must not be renamed.
--   terms_versions    — every version ever written. Only one may be active
--                       per type, enforced by the database, not just by PHP.
--   terms_acceptances — immutable proof of who accepted which exact wording.
--
-- Why this is separate from the documents module: property documents are
-- uploaded files with metadata; terms are versioned content with a legal
-- retention obligation. They change for different reasons, so they are
-- different tables with different rules.
--
-- Import by hand (phpMyAdmin → SQL tab). Safe to run more than once.
-- ─────────────────────────────────────────────────────────────────────────

-- ── 1. Legal document types ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS terms_documents (
    id INT AUTO_INCREMENT PRIMARY KEY,
    slug VARCHAR(50) NOT NULL,
    name VARCHAR(120) NOT NULL,
    description VARCHAR(255) DEFAULT '',
    requires_acceptance TINYINT(1) NOT NULL DEFAULT 1,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    sort_order INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_termsdoc_slug (slug),
    INDEX idx_termsdoc_active (is_active, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- Collation pinned to match schema.sql: this database defaults to
-- utf8mb4_general_ci, and an inherited default would break any join or
-- comparison against the existing utf8mb4_unicode_ci tables.

-- 'booking' is what the reservation form asks for and 'general' is what the
-- public terms page renders, so those two slugs are load-bearing. Renaming a
-- type in the admin UI is fine; changing its slug is not, which is why the
-- UI does not offer it.
INSERT INTO terms_documents (slug, name, description, requires_acceptance, sort_order) VALUES
('rental',  'Rental Terms & Conditions',  'Terms that apply to a tenancy.',                        1, 10),
('sale',    'Sale Terms & Conditions',    'Terms that apply to a property purchase.',              1, 20),
('viewing', 'Property Viewing Terms',     'Terms accepted before an accompanied viewing.',         1, 30),
('booking', 'Booking / Reservation Terms','Terms accepted when a property is reserved.',           1, 40),
('general', 'General Terms & Conditions', 'Site-wide terms of service shown on the public site.',  0, 50)
ON DUPLICATE KEY UPDATE slug = slug;

-- ── 2. Versions ─────────────────────────────────────────────────────────
-- active_flag is a generated column: 1 when the version is active, NULL
-- otherwise. A UNIQUE index ignores NULLs, so the database itself guarantees
-- at most one active version per type. The application still supersedes the
-- previous version first inside a transaction — this index is the backstop
-- that turns a logic bug into a failed write instead of two live agreements.
--
-- Needs MariaDB 10.2+ / MySQL 5.7+ (this install is MariaDB 10.4). If a
-- future server rejects it, drop the active_flag column and the
-- uq_tv_one_active key: everything still works, enforcement just falls back
-- to TermsVersion::activate()'s transaction.
CREATE TABLE IF NOT EXISTS terms_versions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    terms_document_id INT NOT NULL,
    version_number INT NOT NULL DEFAULT 1,
    version_code VARCHAR(20) NOT NULL,
    title VARCHAR(200) NOT NULL,
    summary VARCHAR(255) DEFAULT '',
    body MEDIUMTEXT NOT NULL,
    content_hash CHAR(64) NOT NULL,
    status ENUM('draft','active','superseded','withdrawn') NOT NULL DEFAULT 'draft',
    effective_from DATE DEFAULT NULL,
    effective_to DATE DEFAULT NULL,
    created_by INT DEFAULT NULL,
    activated_by INT DEFAULT NULL,
    activated_at DATETIME DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    active_flag TINYINT(1) AS (IF(status = 'active', 1, NULL)) STORED,
    UNIQUE KEY uq_tv_number (terms_document_id, version_number),
    UNIQUE KEY uq_tv_one_active (terms_document_id, active_flag),
    INDEX idx_tv_doc_status (terms_document_id, status),
    INDEX idx_tv_effective (effective_from, effective_to),
    CONSTRAINT fk_tv_doc       FOREIGN KEY (terms_document_id) REFERENCES terms_documents(id) ON DELETE RESTRICT,
    CONSTRAINT fk_tv_creator   FOREIGN KEY (created_by)   REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_tv_activator FOREIGN KEY (activated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 3. Acceptance records ───────────────────────────────────────────────
-- No updated_at, and no UPDATE or DELETE path anywhere in the code: a row
-- here is written once and never touched again.
--
-- Three things make the proof survive a later edit of the terms:
--   * ON DELETE RESTRICT on the version — a version with acceptances cannot
--     be deleted, and the RESTRICT propagates up to block deleting the type.
--   * content_hash is copied in at acceptance time, so the row proves the
--     exact wording even if someone reaches into the database.
--   * TermsVersion::update() refuses anything that is not a draft, so a
--     published version is never rewritten in place.
CREATE TABLE IF NOT EXISTS terms_acceptances (
    id INT AUTO_INCREMENT PRIMARY KEY,
    terms_version_id INT NOT NULL,
    user_id INT DEFAULT NULL,
    customer_id INT DEFAULT NULL,
    reference_type VARCHAR(50) DEFAULT '',
    reference_id INT DEFAULT NULL,
    content_hash CHAR(64) NOT NULL,
    acceptance_method VARCHAR(30) NOT NULL DEFAULT 'checkbox',
    accepted_name VARCHAR(120) DEFAULT '',
    ip_address VARCHAR(45) DEFAULT '',
    user_agent VARCHAR(255) DEFAULT '',
    accepted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ta_version (terms_version_id),
    INDEX idx_ta_reference (reference_type, reference_id),
    INDEX idx_ta_user (user_id),
    INDEX idx_ta_customer (customer_id),
    CONSTRAINT fk_ta_version  FOREIGN KEY (terms_version_id) REFERENCES terms_versions(id) ON DELETE RESTRICT,
    CONSTRAINT fk_ta_user     FOREIGN KEY (user_id)     REFERENCES users(id)     ON DELETE SET NULL,
    CONSTRAINT fk_ta_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 4. Settings ─────────────────────────────────────────────────────────
INSERT INTO settings (setting_key, setting_value, setting_group) VALUES
('terms_require_on_reservation', '1',       'legal'),
('terms_public_slug',            'general', 'legal')
ON DUPLICATE KEY UPDATE setting_key = setting_key;
