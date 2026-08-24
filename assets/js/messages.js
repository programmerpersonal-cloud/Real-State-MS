/**
 * Messages — progressive enhancement only.
 *
 * Everything on this page works without a line of this file. Navigation is
 * links, sending is a form POST, filtering the inbox is a GET form, and the
 * mobile takeover is two CSS rules keyed off the URL. What follows adds three
 * conveniences on top, and each one reveals itself only once it is available —
 * so a reader without JavaScript is never shown a control that does nothing.
 *
 *   1. the stream opens at the newest message rather than the oldest
 *   2. Enter sends, Shift+Enter makes a new line
 *   3. a filter box over the recipient list
 *
 * Loaded through $extraScripts in views/messages/index.php, so it ships with
 * this page and no other.
 */
(function () {
    'use strict';

    /* ── 1. Open at the newest message ──────────────────────────────────
       A thread is read from the bottom. Without this the browser lands at
       the top of the scroll region, which on a long conversation means the
       reader arrives at correspondence from months ago.

       Skipped when ?before= is in the URL: that is the "load earlier"
       pagination, and someone who asked for older messages wants to be
       looking at them, not thrown back to the end. */
    var stream = document.getElementById('msgStream');
    if (stream && window.location.search.indexOf('before=') === -1) {
        stream.scrollTop = stream.scrollHeight;
    }

    /* ── 2. Enter to send ───────────────────────────────────────────────
       The hint is hidden in the markup and revealed here, because it would
       otherwise promise a shortcut that does not exist.

       Deliberately not applied on touch screens: on a phone the Enter key
       is the newline key, and hijacking it means every multi-line message
       gets sent in pieces. `pointer: fine` is the honest test — a real
       pointing device implies a real keyboard. */
    var composer = document.querySelector('[data-msg-composer]');
    var precise  = window.matchMedia && window.matchMedia('(pointer: fine)').matches;

    if (composer && precise) {
        var body = composer.querySelector('textarea[name="body"]');
        var hint = composer.querySelector('[data-msg-hint]');

        if (body) {
            if (hint) { hint.hidden = false; }

            body.addEventListener('keydown', function (e) {
                if (e.key !== 'Enter' || e.shiftKey || e.altKey || e.ctrlKey || e.metaKey) { return; }

                // An IME composing a character also emits Enter, and that
                // Enter belongs to the candidate list, not to us.
                if (e.isComposing || e.keyCode === 229) { return; }

                if (body.value.trim() === '') {
                    e.preventDefault();
                    return;
                }

                e.preventDefault();
                if (composer.requestSubmit) {
                    composer.requestSubmit();
                } else {
                    composer.submit();
                }
            });
        }
    }

    /* ── 3. Filter the recipient list ───────────────────────────────────
       Purely a display convenience over a list the server already limited
       to this user's authorised contacts. It hides rows; it cannot reveal
       one, because a contact that is not in the DOM was never sent. */
    var filter = document.querySelector('[data-msg-filter]');
    var list   = document.querySelector('[data-msg-contacts]');

    if (filter && list) {
        var box     = document.querySelector('[data-msg-filter-box]');
        var noMatch = document.querySelector('[data-msg-no-match]');
        var rows    = Array.prototype.slice.call(list.querySelectorAll('.msg__contact'));

        // Worth showing only when there is enough to filter.
        if (rows.length > 4 && box) {
            box.hidden = false;

            filter.addEventListener('input', function () {
                var q = filter.value.trim().toLowerCase();
                var shown = 0;

                rows.forEach(function (row) {
                    var hit = q === '' || (row.getAttribute('data-name') || '').indexOf(q) !== -1;
                    row.hidden = !hit;
                    if (hit) { shown++; }
                });

                if (noMatch) { noMatch.hidden = shown !== 0; }
            });
        }
    }
}());
