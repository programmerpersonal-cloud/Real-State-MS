<?php
/**
 * Payment Controller
 */
require_once BASE_PATH . '/models/Payment.php';
require_once BASE_PATH . '/models/Lease.php';

class PaymentController
{
    /**
     * The payment lifecycle, keyed by the stored value and ordered the way a
     * ledger is read: what came in, what is owed, what is late.
     */
    public const STATUSES = [
        'paid'      => 'Paid',
        'pending'   => 'Pending',
        'overdue'   => 'Overdue',
        'partial'   => 'Partial',
        'cancelled' => 'Cancelled',
    ];

    /** What the money was for — the payments.payment_type enum. */
    public const TYPES = [
        'rent'     => 'Rent',
        'sale'     => 'Sale',
        'deposit'  => 'Deposit',
        'refund'   => 'Refund',
        'late_fee' => 'Late fee',
        'other'    => 'Other',
    ];

    /** How it was taken — the payments.payment_method enum. */
    public const METHODS = [
        'cash'          => 'Cash',
        'bank_transfer' => 'Bank transfer',
        'check'         => 'Cheque',
        'card'          => 'Card',
        'other'         => 'Other',
    ];

    private Payment $model;

    public function __construct()
    {
        $this->model = new Payment();
    }

    public function index(): void
    {
        authorize('payments.view');

        // Every enumerated filter is checked against the same map its control
        // is built from, and the sort key is resolved by Payment::SORTS. Only
        // the free-text search reaches the model, and it stays a bound param.
        $filters = [
            'status'         => uiPick($_GET['status'] ?? '', array_keys(self::STATUSES)),
            'payment_type'   => uiPick($_GET['payment_type'] ?? '', array_keys(self::TYPES)),
            'payment_method' => uiPick($_GET['payment_method'] ?? '', array_keys(self::METHODS)),
            'search'         => trim((string) ($_GET['search'] ?? '')),
            'sort'           => uiSortValue(array_keys(Payment::SORTS), 'newest'),
        ];

        $page = max(1, (int)($_GET['p'] ?? 1));
        $offset = ($page - 1) * ITEMS_PER_PAGE;
        $payments = $this->model->getAll($filters, ITEMS_PER_PAGE, $offset);
        $totalCount = $this->model->count($filters);
        $totalPages = (int) ceil($totalCount / ITEMS_PER_PAGE);
        // Already on the page before this redesign. It now drives the status
        // cards as well as the totals, so the filter costs no extra query.
        $totals = $this->model->totalsByStatus();

        // The quick-record popup lives on this page, so it needs the lease
        // list the full form uses and the entry kept back after a reject.
        $formData = $_SESSION['form_data'] ?? [];
        unset($_SESSION['form_data']);

        renderPage(VIEWS_PATH . '/admin/payments/index.php', [
            'payments' => $payments, 'filters' => $filters,
            'page' => $page, 'totalPages' => $totalPages, 'totalCount' => $totalCount,
            'totals' => $totals,
            'statuses' => self::STATUSES,
            'types'    => self::TYPES,
            'methods'  => self::METHODS,
            'leases'   => self::activeLeases(),
            'formData' => $formData,
            'openCreateModal' => ($_GET['modal'] ?? '') === 'create',
            'pageTitle' => 'Payments',
            'pageSubtitle' => 'Every transaction recorded against a lease, a sale or a reservation.',
            'breadcrumbs' => [['label' => 'Payments']],
            'actionButton' => [
                'label' => 'Record Payment',
                'icon'  => 'bi-plus-lg',
                'url'   => APP_URL . '/index.php?page=payments&action=create',
                'attrs' => ['data-modal-open' => 'paymentCreateModal'],
            ],
        ]);
    }

    /**
     * Active leases, with the customer and property each one implies.
     * Reachable from outside the controller so the quick-record popup offers
     * the same list wherever it is hosted.
     */
    public static function activeLeases(): array
    {
        return getDBConnection()->query("
            SELECT l.id, l.lease_code, l.rent_amount, c.full_name AS customer_name, p.title AS property_title, p.id AS property_id, c.id AS customer_id
            FROM leases l
            JOIN customers c ON l.customer_id = c.id
            JOIN properties p ON l.property_id = p.id
            WHERE l.status='active'
            ORDER BY l.created_at DESC
        ")->fetchAll();
    }

    public function create(): void
    {
        authorize('payments.create');
        $db = getDBConnection();
        $leases = self::activeLeases();

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
            // A submit from a popup returns to that popup, so a rejected
            // entry is corrected where it was made.
            $failUrl = modalReturnUrl('payments', 'payment',
                APP_URL . '/index.php?page=payments&action=create');

            $data = [
                'payment_type'   => $_POST['payment_type'] ?? 'rent',
                'reference_type' => $_POST['reference_type'] ?? 'lease',
                'reference_id'   => (int)($_POST['reference_id'] ?? 0),
                'customer_id'    => (int)($_POST['customer_id'] ?? 0),
                'property_id'    => (int)($_POST['property_id'] ?? 0) ?: null,
                'amount'         => (float)($_POST['amount'] ?? 0),
                'due_date'       => $_POST['due_date'] ?? null,
                'payment_date'   => $_POST['payment_date'] ?? date('Y-m-d'),
                'payment_method' => $_POST['payment_method'] ?? 'cash',
                'notes'          => sanitize($_POST['notes'] ?? ''),
                'status'         => $_POST['status'] ?? 'paid',
                'schedule_id'    => (int)($_POST['schedule_id'] ?? 0),
            ];
            // The same rules, each now keyed to the field it belongs to so the
            // message appears under the control rather than as one flash.
            unset($_SESSION['form_errors']);
            $errors = [];

            if (!$data['customer_id']) {
                addFieldError($errors, 'reference_id', 'Choose the lease this payment belongs to.');
            }
            if ($data['amount'] <= 0) {
                addFieldError($errors, 'amount', 'The amount must be greater than zero.');
            }
            // These three come from <select>s. A value outside the enum would
            // be refused by MySQL anyway, but a truncation warning is a poor
            // way to learn a form was tampered with.
            if (!isset(self::TYPES[$data['payment_type']])) {
                addFieldError($errors, 'payment_type', 'Choose what this payment is for.');
            }
            if (!isset(self::METHODS[$data['payment_method']])) {
                addFieldError($errors, 'payment_method', 'Choose how the payment was taken.');
            }
            if (!isset(self::STATUSES[$data['status']])) {
                addFieldError($errors, 'status', 'Choose the state of this payment.');
            }
            if ($errors) {
                rejectForm($errors, $data, $failUrl);
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
            $_SESSION['form_data'] = $data;
            redirect($failUrl);
        }

        $formData = $_SESSION['form_data'] ?? [];
        unset($_SESSION['form_data']);

        renderPage(VIEWS_PATH . '/admin/payments/create.php', [
            'leases' => $leases,
            'preset' => $preset,
            'formData' => $formData,
            'scheduleId' => $scheduleId,
            // The form's <option>s and the validator read one list.
            'types'   => self::TYPES,
            'methods' => self::METHODS,
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
        authorize('payments.receipt');
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
