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
            <label class="form-label" for="login">Email or username</label>
            <?php /* autocomplete lets a password manager fill this. Without it
                     people retype credentials by hand, which is how short and
                     reused passwords happen. */ ?>
            <input type="text" class="form-control" id="login" name="login"
                   autocomplete="username" placeholder="you@example.com" required autofocus>
        </div>

        <div class="form-group">
            <label class="form-label" for="password">Password</label>
            <div class="input-reveal" data-reveal>
                <input type="password" class="form-control" id="password" name="password"
                       autocomplete="current-password" placeholder="••••••••" required>
                <button type="button" class="input-reveal__btn" data-reveal-toggle
                        aria-label="Show password" aria-pressed="false">
                    <i class="bi bi-eye" aria-hidden="true"></i>
                </button>
            </div>
        </div>

        <div class="auth__form-row">
            <label class="auth__remember">
                <input type="checkbox" name="remember"> Remember me
            </label>
            <?php /* There is no self-service reset in this system — passwords are
                     issued by an administrator from Users & Roles. A link to
                     nowhere was worse than saying so. */ ?>
            <span class="text-subtle">Lost your password? Ask an administrator.</span>
        </div>

        <button type="submit" class="btn btn--primary btn--block btn--lg">
            Sign in <i class="bi bi-arrow-right" aria-hidden="true"></i>
        </button>
    </form>

    <p class="auth__footer-text">
        Don't have an account? <a href="<?= APP_URL ?>/index.php?page=register">Create one</a>
    </p>
    <p class="auth__footer-text auth__footer-text--tight">
        <a href="<?= APP_URL ?>/index.php?page=home">
            <i class="bi bi-arrow-left" aria-hidden="true"></i> Back to <?= sanitize(companyName()) ?>
        </a>
    </p>
</div>
<script src="<?= JS_URL ?>/main.js"></script>
<script src="<?= JS_URL ?>/components.js"></script>
</body>
</html>
