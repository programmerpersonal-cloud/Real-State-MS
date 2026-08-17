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
<?php
$formData = $_SESSION['form_data'] ?? [];
unset($_SESSION['form_data']);
$errs = $formErrors ?? [];

$err  = static fn(string $f): string => uiFieldError($errs, $f);
$bad  = static fn(string $f): string => uiInvalidClass($errs, $f);
$aria = static fn(string $f, string $hint = ''): string => uiFieldAria($errs, $f, $hint);
?>
<a class="skip-link" href="#register-form">Skip to the form</a>
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

            <form method="POST" action="<?= APP_URL ?>/index.php?page=register" id="register-form" data-validate>
                <?= csrfField() ?>

                <?php require VIEWS_PATH . '/components/ui/error_summary.php'; ?>

                <div class="form-group">
                    <label class="form-label" for="full_name">Full name</label>
                    <input type="text" class="form-control<?= $bad('full_name') ?>" id="full_name" name="full_name"
                           value="<?= sanitize($formData['full_name'] ?? '') ?>"
                           autocomplete="name" placeholder="As it should appear on your account"
                           required<?= $aria('full_name') ?>>
                    <?= $err('full_name') ?>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label" for="email">Email</label>
                        <input type="email" class="form-control<?= $bad('email') ?>" id="email" name="email"
                               value="<?= sanitize($formData['email'] ?? '') ?>"
                               autocomplete="email" placeholder="you@example.com" required<?= $aria('email') ?>>
                        <?= $err('email') ?>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="phone">Phone</label>
                        <input type="tel" class="form-control<?= $bad('phone') ?>" id="phone" name="phone"
                               value="<?= sanitize($formData['phone'] ?? '') ?>"
                               autocomplete="tel" placeholder="Optional"<?= $aria('phone') ?>>
                        <?= $err('phone') ?>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label" for="username">Username</label>
                    <input type="text" class="form-control<?= $bad('username') ?>" id="username" name="username"
                           value="<?= sanitize($formData['username'] ?? '') ?>"
                           autocomplete="username" placeholder="johndoe" required<?= $aria('username', 'username-hint') ?>>
                    <?= $err('username') ?>
                    <div class="form-hint" id="username-hint">You can sign in with either this or your email.</div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label" for="password">Password</label>
                        <?php /* The reveal toggle and the autocomplete hint are both
                                 there so a password manager can fill this and a person
                                 can check what they typed — a field that allows
                                 neither pushes people towards weaker passwords. */ ?>
                        <div class="input-reveal" data-reveal>
                            <input type="password" class="form-control<?= $bad('password') ?>" id="password" name="password"
                                   autocomplete="new-password" placeholder="At least 8 characters"
                                   required<?= $aria('password') ?>>
                            <button type="button" class="input-reveal__btn" data-reveal-toggle
                                    aria-label="Show password" aria-pressed="false">
                                <i class="bi bi-eye" aria-hidden="true"></i>
                            </button>
                        </div>
                        <?= $err('password') ?>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="confirm_password">Confirm</label>
                        <input type="password" class="form-control<?= $bad('confirm_password') ?>"
                               id="confirm_password" name="confirm_password"
                               autocomplete="new-password" placeholder="Repeat it" required<?= $aria('confirm_password') ?>>
                        <?= $err('confirm_password') ?>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label" for="role_id">I am a</label>
                    <?php /* Built from the roles registration actually accepts, so
                             the list offered and the list allowed are one list. */ ?>
                    <select class="form-control<?= $bad('role_id') ?>" id="role_id" name="role_id"
                            <?= $aria('role_id', 'role-hint') ?>>
                        <?php foreach (($roleOptions ?? []) as $rid => $label): ?>
                            <option value="<?= (int) $rid ?>"
                                <?= (int) ($formData['role_id'] ?? 0) === (int) $rid ? 'selected' : '' ?>>
                                <?= sanitize($label) ?>
                            </option>
                        <?php endforeach ?>
                    </select>
                    <?= $err('role_id') ?>
                    <div class="form-hint" id="role-hint">
                        Staff accounts are created by an administrator, not from this form.
                    </div>
                </div>

                <button type="submit" class="btn btn--primary btn--block btn--lg mt-2">
                    <i class="bi bi-person-plus" aria-hidden="true"></i> Create account
                </button>
            </form>

            <p class="auth__footer-text">
                Already have an account? <a href="<?= APP_URL ?>/index.php?page=login">Sign in</a>
            </p>
        </div>
    </div>
</div>
<script src="<?= JS_URL ?>/main.js"></script>
<script src="<?= JS_URL ?>/components.js"></script>
</body>
</html>
