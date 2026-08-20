<?php
/**
 * Footer — three slots.
 *
 * Was a single centred line repeating the company name the rail already shows.
 * It is the last thing on every page, so it may as well answer the questions
 * that get asked of a support ticket: which product, which build, and where to
 * go when something is wrong.
 *
 * APP_ENV is only surfaced when it is not production. On a live install the
 * slot stays empty rather than printing "production" at everybody all day.
 */
$env = defined('APP_ENV') ? (string) APP_ENV : '';
$isProd = $env === '' || strtolower($env) === 'production' || strtolower($env) === 'prod';
?>
<footer class="app__footer">
    <div class="app__footer-slot app__footer-slot--start">
        &copy; <?= date('Y') ?> <?= sanitize(companyName()) ?>
    </div>

    <div class="app__footer-slot app__footer-slot--center">
        <?php if (canAccessPage('inquiries')): ?>
            <a href="<?= APP_URL ?>/index.php?page=inquiries">Support</a>
        <?php endif ?>
    </div>

    <div class="app__footer-slot app__footer-slot--end">
        <?php if (!$isProd): ?>
            <span class="app__footer-env"><?= sanitize(strtoupper($env)) ?></span>
        <?php endif ?>
        <span class="app__footer-version">v<?= sanitize(APP_VERSION) ?></span>
    </div>
</footer>
