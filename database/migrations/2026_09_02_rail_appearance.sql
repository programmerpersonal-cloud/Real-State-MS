-- ═══════════════════════════════════════════════════════════════════════
-- Navigation rail appearance
-- 2026-09-02
--
-- The rail used to be one fixed navy column. It is now themeable, and two
-- values decide the whole of it: a surface mode and one accent colour.
-- Everything else the rail draws is derived from these in
-- assets/css/rail.css — see the derivation notes at the top of that file
-- for why an administrator is asked for two values rather than eight.
--
-- They live in the existing settings table rather than a table of their
-- own: it is already the one key/value store, already cached per request
-- by setting(), and already has a validated write path. The group is
-- 'appearance', which SettingsController renders as its own panel.
--
-- INSERT IGNORE so re-running this is a no-op — setting_key is UNIQUE, and
-- an installation that has already chosen a colour must not have it reset
-- by a migration being applied twice.
-- ═══════════════════════════════════════════════════════════════════════

INSERT IGNORE INTO settings (setting_key, setting_value, setting_group) VALUES
    -- 'light' | 'dark' | 'auto'. Validated on the way in by
    -- SettingsController::normalize() and again on the way out by
    -- railTheme(), so a value edited straight into this row still falls
    -- back to light rather than rendering a rail with no palette.
    ('rail_theme',  'light',   'appearance'),

    -- #rrggbb, and nothing else: this value is interpolated into a <style>
    -- block on every signed-in page. railAccent() applies the same pattern
    -- on read. Empty means "use the default", which is the brand blue.
    ('rail_accent', '#0a63a8', 'appearance');
