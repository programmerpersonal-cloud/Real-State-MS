<?php
/**
 * User Controller — admin user/staff management.
 */
require_once BASE_PATH . '/models/User.php';

class UserController
{
    private User $model;

    public function __construct()
    {
        $this->model = new User();
    }

    public function index(): void
    {
        authorize('users.view');
        $filters = [
            'search'  => $_GET['search'] ?? '',
            'role_id' => $_GET['role_id'] ?? '',
        ];
        $page = max(1, (int)($_GET['p'] ?? 1));
        $offset = ($page - 1) * ITEMS_PER_PAGE;
        $users = $this->model->getAll($filters, ITEMS_PER_PAGE, $offset);
        $totalCount = $this->model->count($filters);
        $totalPages = (int) ceil($totalCount / ITEMS_PER_PAGE);
        $roles = getDBConnection()->query("SELECT * FROM roles ORDER BY id")->fetchAll();

        // The quick-add popup lives on this page, so it needs the branch list
        // and the entry kept back after a failed submit.
        $formData = $_SESSION['form_data'] ?? [];
        unset($_SESSION['form_data']);

        renderPage(VIEWS_PATH . '/admin/users/index.php', [
            'users' => $users, 'filters' => $filters, 'roles' => $roles,
            'page' => $page, 'totalPages' => $totalPages, 'totalCount' => $totalCount,
            'branches' => getDBConnection()->query("SELECT id, name FROM branches WHERE is_active=1")->fetchAll(),
            'formData' => $formData,
            'openCreateModal' => ($_GET['modal'] ?? '') === 'create',
            'pageTitle' => 'Users & Roles',
            'breadcrumbs' => [['label' => 'Users']],
            'actionButton' => [
                'label' => 'Add User',
                'icon'  => 'bi-plus-lg',
                'url'   => APP_URL . '/index.php?page=users&action=create',
                'attrs' => ['data-modal-open' => 'userCreateModal'],
            ],
        ]);
    }

    public function create(): void
    {
        authorize('users.create');
        $db = getDBConnection();
        $roles = $db->query("SELECT * FROM roles ORDER BY id")->fetchAll();
        $branches = $db->query("SELECT id, name FROM branches WHERE is_active=1")->fetchAll();

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            enforceCSRF();
            // A submit from the popup returns to the popup, so a rejected
            // entry is corrected where it was typed.
            $failUrl = ($_POST['return_to'] ?? '') === 'modal'
                ? APP_URL . '/index.php?page=users&modal=create'
                : APP_URL . '/index.php?page=users&action=create';

            $data = [
                'full_name' => sanitize($_POST['full_name'] ?? ''),
                'email'     => sanitize($_POST['email'] ?? ''),
                'username'  => sanitize($_POST['username'] ?? ''),
                'phone'     => sanitize($_POST['phone'] ?? ''),
                'password'  => $_POST['password'] ?? '',
                'role_id'   => (int)($_POST['role_id'] ?? 0),
                'branch_id' => $_POST['branch_id'] ?: null,
            ];
            $errors = [];
            if (!$data['full_name'] || !$data['email'] || !$data['username']) $errors[] = 'Name, email and username are required.';
            if (strlen($data['password']) < 8) $errors[] = 'Password must be at least 8 characters.';
            if ($this->model->emailExists($data['email'])) $errors[] = 'Email already exists.';
            if ($this->model->usernameExists($data['username'])) $errors[] = 'Username already taken.';
            if ($errors) {
                setFlash('error', implode(' ', $errors));
                // Everything but the password comes back; a plaintext
                // credential has no business sitting in the session.
                $_SESSION['form_data'] = array_diff_key($data, ['password' => '']);
                redirect($failUrl);
            }

            $id = $this->model->create($data);
            if ($id) {
                logAudit('created_user', 'user', $id);
                setFlash('success', 'User created.');
                redirect(APP_URL . '/index.php?page=users');
            }
            setFlash('error', 'Failed to create user.');
            $_SESSION['form_data'] = array_diff_key($data, ['password' => '']);
            redirect($failUrl);
        }

        $formData = $_SESSION['form_data'] ?? [];
        unset($_SESSION['form_data']);

        renderPage(VIEWS_PATH . '/admin/users/form.php', [
            'user' => null, 'roles' => $roles, 'branches' => $branches,
            'formData' => $formData,
            'pageTitle' => 'New User',
            'breadcrumbs' => [
                ['label' => 'Users', 'url' => APP_URL . '/index.php?page=users'],
                ['label' => 'Create'],
            ],
        ]);
    }

    public function edit(): void
    {
        authorize('users.edit');
        $id = (int)($_GET['id'] ?? 0);
        $user = $this->model->findById($id);
        if (!$user) { setFlash('error', 'User not found.'); redirect(APP_URL . '/index.php?page=users'); }

        $db = getDBConnection();
        $roles = $db->query("SELECT * FROM roles ORDER BY id")->fetchAll();
        $branches = $db->query("SELECT id, name FROM branches WHERE is_active=1")->fetchAll();

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            enforceCSRF();
            $data = [
                'full_name' => sanitize($_POST['full_name']),
                'email'     => sanitize($_POST['email']),
                'username'  => sanitize($_POST['username']),
                'phone'     => sanitize($_POST['phone'] ?? ''),
                'branch_id' => $_POST['branch_id'] ?: null,
                'is_active' => isset($_POST['is_active']) ? 1 : 0,
            ];
            $this->model->update($id, $data);
            // Change role
            if (!empty($_POST['role_id'])) {
                $db->prepare("UPDATE users SET role_id = ? WHERE id = ?")->execute([(int)$_POST['role_id'], $id]);
            }
            // Optional password change
            $newPassword = $_POST['password'] ?? '';
            if ($newPassword !== '') {
                if (strlen($newPassword) < 8) {
                    setFlash('error', 'Password must be at least 8 characters.');
                    redirect(APP_URL . '/index.php?page=users&action=edit&id=' . $id);
                }
                $this->model->changePassword($id, $newPassword);
                logAudit('changed_password', 'user', $id);
            }
            logAudit('updated_user', 'user', $id);
            setFlash('success', 'User updated.');
            redirect(APP_URL . '/index.php?page=users');
        }

        renderPage(VIEWS_PATH . '/admin/users/form.php', [
            'user' => $user, 'roles' => $roles, 'branches' => $branches,
            'pageTitle' => 'Edit ' . $user['full_name'],
            'breadcrumbs' => [
                ['label' => 'Users', 'url' => APP_URL . '/index.php?page=users'],
                ['label' => sanitize($user['full_name'])],
            ],
        ]);
    }

    public function toggle(): void
    {
        authorize('users.toggle');
        $id = (int)($_GET['id'] ?? 0);
        $user = $this->model->findById($id);
        if ($user) {
            $this->model->update($id, ['is_active' => $user['is_active'] ? 0 : 1]);
            logAudit('toggled_user', 'user', $id);
            setFlash('success', 'User status updated.');
        }
        redirect(APP_URL . '/index.php?page=users');
    }

    public function resetPassword(): void
    {
        authorize('users.reset-pass');
        $id = (int)($_GET['id'] ?? 0);
        $newPass = bin2hex(random_bytes(5));
        $this->model->changePassword($id, $newPass);
        logAudit('reset_password', 'user', $id);
        setFlash('success', 'Password reset to: ' . $newPass . ' — please share securely.');
        redirect(APP_URL . '/index.php?page=users');
    }
}
