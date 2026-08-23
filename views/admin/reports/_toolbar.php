<?php
/**
 * The analytics toolbar — period, comparison, filters, output.
 *
 * One bar governing every report in the workspace, and every control on it is
 * a link or a plain GET form. That is not nostalgia: it means the period and
 * the filters live in the URL, so a report can be bookmarked, sent to whoever
 * has to answer for the figure, and reached again with the back button. It
 * also means the whole thing works with scripting off, which the rest of this
 * application already guarantees and this page has no business breaking.
 *
 * No date arithmetic happens here or in JavaScript. The eight ranges are
 * tokens; reportWindow() in includes/reporting.php turns a token into two
 * validated dates, and this file only ever prints what it was given.
 *
 * Expects: $window, $filters, $reportTab, $compare
 *
 * Every local in this file is prefixed, and that is not house style — it is a
 * bug fix. A partial pulled in with require shares the including view's
 * variable scope, so a plain $series or $meta here silently overwrites the
 * one the report was using. It cost exactly that: the KPI tiles clobbered the
 * overview's $spark and the revenue chart rendered "nothing to chart" over a
 * period with revenue in it, while the tab strip clobbered $meta and titled
 * the Overview "Performance". Prefixes are what make these safe to require
 * more than once, and in any order.
 */
$tbTab       = $reportTab ?? 'overview';
$tbRanges    = reportRanges();
$tbApplied   = reportFilterCount($filters);
$tbIsCustom  = $window['key'] === 'custom';
$tbAgents    = reportAgentOptions();
$tbLocations = reportLocationOptions();

/* Every control keeps the reader where they are. A change of period must not
   also be a change of report, and turning comparison on must not silently
   drop the filters someone spent a minute setting. */
$tbLink = static fn(array $over): string => reportUrl($window, $filters, ['tab' => $tbTab] + $over);

/* Which filters can actually be offered. A select with nothing in it is a
   control that can only disappoint, so an empty option list means the filter
   is not drawn at all — and on a single-agent session the agent filter is
   simply the reader, which is not a choice. */
$tbFields = [
    ['name' => 'category', 'label' => 'Category',
     'options' => array_combine(REPORT_CATEGORIES, array_map('categoryLabel', REPORT_CATEGORIES))],
    ['name' => 'location', 'label' => 'Location',
     'options' => array_combine($tbLocations, $tbLocations)],
    ['name' => 'agent', 'label' => 'Agent',
     'options' => count($tbAgents) > 1 ? $tbAgents : []],
    ['name' => 'payment_status', 'label' => 'Payment status',
     'options' => array_combine(REPORT_PAYMENT_STATUSES, array_map('uiLabel', REPORT_PAYMENT_STATUSES))],
    ['name' => 'payment_method', 'label' => 'Payment method',
     'options' => array_combine(REPORT_PAYMENT_METHODS, array_map('uiLabel', REPORT_PAYMENT_METHODS))],
];
/* Two reasons a filter is not drawn: this report cannot honour it, or there
   is nothing to choose from. Both produce the same outcome — the control is
   absent rather than present and inert. */
$tbHonoured = ReportController::filtersFor($tbTab);
$tbFields = array_values(array_filter(
    $tbFields,
    static fn(array $tbF): bool => !empty($tbF['options']) && in_array($tbF['name'], $tbHonoured, true)
));
?>

<?php if (!empty($window['notice'])): ?>
    <?php /* A custom range that could not be honoured says so, once, where the
             range was chosen. The report below it is real — it is simply the
             default period rather than the one that was asked for. */ ?>
    <div class="notice notice--info mb-2" role="status">
        <div class="notice__icon"><i class="bi bi-calendar-x" aria-hidden="true"></i></div>
        <div class="notice__body"><?= sanitize($window['notice']) ?></div>
    </div>
<?php endif ?>

<div class="rtoolbar">

    <!-- ── Period ─────────────────────────────────────────────────── -->
    <div class="rtoolbar__row">
        <div class="rtoolbar__group">
            <span class="rtoolbar__legend" id="rangeLegend">Period</span>
            <div class="rrange" role="group" aria-labelledby="rangeLegend">
                <?php foreach ($tbRanges as $tbKey => $tbLabel): ?>
                    <?php if ($tbKey === 'custom') continue; ?>
                    <a class="rrange__btn<?= $window['key'] === $tbKey ? ' is-active' : '' ?>"
                       href="<?= sanitize($tbLink(['range' => $tbKey, 'from' => null, 'to' => null])) ?>"
                       <?= $window['key'] === $tbKey ? 'aria-current="true"' : '' ?>><?= sanitize($tbLabel) ?></a>
                <?php endforeach ?>

                <?php /* Custom is a disclosure rather than a link: it has no
                         destination until two dates have been picked. Written
                         as <details> so it opens without JavaScript, and held
                         open when a custom range is already applied. */ ?>
                <details class="rrange__custom" <?= $tbIsCustom ? 'open' : '' ?>>
                    <summary class="rrange__btn<?= $tbIsCustom ? ' is-active' : '' ?>">
                        <i class="bi bi-calendar-range" aria-hidden="true"></i>
                        <span>Custom</span>
                    </summary>
                    <form class="rrange__panel" method="GET" action="<?= APP_URL ?>/index.php">
                        <input type="hidden" name="page" value="reports">
                        <input type="hidden" name="tab" value="<?= sanitize($tbTab) ?>">
                        <input type="hidden" name="range" value="custom">
                        <?php if (!empty($compare)): ?>
                            <input type="hidden" name="compare" value="1">
                        <?php endif ?>
                        <?php foreach (array_keys(reportFilterSpec()) as $tbKeep): ?>
                            <?php if (($filters[$tbKeep] ?? null) !== null): ?>
                                <input type="hidden" name="<?= sanitize($tbKeep) ?>"
                                       value="<?= sanitize((string) $filters[$tbKeep]) ?>">
                            <?php endif ?>
                        <?php endforeach ?>

                        <div class="rrange__fields">
                            <div class="toolbar__field">
                                <label class="toolbar__field-label" for="rangeFrom">From</label>
                                <input type="date" class="form-control" id="rangeFrom" name="from"
                                       max="<?= sanitize($window['today']) ?>"
                                       value="<?= sanitize($tbIsCustom ? $window['from'] : '') ?>">
                            </div>
                            <div class="toolbar__field">
                                <label class="toolbar__field-label" for="rangeTo">To</label>
                                <input type="date" class="form-control" id="rangeTo" name="to"
                                       max="<?= sanitize($window['today']) ?>"
                                       value="<?= sanitize($tbIsCustom ? $window['to'] : '') ?>">
                            </div>
                        </div>
                        <p class="rrange__hint">Up to five years. Dates that cannot be read fall back to the last 30 days.</p>
                        <div class="rrange__foot">
                            <button type="submit" class="btn btn--primary btn--sm">Apply range</button>
                        </div>
                    </form>
                </details>
            </div>
        </div>

        <div class="rtoolbar__actions">
            <?php /* A link that carries state, not a checkbox that needs a
                     submit. aria-pressed states the mode for anyone who
                     cannot see that the control is filled in. */ ?>
            <a class="rtoggle<?= !empty($compare) ? ' is-on' : '' ?>"
               href="<?= sanitize($tbLink(['compare' => !empty($compare) ? null : '1'])) ?>"
               role="button" aria-pressed="<?= !empty($compare) ? 'true' : 'false' ?>">
                <span class="rtoggle__track" aria-hidden="true"><span class="rtoggle__knob"></span></span>
                <span>Compare with previous period</span>
            </a>

            <?php /* Printing is real and works today. Export is not built yet
                     and is therefore not offered as a button that would do
                     nothing — the workspace would rather be short a control
                     than have one that lies. */ ?>
            <button type="button" class="btn btn--outline btn--sm" data-report-print>
                <i class="bi bi-printer" aria-hidden="true"></i>
                Print
            </button>
        </div>
    </div>

    <!-- ── Filters ────────────────────────────────────────────────── -->
    <?php if ($tbFields): ?>
    <div class="rtoolbar__row rtoolbar__row--filters">
        <form method="GET" action="<?= APP_URL ?>/index.php" class="rfilters">
            <input type="hidden" name="page" value="reports">
            <input type="hidden" name="tab" value="<?= sanitize($tbTab) ?>">
            <input type="hidden" name="range" value="<?= sanitize($window['key']) ?>">
            <?php if ($tbIsCustom): ?>
                <input type="hidden" name="from" value="<?= sanitize($window['from']) ?>">
                <input type="hidden" name="to" value="<?= sanitize($window['to']) ?>">
            <?php endif ?>
            <?php if (!empty($compare)): ?>
                <input type="hidden" name="compare" value="1">
            <?php endif ?>

            <details class="toolbar__filters" data-filter-popover <?= $tbApplied ? 'open' : '' ?>>
                <summary class="toolbar__filter-trigger" aria-controls="reportFilters">
                    <i class="bi bi-sliders" aria-hidden="true"></i>
                    <span>Filters</span>
                    <?php if ($tbApplied): ?>
                        <span class="toolbar__filter-count"><?= (int) $tbApplied ?></span>
                        <span class="sr-only"><?= $tbApplied === 1 ? 'filter applied' : 'filters applied' ?></span>
                    <?php endif ?>
                    <i class="bi bi-chevron-down toolbar__filter-chev" aria-hidden="true"></i>
                </summary>

                <div class="toolbar__popover" id="reportFilters">
                    <div class="toolbar__popover-grid">
                        <?php foreach ($tbFields as $tbF): ?>
                            <?php $tbId = 'rfilter-' . $tbF['name']; ?>
                            <div class="toolbar__field">
                                <label class="toolbar__field-label" for="<?= $tbId ?>"><?= sanitize($tbF['label']) ?></label>
                                <select name="<?= sanitize($tbF['name']) ?>" id="<?= $tbId ?>" class="form-control">
                                    <option value="">All <?= sanitize(strtolower($tbF['label'])) ?></option>
                                    <?php foreach ($tbF['options'] as $tbValue => $tbLabel): ?>
                                        <option value="<?= sanitize((string) $tbValue) ?>"
                                            <?= (string) ($filters[$tbF['name']] ?? '') === (string) $tbValue ? 'selected' : '' ?>>
                                            <?= sanitize((string) $tbLabel) ?>
                                        </option>
                                    <?php endforeach ?>
                                </select>
                            </div>
                        <?php endforeach ?>
                    </div>
                    <div class="toolbar__popover-foot">
                        <?php if ($tbApplied): ?>
                            <?php /* Reset drops the filters and keeps the
                                     period and the report — clearing one
                                     thing should not clear three. */ ?>
                            <a class="btn btn--ghost btn--sm"
                               href="<?= sanitize(reportUrl($window, [], ['tab' => $tbTab] + (!empty($compare) ? ['compare' => '1'] : []))) ?>">
                                Reset filters
                            </a>
                        <?php endif ?>
                        <button type="submit" class="btn btn--primary btn--sm">Apply filters</button>
                    </div>
                </div>
            </details>
        </form>

        <?php /* What is narrowing the report, stated rather than implied, and
                 each chip removes only itself. */ ?>
        <?php if ($tbApplied): ?>
            <div class="filter-chips">
                <span class="filter-chips__label">Filtered by</span>
                <?php foreach (reportFilterSpec() as $tbName => $tbSpec): ?>
                    <?php
                    $tbValue = $filters[$tbName] ?? null;
                    if ($tbValue === null) continue;

                    $tbShown = match ($tbName) {
                        'agent'    => $tbAgents[(int) $tbValue] ?? ('Agent #' . (int) $tbValue),
                        'category' => categoryLabel((string) $tbValue),
                        'property' => 'Property #' . (int) $tbValue,
                        'owner'    => 'Owner #' . (int) $tbValue,
                        default    => uiLabel((string) $tbValue),
                    };
                    ?>
                    <a class="filter-chip"
                       href="<?= sanitize(reportUrl($window, array_merge($filters, [$tbName => null]), ['tab' => $tbTab] + (!empty($compare) ? ['compare' => '1'] : []))) ?>">
                        <span class="filter-chip__key"><?= sanitize($tbSpec['label']) ?></span>
                        <?= sanitize((string) $tbShown) ?>
                        <span class="filter-chip__x" aria-hidden="true">&times;</span>
                        <span class="sr-only">— remove this filter</span>
                    </a>
                <?php endforeach ?>
            </div>
        <?php endif ?>
    </div>
    <?php endif ?>

    <!-- ── What the reader is looking at ──────────────────────────── -->
    <p class="rtoolbar__period">
        <i class="bi bi-calendar3" aria-hidden="true"></i>
        <strong><?= sanitize($window['label']) ?></strong>
        <span class="rtoolbar__dates">
            <?= sanitize(formatDate($window['from'])) ?> – <?= sanitize(formatDate($window['to_capped'])) ?>
            <?php if ($window['is_partial']): ?>
                <span class="rtoolbar__partial" title="This period has not finished; figures run to today.">so far</span>
            <?php endif ?>
        </span>
        <?php if (!empty($compare)): ?>
            <span class="rtoolbar__vs">
                vs <?= sanitize(formatDate($window['prev_from'])) ?> – <?= sanitize(formatDate($window['prev_to'])) ?>
                <span class="rtoolbar__days">(<?= (int) $window['days'] ?> days each)</span>
            </span>
        <?php endif ?>
    </p>
</div>
