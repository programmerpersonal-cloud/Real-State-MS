/**
 * The login ↔ registration switch.
 *
 * Both forms are already on the page (see views/auth/_shell.php); this
 * only decides which one is showing and slides the blue panel across.
 * Nothing here submits anything — the two forms keep their own actions,
 * their own CSRF tokens and their own server-side validation, and with
 * this file removed the switch controls are still links that work.
 *
 * Three things are worth knowing before editing:
 *
 * 1. The state is set immediately and synchronously. No correctness
 *    depends on a transitionend or animationend event, so hammering the
 *    switch cannot leave a panel half-hidden or a form unreachable.
 *
 * 2. The card's height is the one thing that cannot be animated with a
 *    transform. It is measured with transitions switched off, pinned in
 *    pixels for the length of the swap, then released back to auto by a
 *    timer — releasing on transitionend would strand the inline height
 *    if the transition were interrupted.
 *
 * 3. The hidden panel is `inert`, not `display:none`, because it still
 *    has to be laid out for the cross-fade. inert is what keeps its
 *    fields out of the tab order and out of the accessibility tree; a
 *    faded-but-focusable form is the usual bug in this pattern.
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
    var narrow = window.matchMedia('(max-width: 600px)');
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
     * Where focus goes after a switch. On a phone the first field would
     * throw the keyboard up over the form the moment someone tapped
     * "Register", so the heading takes it instead — which still moves a
     * screen reader to the right place and still reads the new title.
     */
    function focusInto(panel) {
      var target = narrow.matches
        ? panel.querySelector('[data-auth-heading]')
        : panel.querySelector('input:not([type="hidden"]), select, textarea');

      if (target) target.focus();
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

  document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('[data-auth-shell]').forEach(initAuthShell);
  });
})();
