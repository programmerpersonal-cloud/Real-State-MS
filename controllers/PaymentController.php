<?php
/**
 * Payment Controller
 */
require_once BASE_PATH . '/models/Payment.php';
require_once BASE_PATH . '/models/Lease.php';

class PaymentController
{
    private Payment $model;

    public function __construct()
    {
        $this->model = new Payment();
    }

    public function index(): void
    {
        requireRole(ROLE_ADMIN, ROLE_AGENT);
        $filters = [
            'status'       => $_GET['status'] ?? '',
            'payment_type' => $_GET['payment_type'] ?? '',
            'search'       => $_GET['search'] ?? '',
        ];
        $page = max(1, (int)($_GET['p'] ?? 1));
        $offset = ($page - 1) * ITEMS_PER_PAGE;
        $payments = $this->model->getAll($filters, ITEMS_PER_PAGE, $offset);
        $totalCount = $this->model->count($filters);
        $totalPages = (int) ceil($totalCount / ITEMS_PER_PAGE);
        $totals = $this->model->totalsByStatus();

        renderPage(VIEWS_PATH . '/admin/payments/index.php', [
            'payments' => $payments, 'filters' => $filters,
            'page' => $page, 'totalPages' => $totalPages, 'totalCount' => $totalCount,
            'totals' => $totals,
            'pageTitle' => 'Payments',
            'breadcrumbs' => [['label' => 'Payments']],
            'actionButton' => ['label' => 'Record Payment', 'icon' => 'bi-plus-lg', 'url' => APP_URL . '/index.php?page=payments&action=create'],
        ]);
    }

    public function create(): void
    {
        requireRole(ROLE_ADMIN, ROLE_AGENT);
        $db = getDBConnection();
        $leases = $db->query("
            SELECT l.id, l.lease_code, l.rent_amount, c.full_name AS customer_name, p.title AS property_title, p.id AS property_id, c.id AS customer_id
            FROM leases l
            JOIN customers c ON l.customer_id = c.id
            JOIN properties p ON l.property_id = p.id
            WHERE l.status='active'
            ORDER BY l.created_at DESC
        ")->fetchAll();

        $scheduleId = (int)($_GET['schedule'] ?? 0);
        $preset = null;
        if ($scheduleId) {
            $stmt = $db->prepare("
                SELECT ps.*, l.lease_code, l.customer_id, l.property_id, c.full_name AS customer_name, p.title AS property_title
                FROM payment_schedules ps
                JOIN leases l ON ps.lease_id = l.id
                JOIN customers c ON l.customer_id = c.id
                JOIN properties p ON l.property_id = p.id
                WHERE ps.id = ?
            ");
            $stmt->execute([$scheduleId]);
            $preset = $stmt->fetch();
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            enforceCSRF();
            $data = [
                'payment_type'   => $_POST['payment_type'] ?? 'rent',
                'reference_type' => $_POST['reference_type'] ?? 'lease',
                'reference_id'   => (int)($_POST['reference_id'] ?? 0),
                'customer_id'    => (int)$_POST['customer_id'],
                'property_id'    => (int)($_POST['property_id'] ?? 0) ?: null,
                'amount'         => (float)$_POST['amount'],
                'due_date'       => $_POST['due_date'] ?? null,
                'payment_date'   => $_POST['payment_date'] ?? date('Y-m-d'),
                'payment_method' => $_POST['payment_method'] ?? 'cash',
                'notes'          => sanitize($_POST['notes'] ?? ''),
                'status'         => $_POST['status'] ?? 'paid',
                'schedule_id'    => (int)($_POST['schedule_id'] ?? 0),
            ];
            $errors = [];
            if (!$data['customer_id']) $errors[] = 'Customer required.';
            if ($data['amount'] <= 0)  $errors[] = 'Amount must be positive.';
            if ($errors) {
                setFlash('error', implode(' ', $errors));
                redirect(APP_URL . '/index.php?page=payments&action=create');
            }
            $id = $this->model->create($data);
            if ($id) {
                logAudit('recorded_payment', 'payment', $id);
                if ($data['customer_id']) {
                    // Find the user_id behind the customer (if any) for notification
                    $stmt = $db->prepare("SELECT user_id FROM customers WHERE id = ?");
                    $stmt->execute([$data['customer_id']]);
                    $uid = $stmt->fetchColumn();
                    if ($uid) notify((int)$uid, 'Payment Recorded', 'A payment of ' . formatCurrency($data['amount']) . ' has been recorded.', 'success', 'payment', $id);
                }
                setFlash('success', 'Payment recorded.');
                redirect(APP_URL . '/index.php?page=payments&action=receipt&id=' . $id);
            }
            setFlash('error', 'Failed to record payment.');
            redirect(APP_URL . '/index.php?page=payments&action=create');
        }

        renderPage(VIEWS_PATH . '/admin/payments/create.php', [
            'leases' => $leases,
            'preset' => $preset,
            'scheduleId' => $scheduleId,
            'pageTitle' => 'Record Payment',
            'breadcrumbs' => [
                ['label' => 'Payments', 'url' => APP_URL . '/index.php?page=payments'],
                ['label' => 'New'],
            ],
        ]);
    }

    public function show(): void
    {
        $this->receipt();
    }

    public function receipt(): void
    {
        requireLogin();
        $id = (int)($_GET['id'] ?? 0);
        $payment = $this->model->findById($id);
        if (!$payment) { setFlash('error', 'Payment not found.'); redirect(APP_URL . '/index.php?page=payments'); }

        // Sale payments print a tax breakdown. The figures come from the sale
        // record, not from today's configured rate, so a reprint years later
        // still shows what the buyer was actually charged.
        $sale = null;
        if ($payment['reference_type'] === 'sale') {
            $stmt = getDBConnection()->prepare(
                "SELECT sale_amount, tax_amount, tax_rate FROM sales WHERE id = ?"
            );
            $stmt->execute([$payment['reference_id']]);
            $sale = $stmt->fetch() ?: null;
        }

        renderPage(VIEWS_PATH . '/admin/payments/receipt.php', [
            'payment' => $payment,
            'sale'    => $sale,
            'pageTitle' => 'Receipt ' . $payment['payment_code'],
            'breadcrumbs' => [
                ['label' => 'Payments', 'url' => APP_URL . '/index.php?page=payments'],
                ['label' => $payment['payment_code']],
            ],
        ]);
    }
}
