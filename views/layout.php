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
<body>
<div class="app">
    <?php require VIEWS_PATH . '/components/sidebar.php'; ?>

    <div class="app__main">
        <?php require VIEWS_PATH . '/components/header.php'; ?>

        <main class="app__content">
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
<?php /* components.js first: main.js's DOMContentLoaded handler calls into it,
         and it reads lockPageScroll()/FOCUSABLE back out of main.js. Both are
         plain synchronous scripts, so every declaration is in place before
         either handler runs, whichever order the tags appear in. */ ?>
<script src="<?= JS_URL ?>/main.js"></script>
<script src="<?= JS_URL ?>/components.js"></script>
</body>
</html>
