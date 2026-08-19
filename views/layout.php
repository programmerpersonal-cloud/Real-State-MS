<?php
/**
 * Master Application Layout
 * Expects in scope: $viewFile (absolute or BASE_PATH-relative), $currentUser, $role
 * Optional: $pageTitle, $pageSubtitle, $breadcrumbs, $actionButton, $actionButtons
 */
$resolvedViewFile = $viewFile ?? null;
if ($resolvedViewFile && !file_exists($resolvedViewFile) && file_exists(BASE_PATH . $resolvedViewFile)) {
    $resolvedViewFile = BASE_PATH . $resolvedViewFile;
}

// Render the inner view first so its $pageTitle / $breadcrumbs / $actionButtons
// are visible to header.php and page_header.php.
$__viewOutput = '';
if ($resolvedViewFile && file_exists($resolvedViewFile)) {
    ob_start();
    require $resolvedViewFile;
    $__viewOutput = ob_get_clean();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= sanitize($pageTitle ?? 'Dashboard') ?> — <?= sanitize(companyName()) ?></title>
    <link rel="preload" href="<?= VENDOR_URL ?>/inter/fonts/inter-latin.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="preload" href="<?= VENDOR_URL ?>/raleway/fonts/raleway-latin.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="preload" href="<?= VENDOR_URL ?>/bootstrap-icons/fonts/bootstrap-icons.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="stylesheet" href="<?= VENDOR_URL ?>/bootstrap-icons/bootstrap-icons.min.css">
    <?php $bundle = 'app'; require VIEWS_PATH . '/components/styles.php'; ?>
</head>
<?php
/* Fetched once, here, because both the rail and the topbar want it: the bell
   badge and its panel in the header, and the count beside the Notifications
   row in the sidebar. One call, one query — asking twice would double a cost
   every page in the application pays. */
$notif = notificationBell();
?>
<body>
<?php /* Applied before first paint so a collapsed rail does not render wide and
         then snap. The class is written by the same key the toggle stores, and
         the script is inline and synchronous for that reason. */ ?>
<script>
(function () {
  try {
    var d = document.documentElement;
    if (localStorage.getItem('saxane.rail') === 'collapsed') {
      d.classList.add('rail-collapsed');
    }
    // Same reason as the rail: a compact preference must not render at
    // comfortable and then reflow every table on the page.
    if (localStorage.getItem('saxane.density') === 'compact') {
      d.setAttribute('data-density', 'compact');
    }
  } catch (e) { /* private mode: both simply start at their defaults */ }
})();
</script>
<?php /* First tab stop on every page. Without it a keyboard user pays for the
         whole rail before reaching the content, on every navigation. */ ?>
<a class="skip-link" href="#main">Skip to main content</a>

<?php /* The announcement region is rendered here rather than created by the
         first toast that needs it. A live region has to be in the document
         before content is put into it, or the screen reader has nothing to
         observe and the first message of the session is read by nobody. */ ?>
<div id="toastRegion" class="toast-region" role="status" aria-live="polite"></div>

<div class="app">
    <?php require VIEWS_PATH . '/components/sidebar.php'; ?>

    <div class="app__main">
        <?php require VIEWS_PATH . '/components/header.php'; ?>

        <main class="app__content" id="main" tabindex="-1">
            <?= renderFlash() ?>

            <?php if (!empty($pageTitle) || !empty($actionButton) || !empty($actionButtons)): ?>
                <?php require VIEWS_PATH . '/components/page_header.php'; ?>
            <?php endif; ?>

            <?php if ($__viewOutput !== ''): ?>
                <?= $__viewOutput ?>
            <?php else: ?>
                <div class="empty-state">
                    <div class="empty-state__icon"><i class="bi bi-hammer"></i></div>
                    <div class="empty-state__title">Coming Soon</div>
                    <div class="empty-state__desc">This module is under development.</div>
                </div>
            <?php endif; ?>
        </main>

        <?php require VIEWS_PATH . '/components/footer.php'; ?>
    </div>
</div>
<?php require VIEWS_PATH . '/components/scripts.php'; ?>
</body>
</html>
