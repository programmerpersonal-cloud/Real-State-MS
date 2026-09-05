-- ═══════════════════════════════════════════════════════════════════════
--  Backup scheduler — proof of life
--  2026-09-05
--
--  The backup module could describe what it intended to do and could not
--  describe whether anything was doing it. backup_schedules said "daily,
--  active, next run 03 Sep 04:00"; the runner that turns that row into an
--  archive had never been installed, and nothing anywhere recorded that
--  fact. The dashboard therefore showed a confident schedule beside a
--  Critical health badge that blamed the absence of backups rather than the
--  absence of a scheduler, and the two were never connected.
--
--  These four rows are the missing evidence. The runner writes them on every
--  tick, whether or not a backup was due, so "when did the scheduler last
--  check?" becomes a fact with a timestamp instead of an assumption:
--
--    backup_scheduler_last_tick    when the runner last completed a pass
--    backup_scheduler_last_result  what that pass did, in one line
--    backup_scheduler_tick_count   passes since installation, monotonic
--    backup_scheduler_host         which machine and account is running it
--
--  They live in `settings` for the same reason the rest of the module's
--  scalars do: it is already the one key/value store, already cached per
--  request, and already excluded from the Settings screen for the `backup`
--  group. A table of one row would be a second scheduling system to keep in
--  step with the first.
--
--  Written on MySQL's clock (backupDbNow()) like every other datetime in
--  this module, so the age comparisons in backupHealth() are between two
--  values that agree about what time it is.
--
--  Idempotent: INSERT IGNORE against the unique key on setting_key. Running
--  it twice changes nothing, and running it on an installation that already
--  has the rows will not reset a live scheduler's counter.
-- ═══════════════════════════════════════════════════════════════════════

INSERT IGNORE INTO settings (setting_key, setting_value, setting_group) VALUES
    ('backup_scheduler_last_tick',   '',  'backup'),
    ('backup_scheduler_last_result', '',  'backup'),
    ('backup_scheduler_tick_count',  '0', 'backup'),
    ('backup_scheduler_host',        '',  'backup');
