<?php
/**
 * Branch Controller
 */
class BranchController
{
    public function index(): void
    {
        authorize('branches.view');
        $db = getDBConnection();
        $branches = $db->query("SELECT * FROM branches ORDER BY name")->fetchAll();

        // How many people each branch actually has, in one grouped query
        // rather than one per row. A branch list that says nothing about what
        // a branch contains is a list of names.
        $staff = array_map('intval', array_column(
            $db->query("SELECT branch_id, COUNT(*) AS n FROM users
                        WHERE branch_id IS NOT NULL GROUP BY branch_id")->fetchAll(),
            'n', 'branch_id'
        ));

        // The quick-add popup lives on this page, so it needs the entry kept
        // back after a failed submit to reopen where the user left off.
        $formData = $_SESSION['form_data'] ?? [];
        unset($_SESSION['form_data']);

        renderPage(VIEWS_PATH . '/admin/branches/index.php', [
            'branches' => $branches,
            'staffCounts' => $staff,
            'formData' => $formData,
            'pageSubtitle' => 'Offices, who runs them, and how many people are attached to each.',
            'openCreateModal' => ($_GET['modal'] ?? '') === 'create',
            'pageTitle' => 'Branches',
            'breadcrumbs' => [['label' => 'Branches']],
            'actionButton' => [
                'label' => 'Add Branch',
                'icon'  => 'bi-plus-lg',
                'url'   => APP_URL . '/index.php?page=branches&action=create',
                'attrs' => ['data-modal-open' => 'branchCreateModal'],
            ],
        ]);
    }

    public function create(): void
    {
        authorize('branches.create');
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            enforceCSRF();
            // A submit from the popup returns to the popup, so a rejected
            // entry is corrected where it was typed.
            $failUrl = ($_POST['return_to'] ?? '') === 'modal'
                ? APP_URL . '/index.php?page=branches&modal=create'
                : APP_URL . '/index.php?page=branches&action=create';

            $data = [
                'name'         => sanitize($_POST['name'] ?? ''),
                'address'      => sanitize($_POST['address'] ?? ''),
                'phone'        => sanitize($_POST['phone'] ?? ''),
                'email'        => sanitize($_POST['email'] ?? ''),
                'manager_name' => sanitize($_POST['manager_name'] ?? ''),
                'is_active'    => isset($_POST['is_active']) ? 1 : 0,
            ];
            normalisePhoneFields($data);
            if ($errors = $this->validate($data)) {
                rejectForm($errors, $data, $failUrl);
            }

            $db = getDBConnection();
            $stmt = $db->prepare("INSERT INTO branches (name, address, phone, email, manager_name, is_active)
                                  VALUES (:name, :address, :phone, :email, :manager_name, :is_active)");
            // Named rather than positional: array_values() bound these by the
            // order the array happened to be written in, so re-ordering one
            // line above would have silently written the phone into the email.
            $stmt->execute([
                ':name'         => $data['name'],
                ':address'      => $data['address'],
                ':phone'        => $data['phone'],
                ':email'        => $data['email'],
                ':manager_name' => $data['manager_name'],
                ':is_active'    => $data['is_active'],
            ]);
            logAudit('created_branch', 'branch', (int)$db->lastInsertId());
            setFlash('success', 'Branch created.');
            redirect(APP_URL . '/index.php?page=branches');
        }

        $formData = $_SESSION['form_data'] ?? [];
        unset($_SESSION['form_data']);

        renderPage(VIEWS_PATH . '/admin/branches/form.php', [
            'branch' => null,
            'formData' => $formData,
            'pageTitle' => 'New Branch',
            'breadcrumbs' => [
                ['label' => 'Branches', 'url' => APP_URL . '/index.php?page=branches'],
                ['label' => 'Create'],
            ],
        ]);
    }

    public function edit(): void
    {
        authorize('branches.edit');
        $id = (int)($_GET['id'] ?? 0);
        $db = getDBConnection();
        $stmt = $db->prepare("SELECT * FROM branches WHERE id = ?");
        $stmt->execute([$id]);
        $branch = $stmt->fetch();
        if (!$branch) { setFlash('error', 'Branch not found.'); redirect(APP_URL . '/index.php?page=branches'); }

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            enforceCSRF();
            $failUrl = APP_URL . '/index.php?page=branches&action=edit&id=' . $id;

            $data = [
                'name'         => sanitize($_POST['name'] ?? ''),
                'address'      => sanitize($_POST['address'] ?? ''),
                'phone'        => sanitize($_POST['phone'] ?? ''),
                'email'        => sanitize($_POST['email'] ?? ''),
                'manager_name' => sanitize($_POST['manager_name'] ?? ''),
                'is_active'    => isset($_POST['is_active']) ? 1 : 0,
            ];
            normalisePhoneFields($data);
            // Editing had no validation: a branch could be renamed to nothing,
            // and it would then appear as a blank line in every staff form
            // that offers it.
            if ($errors = $this->validate($data)) {
                rejectForm($errors, $data, $failUrl);
            }

            $stmt = $db->prepare("UPDATE branches SET name=:name, address=:address, phone=:phone,
                                  email=:email, manager_name=:manager_name, is_active=:is_active WHERE id=:id");
            $stmt->execute([
                ':name'         => $data['name'],
                ':address'      => $data['address'],
                ':phone'        => $data['phone'],
                ':email'        => $data['email'],
                ':manager_name' => $data['manager_name'],
                ':is_active'    => $data['is_active'],
                ':id'           => $id,
            ]);
            logAudit('updated_branch', 'branch', $id);
            setFlash('success', $data['name'] . ' updated.');
            redirect(APP_URL . '/index.php?page=branches');
        }

        $formData = $_SESSION['form_data'] ?? [];
        unset($_SESSION['form_data']);

        renderPage(VIEWS_PATH . '/admin/branches/form.php', [
            'branch' => $branch,
            'formData' => $formData,
            'pageTitle' => 'Edit Branch',
            'breadcrumbs' => [
                ['label' => 'Branches', 'url' => APP_URL . '/index.php?page=branches'],
                ['label' => sanitize($branch['name'])],
            ],
        ]);
    }

    /**
     * The same rules for both entry points.
     *
     * @return array<int, string> messages for the flash; field keys are stored
     *                            alongside by addFieldError()
     */
    private function validate(array $d): array
    {
        unset($_SESSION['form_errors']);
        $errors = [];

        // Shape first, from the shared ruleset: a manager's name is letters,
        // a phone answers to its own country, an email is an email.
        validateSharedFields($d, $errors, ['name']);

        // A branch with no name is unusable everywhere it is offered.
        if ($d['name'] === '') {
            addFieldError($errors, 'name', 'A branch name is required.');
        }
        if ($d['email'] !== '' && !filter_var($d['email'], FILTER_VALIDATE_EMAIL)) {
            addFieldError($errors, 'email', 'That does not look like an email address.');
        }

        return $errors;
    }
}
