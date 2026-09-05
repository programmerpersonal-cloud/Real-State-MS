/**
 * The login ↔ registration switch, and the one thing that decorates it.
 *
 * Both forms are already on the page (see views/auth/_shell.php); this
 * only decides which one is showing and cross-fades the welcome panel.
 * Nothing here submits anything — the two forms keep their own actions,
 * their own CSRF tokens and their own server-side validation, and with
 * this file removed the switch controls are still links that work and
 * registration still validates on the server exactly as before.
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
 * The other module is initStrength(), the advisory strength bar under
 * the new password. It is scoped to this screen and is not
 * load-bearing: the rule that decides whether an account is created is
 * still the server's.
 *
 * A third used to live here — the property slideshow behind the welcome
 * panel. The panel is a flat brand gradient now, so the photographs,
 * their dot controls and the two matchMedia helpers they alone needed
 * have all gone with it.
 */
(function () {
  'use strict';

  var HEIGHT_RELEASE_MS = 560;

  /* Marked now rather than on DOMContentLoaded, and the difference is
     visible. This file is a plain synchronous tag at the foot of the
     body, so it runs while the parser is finishing and before the first
     paint; the class is what turns the stacked layout into two steps, and
     adding it a tick later would paint the one-step layout and then jump.

     Everything keyed to .is-enhanced is therefore also the answer to
     "what happens with scripting off": the hero and the form are both on
     the page, as they were, and the gate's links navigate. */
  document.querySelectorAll('[data-auth-shell]').forEach(function (shell) {
    shell.classList.add('is-enhanced');
    shell.querySelectorAll('[data-auth-back]').forEach(function (el) { el.hidden = false; });
  });

  function initAuthShell(shell) {
    var forms = shell.querySelector('[data-auth-forms]');
    if (!forms) return;

    var panels = collect(shell, '[data-auth-panel]', 'authPanel');
    var faces  = collect(shell, '[data-auth-face]', 'authFace');
    var gate   = shell.querySelector('[data-auth-gate]');
    var reduce = window.matchMedia('(prefers-reduced-motion: reduce)');
    var narrow = window.matchMedia('(max-width: 1023px)');
    var titles = { login: shell.dataset.titleLogin, register: shell.dataset.titleRegister };
    var timer  = null;

    // What the server opened on, for a history entry that names no step.
    var firstStep = shell.dataset.authStep === 'form' ? 'form' : 'choose';

    shell.addEventListener('click', function (event) {
      var trigger = event.target.closest('[data-auth-switch]');
      if (!trigger || !shell.contains(trigger)) return;
      if (event.metaKey || event.ctrlKey || event.shiftKey || event.button !== 0) return;

      var mode = trigger.getAttribute('data-auth-switch');
      if (!panels[mode]) return;

      // Two things can be asked for here and either alone is enough to
      // act on. The gate's "Sign In" pressed on ?page=login changes no
      // mode at all — it is only asking for the form — and the old
      // guard, which compared modes and gave up when they matched, let
      // that navigate away and reload the page it was already on.
      var wantsMode = mode !== shell.dataset.authMode;
      var wantsStep = narrow.matches && shell.dataset.authStep !== 'form';
      if (!wantsMode && !wantsStep) return;

      event.preventDefault();

      // Step first: it is what takes the form out of display:none, and
      // measuring a hidden panel for the height animation would measure
      // zero. That animation is skipped on a step change for the same
      // reason — the step's own transition is already covering it.
      if (wantsStep) setStep('form');
      if (wantsMode) setMode(mode, true, !wantsStep);
      else focusInto(panels[mode]);

      // The URL follows the panel, so a refresh, a bookmark or the back
      // button all land where the person actually is.
      var href = trigger.getAttribute('href');
      if (href && window.history.pushState) {
        window.history.pushState({ authMode: mode, authStep: shell.dataset.authStep }, '', href);
      }
    });

    // The way back to the gate, and a history entry with it, so the
    // browser's own back button does the same thing this button does.
    shell.querySelectorAll('[data-auth-back]').forEach(function (button) {
      button.addEventListener('click', function () {
        setStep('choose');
        if (window.history.pushState) {
          window.history.pushState({ authStep: 'choose' }, '', stepUrl('choose'));
        }
        var first = gate && gate.querySelector('a[data-auth-switch]');
        if (first) first.focus();
      });
    });

    window.addEventListener('popstate', function () {
      var mode = modeFromLocation();
      if (panels[mode] && mode !== shell.dataset.authMode) setMode(mode, false, true);
      setStep(stepFromLocation());
    });

    /**
     * Which of the two steps a narrow screen is showing. Inert on a wide
     * one — the CSS that reads this attribute is inside a media query —
     * but it is still set, so a phone rotated to landscape and back finds
     * the screen where it left it.
     */
    function setStep(step) {
      if (shell.dataset.authStep === step) return;
      shell.dataset.authStep = step;
      if (step === 'choose') forms.style.height = '';
    }

    /** The current page URL carrying an explicit step. */
    function stepUrl(step) {
      var params = new URLSearchParams(window.location.search);
      params.set('step', step);
      return window.location.pathname + '?' + params.toString();
    }

    /**
     * Only an explicit ?step= is believed. A history entry that names no
     * step is the one the server rendered, and that one opened on
     * whichever step the server chose — which is 'form' when a rejected
     * submission left errors on the page, and going back to it must not
     * hide them behind the gate.
     */
    function stepFromLocation() {
      var step = new URLSearchParams(window.location.search).get('step');
      return step === 'form' || step === 'choose' ? step : firstStep;
    }

    /**
     * @param {string}  mode
     * @param {boolean} moveFocus     true when a person asked for the switch
     * @param {boolean} animateHeight false when the stack was hidden a
     *                                moment ago and there is no old height
     *                                worth animating from
     */
    function setMode(mode, moveFocus, animateHeight) {
      var animate = animateHeight !== false && !reduce.matches;
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

  document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('[data-auth-shell]').forEach(initAuthShell);
    document.querySelectorAll('[data-strength-input]').forEach(initStrength);
  });
})();
