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

  // ─── Flash message ─────────────────────────────────────
  // A confirmation becomes a toast; a problem stays on the page. The two
  // are not the same kind of message: "Payment recorded" has been read by
  // the time it fades, but "Fix these three fields" has to still be there
  // while they are being fixed, and a four-second toast would take it away
  // mid-correction. The inline alert is also the no-JS path for both.
  initFlash(document.getElementById('flashMessage'));

  // ─── Forms: inline validation, error summary, submit state ──
  document.querySelectorAll('form[data-validate]').forEach(initFormValidation);

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
  document.querySelectorAll('[data-tabs]').forEach(initTabs);

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

  // ─── Row action menus (the ⋮ in a table's last column) ──
  document.querySelectorAll('[data-row-menu]').forEach(initRowMenu);

  // ─── Confirmation dialogs ──────────────────────────────
  // Delegated from the document rather than bound per control, so a menu
  // rendered after load — or one inside a dialog — is covered too.
  initConfirm();

  // ─── List view switch (table / grid) ───────────────────
  document.querySelectorAll('[data-view-switch]').forEach(initViewSwitch);

  // ─── Password reveal ───────────────────────────────────
  document.querySelectorAll('[data-reveal]').forEach(initReveal);

  // ─── Shell (Phase 6) ───────────────────────────────────
  initNavGroups();
  initRailToggle();
  initHeaderCondense();

  // ─── Step 2.1 ──────────────────────────────────────────
  document.querySelectorAll('[data-filter-popover]').forEach(initFilterPopover);
  initNavProgress();

  // ─── Step 5 ────────────────────────────────────────────
  initStickyActions();

  // ─── Step 6 ────────────────────────────────────────────
  document.querySelectorAll('.table-wrap').forEach(initTableScroll);

  // ─── Phone country picker ──────────────────────────────
  // Progressive: the <select> underneath is what posts, and is what a
  // browser with scripting off is left holding.
  document.querySelectorAll('[data-phone-field]').forEach(initPhoneField);
});

/**
 * Tell the CSS how a table is scrolled.
 *
 * CSS can style a scroll container but cannot ask how far it has been
 * scrolled, so the three states a wide table needs — it overflows at all,
 * there is content hidden to the left, there is content hidden to the right —
 * are set here as classes and drawn there.
 *
 * The alternative is what was here before: a raw scrollbar, no indication
 * that there was more table until you noticed it, and an identity column that
 * slid away the moment you used it, leaving rows you could read but not
 * identify.
 */
function initTableScroll(wrap) {
  // The classes go on the card, because the edge shadows have to sit over the
  // table rather than scroll along with it.
  const card = wrap.closest('.table-card') || wrap.parentElement;
  if (!card) return;

  // A few pixels of slack, used by every comparison below. Sub-pixel column
  // layout leaves a fractional remainder on tables with nothing actually
  // hidden — six columns rounding up a fraction each is a 2-3px "overflow"
  // that is not one. Without the slack a table drops a whole column to pay
  // for it, and an affordance appears that never quite goes away, which reads
  // as a rendering fault.
  const SLACK = 4;
  const overflow = () => wrap.scrollWidth - wrap.clientWidth;

  let settling = false;

  /**
   * Show the most columns this table can fit in the width it actually has.
   *
   * The stylesheet's breakpoints are a baseline for the no-scripting case and
   * a reasonable first paint, but they can only reason about the viewport.
   * They cannot know that the rail is pinned, that this is the one table that
   * needs 1216px of the 1127 a 1440 window gave it, or — much more often —
   * that a table they just stripped to four columns had room for six.
   *
   * So all three states are tried here, widest first, and the first one that
   * fits wins. Re-derived from scratch every pass rather than nudged from the
   * current state, because a window being widened has to be able to bring
   * columns back, not only take them away.
   */
  const TIERS = ['is-cols-all', 'is-cols-mid', 'is-cols-min'];
  const fit = () => {
    for (const tier of TIERS) {
      card.classList.remove(...TIERS);
      card.classList.add(tier);
      if (overflow() <= SLACK) return;  // fits — keep this one
    }
    // Nothing fits: a phone cannot hold eight columns however few are hidden.
    // The narrowest state stands and the wrap scrolls, which is what the
    // pinned first column and the edge shadows below are for.
  };

  let frame = 0;
  const sync = () => {
    frame = 0;
    settling = true;        // the class changes below resize the wrap
    fit();

    const max = overflow();
    const scrollable = max > SLACK;
    card.classList.toggle('is-scrollable', scrollable);
    card.classList.toggle('is-scroll-start', scrollable && wrap.scrollLeft > SLACK);
    card.classList.toggle('is-scroll-end', scrollable && wrap.scrollLeft < max - SLACK);
    settling = false;
  };
  const schedule = () => { if (!frame) frame = requestAnimationFrame(sync); };

  // Scrolling moves the shadows but must never re-run the column fit: the
  // table's width has not changed, and re-measuring mid-scroll would fight
  // the gesture.
  const shadows = () => {
    const max = overflow();
    card.classList.toggle('is-scroll-start', max > SLACK && wrap.scrollLeft > SLACK);
    card.classList.toggle('is-scroll-end', max > SLACK && wrap.scrollLeft < max - SLACK);
  };
  let shadowFrame = 0;
  wrap.addEventListener('scroll', () => {
    if (!shadowFrame) shadowFrame = requestAnimationFrame(() => { shadowFrame = 0; shadows(); });
  }, { passive: true });

  sync();
  window.addEventListener('resize', schedule);

  // The table changes width without the window doing so: the density toggle
  // switches, a filter returns longer values, the rail collapses. The guard
  // is what stops fit() — which resizes the very box being observed — from
  // calling itself forever.
  if (typeof ResizeObserver === 'function') {
    new ResizeObserver(() => { if (!settling) schedule(); }).observe(wrap);
  }
}

/**
 * Pin a form's action bar to the foot of the viewport — but only on a form
 * that is genuinely taller than the screen.
 *
 * On a form that already fits, a pinned Save is a bar occupying space to
 * solve a problem nobody has: the button is visible anyway. It earns its
 * place only once finishing the form means losing sight of how to submit it.
 * So the class is applied here, from measurement, rather than baked into the
 * markup of every form.
 *
 * The bar sits inside the card and cancels the card's inset with negative
 * margins, which only lands correctly when it is flush with the card's
 * bottom edge — hence the last-child walk. Anything else is left alone.
 */
function initStickyActions() {
  const bars = Array.from(document.querySelectorAll('form .form-actions'))
    .filter((el) => {
      if (el.closest('.modal')) return false;   // a modal has its own footer
      const body = el.closest('.card__body');
      if (!body) return false;
      // Flush with the card's foot? Walk up to the card body; every step has
      // to be the last thing in its parent, or there is content below the bar
      // and pulling it to the card edge would overlap that content.
      for (let n = el; n && n !== body; n = n.parentElement) {
        if (n.parentElement && n !== n.parentElement.lastElementChild) return false;
      }
      return true;
    });
  if (!bars.length) return;

  const measure = () => {
    bars.forEach((el) => {
      const form = el.closest('form');
      // Measured without the class, so the reading is of the form itself and
      // not of a layout the previous pass produced.
      el.classList.remove('form-actions--sticky');
      // A quarter-screen of margin: a form a few pixels over the fold does
      // not need pinning, and flipping the bar on and off around an exact
      // match would just make the page twitch on resize.
      if (form && form.getBoundingClientRect().height > window.innerHeight * 1.25) {
        el.classList.add('form-actions--sticky');
      }
    });
  };

  // A sticky element gives no signal that it is currently stuck, so the
  // shadow is toggled from the geometry: pinned to the viewport foot means
  // there is content underneath to lift away from; resting at the end of the
  // form means there is not, and the shadow would be a shadow over nothing.
  let frame = 0;
  const pinned = () => {
    frame = 0;
    bars.forEach((el) => {
      if (!el.classList.contains('form-actions--sticky')) return;
      const stuck = el.getBoundingClientRect().bottom >= window.innerHeight - 1;
      el.classList.toggle('is-pinned', stuck);
    });
  };
  const schedule = () => { if (!frame) frame = requestAnimationFrame(pinned); };

  measure();
  pinned();
  window.addEventListener('scroll', schedule, { passive: true });

  // Re-measured on resize and on rotation, because both change which side of
  // the threshold a form sits on. Debounced: this reads layout, and doing it
  // per resize event is a scroll-jank generator.
  let t = null;
  window.addEventListener('resize', () => {
    clearTimeout(t);
    t = setTimeout(() => { measure(); pinned(); }, 150);
  });

  // Forms grow: an error summary appears, a conditional section unfolds, a
  // repeatable row is added. One observer over the action bars catches all of
  // it without any of those features having to know this exists.
  if (typeof ResizeObserver === 'function') {
    let pending = null;
    const ro = new ResizeObserver(() => {
      clearTimeout(pending);
      // Deferred past the current frame: measure() toggles a class that
      // changes layout, which would otherwise re-enter the observer.
      pending = setTimeout(() => { measure(); pinned(); }, 200);
    });
    bars.forEach((el) => { const f = el.closest('form'); if (f) ro.observe(f); });
  }
}

/**
 * The toolbar's filter popover.
 *
 * Built on <details>, so with scripting off it is already a working
 * disclosure: closed it is a button, open it is a panel, and the controls
 * inside submit with the surrounding form either way. Everything here is the
 * polish a native <details> does not do — closing on outside click and on
 * Escape, and flipping the panel when the trigger sits near the right edge.
 */
function initFilterPopover(details) {
  const summary = details.querySelector('summary');
  if (!summary) return;

  const panel = details.querySelector('.toolbar__popover');

  const edge = () => {
    // Measured on open: the trigger's position depends on how much room the
    // search field took, which depends on the viewport.
    const r = summary.getBoundingClientRect();
    // The panel's own width, not a number copied out of the stylesheet. It is
    // min(420px, 100vw - 32px), so a copied 420 is wrong on exactly the narrow
    // windows where getting this right matters most.
    const w = panel && panel.offsetWidth ? panel.offsetWidth : 420;
    details.classList.toggle('toolbar__filters--end', r.left + w > window.innerWidth - 16);
  };

  details.addEventListener('toggle', () => {
    if (details.open) edge();
  });

  // A popover the server rendered open — which is what happens whenever a
  // filter is applied — never fires 'toggle', so it was never measured and
  // sat ~180px off the right of the screen at every desktop width.
  //
  // Deferred a frame rather than run here: at DOMContentLoaded the toolbar has
  // not settled, the search field has not taken its share of the row, and the
  // trigger is still far enough left that the panel looks like it fits.
  if (details.open) requestAnimationFrame(edge);

  // The window can be resized under an open panel, which moves the trigger.
  window.addEventListener('resize', () => { if (details.open) edge(); });

  document.addEventListener('click', (e) => {
    if (details.open && !details.contains(e.target)) details.open = false;
  });

  details.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && details.open) {
      e.preventDefault();
      details.open = false;
      summary.focus();
    }
  });
}

/**
 * A progress bar across the top of the viewport while a page loads.
 *
 * This application renders on the server, so the only genuine wait is the one
 * between committing to a navigation and the next document painting. Nothing
 * reported that; a slow register just sat there looking ignored.
 *
 * It cannot stick. Leaving the page destroys the element outright, and the two
 * ways a navigation can be abandoned — the browser cancelling it, or a
 * download/`target=_blank` that was never a navigation at all — are covered by
 * the pageshow handler (bfcache back) and a timeout that retires the bar if
 * nothing has happened.
 */
function initNavProgress() {
  const bar = document.createElement('div');
  bar.className = 'nav-progress';
  bar.setAttribute('aria-hidden', 'true');
  document.body.appendChild(bar);

  let timer = null;
  let failsafe = null;

  const done = () => {
    clearInterval(timer);
    clearTimeout(failsafe);
    timer = failsafe = null;
    bar.style.width = '100%';
    setTimeout(() => {
      bar.classList.remove('is-active');
      bar.style.width = '0';
    }, 180);
  };

  const start = () => {
    if (timer) return;
    let width = 0;
    bar.classList.add('is-active');
    bar.style.width = '8%';
    // Creeps towards 90% and waits there: the bar reports that the request is
    // in flight, and only the new document can honestly say it finished.
    timer = setInterval(() => {
      width = Math.min(width + (90 - width) * 0.12, 90);
      bar.style.width = width + '%';
    }, 220);
    failsafe = setTimeout(done, 12000);
  };

  document.addEventListener('click', (e) => {
    // Something nearer the link already cancelled it — the sign-in screen
    // turns its own links into an in-page panel switch, for one. A bar that
    // creeps for twelve seconds over a navigation that never started is
    // worse than no bar at all.
    if (e.defaultPrevented) return;
    const a = e.target.closest('a[href]');
    if (!a) return;
    const href = a.getAttribute('href');
    if (!href || href.startsWith('#') || href.startsWith('javascript:')) return;
    if (a.target === '_blank' || a.hasAttribute('download')) return;
    if (e.metaKey || e.ctrlKey || e.shiftKey || e.altKey || e.button !== 0) return;
    // Same origin only: a link to the public site in a new tab is not this
    // document's wait to report.
    if (a.origin && a.origin !== window.location.origin) return;
    start();
  });

  // A submit is the other committed navigation. The confirm dialog cancels
  // and re-fires a submit, so this can fire twice — start() is idempotent.
  document.addEventListener('submit', (e) => {
    if (e.target && e.target.getAttribute('target') !== '_blank') start();
  });

  window.addEventListener('pagehide', done);
  // Coming back through the bfcache restores the old DOM with the bar still
  // mid-crawl; this is what clears it.
  window.addEventListener('pageshow', done);
}

/* ═══════════════════════════════════════════════════════════
   SHELL
   ═══════════════════════════════════════════════════════════ */

const RAIL_KEY = 'saxane.rail';
const NAV_KEY = 'saxane.nav.closed';

/** Read a JSON array from storage, tolerating private mode and junk. */
function readClosedGroups() {
  try {
    const raw = localStorage.getItem(NAV_KEY);
    const parsed = raw ? JSON.parse(raw) : [];
    return Array.isArray(parsed) ? parsed : [];
  } catch (e) {
    return [];
  }
}

function writeClosedGroups(keys) {
  try {
    localStorage.setItem(NAV_KEY, JSON.stringify(keys));
  } catch (e) { /* private mode: the rail simply forgets between visits */ }
}

/**
 * Collapsible navigation groups.
 *
 * The group holding the current page is always opened, whatever was stored.
 * A remembered preference that hides the page you are on is a bug wearing the
 * costume of a preference — so `data-holds-current` overrules storage, and the
 * stored entry is dropped so it stops fighting on the next visit too.
 */
function initNavGroups() {
  const sections = Array.from(document.querySelectorAll('[data-nav-section]'));
  if (!sections.length) return;

  let closed = readClosedGroups();

  sections.forEach((section) => {
    const toggle = section.querySelector('[data-nav-toggle]');
    if (!toggle) return;   // single-item group: nothing to collapse

    const key = section.dataset.navSection;
    const holdsCurrent = section.hasAttribute('data-holds-current');

    if (closed.includes(key) && !holdsCurrent) {
      setGroup(section, toggle, false);
    } else if (holdsCurrent && closed.includes(key)) {
      // The group holding the current page always opens, and the stale entry
      // is dropped so it stops fighting on the next visit too.
      closed = closed.filter((k) => k !== key);
      writeClosedGroups(closed);
    }

    toggle.addEventListener('click', () => {
      const open = toggle.getAttribute('aria-expanded') === 'true';
      setGroup(section, toggle, !open);
      closed = readClosedGroups().filter((k) => k !== key);
      if (open) closed.push(key);
      writeClosedGroups(closed);
    });
  });

  syncGroupInert();
}

/**
 * Open or close one group.
 *
 * Group collapse and rail collapse are separate states and must not be
 * confused: this one only ever touches the section it was given.
 */
function setGroup(section, toggle, open) {
  section.classList.toggle('is-collapsed', !open);
  toggle.setAttribute('aria-expanded', String(open));
  syncGroupInert();
}

/**
 * Reconcile `inert` with both states at once.
 *
 * `inert` is what takes hidden rows out of the tab order — collapsing a list
 * visually while leaving its links focusable is how a keyboard user ends up
 * tabbing into something they cannot see.
 *
 * But a collapsed *rail* re-opens every group, because there is no heading
 * left to press. If a group's stored collapse also left `inert` on, those
 * icons would be visible and unreachable. So inert is derived from both
 * states here rather than set at the moment a group is toggled.
 */
function syncGroupInert() {
  const railCollapsed = document.documentElement.classList.contains('rail-collapsed');
  document.querySelectorAll('[data-nav-section]').forEach((section) => {
    const panel = section.querySelector('.sidebar__items');
    if (!panel) return;
    const hidden = !railCollapsed && section.classList.contains('is-collapsed');
    if (hidden) panel.setAttribute('inert', '');
    else panel.removeAttribute('inert');
  });
}

/**
 * The 68px icon rail.
 *
 * The class lives on <html> and is also written by an inline script in the
 * layout head, so the collapsed width is correct on the first paint rather
 * than snapping into place after the stylesheet and this file have both run.
 */
function initRailToggle() {
  const btn = document.querySelector('[data-rail-toggle]');
  if (!btn) return;

  const root = document.documentElement;
  const sync = () => {
    const collapsed = root.classList.contains('rail-collapsed');
    btn.setAttribute('aria-pressed', String(collapsed));
    btn.setAttribute('aria-label', collapsed ? 'Expand the navigation rail' : 'Collapse the navigation rail');
    btn.title = collapsed ? 'Expand rail' : 'Collapse rail';
  };
  sync();

  btn.addEventListener('click', () => {
    const collapsed = root.classList.toggle('rail-collapsed');
    try {
      localStorage.setItem(RAIL_KEY, collapsed ? 'collapsed' : 'expanded');
    } catch (e) { /* private mode: the choice lasts for this page only */ }
    sync();
    // The two states interact in exactly one place: a collapsed rail forces
    // every group open, so the inert flags have to be recomputed here.
    syncGroupInert();
  });
}

/**
 * Condense the page header once the page has scrolled past it.
 *
 * Only the title size and the subtitle's height change; nothing is removed
 * and no control moves out of reach, so a keyboard user part-way down a long
 * register does not lose the thing they were heading for.
 *
 * Under prefers-reduced-motion the class still toggles — the layout still
 * tightens — but the CSS transitions are switched off, so it arrives at the
 * same place without animating. Reduced motion means less movement, not less
 * function.
 */
function initHeaderCondense() {
  const header = document.querySelector('[data-page-header]');
  if (!header) return;

  const ON = 90;
  const OFF = 60;   // hysteresis: a single threshold flickers when a scroll
                    // settles exactly on it
  let condensed = false;
  let ticking = false;

  const apply = () => {
    ticking = false;
    const y = window.scrollY || window.pageYOffset;
    if (!condensed && y > ON) {
      condensed = true;
      document.body.classList.add('is-condensed');
    } else if (condensed && y < OFF) {
      condensed = false;
      document.body.classList.remove('is-condensed');
    }
  };

  window.addEventListener('scroll', () => {
    if (ticking) return;
    ticking = true;
    requestAnimationFrame(apply);
  }, { passive: true });

  apply();
}

/**
 * Show/hide a password field.
 *
 * A field nobody can read is a field people type badly and then choose a
 * shorter password for. The toggle is a real button so it is reachable by
 * keyboard, and it reports its state through aria-pressed rather than only
 * by swapping the icon.
 */
function initReveal(wrap) {
  const input = wrap.querySelector('input');
  const btn = wrap.querySelector('[data-reveal-toggle]');
  if (!input || !btn) return;

  btn.addEventListener('click', () => {
    const shown = input.type === 'text';
    input.type = shown ? 'password' : 'text';
    btn.setAttribute('aria-pressed', String(!shown));
    btn.setAttribute('aria-label', shown ? 'Show password' : 'Hide password');
    btn.innerHTML = '<i class="bi bi-eye' + (shown ? '' : '-slash') + '" aria-hidden="true"></i>';
    // Typing continues where it left off rather than at the end.
    const at = input.selectionStart;
    input.focus();
    if (at !== null) input.setSelectionRange(at, at);
  });
}

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
    const files = input.files;
    const count = files ? files.length : 0;

    // One file is named; several are counted. Listing eight filenames in a
    // 280px zone wraps to five lines and says less than "8 images selected".
    label.textContent = count === 0 ? original
      : count === 1 ? files[0].name
      : count + ' images selected';

    zone.classList.toggle('is-filled', count > 0);
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
/**
 * Point a dialog's transform-origin at whatever was clicked to summon it, so
 * it grows out of that control instead of appearing in the middle of the
 * screen with no visible cause. Shared with the confirm dialog, which is
 * opened the same way from row menus and buttons all over the app.
 *
 * Call it while the dialog is mounted but before the entry transition starts:
 * the box being measured has to be the one about to move.
 */
function setModalOrigin(dialog, from) {
  if (!dialog) return;
  // No source means the keyboard, a redirect, or a rejected form reopening the
  // dialog — nothing on screen for it to grow from, so clear any origin left
  // by a previous opening and let the CSS fallback (the centre) apply.
  if (!from) { dialog.style.removeProperty('--modal-origin-x');
               dialog.style.removeProperty('--modal-origin-y'); return; }

  // An element, or a rect already taken from one. The confirm dialog closes
  // the row menu its trigger lives in before opening, so it measures first
  // and hands the rect over.
  const t = typeof from.getBoundingClientRect === 'function'
    ? from.getBoundingClientRect() : from;
  if (!t.width && !t.height) return;            // detached, or display:none
  const d = dialog.getBoundingClientRect();
  if (!d.width || !d.height) return;

  // Percentages of the dialog's own box: transform-origin resolves against
  // the element it is set on, not the viewport.
  // Clamped just outside that box — a trigger far down a long page would
  // otherwise throw the origin so far off that the dialog swings in from the
  // side rather than growing.
  const pin = (n) => Math.max(-50, Math.min(150, n)).toFixed(1) + '%';
  dialog.style.setProperty('--modal-origin-x',
    pin(((t.left + t.width / 2) - d.left) / d.width * 100));
  dialog.style.setProperty('--modal-origin-y',
    pin(((t.top + t.height / 2) - d.top) / d.height * 100));
}

function initModal(modal) {
  const dialog = modal.querySelector('.modal__dialog');
  if (!dialog) return;
  let lastFocused = null;

  const open = (trigger) => {
    if (!modal.hidden) return;
    lastFocused = document.activeElement;
    modal.hidden = false;
    // Measured while the dialog is mounted but before .is-open starts the
    // transition, so the box being measured is the one about to move.
    setModalOrigin(dialog, trigger);
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
    trigger.addEventListener('click', (e) => { e.preventDefault(); open(trigger); });
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
