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
            'statusCounts' => $this->model->countsByStatus($filters),
            'arrears' => $this->model->arrearsFor(array_column($leases, 'id')),
            'openCreateModal' => ($_GET['modal'] ?? '') === 'create',
            'pageTitle' => 'Leases',
            // Says whose tenancies these are. An agent shown four of the
            // agency's four hundred should be able to read the reason rather
            // than assume the page is broken.
            'pageSubtitle' => recordScopeHint('tenancy'),
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
     * Both lists carry the signed-in user's record scope, so an agent is
     * offered their own properties and their own clients rather than the whole
     * register. This is a courtesy to the UI, not the boundary — create()
     * re-checks the submitted ids before anything is written.
     *
     * @return array{properties:array,customers:array}
     */
    public static function formLookups(): array
    {
        $db = getDBConnection();

        [$propertyScope, $propertyParams] = propertyRecordScope('p');
        $properties = $db->prepare("
            SELECT p.id, p.title, p.property_code, p.rent_amount, p.deposit_amount, p.owner_id
            FROM properties p
            WHERE p.status = 'available' AND p.is_archived = 0
              AND p.property_type IN ('rent','both')
              AND ({$propertyScope})
            ORDER BY p.created_at DESC
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
            // Level 3 on the write. The <select>s above are already scoped, so
            // an id outside the user's reach arrived by hand-editing the form
            // — refused with the same wording whether the record is someone
            // else's or does not exist, so the response cannot be used to probe
            // which ids are real.
            if ($data['property_id'] && !canActOnProperty($data['property_id'])) {
                addFieldError($errors, 'property_id', 'That property is not one you manage.');
            }
            if ($data['customer_id'] && !canActOnCustomer($data['customer_id'])) {
                addFieldError($errors, 'customer_id', 'That customer is not one of yours.');
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

        // Level 3. Holding leases.show opens the page; it does not open every
        // tenancy on it. A lease that does not exist and one belonging to
        // another desk are refused identically — distinguishing them would
        // confirm that a given lease code is real, which is itself a
        // disclosure. Nothing below runs if the row is not theirs.
        authorizeRecord(canViewLease($lease), 'lease', $id);

        $schedule = $this->model->getPaymentSchedule($id);
        $arrears  = $this->model->getArrears($id);

        renderPage(VIEWS_PATH . '/admin/leases/show.php', [
            'lease' => $lease, 'schedule' => $schedule, 'arrears' => $arrears,
            // The renew and terminate controls are drawn from the same answer
            // the actions enforce, so a button offered is a button that works.
            'canManage' => canManageLease($lease),
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

        // Fetched and checked before the form is drawn as well as before the
        // change is written: renewing a tenancy you may not read is the same
        // breach as reading it, so it is refused the same way.
        $lease = $this->model->findById($id);
        authorizeRecord(canManageLease($lease), 'lease', $id);

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            enforceCSRF();
            $newEnd = $_POST['end_date'];
            $newRent = !empty($_POST['rent_amount']) ? (float)$_POST['rent_amount'] : null;
            $this->model->renew($id, $newEnd, $newRent);
            logAudit('renewed_lease', 'lease', $id);
            setFlash('success', 'Lease renewed.');
            redirect(APP_URL . '/index.php?page=leases&action=show&id=' . $id);
        }
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

        // Ending someone else's tenancy is the most destructive thing this
        // module can do, and it was reachable by posting an id.
        $lease = $this->model->findById($id);
        authorizeRecord(canManageLease($lease), 'lease', $id);

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
