<?php
/**
 * Owner Controller
 */
require_once BASE_PATH . '/models/Owner.php';

class OwnerController
{
    private Owner $model;

    public function __construct()
    {
        $this->model = new Owner();
    }

    public function index(): void
    {
        requireRole(ROLE_ADMIN, ROLE_AGENT);
        $filters = ['search' => $_GET['search'] ?? ''];
        $page = max(1, (int)($_GET['p'] ?? 1));
        $offset = ($page - 1) * ITEMS_PER_PAGE;
        $owners = $this->model->getAll($filters, ITEMS_PER_PAGE, $offset);
        $totalCount = $this->model->count($filters);
        $totalPages = (int) ceil($totalCount / ITEMS_PER_PAGE);

        renderPage(VIEWS_PATH . '/admin/owners/index.php', [
            'owners'     => $owners,
            'filters'    => $filters,
            'page'       => $page,
            'totalPages' => $totalPages,
            'totalCount' => $totalCount,
            'pageTitle'  => 'Property Owners',
            'breadcrumbs'=> [['label' => 'Owners']],
            'actionButton' => ['label' => 'Add Owner', 'icon' => 'bi-plus-lg', 'url' => APP_URL . '/index.php?page=owners&action=create'],
        ]);
    }

    public function create(): void
    {
        requireRole(ROLE_ADMIN, ROLE_AGENT);
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            enforceCSRF();
            $data = $this->extractData();
            if (empty($data['full_name']) || empty($data['phone'])) {
                setFlash('error', 'Name and phone are required.');
                $_SESSION['form_data'] = $data;
                redirect(APP_URL . '/index.php?page=owners&action=create');
            }
            $id = $this->model->create($data);
            if ($id) {
                logAudit('created_owner', 'owner', $id);
                setFlash('success', 'Owner created successfully!');
                redirect(APP_URL . '/index.php?page=owners&action=show&id=' . $id);
            }
            setFlash('error', 'Failed to create owner.');
            redirect(APP_URL . '/index.php?page=owners&action=create');
        }
        $formData = $_SESSION['form_data'] ?? [];
        unset($_SESSION['form_data']);

        renderPage(VIEWS_PATH . '/admin/owners/create.php', [
            'formData'   => $formData,
            'pageTitle'  => 'New Owner',
            'breadcrumbs'=> [
                ['label' => 'Owners', 'url' => APP_URL . '/index.php?page=owners'],
                ['label' => 'Create'],
            ],
        ]);
    }

    public function edit(): void
    {
        requireRole(ROLE_ADMIN, ROLE_AGENT);
        $id = (int)($_GET['id'] ?? 0);
        $owner = $this->model->findById($id);
        if (!$owner) { setFlash('error', 'Owner not found.'); redirect(APP_URL . '/index.php?page=owners'); }

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            enforceCSRF();
            $data = $this->extractData();
            $this->model->update($id, $data);
            logAudit('updated_owner', 'owner', $id);
            setFlash('success', 'Owner updated.');
            redirect(APP_URL . '/index.php?page=owners&action=show&id=' . $id);
        }

        renderPage(VIEWS_PATH . '/admin/owners/edit.php', [
            'formData'   => $owner,
            'pageTitle'  => 'Edit Owner',
            'breadcrumbs'=> [
                ['label' => 'Owners', 'url' => APP_URL . '/index.php?page=owners'],
                ['label' => sanitize($owner['full_name']), 'url' => APP_URL . '/index.php?page=owners&action=show&id=' . $id],
                ['label' => 'Edit'],
            ],
        ]);
    }

    public function show(): void
    {
        requireRole(ROLE_ADMIN, ROLE_AGENT, ROLE_OWNER);
        $id = (int)($_GET['id'] ?? 0);
        $owner = $this->model->findById($id);
        if (!$owner) { setFlash('error', 'Owner not found.'); redirect(APP_URL . '/index.php?page=owners'); }
        $properties = $this->model->getProperties($id);

        $db = getDBConnection();
        $stmt = $db->prepare("
            SELECT COALESCE(SUM(p.amount),0) AS total_income
            FROM payments p JOIN properties pr ON p.property_id = pr.id
            WHERE pr.owner_id = ? AND p.status = 'paid' AND p.payment_type IN ('rent','sale')
        ");
        $stmt->execute([$id]);
        $totalIncome = (float) $stmt->fetchColumn();

        renderPage(VIEWS_PATH . '/admin/owners/show.php', [
            'owner'       => $owner,
            'properties'  => $properties,
            'totalIncome' => $totalIncome,
            'pageTitle'   => $owner['full_name'],
            'breadcrumbs' => [
                ['label' => 'Owners', 'url' => APP_URL . '/index.php?page=owners'],
                ['label' => sanitize($owner['full_name'])],
            ],
        ]);
    }

    private function extractData(): array
    {
        return [
            'full_name'       => sanitize($_POST['full_name'] ?? ''),
            'phone'           => sanitize($_POST['phone'] ?? ''),
            'email'           => sanitize($_POST['email'] ?? ''),
            'address'         => sanitize($_POST['address'] ?? ''),
            'national_id'     => sanitize($_POST['national_id'] ?? ''),
            'bank_name'       => sanitize($_POST['bank_name'] ?? ''),
            'bank_account'    => sanitize($_POST['bank_account'] ?? ''),
            'commission_rate' => ($_POST['commission_rate'] ?? '') !== '' ? $_POST['commission_rate'] : commissionRate(),
            'revenue_share'   => $_POST['revenue_share'] !== '' ? $_POST['revenue_share'] : null,
            'notes'           => sanitize($_POST['notes'] ?? ''),
        ];
    }
}
