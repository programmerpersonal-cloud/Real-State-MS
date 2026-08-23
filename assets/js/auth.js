/**
 * The login ↔ registration switch, and the two things that decorate it.
 *
 * Both forms are already on the page (see views/auth/_shell.php); this
 * only decides which one is showing and cross-fades the welcome panel.
 * Nothing here submits anything — the two forms keep their own actions,
 * their own CSRF tokens and their own server-side validation, and with
 * this file removed the switch controls are still links that work, the
 * first photograph is still shown, and registration still validates on
 * the server exactly as before.
 *
 * Three things are worth knowing before editing:
 *
 * 1. The state is set immediately and synchronously. No correctness
 *    depends on a transitionend or animationend event, so hammering the
 *    switch cannot leave a panel half-hidden or a form unreachable.
 *
 * 2. The form stack's height is the one thing that cannot be animated
 *    with a transform. It is measured with transitions switched off,
 *    pinned in pixels for the length of the swap, then released back to
 *    auto by a timer — releasing on transitionend would strand the
 *    inline height if the transition were interrupted.
 *
 * 3. The hidden panel is `inert`, not `display:none`, because it still
 *    has to be laid out for the cross-fade. inert is what keeps its
 *    fields out of the tab order and out of the accessibility tree; a
 *    faded-but-focusable form is the usual bug in this pattern.
 *
 * The other two modules are initSlideshow() — the property photographs
 * behind the welcome panel, which change every five seconds under a
 * slow continuous zoom, and stop on hover, on focus, on a dot press
 * and under reduced motion — and initStrength(), the advisory strength
 * bar under the new password. Both are scoped to this screen and
 * neither is load-bearing.
 */
(function () {
  'use strict';

  var HEIGHT_RELEASE_MS = 560;

  function initAuthShell(shell) {
    var forms = shell.querySelector('[data-auth-forms]');
    if (!forms) return;

    var panels = collect(shell, '[data-auth-panel]', 'authPanel');
    var faces  = collect(shell, '[data-auth-face]', 'authFace');
    var reduce = window.matchMedia('(prefers-reduced-motion: reduce)');
    var narrow = window.matchMedia('(max-width: 1023px)');
    var titles = { login: shell.dataset.titleLogin, register: shell.dataset.titleRegister };
    var timer  = null;

    shell.addEventListener('click', function (event) {
      var trigger = event.target.closest('[data-auth-switch]');
      if (!trigger || !shell.contains(trigger)) return;
      if (event.metaKey || event.ctrlKey || event.shiftKey || event.button !== 0) return;

      var mode = trigger.getAttribute('data-auth-switch');
      if (!panels[mode] || mode === shell.dataset.authMode) return;

      event.preventDefault();
      setMode(mode, true);

      // The URL follows the panel, so a refresh, a bookmark or the back
      // button all land where the person actually is.
      var href = trigger.getAttribute('href');
      if (href && window.history.pushState) window.history.pushState({ authMode: mode }, '', href);
    });

    window.addEventListener('popstate', function () {
      var mode = modeFromLocation();
      if (panels[mode] && mode !== shell.dataset.authMode) setMode(mode, false);
    });

    /**
     * @param {string}  mode
     * @param {boolean} moveFocus  true when a person asked for the switch
     */
    function setMode(mode, moveFocus) {
      var animate = !reduce.matches;
      var from = forms.offsetHeight;

      if (animate) forms.style.transition = 'none';

      apply(mode);
      if (titles[mode]) document.title = titles[mode];

      if (animate) {
        // Measured at the new content's natural height, then pinned back
        // to the old one — both reads happen with transitions off, so
        // neither of them starts an animation of its own.
        forms.style.height = '';
        var to = forms.offsetHeight;
        forms.style.height = from + 'px';
        void forms.offsetHeight;
        forms.style.transition = '';

        if (to !== from) {
          forms.style.height = to + 'px';
          window.clearTimeout(timer);
          timer = window.setTimeout(release, HEIGHT_RELEASE_MS);
        } else {
          release();
        }
      } else {
        release();
      }

      if (moveFocus) focusInto(panels[mode]);
    }

    function release() {
      window.clearTimeout(timer);
      forms.style.height = '';
    }

    function apply(mode) {
      shell.dataset.authMode = mode;
      each(panels, function (el, key) { toggle(el, key !== mode); });
      each(faces, function (el, key) { toggle(el, key !== mode); });
    }

    /**
     * Where focus goes after a switch. On the stacked layout the first
     * field would throw the keyboard up over the form the moment someone
     * tapped "Register", so the heading takes it instead — which still
     * moves a screen reader to the right place and still reads the new
     * title. The panel is also scrolled back into view: on a phone the
     * switch link at the foot of a long registration form is a screen
     * and a half below the heading it is about to move focus to.
     */
    function focusInto(panel) {
      var target = narrow.matches
        ? panel.querySelector('[data-auth-heading]')
        : panel.querySelector('input:not([type="hidden"]), select, textarea');

      if (!target) return;

      if (narrow.matches && target.scrollIntoView) {
        target.scrollIntoView({
          block: 'center',
          behavior: reduce.matches ? 'auto' : 'smooth'
        });
      }
      target.focus();
    }
  }

  function toggle(el, hidden) {
    el.classList.toggle('is-hidden', hidden);
    if (hidden) {
      el.setAttribute('inert', '');
      el.setAttribute('aria-hidden', 'true');
    } else {
      el.removeAttribute('inert');
      el.removeAttribute('aria-hidden');
    }
  }

  function modeFromLocation() {
    return new URLSearchParams(window.location.search).get('page') === 'register'
      ? 'register' : 'login';
  }

  function collect(root, selector, key) {
    var out = {};
    root.querySelectorAll(selector).forEach(function (el) { out[el.dataset[key]] = el; });
    return out;
  }

  function each(map, fn) {
    Object.keys(map).forEach(function (key) { fn(map[key], key); });
  }

  /* ────────────────────────────────────────────────────────────────
     The property slideshow

     One photograph is visible at a time and the rest are stacked
     behind it at opacity 0 — the swap is a class change, so a slow
     image can never leave a blank frame between two pictures. The
     pictures are held still: nothing pans, nothing zooms, one simply
     replaces the next every five seconds.

     Under the swap, every photograph is drifting slowly in or out.
     That animation lives in CSS and runs continuously rather than
     being keyed to whichever slide is showing — keyed to .is-active it
     would restart on every swap, and the snap back to its first frame
     is exactly what a drift like this must never do. This module only
     decides whether it is running, by way of an .is-playing class.

     There is no pause button on the panel. What stops both the
     rotation and the zoom is everything a person does when they are
     actually looking at it: hovering it, moving keyboard focus into
     it, backgrounding the tab, or choosing a photograph from the dots
     — which holds it there for good, and is the deliberate, operable
     stop that auto-updating content is required to offer. Under
     prefers-reduced-motion neither starts at all.
     ──────────────────────────────────────────────────────────────── */
  function initSlideshow(root) {
    var slides = toArray(root.querySelectorAll('[data-slide]'));
    // One photograph still drifts; it just never advances. The guard
    // is on the timer below rather than here.
    if (!slides.length) return;

    var cards  = toArray(root.querySelectorAll('[data-slide-card]'));
    var dots   = toArray(root.querySelectorAll('[data-slide-to]'));
    var reduce = window.matchMedia('(prefers-reduced-motion: reduce)');
    var every  = Math.max(1000, parseInt(root.dataset.slideshowInterval, 10) || 5000);

    var index   = 0;
    var timer   = null;
    var stopped = false;   // asked for by a person, and remembered
    var held    = false;   // hover / focus / hidden tab, transient

    dots.forEach(function (dot) {
      dot.addEventListener('click', function () {
        show(parseInt(dot.getAttribute('data-slide-to'), 10) || 0);
        // Choosing a property is a statement of interest in that one.
        // This is also the screen's stop control, so it has to last:
        // a rotation that resumed a few seconds later would defeat
        // the point of having asked for this photograph.
        stopped = true;
        sync();
      });
    });

    // Transient holds. These never clear `stopped`, so moving the
    // pointer away cannot restart a slideshow somebody stopped.
    root.addEventListener('mouseenter', function () { held = true; sync(); });
    root.addEventListener('mouseleave', function () { held = false; sync(); });
    root.addEventListener('focusin', function () { held = true; sync(); });
    root.addEventListener('focusout', function () {
      if (!root.contains(document.activeElement)) { held = false; sync(); }
    });
    document.addEventListener('visibilitychange', sync);

    // The query is live: turning the system setting on mid-session
    // stops the rotation rather than waiting for a reload.
    onMediaChange(reduce, sync);

    sync();

    /** Show slide `next`, immediately and completely. */
    function show(next) {
      index = ((next % slides.length) + slides.length) % slides.length;

      slides.forEach(function (el, i) { el.classList.toggle('is-active', i === index); });
      dots.forEach(function (el, i) {
        el.classList.toggle('is-active', i === index);
        el.setAttribute('aria-pressed', String(i === index));
      });
      // Cards are keyed by slide index rather than by position: a
      // listing with no title renders no card at all, so the two lists
      // are not necessarily the same length.
      cards.forEach(function (el) {
        el.classList.toggle('is-active', parseInt(el.getAttribute('data-slide-card'), 10) === index);
      });
    }

    /** Whether the pictures should be moving right now. */
    function running() {
      return !stopped && !held && !reduce.matches && !document.hidden;
    }

    function sync() {
      window.clearInterval(timer);
      timer = null;

      var go = running();

      // The zoom is moving content in its own right, so it answers to
      // the same switch the rotation does — stopping one and leaving
      // the other running would defeat the point of stopping either.
      root.classList.toggle('is-playing', go);

      if (go && slides.length > 1) {
        timer = window.setInterval(function () { show(index + 1); }, every);
      }
    }
  }

  /* ────────────────────────────────────────────────────────────────
     Password strength

     Advice, not a gate. The rule that decides whether an account is
     created is still the server's eight-character minimum in
     AuthController, and nothing here can block or alter a submission.

     The reading is written out in words as well as drawn as a bar,
     because a bar that only changes colour says nothing to anyone who
     cannot see the colour. It is updated on a short debounce so a
     screen reader is not interrupted on every keystroke.
     ──────────────────────────────────────────────────────────────── */
  var STRENGTH = ['Too short', 'Weak', 'Fair', 'Good', 'Strong'];

  function initStrength(input) {
    var group = input.closest('.form-group');
    var meter = group && group.querySelector('[data-strength]');
    if (!meter) return;

    var text  = meter.querySelector('[data-strength-label]');
    var timer = null;

    input.addEventListener('input', function () {
      window.clearTimeout(timer);
      timer = window.setTimeout(render, 300);
    });
    input.addEventListener('blur', render);
    render();

    function render() {
      window.clearTimeout(timer);

      if (!input.value) {
        meter.hidden = true;
        meter.removeAttribute('data-level');
        if (text) text.textContent = '';
        return;
      }

      var level = score(input.value);
      meter.hidden = false;
      meter.setAttribute('data-level', String(level));
      if (text) text.textContent = STRENGTH[level];
    }
  }

  /**
   * 0-4. Length carries most of it, because it is the one property
   * that actually costs an attacker time; variety adds the rest.
   * Anything under the server's own minimum scores 0 whatever it
   * contains, so the bar never reads "Good" for a password that is
   * about to be refused.
   */
  function score(value) {
    if (value.length < 8) return 0;

    var points = 0;
    if (value.length >= 10) points++;
    if (value.length >= 14) points++;
    if (/[a-z]/.test(value) && /[A-Z]/.test(value)) points++;
    if (/[0-9]/.test(value)) points++;
    if (/[^A-Za-z0-9]/.test(value)) points++;

    return Math.max(1, Math.min(4, Math.ceil(points * 4 / 5)));
  }

  function toArray(list) {
    return Array.prototype.slice.call(list);
  }

  /** matchMedia listener, with the pre-Safari-14 spelling as a fallback. */
  function onMediaChange(query, fn) {
    if (query.addEventListener) query.addEventListener('change', fn);
    else if (query.addListener) query.addListener(fn);
  }

  document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('[data-auth-shell]').forEach(initAuthShell);
    document.querySelectorAll('[data-slideshow]').forEach(initSlideshow);
    document.querySelectorAll('[data-strength-input]').forEach(initStrength);
  });
})();
