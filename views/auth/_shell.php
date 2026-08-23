<?php
/**
 * The authentication screen: both forms, one full-viewport interface.
 *
 * login.php and register.php are still two routes and two POST endpoints —
 * nothing about the controller, the CSRF token, the field names, the
 * validation or the redirect-after-post has changed. What changed is that
 * each route renders *both* panels and marks one active, so the switch
 * between them can be an animation rather than a page load.
 *
 * That is also why the switch controls are real links to the other page:
 * with scripting off they navigate, and the server renders the other panel
 * active. auth.js intercepts the click and cross-fades instead.
 *
 * The left half is a photographic panel rather than a flat brand block. The
 * pictures are real listings supplied by AuthController::showcaseProperties()
 * — cover photos where one has been uploaded, seeded stock shots where none
 * has — so the screen shows the product rather than describing it. It is
 * decoration in the accessibility sense: every image is presentational, and
 * the property details behind them are reachable through the labelled dot
 * controls rather than by watching the panel change.
 *
 * Expects:
 *   $authMode    'login' | 'register'  which panel opens active
 *   $roleOptions array<int,string>     roles registration will accept
 *   $formErrors  array                 per-field errors from a reject
 *   $showcase    array                 slides: image, title, location, badge, price
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
$homeUrl     = APP_URL . '/index.php?page=home';
$contactUrl  = APP_URL . '/index.php?page=contact';
$company     = sanitize(companyName());

$slides = array_values($showcase ?? []);
?>
<?php /* Straight to the form. "Main content" here would land a keyboard
         user at the top of the property panel, in front of a wordmark, a
         pause control and one dot per photograph — nine tab stops before
         the first field. */ ?>
<a class="skip-link" href="#auth-form">Skip to the form</a>

<div class="auth-shell" id="main" data-auth-shell data-auth-mode="<?= $authMode ?>"
     data-title-login="<?= sanitize('Sign In — ' . companyName()) ?>"
     data-title-register="<?= sanitize('Create Account — ' . companyName()) ?>">

    <!-- ─── The photographic panel ───────────────────────────── -->
    <?php /* Five seconds a slide, with a short cross-fade so the pictures
             replace each other rather than cut, and a slow zoom under that
             which runs the whole time — neighbouring photographs drift in
             opposite directions, so the panel never looks like it is doing
             one thing on a loop. The interval is data rather than a constant
             in the script, so it can be changed without touching auth.js.

             There is no pause button. What stops the rotation instead is
             hovering the panel, moving keyboard focus into it, choosing a
             property from the dots, switching to another tab, and
             prefers-reduced-motion — which holds it on the first frame and
             never starts it at all. */ ?>
    <section class="auth-showcase" data-slideshow data-slideshow-interval="5000"
             aria-label="<?= $company ?> featured properties">

        <div class="auth-showcase__stage" aria-hidden="true">
            <?php foreach ($slides as $i => $slide): ?>
                <div class="auth-showcase__slide<?= $i === 0 ? ' is-active' : '' ?>" data-slide="<?= $i ?>">
                    <?php /* The first frame is the one blocking the panel's
                             appearance, so it is fetched eagerly and at high
                             priority; the rest are lazy and arrive during the
                             first few seconds. */ ?>
                    <img src="<?= sanitize($slide['image']) ?>" alt=""
                         decoding="async"
                         <?= $i === 0 ? 'fetchpriority="high"' : 'loading="lazy"' ?>>
                </div>
            <?php endforeach ?>
        </div>
        <div class="auth-showcase__veil" aria-hidden="true"></div>

        <div class="auth-showcase__body">

            <div class="auth-showcase__top">
                <a class="auth-showcase__wordmark" href="<?= $homeUrl ?>">
                    <i class="bi bi-buildings-fill" aria-hidden="true"></i>
                    <span><?= $company ?></span>
                </a>
            </div>

            <div class="auth-showcase__faces">
                <div class="auth-showcase__face<?= $authMode === 'login' ? '' : ' is-hidden' ?>"
                     data-auth-face="login" <?= $authMode === 'login' ? '' : 'inert aria-hidden="true"' ?>>
                    <p class="auth-showcase__title">Welcome Back!</p>
                    <p class="auth-showcase__text">
                        Sign in to manage your properties, clients, rentals, maintenance
                        and everyday real-estate operations.
                    </p>
                    <ul class="auth-showcase__list">
                        <li><i class="bi bi-check-lg" aria-hidden="true"></i> Listings, tenants and leases in one place</li>
                        <li><i class="bi bi-check-lg" aria-hidden="true"></i> Payments and receipts tracked automatically</li>
                        <li><i class="bi bi-check-lg" aria-hidden="true"></i> Reports your accountant will accept</li>
                    </ul>
                    <a class="auth-showcase__btn" href="<?= $registerUrl ?>"
                       data-auth-switch="register" aria-controls="auth-panel-register">
                        Create an account <i class="bi bi-arrow-right" aria-hidden="true"></i>
                    </a>
                </div>

                <div class="auth-showcase__face<?= $authMode === 'register' ? '' : ' is-hidden' ?>"
                     data-auth-face="register" <?= $authMode === 'register' ? '' : 'inert aria-hidden="true"' ?>>
                    <p class="auth-showcase__title">Welcome to <?= $company ?></p>
                    <p class="auth-showcase__text">
                        Create your account and start managing your real-estate
                        journey professionally.
                    </p>
                    <ul class="auth-showcase__list">
                        <li><i class="bi bi-shield-check" aria-hidden="true"></i> Your session stays private</li>
                        <li><i class="bi bi-lightning-charge" aria-hidden="true"></i> Set up in a couple of minutes</li>
                        <li><i class="bi bi-graph-up-arrow" aria-hidden="true"></i> Every listing you add, tracked from day one</li>
                    </ul>
                    <a class="auth-showcase__btn" href="<?= $loginUrl ?>"
                       data-auth-switch="login" aria-controls="auth-panel-login">
                        I already have an account <i class="bi bi-arrow-right" aria-hidden="true"></i>
                    </a>
                </div>
            </div>

            <?php if ($slides): ?>
                <div class="auth-showcase__foot">
                    <?php /* Not a live region: a caption that re-announces
                             itself every five seconds interrupts a screen
                             reader for as long as the page is open. The same
                             information is on the dot controls below, where it
                             is read on demand. */ ?>
                    <div class="auth-showcase__meta" data-slide-meta>
                        <?php foreach ($slides as $i => $slide): ?>
                            <?php if (trim((string) $slide['title']) === '') continue ?>
                            <div class="auth-showcase__card<?= $i === 0 ? ' is-active' : '' ?>"
                                 data-slide-card="<?= $i ?>" aria-hidden="true">
                                <p class="auth-showcase__ptitle"><?= sanitize($slide['title']) ?></p>
                                <p class="auth-showcase__pmeta">
                                    <?php if ($slide['location'] !== ''): ?>
                                        <span><i class="bi bi-geo-alt" aria-hidden="true"></i> <?= sanitize($slide['location']) ?></span>
                                    <?php endif ?>
                                    <?php if ($slide['badge'] !== ''): ?>
                                        <span class="auth-showcase__badge"><?= sanitize($slide['badge']) ?></span>
                                    <?php endif ?>
                                    <?php if ($slide['price'] !== ''): ?>
                                        <span class="auth-showcase__price"><?= sanitize($slide['price']) ?></span>
                                    <?php endif ?>
                                </p>
                            </div>
                        <?php endforeach ?>
                    </div>

                    <?php if (count($slides) > 1): ?>
                        <div class="auth-showcase__dots" role="group" aria-label="Choose a property and stop the slideshow">
                            <?php foreach ($slides as $i => $slide): ?>
                                <button type="button" class="auth-showcase__dot<?= $i === 0 ? ' is-active' : '' ?>"
                                        data-slide-to="<?= $i ?>" aria-pressed="<?= $i === 0 ? 'true' : 'false' ?>">
                                    <span class="sr-only"><?=
                                        sanitize(trim((string) $slide['title']) !== ''
                                            ? $slide['title'] . ($slide['location'] !== '' ? ', ' . $slide['location'] : '')
                                            : 'Property ' . ($i + 1))
                                    ?></span>
                                </button>
                            <?php endforeach ?>
                        </div>
                    <?php endif ?>
                </div>
            <?php endif ?>
        </div>
    </section>

    <!-- ─── The forms ────────────────────────────────────────── -->
    <div class="auth-forms" id="auth-form" tabindex="-1">
        <div class="auth-forms__inner" data-auth-forms>

            <!-- ─── Sign in ─────────────────────────────────── -->
            <section class="auth-panel<?= $authMode === 'login' ? '' : ' is-hidden' ?>"
                     id="auth-panel-login" data-auth-panel="login"
                     aria-labelledby="auth-title-login"
                     <?= $authMode === 'login' ? '' : 'inert aria-hidden="true"' ?>>

                <h1 class="auth-panel__title" id="auth-title-login" data-auth-heading tabindex="-1">Welcome back</h1>
                <p class="auth-panel__subtitle">Enter your credentials to reach your dashboard.</p>

                <?= $authMode === 'login' ? $flash : '' ?>

                <form method="POST" action="<?= $loginUrl ?>" data-validate>
                    <?= csrfField() ?>

                    <div class="form-group">
                        <label class="form-label" for="login-identifier">Email or username</label>
                        <?php /* autocomplete lets a password manager fill this. Without it
                                 people retype credentials by hand, which is how short and
                                 reused passwords happen. */ ?>
                        <div class="input-icon">
                            <i class="bi bi-person" aria-hidden="true"></i>
                            <input type="text" class="form-control" id="login-identifier" name="login"
                                   autocomplete="username" placeholder="you@example.com" required
                                   <?= $authMode === 'login' ? 'autofocus' : '' ?>>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="login-password">Password</label>
                        <div class="input-icon input-reveal" data-reveal>
                            <i class="bi bi-lock" aria-hidden="true"></i>
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
                        <?php /* There is no self-service reset in this system — passwords
                                 are issued by an administrator from Users & Roles. The link
                                 goes where the question can actually be answered rather
                                 than to a reset page that does not exist. */ ?>
                        <a class="auth-panel__link" href="<?= $contactUrl ?>">Forgot password?</a>
                    </div>

                    <button type="submit" class="btn btn--primary btn--block btn--lg auth-panel__submit">
                        <span>Sign in</span> <i class="bi bi-arrow-right" aria-hidden="true"></i>
                    </button>
                </form>

                <p class="auth-panel__switch">
                    Don't have an account?
                    <a class="auth-switch" href="<?= $registerUrl ?>"
                       data-auth-switch="register" aria-controls="auth-panel-register">Register</a>
                </p>

                <?php /* The way back and the social accounts share one row.
                         They were two stacked blocks with a heading apiece,
                         which is 50-odd pixels of chrome under a form that has
                         to finish inside the window — and neither is what
                         anyone came here to do. */ ?>
                <div class="auth-panel__foot">
                    <a class="auth-panel__back" href="<?= $homeUrl ?>">
                        <i class="bi bi-arrow-left" aria-hidden="true"></i> Back to <?= $company ?>
                    </a>
                    <?php $socialClass = 'auth-social'; require VIEWS_PATH . '/components/social_links.php'; ?>
                </div>
            </section>

            <!-- ─── Create account ──────────────────────────── -->
            <section class="auth-panel auth-panel--wide<?= $authMode === 'register' ? '' : ' is-hidden' ?>"
                     id="auth-panel-register" data-auth-panel="register"
                     aria-labelledby="auth-title-register"
                     <?= $authMode === 'register' ? '' : 'inert aria-hidden="true"' ?>>

                <h1 class="auth-panel__title" id="auth-title-register" data-auth-heading tabindex="-1">Create your account</h1>
                <p class="auth-panel__subtitle">Fill in your details and start managing your properties.</p>

                <?= $authMode === 'register' ? $flash : '' ?>

                <form method="POST" action="<?= $registerUrl ?>" id="register-form" data-validate>
                    <?= csrfField() ?>

                    <?php /* Two fields to a row rather than a stack of seven.
                             The point is not density for its own sake: the form
                             has to be visible in one screen, because a field a
                             person cannot see is a field they do not know is
                             waiting for them. The pairing follows what someone
                             is thinking about at each step — who they are, how
                             they are reached, how they sign in, how they prove
                             it — and every row collapses to one column below
                             768px, where two-up stops being readable.

                             The middle row carries three fields rather than
                             two, and on a short window it lays them out that
                             way: a laptop is wide and shallow, so the row that
                             can spend width to save height is the one that
                             does. On a tall window the third simply wraps
                             underneath, which is where it used to live. */ ?>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label" for="full_name">Full name</label>
                            <div class="input-icon">
                                <i class="bi bi-person" aria-hidden="true"></i>
                                <input type="text" class="form-control<?= $bad('full_name') ?>" id="full_name" name="full_name"
                                       value="<?= sanitize($formData['full_name'] ?? '') ?>"
                                       autocomplete="name" placeholder="Your full name"
                                       required<?= $aria('full_name') ?>>
                            </div>
                            <?= $err('full_name') ?>
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="email">Email</label>
                            <div class="input-icon">
                                <i class="bi bi-envelope" aria-hidden="true"></i>
                                <input type="email" class="form-control<?= $bad('email') ?>" id="email" name="email"
                                       value="<?= sanitize($formData['email'] ?? '') ?>"
                                       autocomplete="email" placeholder="you@example.com" required<?= $aria('email') ?>>
                            </div>
                            <?= $err('email') ?>
                        </div>
                    </div>

                    <div class="form-row auth-row--triple">
                        <?php $phoneField = ['name' => 'phone', 'id' => 'phone', 'label' => 'Phone',
                                            'value' => $formData['phone'] ?? ''];
                              require VIEWS_PATH . '/components/ui/phone_field.php'; ?>

                        <div class="form-group">
                            <label class="form-label" for="username">Username</label>
                            <div class="input-icon">
                                <i class="bi bi-at" aria-hidden="true"></i>
                                <input type="text" class="form-control<?= $bad('username') ?>" id="username" name="username"
                                       value="<?= sanitize($formData['username'] ?? '') ?>"
                                       autocomplete="username" placeholder="johndoe" required<?= $aria('username', 'username-hint') ?>>
                            </div>
                            <?= $err('username') ?>
                            <div class="form-hint" id="username-hint">Sign in with this or your email.</div>
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="role_id">I am a</label>
                            <?php /* Built from the roles registration actually accepts, so
                                     the list offered and the list allowed are one list. */ ?>
                            <div class="input-icon">
                                <i class="bi bi-person-badge" aria-hidden="true"></i>
                                <select class="form-control<?= $bad('role_id') ?>" id="role_id" name="role_id"
                                        <?= $aria('role_id', 'role-hint') ?>>
                                    <?php foreach (($roleOptions ?? []) as $rid => $label): ?>
                                        <option value="<?= (int) $rid ?>"
                                            <?= (int) ($formData['role_id'] ?? 0) === (int) $rid ? 'selected' : '' ?>>
                                            <?= sanitize($label) ?>
                                        </option>
                                    <?php endforeach ?>
                                </select>
                            </div>
                            <?= $err('role_id') ?>
                            <div class="form-hint" id="role-hint">
                                Staff accounts are created by an administrator.
                            </div>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label" for="password">Password</label>
                            <?php /* The reveal toggle and the autocomplete hint are both
                                     there so a password manager can fill this and a person
                                     can check what they typed — a field that allows
                                     neither pushes people towards weaker passwords. */ ?>
                            <div class="input-icon input-reveal" data-reveal>
                                <i class="bi bi-lock" aria-hidden="true"></i>
                                <input type="password" class="form-control<?= $bad('password') ?>" id="password" name="password"
                                       autocomplete="new-password" placeholder="At least 8 characters"
                                       data-strength-input required<?= $aria('password') ?>>
                                <button type="button" class="input-reveal__btn" data-reveal-toggle
                                        aria-label="Show password" aria-pressed="false">
                                    <i class="bi bi-eye" aria-hidden="true"></i>
                                </button>
                            </div>
                            <?= $err('password') ?>
                            <?php /* Advice, not a gate: the server's rule is still eight
                                     characters and this never blocks a submission. The
                                     wording carries the level so it does not depend on
                                     the colour of the bar. */ ?>
                            <div class="pw-strength" data-strength hidden>
                                <span class="pw-strength__track" aria-hidden="true">
                                    <span class="pw-strength__fill" data-strength-bar></span>
                                </span>
                                <span class="pw-strength__label" data-strength-label
                                      aria-live="polite"></span>
                            </div>
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="confirm_password">Confirm password</label>
                            <div class="input-icon input-reveal" data-reveal>
                                <i class="bi bi-lock-fill" aria-hidden="true"></i>
                                <input type="password" class="form-control<?= $bad('confirm_password') ?>"
                                       id="confirm_password" name="confirm_password"
                                       autocomplete="new-password" placeholder="Repeat your password" required<?= $aria('confirm_password') ?>>
                                <button type="button" class="input-reveal__btn" data-reveal-toggle
                                        aria-label="Show password" aria-pressed="false">
                                    <i class="bi bi-eye" aria-hidden="true"></i>
                                </button>
                            </div>
                            <?= $err('confirm_password') ?>
                        </div>
                    </div>

                    <p class="auth-panel__terms">
                        By creating an account you agree to our
                        <a href="<?= APP_URL ?>/index.php?page=terms">Terms of Service</a> and
                        <a href="<?= APP_URL ?>/index.php?page=privacy">Privacy Policy</a>.
                    </p>

                    <button type="submit" class="btn btn--primary btn--block btn--lg auth-panel__submit">
                        <span>Create account</span> <i class="bi bi-arrow-right" aria-hidden="true"></i>
                    </button>
                </form>

                <p class="auth-panel__switch">
                    Already have an account?
                    <a class="auth-switch" href="<?= $loginUrl ?>"
                       data-auth-switch="login" aria-controls="auth-panel-login">Login</a>
                </p>

                <?php /* The way back and the social accounts share one row.
                         They were two stacked blocks with a heading apiece,
                         which is 50-odd pixels of chrome under a form that has
                         to finish inside the window — and neither is what
                         anyone came here to do. */ ?>
                <div class="auth-panel__foot">
                    <a class="auth-panel__back" href="<?= $homeUrl ?>">
                        <i class="bi bi-arrow-left" aria-hidden="true"></i> Back to <?= $company ?>
                    </a>
                    <?php $socialClass = 'auth-social'; require VIEWS_PATH . '/components/social_links.php'; ?>
                </div>
            </section>
        </div>
    </div>
</div>
