<?php
/**
 * Saxane Real Estate Management System
 * Authentication Controller
 */

require_once BASE_PATH . '/models/User.php';
require_once BASE_PATH . '/models/Property.php';

class AuthController
{
    /**
     * The only roles a visitor may give themselves.
     *
     * Registration is public and unauthenticated, so the role field on that
     * form is a request from a stranger, not an instruction. Named rather
     * than numbered: the ids are database keys and a reader of this list
     * should be able to see what it permits without looking them up.
     *
     * Every other role — agent, administrator, technician — is created by an
     * administrator through Users & Roles, where the action is audited and
     * the person doing it already holds `users.create`.
     */
    private const SELF_SERVICE_ROLES = [ROLE_CUSTOMER, ROLE_OWNER];

    private User $userModel;

    public function __construct()
    {
        $this->userModel = new User();
    }

    /**
     * Resolve a requested role id to one a visitor is allowed to have.
     *
     * Anything not on the self-service list — including a role id that names
     * nothing — becomes the tenant role. Registration never fails over this:
     * a hand-edited form should quietly get the account it was entitled to,
     * not an error message that tells the sender their probe was noticed.
     *
     * @return array{0:int, 1:bool} [role id to use, whether the request was honoured]
     */
    private function selfServiceRole(int $requested): array
    {
        $stmt = getDBConnection()->prepare(
            "SELECT id FROM roles WHERE name = ? AND id = ?"
        );

        foreach (self::SELF_SERVICE_ROLES as $name) {
            $stmt->execute([$name, $requested]);
            if ($stmt->fetchColumn()) {
                return [$requested, true];
            }
        }

        $fallback = (int) getDBConnection()
            ->query("SELECT id FROM roles WHERE name = " . getDBConnection()->quote(ROLE_CUSTOMER))
            ->fetchColumn();

        return [$fallback, false];
    }

    /**
     * Handle login form display and submission.
     */
    public function login(): void
    {
        if (isLoggedIn()) {
            $this->redirectToDashboard();
            return;
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            enforceCSRF();
            $this->processLogin();
            return;
        }

        // The sign-in screen renders the registration panel beside it so the
        // two can be switched without a page load, and that panel is built
        // from the same allow-list the submission is checked against.
        $roleOptions = $this->selfServiceRoleOptions();
        // Read and cleared here for the same reason register() does it: the
        // errors belong to the request that was just rejected, and leaving
        // them in the session would mark the fields again on the next visit.
        $formErrors  = $_SESSION['form_errors'] ?? [];
        unset($_SESSION['form_errors']);
        $showcase    = $this->showcaseProperties();

        require VIEWS_PATH . '/auth/login.php';
    }

    /**
     * Handle registration form display and submission.
     */
    public function register(): void
    {
        if (isLoggedIn()) {
            $this->redirectToDashboard();
            return;
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            enforceCSRF();
            $this->processRegistration();
            return;
        }

        // The <select> is built from the same list the submission is checked
        // against, so an option can never be offered that would be refused —
        // and the ids stop being hard-coded in the markup.
        $roleOptions = $this->selfServiceRoleOptions();
        $formErrors  = $_SESSION['form_errors'] ?? [];
        unset($_SESSION['form_errors']);
        $showcase    = $this->showcaseProperties();

        require VIEWS_PATH . '/auth/register.php';
    }

    /**
     * The roles offered on the registration form: id => label.
     *
     * @return array<int, string>
     */
    private function selfServiceRoleOptions(): array
    {
        $in   = implode(',', array_fill(0, count(self::SELF_SERVICE_ROLES), '?'));
        $stmt = getDBConnection()->prepare(
            "SELECT id, display_name FROM roles WHERE name IN ({$in}) ORDER BY id"
        );
        $stmt->execute(self::SELF_SERVICE_ROLES);

        return array_map('strval', array_column($stmt->fetchAll(), 'display_name', 'id'));
    }

    /**
     * Photographs for the slideshow beside the sign-in form.
     *
     * Real listings, newest first, with their own cover photo where one has
     * been uploaded — propertyImage() already falls back to a seeded stock
     * shot per row, so a fresh install still shows a full panel rather than
     * an empty blue rectangle.
     *
     * Everything is wrapped: the authentication screen is the one page that
     * has to render when the database is unreachable, because it is where
     * an administrator goes to find out why. A failure here costs the
     * decoration, never the form.
     *
     * @return array<int,array{image:string,title:string,location:string,badge:string,price:string}>
     */
    private function showcaseProperties(int $limit = 6): array
    {
        try {
            $model = new Property();
            $rows  = $model->getAll(['status' => 'available'], $limit, 0);
            $covers = $model->getCoversFor(array_column($rows, 'id'));
        } catch (Throwable $e) {
            $rows = $covers = [];
        }

        $out = [];
        foreach ($rows as $row) {
            $isRent = ($row['property_type'] ?? '') === 'rent';
            $amount = (float) ($isRent ? ($row['rent_amount'] ?? 0) : ($row['price'] ?? 0));

            $out[] = [
                'image'    => propertyImage($row, $covers[(int) $row['id']] ?? null),
                'title'    => (string) ($row['title'] ?? 'Featured property'),
                'location' => (string) ($row['location'] ?? ''),
                'badge'    => $isRent ? 'For Rent' : 'For Sale',
                'price'    => $amount > 0
                    ? formatCurrency($amount) . ($isRent ? ' / month' : '')
                    : '',
            ];
        }

        /* No listings yet — the panel is still a photograph, just an
           unlabelled one. Better an empty caption than an empty frame. */
        if (!$out) {
            foreach (stockPropertyGallery('saxane-auth', $limit) as $src) {
                $out[] = ['image' => $src, 'title' => '', 'location' => '', 'badge' => '', 'price' => ''];
            }
        }

        return $out;
    }

    /**
     * Handle logout.
     */
    public function logout(): void
    {
        if (isLoggedIn()) {
            logAudit('logout', 'user', $_SESSION['user_id']);
        }
        destroySession();
        redirect(APP_URL . '/index.php?page=login');
    }

    // ─── Private Methods ────────────────────────────────────

    private function processLogin(): void
    {
        $login    = sanitize($_POST['login'] ?? '');
        $password = $_POST['password'] ?? '';
        $failUrl  = APP_URL . '/index.php?page=login';

        /* A rejected sign-in comes back with the identifier still in the box.
           It used to come back empty: the failure path set a flash and
           redirected without preserving anything, so someone who mistyped a
           password retyped their email address as well — and on a phone, with
           a password manager that had already filled both, that is the point
           where people give up and reset something.

           The password is the one thing deliberately not preserved. It is
           never written to the session here, exactly as rejectForm() refuses
           to write it, so a failed attempt leaves no secret in server-side
           storage waiting for the next request. */
        unset($_SESSION['form_errors']);
        $errors = [];
        if ($login === '') {
            addFieldError($errors, 'login', 'Enter your email address or username.');
        }
        if ($password === '') {
            addFieldError($errors, 'password', 'Enter your password.');
        }

        // Both of these are keyed to a field, so rejectForm() raises no
        // banner: each message travels back to the box it is about.
        if ($errors) {
            rejectForm($errors, ['login' => $login], $failUrl);
        }

        $result = $this->userModel->authenticate($login, $password);

        if (!$result['success']) {
            /* Deliberately not attached to a field. authenticate() answers
               "these credentials do not work" without ever saying which half
               was wrong, and that is what stops this form being used to
               discover which addresses hold accounts. A message pinned under
               the email box would undo it by implication. So it stays a
               panel-level alert — and the identifier is still preserved. */
            $_SESSION['form_data'] = ['login' => $login];
            setFlash('error', $result['error']);
            redirect($failUrl);
        }

        $user = $result['user'];
        setUserSession($user, $user['role_name']);
        logAudit('login', 'user', $user['id']);
        setFlash('success', 'Welcome back, ' . sanitize($user['full_name']) . '!');
        $this->redirectToDashboard();
    }

    private function processRegistration(): void
    {
        // The role is the whole of an account's authority and this form is
        // open to the public, so it is resolved through the self-service
        // allow-list rather than taken as posted. Before this, a visitor
        // could post the administrator role id and be granted it.
        [$roleId, $honoured] = $this->selfServiceRole((int) ($_POST['role_id'] ?? 0));

        $data = [
            'full_name' => sanitize($_POST['full_name'] ?? ''),
            'email'     => sanitize($_POST['email'] ?? ''),
            'phone'     => sanitize($_POST['phone'] ?? ''),
            'username'  => sanitize($_POST['username'] ?? ''),
            'password'  => $_POST['password'] ?? '',
            'role_id'   => $roleId,
        ];
        $confirmPassword = $_POST['confirm_password'] ?? '';

        // A role outside the list is refused silently, but not invisibly: an
        // administrator reading the trail can see that someone asked for one.
        if (!$honoured && (int) ($_POST['role_id'] ?? 0) > 0) {
            logAudit('registration_role_refused', 'user', 0, (string) (int) $_POST['role_id'], (string) $roleId);
        }

        unset($_SESSION['form_errors']);
        $errors  = [];
        $failUrl = APP_URL . '/index.php?page=register';

        normalisePhoneFields($data);
        // Shape first, from the shared ruleset; the identity checks below are
        // this controller's own and deliberately overwrite it.
        validateSharedFields($data, $errors, ['full_name', 'email']);

        if ($data['email'] !== '' && $this->userModel->emailExists($data['email'])) {
            addFieldError($errors, 'email', 'An account already uses that email address.');
        }
        if (strlen($data['username']) < 3) {
            addFieldError($errors, 'username', 'Choose a username of at least 3 characters.');
        } elseif ($this->userModel->usernameExists($data['username'])) {
            addFieldError($errors, 'username', 'That username is already taken.');
        }
        if (strlen($data['password']) < 8) {
            addFieldError($errors, 'password', 'Choose a password of at least 8 characters.');
        }
        /* From the shared ruleset rather than written out here. The browser
           already refuses this mismatch with validationMessage('passwordMatch')
           (components.js), so a hard-coded sentence in this file meant one rule
           on one field answering in two different languages depending on
           whether scripting had run. The ruleset is the single definition;
           this asks it. */
        if ($data['password'] !== $confirmPassword) {
            addFieldError($errors, 'confirm_password', validationMessage('passwordMatch'));
        }

        if ($errors) {
            // rejectForm() strips the password fields before storing the rest.
            rejectForm($errors, $data, $failUrl);
        }

        $userId = $this->userModel->create($data);

        if ($userId) {
            logAudit('register', 'user', $userId);
            setFlash('success', 'Account created. You can sign in now.');
            redirect(APP_URL . '/index.php?page=login');
        }

        setFlash('error', 'The account could not be created. Please try again.');
        redirect($failUrl);
    }

    /**
     * Redirect to the correct dashboard based on user role.
     */
    private function redirectToDashboard(): void
    {
        $role = getUserRole();
        redirect(APP_URL . '/index.php?page=dashboard');
    }
}
