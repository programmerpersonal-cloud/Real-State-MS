<?php
/**
 * Branch Controller
 */
class BranchController
{
    public function index(): void
    {
        requireRole(ROLE_ADMIN);
        $branches = getDBConnection()->query("SELECT * FROM branches ORDER BY name")->fetchAll();
        renderPage(VIEWS_PATH . '/admin/branches/index.php', [
            'branches' => $branches,
            'pageTitle' => 'Branches',
            'breadcrumbs' => [['label' => 'Branches']],
            'actionButton' => ['label' => 'Add Branch', 'icon' => 'bi-plus-lg', 'url' => APP_URL . '/index.php?page=branches&action=create'],
        ]);
    }

    public function create(): void
    {
        requireRole(ROLE_ADMIN);
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            enforceCSRF();
            $db = getDBConnection();
            $stmt = $db->prepare("INSERT INTO branches (name, address, phone, email, manager_name, is_active) VALUES (?,?,?,?,?,?)");
            $stmt->execute([
                sanitize($_POST['name']),
                sanitize($_POST['address'] ?? ''),
                sanitize($_POST['phone'] ?? ''),
                sanitize($_POST['email'] ?? ''),
                sanitize($_POST['manager_name'] ?? ''),
                isset($_POST['is_active']) ? 1 : 0,
            ]);
            logAudit('created_branch', 'branch', (int)$db->lastInsertId());
            setFlash('success', 'Branch created.');
            redirect(APP_URL . '/index.php?page=branches');
        }
        renderPage(VIEWS_PATH . '/admin/branches/form.php', [
            'branch' => null,
            'pageTitle' => 'New Branch',
            'breadcrumbs' => [
                ['label' => 'Branches', 'url' => APP_URL . '/index.php?page=branches'],
                ['label' => 'Create'],
            ],
        ]);
    }

    public function edit(): void
    {
        requireRole(ROLE_ADMIN);
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
