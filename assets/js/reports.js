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

        registry[id] = new window.Chart(canvas, {
            type: cfg.type || 'line',
            data: isRound ? doughnutData(cfg, ink) : lineOrBarData(cfg, ink),
            options: options
        });
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
     * The active report, brought into view.
     *
     * The tab strip scrolls horizontally below about 900px, and "Performance"
     * is the eighth of eight. Landing on it and finding the strip parked at
     * "Overview" reads as though the page opened on the wrong report. Jumped,
     * not animated: a strip that slides on every page load is motion nobody
     * asked for, and it would fight prefers-reduced-motion.
     */
    function centreActiveTab() {
        var nav = document.querySelector('.rtabs');
        var active = nav && nav.querySelector('[data-report-tab-active]');
        if (!nav || !active || nav.scrollWidth <= nav.clientWidth) { return; }
        nav.scrollLeft = Math.max(
            0,
            active.offsetLeft - (nav.clientWidth - active.offsetWidth) / 2
        );
    }

    function boot() {
        init();
        bindPrint();
        bindDisclosures();
        centreActiveTab();
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
