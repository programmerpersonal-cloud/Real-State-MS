<?php
/**
 * Saxane Real Estate Management System
 * Authentication Controller
 */

require_once BASE_PATH . '/models/User.php';

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

        // Validation
        $errors = [];
        if (empty($login)) $errors[] = 'Email or username is required.';
        if (empty($password)) $errors[] = 'Password is required.';

        if (!empty($errors)) {
            setFlash('error', implode(' ', $errors));
            redirect(APP_URL . '/index.php?page=login');
        }

        $result = $this->userModel->authenticate($login, $password);

        if (!$result['success']) {
            setFlash('error', $result['error']);
            redirect(APP_URL . '/index.php?page=login');
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

        if ($data['full_name'] === '') {
            addFieldError($errors, 'full_name', 'Your full name is required.');
        }
        if ($data['email'] === '' || !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            addFieldError($errors, 'email', 'A valid email address is required.');
        } elseif ($this->userModel->emailExists($data['email'])) {
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
        if ($data['password'] !== $confirmPassword) {
            addFieldError($errors, 'confirm_password', 'The two passwords do not match.');
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
