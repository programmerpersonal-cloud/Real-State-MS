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
 * The left half is the brand panel: a gradient in the house blues, a
 * wordmark, a welcome and the switch to the other form. It used to rotate
 * through photographs of real listings, and it no longer does — colour holds
 * the copy at full contrast without a scrim over it, downloads nothing, and
 * does not move while somebody is typing a password beside it.
 *
 * The curve between the two halves is the one structural addition: two
 * clipPaths in objectBoundingBox units, defined at the top of the shell and
 * applied from pages/auth.css — vertical side by side, horizontal stacked.
 *
 * Expects:
 *   $authMode    'login' | 'register'  which panel opens active
 *   $roleOptions array<int,string>     roles registration will accept
 *   $formErrors  array                 per-field errors from a reject
 *
 * AuthController still passes $showcase; nothing on the page reads it now.
 * Retiring the query behind it is a controller change and is deliberately
 * left alone here.
 */
$authMode = ($authMode ?? 'login') === 'register' ? 'register' : 'login';

/* The reject path stores what was typed so the form comes back filled.
   Read once and cleared, exactly as register.php did before. */
$formData = $_SESSION['form_data'] ?? [];
unset($_SESSION['form_data']);

/* Both panels are in the document, and both own a field called `password`.
   So the errors are scoped to the panel the rejection actually landed on
   rather than handed to the page: a failed sign-in redirects to ?page=login
   and a failed registration to ?page=register, which is exactly the switch
   below. Without it a mistyped sign-in would come back having outlined the
   registration form's password box as well — a field nobody had touched.

   This is the same rule the flash already follows a few lines down. */
$allErrs   = $formErrors ?? [];
$errs      = $authMode === 'register' ? $allErrs : [];   // registration panel
$loginErrs = $authMode === 'login'    ? $allErrs : [];   // sign-in panel

$err  = static fn(string $f): string => uiFieldError($errs, $f);
$bad  = static fn(string $f): string => uiInvalidClass($errs, $f);
$aria = static fn(string $f, string $hint = ''): string => uiFieldAria($errs, $f, $hint);

$lerr  = static fn(string $f): string => uiFieldError($loginErrs, $f);
$lbad  = static fn(string $f): string => uiInvalidClass($loginErrs, $f);
$laria = static fn(string $f, string $hint = ''): string => uiFieldAria($loginErrs, $f, $hint);

/* Flash is consumed once and printed into the panel that is open.
   Every redirect that sets one lands on the matching route, so the
   message and the panel it belongs to always arrive together. */
$flash = renderFlash();

/* Which step a phone opens on. Two panels side by side is a desktop
   layout; stacked, showing a hero and a seven-field form at once asks
   somebody to scroll past the branding every single time. So on a narrow
   screen the screen is the gate first and the form second.

   The rule is: open on the gate unless there is already something to
   read or something already typed. A rejected sign-in comes back with a
   flash and a filled identifier, and hiding that behind a chooser would
   throw away the message the redirect exists to deliver.

   ?step=form is the same statement from the other direction — it is what
   the gate's links carry, so they still work with scripting off. It is
   read here and nowhere else: no controller, route or redirect knows
   about it, and dropping it changes nothing but which step opens. */
$authStep = (($_GET['step'] ?? '') === 'form' || $allErrs || $flash !== '' || $formData)
    ? 'form' : 'choose';

$loginUrl    = APP_URL . '/index.php?page=login';
$registerUrl = APP_URL . '/index.php?page=register';
$homeUrl     = APP_URL . '/index.php?page=home';
$contactUrl  = APP_URL . '/index.php?page=contact';
$company     = sanitize(companyName());
?>
<?php /* Straight to the form. "Main content" here would land a keyboard
         user at the top of the property panel, in front of a wordmark, a
         pause control and one dot per photograph — nine tab stops before
         the first field. */ ?>
<a class="skip-link" href="#auth-form">Skip to the form</a>

<div class="auth-shell" id="main" data-auth-shell data-auth-mode="<?= $authMode ?>"
     data-auth-step="<?= $authStep ?>"
     data-title-login="<?= sanitize('Sign In — ' . companyName()) ?>"
     data-title-register="<?= sanitize('Create Account — ' . companyName()) ?>">

    <?php /* ─── The curve between the two halves ─────────────────
             The only markup this redesign added, and it is here because
             CSS cannot express the shape on its own: clip-path: path()
             takes absolute user units, so a curve written that way is
             fixed at one window size, and a mask would draw the wave
             without clipping hit-testing — leaving an invisible strip of
             the photographic panel lying over the form's left margin.

             A clipPath in objectBoundingBox units has neither problem.
             Every coordinate is a fraction of the box, so one path
             describes the curve at 1024px and at 2560px alike, and the
             clipped-away area stops taking pointer events as well as
             paint.

             Two paths rather than one: side by side the curve is the
             vertical boundary between the panels, stacked it is the
             horizontal one under the hero. Purely decorative — nothing
             here is announced, focusable or laid out. */ ?>
    <svg class="auth-waves" aria-hidden="true" focusable="false" width="0" height="0">
        <defs>
            <?php /* One sweep, not two. The path this replaced bent at
                     0.17, again at 0.37 and again at 0.56, which at the size
                     it is drawn reads as a ripple down the seam rather than
                     as a shape. The reference has a single wave: the panel
                     leans into the form column through the upper half and
                     draws back below it, and the control points are far
                     enough apart for the curve to be legible as one gesture
                     at 1024px and at 2560px alike.

                     0.78 at the top and 0.80 at the foot are the two numbers
                     the layout is written against — they are the leftmost the
                     boundary ever comes, and the panel's copy is held clear
                     of them by a percentage padding, so it scales with the
                     same box the path does. 0.97 is the crest, and the form's
                     own left padding clears that. */ ?>
            <clipPath id="authWaveSide" clipPathUnits="objectBoundingBox">
                <path d="M0,0 H0.78 C0.90,0.16 0.99,0.32 0.97,0.50 C0.95,0.70 0.82,0.84 0.80,1 H0 Z"/>
            </clipPath>
            <clipPath id="authWaveFoot" clipPathUnits="objectBoundingBox">
                <path d="M0,0 H1 V0.80 C0.88,0.94 0.70,1 0.50,0.96 C0.30,0.92 0.14,0.80 0,0.84 Z"/>
            </clipPath>
        </defs>
    </svg>

    <!-- ─── The brand panel ──────────────────────────────────── -->
    <?php /* Colour, not photography. The panel used to rotate through real
             listings, and the pictures were doing two jobs badly: every
             frame needed a scrim heavy enough to hold white text over a
             sunlit exterior, which flattened the photograph, and the
             rotation put moving content on a screen whose whole purpose is
             a person typing a password into it.

             What is left is the brand: a gradient in the house blues with a
             pair of soft shapes behind the copy. It reads at any window
             size, needs no scrim, downloads nothing, and holds still.

             The .auth-showcase__veil element stays and changes job — it is
             the decorative layer now rather than the readability one. */ ?>
    <section class="auth-showcase" aria-label="Welcome to <?= $company ?>">

        <div class="auth-showcase__veil" aria-hidden="true"></div>

        <div class="auth-showcase__body">

            <?php /* The lockup: an eyebrow, a roundel and the name, stacked
                     and centred, which is the shape the reference gives its
                     brand panel. It was a wordmark in the top-left corner —
                     the right place on a page with a header, and this page has
                     no header, so the mark sat in the corner of an empty blue
                     field with nothing to be aligned to.

                     Still one link to the marketing site, still one accessible
                     name. The eyebrow is outside it: "Welcome to" is the
                     sentence the name finishes, not part of what the link
                     announces itself as. */ ?>
            <div class="auth-showcase__top">
                <p class="auth-showcase__eyebrow">Welcome to</p>
                <a class="auth-showcase__wordmark" href="<?= $homeUrl ?>">
                    <span class="auth-showcase__mark" aria-hidden="true">
                        <i class="bi bi-buildings-fill"></i>
                    </span>
                    <span class="auth-showcase__name"><?= $company ?></span>
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

            <?php /* ─── The gate ──────────────────────────────────
                     Both destinations, on one row, always in the same
                     place — which is what the single CTA above could not
                     be. That one says "create an account" on the sign-in
                     panel and "I already have an account" on the other,
                     so the control a person is looking for moves and
                     changes wording depending on where they already are.

                     This is the first thing on a phone. The step it opens
                     is branding and these two buttons and nothing else;
                     the form arrives when one of them is pressed. On a
                     desktop the form is already beside it, so the gate is
                     not shown at all and the panel keeps its single CTA.

                     Real links to the two routes, and links that carry
                     their own step. Without scripting they navigate, the
                     server renders that panel active and ?step=form opens
                     it directly — the same contract the switch controls
                     have always had. auth.js intercepts and neither the
                     navigation nor the query string ever happens. */ ?>
            <div class="auth-gate" data-auth-gate>
                <p class="auth-gate__lead" id="auth-gate-lead">How would you like to continue?</p>
                <div class="auth-gate__actions" role="group" aria-labelledby="auth-gate-lead">
                    <a class="auth-gate__btn auth-gate__btn--solid" href="<?= $registerUrl ?>&amp;step=form"
                       data-auth-switch="register" aria-controls="auth-panel-register">
                        <i class="bi bi-person-plus" aria-hidden="true"></i> Sign Up
                    </a>
                    <a class="auth-gate__btn" href="<?= $loginUrl ?>&amp;step=form"
                       data-auth-switch="login" aria-controls="auth-panel-login">
                        <i class="bi bi-box-arrow-in-right" aria-hidden="true"></i> Sign In
                    </a>
                </div>
            </div>

            <?php /* The rule at the foot of the panel. The reference closes its
                     brand half with a line of small capitals rather than with
                     the gradient simply running out, and the difference is that
                     the panel reads as a composition with a bottom edge instead
                     of as a column that was cut off.

                     Both halves of it earn the space rather than filling it:
                     the year and owner of the software somebody is about to
                     hand a password to, and the one link on this screen that
                     goes to a person. There is no self-service reset here —
                     passwords are issued from Users & Roles — so "Need help?"
                     is the honest destination for anyone who is stuck. */ ?>
            <p class="auth-showcase__meta">
                <span>&copy; <?= date('Y') ?> <?= $company ?></span>
                <a href="<?= $contactUrl ?>">Need help?</a>
            </p>
        </div>
    </section>

    <!-- ─── The forms ────────────────────────────────────────── -->
    <div class="auth-forms" id="auth-form" tabindex="-1">
        <div class="auth-forms__inner" data-auth-forms>

            <?php /* The way back to the gate. Shown only on a narrow
                     screen and only once scripting has enhanced the
                     screen into two steps — with the form and the hero
                     both on the page there is nothing for it to go back
                     *to*, so without JS it is not rendered at all rather
                     than rendered and inert. One control for both panels,
                     because the step is a property of the screen and not
                     of whichever form happens to be open. */ ?>
            <button type="button" class="auth-step-back" data-auth-back hidden>
                <i class="bi bi-arrow-left" aria-hidden="true"></i>
                <span>All sign-in options</span>
            </button>

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
                                 reused passwords happen.

                                 value= is the other half of the same idea: a refused
                                 attempt comes back with the identifier still in the box,
                                 so a mistyped password costs one field rather than two.
                                 Only this one — the password is never carried back. */ ?>
                        <div class="input-icon">
                            <i class="bi bi-person" aria-hidden="true"></i>
                            <input type="text" class="form-control<?= $lbad('login') ?>" id="login-identifier" name="login"
                                   value="<?= sanitize($formData['login'] ?? '') ?>"
                                   autocomplete="username" placeholder="you@example.com" required<?= $laria('login') ?>
                                   <?= $authMode === 'login' ? 'autofocus' : '' ?>>
                        </div>
                        <?= $lerr('login') ?>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="login-password">Password</label>
                        <div class="input-icon input-reveal" data-reveal>
                            <i class="bi bi-lock" aria-hidden="true"></i>
                            <input type="password" class="form-control<?= $lbad('password') ?>" id="login-password" name="password"
                                   autocomplete="current-password" placeholder="••••••••" required<?= $laria('password') ?>>
                            <button type="button" class="input-reveal__btn" data-reveal-toggle
                                    aria-label="Show password" aria-pressed="false">
                                <i class="bi bi-eye" aria-hidden="true"></i>
                            </button>
                        </div>
                        <?= $lerr('password') ?>
                    </div>

                    <?php /* "Remember me" used to sit here as a checkbox. Nothing read it:
                             no branch of AuthController, the session module or the login
                             path ever looked at $_POST['remember'], and checkSessionTimeout()
                             expires an idle session at SESSION_LIFETIME whatever the cookie
                             says — so ticking it changed nothing at all.

                             A control that promises to keep you signed in and does not is
                             worse than no control, and the honest alternatives both mean
                             loosening session expiry on a system where an administrator
                             chose that window deliberately. So the promise is withdrawn
                             rather than the security. Reinstating it is a session change,
                             not a markup one. */ ?>
                    <div class="auth-panel__row auth-panel__row--end">
                        <?php /* There is no self-service reset in this system — passwords
                                 are issued by an administrator from Users & Roles. The link
                                 goes where the question can actually be answered rather
                                 than to a reset page that does not exist. */ ?>
                        <a class="auth-panel__link" href="<?= $contactUrl ?>">Forgot password?</a>
                    </div>

                    <?php /* Both destinations, one row, filled and outlined —
                             the pair the reference ends its form with, and the
                             pair the gate already offers a phone on its first
                             step. They were a full-width submit with a sentence
                             underneath reading "Don't have an account? Sign
                             Up", which is the same two journeys drawn once as a
                             control and once as a footnote.

                             The order is fixed rather than mirrored: the action
                             this form performs is always first and always
                             filled, so the button the eye lands on does what
                             the heading above it promised.

                             The second is a link and not a button, because it
                             goes somewhere. Same href, same switch attribute,
                             same behaviour with scripting off; auth.js reads
                             [data-auth-switch] from anywhere inside the shell,
                             so sitting inside the form changes nothing about
                             the switch — and an anchor submits nothing. */ ?>
                    <div class="auth-actions">
                        <button type="submit" class="btn btn--primary auth-panel__submit">
                            <span>Sign In</span>
                        </button>
                        <a class="auth-actions__alt" href="<?= $registerUrl ?>&amp;step=form"
                           data-auth-switch="register" aria-controls="auth-panel-register">
                            Sign Up
                        </a>
                    </div>
                </form>

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

                    <?php /* The consent line, with the reference's tick beside
                             it. The tick is a mark and not a control: it is a
                             <span>, it is aria-hidden, it has no name, no
                             checked state and nothing reads it — because a real
                             checkbox here would be a new required field, and a
                             new required field is a change to what the server
                             accepts. Submitting this form is the agreement, as
                             it always was; the mark says so at a glance instead
                             of leaving the sentence to be read. */ ?>
                    <p class="auth-panel__terms auth-consent">
                        <span class="auth-consent__mark" aria-hidden="true">
                            <i class="bi bi-check-lg"></i>
                        </span>
                        <span>
                            By signing up I agree with the
                            <a href="<?= APP_URL ?>/index.php?page=terms">Terms of Service</a> and
                            <a href="<?= APP_URL ?>/index.php?page=privacy">Privacy Policy</a>.
                        </span>
                    </p>

                    <div class="auth-actions">
                        <button type="submit" class="btn btn--primary auth-panel__submit">
                            <span>Sign Up</span>
                        </button>
                        <a class="auth-actions__alt" href="<?= $loginUrl ?>&amp;step=form"
                           data-auth-switch="login" aria-controls="auth-panel-login">
                            Sign In
                        </a>
                    </div>
                </form>

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
