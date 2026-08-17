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

        $roles = getDBConnection()->query("SELECT * FROM roles ORDER BY id")->fetchAll();
        // The role filter is checked against the roles that actually exist, so
        // a hand-edited id is an absent filter rather than an empty page.
        $roleIds = array_map('strval', array_column($roles, 'id'));

        $filters = [
            'search'  => trim((string) ($_GET['search'] ?? '')),
            'role_id' => uiPick($_GET['role_id'] ?? '', $roleIds),
            // Never interpolated: User::SORTS resolves this key.
            'sort'    => uiSortValue(array_keys(User::SORTS), 'newest'),
        ];
        if (($_GET['state'] ?? '') === 'active') {
            $filters['is_active'] = 1;
        } elseif (($_GET['state'] ?? '') === 'disabled') {
            $filters['is_active'] = 0;
        }

        $page = max(1, (int)($_GET['p'] ?? 1));
        $offset = ($page - 1) * ITEMS_PER_PAGE;
        $users = $this->model->getAll($filters, ITEMS_PER_PAGE, $offset);
        $totalCount = $this->model->count($filters);
        $totalPages = (int) ceil($totalCount / ITEMS_PER_PAGE);

        // The quick-add popup lives on this page, so it needs the branch list
        // and the entry kept back after a failed submit.
        $formData = $_SESSION['form_data'] ?? [];
        unset($_SESSION['form_data']);

        renderPage(VIEWS_PATH . '/admin/users/index.php', [
            'users' => $users, 'filters' => $filters, 'roles' => $roles,
            'page' => $page, 'totalPages' => $totalPages, 'totalCount' => $totalCount,
            'branches' => getDBConnection()->query("SELECT id, name FROM branches WHERE is_active=1")->fetchAll(),
            'formData' => $formData,
            // One added query: a GROUP BY behind the role pills, which report
            // how many accounts sit under each role. Fixed cost.
            'roleCounts' => $this->model->countsByRole(),
            'state'      => uiPick($_GET['state'] ?? '', ['active', 'disabled']),
            'openCreateModal' => ($_GET['modal'] ?? '') === 'create',
            'pageTitle' => 'Users & Roles',
            'pageSubtitle' => 'Who can sign in, what they may do, and which record each account belongs to.',
            'breadcrumbs' => [['label' => 'Users']],
            'actionButton' => [
                'label' => 'Add User',
                'icon'  => 'bi-plus-lg',
                'url'   => APP_URL . '/index.php?page=users&action=create',
                'attrs' => ['data-modal-open' => 'userCreateModal'],
            ],
        ]);
    }

    /**
     * The permission matrix, rendered from the matrix itself.
     *
     * Not a second copy of the rules written out for humans — permissionMatrix()
     * is read directly, so this page cannot fall out of step with what the
     * application actually enforces. If a role gains a capability tomorrow,
     * this table shows it without an edit.
     */
    public function permissions(): void
    {
        authorize('users.view');

        $matrix = permissionMatrix();
        $roles  = getDBConnection()->query("SELECT name, display_name FROM roles ORDER BY id")->fetchAll();

        // Every permission any role holds, grouped by the module it belongs
        // to. The name is `page.action` by design, so the module is simply the
        // part before the dot — no second lookup table to maintain.
        $groups = [];
        foreach ($matrix as $granted) {
            foreach ($granted as $perm) {
                if ($perm === '*') {
                    continue;   // the wildcard is a row property, not a column
                }
                [$module, $action] = array_pad(explode('.', $perm, 2), 2, '');
                $groups[$module][$action] = $perm;
            }
        }
        ksort($groups);
        foreach ($groups as &$actions) {
            ksort($actions);
        }
        unset($actions);

        renderPage(VIEWS_PATH . '/admin/users/permissions.php', [
            'matrix' => $matrix,
            'roles'  => $roles,
            'groups' => $groups,
            'pageTitle' => 'Roles & Permissions',
            'pageSubtitle' => 'What each role may do, read straight from the rules the application enforces.',
            'breadcrumbs' => [
                ['label' => 'Users', 'url' => APP_URL . '/index.php?page=users'],
                ['label' => 'Permissions'],
            ],
        ]);
    }

    /**
     * Does this role id name a real role?
     *
     * The role is the whole of an account's authority, so it is the last thing
     * that should be taken on trust from a form. Cached because both create
     * and edit ask, and the roles table changes about once a release.
     */
    private function roleExists(int $roleId): bool
    {
        static $ids = null;
        if ($ids === null) {
            $ids = array_map('intval', getDBConnection()
                ->query("SELECT id FROM roles")->fetchAll(PDO::FETCH_COLUMN));
        }

        return in_array($roleId, $ids, true);
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
            // The same rules, each keyed to the field it belongs to so a form
            // with three problems shows all three where they happened rather
            // than as one sentence above the whole thing.
            unset($_SESSION['form_errors']);
            $errors = [];

            if ($data['full_name'] === '') addFieldError($errors, 'full_name', 'A name is required.');
            if ($data['email'] === '')     addFieldError($errors, 'email', 'An email address is required.');
            if ($data['username'] === '')  addFieldError($errors, 'username', 'A username is required.');

            if ($data['email'] !== '' && !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
                addFieldError($errors, 'email', 'That does not look like an email address.');
            } elseif ($data['email'] !== '' && $this->model->emailExists($data['email'])) {
                addFieldError($errors, 'email', 'An account already uses that email address.');
            }
            if ($data['username'] !== '' && $this->model->usernameExists($data['username'])) {
                addFieldError($errors, 'username', 'That username is already taken.');
            }
            if (strlen($data['password']) < 8) {
                addFieldError($errors, 'password', 'The password must be at least 8 characters.');
            }
            // The role decides everything this account may do, so it is checked
            // against the roles that exist rather than trusted from the form.
            if (!$this->roleExists($data['role_id'])) {
                addFieldError($errors, 'role_id', 'Choose the role this account should have.');
            }

            if ($errors) {
                // Everything but the password comes back; a plaintext
                // credential has no business sitting in the session.
                rejectForm($errors, array_diff_key($data, ['password' => '']), $failUrl);
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
            $failUrl = APP_URL . '/index.php?page=users&action=edit&id=' . $id;

            $data = [
                'full_name' => sanitize($_POST['full_name'] ?? ''),
                'email'     => sanitize($_POST['email'] ?? ''),
                'username'  => sanitize($_POST['username'] ?? ''),
                'phone'     => sanitize($_POST['phone'] ?? ''),
                'branch_id' => $_POST['branch_id'] ?: null,
                'is_active' => isset($_POST['is_active']) ? 1 : 0,
            ];
            $newRole     = (int) ($_POST['role_id'] ?? 0);
            $newPassword = $_POST['password'] ?? '';

            // Editing had no validation at all: a blank name, a duplicate
            // email or a role id that names nothing were all written straight
            // through. A record must not be editable into a state it could not
            // have been created in.
            unset($_SESSION['form_errors']);
            $errors = [];

            if ($data['full_name'] === '') addFieldError($errors, 'full_name', 'A name is required.');
            if ($data['email'] === '')     addFieldError($errors, 'email', 'An email address is required.');
            if ($data['username'] === '')  addFieldError($errors, 'username', 'A username is required.');

            if ($data['email'] !== '' && !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
                addFieldError($errors, 'email', 'That does not look like an email address.');
            } elseif ($data['email'] !== '' && $this->model->emailExists($data['email'], $id)) {
                addFieldError($errors, 'email', 'Another account already uses that email address.');
            }
            if ($data['username'] !== '' && $this->model->usernameExists($data['username'], $id)) {
                addFieldError($errors, 'username', 'Another account already uses that username.');
            }
            if ($newPassword !== '' && strlen($newPassword) < 8) {
                addFieldError($errors, 'password', 'The password must be at least 8 characters.');
            }
            if ($newRole && !$this->roleExists($newRole)) {
                addFieldError($errors, 'role_id', 'That is not a role this system has.');
            }

            // The same lockout guard as toggle(), for the two other ways to
            // reach the same dead end: taking the administrator role away from
            // the last administrator, or disabling them from this form.
            $losingAdmin = $user['role_name'] === ROLE_ADMIN
                && (($newRole && $newRole !== (int) $user['role_id']) || !$data['is_active']);
            if ($losingAdmin && $this->model->otherActiveWithRole(ROLE_ADMIN, $id) === 0) {
                addFieldError($errors, 'role_id', 'This is the only administrator who can still sign in. '
                    . 'Give another account the administrator role first.');
            }

            if ($errors) {
                rejectForm($errors, array_diff_key($data, ['password' => '']), $failUrl);
            }

            $this->model->update($id, $data);
            if ($newRole) {
                $db->prepare("UPDATE users SET role_id = ? WHERE id = ?")->execute([$newRole, $id]);
            }
            if ($newPassword !== '') {
                $this->model->changePassword($id, $newPassword);
                logAudit('changed_password', 'user', $id);
            }
            logAudit('updated_user', 'user', $id);
            setFlash('success', $data['full_name'] . ' updated.');
            redirect(APP_URL . '/index.php?page=users');
        }

        // What was typed before the reject, so a corrected form redraws with
        // the entry rather than reverting to what is on file.
        $formData = $_SESSION['form_data'] ?? [];
        unset($_SESSION['form_data']);

        renderPage(VIEWS_PATH . '/admin/users/form.php', [
            'user' => $user, 'roles' => $roles, 'branches' => $branches,
            'formData' => $formData,
            'pageTitle' => 'Edit ' . $user['full_name'],
            'breadcrumbs' => [
                ['label' => 'Users', 'url' => APP_URL . '/index.php?page=users'],
                ['label' => sanitize($user['full_name'])],
            ],
        ]);
    }

    /**
     * Enable or disable an account.
     *
     * Was a GET link with no token. A single <img src="…&action=toggle&id=1">
     * on any page an administrator visited would disable an account without a
     * click, so this now requires a POST carrying the session CSRF token. A
     * stray GET falls through to the redirect and changes nothing.
     */
    public function toggle(): void
    {
        authorize('users.toggle');
        $id = (int)($_GET['id'] ?? 0);

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            redirect(APP_URL . '/index.php?page=users');
        }
        enforceCSRF();

        $user = $this->model->findById($id);
        if (!$user) {
            setFlash('error', 'That account no longer exists.');
            redirect(APP_URL . '/index.php?page=users');
        }

        $disabling = (bool) $user['is_active'];

        // Nobody may lock the company out of its own installation. Disabling
        // the last administrator who can still sign in leaves no account able
        // to re-enable anything, including this one.
        if ($disabling
            && $user['role_name'] === ROLE_ADMIN
            && $this->model->otherActiveWithRole(ROLE_ADMIN, $id) === 0) {
            setFlash('error', 'This is the only administrator who can still sign in. '
                . 'Give another account the administrator role before disabling this one.');
            redirect(APP_URL . '/index.php?page=users');
        }
        if ($disabling && $id === (int) ($_SESSION['user_id'] ?? 0)) {
            setFlash('error', 'You cannot disable the account you are signed in with.');
            redirect(APP_URL . '/index.php?page=users');
        }

        $this->model->update($id, ['is_active' => $disabling ? 0 : 1]);
        logAudit('toggled_user', 'user', $id, $disabling ? 'active' : 'disabled', $disabling ? 'disabled' : 'active');
        setFlash('success', $disabling
            ? $user['full_name'] . ' can no longer sign in. Their records are untouched.'
            : $user['full_name'] . ' can sign in again.');
        redirect(APP_URL . '/index.php?page=users');
    }

    /**
     * Issue a new password for an account.
     *
     * This was the worst of the GET actions: a link that silently replaced
     * somebody's password, with no token and no check that the account
     * existed. An <img> tag pointed at it would have locked an administrator
     * out of their own installation while they read the page hosting it.
     */
    public function resetPassword(): void
    {
        authorize('users.reset-pass');
        $id = (int)($_GET['id'] ?? 0);

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            redirect(APP_URL . '/index.php?page=users');
        }
        enforceCSRF();

        $user = $this->model->findById($id);
        if (!$user) {
            setFlash('error', 'That account no longer exists.');
            redirect(APP_URL . '/index.php?page=users');
        }

        $newPass = bin2hex(random_bytes(5));
        $this->model->changePassword($id, $newPass);
        // The password itself is deliberately kept out of the audit trail —
        // the log records that a reset happened, not what it produced.
        logAudit('reset_password', 'user', $id);
        setFlash('success', 'New password for ' . $user['full_name'] . ': ' . $newPass
            . ' — share it securely and ask them to change it.');
        redirect(APP_URL . '/index.php?page=users');
    }
}
