<?php
/**
 * The authentication screen: both forms, one card.
 *
 * login.php and register.php are still two routes and two POST
 * endpoints — nothing about the controller, the CSRF token, the
 * validation or the redirect-after-post has changed. What changed is
 * that each route renders *both* panels and marks one active, so the
 * switch between them can be an animation rather than a page load.
 *
 * That is also why the switch controls are real links to the other
 * page: with scripting off they navigate, and the server renders the
 * other panel active. auth.js intercepts the click and slides instead.
 *
 * Expects:
 *   $authMode    'login' | 'register'  which panel opens active
 *   $roleOptions array<int,string>     roles registration will accept
 *   $formErrors  array                 per-field errors from a reject
 */
$authMode = ($authMode ?? 'login') === 'register' ? 'register' : 'login';

/* The reject path stores what was typed so the form comes back filled.
   Read once and cleared, exactly as register.php did before. */
$formData = $_SESSION['form_data'] ?? [];
unset($_SESSION['form_data']);
$errs = $formErrors ?? [];

$err  = static fn(string $f): string => uiFieldError($errs, $f);
$bad  = static fn(string $f): string => uiInvalidClass($errs, $f);
$aria = static fn(string $f, string $hint = ''): string => uiFieldAria($errs, $f, $hint);

/* Flash is consumed once and printed into the panel that is open.
   Every redirect that sets one lands on the matching route, so the
   message and the panel it belongs to always arrive together. */
$flash = renderFlash();

$loginUrl    = APP_URL . '/index.php?page=login';
$registerUrl = APP_URL . '/index.php?page=register';
$company     = sanitize(companyName());
?>
<a class="skip-link" href="#main">Skip to main content</a>

<div class="auth-shell" id="main" data-auth-shell data-auth-mode="<?= $authMode ?>"
     data-title-login="<?= sanitize('Sign In — ' . companyName()) ?>"
     data-title-register="<?= sanitize('Create Account — ' . companyName()) ?>">
    <div class="auth-shell__inner">

        <div class="auth-shell__forms" data-auth-forms>

            <!-- ─── Sign in ─────────────────────────────────── -->
            <section class="auth-panel<?= $authMode === 'login' ? '' : ' is-hidden' ?>"
                     id="auth-panel-login" data-auth-panel="login"
                     aria-labelledby="auth-title-login"
                     <?= $authMode === 'login' ? '' : 'inert aria-hidden="true"' ?>>

                <div class="auth-panel__brand">
                    <span class="auth-panel__logo"><i class="bi bi-buildings-fill" aria-hidden="true"></i></span>
                    <span class="auth-panel__name"><?= $company ?></span>
                </div>

                <h1 class="auth-panel__title" id="auth-title-login" data-auth-heading tabindex="-1">Login</h1>
                <p class="auth-panel__subtitle">Enter your credentials to reach your dashboard.</p>

                <?= $authMode === 'login' ? $flash : '' ?>

                <form method="POST" action="<?= $loginUrl ?>" data-validate>
                    <?= csrfField() ?>

                    <div class="form-group">
                        <label class="form-label" for="login-identifier">Email or username</label>
                        <?php /* autocomplete lets a password manager fill this. Without it
                                 people retype credentials by hand, which is how short and
                                 reused passwords happen. */ ?>
                        <input type="text" class="form-control" id="login-identifier" name="login"
                               autocomplete="username" placeholder="you@example.com" required
                               <?= $authMode === 'login' ? 'autofocus' : '' ?>>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="login-password">Password</label>
                        <div class="input-reveal" data-reveal>
                            <input type="password" class="form-control" id="login-password" name="password"
                                   autocomplete="current-password" placeholder="••••••••" required>
                            <button type="button" class="input-reveal__btn" data-reveal-toggle
                                    aria-label="Show password" aria-pressed="false">
                                <i class="bi bi-eye" aria-hidden="true"></i>
                            </button>
                        </div>
                    </div>

                    <div class="auth-panel__row">
                        <label class="auth-panel__remember">
                            <input type="checkbox" name="remember"> Remember me
                        </label>
                        <?php /* There is no self-service reset in this system — passwords are
                                 issued by an administrator from Users & Roles. A link to
                                 nowhere was worse than saying so. */ ?>
                        <span class="auth-panel__note">Lost your password? Ask an administrator.</span>
                    </div>

                    <button type="submit" class="btn btn--primary btn--block btn--lg auth-panel__submit">
                        Login <i class="bi bi-arrow-right" aria-hidden="true"></i>
                    </button>
                </form>

                <p class="auth-panel__switch">
                    Don't have an account?
                    <a class="auth-switch" href="<?= $registerUrl ?>"
                       data-auth-switch="register" aria-controls="auth-panel-register">Register</a>
                </p>

                <p class="auth-panel__foot">
                    <a href="<?= APP_URL ?>/index.php?page=home">
                        <i class="bi bi-arrow-left" aria-hidden="true"></i> Back to <?= $company ?>
                    </a>
                    <span>Secure sign-in</span>
                </p>
            </section>

            <!-- ─── Create account ──────────────────────────── -->
            <section class="auth-panel<?= $authMode === 'register' ? '' : ' is-hidden' ?>"
                     id="auth-panel-register" data-auth-panel="register"
                     aria-labelledby="auth-title-register"
                     <?= $authMode === 'register' ? '' : 'inert aria-hidden="true"' ?>>

                <div class="auth-panel__brand">
                    <span class="auth-panel__logo"><i class="bi bi-buildings-fill" aria-hidden="true"></i></span>
                    <span class="auth-panel__name"><?= $company ?></span>
                </div>

                <h1 class="auth-panel__title" id="auth-title-register" data-auth-heading tabindex="-1">Registration</h1>
                <p class="auth-panel__subtitle">Fill in your details and start managing your properties.</p>

                <?= $authMode === 'register' ? $flash : '' ?>

                <form method="POST" action="<?= $registerUrl ?>" id="register-form" data-validate>
                    <?= csrfField() ?>

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
                        <?php $phoneField = ['name' => 'phone', 'id' => 'phone', 'label' => 'Phone',
                                            'value' => $formData['phone'] ?? ''];
                              require VIEWS_PATH . '/components/ui/phone_field.php'; ?>
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

                    <button type="submit" class="btn btn--primary btn--block btn--lg auth-panel__submit">
                        <i class="bi bi-person-plus" aria-hidden="true"></i> Register
                    </button>
                </form>

                <p class="auth-panel__switch">
                    Already have an account?
                    <a class="auth-switch" href="<?= $loginUrl ?>"
                       data-auth-switch="login" aria-controls="auth-panel-login">Login</a>
                </p>

                <p class="auth-panel__foot">
                    <a href="<?= APP_URL ?>/index.php?page=home">
                        <i class="bi bi-arrow-left" aria-hidden="true"></i> Back to <?= $company ?>
                    </a>
                    <span>Takes a minute</span>
                </p>
            </section>
        </div>

        <!-- ─── The panel that slides ───────────────────────── -->
        <aside class="auth-aside">
            <div class="auth-aside__face<?= $authMode === 'login' ? '' : ' is-hidden' ?>"
                 data-auth-face="login" <?= $authMode === 'login' ? '' : 'inert aria-hidden="true"' ?>>
                <h2 class="auth-aside__title">Hello, Welcome!</h2>
                <p class="auth-aside__text">Don't have an account? Create one and manage your portfolio in minutes.</p>
                <ul class="auth-aside__list">
                    <li><i class="bi bi-check-lg" aria-hidden="true"></i> Listings, tenants and leases in one place</li>
                    <li><i class="bi bi-check-lg" aria-hidden="true"></i> Payments and receipts tracked automatically</li>
                    <li><i class="bi bi-check-lg" aria-hidden="true"></i> Reports your accountant will accept</li>
                </ul>
                <a class="auth-aside__btn" href="<?= $registerUrl ?>"
                   data-auth-switch="register" aria-controls="auth-panel-register">Register</a>
            </div>

            <div class="auth-aside__face<?= $authMode === 'register' ? '' : ' is-hidden' ?>"
                 data-auth-face="register" <?= $authMode === 'register' ? '' : 'inert aria-hidden="true"' ?>>
                <h2 class="auth-aside__title">Welcome Back!</h2>
                <p class="auth-aside__text">Already have an account? Sign in and pick up where you left off.</p>
                <ul class="auth-aside__list">
                    <li><i class="bi bi-shield-check" aria-hidden="true"></i> Your session stays private</li>
                    <li><i class="bi bi-lightning-charge" aria-hidden="true"></i> Straight back to your dashboard</li>
                    <li><i class="bi bi-clock-history" aria-hidden="true"></i> Everything exactly as you left it</li>
                </ul>
                <a class="auth-aside__btn" href="<?= $loginUrl ?>"
                   data-auth-switch="login" aria-controls="auth-panel-login">Login</a>
            </div>
        </aside>

    </div>
</div>
