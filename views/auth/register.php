<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Account — <?= sanitize(companyName()) ?></title>
    <meta name="description" content="Create an account on the <?= sanitize(companyName()) ?> management system">
    <link rel="preload" href="<?= VENDOR_URL ?>/inter/fonts/inter-latin.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="preload" href="<?= VENDOR_URL ?>/raleway/fonts/raleway-latin.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="preload" href="<?= VENDOR_URL ?>/bootstrap-icons/fonts/bootstrap-icons.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="stylesheet" href="<?= VENDOR_URL ?>/bootstrap-icons/bootstrap-icons.min.css">
    <?php /* The first frame of the property panel is the largest thing this
             page paints, and it is discovered late — the browser only meets
             it after the stylesheets. Preloading just the first one moves it
             forward without pulling the rest of the slideshow into the
             critical path; the others are lazy and arrive afterwards. */ ?>
    <?php if (!empty($showcase[0]['image'])): ?>
    <link rel="preload" as="image" href="<?= sanitize($showcase[0]['image']) ?>" fetchpriority="high">
    <?php endif ?>
    <?php $bundle = 'auth'; require VIEWS_PATH . '/components/styles.php'; ?>
</head>
<body class="auth-page">
<?php
/* Both panels are rendered here; this route just decides which one is
   open. See views/auth/_shell.php. */
$authMode = 'register';
require VIEWS_PATH . '/auth/_shell.php';
?>
<?php $extraScripts = ['auth']; require VIEWS_PATH . '/components/scripts.php'; ?>
</body>
</html>
