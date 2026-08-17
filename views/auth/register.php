<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Account — <?= sanitize(companyName()) ?></title>
    <link rel="preload" href="<?= VENDOR_URL ?>/inter/fonts/inter-latin.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="preload" href="<?= VENDOR_URL ?>/raleway/fonts/raleway-latin.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="preload" href="<?= VENDOR_URL ?>/bootstrap-icons/fonts/bootstrap-icons.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="stylesheet" href="<?= VENDOR_URL ?>/bootstrap-icons/bootstrap-icons.min.css">
    <?php $bundle = 'auth'; require VIEWS_PATH . '/components/styles.php'; ?>
</head>
<body>
<?php $formData = $_SESSION['form_data'] ?? []; unset($_SESSION['form_data']); ?>
<div class="auth">
    <div class="auth__brand">
        <div class="auth__brand-logo"><i class="bi bi-buildings-fill"></i></div>
        <h1 class="auth__brand-title">Join <?= sanitize(companyName()) ?></h1>
        <p class="auth__brand-desc">Create your account and start managing your real estate operations professionally.</p>
    </div>
    <div class="auth__form-side">
        <div class="auth__form-container">
            <h2 class="auth__form-title">Create account</h2>
            <p class="auth__form-subtitle">Fill in your details to get started</p>

            <?= renderFlash() ?>

            <form method="POST" action="<?= APP_URL ?>/index.php?page=register" data-validate>
                <?= csrfField() ?>

                <div class="form-group">
                    <label class="form-label" for="full_name">Full Name</label>
                    <input type="text" class="form-control" id="full_name" name="full_name" value="<?= sanitize($formData['full_name'] ?? '') ?>" placeholder="enter your full name" required>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label" for="email">Email</label>
                        <input type="email" class="form-control" id="email" name="email" value="<?= sanitize($formData['email'] ?? '') ?>" placeholder="enter your email" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="phone">Phone</label>
                        <input type="tel" class="form-control" id="phone" name="phone" value="<?= sanitize($formData['phone'] ?? '') ?>" placeholder="enter your phone number">
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label" for="username">Username</label>
                    <input type="text" class="form-control" id="username" name="username" value="<?= sanitize($formData['username'] ?? '') ?>" placeholder="johndoe" required>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label" for="password">Password</label>
                        <input type="password" class="form-control" id="password" name="password" placeholder="Min 8 characters" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="confirm_password">Confirm</label>
                        <input type="password" class="form-control" id="confirm_password" name="confirm_password" placeholder="Repeat password" required>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label" for="role_id">I am a</label>
                    <select class="form-control" id="role_id" name="role_id">
                        <option value="3">Customer / Tenant</option>
                        <option value="4">Property Owner</option>
                    </select>
                    <div class="form-hint">Agent and Admin accounts are created by administrators.</div>
                </div>

                <button type="submit" class="btn btn--primary btn--block btn--lg mt-2">
                    <i class="bi bi-person-plus"></i> Create Account
                </button>
            </form>

            <p class="auth__footer-text">
                Already have an account? <a href="<?= APP_URL ?>/index.php?page=login">Sign in</a>
            </p>
        </div>
    </div>
</div>
<script src="<?= JS_URL ?>/main.js"></script>
</body>
</html>
