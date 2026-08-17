/**
 * Marko Real Estate — Shared UI components
 *
 * The behaviour behind the component layer: toasts, row menus, the
 * confirmation dialog, the list view switch and form validation.
 *
 * Loaded after main.js, which owns the page shell (sidebar, search, modals)
 * and exposes the two things reused here — lockPageScroll() and FOCUSABLE.
 * Both scripts are plain synchronous files, so every declaration below is
 * in place before main.js's DOMContentLoaded handler calls into it.
 */

/* ═══════════════════════════════════════════════════════════
   TOASTS
   Transient confirmation, announced politely.
   ═══════════════════════════════════════════════════════════ */

const TOAST_ICONS = {
  success: 'bi-check-circle-fill',
  danger: 'bi-exclamation-triangle-fill',
  warning: 'bi-exclamation-circle-fill',
  info: 'bi-info-circle-fill',
};

/** The live region every toast is appended to, created on first use. */
function toastRegion() {
  let region = document.getElementById('toastRegion');
  if (!region) {
    region = document.createElement('div');
    region.id = 'toastRegion';
    region.className = 'toast-region';
    // polite, not assertive: a confirmation should be read after whatever the
    // user is doing, never interrupt it. The region is never focused, so
    // nothing is taken from the field they are typing in.
    region.setAttribute('role', 'status');
    region.setAttribute('aria-live', 'polite');
    document.body.appendChild(region);
  }
  return region;
}

/**
 * Show a toast. Hovering pauses the timer — a message that disappears while
 * it is being read is worse than one that never appeared.
 */
function showToast(message, tone, title, timeout) {
  tone = tone || 'info';
  timeout = timeout === undefined ? 4500 : timeout;

  const el = document.createElement('div');
  el.className = 'toast toast--' + tone;
  el.innerHTML =
    '<i class="bi ' + (TOAST_ICONS[tone] || TOAST_ICONS.info) + ' toast__icon" aria-hidden="true"></i>' +
    '<div class="toast__body">' +
      (title ? '<strong class="toast__title"></strong>' : '') +
      '<span class="toast__text"></span>' +
    '</div>' +
    '<button type="button" class="toast__close" aria-label="Dismiss">' +
      '<i class="bi bi-x-lg" aria-hidden="true"></i></button>';

  // textContent, not innerHTML: the message can carry a record name someone
  // typed, and this is the one place it would be parsed as markup.
  if (title) el.querySelector('.toast__title').textContent = title;
  el.querySelector('.toast__text').textContent = message;

  toastRegion().appendChild(el);
  requestAnimationFrame(() => el.classList.add('is-in'));

  let timer = null;
  const dismiss = () => {
    clearTimeout(timer);
    el.classList.add('is-out');
    setTimeout(() => el.remove(), 220);
  };
  const start = () => { if (timeout) timer = setTimeout(dismiss, timeout); };

  el.querySelector('.toast__close').addEventListener('click', dismiss);
  el.addEventListener('mouseenter', () => clearTimeout(timer));
  el.addEventListener('mouseleave', start);
  start();

  return el;
}

/**
 * The server's flash message, routed to whichever presentation suits it.
 *
 * Success and info confirm something the user just did — they become toasts
 * and get out of the way. Warnings and errors describe a problem that is
 * still on the page, so they stay put until dismissed. The inline alert is
 * also what shows when scripting is off.
 */
function initFlash(flash) {
  if (!flash) return;

  const isProblem = flash.classList.contains('alert--danger')
                 || flash.classList.contains('alert--warning');
  if (isProblem) return;

  const span = flash.querySelector('span');
  const text = (span ? span.textContent : flash.textContent).trim();
  const tone = flash.classList.contains('alert--success') ? 'success' : 'info';
  flash.remove();
  if (text) showToast(text, tone);
}

/* ═══════════════════════════════════════════════════════════
   TABS
   ═══════════════════════════════════════════════════════════ */

/**
 * A tab strip and the panels it switches between.
 *
 * The panels are siblings of the strip rather than children, so they are
 * found from the strip's parent. Both halves are rendered server-side from
 * one list, so a tab can never point at a panel that was not drawn.
 *
 * aria-selected is kept honest and the arrow keys walk the strip, because a
 * tab that only responds to a mouse is a link that lies about being a tab.
 * The chosen tab is written to the URL hash so a reload, a bookmark or a
 * shared link comes back to the same one.
 */
function initTabs(group) {
  const items = Array.from(group.querySelectorAll('.tabs__item'));
  const panels = Array.from(group.parentElement.querySelectorAll('.tab-panel'));
  if (!items.length) return;

  const select = (item, moveFocus) => {
    const target = item.getAttribute('data-tab');
    items.forEach(i => {
      const on = i === item;
      i.classList.toggle('is-active', on);
      i.setAttribute('aria-selected', on ? 'true' : 'false');
      // Only the selected tab is in the tab order; the rest are reached with
      // the arrow keys. Tabbing through eight tabs to reach the panel is what
      // the roving tabindex exists to avoid.
      i.tabIndex = on ? 0 : -1;
    });
    panels.forEach(p => p.classList.toggle('is-active', p.getAttribute('data-panel') === target));
    if (moveFocus) item.focus();

    try {
      history.replaceState(null, '', '#' + target);
    } catch (err) { /* file:// and sandboxed frames refuse this */ }
  };

  items.forEach((item, i) => {
    item.tabIndex = item.classList.contains('is-active') ? 0 : -1;
    item.addEventListener('click', () => select(item, false));
    item.addEventListener('keydown', (e) => {
      const map = { ArrowRight: 1, ArrowLeft: -1, Home: 'first', End: 'last' };
      if (!(e.key in map)) return;
      e.preventDefault();
      const step = map[e.key];
      const next = step === 'first' ? 0
                 : step === 'last' ? items.length - 1
                 : (i + step + items.length) % items.length;
      select(items[next], true);
    });
  });

  // Reopen the tab named in the URL, so a reload does not silently drop the
  // reader back on Overview.
  const fromHash = window.location.hash.replace('#', '');
  const wanted = items.find(i => i.getAttribute('data-tab') === fromHash);
  if (wanted) select(wanted, false);
}

/* ═══════════════════════════════════════════════════════════
   ROW MENU
   The compact action list behind the ⋮ in a table's last column.
   ═══════════════════════════════════════════════════════════ */

/** Close every open row menu, optionally sparing one. */
function closeRowMenus(except) {
  document.querySelectorAll('.row-menu__list:not([hidden])').forEach(list => {
    if (list === except) return;
    list.hidden = true;
    list.classList.remove('is-up');
    const trigger = list.previousElementSibling;
    if (trigger) trigger.setAttribute('aria-expanded', 'false');
  });
}

function initRowMenu(menu) {
  const trigger = menu.querySelector('.row-menu__trigger');
  const list = menu.querySelector('.row-menu__list');
  if (!trigger || !list) return;

  const items = () => Array.from(list.querySelectorAll('.row-menu__item'));

  const open = () => {
    closeRowMenus(list);
    list.hidden = false;
    trigger.setAttribute('aria-expanded', 'true');

    // A menu on the last row of a long table would otherwise open into the
    // page fold, where its final item cannot be reached. Measured once it is
    // visible, because a hidden element has no height to measure.
    const room = window.innerHeight - trigger.getBoundingClientRect().bottom;
    list.classList.toggle('is-up', room < list.offsetHeight + 16);
  };

  const close = (focusTrigger) => {
    list.hidden = true;
    list.classList.remove('is-up');
    trigger.setAttribute('aria-expanded', 'false');
    if (focusTrigger) trigger.focus();
  };

  trigger.addEventListener('click', (e) => {
    e.stopPropagation();
    if (list.hidden) { open(); } else { close(); }
  });

  // Arrow keys walk the list and Escape returns to the trigger. Without this
  // a keyboard user can tab in but has no way out that does not walk through
  // every remaining action.
  menu.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && !list.hidden) { e.preventDefault(); close(true); return; }
    if (list.hidden && e.target === trigger && (e.key === 'ArrowDown' || e.key === 'Enter')) {
      e.preventDefault();
      open();
      const first = items()[0];
      if (first) first.focus();
      return;
    }
    if (list.hidden || (e.key !== 'ArrowDown' && e.key !== 'ArrowUp')) return;

    e.preventDefault();
    const all = items();
    if (!all.length) return;
    const at = all.indexOf(document.activeElement);
    const next = e.key === 'ArrowDown'
      ? (at + 1) % all.length
      : (at <= 0 ? all.length - 1 : at - 1);
    all[next].focus();
  });
}

// One document listener for all of them, rather than one per menu.
document.addEventListener('click', () => closeRowMenus());

/* ═══════════════════════════════════════════════════════════
   CONFIRMATION DIALOG
   Replaces window.confirm(): it names the record, says what will
   happen, and can be backed out of with Escape or the backdrop.
   ═══════════════════════════════════════════════════════════ */

let confirmEl = null;

/** The single dialog instance, built once and reused. */
function confirmDialog() {
  if (confirmEl) return confirmEl;

  confirmEl = document.createElement('div');
  confirmEl.className = 'modal confirm';
  confirmEl.hidden = true;
  confirmEl.innerHTML =
    '<div class="modal__backdrop" data-confirm-cancel></div>' +
    '<div class="modal__dialog confirm__dialog" role="alertdialog" aria-modal="true"' +
    ' aria-labelledby="confirmTitle" aria-describedby="confirmText" tabindex="-1">' +
      '<div class="confirm__body">' +
        '<div class="confirm__icon"><i class="bi bi-exclamation-triangle" aria-hidden="true"></i></div>' +
        '<div>' +
          '<h2 class="confirm__title" id="confirmTitle"></h2>' +
          '<p class="confirm__text" id="confirmText"></p>' +
          '<span class="confirm__record" hidden></span>' +
        '</div>' +
      '</div>' +
      '<footer class="modal__footer confirm__footer">' +
        '<button type="button" class="btn btn--outline" data-confirm-cancel>Cancel</button>' +
        '<button type="button" class="btn btn--danger" data-confirm-ok></button>' +
      '</footer>' +
    '</div>';
  document.body.appendChild(confirmEl);
  return confirmEl;
}

function initConfirm() {
  // Capture phase: this must run before the row menu's own click handling and
  // before any inline handler, or the action fires while the dialog is still
  // asking about it.
  document.addEventListener('click', (e) => {
    const trigger = e.target.closest('[data-confirm]');
    if (!trigger || trigger.dataset.confirmDone === '1') return;

    e.preventDefault();
    e.stopPropagation();
    openConfirm(trigger);
  }, true);
}

function openConfirm(trigger) {
  const el = confirmDialog();
  const dialog = el.querySelector('.modal__dialog');
  const d = trigger.dataset;
  const tone = d.confirmTone || 'danger';

  el.classList.remove('confirm--warning', 'confirm--info');
  if (tone !== 'danger') el.classList.add('confirm--' + tone);

  el.querySelector('.confirm__title').textContent = d.confirmTitle || 'Are you sure?';
  el.querySelector('.confirm__text').textContent = d.confirm || 'This action cannot be undone.';
  el.querySelector('.confirm__icon i').className =
    'bi ' + (tone === 'danger' ? 'bi-exclamation-triangle' : 'bi-question-circle');

  const record = el.querySelector('.confirm__record');
  record.textContent = d.confirmRecord || '';
  record.hidden = !d.confirmRecord;

  const ok = el.querySelector('[data-confirm-ok]');
  ok.textContent = d.confirmAction || 'Confirm';
  ok.className = 'btn ' + (tone === 'danger' ? 'btn--danger' : 'btn--primary');

  closeRowMenus();

  const lastFocused = document.activeElement;
  el.hidden = false;
  lockPageScroll(true);
  requestAnimationFrame(() => {
    el.classList.add('is-open');
    // Cancel takes focus, not the destructive button: a stray Enter should
    // back out of a deletion, never commit one.
    const cancel = el.querySelector('button[data-confirm-cancel]');
    if (cancel) cancel.focus({ preventScroll: true });
  });

  const cancels = Array.from(el.querySelectorAll('[data-confirm-cancel]'));

  const close = () => {
    el.classList.remove('is-open');
    lockPageScroll(false);
    setTimeout(() => { el.hidden = true; }, 200);
    document.removeEventListener('keydown', onKey, true);
    cancels.forEach(b => b.removeEventListener('click', close));
    ok.removeEventListener('click', proceed);
    if (lastFocused && lastFocused.focus) lastFocused.focus({ preventScroll: true });
  };

  const proceed = () => {
    close();
    // Re-fire the original control with the guard set, so the capture-phase
    // listener lets it through this time. A link navigates, a submit button
    // submits its form — each keeps the behaviour it already had.
    trigger.dataset.confirmDone = '1';
    trigger.click();
    delete trigger.dataset.confirmDone;
  };

  const onKey = (e) => {
    if (e.key === 'Escape') { e.preventDefault(); close(); return; }
    if (e.key !== 'Tab') return;
    const items = Array.from(dialog.querySelectorAll(FOCUSABLE)).filter(n => n.offsetParent !== null);
    if (!items.length) return;
    const first = items[0];
    const last = items[items.length - 1];
    if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
    else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
  };

  cancels.forEach(b => b.addEventListener('click', close));
  ok.addEventListener('click', proceed);
  document.addEventListener('keydown', onKey, true);
}

/* ═══════════════════════════════════════════════════════════
   LIST VIEW SWITCH — table / grid
   ═══════════════════════════════════════════════════════════ */

/**
 * Remembers the chosen view per module, so someone who works in the grid is
 * not put back into the table every time they open the page.
 *
 * Both panels are rendered server-side and toggled here: choosing a view is
 * a preference, not a new query, and a round trip to redraw the same rows is
 * a wait for nothing.
 */
function initViewSwitch(root) {
  const key = 'saxane.view.' + (root.dataset.viewSwitch || 'list');
  const buttons = Array.from(root.querySelectorAll('[data-view]'));
  const panels = Array.from(document.querySelectorAll('[data-view-panel]'));
  if (!buttons.length || !panels.length) return;

  const apply = (view) => {
    buttons.forEach(b => {
      const on = b.dataset.view === view;
      b.classList.toggle('is-active', on);
      b.setAttribute('aria-pressed', on ? 'true' : 'false');
    });
    panels.forEach(p => { p.hidden = p.dataset.viewPanel !== view; });
  };

  let saved = null;
  try { saved = localStorage.getItem(key); } catch (err) { /* private mode */ }
  apply(buttons.some(b => b.dataset.view === saved) ? saved : buttons[0].dataset.view);

  buttons.forEach(b => b.addEventListener('click', () => {
    apply(b.dataset.view);
    try { localStorage.setItem(key, b.dataset.view); } catch (err) { /* not worth failing over */ }
  }));
}

/* ═══════════════════════════════════════════════════════════
   FORM VALIDATION
   Problems stay inside the form, next to the field that caused them.
   ═══════════════════════════════════════════════════════════ */

/** The label text for a control, for use in the summary list. */
function fieldLabel(field) {
  let label = null;
  if (field.id && field.form) {
    label = field.form.querySelector('label[for="' + CSS.escape(field.id) + '"]');
  }
  if (!label) {
    const group = field.closest('.form-group');
    label = group ? group.querySelector('.form-label') : null;
  }
  return (label ? label.textContent : (field.name || 'This field'))
    .replace(/\s*\*\s*$/, '')   // the required marker is not part of the name
    .trim();
}

/** Mark or clear one control, keeping the message tied to it for a11y. */
function setFieldError(field, message) {
  const group = field.closest('.form-group') || field.parentElement;
  let box = group ? group.querySelector('.form-error[data-client-error]') : null;

  if (!message) {
    field.classList.remove('form-control--error');
    field.removeAttribute('aria-invalid');
    if (box) box.remove();
    return;
  }

  field.classList.add('form-control--error');
  field.setAttribute('aria-invalid', 'true');

  if (!box && group) {
    box = document.createElement('div');
    box.className = 'form-error';
    box.setAttribute('data-client-error', '');
    box.id = 'cerr-' + (field.name || Math.random().toString(36).slice(2, 8));
    group.appendChild(box);
    const described = [field.getAttribute('aria-describedby'), box.id].filter(Boolean).join(' ');
    field.setAttribute('aria-describedby', described);
  }
  if (box) {
    box.innerHTML = '<i class="bi bi-exclamation-circle" aria-hidden="true"></i> ';
    box.appendChild(document.createTextNode(message));
  }
}

/** Why this control is not acceptable, or '' when it is. */
function fieldProblem(field) {
  if (field.disabled || field.type === 'hidden') return '';

  if (field.hasAttribute('required') && !field.value.trim()) {
    return fieldLabel(field) + ' is required.';
  }
  if (field.value.trim() && !field.checkValidity()) {
    // The browser already knows what is wrong with an email or a number, and
    // its wording is localised — better than anything hard-coded here.
    return field.validationMessage;
  }
  return '';
}

function initFormValidation(form) {
  const controls = () => Array.from(form.elements).filter(el =>
    el.name && !el.disabled && ['hidden', 'submit', 'button'].indexOf(el.type) === -1);

  // Validate on blur, never on keystroke: marking a field invalid while it is
  // still being typed into tells someone they are wrong before they have
  // finished. Once marked, it clears as soon as it is fixed.
  controls().forEach(field => {
    field.addEventListener('blur', () => {
      if (field.value.trim() || field.classList.contains('form-control--error')) {
        setFieldError(field, fieldProblem(field));
      }
    });
    field.addEventListener('input', () => {
      if (field.classList.contains('form-control--error') && !fieldProblem(field)) {
        setFieldError(field, '');
      }
    });
  });

  form.addEventListener('submit', (e) => {
    const bad = [];
    controls().forEach(field => {
      const problem = fieldProblem(field);
      setFieldError(field, problem);
      if (problem) bad.push([field, problem]);
    });

    // Password confirmation is the one rule the browser cannot express.
    const pw = form.querySelector('[name="password"]');
    const cpw = form.querySelector('[name="confirm_password"]');
    if (pw && cpw && cpw.value && pw.value !== cpw.value) {
      const msg = 'The two passwords do not match.';
      setFieldError(cpw, msg);
      bad.push([cpw, msg]);
    }

    const stale = form.querySelector('.error-summary[data-client-summary]');
    if (stale) stale.remove();

    if (!bad.length) {
      // Valid: show the submit working, so a slow save does not read as a
      // dead button and get pressed a second time.
      const submit = form.querySelector('[type="submit"]');
      if (submit) {
        submit.classList.add('is-loading');
        submit.disabled = true;
        // Restored if the browser stays on this page — a rejected upload or a
        // back-navigation would otherwise leave a permanently dead button.
        setTimeout(() => {
          submit.classList.remove('is-loading');
          submit.disabled = false;
        }, 12000);
      }
      return;
    }

    e.preventDefault();

    if (bad.length === 1) {
      // One problem needs no summary — the field itself says everything.
      bad[0][0].focus();
      return;
    }

    // More than one: a summary at the top, each line linking to its field, and
    // focus moved there, so a screen reader hears the whole list rather than
    // one message with no hint that others exist.
    const summary = document.createElement('div');
    summary.className = 'error-summary';
    summary.setAttribute('data-client-summary', '');
    summary.setAttribute('role', 'alert');
    summary.tabIndex = -1;
    summary.innerHTML =
      '<div class="error-summary__title">' +
        '<i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i>' +
        'There are ' + bad.length + ' problems with this form' +
      '</div><ul class="error-summary__list"></ul>';

    const list = summary.querySelector('.error-summary__list');
    bad.forEach(pair => {
      const field = pair[0];
      const li = document.createElement('li');
      const a = document.createElement('a');
      a.href = '#';
      a.textContent = pair[1];
      a.addEventListener('click', (ev) => { ev.preventDefault(); field.focus(); });
      li.appendChild(a);
      list.appendChild(li);
    });

    // Inside the dialog body when this is a popup, so the summary lands on the
    // surface that actually scrolls rather than behind it.
    const host = form.querySelector('.modal__body') || form;
    host.prepend(summary);
    summary.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
    summary.focus();
  });
}
