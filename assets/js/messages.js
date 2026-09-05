/**
 * Messages — progressive enhancement only.
 *
 * Everything on this page works without a line of this file. Navigation is
 * links, sending is a form POST, reacting is a form POST, the attachment menu
 * is a <details> disclosure, and the mobile takeover is two CSS rules keyed
 * off the URL.
 *
 * There is exactly one exception, and it is section 12: a single held fetch
 * against ?page=messages&action=poll, so a message the other participant
 * sends appears without anyone pressing reload. It is still an enhancement —
 * with it switched off the page is precisely what it was, correct when
 * rendered and refreshed by a reload — and it adds no second renderer: the
 * server answers with the same partials the full page is built from.
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
 *  10. a permanent send button beside a permanent microphone
 *  11. a real transport for voice notes
 *  12. live updates — new messages arrive without a reload
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
    /* A function rather than a one-off loop, because the stream is now
       re-rendered in place when a message arrives (section 12) and the
       bubbles that come back need the same handlers the original ones got.
       Called once at load with the whole document, and again with just the
       stream after every swap. */
    function enhanceBubbles(scope) {
        Array.prototype.slice.call(
            (scope || document).querySelectorAll('[data-msg]')
        ).forEach(function (bubble) {
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
    }

    enhanceBubbles(document);

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
    /* Re-appliable for the same reason enhanceBubbles() is: a swapped-in
       stream brings new <audio> elements with it, and each needs its own
       transport before the native controls are taken away. */
    function enhanceVoice(scope) {
        Array.prototype.slice.call(
            (scope || document).querySelectorAll('[data-msg-voice]')
        ).forEach(function (root) {
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
            /* Asked of the document at press time rather than of a list
               captured at load: after a swap the other players on the page
               are different elements from the ones this handler was built
               beside. */
            Array.prototype.slice.call(
                document.querySelectorAll('[data-msg-voice-audio]')
            ).forEach(function (a) {
                if (a !== audio && !a.paused) { a.pause(); }
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
    }

    enhanceVoice(document);

    /* ── 12. Live updates ───────────────────────────────────────────────
       The one thing on this page that talks to the server without a form.

       The problem it solves: a message was saved correctly and read back
       correctly, but the *other* browser never asked again, so it kept
       showing the thread as it stood when the page was rendered. Sending
       worked; delivery only happened on reload.

       How it works, in one paragraph. The server has a route,
       ?page=messages&action=poll, that takes a cheap fingerprint of this
       user's inbox and the open conversation and then *waits* — holding the
       request for up to fifteen seconds, re-checking about once a second —
       answering the moment the fingerprint moves. So this loop is one idle
       connection, not a request every second, and a message lands on the
       other screen in about a second. When the answer does come it carries
       the two panels already rendered as HTML by the very same partials the
       full page uses, so nothing about a bubble or a row is built twice.

       Why the whole stream is replaced rather than new bubbles appended:
       appending means this file has to know how a bubble is built, how a run
       of consecutive messages is grouped, when a day divider belongs and what
       a read receipt looks like — a second renderer that would drift from the
       PHP one, and drift silently. Replacing what the server just rendered
       makes a duplicate impossible and a missed message impossible: the
       stream is not patched, it *is* the server's answer.

       What it deliberately does not do:

         · it does not poll while the tab is hidden — nobody is reading, and
           marking messages read behind someone's back would turn the other
           side's ticks blue for a message that was never seen
         · it does not poll after ten minutes without a keypress, click or
           scroll — an abandoned tab must not hold a worker open, nor keep a
           session alive that the timeout should have ended
         · it does not touch the composer, the search box or the filters, so
           a half-typed message, a caret and an open menu all survive
         · it does not replace the stream while an editor is open (?edit=),
           because that would throw away text somebody is in the middle of

       Everything here is an enhancement. With JavaScript off, or if the
       endpoint fails, the page is exactly what it was: a thread that is
       correct when rendered and refreshes when reloaded. */
    var live = document.querySelector('[data-msg-live]');

    if (live && window.fetch && window.AbortController) {
        var itemsBox = document.querySelector('[data-msg-items]');
        var totalBox = document.querySelector('[data-msg-total]');

        /* The fingerprint the page on screen was rendered from, stamped into
           the markup by the controller. Starting from it rather than from
           nothing is what stops the first poll answering "everything changed"
           and re-rendering, one second after load, exactly what is already
           there. */
        var sig = live.getAttribute('data-poll-sig') || '';

        var IDLE_LIMIT   = 10 * 60 * 1000;   // stop after this long untouched
        var RECONNECT    = 250;              // between a held poll and the next
        var BACKOFF_MAX  = 30000;

        var pending  = null;    // the AbortController of the request in flight
        var busy     = false;   // a request is out; do not start a second
        var timer    = null;
        var failures = 0;
        var halted   = false;   // set only when there is no point asking again
        var touched  = Date.now();

        var awake = function () {
            return document.visibilityState !== 'hidden'
                && (Date.now() - touched) < IDLE_LIMIT;
        };

        var schedule = function (ms) {
            if (timer) { clearTimeout(timer); }
            timer = setTimeout(poll, ms);
        };

        /* The poll URL is this page's own query string with the action
           swapped. That is deliberate: filter, search, p, before and find all
           describe which slice of the inbox and the thread is on screen, and
           the server has to render the same slice back. `compose` is dropped
           because the recipient picker is never part of an update and
           building it would cost a query for markup nobody sees. */
        var pollUrl = function () {
            var q = new URLSearchParams(window.location.search);
            q.set('page', 'messages');
            q.set('action', 'poll');
            q.delete('compose');
            q.set('sig', sig);
            q.set('wait', '1');
            q.set('visible', document.visibilityState === 'hidden' ? '0' : '1');
            return window.location.pathname + '?' + q.toString();
        };

        /* Swap the history in place.

           Scroll is preserved by intent rather than by number: someone
           reading the newest message stays pinned to the newest message, and
           someone who has scrolled up to read something older keeps that
           older thing where it was, however much arrives below it. */
        var swapStream = function (html) {
            if (!stream) { return; }

            var pinned = stream.scrollHeight - stream.scrollTop - stream.clientHeight < 80;
            var above  = stream.scrollHeight - stream.scrollTop;

            stream.innerHTML = html;

            // The new bubbles need the handlers the old ones had.
            enhanceBubbles(stream);
            enhanceVoice(stream);

            stream.scrollTop = pinned
                ? stream.scrollHeight
                : stream.scrollHeight - above;
        };

        var apply = function (data) {
            // innerHTML, and only ever with markup this application rendered
            // and escaped through sanitize(). Nothing typed by a user reaches
            // this line without having been through the same partials the
            // full page render uses.
            if (typeof data.total === 'string' && totalBox) {
                totalBox.innerHTML = data.total;
            }
            if (typeof data.items === 'string' && itemsBox) {
                itemsBox.innerHTML = data.items;
            }
            if (typeof data.stream === 'string') {
                swapStream(data.stream);
            }
        };

        function poll() {
            timer = null;

            if (halted) { return; }

            // Asleep: no request at all. The listeners below wake it the
            // moment the tab is looked at or touched again.
            if (!awake()) { return; }

            busy    = true;
            pending = new AbortController();

            fetch(pollUrl(), {
                credentials: 'same-origin',
                headers: { 'Accept': 'application/json' },
                signal: pending.signal
            }).then(function (res) {
                /* A session that has timed out answers with a redirect to the
                   login page, which is HTML. Reloading hands the reader the
                   real login screen instead of leaving a thread that has
                   quietly stopped moving. */
                var type = res.headers.get('content-type') || '';
                if (type.indexOf('application/json') === -1) {
                    halted = true;
                    window.location.reload();
                    return null;
                }
                return res.json();
            }).then(function (data) {
                busy = false;
                if (!data) { return; }

                // Access ended while the tab sat open — a lease closed, an
                // account deactivated. The ordinary route explains it
                // properly; this one only knows to step aside.
                if (data.reload) {
                    halted = true;
                    window.location.reload();
                    return;
                }

                failures = 0;

                if (data.sig) { sig = data.sig; }
                if (data.changed) { apply(data); }

                schedule(RECONNECT);
            }).catch(function (err) {
                busy = false;

                // An abort is this file's own doing — a navigation, or the
                // tab being hidden — and is not a failure.
                if (err && err.name === 'AbortError') { return; }

                failures++;
                schedule(Math.min(BACKOFF_MAX, 1000 * Math.pow(2, failures)));
            });
        }

        /* Waking up. Any of these means someone is there, so the clock on the
           idle ceiling restarts; if the loop had stopped, it starts again at
           once rather than waiting out a timer. */
        var wake = function () {
            touched = Date.now();
            if (!halted && !timer && !busy) { schedule(0); }
        };

        /* mousemove is in the list deliberately. Without it, someone who has
           been reading a long thread for ten minutes without clicking
           anything would find the updates had quietly stopped — which is the
           bug this file exists to fix, arriving by a different door. wake()
           is a timestamp and two boolean checks, so firing it on move costs
           nothing worth measuring. */
        ['mousemove', 'mousedown', 'keydown', 'touchstart', 'wheel'].forEach(function (evt) {
            document.addEventListener(evt, wake, { passive: true });
        });
        window.addEventListener('focus', wake);
        if (stream) {
            stream.addEventListener('scroll', wake, { passive: true });
        }

        document.addEventListener('visibilitychange', function () {
            if (document.visibilityState === 'hidden') {
                // Let the held request go rather than leaving a worker
                // waiting on a reader who has switched away.
                if (pending) { pending.abort(); pending = null; }
                if (timer) { clearTimeout(timer); timer = null; }
            } else {
                wake();
            }
        });

        // Nothing held open across a navigation.
        window.addEventListener('pagehide', function () {
            halted = true;
            if (pending) { pending.abort(); }
            if (timer) { clearTimeout(timer); }
        });

        /* Coming back through the browser's Back button can restore this page
           from the back/forward cache rather than re-running it, in which
           case pagehide has already stopped the loop and nothing would start
           it again — a thread that looks live and is not. */
        window.addEventListener('pageshow', function (e) {
            if (e && e.persisted) {
                halted = false;
                wake();
            }
        });

        schedule(0);
    }
}());
