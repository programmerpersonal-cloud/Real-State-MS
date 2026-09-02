/**
 * Messages — progressive enhancement only.
 *
 * Everything on this page works without a line of this file. Navigation is
 * links, sending is a form POST, reacting is a form POST, the attachment menu
 * is a <details> disclosure, and the mobile takeover is two CSS rules keyed
 * off the URL. There is no fetch, no XHR, no WebSocket and no
 * JSON endpoint anywhere in this module.
 *
 * What follows adds conveniences on top, and each reveals itself only once it
 * is known to work — so a reader without JavaScript, or on a browser missing
 * an API, is never shown a control that does nothing:
 *
 *   1. the stream opens at the newest message
 *   2. Enter sends, Shift+Enter makes a new line
 *   3. the composer grows with what is typed
 *   4. the chosen files are named before sending
 *   5. one menu open at a time, Escape and outside-click close it
 *   6. right-click and long-press open a message's action menu
 *   7. an emoji picker that inserts into the field
 *   8. voice recording, which hands its result to the ordinary form
 *   9. a filter over the recipient list
 *
 * Loaded through $extraScripts in views/messages/index.php.
 */
(function () {
    'use strict';

    var reduceMotion = window.matchMedia
        && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    /* ── 1. Open at the newest message ──────────────────────────────────
       A thread is read from the bottom. Skipped when ?before= or ?find= is in
       the URL: someone who asked for older messages, or for search results,
       wants to be looking at them. */
    var stream = document.getElementById('msgStream');
    if (stream && window.location.search.indexOf('before=') === -1
               && window.location.search.indexOf('find=') === -1) {
        stream.scrollTop = stream.scrollHeight;
    }

    /* If a message was linked to (#m123), mark it briefly so the eye can find
       it — a reply quote and a reaction redirect both land this way. */
    if (window.location.hash && /^#m\d+$/.test(window.location.hash)) {
        var target = document.querySelector(window.location.hash);
        if (target) {
            target.classList.add('is-linked');
            if (!reduceMotion) {
                target.scrollIntoView({ block: 'center', behavior: 'smooth' });
            }
            setTimeout(function () { target.classList.remove('is-linked'); }, 2400);
        }
    }

    var composer = document.querySelector('[data-msg-composer]');
    var body     = composer && composer.querySelector('textarea[name="body"]');
    var precise  = window.matchMedia && window.matchMedia('(pointer: fine)').matches;

    /* ── 2. Enter to send ───────────────────────────────────────────────
       Not on touch: there Enter is the newline key, and hijacking it means
       every multi-line message gets sent in pieces. */
    if (composer && body && precise) {
        var hint = composer.querySelector('[data-msg-hint]');
        if (hint) { hint.hidden = false; }

        body.addEventListener('keydown', function (e) {
            if (e.key !== 'Enter' || e.shiftKey || e.altKey || e.ctrlKey || e.metaKey) { return; }
            if (e.isComposing || e.keyCode === 229) { return; }   // an IME's Enter
            if (body.value.trim() === '') { e.preventDefault(); return; }

            e.preventDefault();
            if (composer.requestSubmit) { composer.requestSubmit(); } else { composer.submit(); }
        });
    }

    /* ── 3. Grow with the text ──────────────────────────────────────────
       The field starts one row tall and grows to a ceiling, after which it
       scrolls. The ceiling is read from the stylesheet rather than hard-coded
       here, so the two cannot disagree. */
    var grow = document.querySelector('[data-msg-grow]');
    if (grow) {
        var resize = function () {
            grow.style.height = 'auto';
            var max = parseInt(window.getComputedStyle(grow).maxHeight, 10);
            var next = grow.scrollHeight;
            grow.style.height = (isNaN(max) ? next : Math.min(next, max)) + 'px';
        };
        grow.addEventListener('input', resize);
        resize();
    }

    /* ── 4. Name the chosen files ───────────────────────────────────────
       The server validates every file again — by content, not by name — so
       marking one here only saves a round trip. Never blocks the submit. */
    var picked = document.querySelector('[data-msg-picked]');
    var pickers = Array.prototype.slice.call(document.querySelectorAll('[data-msg-files]'));

    if (picked && pickers.length) {
        var MAX_FILES = 5;                    // mirrors MESSAGE_ATTACHMENT_MAX_COUNT
        var MAX_BYTES = 10 * 1024 * 1024;     // mirrors MESSAGE_ATTACHMENT_MAX_SIZE

        var human = function (bytes) {
            if (bytes < 1024) { return bytes + ' B'; }
            if (bytes < 1024 * 1024) { return Math.round(bytes / 1024) + ' KB'; }
            return (bytes / (1024 * 1024)).toFixed(1) + ' MB';
        };

        var listFiles = function () {
            var all = [];
            pickers.forEach(function (input) {
                Array.prototype.slice.call(input.files || []).forEach(function (f) { all.push(f); });
            });

            picked.textContent = '';
            if (!all.length) { picked.hidden = true; return; }

            all.forEach(function (file, i) {
                var li = document.createElement('li');
                // textContent, never innerHTML: a filename is user input.
                li.textContent = file.name + ' · ' + human(file.size);
                if (file.size > MAX_BYTES || i >= MAX_FILES) {
                    li.className = 'is-bad';
                    li.textContent += i >= MAX_FILES ? ' · over the limit of ' + MAX_FILES : ' · too large';
                }
                picked.appendChild(li);
            });
            picked.hidden = false;
        };

        pickers.forEach(function (input) {
            input.addEventListener('change', function () {
                listFiles();
                closeAll();                    // the attach menu has done its job
            });
        });
    }

    /* ── 5. One menu at a time ──────────────────────────────────────────
       Every menu on this page is a <details>. Managing them centrally is what
       gives them all the same Escape and outside-click behaviour without any
       of them needing its own handler. */
    var allMenus = function () {
        return Array.prototype.slice.call(document.querySelectorAll(
            '[data-msg-attach], [data-msg-menu]'
        ));
    };

    /* The emoji grid is a plain panel rather than a <details>: it has never
       existed without JavaScript, so a summary nobody could reach would be a
       control that lies. It is closed alongside the disclosures. */
    var emojiPanel = document.querySelector('[data-msg-emoji-panel]');

    /* The emoji trigger is no longer inside a <details>, so it has to be
       named wherever the panel's state is touched. */
    var emojiBtn = document.querySelector('[data-msg-emoji-open]');

    function closeAll(except) {
        allMenus().forEach(function (d) { if (d !== except) { d.open = false; } });
        if (emojiPanel && except !== emojiPanel) {
            emojiPanel.hidden = true;
            if (emojiBtn) { emojiBtn.setAttribute('aria-expanded', 'false'); }
        }
    }

    document.addEventListener('toggle', function (e) {
        var d = e.target;
        if (d && d.tagName === 'DETAILS' && d.open && allMenus().indexOf(d) !== -1) {
            closeAll(d);
        }
    }, true);

    document.addEventListener('click', function (e) {
        /* Neither the emoji panel nor the button that opens it lives inside a
           <details>, so both have to be named here. Without the button, its
           own handler would open the panel and this one would close it again
           on the very same click. */
        if (!e.target.closest('details')
            && !e.target.closest('[data-msg-emoji-panel]')
            && !e.target.closest('[data-msg-emoji-open]')) {
            closeAll();
        }
    });

    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Escape') { return; }
        var open = allMenus().filter(function (d) { return d.open; });
        if (!open.length) { return; }
        open.forEach(function (d) {
            d.open = false;
            var s = d.querySelector('summary');
            if (s) { s.focus(); }              // focus goes back where it came from
        });
    });

    /* Escape and outside clicks close the emoji panel too. Handled separately
       from the disclosures above because it has no `open` attribute to clear. */
    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Escape' || !emojiPanel || emojiPanel.hidden) { return; }
        emojiPanel.hidden = true;
        // Focus goes back to the control that opened it, not to the
        // attachment button next door.
        if (emojiBtn) {
            emojiBtn.setAttribute('aria-expanded', 'false');
            emojiBtn.focus();
        }
    });

    /* ── 6. Right-click and long-press ──────────────────────────────────
       Both open the same <details> menu the ⋯ button opens, so there is one
       menu with one set of rules rather than three implementations. Neither
       is the only way in: the button is always there. */
    var bubbles = Array.prototype.slice.call(document.querySelectorAll('[data-msg]'));

    bubbles.forEach(function (bubble) {
        var menu = bubble.querySelector('[data-msg-menu]');
        if (!menu) { return; }

        if (precise) {
            bubble.addEventListener('contextmenu', function (e) {
                e.preventDefault();
                closeAll(menu);
                menu.open = true;
            });
        }

        /* Long press. A movement threshold and a timer, so a scroll that
           starts on a message is a scroll and not an accidental menu — the
           commonest way long-press implementations become infuriating. */
        var timer = null, startX = 0, startY = 0;

        var cancel = function () {
            if (timer) { clearTimeout(timer); timer = null; }
        };

        bubble.addEventListener('touchstart', function (e) {
            var t = e.touches[0];
            startX = t.clientX; startY = t.clientY;
            cancel();
            timer = setTimeout(function () {
                timer = null;
                closeAll(menu);
                menu.open = true;
                if (navigator.vibrate) { navigator.vibrate(8); }
            }, 500);
        }, { passive: true });

        bubble.addEventListener('touchmove', function (e) {
            var t = e.touches[0];
            if (Math.abs(t.clientX - startX) > 10 || Math.abs(t.clientY - startY) > 10) { cancel(); }
        }, { passive: true });

        bubble.addEventListener('touchend', cancel, { passive: true });
        bubble.addEventListener('touchcancel', cancel, { passive: true });
    });

    /* ── 7. Emoji ───────────────────────────────────────────────────────
       A curated list built here rather than a dependency: no library, no
       network request, nothing to keep updated. The disclosure ships hidden
       and is revealed only once it has been filled. */
    var emojiOpen = document.querySelector('[data-msg-emoji-open]');
    var emojiGrid = document.querySelector('[data-msg-emoji-grid]');

    if (emojiOpen && emojiGrid && emojiPanel && body) {
        var EMOJI = (
            '😀 😃 😄 😁 😊 🙂 😉 😍 😘 😌 😎 🤓 🤔 🤨 😐 😴 😢 😭 😤 😠 ' +
            '👍 👎 👏 🙏 💪 🤝 👋 ✌️ 🤞 ☝️ ✅ ❌ ❗ ❓ 💡 ⭐ 🔥 💯 ⏰ 📅 ' +
            '❤️ 🧡 💚 💙 💜 🎉 🎊 🏠 🏡 🏢 🔑 🚪 🛠️ 🔧 🧹 💧 ⚡ 📸 📄 💰'
        ).split(' ');

        EMOJI.forEach(function (ch) {
            var b = document.createElement('button');
            b.type = 'button';
            b.className = 'msg__emoji-btn';
            b.textContent = ch;
            b.setAttribute('aria-label', 'Insert ' + ch);
            b.addEventListener('click', function () {
                // Inserted at the caret, and the caret is put back after it —
                // an emoji picker that dumps at the end and steals focus is a
                // picker people use once.
                var start = body.selectionStart || 0;
                var end   = body.selectionEnd || 0;
                body.value = body.value.slice(0, start) + ch + body.value.slice(end);
                var at = start + ch.length;
                body.focus();
                body.setSelectionRange(at, at);
                body.dispatchEvent(new Event('input'));   // let the grower re-measure
                if (emojiPanel) {
                    emojiPanel.hidden = true;
                    emojiOpen.setAttribute('aria-expanded', 'false');
                }
            });
            emojiGrid.appendChild(b);
        });

        /* Revealed only now that the grid holds something. The menu item
           swaps the attachment menu for the emoji panel, so the two never
           overlap and Escape always has exactly one thing to close. */
        emojiOpen.hidden = false;
        emojiOpen.addEventListener('click', function () {
            // A toggle, not a one-way open: the button that revealed the
            // panel is the obvious thing to press to put it away again.
            var show = emojiPanel.hidden;
            closeAll();
            emojiPanel.hidden = !show;
            emojiOpen.setAttribute('aria-expanded', show ? 'true' : 'false');
            if (show) {
                var first = emojiGrid.querySelector('button');
                if (first) { first.focus(); }
            }
        });
    }

    /* ── 8. Voice notes ─────────────────────────────────────────────────
       Revealed only where the browser can actually record. The recorded blob
       is placed into a real <input type="file"> using DataTransfer and the
       ordinary form carries it — which is why this feature needs no fetch, no
       JSON endpoint and no change to the server's upload path. The bytes go
       through exactly the same validation as a chosen file.

       The microphone is requested only when Record is pressed. Nothing here
       touches getUserMedia before that. */
    /* Two controls now start a recording — the item in the attach menu and
       the microphone that stands in the send button's place while the field
       is empty. They are the same action, so they share one handler rather
       than duplicating the recorder. */
    var recordBtns = Array.prototype.slice.call(document.querySelectorAll('[data-msg-record]'));
    var voiceInput = document.querySelector('[data-msg-voice-input]');
    var recBar = document.querySelector('[data-msg-rec]');

    var canRecord = !!(window.MediaRecorder && navigator.mediaDevices
        && navigator.mediaDevices.getUserMedia && window.DataTransfer && window.File);

    if (recordBtns.length && voiceInput && recBar && composer && canRecord) {

        var recorder = null, chunks = [], stream_ = null, ticker = null, startedAt = 0;
        var timeEl = recBar.querySelector('[data-msg-rec-time]');
        var bar = composer.querySelector('[data-msg-bar]');

        var showRec = function (on) {
            recBar.hidden = !on;
            if (bar) { bar.hidden = on; }
        };

        var stopTracks = function () {
            if (stream_) { stream_.getTracks().forEach(function (t) { t.stop(); }); stream_ = null; }
        };

        var tick = function () {
            var s = Math.floor((Date.now() - startedAt) / 1000);
            if (timeEl) {
                timeEl.textContent = Math.floor(s / 60) + ':' + ('0' + (s % 60)).slice(-2);
            }
            // A hard ceiling, so a forgotten recording cannot grow past what
            // the server will accept.
            if (s >= 600) { finish(true); }
        };

        var cleanup = function () {
            if (ticker) { clearInterval(ticker); ticker = null; }
            stopTracks();
            recorder = null;
            showRec(false);
        };

        function finish(send) {
            if (!recorder) { cleanup(); return; }
            recorder.onstop = function () {
                if (send && chunks.length) {
                    var type = (recorder && recorder.mimeType) || 'audio/webm';
                    var ext  = type.indexOf('ogg') !== -1 ? 'ogg'
                             : type.indexOf('mp4') !== -1 ? 'm4a' : 'webm';
                    var blob = new Blob(chunks, { type: type.split(';')[0] });
                    var file = new File([blob], 'voice-note.' + ext, { type: blob.type });

                    var dt = new DataTransfer();
                    dt.items.add(file);
                    voiceInput.files = dt.files;

                    cleanup();
                    if (composer.requestSubmit) { composer.requestSubmit(); } else { composer.submit(); }
                    return;
                }
                cleanup();
            };
            try { recorder.stop(); } catch (e) { cleanup(); }
        }

        var startRecording = function () {
            closeAll();                       // it is a menu item now
            // Permission is asked here and only here, after a deliberate press.
            navigator.mediaDevices.getUserMedia({ audio: true }).then(function (s) {
                stream_ = s;
                chunks = [];
                try {
                    recorder = new MediaRecorder(s);
                } catch (e) {
                    stopTracks();
                    alert('This browser cannot record audio.');
                    return;
                }
                recorder.ondataavailable = function (e) {
                    if (e.data && e.data.size) { chunks.push(e.data); }
                };
                recorder.start();
                startedAt = Date.now();
                if (timeEl) { timeEl.textContent = '0:00'; }
                showRec(true);
                ticker = setInterval(tick, 250);
            }).catch(function () {
                // Denied, dismissed, or no microphone. Say so plainly and
                // leave the composer exactly as it was.
                alert('Microphone access was not granted, so a voice note cannot be recorded. '
                    + 'You can still type a message or attach a file.');
            });
        };

        /* Revealed only here, inside the branch that has already proved the
           browser can record. A microphone that cannot record is never
           painted onto the page. */
        recordBtns.forEach(function (btn) {
            btn.hidden = false;
            btn.addEventListener('click', startRecording);
        });

        recBar.querySelector('[data-msg-rec-stop]').addEventListener('click', function () { finish(true); });
        recBar.querySelector('[data-msg-rec-cancel]').addEventListener('click', function () {
            chunks = [];
            finish(false);
        });
    }

    /* ── 9. Filter the recipient list ───────────────────────────────────
       Hides rows in a list the server already limited to authorised contacts.
       It cannot reveal one: a contact that is not in the DOM was never sent. */
    var filter = document.querySelector('[data-msg-filter]');
    var list   = document.querySelector('[data-msg-contacts]');

    if (filter && list) {
        var box     = document.querySelector('[data-msg-filter-box]');
        var noMatch = document.querySelector('[data-msg-no-match]');
        var rows    = Array.prototype.slice.call(list.querySelectorAll('.msg__contact'));

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
    /* ── 10. The send button is always the send button ──────────────────
       This used to swap: microphone while the field was empty, send once
       there was something to send — the messenger pattern. It was asked
       for and then asked about twice, which is the answer: a send control
       that is only there once you have already typed cannot be found by
       someone looking for it before they type, and "where do I click to
       send this" is not a question a composer should raise.

       So both are permanent now. Send keeps the filled brand circle and
       stays the one primary action; the microphone sits beside it as a
       quiet secondary control, and is still revealed only where the
       browser can actually record — see section 8. Nothing about
       recording or sending changed, only which of the two is visible
       when. */

    /* ── 11. Voice notes: a real player wearing a nicer transport ────────
       The <audio> element ships with `controls` and stays the only thing that
       plays anything. This takes the native controls away *only* after it has
       a working element in hand and has attached its own, so a failure here
       leaves the browser's player intact rather than leaving a dead play
       button on the page.

       Nothing is fetched or decoded: the bars are a scrubber styled as a
       waveform, drawn server-side and marked aria-hidden, and `preload` stays
       at metadata so opening a thread does not pull every recording in it. */
    var players = Array.prototype.slice.call(document.querySelectorAll('[data-msg-voice]'));

    players.forEach(function (root) {
        var audio = root.querySelector('[data-msg-voice-audio]');
        var ui    = root.querySelector('[data-msg-voice-ui]');
        if (!audio || !ui || typeof audio.play !== 'function') { return; }

        var play  = ui.querySelector('[data-msg-voice-play]');
        var seek  = ui.querySelector('[data-msg-voice-seek]');
        var bars  = Array.prototype.slice.call(ui.querySelectorAll('.msg__wave > i'));
        var nowEl = ui.querySelector('[data-msg-voice-now]');
        var totEl = ui.querySelector('[data-msg-voice-total]');
        if (!play || !seek) { return; }

        // Only now is the enhancement real, so only now does the native
        // player step aside.
        audio.removeAttribute('controls');
        ui.hidden = false;

        var clock = function (s) {
            if (!isFinite(s) || s < 0) { return '--:--'; }
            s = Math.floor(s);
            return Math.floor(s / 60) + ':' + ('0' + (s % 60)).slice(-2);
        };

        var paint = function () {
            var d = audio.duration;
            var pct = (isFinite(d) && d > 0) ? (audio.currentTime / d) * 100 : 0;
            // Colour the bars up to the play head. 28 of them, four times a
            // second - cheap enough not to warrant a rAF.
            var upTo = Math.round((pct / 100) * bars.length);
            bars.forEach(function (bar, i) {
                bar.classList.toggle('is-played', i < upTo);
            });
            seek.value = pct;
            seek.setAttribute('aria-valuetext', clock(audio.currentTime) + ' of ' + clock(d));
            if (nowEl) { nowEl.textContent = clock(audio.currentTime); }
        };

        var icon = function (playing) {
            var i = play.querySelector('i');
            if (i) { i.className = playing ? 'bi bi-pause-fill' : 'bi bi-play-fill'; }
            play.setAttribute('aria-pressed', playing ? 'true' : 'false');
            var label = play.querySelector('.sr-only');
            if (label) { label.textContent = playing ? 'Pause the voice note' : 'Play the voice note'; }
        };

        audio.addEventListener('loadedmetadata', function () {
            if (totEl) { totEl.textContent = clock(audio.duration); }
            paint();
        });
        audio.addEventListener('timeupdate', paint);
        audio.addEventListener('play', function () {
            // One at a time. Two recordings talking over each other is not a
            // feature anybody asked for.
            players.forEach(function (other) {
                var a = other.querySelector('[data-msg-voice-audio]');
                if (a && a !== audio && !a.paused) { a.pause(); }
            });
            icon(true);
        });
        audio.addEventListener('pause', function () { icon(false); });
        audio.addEventListener('ended', function () {
            icon(false);
            audio.currentTime = 0;
            paint();
        });

        play.addEventListener('click', function () {
            if (audio.paused) {
                var p = audio.play();
                // A rejected play() is ordinary — an autoplay policy, a file
                // the browser will not decode. Give the native player back
                // rather than leaving a button that does nothing.
                if (p && typeof p.catch === 'function') {
                    p.catch(function () {
                        audio.setAttribute('controls', '');
                        ui.hidden = true;
                    });
                }
            } else {
                audio.pause();
            }
        });

        var scrub = function () {
            var d = audio.duration;
            if (isFinite(d) && d > 0) { audio.currentTime = (seek.value / 100) * d; }
        };
        seek.addEventListener('input', scrub);
        seek.addEventListener('change', scrub);

        if (totEl && isFinite(audio.duration)) { totEl.textContent = clock(audio.duration); }
    });
}());
