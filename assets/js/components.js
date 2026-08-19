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

/* How many toasts may stand at once. Past three the stack starts covering
   the page it is reporting on, and nobody reads the fourth. */
const TOAST_LIMIT = 3;

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
 * Show a toast.
 *
 * A countdown bar along the foot says how long is left, and pointing at the
 * toast or tabbing into it stops that clock: a message that disappears while
 * it is being read is worse than one that never appeared. At most three stand
 * at once — past that the stack covers the page it is reporting on.
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

  // The dismiss clock, made visible. A toast that simply vanishes gives no
  // sense of how long is left to read it; the bar does, and pausing it on
  // hover or focus is what turns "read fast" into "read when you like".
  if (timeout) {
    const bar = document.createElement('span');
    bar.className = 'toast__progress';
    bar.style.animationDuration = timeout + 'ms';
    el.appendChild(bar);
  }

  const region = toastRegion();
  region.appendChild(el);

  // Three at once is the ceiling. Beyond that the stack covers the page it is
  // reporting on, and nobody reads the fourth — the oldest goes so the newest
  // is always the one in view.
  const live = region.querySelectorAll('.toast:not(.is-out)');
  for (let i = 0; i < live.length - TOAST_LIMIT; i++) {
    const old = live[i];
    if (typeof old.dismissToast === 'function') old.dismissToast();
  }

  requestAnimationFrame(() => el.classList.add('is-in'));

  let timer = null;
  let dismissed = false;
  let remaining = timeout;   // ms still owed; the bar above is its picture
  let startedAt = 0;

  const dismiss = () => {
    if (dismissed) return;   // close clicked while the timer was also firing
    dismissed = true;
    clearTimeout(timer);
    el.classList.add('is-out');
    setTimeout(() => el.remove(), 220);
  };

  const start = () => {
    if (!timeout || dismissed || timer) return;
    // The bar is paused by CSS on :hover and :focus-within, and resumes from
    // where it stopped. The timer has to resume from the same place or the
    // two disagree — a bar that empties with seconds still on the clock, or a
    // toast that vanishes with bar left to run.
    startedAt = Date.now();
    timer = setTimeout(dismiss, remaining);
  };
  const pause = () => {
    if (!timer) return;
    clearTimeout(timer);
    timer = null;
    remaining = Math.max(0, remaining - (Date.now() - startedAt));
  };
  // Pointer and keyboard hold the toast independently, and CSS keeps the bar
  // paused while either applies. Resuming on one while the other still holds
  // would restart the clock under a bar that is still stopped.
  const held = () => el.matches(':hover') || el.contains(document.activeElement);
  const release = () => { if (!held()) start(); };

  // Exposed so the stack limit above can retire this toast through the same
  // path a click takes, rather than tearing it out of the DOM mid-animation.
  el.dismissToast = dismiss;

  el.querySelector('.toast__close').addEventListener('click', dismiss);
  el.addEventListener('mouseenter', pause);
  el.addEventListener('mouseleave', release);
  // Keyboard users reach the close button by tabbing; the clock has to stop
  // for them too, or the control moves out from under them mid-reach.
  el.addEventListener('focusin', pause);
  el.addEventListener('focusout', (e) => {
    if (!el.contains(e.relatedTarget)) release();
  });
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
    // Call the menu's own closer where there is one, so the scroll and resize
    // listeners it attached while open are detached again. Setting `hidden`
    // from out here would hide the menu and leave those bound for the life of
    // the page.
    if (typeof list.closeRowMenu === 'function') {
      list.closeRowMenu(false);
      return;
    }
    list.hidden = true;
    const trigger = list.previousElementSibling;
    if (trigger) trigger.setAttribute('aria-expanded', 'false');
  });
}

function initRowMenu(menu) {
  const trigger = menu.querySelector('.row-menu__trigger');
  const list = menu.querySelector('.row-menu__list');
  if (!trigger || !list) return;

  const items = () => Array.from(list.querySelectorAll('.row-menu__item'));

  /**
   * Place the menu against the trigger.
   *
   * The list is position:fixed so it escapes the table's two clipping
   * ancestors (.table-wrap scrolls sideways, .table-card hides overflow for
   * its rounded corners). Fixed means viewport coordinates, which only JS
   * can know — so they are computed here from the trigger's rect and kept in
   * step while the menu is open.
   *
   * Right-aligned to the trigger, because the actions column is the last one
   * and there is no room to the right. Flipped up when the row is near the
   * foot of the viewport, and clamped so neither edge can push it off screen.
   */
  const place = () => {
    const t = trigger.getBoundingClientRect();
    const w = list.offsetWidth;
    const h = list.offsetHeight;
    const gap = 4;
    const pad = 8;

    const below = window.innerHeight - t.bottom;
    const up = below < h + 16 && t.top > h + 16;
    let top = up ? t.top - h - gap : t.bottom + gap;
    top = Math.max(pad, Math.min(top, window.innerHeight - h - pad));

    let left = t.right - w;
    left = Math.max(pad, Math.min(left, window.innerWidth - w - pad));

    list.style.top = Math.round(top) + 'px';
    list.style.left = Math.round(left) + 'px';
  };

  // Passive listeners so keeping the menu pinned never blocks a scroll.
  // `capture` catches scrolls inside .table-wrap as well as the page.
  let pinning = false;
  const repin = () => {
    if (pinning || list.hidden) return;
    pinning = true;
    requestAnimationFrame(() => { pinning = false; if (!list.hidden) place(); });
  };

  const open = () => {
    closeRowMenus(list);
    list.hidden = false;
    trigger.setAttribute('aria-expanded', 'true');
    // Measured only once visible: a hidden element has no size to measure.
    place();
    window.addEventListener('scroll', repin, { passive: true, capture: true });
    window.addEventListener('resize', repin, { passive: true });
  };

  const close = (focusTrigger) => {
    list.hidden = true;
    trigger.setAttribute('aria-expanded', 'false');
    window.removeEventListener('scroll', repin, { capture: true });
    window.removeEventListener('resize', repin);
    if (focusTrigger) trigger.focus();
  };

  // Exposed so closeRowMenus() can shut this one down properly — including
  // detaching the listeners open() attached — rather than just hiding it.
  list.closeRowMenu = close;

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
  // Measured now, because closeRowMenus() below hides the menu this trigger
  // may live in — and a hidden element has no rect to grow the dialog from.
  const from = trigger.getBoundingClientRect();
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
  // Grows out of the control that asked the question.
  setModalOrigin(dialog, from);
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
/* The shared ruleset, as PHP declared it. Read once, on first use.

   This is the same table includes/validation.php validates against on the
   server — handed over as data rather than restated here, because two
   descriptions of what a valid phone number is will eventually disagree and
   the one people meet first is this one. */
let VALIDATION = null;
function validationRules() {
  if (VALIDATION !== null) return VALIDATION;
  const el = document.getElementById('validationRules');
  try {
    VALIDATION = el ? JSON.parse(el.textContent) : {};
  } catch (e) {
    VALIDATION = {};       // malformed: fall back to the browser's own checks
  }
  return VALIDATION;
}

function validationMessage(key, vars) {
  const rules = validationRules();
  let msg = (rules.messages && rules.messages[key]) || '';
  for (const k in (vars || {})) msg = msg.replace('{' + k + '}', vars[k]);
  return msg;
}

/** Which rule applies to a control: what it says it is, else what it is named. */
function fieldRule(field) {
  const rules = validationRules();
  const declared = field.dataset.validateType;
  if (declared) return { type: declared, opts: {} };

  const spec = (rules.fields || {})[field.name];
  if (spec) {
    const opts = Object.assign({}, spec);
    delete opts.type;
    return { type: spec.type, opts };
  }
  return null;
}

/** The country a phone input is currently set to, from its sibling selector. */
function phoneCountryOf(field) {
  const sel = field.form && field.form.querySelector('[name="' + field.name + '_country"]');
  const rules = validationRules();
  return (sel && sel.value) || rules.defaultCountry || 'SO';
}

function fieldProblem(field) {
  if (field.disabled || field.type === 'hidden') return '';

  const rules = validationRules();
  const value = (field.value || '').trim();

  // Empty first, always: telling someone their blank field is the wrong shape
  // is nonsense, and "fill this in" is the only useful thing to say.
  if (field.hasAttribute('required') && !value) {
    return validationMessage('required') || (fieldLabel(field) + ' is required.');
  }
  if (!value) return '';

  const rule = fieldRule(field);
  if (rule && rules.types) {
    if (rule.type === 'phone') {
      const c = (rules.countries || {})[phoneCountryOf(field)];
      if (c) {
        const digits = value.replace(/\D+/g, '').replace(/^0+/, '');
        if (!digits) return validationMessage('phone');
        if (c.lengths.indexOf(digits.length) === -1) {
          return validationMessage('phoneLen', {
            country: c.name, lengths: c.lengths.join(' ama '),
          });
        }
        return '';
      }
    }

    const t = rules.types[rule.type];
    if (t && t.pattern && !new RegExp(t.pattern, 'u').test(value)) {
      return validationMessage(t.message);
    }

    const o = rule.opts || {};
    if (o.max && value.length > o.max) return validationMessage('max', { max: o.max });
    if (o.min && value.length < o.min) return validationMessage('min', { min: o.min });

    if (rule.type === 'number' || rule.type === 'integer') {
      const n = parseFloat(value);
      if (o.positive && !(n > 0)) return validationMessage('positive');
      if (o.minValue !== undefined && n < o.minValue) return validationMessage('minValue', { min: o.minValue });
      if (o.maxValue !== undefined && n > o.maxValue) return validationMessage('maxValue', { max: o.maxValue });
    }
    return '';
  }

  // No shared rule for this field: the browser's own check still applies, and
  // its wording is localised, which is better than anything invented here.
  if (!field.checkValidity()) return field.validationMessage;
  return '';
}

/**
 * Refuse characters that cannot belong in a field as they are typed.
 *
 * Only where the wrong character is unambiguous — a letter in a price, a digit
 * in a person's name. Never on free text, where guessing at what someone meant
 * to type is how a form starts fighting the person filling it in. The caret is
 * put back where it was, so correcting a character mid-word does not throw the
 * cursor to the end.
 */
function initInputFilter(field) {
  const rule = fieldRule(field);
  const rules = validationRules();
  if (!rule || !rules.types || !rules.types[rule.type] || !rules.types[rule.type].filter) return;

  const keep = {
    name: /[^\p{L} '\-.]/gu,
    integer: /[^0-9]/g,
    number: /[^0-9.]/g,
    phone: /[^0-9 ]/g,
  }[rule.type];
  if (!keep) return;

  field.addEventListener('input', () => {
    const before = field.value;
    const cleaned = before.replace(keep, '');
    if (cleaned === before) return;
    const at = field.selectionStart;
    field.value = cleaned;
    const moved = before.length - cleaned.length;
    try { field.setSelectionRange(at - moved, at - moved); } catch (e) { /* not a text input */ }
  });
}

function initFormValidation(form) {
  const controls = () => Array.from(form.elements).filter(el =>
    el.name && !el.disabled && ['hidden', 'submit', 'button'].indexOf(el.type) === -1);

  /* Hand the browser's own validation over to ours — but only once this code
     is running, which is why it is set here rather than in the markup.

     A `required` field the browser considers empty cancels submission before
     the submit event is dispatched, so none of the handling below ever ran:
     the user got the browser's native bubble, in the browser's language,
     floating above the field instead of the message that belongs beside it.
     With scripting off the attribute is never set and native validation is
     still the fallback, which is exactly the right order of precedence. */
  form.noValidate = true;

  // Validate on blur, never on keystroke: marking a field invalid while it is
  // still being typed into tells someone they are wrong before they have
  // finished. Once marked, it clears as soon as it is fixed.
  controls().forEach(field => {
    initInputFilter(field);
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

  // A phone is judged against its country, so changing the country re-judges
  // the number. Without this a field stays marked wrong after the very action
  // that made it right.
  form.querySelectorAll('.phone-field__select').forEach(sel => {
    sel.addEventListener('change', () => {
      const num = form.querySelector('[name="' + sel.name.replace(/_country$/, '') + '"]');
      if (!num) return;
      const rules = validationRules();
      const c = (rules.countries || {})[sel.value];
      if (c) num.placeholder = c.example;
      if (num.value.trim()) setFieldError(num, fieldProblem(num));
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
      const msg = validationMessage('passwordMatch') || 'The two passwords do not match.';
      setFieldError(cpw, msg);
      bad.push([cpw, msg]);
    }

    const stale = form.querySelector('.error-summary[data-client-summary]');
    if (stale) stale.remove();

    if (!bad.length) {
      // Valid: show the submit working, so a slow save does not read as a
      // dead button and get pressed a second time.
      //
      // e.submitter, not the first submit button in the form. A form with
      // more than one submit button would otherwise spin the wrong one.
      const submit = e.submitter && e.submitter.type === 'submit'
        ? e.submitter
        : form.querySelector('[type="submit"]');

      if (submit) {
        submit.classList.add('is-loading');
        // Deliberately NOT `submit.disabled = true`. A disabled submit button
        // is excluded from the form's data, so disabling it here would drop
        // its name and value from the request that is about to be sent, which
        // silently changes what a form with more than one submit asks for.
        // .is-loading already sets pointer-events:none, and the flag below
        // is what actually stops a second submission.
        if (form.dataset.submitting === '1') {
          e.preventDefault();
          return;
        }
        form.dataset.submitting = '1';

        // Restored if the browser stays on this page — a rejected upload or a
        // back-navigation would otherwise leave a permanently dead button.
        setTimeout(() => {
          submit.classList.remove('is-loading');
          delete form.dataset.submitting;
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

/* ═══════════════════════════════════════════════════════════════════
   Phone country picker
   ═══════════════════════════════════════════════════════════════════

   The server renders a real <select>, which is what posts and what works
   with scripting off. This turns it into the control the form actually
   wants: a narrow trigger showing the flag and the dialling code, and a
   searchable list that spells out the country while it is open.

   The two halves of the old field competed for one row — a select wide
   enough to read "Boqortooyada (+44)" left the number input with whatever
   remained, which in a two-column form was not enough to see a nine-digit
   number. Moving the country name into the open list gives the number that
   width back and loses nothing: the name is there at the moment you are
   choosing, which is the only moment it is needed.

   The select stays in the DOM and stays authoritative. Every choice writes
   to it and fires `change`, so the validation client — which already listens
   for that to re-judge the number and swap the placeholder — needs no
   knowledge of any of this.
   ═══════════════════════════════════════════════════════════════════ */

/** The flag for an ISO country code, as regional indicator symbols. */
function countryFlag(iso) {
  if (!/^[A-Za-z]{2}$/.test(iso)) return '';
  return String.fromCodePoint(
    ...iso.toUpperCase().split('').map(c => 0x1f1e6 + c.charCodeAt(0) - 65)
  );
}

let phonePickerSeq = 0;

function initPhoneField(field) {
  const select = field.querySelector('.phone-field__select');
  const number = field.querySelector('.phone-field__number');
  if (!select || !number || field.dataset.phoneReady) return;
  field.dataset.phoneReady = '1';

  const options = Array.from(select.options).map(o => ({
    iso: o.value,
    name: o.dataset.name || o.textContent.trim(),
    dial: o.dataset.dial || '',
    flag: countryFlag(o.value),
  }));

  const id = 'phonemenu-' + (++phonePickerSeq);

  const trigger = document.createElement('button');
  trigger.type = 'button';
  trigger.className = 'phone-field__trigger';
  trigger.setAttribute('aria-haspopup', 'listbox');
  trigger.setAttribute('aria-expanded', 'false');
  trigger.setAttribute('aria-controls', id);

  const menu = document.createElement('div');
  menu.className = 'phone-menu';
  menu.id = id;
  menu.hidden = true;
  menu.innerHTML =
    '<div class="phone-menu__search">' +
      '<i class="bi bi-search" aria-hidden="true"></i>' +
      '<input type="text" class="phone-menu__input" autocomplete="off" spellcheck="false"' +
      ' placeholder="Raadi waddan" aria-label="Raadi waddan">' +
    '</div>' +
    '<ul class="phone-menu__list" role="listbox" tabindex="-1"></ul>' +
    '<p class="phone-menu__empty" hidden>Waddan la mid ah lama helin.</p>';

  const search = menu.querySelector('.phone-menu__input');
  const list = menu.querySelector('.phone-menu__list');
  const empty = menu.querySelector('.phone-menu__empty');

  // What the trigger has to say in very little room: the flag carries the
  // country, the dial code carries what it means for the number beside it.
  const paint = () => {
    const o = options.find(x => x.iso === select.value) || options[0];
    if (!o) return;
    trigger.innerHTML =
      '<span class="phone-field__flag" aria-hidden="true">' + o.flag + '</span>' +
      '<span class="phone-field__dial">+' + o.dial + '</span>' +
      '<i class="bi bi-chevron-down phone-field__caret" aria-hidden="true"></i>';
    trigger.setAttribute('aria-label', 'Waddanka lambarka: ' + o.name + ' +' + o.dial);
  };

  const render = (q) => {
    const needle = (q || '').trim().toLowerCase();
    // Matched against the name, the ISO code and the dialling code, with or
    // without its plus, because all three are things people type.
    const hits = options.filter(o =>
      needle === ''
      || o.name.toLowerCase().indexOf(needle) > -1
      || o.iso.toLowerCase().indexOf(needle) > -1
      || ('+' + o.dial).indexOf(needle) > -1
      || o.dial.indexOf(needle.replace(/^\+/, '')) > -1);

    list.innerHTML = '';
    hits.forEach((o) => {
      const li = document.createElement('li');
      li.className = 'phone-menu__item' + (o.iso === select.value ? ' is-active' : '');
      li.setAttribute('role', 'option');
      li.setAttribute('aria-selected', o.iso === select.value ? 'true' : 'false');
      li.dataset.iso = o.iso;
      li.innerHTML =
        '<span class="phone-menu__flag" aria-hidden="true">' + o.flag + '</span>' +
        '<span class="phone-menu__name"></span>' +
        '<span class="phone-menu__dial">+' + o.dial + '</span>';
      li.querySelector('.phone-menu__name').textContent = o.name;
      list.appendChild(li);
    });
    empty.hidden = hits.length > 0;
    return hits.length;
  };

  /* Fixed, like the row menu, so the list escapes a modal body or a card
     that clips its own overflow. Aligned to the field rather than to the
     trigger, so it opens under the whole control. */
  const place = () => {
    const r = field.getBoundingClientRect();
    const h = menu.offsetHeight;
    const w = menu.offsetWidth;
    const gap = 4;
    const pad = 8;

    const below = window.innerHeight - r.bottom;
    const up = below < h + 16 && r.top > h + 16;
    let top = up ? r.top - h - gap : r.bottom + gap;
    top = Math.max(pad, Math.min(top, window.innerHeight - h - pad));

    let left = Math.max(pad, Math.min(r.left, window.innerWidth - w - pad));

    menu.style.top = Math.round(top) + 'px';
    menu.style.left = Math.round(left) + 'px';
  };

  let pinning = false;
  const repin = () => {
    if (pinning || menu.hidden) return;
    pinning = true;
    requestAnimationFrame(() => { pinning = false; if (!menu.hidden) place(); });
  };

  const highlight = (li) => {
    list.querySelectorAll('.phone-menu__item').forEach(x => x.classList.remove('is-cursor'));
    if (!li) return;
    li.classList.add('is-cursor');
    li.scrollIntoView({ block: 'nearest' });
  };

  const open = () => {
    closePhoneMenus(menu);
    render('');
    search.value = '';
    menu.hidden = false;
    trigger.setAttribute('aria-expanded', 'true');
    place();
    highlight(list.querySelector('.is-active') || list.querySelector('.phone-menu__item'));
    search.focus();
    window.addEventListener('scroll', repin, { passive: true, capture: true });
    window.addEventListener('resize', repin, { passive: true });
  };

  const close = (focusTrigger) => {
    if (menu.hidden) return;
    menu.hidden = true;
    trigger.setAttribute('aria-expanded', 'false');
    window.removeEventListener('scroll', repin, { capture: true });
    window.removeEventListener('resize', repin);
    if (focusTrigger) trigger.focus();
  };
  menu.__closePhoneMenu = close;

  const choose = (iso) => {
    if (!iso) return;
    select.value = iso;
    // The validation client listens for this to re-judge the number against
    // the new country and swap the example placeholder.
    select.dispatchEvent(new Event('change', { bubbles: true }));
    paint();
    close(false);
    number.focus();
  };

  trigger.addEventListener('click', (e) => {
    e.stopPropagation();
    if (menu.hidden) { open(); } else { close(true); }
  });

  search.addEventListener('input', () => {
    render(search.value);
    highlight(list.querySelector('.phone-menu__item'));
  });

  const move = (step) => {
    const items = Array.from(list.querySelectorAll('.phone-menu__item'));
    if (!items.length) return;
    const at = items.findIndex(x => x.classList.contains('is-cursor'));
    const from = at < 0 ? 0 : at;
    highlight(items[Math.max(0, Math.min(items.length - 1, from + step))]);
  };

  menu.addEventListener('keydown', (e) => {
    if (e.key === 'ArrowDown') { e.preventDefault(); move(1); }
    else if (e.key === 'ArrowUp') { e.preventDefault(); move(-1); }
    else if (e.key === 'Home') { e.preventDefault(); highlight(list.querySelector('.phone-menu__item')); }
    else if (e.key === 'End') {
      e.preventDefault();
      const items = list.querySelectorAll('.phone-menu__item');
      highlight(items[items.length - 1]);
    } else if (e.key === 'Enter') {
      e.preventDefault();
      const cur = list.querySelector('.is-cursor') || list.querySelector('.phone-menu__item');
      if (cur) choose(cur.dataset.iso);
    } else if (e.key === 'Escape') {
      e.preventDefault();
      close(true);
    } else if (e.key === 'Tab') {
      close(false);
    }
  });

  list.addEventListener('click', (e) => {
    const li = e.target.closest('.phone-menu__item');
    if (li) choose(li.dataset.iso);
  });
  menu.addEventListener('click', (e) => e.stopPropagation());

  // The select keeps working underneath — a change from anywhere repaints.
  select.addEventListener('change', paint);

  field.insertBefore(trigger, select);
  document.body.appendChild(menu);
  field.classList.add('phone-field--enhanced');
  paint();
}

/** Shut every open country list except the one passed in. */
function closePhoneMenus(except) {
  document.querySelectorAll('.phone-menu').forEach((m) => {
    if (m !== except && !m.hidden && m.__closePhoneMenu) m.__closePhoneMenu(false);
  });
}
document.addEventListener('click', () => closePhoneMenus());
