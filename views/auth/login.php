<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign In — <?= sanitize(companyName()) ?></title>
    <meta name="description" content="Sign in to the <?= sanitize(companyName()) ?> management system">
    <link rel="preload" href="<?= VENDOR_URL ?>/inter/fonts/inter-latin.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="preload" href="<?= VENDOR_URL ?>/raleway/fonts/raleway-latin.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="preload" href="<?= VENDOR_URL ?>/bootstrap-icons/fonts/bootstrap-icons.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="stylesheet" href="<?= VENDOR_URL ?>/bootstrap-icons/bootstrap-icons.min.css">
    <?php /* No image preload. The brand panel is a gradient now, so the
             largest thing this page paints is drawn by the stylesheet that
             is already in the critical path. */ ?>
    <?php $bundle = 'auth'; require VIEWS_PATH . '/components/styles.php'; ?>
</head>
<body class="auth-page">
<?php
/* Both panels are rendered here; this route just decides which one is
   open. See views/auth/_shell.php. */
$authMode = 'login';
require VIEWS_PATH . '/auth/_shell.php';
?>
<?php $extraScripts = ['auth']; require VIEWS_PATH . '/components/scripts.php'; ?>
</body>
</html>
