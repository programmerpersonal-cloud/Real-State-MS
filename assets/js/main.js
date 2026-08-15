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
  document.querySelectorAll('[data-dropdown]').forEach(trigger => {
    trigger.addEventListener('click', (e) => {
      e.stopPropagation();
      const menu = trigger.nextElementSibling;
      document.querySelectorAll('.dropdown__menu.show').forEach(m => {
        if (m !== menu) m.classList.remove('show');
      });
      menu?.classList.toggle('show');
    });
  });
  document.addEventListener('click', () => {
    document.querySelectorAll('.dropdown__menu.show').forEach(m => m.classList.remove('show'));
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

  // ─── Ctrl/Cmd + K → focus sidebar search ───────────────
  const search = document.getElementById('globalSearch');
  document.addEventListener('keydown', (e) => {
    if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') {
      e.preventDefault();
      search?.focus();
      search?.select();
    }
  });

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

  // ─── Settings console ──────────────────────────────────
  const settingsForm = document.getElementById('settingsForm');
  if (settingsForm) initSettings(settingsForm);
});

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
