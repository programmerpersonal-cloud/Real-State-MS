<?php
/**
 * Branch Controller
 */
class BranchController
{
    public function index(): void
    {
        authorize('branches.view');
        $branches = getDBConnection()->query("SELECT * FROM branches ORDER BY name")->fetchAll();

        // The quick-add popup lives on this page, so it needs the entry kept
        // back after a failed submit to reopen where the user left off.
        $formData = $_SESSION['form_data'] ?? [];
        unset($_SESSION['form_data']);

        renderPage(VIEWS_PATH . '/admin/branches/index.php', [
            'branches' => $branches,
            'formData' => $formData,
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
            // A branch with no name is unusable everywhere it is offered.
            if ($data['name'] === '') {
                setFlash('error', 'Branch name is required.');
                $_SESSION['form_data'] = $data;
                redirect($failUrl);
            }

            $db = getDBConnection();
            $stmt = $db->prepare("INSERT INTO branches (name, address, phone, email, manager_name, is_active) VALUES (?,?,?,?,?,?)");
            $stmt->execute(array_values($data));
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
            $stmt = $db->prepare("UPDATE branches SET name=?, address=?, phone=?, email=?, manager_name=?, is_active=? WHERE id=?");
            $stmt->execute([
                sanitize($_POST['name']),
                sanitize($_POST['address'] ?? ''),
                sanitize($_POST['phone'] ?? ''),
                sanitize($_POST['email'] ?? ''),
                sanitize($_POST['manager_name'] ?? ''),
                isset($_POST['is_active']) ? 1 : 0,
                $id,
            ]);
            logAudit('updated_branch', 'branch', $id);
            setFlash('success', 'Branch updated.');
            redirect(APP_URL . '/index.php?page=branches');
        }
        renderPage(VIEWS_PATH . '/admin/branches/form.php', [
            'branch' => $branch,
            'pageTitle' => 'Edit Branch',
            'breadcrumbs' => [
                ['label' => 'Branches', 'url' => APP_URL . '/index.php?page=branches'],
                ['label' => sanitize($branch['name'])],
            ],
        ]);
    }
}
