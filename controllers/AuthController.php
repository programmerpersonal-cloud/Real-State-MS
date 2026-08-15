<?php
/**
 * Saxane Real Estate Management System
 * Authentication Controller
 */

require_once BASE_PATH . '/models/User.php';

class AuthController
{
    private User $userModel;

    public function __construct()
    {
        $this->userModel = new User();
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

        require VIEWS_PATH . '/auth/register.php';
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
        $data = [
            'full_name' => sanitize($_POST['full_name'] ?? ''),
            'email'     => sanitize($_POST['email'] ?? ''),
            'phone'     => sanitize($_POST['phone'] ?? ''),
            'username'  => sanitize($_POST['username'] ?? ''),
            'password'  => $_POST['password'] ?? '',
            'role_id'   => (int) ($_POST['role_id'] ?? 3), // Default to customer
        ];
        $confirmPassword = $_POST['confirm_password'] ?? '';

        // Validation
        $errors = [];
        if (empty($data['full_name'])) $errors[] = 'Full name is required.';
        if (empty($data['email']) || !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'A valid email is required.';
        }
        if (empty($data['username']) || strlen($data['username']) < 3) {
            $errors[] = 'Username must be at least 3 characters.';
        }
        if (strlen($data['password']) < 8) {
            $errors[] = 'Password must be at least 8 characters.';
        }
        if ($data['password'] !== $confirmPassword) {
            $errors[] = 'Passwords do not match.';
        }
        if ($this->userModel->emailExists($data['email'])) {
            $errors[] = 'This email is already registered.';
        }
        if ($this->userModel->usernameExists($data['username'])) {
            $errors[] = 'This username is already taken.';
        }

        if (!empty($errors)) {
            setFlash('error', implode(' ', $errors));
            $_SESSION['form_data'] = $data;
            redirect(APP_URL . '/index.php?page=register');
        }

        $userId = $this->userModel->create($data);

        if ($userId) {
            logAudit('register', 'user', $userId);
            setFlash('success', 'Account created successfully! Please log in.');
            redirect(APP_URL . '/index.php?page=login');
        } else {
            setFlash('error', 'Registration failed. Please try again.');
            redirect(APP_URL . '/index.php?page=register');
        }
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
