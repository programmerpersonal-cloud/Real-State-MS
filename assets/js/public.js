/**
 * Saxane Real Estate — Public site behaviour.
 *
 * Everything here is progressive enhancement: the site is fully usable
 * with JavaScript disabled. Nothing below creates content, it only
 * improves interaction with content the server already rendered.
 */
(function () {
  'use strict';

  var prefersReducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  /* ── Sticky header state ──────────────────────────────────
     Adds .is-scrolled to <html> so the topbar can collapse and the
     navbar can gain a shadow. rAF-throttled to keep scrolling smooth. */
  function initStickyHeader() {
    var root = document.documentElement;
    var ticking = false;

    function update() {
      root.classList.toggle('is-scrolled', window.scrollY > 24);
      ticking = false;
    }

    window.addEventListener('scroll', function () {
      if (!ticking) {
        ticking = true;
        window.requestAnimationFrame(update);
      }
    }, { passive: true });

    update();
  }

  /* ── Mobile navigation drawer ─────────────────────────────
     Off-canvas panel with a scrim, body scroll lock, Escape to close,
     and focus returned to the trigger so keyboard users don't get lost. */
  function initNavDrawer() {
    var toggle = document.getElementById('navToggle');
    var menu   = document.getElementById('navmenu');
    var scrim  = document.getElementById('navScrim');
    var close  = document.getElementById('navClose');
    if (!toggle || !menu || !scrim) return;

    function setOpen(open) {
      menu.classList.toggle('is-open', open);
      scrim.classList.toggle('is-open', open);
      scrim.hidden = !open;
      document.body.classList.toggle('has-drawer', open);
      toggle.setAttribute('aria-expanded', String(open));
      toggle.setAttribute('aria-label', open ? 'Close menu' : 'Open menu');

      if (open) {
        // First *visible* control, not simply the first in the DOM: on
        // mobile the panel's own head is display:none, and focusing a
        // hidden button would strand the focus ring where nobody can
        // see it.
        var first = Array.prototype.slice.call(menu.querySelectorAll('a, button'))
          .filter(function (el) { return el.offsetParent !== null; })[0];
        if (first) first.focus();
      } else {
        toggle.focus();
      }
    }

    toggle.addEventListener('click', function () {
      setOpen(!menu.classList.contains('is-open'));
    });
    scrim.addEventListener('click', function () { setOpen(false); });
    if (close) close.addEventListener('click', function () { setOpen(false); });

    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && menu.classList.contains('is-open')) setOpen(false);
    });

    // Reset when the viewport grows past the drawer breakpoint, so the
    // desktop nav never inherits a stale scroll lock.
    window.matchMedia('(min-width: 1025px)').addEventListener('change', function (e) {
      if (e.matches && menu.classList.contains('is-open')) setOpen(false);
    });
  }

  /* ── Property detail gallery ──────────────────────────────
     Stage + thumbnail strip, arrow-key navigable, with a lightbox.
     Falls back to a plain first image if JS never runs. */
  function initGallery() {
    var gallery = document.querySelector('[data-gallery]');
    if (!gallery) return;

    var slides  = Array.prototype.slice.call(gallery.querySelectorAll('.pgallery__slide'));
    var thumbs  = Array.prototype.slice.call(gallery.querySelectorAll('.pgallery__thumb'));
    var current = gallery.querySelector('[data-gallery-current]');
    var index   = 0;
    if (slides.length === 0) return;

    function show(i) {
      index = (i + slides.length) % slides.length;
      slides.forEach(function (s, n) {
        s.classList.toggle('is-active', n === index);
        s.setAttribute('aria-hidden', String(n !== index));
      });
      thumbs.forEach(function (t, n) {
        t.classList.toggle('is-active', n === index);
        t.setAttribute('aria-selected', String(n === index));
      });
      if (current) current.textContent = String(index + 1);

      var active = thumbs[index];
      if (active && active.scrollIntoView) {
        active.scrollIntoView({ block: 'nearest', inline: 'nearest',
          behavior: prefersReducedMotion ? 'auto' : 'smooth' });
      }
    }

    thumbs.forEach(function (t, n) {
      t.addEventListener('click', function () { show(n); });
    });

    gallery.addEventListener('click', function (e) {
      var nav = e.target.closest('[data-gallery-nav]');
      if (nav) show(index + (nav.getAttribute('data-gallery-nav') === 'next' ? 1 : -1));
    });

    gallery.addEventListener('keydown', function (e) {
      if (e.key === 'ArrowRight') { e.preventDefault(); show(index + 1); }
      if (e.key === 'ArrowLeft')  { e.preventDefault(); show(index - 1); }
    });

    initLightbox(gallery, slides, function () { return index; }, show);
  }

  /* Lightbox: full-screen view of the active photo. */
  function initLightbox(gallery, slides, getIndex, show) {
    var box = document.getElementById('lightbox');
    if (!box) return;

    var img     = box.querySelector('img');
    var counter = box.querySelector('[data-lightbox-count]');
    var lastFocus = null;

    var sources = slides.map(function (s) {
      var el = s.querySelector('img');
      return el ? { src: el.currentSrc || el.src, alt: el.alt } : null;
    }).filter(Boolean);
    if (sources.length === 0) return;

    function paint(i) {
      var item = sources[(i + sources.length) % sources.length];
      img.src = item.src;
      img.alt = item.alt;
      if (counter) counter.textContent = ((i % sources.length) + 1) + ' / ' + sources.length;
    }

    function open() {
      lastFocus = document.activeElement;
      paint(getIndex());
      box.removeAttribute('hidden');
      box.classList.add('is-open');
      document.body.classList.add('has-drawer');

      // Flush the pending style recalc. Without this the dialog is still
      // display:none when focus() runs, and the call is silently dropped —
      // leaving keyboard users stranded behind the overlay.
      void box.offsetHeight;

      var closeBtn = box.querySelector('.lightbox__close');
      if (closeBtn) closeBtn.focus();
    }

    function close() {
      box.classList.remove('is-open');
      box.setAttribute('hidden', '');
      document.body.classList.remove('has-drawer');
      if (lastFocus) lastFocus.focus();
    }

    gallery.addEventListener('click', function (e) {
      if (e.target.closest('.pgallery__slide.is-active img')) open();
    });

    box.addEventListener('click', function (e) {
      if (e.target.closest('.lightbox__close') || e.target === box) { close(); return; }
      var nav = e.target.closest('[data-lightbox-nav]');
      if (nav) {
        var next = getIndex() + (nav.getAttribute('data-lightbox-nav') === 'next' ? 1 : -1);
        show(next);
        paint(next);
      }
    });

    document.addEventListener('keydown', function (e) {
      if (!box.classList.contains('is-open')) return;
      if (e.key === 'Escape')     { close(); }
      if (e.key === 'ArrowRight') { show(getIndex() + 1); paint(getIndex()); }
      if (e.key === 'ArrowLeft')  { show(getIndex() - 1); paint(getIndex()); }
    });
  }

  /* ── Detail page tabs ─────────────────────────────────────
     Follows the WAI-ARIA tabs pattern: roving tabindex, arrow keys,
     Home/End. Panels are plain markup, so with JS off every panel
     is simply visible and stacked. */
  function initTabs() {
    document.querySelectorAll('[data-tabs]').forEach(function (group) {
      var tabs   = Array.prototype.slice.call(group.querySelectorAll('[role="tab"]'));
      var panels = tabs.map(function (t) { return document.getElementById(t.getAttribute('aria-controls')); });
      if (tabs.length === 0) return;

      function select(i) {
        tabs.forEach(function (t, n) {
          var on = n === i;
          t.setAttribute('aria-selected', String(on));
          t.tabIndex = on ? 0 : -1;
          if (panels[n]) panels[n].hidden = !on;
        });
      }

      tabs.forEach(function (tab, i) {
        tab.addEventListener('click', function () { select(i); });
        tab.addEventListener('keydown', function (e) {
          var next = null;
          if (e.key === 'ArrowRight') next = (i + 1) % tabs.length;
          if (e.key === 'ArrowLeft')  next = (i - 1 + tabs.length) % tabs.length;
          if (e.key === 'Home')       next = 0;
          if (e.key === 'End')        next = tabs.length - 1;
          if (next === null) return;
          e.preventDefault();
          select(next);
          tabs[next].focus();
        });
      });

      select(0);
    });
  }

  /* ── Listings grid/list view toggle ───────────────────────
     Purely presentational, and the choice is remembered per browser. */
  function initViewSwitch() {
    var sw   = document.querySelector('[data-viewswitch]');
    var grid = document.getElementById('resultsGrid');
    if (!sw || !grid) return;

    function apply(mode) {
      grid.classList.toggle('pgrid--list', mode === 'list');
      sw.querySelectorAll('button').forEach(function (b) {
        var on = b.getAttribute('data-view') === mode;
        b.classList.toggle('is-active', on);
        b.setAttribute('aria-pressed', String(on));
      });
      try { localStorage.setItem('saxane:view', mode); } catch (err) { /* private mode */ }
    }

    sw.addEventListener('click', function (e) {
      var btn = e.target.closest('[data-view]');
      if (btn) apply(btn.getAttribute('data-view'));
    });

    var saved = null;
    try { saved = localStorage.getItem('saxane:view'); } catch (err) { /* private mode */ }
    apply(saved === 'list' ? 'list' : 'grid');
  }

  /* ── Favourite buttons ────────────────────────────────────
     The button is a real submit inside a real POST form, so it works
     without JS. This only adds the pop animation on click. */
  function initFavourites() {
    if (prefersReducedMotion) return;
    document.addEventListener('click', function (e) {
      var btn = e.target.closest('.pcard__fav');
      if (!btn) return;
      btn.classList.remove('just-saved');
      void btn.offsetWidth; // restart the animation
      btn.classList.add('just-saved');
    });
  }

  /* ── Scroll reveal ────────────────────────────────────────
     The hiding class is only added once we know IntersectionObserver
     exists, so content can never be left permanently invisible. */
  function initReveal() {
    var targets = document.querySelectorAll('[data-reveal]');
    if (targets.length === 0) return;

    if (prefersReducedMotion || !('IntersectionObserver' in window)) return;

    document.documentElement.classList.add('js-reveal-ready');

    var io = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        if (!entry.isIntersecting) return;
        // Stagger siblings slightly for a natural cascade.
        var delay = Number(entry.target.getAttribute('data-reveal-delay') || 0);
        setTimeout(function () { entry.target.classList.add('is-visible'); }, delay);
        io.unobserve(entry.target);
      });
    }, { rootMargin: '0px 0px -8% 0px', threshold: 0.08 });

    targets.forEach(function (t) { io.observe(t); });

    // Safety net. Content must never be permanently stuck at opacity 0
    // because an observer callback didn't fire — which can happen when the
    // viewport is resized after load, or the page is printed or scraped.
    // Anything still hidden after 3s is revealed unconditionally.
    setTimeout(function () {
      document.querySelectorAll('[data-reveal]:not(.is-visible)').forEach(function (el) {
        var r = el.getBoundingClientRect();
        if (r.top < window.innerHeight) el.classList.add('is-visible');
      });
    }, 3000);

    // Printing must always show everything, revealed or not.
    window.addEventListener('beforeprint', function () {
      document.documentElement.classList.remove('js-reveal-ready');
    });
  }

  /* ── Inline form validation ───────────────────────────────
     Native constraint validation does the checking; this only
     replaces the browser's transient bubble with a persistent
     message next to the offending field, and moves focus there. */
  function initFormValidation() {
    document.querySelectorAll('form[data-validate]').forEach(function (form) {
      form.setAttribute('novalidate', '');

      function clearError(field) {
        field.removeAttribute('aria-invalid');
        field.removeAttribute('aria-describedby');
        var msg = field.parentNode.querySelector('.field-error');
        if (msg) msg.remove();
      }

      function showError(field, text) {
        clearError(field);
        var id = (field.id || field.name || 'field') + '-error';
        var msg = document.createElement('p');
        msg.className = 'field-error';
        msg.id = id;
        msg.innerHTML = '<i class="bi bi-exclamation-circle" aria-hidden="true"></i>';
        msg.appendChild(document.createTextNode(text));
        field.parentNode.appendChild(msg);
        field.setAttribute('aria-invalid', 'true');
        field.setAttribute('aria-describedby', id);
      }

      form.addEventListener('submit', function (e) {
        var firstBad = null;

        form.querySelectorAll('input, select, textarea').forEach(function (field) {
          if (field.type === 'hidden' || field.disabled) return;

          if (!field.checkValidity()) {
            var text = field.validity.valueMissing
              ? 'This field is required.'
              : (field.validationMessage || 'Please check this value.');
            showError(field, text);
            if (!firstBad) firstBad = field;
          } else {
            clearError(field);
          }
        });

        // Password confirmation, where both fields are present.
        var pw  = form.querySelector('[name="password"]');
        var cpw = form.querySelector('[name="confirm_password"]');
        if (pw && cpw && pw.value !== cpw.value) {
          showError(cpw, 'Passwords do not match.');
          if (!firstBad) firstBad = cpw;
        }

        if (firstBad) {
          e.preventDefault();
          firstBad.focus();
          firstBad.scrollIntoView({ block: 'center',
            behavior: prefersReducedMotion ? 'auto' : 'smooth' });
        }
      });

      // Clear a field's error as soon as it becomes valid again.
      form.addEventListener('input', function (e) {
        var field = e.target;
        if (field.hasAttribute('aria-invalid') && field.checkValidity()) clearError(field);
      });
    });
  }

  /* ── Auto-dismiss flash messages ──────────────────────────  */
  function initFlash() {
    var flash = document.getElementById('flashMessage');
    if (!flash) return;
    setTimeout(function () {
      flash.style.opacity = '0';
      setTimeout(function () { flash.remove(); }, 300);
    }, 6000);
  }

  function init() {
    initStickyHeader();
    initNavDrawer();
    initGallery();
    initTabs();
    initViewSwitch();
    initFavourites();
    initReveal();
    initFormValidation();
    initFlash();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
