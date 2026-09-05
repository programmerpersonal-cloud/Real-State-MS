/**
 * Reporting workspace — chart bootstrap and toolbar behaviour.
 *
 * Loaded only on ?page=reports, through the $extraScripts hook in
 * views/components/scripts.php.
 *
 * Three things this file is careful about, all of them lessons the old
 * inline chart script had to learn the hard way:
 *
 *   Colour comes from the stylesheet, never from here. Every series names a
 *   CSS custom property and this file reads its computed value, so the charts
 *   cannot drift from the palette the rest of the product uses — and changing
 *   --primary moves the line on the chart with everything else.
 *
 *   A canvas is drawn into exactly once. Chart.js throws if a second
 *   instance is created on a canvas that already has one, and the usual way
 *   to hit that is a re-render that nobody expected. Every instance is kept
 *   in a registry keyed by canvas id and destroyed before it is replaced.
 *
 *   No data is invented. The server decides whether a card has anything to
 *   show and renders an empty state instead of a canvas when it does not, so
 *   this file never has to guess and never draws a chart of zeroes.
 *
 * Data arrives as JSON in a <script type="application/json"> block beside
 * each canvas — the same pattern scripts.php already uses for the validation
 * rules — so no executable data is written into the page.
 */
(function () {
    'use strict';

    /** Live chart instances, keyed by canvas id. */
    var registry = Object.create(null);

    // ─── Tokens ────────────────────────────────────────────────────────

    var css = getComputedStyle(document.documentElement);

    function token(name, fallback) {
        var v = css.getPropertyValue(name);
        return (v && v.trim()) || fallback;
    }

    /**
     * A token colour at partial opacity.
     *
     * Handles the #rgb and #rrggbb the design system actually uses, and
     * returns the colour untouched if it is in any other notation — a fill
     * that is too solid is a cosmetic problem, a thrown exception takes the
     * whole chart down.
     */
    function alpha(color, a) {
        var h = String(color).trim();
        if (h.charAt(0) !== '#') { return h; }
        h = h.slice(1);
        if (h.length === 3) { h = h[0] + h[0] + h[1] + h[1] + h[2] + h[2]; }
        if (h.length !== 6) { return color; }
        var n = parseInt(h, 16);
        if (isNaN(n)) { return color; }
        return 'rgba(' + ((n >> 16) & 255) + ',' + ((n >> 8) & 255) + ',' + (n & 255) + ',' + a + ')';
    }

    // ─── Formatting ────────────────────────────────────────────────────
    //
    // The currency symbol is read from the page rather than hardcoded, so a
    // chart tooltip cannot end up quoting a different currency from the
    // receipt the same money appears on.

    var symbol = (document.querySelector('[data-currency-symbol]') || {}).dataset;
    symbol = (symbol && symbol.currencySymbol) || '$';

    function money(v) {
        return symbol + Number(v).toLocaleString(undefined, {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        });
    }

    /** Short form for an axis tick, where there is no room for the full number. */
    function brief(v) {
        var n = Math.abs(Number(v));
        if (n >= 1e6) { return symbol + (n / 1e6).toFixed(1).replace(/\.0$/, '') + 'M'; }
        if (n >= 1e3) { return symbol + (n / 1e3).toFixed(1).replace(/\.0$/, '') + 'K'; }
        return symbol + n;
    }

    function count(v) {
        return Number(v).toLocaleString();
    }

    /**
     * A category label on a horizontal axis.
     *
     * "Warehouse in the industrial district" as a y-axis label eats half the
     * plot and pushes the bars into a strip. It is ellipsised here and given
     * in full by the tooltip and by the card's data table, so nothing is lost
     * -- only the axis is asked to be brief.
     */
    function axisLabel(value) {
        var label = this.getLabelForValue ? this.getLabelForValue(value) : value;
        label = String(label === undefined || label === null ? '' : label);
        return label.length > 24 ? label.slice(0, 23) + '\u2026' : label;
    }

    function formatter(unit) {
        if (unit === 'currency') { return money; }
        if (unit === 'percent') { return function (v) { return Number(v).toFixed(1) + '%'; }; }
        return count;
    }

    function tickFormatter(unit) {
        if (unit === 'currency') { return brief; }
        if (unit === 'percent') { return function (v) { return v + '%'; }; }
        return count;
    }

    // ─── Reading a card's data ─────────────────────────────────────────

    function readConfig(id) {
        var el = document.getElementById(id + '-data');
        if (!el) { return null; }
        try {
            return JSON.parse(el.textContent);
        } catch (e) {
            // Malformed JSON is a bug on the server side, not something the
            // reader can act on. The card keeps its data table, which is the
            // same figures in a form that never needed parsing.
            return null;
        }
    }

    // ─── Chart construction ────────────────────────────────────────────

    /**
     * A series, with its gaps intact.
     *
     * Number(null) is 0, and passing a series through it turns "no rent was
     * scheduled in this bucket" into "0% was collected" — the one reading a
     * collection-rate chart must never invite. A null stays null and Chart.js
     * leaves a break in the line, which is the honest picture.
     */
    function values(data) {
        return data.map(function (v) {
            return (v === null || v === undefined || v === '') ? null : Number(v);
        });
    }

    function lineOrBarData(cfg, ink) {
        return {
            labels: cfg.labels,
            datasets: cfg.series.map(function (s, i) {
                var color = token(s.tone || '--primary', ink);
                var isBar = cfg.type === 'bar';
                return {
                    label: s.label,
                    data: values(s.data),
                    borderColor: color,
                    backgroundColor: isBar ? alpha(color, 0.85) : alpha(color, 0.10),
                    borderWidth: isBar ? 0 : 2,
                    borderRadius: isBar ? 3 : 0,
                    fill: !isBar,
                    tension: 0.32,
                    pointBackgroundColor: color,
                    pointBorderColor: token('--surface', '#fff'),
                    pointBorderWidth: 2,
                    // A visible point at rest, a bigger one under the cursor.
                    // The resting radius matters on a sparse series: a single
                    // month of revenue with no point is an invisible chart.
                    pointRadius: 3,
                    pointHoverRadius: 6,
                    // Comparison series arrive dashed so the two are
                    // distinguishable without relying on colour alone —
                    // which matters on a greyscale print as much as it does
                    // for a reader who cannot separate the two hues.
                    borderDash: (i > 0 && !isBar) ? [5, 4] : [],
                    // Not a Chart.js option; carried on the dataset so the
                    // tooltip callback can name the previous period's dates.
                    altLabels: s.altLabels || null
                };
            })
        };
    }

    function doughnutData(cfg, ink) {
        var s = cfg.series[0] || { data: [], tones: [] };
        var tones = s.tones || [];
        return {
            labels: cfg.labels,
            datasets: [{
                label: s.label,
                data: values(s.data),
                backgroundColor: cfg.labels.map(function (_, i) {
                    return token(tones[i] || '--primary', ink);
                }),
                borderColor: token('--surface', '#fff'),
                borderWidth: 2,
                hoverOffset: 4
            }]
        };
    }

    function build(canvas) {
        var id = canvas.id;
        var cfg = readConfig(id);
        if (!cfg || !cfg.series || !cfg.series.length) { return; }

        // Belt and braces against the double-instance exception: the registry
        // covers charts this file made, and Chart.getChart() catches one made
        // by anything else on the same canvas.
        if (registry[id]) {
            registry[id].destroy();
            delete registry[id];
        }
        if (window.Chart.getChart) {
            var existing = window.Chart.getChart(canvas);
            if (existing) { existing.destroy(); }
        }

        var ink = token('--text-muted', '#5f6b7e');
        var line = token('--border', '#e4eaee');
        // Gridlines carry no information -- they are a ruler. At full border
        // strength on a 230px card they compete with the series drawn over
        // them, so they are dropped to a whisper and the axis line with them.
        var rule = alpha(line, 0.72);
        var isRound = cfg.type === 'doughnut' || cfg.type === 'pie';
        var fmt = formatter(cfg.unit);

        // One legend across the workspace: circles, bottom, same gap. A
        // single-series chart has nothing to distinguish, so it has no legend
        // at all -- the card title already says what the bars are.
        var legend = {
            display: isRound || cfg.series.length > 1,
            position: 'bottom',
            labels: {
                boxWidth: 8,
                boxHeight: 8,
                padding: 14,
                usePointStyle: true,
                pointStyle: 'circle',
                color: ink
            }
        };

        var options = {
            responsive: true,
            // The card reserves the height in CSS, so Chart.js fills its box
            // rather than deriving one from an aspect ratio.
            maintainAspectRatio: false,
            // Readable immediately for anyone who has asked for less motion.
            animation: window.matchMedia('(prefers-reduced-motion: reduce)').matches
                ? false
                : { duration: 320, easing: 'easeOutQuart' },
            interaction: { mode: isRound ? 'nearest' : 'index', intersect: false },
            // The card reserves the box; this keeps the drawing off its own
            // edges so a top gridline label is never clipped by the border.
            layout: { padding: { top: 4, right: 2, bottom: 0, left: 0 } },
            plugins: {
                legend: legend,
                tooltip: {
                    backgroundColor: token('--ink', '#10222c'),
                    padding: 11,
                    cornerRadius: 6,
                    titleFont: { weight: '600', size: 12 },
                    bodyFont: { size: 12 },
                    bodySpacing: 4,
                    boxPadding: 4,
                    displayColors: true,
                    callbacks: {
                        label: function (c) {
                            var name = isRound ? c.label : (c.dataset.label || '');
                            var raw = isRound ? c.parsed : c.parsed.y;
                            if (raw === null || raw === undefined) {
                                return name ? name + ': not applicable' : 'not applicable';
                            }
                            var value = fmt(raw);

                            // The comparison series is aligned by position,
                            // not by date, so the axis label belongs to *this*
                            // period. Without naming its own bucket, the
                            // previous line would appear to be quoting a
                            // figure for a day it has nothing to say about.
                            var alt = c.dataset.altLabels && c.dataset.altLabels[c.dataIndex];
                            if (alt) { name += ' (' + alt + ')'; }

                            return name ? name + ': ' + value : value;
                        },
                        // Proportion charts state the share in the tooltip as
                        // well as the amount: a slice that looks like "about a
                        // third" should not have to be estimated by eye.
                        afterLabel: function (c) {
                            if (!isRound) { return ''; }
                            var total = c.dataset.data.reduce(function (a, b) { return a + Number(b); }, 0);
                            if (!total) { return ''; }
                            return (Number(c.parsed) / total * 100).toFixed(1) + '% of total';
                        }
                    }
                }
            }
        };

        if (isRound) {
            // A slightly thinner ring than the default reads as a chart
            // rather than as a pie with a hole, and leaves the centre clean.
            options.cutout = '66%';
            options.radius = '92%';
        } else if (cfg.type === 'bar' && cfg.horizontal) {
            // A composition read left to right: the categories are few and
            // their labels are words, which a vertical axis would rotate.
            options.indexAxis = 'y';
            options.maxBarThickness = 34;
            options.scales = {
                x: {
                    beginAtZero: true,
                    stacked: !!cfg.stacked,
                    border: { display: false },
                    grid: { color: rule, drawTicks: false },
                    ticks: {
                        callback: function (v) { return tickFormatter(cfg.unit)(v); },
                        maxTicksLimit: 6,
                        padding: 6
                    }
                },
                y: {
                    stacked: !!cfg.stacked,
                    grid: { display: false },
                    border: { display: false },
                    ticks: { padding: 6, callback: axisLabel }
                }
            };
        } else {
            var ticks = tickFormatter(cfg.unit);
            options.maxBarThickness = 46;
            options.scales = {
                x: {
                    stacked: !!cfg.stacked,
                    grid: { display: false },
                    border: { color: rule },
                    // Never rotated. A rotated axis label is unreadable at
                    // this size, and the server already chose a grain coarse
                    // enough that the labels fit -- dropping every other one
                    // is the honest way to run out of room.
                    ticks: { maxRotation: 0, autoSkip: true, autoSkipPadding: 14, padding: 6 }
                },
                y: {
                    beginAtZero: true,
                    stacked: !!cfg.stacked,
                    border: { display: false },
                    grid: { color: rule, drawTicks: false },
                    ticks: { callback: function (v) { return ticks(v); }, maxTicksLimit: 6, padding: 6 },
                    // A percentage axis is bounded by definition. Letting it
                    // autoscale to 42% makes a poor collection rate look like
                    // a full bar.
                    max: cfg.unit === 'percent' ? 100 : undefined
                }
            };
        }

        // A drillable card gets a click handler and a pointer cursor; one
        // without a `drill` block gets neither, so a chart that cannot be
        // traced to records never invites the click.
        var onDrill = chartDrill(canvas, cfg);
        if (onDrill) {
            options.onClick = onDrill;
            options.onHover = function (event, elements) {
                event.native.target.style.cursor = elements.length ? 'pointer' : 'default';
            };
            canvas.parentNode.classList.add('rchart--drill');
        }

        registry[id] = new window.Chart(canvas, {
            type: cfg.type || 'line',
            data: isRound ? doughnutData(cfg, ink) : lineOrBarData(cfg, ink),
            options: options
        });
    }

    /**
     * Drill-down.
     *
     * Every drillable figure on the page is an ordinary link to an ordinary
     * URL. This intercepts the click, fetches the same URL with &partial=1
     * and puts the panel in the drawer instead of navigating -- which means
     * the feature degrades to a page load rather than to nothing, and the
     * link can still be copied, bookmarked and sent to somebody.
     *
     * History is pushed so Back closes the drawer and returns the reader to
     * the report they were reading rather than to whatever they were looking
     * at before it. That is the behaviour the browser's own button promises
     * and the one people press without thinking.
     */
    function bindDrilldown() {
        var drawer = document.querySelector('[data-drill-drawer]');
        if (!drawer) { return; }

        var panel   = drawer.querySelector('.drawer__panel');
        var content = drawer.querySelector('[data-drill-content]');
        var opener  = null;   // what to give focus back to
        var token   = 0;      // so a slow response cannot overwrite a fast one

        function isOpen() { return !drawer.hidden; }

        function open() {
            if (isOpen()) { return; }
            drawer.hidden = false;
            document.body.classList.add('has-drawer');
        }

        /**
         * Close, and put focus back where it came from.
         *
         * `push` distinguishes the two ways a drawer closes: a person
         * dismissing it should leave the history entry behind them, and the
         * Back button arriving here should not add another one.
         */
        function close(push) {
            if (!isOpen()) { return; }
            drawer.hidden = true;
            document.body.classList.remove('has-drawer');
            content.innerHTML = '';

            if (push && window.history && history.pushState) {
                history.pushState({ drill: false }, '', reportUrlOf(location.href));
            }
            if (opener && document.contains(opener)) {
                opener.focus();
            }
            opener = null;
        }

        /** The report URL a drill-down was opened from: same query, no drill. */
        function reportUrlOf(href) {
            var url = href.split('#')[0];
            var cut = url.split('?');
            if (cut.length < 2) { return url; }

            var kept = [];
            cut[1].split('&').forEach(function (pair) {
                var name = pair.split('=')[0];
                if (name !== 'action' && name !== 'metric' && name !== 'key'
                    && name !== 'dp' && name !== 'partial') {
                    kept.push(pair);
                }
            });

            return cut[0] + (kept.length ? '?' + kept.join('&') : '');
        }

        function load(href, push) {
            var mine = ++token;
            var url  = href + (href.indexOf('?') === -1 ? '?' : '&') + 'partial=1';

            open();
            panel.setAttribute('aria-busy', 'true');
            // The heading the dialog is labelled by has to exist for the
            // whole time the dialog does, including while it is loading —
            // aria-labelledby pointing at nothing leaves a modal with no
            // accessible name for anyone arriving on it by keyboard.
            content.innerHTML =
                '<h2 class="sr-only" id="drillTitle">Loading records</h2>'
                + '<div class="drawer__loading" role="status">Loading records…</div>';

            fetch(url, { credentials: 'same-origin', headers: { 'X-Requested-With': 'fetch' } })
                .then(function (r) {
                    // A refused drill-down is a real answer -- 403 from an
                    // expired session, 404 from a stale link -- and following
                    // the link is the honest way to show it.
                    if (!r.ok) { throw new Error('HTTP ' + r.status); }
                    return r.text();
                })
                .then(function (html) {
                    if (mine !== token) { return; }
                    content.innerHTML = html;
                    panel.setAttribute('aria-busy', 'false');
                    panel.scrollTop = 0;
                    panel.focus();

                    if (push && window.history && history.pushState) {
                        history.pushState({ drill: true, href: href }, '', href);
                    }
                })
                .catch(function () {
                    if (mine !== token) { return; }
                    // Whatever went wrong, the URL is a real page. Go to it
                    // rather than leaving an empty drawer open.
                    window.location.href = href;
                });
        }

        // One listener for the whole page, so a drill link inside the drawer
        // -- the pager -- works exactly like one on the report behind it.
        document.addEventListener('click', function (e) {
            var link = e.target.closest && e.target.closest('a[data-drill]');
            if (!link) { return; }

            // Leave the modified clicks alone: somebody holding a modifier is
            // asking for a new tab, and a drawer is not one.
            if (e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) {
                return;
            }

            e.preventDefault();
            if (!isOpen()) { opener = link; }
            load(link.href, true);
        });

        Array.prototype.forEach.call(
            drawer.querySelectorAll('[data-drill-close]'),
            function (el) { el.addEventListener('click', function () { close(true); }); }
        );

        document.addEventListener('keydown', function (e) {
            if (!isOpen()) { return; }

            if (e.key === 'Escape') {
                e.stopPropagation();
                close(true);
                return;
            }

            // Focus stays inside while the dialog is modal. Wrapped rather
            // than clamped, so Shift+Tab from the first element reaches the
            // last instead of escaping to the page underneath.
            if (e.key !== 'Tab') { return; }

            var focusable = panel.querySelectorAll(
                'a[href], button:not([disabled]), input, select, textarea, [tabindex]:not([tabindex="-1"])'
            );
            if (!focusable.length) { return; }

            var first = focusable[0];
            var last  = focusable[focusable.length - 1];

            if (e.shiftKey && document.activeElement === first) {
                e.preventDefault();
                last.focus();
            } else if (!e.shiftKey && document.activeElement === last) {
                e.preventDefault();
                first.focus();
            }
        });

        window.addEventListener('popstate', function (e) {
            if (e.state && e.state.drill && e.state.href) {
                load(e.state.href, false);
            } else {
                close(false);
            }
        });
    }

    /**
     * A chart segment, drilled.
     *
     * The card carries a `drill` block beside its data: a URL template and
     * one key per label, in the same order the labels are in. Clicking a bar
     * or a slice resolves the key at that index and follows the link the
     * table row underneath would have followed -- so the picture and the
     * table drill to the same place, because they drill through the same
     * array.
     */
    function chartDrill(canvas, config) {
        var drill = config.drill;
        if (!drill || !drill.url || !drill.keys) { return null; }

        return function (event, elements) {
            if (!elements || !elements.length) { return; }

            var index = elements[0].index;
            var key   = drill.keys[index];
            if (key === undefined || key === null || key === '') { return; }

            var href = drill.url.replace('__KEY__', encodeURIComponent(key));
            var link = document.createElement('a');
            link.href = href;
            link.setAttribute('data-drill', '');
            // Routed through the same delegated listener as every other drill
            // link, so the drawer, the history entry and the focus handling
            // are the ones already written rather than a second copy.
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
        };
    }

    // ─── Boot ──────────────────────────────────────────────────────────

    function init() {
        var canvases = document.querySelectorAll('[data-report-chart]');
        if (!canvases.length) { return; }

        // The vendor file may be absent on a fresh clone that has not run
        // assets/vendor/download-vendor. Every card already carries its own
        // data table, so the report is still completely readable — it is a
        // report without pictures rather than a broken page.
        if (!window.Chart) { return; }

        window.Chart.defaults.font.family = token('--font', 'Inter, sans-serif');
        window.Chart.defaults.font.size = 11;
        window.Chart.defaults.color = token('--text-muted', '#5f6b7e');

        Array.prototype.forEach.call(canvases, function (canvas) {
            try {
                build(canvas);
            } catch (e) {
                // One malformed card must not stop the others from drawing.
                if (window.console && console.warn) {
                    console.warn('Report chart "' + canvas.id + '" could not be drawn.', e);
                }
            }
        });
    }

    /**
     * Print.
     *
     * The stylesheet does the work; this only opens the data tables first,
     * because a <details> that is shut prints nothing at all and the tables
     * are the whole reason a printed report is readable.
     */
    function bindPrint() {
        var btn = document.querySelector('[data-report-print]');
        if (!btn) { return; }

        btn.addEventListener('click', function () {
            // Both kinds of disclosure. A printed page showing "4 items need
            // attention" and then listing none of them is worse than not
            // printing the panel at all.
            var opened = [];
            Array.prototype.forEach.call(
                document.querySelectorAll('.rdata:not([open]), .dq:not([open])'),
                function (d) {
                    d.open = true;
                    opened.push(d);
                }
            );

            // Charts are canvases and print as they are; the tables above
            // are what a reader marks up afterwards.
            window.print();

            // Put the page back the way it was found.
            window.setTimeout(function () {
                opened.forEach(function (d) { d.open = false; });
            }, 0);
        });
    }

    /**
     * One popover at a time.
     *
     * The range panel and the filter panel are both <details>, and both are
     * absolutely positioned over the same band. Leaving both open stacks one
     * on top of the other.
     */
    function bindDisclosures() {
        var panels = document.querySelectorAll('.rrange__custom, .rtoolbar .toolbar__filters');
        Array.prototype.forEach.call(panels, function (d) {
            d.addEventListener('toggle', function () {
                if (!d.open) { return; }
                Array.prototype.forEach.call(panels, function (other) {
                    if (other !== d) { other.open = false; }
                });
            });
        });

        // Escape closes whichever is open and returns focus to its trigger,
        // so the keyboard is never left inside a panel it cannot leave.
        document.addEventListener('keydown', function (e) {
            if (e.key !== 'Escape') { return; }
            Array.prototype.forEach.call(panels, function (d) {
                if (!d.open) { return; }
                d.open = false;
                var summary = d.querySelector('summary');
                if (summary) { summary.focus(); }
            });
        });
    }

    /**
     * The chosen item in a horizontal track, brought into view.
     *
     * Both of the workspace's scrolling tracks need this, and for the same
     * reason. The tab strip scrolls horizontally below about 900px and
     * "Performance" is the eighth of eight, so landing on it and finding the
     * strip parked at "Overview" reads as though the page opened on the wrong
     * report. The period track scrolls on a phone and "This year" is the last
     * of seven, so without this a reader would have to scroll a control bar
     * sideways to find out which period the figures below it cover.
     *
     * Jumped, not animated: a strip that slides on every page load is motion
     * nobody asked for, and it would fight prefers-reduced-motion.
     */
    function centreActive(trackSelector, activeSelector) {
        var track = document.querySelector(trackSelector);
        var active = track && track.querySelector(activeSelector);
        if (!track || !active || track.scrollWidth <= track.clientWidth) { return; }

        // Measured between the two boxes rather than from offsetLeft, which
        // is relative to the nearest *positioned* ancestor. Neither track is
        // positioned, so offsetLeft was being measured from <body> and
        // included the sidebar and the page's own padding: on a 390px screen
        // the eighth tab landed 15px past the right edge of the strip that
        // had just scrolled to show it.
        var delta = active.getBoundingClientRect().left - track.getBoundingClientRect().left;

        track.scrollLeft = Math.max(
            0,
            track.scrollLeft + delta - (track.clientWidth - active.offsetWidth) / 2
        );
    }

    function boot() {
        init();
        bindPrint();
        bindDisclosures();
        bindDrilldown();
        centreActive('.rtabs', '[data-report-tab-active]');
        centreActive('.rrange', '.rrange__btn.is-active');
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }

    /**
     * Exposed so a later phase can redraw a card after replacing its data
     * block, without ever risking a second instance on the same canvas.
     */
    window.SaxaneReports = {
        redraw: function (id) {
            var canvas = document.getElementById(id);
            if (canvas && window.Chart) { build(canvas); }
        },
        destroy: function (id) {
            if (registry[id]) {
                registry[id].destroy();
                delete registry[id];
            }
        }
    };
})();
