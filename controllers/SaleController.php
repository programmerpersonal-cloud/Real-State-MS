<?php
/**
 * Sale Controller
 */
require_once BASE_PATH . '/models/Sale.php';

class SaleController
{
    private Sale $model;

    public function __construct()
    {
        $this->model = new Sale();
    }

    public function index(): void
    {
        requireRole(ROLE_ADMIN, ROLE_AGENT);
        $filters = ['status' => $_GET['status'] ?? '', 'search' => $_GET['search'] ?? ''];
        $page = max(1, (int)($_GET['p'] ?? 1));
        $offset = ($page - 1) * ITEMS_PER_PAGE;
        $sales = $this->model->getAll($filters, ITEMS_PER_PAGE, $offset);
        $totalCount = $this->model->count($filters);
        $totalPages = (int) ceil($totalCount / ITEMS_PER_PAGE);

        renderPage(VIEWS_PATH . '/admin/sales/index.php', [
            'sales' => $sales, 'filters' => $filters,
            'page' => $page, 'totalPages' => $totalPages, 'totalCount' => $totalCount,
            'pageTitle' => 'Sales',
            'breadcrumbs' => [['label' => 'Sales']],
            'actionButton' => ['label' => 'New Sale', 'icon' => 'bi-plus-lg', 'url' => APP_URL . '/index.php?page=sales&action=create'],
        ]);
    }

    public function create(): void
    {
        requireRole(ROLE_ADMIN, ROLE_AGENT);
        $db = getDBConnection();
        $properties = $db->query("SELECT id, title, property_code, price FROM properties WHERE status='available' AND is_archived=0 AND property_type IN ('sale','both') ORDER BY title")->fetchAll();
        $customers = $db->query("SELECT id, full_name, phone FROM customers WHERE is_blacklisted=0 ORDER BY full_name")->fetchAll();
        $agents = $db->query("SELECT id, full_name FROM users WHERE role_id=2 AND is_active=1 ORDER BY full_name")->fetchAll();

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            enforceCSRF();
            $data = [
                'property_id'       => (int)$_POST['property_id'],
                'customer_id'       => (int)$_POST['customer_id'],
                'sale_amount'       => (float)$_POST['sale_amount'],
                // Tax defaults to the configured rate but the agent can override the
                // figure; whatever is charged is stored with the rate it came from.
                'tax_amount'        => ($_POST['tax_amount'] ?? '') !== ''
                    ? (float)$_POST['tax_amount']
                    : round((float)$_POST['sale_amount'] * taxRate() / 100, 2),
                'tax_rate'          => taxRate(),
                'commission_amount' => (float)($_POST['commission_amount'] ?? 0),
                'payment_type'      => $_POST['payment_type'] ?? 'full',
                'status'            => $_POST['status'] ?? 'pending',
                'sale_date'         => $_POST['sale_date'] ?? date('Y-m-d'),
                'agent_id'          => (int)($_POST['agent_id'] ?? 0) ?: null,
                'notes'             => sanitize($_POST['notes'] ?? ''),
            ];
            $errors = [];
            if (!$data['property_id']) $errors[] = 'Select a property.';
            if (!$data['customer_id']) $errors[] = 'Select a buyer.';
            if ($data['sale_amount'] <= 0) $errors[] = 'Sale amount required.';
            if ($errors) {
                setFlash('error', implode(' ', $errors));
                redirect(APP_URL . '/index.php?page=sales&action=create');
            }
            $id = $this->model->create($data);
            if ($id) {
                logAudit('created_sale', 'sale', $id);
                setFlash('success', 'Sale recorded successfully.');
                redirect(APP_URL . '/index.php?page=sales&action=show&id=' . $id);
            }
            setFlash('error', 'Failed to record sale.');
            redirect(APP_URL . '/index.php?page=sales&action=create');
        }

        renderPage(VIEWS_PATH . '/admin/sales/create.php', [
            'properties' => $properties, 'customers' => $customers, 'agents' => $agents,
            'pageTitle' => 'New Sale',
            'breadcrumbs' => [
                ['label' => 'Sales', 'url' => APP_URL . '/index.php?page=sales'],
                ['label' => 'Create'],
            ],
        ]);
    }

    public function show(): void
    {
        requireRole(ROLE_ADMIN, ROLE_AGENT);
        $id = (int)($_GET['id'] ?? 0);
        $sale = $this->model->findById($id);
        if (!$sale) { setFlash('error', 'Sale not found.'); redirect(APP_URL . '/index.php?page=sales'); }
        renderPage(VIEWS_PATH . '/admin/sales/show.php', [
            'sale' => $sale,
            'pageTitle' => 'Sale ' . $sale['sale_code'],
            'breadcrumbs' => [
                ['label' => 'Sales', 'url' => APP_URL . '/index.php?page=sales'],
                ['label' => $sale['sale_code']],
            ],
        ]);
    }
}
