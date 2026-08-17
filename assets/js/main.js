/**
 * Saxane Real Estate — Main JS
 */
document.addEventListener('DOMContentLoaded', () => {
  // ─── Sidebar Toggle (Mobile) ───────────────────────────
  const toggle = document.getElementById('sidebarToggle');
  const sidebar = document.getElementById('sidebar');
  const overlay = document.getElementById('sidebarOverlay');

  if (toggle && sidebar) {
    toggle.addEventListener('click', () => {
      sidebar.classList.toggle('open');
      overlay?.classList.toggle('show');
    });
    overlay?.addEventListener('click', () => {
      sidebar.classList.remove('open');
      overlay.classList.remove('show');
    });
  }

  // ─── Dropdown Toggle ───────────────────────────────────
  // Closing goes through one function so every route out — clicking the
  // trigger again, clicking away, Escape — leaves aria-expanded telling
  // the truth. A trigger that says "expanded" over a closed panel is
  // worse than one that never said anything.
  const closeDropdowns = (except) => {
    document.querySelectorAll('.dropdown__menu.show').forEach(menu => {
      if (menu === except) return;
      menu.classList.remove('show');
      menu.previousElementSibling?.setAttribute?.('aria-expanded', 'false');
    });
  };

  document.querySelectorAll('[data-dropdown]').forEach(trigger => {
    trigger.addEventListener('click', (e) => {
      e.stopPropagation();
      const menu = trigger.nextElementSibling;
      closeDropdowns(menu);
      const open = menu?.classList.toggle('show');
      trigger.setAttribute('aria-expanded', open ? 'true' : 'false');
    });
  });
  document.addEventListener('click', () => closeDropdowns());

  // Escape closes the open panel and hands focus back to what opened it,
  // so a keyboard user is not left adrift at the top of the page.
  document.addEventListener('keydown', (e) => {
    if (e.key !== 'Escape') return;
    const open = document.querySelector('.dropdown__menu.show');
    if (!open) return;
    const trigger = open.previousElementSibling;
    closeDropdowns();
    trigger?.focus?.();
  });

  // ─── Flash Message Auto-dismiss ────────────────────────
  const flash = document.getElementById('flashMessage');
  if (flash) {
    setTimeout(() => {
      flash.style.opacity = '0';
      flash.style.transform = 'translateY(-8px)';
      setTimeout(() => flash.remove(), 300);
    }, 5000);
  }

  // ─── Form Validation Feedback ──────────────────────────
  document.querySelectorAll('form[data-validate]').forEach(form => {
    form.addEventListener('submit', (e) => {
      let valid = true;
      form.querySelectorAll('[required]').forEach(field => {
        if (!field.value.trim()) {
          field.classList.add('form-control--error');
          valid = false;
        } else {
          field.classList.remove('form-control--error');
        }
      });
      // Password match check
      const pw = form.querySelector('[name="password"]');
      const cpw = form.querySelector('[name="confirm_password"]');
      if (pw && cpw && pw.value !== cpw.value) {
        cpw.classList.add('form-control--error');
        valid = false;
      }
      if (!valid) e.preventDefault();
    });
  });

  // ─── Active Sidebar Link ───────────────────────────────
  const currentPage = new URLSearchParams(window.location.search).get('page') || 'dashboard';
  document.querySelectorAll('.sidebar__link').forEach(link => {
    const href = link.getAttribute('href') || '';
    if (href.includes('page=' + currentPage)) {
      link.classList.add('sidebar__link--active');
    }
  });

  // ─── Global search (topbar) ────────────────────────────
  document.querySelectorAll('[data-global-search]').forEach(initGlobalSearch);

  // ─── Tabs ──────────────────────────────────────────────
  document.querySelectorAll('[data-tabs]').forEach(group => {
    const items = group.querySelectorAll('.tabs__item');
    items.forEach(item => {
      item.addEventListener('click', () => {
        const target = item.getAttribute('data-tab');
        items.forEach(i => i.classList.remove('is-active'));
        item.classList.add('is-active');
        group.parentElement
          .querySelectorAll('.tab-panel')
          .forEach(p => p.classList.toggle('is-active', p.getAttribute('data-panel') === target));
      });
    });
  });

  // ─── Modals ────────────────────────────────────────────
  document.querySelectorAll('[data-modal]').forEach(initModal);

  // ─── Location fields ───────────────────────────────────
  document.querySelectorAll('[data-geo]').forEach(initGeoField);

  // ─── Selects that fill other fields in their form ──────
  document.querySelectorAll('[data-prefill]').forEach(initPrefillSelect);

  // ─── Sale form: tax, commission and buyer total ────────
  document.querySelectorAll('[data-sale-calc]').forEach(initSaleCalc);

  // ─── Owner login setup: username preview, password match ──
  document.querySelectorAll('[data-username-source]').forEach(initUsernamePreview);
  document.querySelectorAll('[data-password-confirm]').forEach(initPasswordMatch);

  // ─── Settings console ──────────────────────────────────
  const settingsForm = document.getElementById('settingsForm');
  if (settingsForm) initSettings(settingsForm);

  // ─── Document upload fields ────────────────────────────
  document.querySelectorAll('[data-upload-input]').forEach(initUploadZone);
  document.querySelectorAll('[data-doc-category]').forEach(initCategoryVisibility);
});

/* ═══════════════════════════════════════════════════════════
   GLOBAL SEARCH
   ═══════════════════════════════════════════════════════════ */

const GS_RECENT_KEY = 'saxane.recentPages';
const GS_RECENT_MAX = 4;

/** Escaping for the one place in this file that builds markup from data. */
function gsEscape(value) {
  return String(value).replace(/[&<>"']/g, (c) => (
    { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]
  ));
}

/** Recently opened pages, newest first. A blocked or full store is not an error. */
function gsReadRecent() {
  try {
    const raw = JSON.parse(localStorage.getItem(GS_RECENT_KEY));
    return Array.isArray(raw) ? raw.filter((s) => typeof s === 'string') : [];
  } catch (_) {
    return [];
  }
}

function gsPushRecent(slug) {
  if (!slug) return;
  try {
    const next = [slug, ...gsReadRecent().filter((s) => s !== slug)].slice(0, GS_RECENT_MAX);
    localStorage.setItem(GS_RECENT_KEY, JSON.stringify(next));
  } catch (_) { /* private mode / quota — the search works without history */ }
}

/**
 * Rank one entry against a query, or null when it does not match.
 *
 * The order is what makes short queries feel right: a label that *starts*
 * with what was typed beats one that merely contains it, and a word boundary
 * beats the middle of a word, so "pay" offers Payments before My Payments and
 * never leads with a section that happens to share the letters. Slug and
 * section are matched last so "admin" and "settings" still find their pages.
 *
 * `at` is where to underline in the label; -1 when the match was found
 * somewhere the user cannot see.
 */
function gsMatch(item, query) {
  const q = query.trim().toLowerCase();
  if (!q) return null;

  const label = item.label.toLowerCase();
  const at = label.indexOf(q);
  if (at === 0) return { rank: 0, at, len: q.length };
  if (at > 0) return { rank: /[\s&/-]/.test(label[at - 1]) ? 1 : 2, at, len: q.length };
  if (item.slug.toLowerCase().includes(q)) return { rank: 3, at: -1, len: q.length };
  if (item.section.toLowerCase().includes(q)) return { rank: 4, at: -1, len: q.length };

  // Last chance for a typed-out phrase: every word has to land somewhere in
  // the entry, in any order, so "doc cat" still reaches Document Categories.
  const words = q.split(/\s+/).filter(Boolean);
  if (words.length < 2) return null;
  const hay = (item.label + ' ' + item.section + ' ' + item.slug).toLowerCase();
  return words.every((w) => hay.includes(w)) ? { rank: 5, at: -1, len: 0 } : null;
}

/**
 * The topbar's global search: a combobox over every page the signed-in user
 * is allowed to open, printed into the header by header.php.
 *
 * The whole index is a couple of dozen entries, so it is filtered in place on
 * each keystroke — a request per character would be slower and could answer
 * out of order. Focus never leaves the input; the arrow keys move an
 * aria-activedescendant instead, which is what lets a screen reader announce
 * the highlighted result while the user keeps typing.
 */
function initGlobalSearch(root) {
  const input  = root.querySelector('.header__search-input');
  const panel  = root.querySelector('.header__search-panel');
  const list   = root.querySelector('.gs-list');
  const empty  = root.querySelector('[data-search-empty]');
  const echo   = root.querySelector('[data-empty-query]');
  const clear  = root.querySelector('.header__search-clear');
  const kbd    = root.querySelector('.header__search-kbd');
  const source = root.querySelector('[data-search-index]');
  if (!input || !panel || !list || !source) return;

  let items = [];
  try { items = JSON.parse(source.textContent) || []; } catch (_) { items = []; }

  // A field that can return nothing is worse than no field at all.
  if (!items.length) { root.hidden = true; return; }

  const currentSlug = new URLSearchParams(window.location.search).get('page') || 'dashboard';
  let options = [];
  let active  = -1;

  /* ── markup ─────────────────────────────────────────── */

  const labelHtml = (hit) => {
    const label = hit.item.label;
    if (hit.at < 0 || !hit.len) return gsEscape(label);
    return gsEscape(label.slice(0, hit.at))
         + '<mark>' + gsEscape(label.slice(hit.at, hit.at + hit.len)) + '</mark>'
         + gsEscape(label.slice(hit.at + hit.len));
  };

  const itemHtml = (hit, i) => {
    const here = hit.item.slug === currentSlug;
    return '<a class="gs-item" role="option" id="gs-opt-' + i + '" aria-selected="false"'
         + ' href="' + gsEscape(hit.item.url) + '" data-slug="' + gsEscape(hit.item.slug) + '" tabindex="-1">'
         + '<span class="gs-item__icon"><i class="bi ' + gsEscape(hit.item.icon) + '" aria-hidden="true"></i></span>'
         + '<span class="gs-item__label">' + labelHtml(hit) + '</span>'
         + (here
             ? '<span class="gs-item__here">Here</span>'
             : '<i class="bi bi-arrow-return-left gs-item__enter" aria-hidden="true"></i>')
         + '</a>';
  };

  // role="group" keeps the section headings inside the listbox legal: a
  // listbox may own groups of options, but not loose text. The heading stays
  // visible and is hidden from the accessibility tree, because the group's
  // own label already carries it.
  const groupsHtml = (groups) => {
    let i = 0;
    return groups.map(([label, hits]) =>
      '<div class="gs-group" role="group" aria-label="' + gsEscape(label) + '">'
      + '<div class="gs-group__label" aria-hidden="true">' + gsEscape(label) + '</div>'
      + hits.map((hit) => itemHtml(hit, i++)).join('')
      + '</div>'
    ).join('');
  };

  /* ── what to show ───────────────────────────────────── */

  // Nothing typed yet: where the user has just been, then a short standing
  // list, so the panel opens onto something useful rather than a blank sheet.
  const restingGroups = () => {
    const recent = gsReadRecent()
      .map((slug) => items.find((item) => item.slug === slug))
      .filter(Boolean);
    const groups = [];
    if (recent.length) {
      groups.push(['Recent', recent.map((item) => ({ item, at: -1, len: 0 }))]);
    }
    const rest = items
      .filter((item) => !recent.includes(item))
      .slice(0, Math.max(3, 7 - recent.length));
    if (rest.length) {
      groups.push([recent.length ? 'Jump to' : 'Quick access',
        rest.map((item) => ({ item, at: -1, len: 0 }))]);
    }
    return groups;
  };

  // Ranked globally, then bucketed by section: the strongest hit decides
  // which section heads the list, and grouping tells the user where a result
  // lives without repeating the section on every row.
  const resultGroups = (query) => {
    const hits = [];
    items.forEach((item) => {
      const m = gsMatch(item, query);
      if (m) hits.push({ item, ...m });
    });
    hits.sort((a, b) => a.rank - b.rank || a.item.label.localeCompare(b.item.label));

    const bySection = new Map();
    hits.forEach((hit) => {
      if (!bySection.has(hit.item.section)) bySection.set(hit.item.section, []);
      bySection.get(hit.item.section).push(hit);
    });
    return [...bySection.entries()];
  };

  /* ── state ──────────────────────────────────────────── */

  const setActive = (i, scroll = true) => {
    options.forEach((el, n) => {
      const on = n === i;
      el.classList.toggle('is-active', on);
      el.setAttribute('aria-selected', on ? 'true' : 'false');
    });
    active = i;
    const el = options[i];
    if (!el) { input.removeAttribute('aria-activedescendant'); return; }
    input.setAttribute('aria-activedescendant', el.id);
    if (scroll) el.scrollIntoView({ block: 'nearest' });
  };

  const render = () => {
    const query  = input.value.trim();
    const groups = query ? resultGroups(query) : restingGroups();
    const found  = groups.length > 0;

    list.innerHTML = found ? groupsHtml(groups) : '';
    list.hidden = !found;
    if (empty) {
      if (echo && !found) echo.textContent = query;
      empty.hidden = found;
    }

    options = [...list.querySelectorAll('.gs-item')];
    // Pre-select the top result so Enter is always meaningful, and quietly —
    // moving the list under a screen reader on every keystroke is noise.
    setActive(options.length ? 0 : -1, false);

    if (clear) clear.hidden = query === '';
    if (kbd) kbd.hidden = query !== '';
  };

  const open = () => {
    if (!panel.hidden) return;
    panel.hidden = false;
    root.classList.add('is-open');
    input.setAttribute('aria-expanded', 'true');
  };

  const close = () => {
    if (panel.hidden) return;
    panel.hidden = true;
    root.classList.remove('is-open');
    input.setAttribute('aria-expanded', 'false');
    input.removeAttribute('aria-activedescendant');
  };

  const go = (el) => {
    if (!el) return;
    gsPushRecent(el.dataset.slug);
    window.location.assign(el.href);
  };

  /* ── wiring ─────────────────────────────────────────── */

  input.addEventListener('focus', () => { render(); open(); });
  input.addEventListener('input', () => { render(); open(); });

  input.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') {
      // One step back at a time: clear the query, then close the panel.
      e.preventDefault();
      if (input.value) { input.value = ''; render(); }
      else { close(); input.blur(); }
      return;
    }
    if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
      if (panel.hidden) { render(); open(); }
      if (!options.length) return;
      e.preventDefault();
      const step = e.key === 'ArrowDown' ? 1 : -1;
      setActive((active + step + options.length) % options.length);
      return;
    }
    if (e.key === 'Home' || e.key === 'End') {
      if (panel.hidden || !options.length) return;
      e.preventDefault();
      setActive(e.key === 'Home' ? 0 : options.length - 1);
      return;
    }
    if (e.key === 'Enter') {
      if (panel.hidden || !options[active]) return;
      e.preventDefault();
      go(options[active]);
      return;
    }
    if (e.key === 'Tab') close();
  });

  // Focus stays in the input through a pointer press, so the panel cannot
  // fold away underneath the click that was meant to open a result — the
  // collapsed field on small screens is unfolded by focus.
  panel.addEventListener('mousedown', (e) => e.preventDefault());
  panel.addEventListener('click', (e) => {
    const el = e.target.closest('.gs-item');
    if (!el) return;
    e.preventDefault();
    go(el);
  });
  panel.addEventListener('mousemove', (e) => {
    const el = e.target.closest('.gs-item');
    const i = el ? options.indexOf(el) : -1;
    if (i > -1 && i !== active) setActive(i, false);
  });

  if (clear) {
    clear.addEventListener('click', () => {
      input.value = '';
      render();
      open();
      input.focus();
    });
  }

  document.addEventListener('click', (e) => {
    if (!root.contains(e.target)) close();
  });

  document.addEventListener('keydown', (e) => {
    if (!e.key || !(e.ctrlKey || e.metaKey) || e.key.toLowerCase() !== 'k') return;
    e.preventDefault();
    input.focus();
    input.select();
    render();
    open();
  });
}

/* ═══════════════════════════════════════════════════════════
   DOCUMENT UPLOAD
   ═══════════════════════════════════════════════════════════ */

/** Show the chosen filename, since the real file input is hidden by the CSS. */
function initUploadZone(input) {
  const zone = input.closest('.upload-zone');
  const label = zone && zone.querySelector('[data-upload-label]');
  if (!label) return;

  const original = label.textContent;
  input.addEventListener('change', () => {
    const file = input.files && input.files[0];
    label.textContent = file ? file.name : original;
    zone.classList.toggle('is-filled', Boolean(file));
  });
}

/**
 * Pre-select the visibility a category is normally filed under.
 *
 * Only moves the field while the user has not chosen for themselves — once
 * they touch it, their choice sticks even if they change category afterwards.
 * Getting this wrong would silently publish a title deed.
 */
function initCategoryVisibility(select) {
  const form = select.form;
  if (!form) return;

  const visibility = form.querySelector('[data-doc-visibility]');
  const hint = form.querySelector('[data-doc-visibility-hint]');
  if (!visibility) return;

  let map = {};
  try {
    map = JSON.parse(select.getAttribute('data-visibility-map') || '{}');
  } catch (e) {
    map = {};
  }

  const HINTS = {
    public: 'Shown on the public property listing and downloadable by anyone.',
    staff: 'Visible to administrators and agents only.',
    private: 'Legal and confidential paperwork. Administrators only.'
  };

  let userChose = false;
  visibility.addEventListener('change', () => { userChose = true; updateHint(); });

  function updateHint() {
    if (hint) hint.textContent = HINTS[visibility.value] || '';
  }

  select.addEventListener('change', () => {
    if (userChose) return;
    const preset = map[select.value];
    if (preset) {
      visibility.value = preset;
      updateHint();
    }
  });
}

/* ═══════════════════════════════════════════════════════════
   MODAL
   ═══════════════════════════════════════════════════════════ */

const FOCUSABLE = 'a[href],button:not([disabled]),input:not([disabled]):not([type="hidden"]),' +
                  'select:not([disabled]),textarea:not([disabled]),[tabindex]:not([tabindex="-1"])';

/** Open dialogs are counted, so nesting one never unlocks the page early. */
let openModalCount = 0;

function lockPageScroll(on) {
  openModalCount = Math.max(0, openModalCount + (on ? 1 : -1));
  const locked = openModalCount > 0;
  // Replacing the scrollbar with padding keeps the page from jumping sideways.
  const gap = window.innerWidth - document.documentElement.clientWidth;
  document.body.style.paddingRight = locked && gap > 0 ? gap + 'px' : '';
  document.body.classList.toggle('is-modal-open', locked);
}

/**
 * A dialog over a blurred page. Opened by any [data-modal-open="<id>"]
 * control; closed by the backdrop, [data-modal-close], or Escape.
 */
function initModal(modal) {
  const dialog = modal.querySelector('.modal__dialog');
  if (!dialog) return;
  let lastFocused = null;

  const open = () => {
    if (!modal.hidden) return;
    lastFocused = document.activeElement;
    modal.hidden = false;
    lockPageScroll(true);
    // A frame later, so the entry transition has a state to move from.
    requestAnimationFrame(() => {
      modal.classList.add('is-open');
      const first = dialog.querySelector('[autofocus]')
                 || dialog.querySelector('.modal__body .form-control')
                 || dialog.querySelector(FOCUSABLE);
      first?.focus({ preventScroll: true });
    });
  };

  const close = () => {
    if (modal.hidden) return;
    modal.classList.remove('is-open');
    lockPageScroll(false);
    // Stay mounted until the exit transition ends, but never hang on it.
    setTimeout(() => { modal.hidden = true; }, 240);
    lastFocused?.focus?.({ preventScroll: true });

    // A reopen flag in the URL is spent once; a refresh should not replay it.
    const url = new URL(window.location.href);
    if (url.searchParams.has('modal')) {
      url.searchParams.delete('modal');
      history.replaceState(null, '', url);
    }
  };

  document.querySelectorAll('[data-modal-open="' + modal.id + '"]').forEach(trigger => {
    trigger.addEventListener('click', (e) => { e.preventDefault(); open(); });
  });
  modal.querySelectorAll('[data-modal-close]').forEach(el => el.addEventListener('click', close));

  // Escape is watched on the document: a click on plain dialog text leaves
  // focus on the body, where a listener bound to the modal never hears it.
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && !modal.hidden) { e.preventDefault(); close(); }
  });

  modal.addEventListener('keydown', (e) => {
    if (e.key !== 'Tab') return;

    // Keep focus inside the dialog while it owns the screen.
    const items = [...dialog.querySelectorAll(FOCUSABLE)].filter(el => el.offsetParent !== null);
    if (!items.length) return;
    const first = items[0];
    const last = items[items.length - 1];
    if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
    else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
  });

  // Reopened by the server after a rejected submit.
  if (modal.hasAttribute('data-modal-autoopen')) open();
}

/**
 * A select whose options carry figures the rest of the form needs — the
 * lease that implies a customer and a rent, the property that implies a
 * deposit. Declared as data-prefill="optionKey:fieldName, …".
 *
 * Scoped to the select's own form, so the page form and the popup can each
 * carry one without reaching into the other.
 */
function initPrefillSelect(select) {
  const form = select.form;
  if (!form) return;

  const pairs = select.dataset.prefill
    .split(',')
    .map(pair => pair.split(':').map(part => part.trim()))
    .filter(([key, name]) => key && name);

  select.addEventListener('change', () => {
    const option = select.options[select.selectedIndex];
    if (!option) return;

    pairs.forEach(([key, name]) => {
      const value = option.dataset[key];
      const field = form.querySelector('[name="' + name + '"]');
      // A blank option (e.g. "no lease") leaves what is already typed alone.
      if (!field || value === undefined || value === '') return;
      field.value = value;
      // Announce it, so anything watching the field (the sale calculator)
      // reacts exactly as it would to typing.
      field.dispatchEvent(new Event('input', { bubbles: true }));
    });
  });
}

/**
 * Owner login setup: the username shown beside the email tracks what is typed,
 * so the administrator sees the sign-in name before the account exists.
 *
 * This is a preview only — the server generates the real username and settles
 * any clash, because a name that is free on screen may be taken by the time
 * the form is submitted.
 */
function initUsernamePreview(email) {
  const preview = email.form?.querySelector('[data-username-preview]');
  if (!preview) return;

  const original = preview.value;
  email.addEventListener('input', () => {
    const local = email.value.trim().split('@')[0].replace(/[^a-z0-9._-]/gi, '').toLowerCase();
    preview.value = local || original;
  });
}

/**
 * Confirm-password feedback while typing, rather than after a round trip.
 * The server checks the same thing — this only saves the journey.
 */
function initPasswordMatch(confirm) {
  const form     = confirm.form;
  const password = form?.querySelector('[data-password]');
  const message  = form?.querySelector('[data-password-mismatch]');
  if (!password) return;

  const check = () => {
    const mismatch = confirm.value !== '' && confirm.value !== password.value;
    confirm.classList.toggle('form-control--error', mismatch);
    if (message) message.hidden = !mismatch;
    // Blocks submission with the browser's own message, so the mismatch can
    // never reach the server as a surprise.
    confirm.setCustomValidity(mismatch ? 'The two passwords do not match.' : '');
  };

  confirm.addEventListener('input', check);
  password.addEventListener('input', check);
}

/**
 * Sale form: tax and commission follow the configured rates until an agent
 * types their own figure, and the buyer total tracks the sale amount plus
 * whatever tax ends up being charged.
 */
function initSaleCalc(root) {
  const amount     = root.querySelector('[data-sale-amount]');
  const tax        = root.querySelector('[data-sale-tax]');
  const commission = root.querySelector('[data-sale-commission]');
  const total      = root.querySelector('[data-sale-total]');
  if (!amount) return;

  const taxRate        = parseFloat(root.dataset.taxRate) || 0;
  const commissionRate = parseFloat(root.dataset.commissionRate) || 0;
  const symbol         = root.dataset.currency || '';
  const overridden     = new Set();

  const money = (n) => symbol + n.toLocaleString(undefined, {
    minimumFractionDigits: 2, maximumFractionDigits: 2,
  });

  const recalc = () => {
    const value = parseFloat(amount.value) || 0;
    if (commission && !overridden.has(commission)) {
      commission.value = (value * commissionRate / 100).toFixed(2);
    }
    if (tax && !overridden.has(tax)) {
      tax.value = (value * taxRate / 100).toFixed(2);
    }
    if (total) total.textContent = money(value + (parseFloat(tax?.value) || 0));
  };

  // A form handed back by the server keeps the figures it was given: they
  // only count as defaults while they still match the configured rate.
  const implied = (rate) => (parseFloat(amount.value) || 0) * rate / 100;
  [[tax, taxRate], [commission, commissionRate]].forEach(([field, rate]) => {
    if (field && field.value !== '' && parseFloat(field.value) !== implied(rate)) {
      overridden.add(field);
    }
  });

  [tax, commission].forEach(field => field?.addEventListener('input', () => {
    overridden.add(field);
    recalc();
  }));
  amount.addEventListener('input', recalc);
  recalc();   // a form returned with values already filled shows its total
}

/* ═══════════════════════════════════════════════════════════
   GEO FIELD — coordinates from the device, or typed by hand
   ═══════════════════════════════════════════════════════════ */

function initGeoField(root) {
  const btn     = root.querySelector('[data-geo-locate]');
  const latIn   = root.querySelector('[data-geo-lat]');
  const lngIn   = root.querySelector('[data-geo-lng]');
  const place   = root.querySelector('[data-geo-place]');
  const address = root.querySelector('[data-geo-address]');
  const mapLink = root.querySelector('[data-geo-map]');
  const status  = root.querySelector('[data-geo-status]');
  if (!latIn || !lngIn) return;

  const btnLabel = btn ? btn.innerHTML : '';

  const say = (html, kind) => {
    if (!status) return;
    status.className = 'geo__status is-shown' + (kind ? ' geo__status--' + kind : '');
    status.innerHTML = html;
  };

  /** Both boxes read as a usable pair, or null. */
  const readCoords = () => {
    const lat = parseFloat(latIn.value);
    const lng = parseFloat(lngIn.value);
    const ok = Number.isFinite(lat) && Number.isFinite(lng) &&
               Math.abs(lat) <= 90 && Math.abs(lng) <= 180;
    return ok ? { lat, lng } : null;
  };

  const syncMap = () => {
    if (!mapLink) return;
    const c = readCoords();
    mapLink.classList.toggle('is-disabled', !c);
    mapLink.href = c
      ? `https://www.openstreetmap.org/?mlat=${c.lat}&mlon=${c.lng}#map=17/${c.lat}/${c.lng}`
      : '#';
  };

  const flagRange = (input, max) => {
    const raw = input.value.trim();
    const bad = raw !== '' && !(Number.isFinite(parseFloat(raw)) && Math.abs(parseFloat(raw)) <= max);
    input.classList.toggle('form-control--error', bad);
    return !bad;
  };

  /* A pasted "9.56, 43.18" — or a map link carrying a pair — fills both
     boxes at once, rather than landing as nonsense in one of them. */
  const PAIR = /(-?\d{1,3}(?:\.\d+)?)\s*[,;\s]\s*(-?\d{1,3}(?:\.\d+)?)/;
  const splitPair = (input) => {
    const raw = input.value.trim();
    if (raw === '' || /^-?\d*\.?\d*$/.test(raw)) return;   // a plain number is already fine
    const m = raw.match(/@(-?\d+\.\d+),(-?\d+\.\d+)/)          // google maps /@lat,lng
           || raw.match(/[?&#](?:q|mlat|ll)=(-?\d+\.\d+)[,&](?:mlon=)?(-?\d+\.\d+)/)
           || raw.match(PAIR);
    if (!m) return;
    latIn.value = m[1];
    lngIn.value = m[2];
    latIn.classList.remove('form-control--error');
    lngIn.classList.remove('form-control--error');
    syncMap();
    say('<i class="bi bi-check-circle-fill"></i> Coordinates taken from what you pasted.', 'ok');
  };

  [[latIn, 90], [lngIn, 180]].forEach(([input, max]) => {
    input.addEventListener('input', () => { splitPair(input); syncMap(); });
    input.addEventListener('blur', () => flagRange(input, max));
  });
  syncMap();

  if (!btn) return;

  const setBusy = (on) => {
    btn.disabled = on;
    btn.innerHTML = on
      ? '<i class="bi bi-arrow-repeat spin"></i> Locating…'
      : btnLabel;
  };

  /* Ask the map service what is at these coordinates. Only ever fills a
     field the user left empty, and stays quiet when the lookup fails —
     the coordinates themselves have already landed. */
  const describe = (lat, lng, pinned) => {
    if (!place && !address) return;
    if (place?.value.trim() && address?.value.trim()) return;

    const abort = new AbortController();
    const timer = setTimeout(() => abort.abort(), 7000);

    fetch(`https://nominatim.openstreetmap.org/reverse?format=jsonv2&zoom=16&lat=${lat}&lon=${lng}`,
          { signal: abort.signal, headers: { Accept: 'application/json' } })
      .then(r => r.ok ? r.json() : Promise.reject(new Error(r.status)))
      .then(data => {
        const a = data.address || {};
        const area = [
          a.neighbourhood || a.suburb || a.quarter || a.village || a.hamlet,
          a.city || a.town || a.county || a.state,
        ].filter(Boolean).join(', ');

        let filled = false;
        if (place && area && !place.value.trim()) { place.value = area; filled = true; }
        if (address && data.display_name && !address.value.trim()) { address.value = data.display_name; filled = true; }
        if (filled) say(pinned + ' Address filled in from map data — check it before saving.', 'ok');
      })
      .catch(() => { /* offline or blocked: coordinates stand on their own */ })
      .finally(() => clearTimeout(timer));
  };

  btn.addEventListener('click', () => {
    if (!navigator.geolocation) {
      say('<i class="bi bi-exclamation-triangle-fill"></i> This browser cannot report a location. Enter the coordinates by hand.', 'error');
      return;
    }
    if (window.isSecureContext === false) {
      say('<i class="bi bi-exclamation-triangle-fill"></i> Locating needs an https:// address (or localhost). Enter the coordinates by hand.', 'error');
      return;
    }

    setBusy(true);
    say('<i class="bi bi-arrow-repeat spin"></i> Reading this device’s position…');

    navigator.geolocation.getCurrentPosition(
      (pos) => {
        const { latitude, longitude, accuracy } = pos.coords;
        setBusy(false);
        latIn.value = latitude.toFixed(6);
        lngIn.value = longitude.toFixed(6);
        latIn.classList.remove('form-control--error');
        lngIn.classList.remove('form-control--error');
        syncMap();

        const within = accuracy ? ` (within ±${Math.round(accuracy)} m)` : '';
        const pinned = `<i class="bi bi-check-circle-fill"></i> Pinned to ${latIn.value}, ${lngIn.value}${within}.`;
        say(pinned, 'ok');
        describe(latitude, longitude, pinned);
      },
      (err) => {
        setBusy(false);
        const reasons = {
          1: 'Location permission was refused. Allow it from the icon in the address bar, then try again.',
          2: 'This device could not work out where it is. Check that location services are switched on.',
          3: 'Locating took too long. Try again — near a window or outdoors gives a faster fix.',
        };
        say('<i class="bi bi-exclamation-triangle-fill"></i> ' +
            (reasons[err.code] || 'Could not read this device’s location.'), 'error');
      },
      { enableHighAccuracy: true, timeout: 12000, maximumAge: 60000 }
    );
  });
}

/**
 * Settings page: section nav, dirty tracking, and live field previews.
 */
function initSettings(form) {
  const nav = form.querySelector('[data-settings-nav]');
  const STORE_KEY = 'saxane.settings.section';

  // ── Section switching (remembered across the save redirect) ──
  const showSection = (key) => {
    let matched = false;
    form.querySelectorAll('[data-section-panel]').forEach(p => {
      const on = p.getAttribute('data-section-panel') === key;
      p.classList.toggle('is-active', on);
      if (on) matched = true;
    });
    if (!matched) return false;
    nav?.querySelectorAll('.settings-nav__item').forEach(b => {
      b.classList.toggle('is-active', b.getAttribute('data-section') === key);
    });
    try { sessionStorage.setItem(STORE_KEY, key); } catch (e) { /* private mode */ }
    return true;
  };

  nav?.querySelectorAll('.settings-nav__item').forEach(btn => {
    btn.addEventListener('click', () => showSection(btn.getAttribute('data-section')));
  });

  try {
    const saved = sessionStorage.getItem(STORE_KEY);
    if (saved) showSection(saved);
  } catch (e) { /* private mode */ }

  // ── Dirty tracking ──────────────────────────────────────
  const bar = form.querySelector('[data-settings-bar]');
  const status = form.querySelector('[data-settings-status]');
  const saveBtn = form.querySelector('button[type="submit"]');
  const resetBtn = form.querySelector('[data-settings-reset]');
  let dirty = false;
  let saving = false;

  const setDirty = (on) => {
    dirty = on;
    bar?.classList.toggle('is-dirty', on);
    if (status) status.textContent = on ? 'You have unsaved changes' : 'All changes saved';
    if (saveBtn) saveBtn.disabled = !on;
    if (resetBtn) resetBtn.disabled = !on;
  };
  setDirty(false);

  form.addEventListener('input', () => setDirty(true));
  form.addEventListener('change', () => setDirty(true));
  form.addEventListener('submit', () => { saving = true; });
  form.addEventListener('reset', () => {
    // Field values are restored after this event fires.
    setTimeout(() => { setDirty(false); syncAll(); }, 0);
  });

  window.addEventListener('beforeunload', (e) => {
    if (!dirty || saving) return;
    e.preventDefault();
    e.returnValue = '';
  });

  // ── Logo: preview the chosen file before it is uploaded ─
  const logoInput = form.querySelector('[data-logo-input]');
  const logoBox = form.querySelector('[data-logo-preview]');
  const logoName = form.querySelector('[data-logo-filename]');
  const logoRemove = form.querySelector('[data-logo-remove]');
  const logoOriginal = logoBox ? logoBox.innerHTML : '';
  const logoOriginalName = logoName ? logoName.textContent : '';
  let logoObjectUrl = null;

  const clearObjectUrl = () => {
    if (logoObjectUrl) { URL.revokeObjectURL(logoObjectUrl); logoObjectUrl = null; }
  };

  const restoreLogo = () => {
    clearObjectUrl();
    if (logoBox) { logoBox.innerHTML = logoOriginal; logoBox.classList.remove('is-error'); }
    if (logoName) logoName.textContent = logoOriginalName;
  };

  const syncLogo = () => {
    if (!logoInput || !logoBox) return;
    const file = logoInput.files && logoInput.files[0];

    if (!file) { restoreLogo(); return; }

    // Ticking "remove" and picking a file are contradictory; the file wins.
    if (logoRemove && logoRemove.checked) {
      logoRemove.checked = false;
      logoBox.classList.remove('is-muted');
    }

    clearObjectUrl();
    logoObjectUrl = URL.createObjectURL(file);
    logoBox.innerHTML = '';
    logoBox.classList.remove('is-error');
    const img = new Image();
    img.alt = 'Selected logo';
    img.src = logoObjectUrl;
    logoBox.appendChild(img);
    if (logoName) logoName.textContent = file.name;
  };

  logoInput?.addEventListener('change', syncLogo);

  logoRemove?.addEventListener('change', () => {
    if (logoRemove.checked) {
      if (logoInput) logoInput.value = '';
      restoreLogo();
      logoBox?.classList.add('is-muted');
    } else {
      logoBox?.classList.remove('is-muted');
    }
  });

  const syncSwitches = () => {
    form.querySelectorAll('.switch input[type="checkbox"]').forEach(cb => {
      const text = cb.closest('.switch')?.querySelector('.switch__text');
      if (text) text.textContent = cb.checked ? text.dataset.on : text.dataset.off;
    });
  };
  form.querySelectorAll('.switch input[type="checkbox"]')
      .forEach(cb => cb.addEventListener('change', syncSwitches));

  // Currency picker fills the symbol field, but never overwrites a custom one.
  const currency = form.querySelector('[data-currency-select]');
  const symbol = form.querySelector('[data-currency-symbol]');
  const symbols = window.__currencySymbols || {};
  if (currency && symbol) {
    let previous = currency.value;
    currency.addEventListener('change', () => {
      const current = symbol.value.trim();
      if (current === '' || current === symbols[previous]) {
        symbol.value = symbols[currency.value] || current;
      }
      previous = currency.value;
    });
  }

  const syncAll = () => { syncLogo(); syncSwitches(); };
}
