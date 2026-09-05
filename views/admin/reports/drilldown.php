<?php
/**
 * The drill-down as a page of its own.
 *
 * The drawer is the usual way in, but it is not the only one. This is what a
 * reader gets when they open the link directly, refresh it, follow it from an
 * email, or browse with scripting off — and it is why every drill-down in the
 * workspace is a real URL rather than a piece of page state. A figure
 * somebody is questioning is precisely the thing they want to send to whoever
 * has to answer for it, and "click revenue, then click the third bar" is not
 * something you can put in a message.
 *
 * The same partial renders here and in the drawer, so the two can never drift
 * apart. What this file adds is the furniture the drawer already has around
 * it: the tab strip, the masthead saying which report and which period, and a
 * way back.
 *
 * Vars from ReportController::drill().
 */
$pageStyles   = ['pages/reports'];
$extraScripts = ['reports'];

$activeTab = $reportTab ?? 'overview';
$GLOBALS['reportHasChart'] = false;
?>

<?php require __DIR__ . '/_tabs.php'; ?>

<section class="report report--drill" aria-labelledby="drillTitle">
    <?php require __DIR__ . '/_report_header.php'; ?>

    <?php /* No toolbar. The period and the filters are the ones the drill-down
             was opened under and changing them here would silently produce a
             different figure from the one being questioned — so the controls
             stay on the report, and this page carries a way back to it. */ ?>

    <div class="report__body">
        <div class="drill-page">
            <?php require __DIR__ . '/_drilldown.php'; ?>
        </div>
    </div>
</section>
