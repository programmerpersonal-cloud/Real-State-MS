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
    <?php $bundle = 'auth'; require VIEWS_PATH . '/components/styles.php'; ?>
</head>
<body class="auth-page">
<a class="skip-link" href="#main">Skip to main content</a>
<div class="auth-card" id="main">
    <div class="auth-card__logo"><i class="bi bi-buildings-fill"></i></div>
    <div class="auth-card__brand"><?= sanitize(companyName()) ?></div>

    <h1 class="auth-card__title">Sign in</h1>
    <p class="auth-card__subtitle">Enter your credentials to access the dashboard.</p>

    <?= renderFlash() ?>

    <form method="POST" action="<?= APP_URL ?>/index.php?page=login" data-validate>
        <?= csrfField() ?>

        <div class="form-group">
            <label class="form-label" for="login">Email or Username</label>
            <input type="text" class="form-control" id="login" name="login" placeholder="you@example.com" required autofocus>
        </div>

        <div class="form-group">
            <label class="form-label" for="password">Password</label>
            <input type="password" class="form-control" id="password" name="password" placeholder="••••••••" required>
        </div>

        <div class="auth__form-row">
            <label class="auth__remember">
                <input type="checkbox" name="remember"> Remember me
            </label>
            <a href="#">Forgot password?</a>
        </div>

        <button type="submit" class="btn btn--primary btn--block btn--lg">
            Sign In <i class="bi bi-arrow-right"></i>
        </button>
    </form>

    <p class="auth__footer-text">
        Don't have an account? <a href="<?= APP_URL ?>/index.php?page=register">Create one</a>
    </p>
    <p class="auth__footer-text" style="margin-top:10px">
        <a href="<?= APP_URL ?>/index.php?page=home">
            <i class="bi bi-arrow-left" aria-hidden="true"></i> Back to <?= sanitize(companyName()) ?>
        </a>
    </p>
</div>
<script src="<?= JS_URL ?>/main.js"></script>
<script src="<?= JS_URL ?>/components.js"></script>
</body>
</html>
