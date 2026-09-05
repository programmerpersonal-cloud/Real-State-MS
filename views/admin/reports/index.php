<?php
/**
 * The reporting workspace — the shell every report is drawn inside.
 *
 * One entry point rather than eight near-identical pages: the tab strip, the
 * masthead, the toolbar and the page furniture are written once here, and the
 * active report supplies only its own body from views/admin/reports/tabs/.
 *
 * The order of the three bands above the report is deliberate and changed in
 * Phase 8. It now answers the reader's questions in the order they are asked:
 *
 *   tabs      which report am I in, and what else is there
 *   masthead  what is this report, and what exactly is it showing
 *   toolbar   how do I change it
 *
 * Before, the toolbar sat above the tabs and *also* carried the period
 * statement at its foot — so the page both offered and asserted the period in
 * one band, three controls apart, and a reader who had scrolled past it had
 * nothing on screen telling them which fortnight the figures covered. The
 * statement moved into the masthead, where it sits directly above the
 * numbers it qualifies; the toolbar kept the controls and nothing else.
 *
 * Vars from ReportController::render(). The tab bodies read whatever their
 * own controller method put in scope; because this file requires them
 * directly, they inherit it without anything having to be passed along.
 */

/* The workspace ships its own stylesheet and its own script, and no other
   page pays for either. Both hooks read the variable set here because
   layout.php renders this view into a buffer before it renders <head> — so
   by the time styles.php and scripts.php run, these are already in scope. */
$pageStyles   = ['pages/reports'];
$extraScripts = ['reports'];

$activeTab = $reportTab ?? 'overview';

/* Raised by _chart_card.php the first time it renders a canvas. The Chart.js
   vendor file is then loaded once, after the report body, and only on a tab
   that actually drew something — a tab with no canvas should not be paying
   200KB for a library it never calls. */
$GLOBALS['reportHasChart'] = false;
?>

<?php /* The configured currency, as data rather than as generated JavaScript.
         reports.js reads it here so a chart tooltip cannot end up quoting a
         different currency from the receipt for the same money. */ ?>
<div data-currency-symbol="<?= sanitize(currencySymbol()) ?>" hidden></div>

<?php require __DIR__ . '/_tabs.php'; ?>

<?php /* aria-live is deliberately absent: each tab is a full page load, so the
         browser and the screen reader already handle the change of context —
         announcing it again would be a second, competing narration of the
         same event. */ ?>
<section class="report" aria-labelledby="report-heading">

    <?php require __DIR__ . '/_report_header.php'; ?>
    <?php require __DIR__ . '/_toolbar.php'; ?>

    <div class="report__body">
        <?php
        /* Only a name from the controller's own allowlist can reach this, so
           the path cannot be steered from the request. The file check is for
           the developer who adds a tab to TABS and forgets its body. */
        $tabFile = __DIR__ . '/tabs/' . $activeTab . '.php';
        if (is_file($tabFile)) {
            require $tabFile;
        } else {
            echo uiEmptyState([
                'icon'  => 'bi-hammer',
                'title' => 'This report has no view yet',
                'desc'  => 'The tab is registered but its body has not been added.',
            ]);
        }
        ?>
    </div>
</section>

<?php /* The drill-down drawer.

         Empty until something is clicked, and rendered here rather than
         injected by script so its dialog semantics, its heading and its close
         control exist in the document from the start. reports.js fetches the
         same URL the link points at with &partial=1 and puts the panel
         inside; without scripting the link is simply followed and the panel
         renders as its own page. Neither path is a fallback for the other —
         they render the same partial. §1, §15, §18. */ ?>
<div class="drawer" id="drillDrawer" hidden data-drill-drawer>
    <div class="drawer__scrim" data-drill-close></div>
    <section class="drawer__panel"
             role="dialog"
             aria-modal="true"
             aria-labelledby="drillTitle"
             aria-busy="false"
             tabindex="-1">
        <button type="button" class="drawer__close" data-drill-close aria-label="Close drill-down">
            <i class="bi bi-x-lg" aria-hidden="true"></i>
        </button>
        <div class="drawer__content" data-drill-content>
            <h2 class="sr-only" id="drillTitle">Drill-down</h2>
        </div>
    </section>
</div>

<?php if ($GLOBALS['reportHasChart']): ?>
    <?php /* Ahead of reports.js, which scripts.php prints at the end of the
             body. Both are plain synchronous tags, so Chart.js is defined by
             the time the workspace script looks for it — and if the vendor
             file is missing from a fresh clone, reports.js says so by simply
             not drawing: every card already carries its figures as a table. */ ?>
    <script src="<?= VENDOR_URL ?>/chartjs/chart.umd.min.js"></script>
<?php endif ?>
