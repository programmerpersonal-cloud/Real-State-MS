<?php
/**
 * Lease Controller
 */
require_once BASE_PATH . '/models/Lease.php';
require_once BASE_PATH . '/models/Property.php';
require_once BASE_PATH . '/models/Customer.php';

class LeaseController
{
    private Lease $model;

    public function __construct()
    {
        $this->model = new Lease();
        // Roll over overdue payments on every visit
        $this->model->markOverdue();
    }

    public function index(): void
    {
        requireRole(ROLE_ADMIN, ROLE_AGENT);
        $filters = ['status' => $_GET['status'] ?? '', 'search' => $_GET['search'] ?? ''];
        $page = max(1, (int)($_GET['p'] ?? 1));
        $offset = ($page - 1) * ITEMS_PER_PAGE;
        $leases = $this->model->getAll($filters, ITEMS_PER_PAGE, $offset);
        $totalCount = $this->model->count($filters);
        $totalPages = (int) ceil($totalCount / ITEMS_PER_PAGE);

        renderPage(VIEWS_PATH . '/admin/leases/index.php', [
            'leases' => $leases, 'filters' => $filters,
            'page' => $page, 'totalPages' => $totalPages, 'totalCount' => $totalCount,
            'pageTitle' => 'Leases',
            'breadcrumbs' => [['label' => 'Leases']],
            'actionButton' => ['label' => 'New Lease', 'icon' => 'bi-plus-lg', 'url' => APP_URL . '/index.php?page=leases&action=create'],
        ]);
    }

    public function create(): void
    {
        requireRole(ROLE_ADMIN, ROLE_AGENT);
        $db = getDBConnection();
        $properties = $db->query("SELECT id, title, property_code, rent_amount, deposit_amount, owner_id FROM properties WHERE status='available' AND is_archived=0 AND property_type IN ('rent','both') ORDER BY created_at DESC")->fetchAll();
        $customers = $db->query("SELECT id, full_name, phone FROM customers WHERE is_blacklisted=0 ORDER BY full_name")->fetchAll();

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            enforceCSRF();
            $data = [
                'customer_id'    => (int)$_POST['customer_id'],
                'property_id'    => (int)$_POST['property_id'],
                'start_date'     => $_POST['start_date'],
                'end_date'       => $_POST['end_date'],
                'rent_amount'    => (float)$_POST['rent_amount'],
                'deposit_amount' => (float)($_POST['deposit_amount'] ?? 0),
                'payment_schedule'=> $_POST['payment_schedule'] ?? 'monthly',
                'late_fee_rate'  => (float)(($_POST['late_fee_rate'] ?? '') !== '' ? $_POST['late_fee_rate'] : lateFeeRate()),
                'terms'          => sanitize($_POST['terms'] ?? ''),
                'move_in_date'   => $_POST['move_in_date'] ?: $_POST['start_date'],
            ];
            // Look up owner of the chosen property
            $stmt = $db->prepare("SELECT owner_id FROM properties WHERE id = ?");
            $stmt->execute([$data['property_id']]);
            $data['owner_id'] = $stmt->fetchColumn() ?: null;

            $errors = [];
            if (!$data['customer_id']) $errors[] = 'Select a customer.';
            if (!$data['property_id']) $errors[] = 'Select a property.';
            if (!$data['start_date'] || !$data['end_date']) $errors[] = 'Start and end dates are required.';
            if ($data['end_date'] && $data['start_date'] && $data['end_date'] <= $data['start_date']) {
                $errors[] = 'End date must be after start date.';
            }
            if ($data['rent_amount'] <= 0) $errors[] = 'Rent amount must be greater than zero.';
            if ($data['property_id'] && $this->model->hasOverlap($data['property_id'], $data['start_date'], $data['end_date'])) {
                $errors[] = 'This property already has an overlapping active lease.';
            }
            if (!empty($_FILES['contract_file']['name'])) {
                $path = uploadFile($_FILES['contract_file'], 'documents', ALLOWED_DOC_TYPES);
                if ($path) $data['contract_file'] = $path;
            }
            if ($errors) {
                setFlash('error', implode(' ', $errors));
                redirect(APP_URL . '/index.php?page=leases&action=create');
            }
            $id = $this->model->create($data);
            if ($id) {
                logAudit('created_lease', 'lease', $id);
                setFlash('success', 'Lease created and payment schedule generated.');
                redirect(APP_URL . '/index.php?page=leases&action=show&id=' . $id);
            }
            setFlash('error', 'Failed to create lease.');
            redirect(APP_URL . '/index.php?page=leases&action=create');
        }

        renderPage(VIEWS_PATH . '/admin/leases/create.php', [
            'properties' => $properties, 'customers' => $customers,
            'pageTitle' => 'New Lease',
            'breadcrumbs' => [
                ['label' => 'Leases', 'url' => APP_URL . '/index.php?page=leases'],
                ['label' => 'Create'],
            ],
        ]);
    }

    public function show(): void
    {
        requireRole(ROLE_ADMIN, ROLE_AGENT);
        $id = (int)($_GET['id'] ?? 0);
        $lease = $this->model->findById($id);
        if (!$lease) { setFlash('error', 'Lease not found.'); redirect(APP_URL . '/index.php?page=leases'); }
        $schedule = $this->model->getPaymentSchedule($id);
        $arrears  = $this->model->getArrears($id);

        renderPage(VIEWS_PATH . '/admin/leases/show.php', [
            'lease' => $lease, 'schedule' => $schedule, 'arrears' => $arrears,
            'pageTitle' => 'Lease ' . $lease['lease_code'],
            'breadcrumbs' => [
                ['label' => 'Leases', 'url' => APP_URL . '/index.php?page=leases'],
                ['label' => $lease['lease_code']],
            ],
        ]);
    }

    public function renew(): void
    {
        requireRole(ROLE_ADMIN, ROLE_AGENT);
        $id = (int)($_GET['id'] ?? 0);
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            enforceCSRF();
            $newEnd = $_POST['end_date'];
            $newRent = !empty($_POST['rent_amount']) ? (float)$_POST['rent_amount'] : null;
            $this->model->renew($id, $newEnd, $newRent);
            logAudit('renewed_lease', 'lease', $id);
            setFlash('success', 'Lease renewed.');
            redirect(APP_URL . '/index.php?page=leases&action=show&id=' . $id);
        }
        $lease = $this->model->findById($id);
        renderPage(VIEWS_PATH . '/admin/leases/renew.php', [
            'lease' => $lease,
            'pageTitle' => 'Renew Lease',
            'breadcrumbs' => [
                ['label' => 'Leases', 'url' => APP_URL . '/index.php?page=leases'],
                ['label' => $lease['lease_code'], 'url' => APP_URL . '/index.php?page=leases&action=show&id=' . $id],
                ['label' => 'Renew'],
            ],
        ]);
    }

    public function terminate(): void
    {
        requireRole(ROLE_ADMIN, ROLE_AGENT);
        $id = (int)($_GET['id'] ?? 0);
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            enforceCSRF();
            $reason = sanitize($_POST['reason'] ?? '');
            $moveOut = $_POST['move_out_date'] ?? date('Y-m-d');
            $this->model->terminate($id, $reason, $moveOut);
            logAudit('terminated_lease', 'lease', $id, '', $reason);
            setFlash('success', 'Lease terminated.');
            redirect(APP_URL . '/index.php?page=leases&action=show&id=' . $id);
        }
        redirect(APP_URL . '/index.php?page=leases&action=show&id=' . $id);
    }
}
