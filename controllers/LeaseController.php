<?php
/**
 * Lease Controller
 */
require_once BASE_PATH . '/models/Lease.php';
require_once BASE_PATH . '/models/Property.php';
require_once BASE_PATH . '/models/Customer.php';

class LeaseController
{
    /**
     * The tenancy lifecycle, keyed by the stored value. One list, read by the
     * status pills, the request validator and the label in a row.
     */
    private const STATUSES = [
        'active'     => 'Active',
        'renewed'    => 'Renewed',
        'expired'    => 'Expired',
        'terminated' => 'Terminated',
    ];

    /** Billing cadence — matches the leases.payment_schedule enum. */
    private const SCHEDULES = [
        'monthly'   => 'Monthly',
        'quarterly' => 'Quarterly',
        'yearly'    => 'Yearly',
    ];

    private Lease $model;

    public function __construct()
    {
        $this->model = new Lease();
        // Roll over overdue payments on every visit
        $this->model->markOverdue();
    }

    public function index(): void
    {
        authorize('leases.view');

        // Enumerated values are checked against the same list the pills are
        // built from; the sort key is resolved by Lease::SORTS. Neither the
        // status nor the sort ever reaches SQL as request text.
        $filters = [
            'status' => uiPick($_GET['status'] ?? '', array_keys(self::STATUSES)),
            'search' => trim((string) ($_GET['search'] ?? '')),
            'sort'   => uiSortValue(array_keys(Lease::SORTS), 'newest'),
        ];
        // The renewal queue: one saved view rather than a date picker nobody
        // fills in the same way twice.
        $ending = ($_GET['ending'] ?? '') === 'soon';
        if ($ending) {
            $filters['ending_within'] = 60;
        }

        $page = max(1, (int)($_GET['p'] ?? 1));
        $offset = ($page - 1) * ITEMS_PER_PAGE;
        $leases = $this->model->getAll($filters, ITEMS_PER_PAGE, $offset);
        $totalCount = $this->model->count($filters);
        $totalPages = (int) ceil($totalCount / ITEMS_PER_PAGE);

        // The quick-add popup lives on this page, so it needs the same option
        // lists the full form uses and the entry kept back after a reject.
        $formData = $_SESSION['form_data'] ?? [];
        unset($_SESSION['form_data']);

        renderPage(VIEWS_PATH . '/admin/leases/index.php', array_merge(self::formLookups(), [
            'leases' => $leases, 'filters' => $filters,
            'page' => $page, 'totalPages' => $totalPages, 'totalCount' => $totalCount,
            'formData' => $formData,
            'statuses' => self::STATUSES,
            'schedules' => self::SCHEDULES,
            'endingSoon' => $ending,
            // Two added queries, both fixed cost. countsByStatus() answers the
            // whole pill row in one GROUP BY; arrearsFor() answers the whole
            // page of rows in one WHERE … IN, rather than asking getArrears()
            // once per lease as a naive Arrears column would.
            'statusCounts' => $this->model->countsByStatus(),
            'arrears' => $this->model->arrearsFor(array_column($leases, 'id')),
            'openCreateModal' => ($_GET['modal'] ?? '') === 'create',
            'pageTitle' => 'Leases',
            'pageSubtitle' => 'Tenancies, what they are worth, and what is outstanding on them.',
            'breadcrumbs' => [['label' => 'Leases']],
            'actionButton' => [
                'label' => 'New Lease',
                'icon'  => 'bi-plus-lg',
                'url'   => APP_URL . '/index.php?page=leases&action=create',
                'attrs' => ['data-modal-open' => 'leaseCreateModal'],
            ],
        ]));
    }

    /**
     * Lettable properties and the customers allowed to hold a lease.
     * Reachable from outside the controller so the quick-add popup offers the
     * same lists wherever it is hosted.
     *
     * @return array{properties:array,customers:array}
     */
    public static function formLookups(): array
    {
        $db = getDBConnection();
        return [
            'properties' => $db->query("SELECT id, title, property_code, rent_amount, deposit_amount, owner_id FROM properties WHERE status='available' AND is_archived=0 AND property_type IN ('rent','both') ORDER BY created_at DESC")->fetchAll(),
            'customers'  => $db->query("SELECT id, full_name, phone FROM customers WHERE is_blacklisted=0 ORDER BY full_name")->fetchAll(),
        ];
    }

    public function create(): void
    {
        authorize('leases.create');
        $db = getDBConnection();
        ['properties' => $properties, 'customers' => $customers] = self::formLookups();

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            enforceCSRF();
            // A submit from a popup returns to that popup, so a rejected
            // entry is corrected where it was made.
            $failUrl = modalReturnUrl('leases', 'lease',
                APP_URL . '/index.php?page=leases&action=create');

            $data = [
                'customer_id'    => (int)($_POST['customer_id'] ?? 0),
                'property_id'    => (int)($_POST['property_id'] ?? 0),
                'start_date'     => $_POST['start_date'] ?? '',
                'end_date'       => $_POST['end_date'] ?? '',
                'rent_amount'    => (float)($_POST['rent_amount'] ?? 0),
                'deposit_amount' => (float)($_POST['deposit_amount'] ?? 0),
                'payment_schedule'=> $_POST['payment_schedule'] ?? 'monthly',
                'late_fee_rate'  => (float)(($_POST['late_fee_rate'] ?? '') !== '' ? $_POST['late_fee_rate'] : lateFeeRate()),
                'terms'          => sanitize($_POST['terms'] ?? ''),
                'move_in_date'   => $_POST['move_in_date'] ?: ($_POST['start_date'] ?? ''),
            ];
            // Look up owner of the chosen property
            $stmt = $db->prepare("SELECT owner_id FROM properties WHERE id = ?");
            $stmt->execute([$data['property_id']]);
            $data['owner_id'] = $stmt->fetchColumn() ?: null;

            // The same rules as before, each now carrying the field it belongs
            // to so the message lands under the control that caused it rather
            // than as one run-on sentence at the top of the page.
            unset($_SESSION['form_errors']);
            $errors = [];

            if (!$data['customer_id']) addFieldError($errors, 'customer_id', 'Select the tenant taking the lease.');
            if (!$data['property_id']) addFieldError($errors, 'property_id', 'Select the property being let.');
            if (!$data['start_date'])  addFieldError($errors, 'start_date', 'A start date is required.');
            if (!$data['end_date'])    addFieldError($errors, 'end_date', 'An end date is required.');
            if ($data['end_date'] && $data['start_date'] && $data['end_date'] <= $data['start_date']) {
                addFieldError($errors, 'end_date', 'The end date must fall after the start date.');
            }
            if ($data['rent_amount'] <= 0) {
                addFieldError($errors, 'rent_amount', 'Rent must be greater than zero.');
            }
            if (!isset(self::SCHEDULES[$data['payment_schedule']])) {
                addFieldError($errors, 'payment_schedule', 'Choose how often rent falls due.');
            }
            if ($data['property_id'] && $data['start_date'] && $data['end_date']
                && $this->model->hasOverlap($data['property_id'], $data['start_date'], $data['end_date'])) {
                addFieldError($errors, 'property_id', 'This property already has an active lease overlapping those dates.');
            }
            if (!empty($_FILES['contract_file']['name'])) {
                $path = uploadFile($_FILES['contract_file'], 'documents', ALLOWED_DOC_TYPES);
                if ($path) {
                    $data['contract_file'] = $path;
                } else {
                    // Silently dropping the file left someone believing the
                    // signed contract was attached when it was not.
                    addFieldError($errors, 'contract_file',
                        'That contract could not be attached. Check it is a permitted document type and within the size limit.');
                }
            }
            if ($errors) {
                rejectForm($errors, $data, $failUrl);
            }
            $id = $this->model->create($data);
            if ($id) {
                logAudit('created_lease', 'lease', $id);
                setFlash('success', 'Lease created and payment schedule generated.');
                redirect(APP_URL . '/index.php?page=leases&action=show&id=' . $id);
            }
            setFlash('error', 'Failed to create lease.');
            $_SESSION['form_data'] = $data;
            redirect($failUrl);
        }

        $formData = $_SESSION['form_data'] ?? [];
        unset($_SESSION['form_data']);

        renderPage(VIEWS_PATH . '/admin/leases/create.php', [
            'properties' => $properties, 'customers' => $customers,
            'formData'   => $formData,
            'pageTitle' => 'New Lease',
            'breadcrumbs' => [
                ['label' => 'Leases', 'url' => APP_URL . '/index.php?page=leases'],
                ['label' => 'Create'],
            ],
        ]);
    }

    public function show(): void
    {
        authorize('leases.show');
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
        authorize('leases.renew');
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
        authorize('leases.terminate');
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
