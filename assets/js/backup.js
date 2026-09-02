/**
 * Backup Management — page behaviour.
 *
 * Four small jobs, none of which the page depends on to be usable. Every
 * control here works with scripting off: the tabs are links, the forms are
 * real forms, and the server re-checks the confirmation phrase and the
 * password that the restore button waits for. What this file adds is the
 * difference between usable and comfortable.
 *
 *   1. poll real state while a backup is running
 *   2. keep the create dialog's summary in step with the selection
 *   3. offer only the restore scopes the chosen archive can provide
 *   4. hold the restore button until intent has actually been expressed
 *
 * Deliberately absent: a progress bar. Neither mysqldump nor ZipArchive
 * reports progress, so any percentage shown here would be an animation, and an
 * animation that looks like a measurement is worse than an honest spinner —
 * people wait on it, then distrust the whole screen when it stalls at 94%.
 */
(function () {
  'use strict';

  /* ─── 1. Polling ──────────────────────────────────────────────────
     Only mounted when the server rendered the busy notice, so a quiet page
     makes no requests at all. Reloads once the run finishes, which is what
     brings the new row, the new figures and the new health verdict in
     together rather than patching them in one at a time. */
  function initPoll() {
    const notice = document.querySelector('[data-backup-poll]');
    if (!notice) return;

    const url = notice.getAttribute('data-poll-url');
    if (!url) return;

    let stopped = false;
    let misses  = 0;

    const tick = function () {
      if (stopped) return;

      fetch(url, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
        .then(function (r) { return r.ok ? r.json() : Promise.reject(r.status); })
        .then(function (data) {
          misses = 0;
          if (!data.busy) {
            stopped = true;
            location.reload();
            return;
          }
          setTimeout(tick, 4000);
        })
        .catch(function () {
          // A restore takes the site into maintenance mode, so these requests
          // are *expected* to fail for a while. Backing off and continuing is
          // right; giving up would leave the page showing "running" forever.
          misses += 1;
          if (misses > 40) { stopped = true; return; }
          setTimeout(tick, 6000);
        });
    };

    setTimeout(tick, 4000);
  }

  /* ─── 2. Create dialog summary ────────────────────────────────── */
  function initCreate() {
    const form = document.querySelector('[data-backup-create]');
    if (!form) return;

    const summary = form.querySelector('[data-create-summary]');
    if (!summary) return;

    const sync = function () {
      const picked = form.querySelector('input[name="type"]:checked');
      if (picked) summary.textContent = picked.getAttribute('data-choice-label') || '';
    };

    form.addEventListener('change', function (e) {
      if (e.target.name === 'type') sync();
    });
    sync();
  }

  /* ─── 3 & 4. Restore dialog ───────────────────────────────────── */
  function initRestore() {
    const form = document.querySelector('[data-backup-restore]');
    if (!form) return;

    const source  = form.querySelector('[data-restore-source]');
    const scope   = form.querySelector('[data-restore-scope]');
    const submit  = form.querySelector('[data-restore-submit]');
    const phrase  = form.querySelector('input[name="confirm_phrase"]');
    const secret  = form.querySelector('input[name="password"]');
    const wanted  = form.getAttribute('data-restore-phrase') || 'RESTORE';

    /* A files backup cannot restore a database and a database backup cannot
       restore files. The options are narrowed to what the selected archive
       actually holds — the controller and the engine both refuse an
       impossible combination anyway, but being refused after typing a
       password is a poor way to learn it. */
    function syncScopes() {
      if (!source || !scope) return;

      const opt  = source.options[source.selectedIndex];
      const type = opt ? opt.getAttribute('data-backup-type') : 'full';
      const allowed = type === 'full' ? ['database', 'files', 'full'] : [type];

      let checkedIsGone = false;

      scope.querySelectorAll('[data-scope]').forEach(function (label) {
        const value = label.getAttribute('data-scope');
        const input = label.querySelector('input');
        const ok    = allowed.indexOf(value) !== -1;

        label.hidden = !ok;
        if (input) {
          input.disabled = !ok;
          if (!ok && input.checked) { input.checked = false; checkedIsGone = true; }
        }
      });

      // Never silently pre-select "full". If the previous choice is no longer
      // possible, leave nothing selected so the operator picks again.
      if (checkedIsGone) sync();
    }

    /* The button is held until the phrase matches exactly — case included —
       and a password has been entered. Mirrors the server's check; it does
       not stand in for it. */
    function sync() {
      if (!submit) return;

      const phraseOk = !!phrase && phrase.value === wanted;
      const secretOk = !!secret && secret.value.length > 0;
      const scopeOk  = !!form.querySelector('input[name="restore_type"]:checked');

      submit.disabled = !(phraseOk && secretOk && scopeOk);
    }

    if (source) source.addEventListener('change', function () { syncScopes(); sync(); });
    form.addEventListener('input',  sync);
    form.addEventListener('change', sync);

    syncScopes();
    sync();
  }

  /* ─── Submit feedback ─────────────────────────────────────────────
     A backup or restore runs inline, so the request can take minutes with no
     visible change. The button says what is happening and stops a second
     submit — the lock would refuse it, but a refusal the operator caused by
     double-clicking reads as a bug. */
  function initBusyButtons() {
    document.querySelectorAll('form[data-backup-create], form[data-backup-restore]').forEach(function (form) {
      form.addEventListener('submit', function () {
        const btn = form.querySelector('[data-submit-busy]');
        if (!btn || btn.disabled) return;

        btn.disabled = true;
        btn.innerHTML = '<i class="bi bi-arrow-repeat spin" aria-hidden="true"></i> '
                      + btn.getAttribute('data-submit-busy');
      });
    });
  }

  document.addEventListener('DOMContentLoaded', function () {
    initPoll();
    initCreate();
    initRestore();
    initBusyButtons();
  });
})();
