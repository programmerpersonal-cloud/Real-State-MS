-- ═══════════════════════════════════════════════════════════════════════
--  Backup module
--  2026-08-30
--
--  Four tables, one settings group. The shape follows one rule: a backup is
--  only worth what can be proved about it, so every claim the dashboard
--  makes has a column behind it.
--
--   backups            one archive on disk, and the evidence it is intact
--   backup_schedules   the three recurring jobs, and when each is next due
--   backup_restores    every restore attempted, successful or not
--   backup_locks       the one-at-a-time guarantee, crash-safe
--
--  `status` and `verification_status` are deliberately separate columns.
--  A run can complete and still fail verification — that backup is finished
--  and useless, and collapsing the two would let it read as healthy. Nothing
--  shows "Verified" except verification_status = 'passed'.
--
--  Idempotent throughout: CREATE TABLE IF NOT EXISTS, INSERT IGNORE against
--  unique keys. Re-running it is a no-op, so a half-applied migration can be
--  finished by running the whole file again.
-- ═══════════════════════════════════════════════════════════════════════

-- ─── Backups ───────────────────────────────────────────────────────────
--
-- `public_id` is what the browser sees. The integer primary key never leaves
-- the server: a backup id in a URL is a download handle, and a guessable one
-- invites a walk through the whole series. Every controller action resolves
-- a UUID, never an id.
--
-- `file_name` is a basename, never a path. The directory is derived from
-- `type` by the storage layer, so nothing stored in this table can ever be
-- concatenated into a path that leaves the backup root — the traversal
-- defence starts in the schema.
CREATE TABLE IF NOT EXISTS backups (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    public_id           CHAR(36)     NOT NULL,
    name                VARCHAR(150) NOT NULL,
    type                ENUM('full','database','files') NOT NULL,

    -- How this run was started. 'emergency' is the safety copy taken before
    -- a destructive restore; it is protected from retention on creation,
    -- because the one backup you must not sweep is the one taken to undo
    -- the operation that is running right now.
    source              ENUM('manual','scheduled','emergency') NOT NULL DEFAULT 'manual',

    status              ENUM('pending','running','completed','verified','failed','deleted')
                        NOT NULL DEFAULT 'pending',

    file_name           VARCHAR(255) DEFAULT NULL,
    file_size           BIGINT UNSIGNED NOT NULL DEFAULT 0,
    checksum            CHAR(64)     DEFAULT NULL,

    -- The manifest as written into the archive, kept here too so the list
    -- can be drawn without opening every zip on disk. The copy inside the
    -- archive is the authority; verification compares the two.
    manifest            LONGTEXT     NULL,
    entry_count         INT UNSIGNED NOT NULL DEFAULT 0,
    database_bytes      BIGINT UNSIGNED NOT NULL DEFAULT 0,
    files_bytes         BIGINT UNSIGNED NOT NULL DEFAULT 0,

    verification_status ENUM('unverified','passed','failed') NOT NULL DEFAULT 'unverified',
    verification_note   VARCHAR(500) DEFAULT NULL,
    verified_at         DATETIME     DEFAULT NULL,

    -- Retention. `is_protected` is the user's "never sweep this"; the class
    -- says which rule applies; expires_at is that rule already resolved, so
    -- the sweep is one indexed comparison rather than a policy evaluated per
    -- row at cleanup time.
    is_protected        TINYINT(1)   NOT NULL DEFAULT 0,
    retention_class     ENUM('manual','daily','weekly','monthly') NOT NULL DEFAULT 'manual',
    expires_at          DATETIME     DEFAULT NULL,

    created_by          INT          DEFAULT NULL,
    started_at          DATETIME     DEFAULT NULL,
    completed_at        DATETIME     DEFAULT NULL,
    failure_message     TEXT         NULL,

    created_at          TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uq_backup_public (public_id),
    -- The list is "newest first, optionally one type"; the health panel is
    -- "most recent completed" and "most recent verified". Both are covered.
    INDEX idx_backup_type_created (type, created_at),
    INDEX idx_backup_status (status, created_at),
    INDEX idx_backup_verified (verification_status, verified_at),
    INDEX idx_backup_sweep (is_protected, expires_at),
    CONSTRAINT fk_backup_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── Schedules ─────────────────────────────────────────────────────────
--
-- Exactly three rows, one per frequency, seeded below and thereafter only
-- updated. `frequency` is a unique key so the seed can be re-run safely; a
-- fourth cadence would be a migration, not a row an administrator can
-- invent, because the retention class it would need does not exist.
--
-- next_run_at is stored rather than computed on read. The CLI runner asks
-- "what is due?" on every tick, and a stored answer makes that one indexed
-- comparison; it is recomputed after every run and whenever a schedule is
-- edited.
CREATE TABLE IF NOT EXISTS backup_schedules (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    frequency       ENUM('daily','weekly','monthly') NOT NULL,
    is_active       TINYINT(1)   NOT NULL DEFAULT 0,
    backup_type     ENUM('full','database','files') NOT NULL DEFAULT 'full',

    -- Local wall-clock time in the configured backup timezone, not UTC. An
    -- administrator sets "02:00" meaning two in the morning where the office
    -- is; the runner resolves it against backup_timezone.
    run_at          TIME         NOT NULL DEFAULT '02:00:00',
    day_of_week     TINYINT UNSIGNED NOT NULL DEFAULT 7,   -- 1=Mon … 7=Sun (ISO)
    day_of_month    TINYINT UNSIGNED NOT NULL DEFAULT 1,   -- clamped to month length

    retention_days  SMALLINT UNSIGNED NOT NULL DEFAULT 30,

    last_run_at     DATETIME     DEFAULT NULL,
    last_backup_id  INT          DEFAULT NULL,
    last_status     ENUM('none','completed','verified','failed') NOT NULL DEFAULT 'none',
    next_run_at     DATETIME     DEFAULT NULL,

    updated_at      TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uq_schedule_frequency (frequency),
    INDEX idx_schedule_due (is_active, next_run_at),
    CONSTRAINT fk_schedule_backup FOREIGN KEY (last_backup_id) REFERENCES backups(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Defaults are the retention policy from the requirements: daily kept a
-- month, weekly twelve weeks, monthly twelve months. All three start
-- inactive — a schedule that began running the moment the migration landed
-- would be a surprise, and the CLI runner has to be installed first anyway.
INSERT IGNORE INTO backup_schedules
    (frequency, is_active, backup_type, run_at, day_of_week, day_of_month, retention_days)
VALUES
    ('daily',   0, 'database', '02:00:00', 7, 1,  30),
    ('weekly',  0, 'full',     '02:30:00', 7, 1,  84),
    ('monthly', 0, 'full',     '03:00:00', 7, 1, 365);

-- ─── Restores ──────────────────────────────────────────────────────────
--
-- Kept separate from `backups` because a restore is an event, not an
-- artefact, and the questions asked of it are different: who ran it, against
-- what, what did it touch, did it work. safety_backup_id is the emergency
-- copy taken immediately before — the row that makes an unwanted restore
-- reversible, and the reason for ON DELETE SET NULL rather than CASCADE.
CREATE TABLE IF NOT EXISTS backup_restores (
    id               INT AUTO_INCREMENT PRIMARY KEY,
    public_id        CHAR(36) NOT NULL,
    backup_id        INT      DEFAULT NULL,
    safety_backup_id INT      DEFAULT NULL,
    restore_type     ENUM('database','files','full') NOT NULL,
    status           ENUM('pending','running','completed','failed') NOT NULL DEFAULT 'pending',

    -- What actually landed, counted as it happened rather than promised in
    -- advance: a restore that stopped halfway says so in these two numbers.
    tables_restored  INT UNSIGNED NOT NULL DEFAULT 0,
    files_restored   INT UNSIGNED NOT NULL DEFAULT 0,
    failure_message  TEXT     NULL,

    performed_by     INT      DEFAULT NULL,
    started_at       DATETIME DEFAULT NULL,
    completed_at     DATETIME DEFAULT NULL,
    created_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    UNIQUE KEY uq_restore_public (public_id),
    INDEX idx_restore_created (created_at),
    CONSTRAINT fk_restore_backup FOREIGN KEY (backup_id)        REFERENCES backups(id) ON DELETE SET NULL,
    CONSTRAINT fk_restore_safety FOREIGN KEY (safety_backup_id) REFERENCES backups(id) ON DELETE SET NULL,
    CONSTRAINT fk_restore_user   FOREIGN KEY (performed_by)     REFERENCES users(id)   ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── Locks ─────────────────────────────────────────────────────────────
--
-- One row per named lock, the name as primary key. Acquiring is INSERT
-- IGNORE first, then an UPDATE guarded by `expires_at < NOW()` — two atomic
-- statements, so two runners racing produce exactly one winner without a
-- transaction held open for the length of a backup.
--
-- The lease is what makes it crash-safe. A runner killed mid-backup leaves
-- its row behind; the expiry lets the next run take it over, and `token`
-- means the dead process cannot release a lock it no longer holds if it
-- somehow comes back. Long runs extend the lease by heartbeat rather than
-- taking a lock with no expiry, because a lock with no expiry only has to be
-- orphaned once to stop backups forever.
CREATE TABLE IF NOT EXISTS backup_locks (
    lock_name    VARCHAR(50)  PRIMARY KEY,
    token        CHAR(32)     NOT NULL,
    owner        VARCHAR(120) NOT NULL,
    acquired_at  DATETIME     NOT NULL,
    heartbeat_at DATETIME     NOT NULL,
    expires_at   DATETIME     NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── Settings ──────────────────────────────────────────────────────────
--
-- Scalars live in the existing settings table rather than a table of their
-- own: it is already the one key/value store, already cached per request by
-- setting(), and already has a write path. The group is excluded from the
-- Settings screen — see SettingsController::HIDDEN_GROUPS — because these
-- are edited on Backup Settings, and two screens writing one key is how a
-- setting ends up with two different values in an administrator's head.
INSERT IGNORE INTO settings (setting_key, setting_value, setting_group) VALUES
    ('backup_timezone',           'Africa/Mogadishu', 'backup'),
    ('backup_rpo_hours',          '24',               'backup'),
    ('backup_storage_quota_gb',   '0',                'backup'),
    ('backup_failure_threshold',  '2',                'backup'),
    ('backup_retention_last_run', '',                 'backup');
