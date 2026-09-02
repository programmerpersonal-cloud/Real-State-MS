<?php
/**
 * The report masthead — which report this is, and exactly what it is showing.
 *
 * Before Phase 8 the workspace answered those two questions in two different
 * places: an icon and a sentence at the top of the report body, and a period
 * statement at the *bottom* of the toolbar, three controls away from the
 * figures it qualified. A reader who scrolled past the toolbar was left with
 * a page of numbers and nothing on screen saying which fortnight they were
 * for. Both now sit together, immediately above the report, in the order the
 * questions get asked: what am I reading, and over what period.
 *
 * The context strip is a description list rather than a row of chips, and
 * deliberately: "Period / Last 30 days" is a term and its value, and a <dl>
 * is the one structure that says so to a screen reader without needing an
 * aria-label to explain the layout. Every entry is a *statement* — nothing
 * here is a control. The controls are in the toolbar below, which is what
 * stops the same period being both asserted and offered in one band.
 *
 * Nothing is drawn that is not true. The comparison entry appears only when
 * comparison is on, the scope entry only when a filter is actually narrowing
 * the report, and the "so far" mark only on a period that has not finished.
 *
 * Expects: $window, $filters, $compare, $reportTab
 *
 * Every local in this file is prefixed. A partial pulled in with require
 * shares the including view's variable scope; see the note in _kpi.php.
 */
$rhTab     = $reportTab ?? 'overview';
$rhMeta    = ReportController::TABS[$rhTab] ?? reset(ReportController::TABS);
$rhFilters = reportFilterCount($filters ?? []);
$rhCompare = !empty($compare);
?>
<header class="rhead">
    <div class="rhead__lead">
        <span class="rhead__mark" aria-hidden="true"><i class="bi <?= sanitize($rhMeta['icon']) ?>"></i></span>
        <div class="rhead__titles">
            <h2 class="rhead__title" id="report-heading"><?= sanitize($rhMeta['label']) ?></h2>
            <p class="rhead__lede"><?= sanitize($rhMeta['blurb']) ?></p>
        </div>
    </div>

    <dl class="rhead__context">
        <div class="rhead__ctx">
            <dt class="rhead__ctx-term">Period</dt>
            <dd class="rhead__ctx-value">
                <span class="rhead__ctx-lead">
                    <?= sanitize($window['label']) ?>
                    <?php if ($window['is_partial']): ?>
                        <?php /* Said in words, not implied by a colour: this
                                 period has not finished and the figures run
                                 to today rather than to its end date. */ ?>
                        <span class="rhead__partial">so far</span>
                    <?php endif ?>
                </span>
                <span class="rhead__ctx-sub">
                    <?= sanitize(formatDate($window['from'])) ?> – <?= sanitize(formatDate($window['to_capped'])) ?>
                </span>
            </dd>
        </div>

        <?php if ($rhCompare): ?>
            <div class="rhead__ctx rhead__ctx--compare">
                <dt class="rhead__ctx-term">Compared with</dt>
                <dd class="rhead__ctx-value">
                    <span class="rhead__ctx-lead">Previous period</span>
                    <span class="rhead__ctx-sub">
                        <?= sanitize(formatDate($window['prev_from'])) ?> – <?= sanitize(formatDate($window['prev_to'])) ?>
                        · <?= (int) $window['days'] ?> days each
                    </span>
                </dd>
            </div>
        <?php endif ?>

        <div class="rhead__ctx">
            <dt class="rhead__ctx-term">Scope</dt>
            <dd class="rhead__ctx-value">
                <span class="rhead__ctx-lead">
                    <?= $rhFilters > 0
                        ? sanitize($rhFilters . ($rhFilters === 1 ? ' filter' : ' filters') . ' applied')
                        : 'Whole portfolio' ?>
                </span>
                <span class="rhead__ctx-sub">
                    <?= $rhFilters > 0
                        ? 'Narrowed — every figure below reflects it'
                        : 'Everything you have access to' ?>
                </span>
            </dd>
        </div>
    </dl>

    <?php /* Print only. On screen the timestamp is noise — the reader is
             looking at it now. On paper it is the difference between a
             report and an undated sheet of numbers, and §19 asks for it. */ ?>
    <p class="rhead__stamp">
        <?= sanitize(companyName()) ?> ·
        <?= sanitize($rhMeta['label']) ?> report ·
        <?= sanitize($window['label']) ?>
        (<?= sanitize(formatDate($window['from'])) ?> – <?= sanitize(formatDate($window['to_capped'])) ?>)
        · Generated <?= sanitize(formatDateTime(date('Y-m-d H:i:s'))) ?>
    </p>
</header>
