<?php
/**
 * The reporting workspace — the shell every report is drawn inside.
 *
 * One entry point rather than eight near-identical pages: the toolbar, the
 * tab strip and the page furniture are written once here, and the active
 * report supplies only its own body from views/admin/reports/tabs/.
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

$tabs      = ReportController::TABS;
$activeTab = $reportTab ?? 'overview';
$meta      = $tabs[$activeTab] ?? reset($tabs);

/* Raised by _chart_card.php the first time it renders a canvas. The Chart.js
   vendor file is then loaded once, after the report body, and only on a tab
   that actually drew something — six of the eight currently do not, and none
   of them should be paying 200KB for a library they never call. */
$GLOBALS['reportHasChart'] = false;
?>

<?php /* The configured currency, as data rather than as generated JavaScript.
         reports.js reads it here so a chart tooltip cannot end up quoting a
         different currency from the receipt for the same money. */ ?>
<div data-currency-symbol="<?= sanitize(currencySymbol()) ?>" hidden></div>

<?php require __DIR__ . '/_toolbar.php'; ?>
<?php require __DIR__ . '/_tabs.php'; ?>

<?php /* The report itself. aria-live is deliberately absent: each tab is a
         full page load, so the browser and the screen reader already handle
         the change of context — announcing it again would be a second,
         competing narration of the same event. */ ?>
<section class="report" aria-labelledby="report-heading">
    <div class="report__intro">
        <h2 class="report__heading" id="report-heading">
            <i class="bi <?= sanitize($meta['icon']) ?>" aria-hidden="true"></i>
            <?= sanitize($meta['label']) ?>
        </h2>
        <p class="report__blurb"><?= sanitize($meta['blurb']) ?></p>
    </div>

    <?php
    /* Only a name from the controller's own allowlist can reach this, so the
       path cannot be steered from the request. The file check is for the
       developer who adds a tab to TABS and forgets to add its body. */
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
</section>

<?php if ($GLOBALS['reportHasChart']): ?>
    <?php /* Ahead of reports.js, which scripts.php prints at the end of the
             body. Both are plain synchronous tags, so Chart.js is defined by
             the time the workspace script looks for it — and if the vendor
             file is missing from a fresh clone, reports.js says so by simply
             not drawing: every card already carries its figures as a table. */ ?>
    <script src="<?= VENDOR_URL ?>/chartjs/chart.umd.min.js"></script>
<?php endif ?>
