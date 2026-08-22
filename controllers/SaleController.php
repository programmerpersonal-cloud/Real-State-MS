<?php
/**
 * Sale Controller
 */
require_once BASE_PATH . '/models/Sale.php';

class SaleController
{
    /** Where a deal has got to — the sales.status enum. */
    public const STATUSES = [
        'pending'   => 'Pending',
        'completed' => 'Completed',
        'cancelled' => 'Cancelled',
    ];

    /** How the buyer is paying — the sales.payment_type enum. */
    public const PAYMENT_TYPES = [
        'full'        => 'Paid in full',
        'installment' => 'Instalments',
    ];

    private Sale $model;

    public function __construct()
    {
        $this->model = new Sale();
    }

    public function index(): void
    {
        authorize('sales.view');

        // Enumerated filters check against the same maps their controls are
        // built from; the sort key is resolved by Sale::SORTS. Neither ever
        // reaches SQL as request text.
        $filters = [
            'status'       => uiPick($_GET['status'] ?? '', array_keys(self::STATUSES)),
            'payment_type' => uiPick($_GET['payment_type'] ?? '', array_keys(self::PAYMENT_TYPES)),
            'agent_id'     => max(0, (int) ($_GET['agent_id'] ?? 0)) ?: '',
            'search'       => trim((string) ($_GET['search'] ?? '')),
            'sort'         => uiSortValue(array_keys(Sale::SORTS), 'newest'),
        ];

        $page = max(1, (int)($_GET['p'] ?? 1));
        $offset = ($page - 1) * ITEMS_PER_PAGE;
        $sales = $this->model->getAll($filters, ITEMS_PER_PAGE, $offset);
        $totalCount = $this->model->count($filters);
        $totalPages = (int) ceil($totalCount / ITEMS_PER_PAGE);

        // The quick-add popup lives on this page, so it needs the same option
        // lists the full form uses and the entry kept back after a reject.
        $formData = $_SESSION['form_data'] ?? [];
        unset($_SESSION['form_data']);

        renderPage(VIEWS_PATH . '/admin/sales/index.php', array_merge($this->formLookups(), [
            'sales' => $sales, 'filters' => $filters,
            'page' => $page, 'totalPages' => $totalPages, 'totalCount' => $totalCount,
            'formData' => $formData,
            'statuses'     => self::STATUSES,
            'paymentTypes' => self::PAYMENT_TYPES,
            // One added query: a GROUP BY behind the pipeline cards, which
            // report both the count and the value of each stage. Fixed cost,
            // independent of how many sales are rendered.
            'totals' => $this->model->totalsByStatus($filters),
            'openCreateModal' => ($_GET['modal'] ?? '') === 'create',
            'pageTitle' => 'Sales',
            // Says whose pipeline this is, in the reader's own terms.
            'pageSubtitle' => recordScopeHint('deal'),
            'breadcrumbs' => [['label' => 'Sales']],
            'actionButton' => [
                'label' => 'New Sale',
                'icon'  => 'bi-plus-lg',
                'url'   => APP_URL . '/index.php?page=sales&action=create',
                'attrs' => ['data-modal-open' => 'saleCreateModal'],
            ],
        ]));
    }

    /**
     * Sellable properties, eligible buyers and the agents who can close.
     *
     * The property and buyer lists carry the signed-in user's record scope, so
     * an agent closes deals on their own listings for their own clients. The
     * agent list is not scoped — naming the colleague who earns the commission
     * is the point of the field — but create() refuses to hand the deal to an
     * id that is not an active agent.
     *
     * @return array{properties:array,customers:array,agents:array}
     */
    private function formLookups(): array
    {
        $db = getDBConnection();

        [$propertyScope, $propertyParams] = propertyRecordScope('p');
        $properties = $db->prepare("
            SELECT p.id, p.title, p.property_code, p.price
            FROM properties p
            WHERE p.status = 'available' AND p.is_archived = 0
              AND p.property_type IN ('sale','both')
              AND ({$propertyScope})
            ORDER BY p.title
        ");
        $properties->execute($propertyParams);

        [$customerScope, $customerParams] = customerViewScope('c');
        $customers = $db->prepare("
            SELECT c.id, c.full_name, c.phone
            FROM customers c
            WHERE c.is_blacklisted = 0 AND ({$customerScope})
            ORDER BY c.full_name
        ");
        $customers->execute($customerParams);

        return [
            'properties' => $properties->fetchAll(),
            'customers'  => $customers->fetchAll(),
            'agents'     => $db->query("SELECT id, full_name FROM users WHERE role_id=2 AND is_active=1 ORDER BY full_name")->fetchAll(),
        ];
    }

    public function create(): void
    {
        authorize('sales.create');
        ['properties' => $properties, 'customers' => $customers, 'agents' => $agents] = $this->formLookups();

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            enforceCSRF();
            // A submit from the popup returns to the popup, so a rejected
            // entry is corrected where it was made.
            $failUrl = ($_POST['return_to'] ?? '') === 'modal'
                ? APP_URL . '/index.php?page=sales&modal=create'
                : APP_URL . '/index.php?page=sales&action=create';

            $data = [
                'property_id'       => (int)($_POST['property_id'] ?? 0),
                'customer_id'       => (int)($_POST['customer_id'] ?? 0),
                'sale_amount'       => (float)($_POST['sale_amount'] ?? 0),
                // Tax defaults to the configured rate but the agent can override the
                // figure; whatever is charged is stored with the rate it came from.
                'tax_amount'        => ($_POST['tax_amount'] ?? '') !== ''
                    ? (float)$_POST['tax_amount']
                    : round((float)($_POST['sale_amount'] ?? 0) * taxRate() / 100, 2),
                'tax_rate'          => taxRate(),
                'commission_amount' => (float)($_POST['commission_amount'] ?? 0),
                'payment_type'      => $_POST['payment_type'] ?? 'full',
                'status'            => $_POST['status'] ?? 'pending',
                'sale_date'         => $_POST['sale_date'] ?? date('Y-m-d'),
                'agent_id'          => (int)($_POST['agent_id'] ?? 0) ?: null,
                'notes'             => sanitize($_POST['notes'] ?? ''),
            ];
            // The same rules, each keyed to its field so the message lands
            // under the control rather than as one run-on flash.
            unset($_SESSION['form_errors']);
            $errors = [];

            if (!$data['property_id']) addFieldError($errors, 'property_id', 'Choose the property being sold.');
            if (!$data['customer_id']) addFieldError($errors, 'customer_id', 'Choose the buyer.');
            if ($data['sale_amount'] <= 0) {
                addFieldError($errors, 'sale_amount', 'The sale amount must be greater than zero.');
            }
            // A commission larger than the sale is a typo every time, and it
            // would be written into the commissions ledger as a real debt.
            if ($data['sale_amount'] > 0 && $data['commission_amount'] > $data['sale_amount']) {
                addFieldError($errors, 'commission_amount', 'Commission cannot exceed the sale amount.');
            }
            if ($data['commission_amount'] < 0) {
                addFieldError($errors, 'commission_amount', 'Commission cannot be negative.');
            }
            if (!isset(self::PAYMENT_TYPES[$data['payment_type']])) {
                addFieldError($errors, 'payment_type', 'Choose how the buyer is paying.');
            }
            if (!isset(self::STATUSES[$data['status']])) {
                addFieldError($errors, 'status', 'Choose where this deal has got to.');
            }
            // Level 3 on the write. The <select>s are already scoped, so an id
            // outside the user's reach was typed into the request by hand.
            if ($data['property_id'] && !canActOnProperty($data['property_id'])) {
                addFieldError($errors, 'property_id', 'That property is not one you manage.');
            }
            if ($data['customer_id'] && !canActOnCustomer($data['customer_id'])) {
                addFieldError($errors, 'customer_id', 'That buyer is not one of yours.');
            }
            // The commission line is a real debt, so the person it is written
            // against has to be a real, active agent rather than any user id
            // the form happened to carry.
            if ($data['agent_id'] && !$this->isActiveAgent((int) $data['agent_id'])) {
                addFieldError($errors, 'agent_id', 'That is not an active agent.');
            }
            if ($errors) {
                rejectForm($errors, $data, $failUrl);
            }
            $id = $this->model->create($data);
            if ($id) {
                logAudit('created_sale', 'sale', $id);
                setFlash('success', 'Sale recorded successfully.');
                redirect(APP_URL . '/index.php?page=sales&action=show&id=' . $id);
            }
            setFlash('error', 'Failed to record sale.');
            $_SESSION['form_data'] = $data;
            redirect($failUrl);
        }

        $formData = $_SESSION['form_data'] ?? [];
        unset($_SESSION['form_data']);

        renderPage(VIEWS_PATH . '/admin/sales/create.php', [
            'properties' => $properties, 'customers' => $customers, 'agents' => $agents,
            'formData'   => $formData,
            // The form's <option>s and the validator read one list.
            'paymentTypes' => self::PAYMENT_TYPES,
            'pageTitle' => 'New Sale',
            'breadcrumbs' => [
                ['label' => 'Sales', 'url' => APP_URL . '/index.php?page=sales'],
                ['label' => 'Create'],
            ],
        ]);
    }

    /** Whether a submitted agent id is an active member of the agent role. */
    private function isActiveAgent(int $userId): bool
    {
        $stmt = getDBConnection()->prepare(
            "SELECT 1 FROM users WHERE id = ? AND role_id = 2 AND is_active = 1 LIMIT 1"
        );
        $stmt->execute([$userId]);
        return (bool) $stmt->fetchColumn();
    }

    public function show(): void
    {
        authorize('sales.show');
        $id = (int)($_GET['id'] ?? 0);
        $sale = $this->model->findById($id);

        // Level 3. Holding sales.show opens the page; it does not open every
        // deal on it, and a deal carries the buyer, the price and the
        // commission. A missing sale and someone else's are refused alike.
        authorizeRecord(canViewSale($sale), 'sale', $id);

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
