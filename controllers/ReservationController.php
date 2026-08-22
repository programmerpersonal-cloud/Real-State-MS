<?php
/**
 * Reservation Controller
 */
require_once BASE_PATH . '/models/Reservation.php';

class ReservationController
{
    /**
     * The reservation lifecycle, keyed by the stored value.
     *
     * One list, read by three things: the status filter pills, the request
     * validator, and the label shown in a row. They cannot disagree, because
     * there is nothing for them to disagree with.
     */
    private const STATUSES = [
        'active'    => 'Active',
        'confirmed' => 'Confirmed',
        'expired'   => 'Expired',
        'cancelled' => 'Cancelled',
    ];

    private Reservation $model;

    public function __construct()
    {
        $this->model = new Reservation();
        $this->model->expireOld();
    }

    public function index(): void
    {
        authorize('reservations.view');

        // The status comes back only if it is one of ours, and the sort key is
        // resolved by Reservation::SORTS rather than reaching the query as
        // text. The search term stays a bound parameter inside the model.
        $filters = [
            'status' => uiPick($_GET['status'] ?? '', array_keys(self::STATUSES)),
            'search' => trim((string) ($_GET['search'] ?? '')),
            'sort'   => uiSortValue(array_keys(Reservation::SORTS), 'newest'),
        ];

        $page = max(1, (int)($_GET['p'] ?? 1));
        $offset = ($page - 1) * ITEMS_PER_PAGE;
        $reservations = $this->model->getAll($filters, ITEMS_PER_PAGE, $offset);
        $totalCount = $this->model->count($filters);
        $totalPages = (int) ceil($totalCount / ITEMS_PER_PAGE);

        // The quick-add popup lives on this page, so it needs the same
        // option lists the full form uses — and the entry kept back after a
        // failed submit, so the popup can reopen where the user left off.
        $formData = $_SESSION['form_data'] ?? [];
        unset($_SESSION['form_data']);

        renderPage(VIEWS_PATH . '/admin/reservations/index.php', array_merge($this->formLookups(), [
            'reservations' => $reservations, 'filters' => $filters,
            'page' => $page, 'totalPages' => $totalPages, 'totalCount' => $totalCount,
            'formData' => $formData,
            'statuses' => self::STATUSES,
            // One grouped query behind the status pills. It is the page's only
            // added cost and does not grow with the number of rows shown.
            'statusCounts' => $this->model->countsByStatus($filters),
            'openCreateModal' => ($_GET['modal'] ?? '') === 'create',
            'pageTitle' => 'Reservations',
            // Says whose holds these are, in the reader's own terms.
            'pageSubtitle' => recordScopeHint('hold'),
            'breadcrumbs' => [['label' => 'Reservations']],
            'actionButton' => [
                'label' => 'New Reservation',
                'icon'  => 'bi-plus-lg',
                'url'   => APP_URL . '/index.php?page=reservations&action=create',
                'attrs' => ['data-modal-open' => 'reservationCreateModal'],
            ],
        ]));
    }

    /**
     * Reservable properties and the customers who can hold them.
     *
     * A buyer holds a property in their own name and nobody else's, so their
     * customer list is exactly one entry — themselves. Staff get their scoped
     * client list. The property list is deliberately *not* cut to the user's
     * portfolio: reserving a listing is how a buyer expresses interest in one
     * they do not yet have any relationship with, so every publicly visible
     * listing stays reservable, and everything else is refused.
     *
     * @return array{properties:array,customers:array}
     */
    private function formLookups(): array
    {
        $db = getDBConnection();

        // Staff work the whole register, including listings still awaiting
        // approval. A buyer is offered what the public site already shows
        // them, so an unapproved listing does not leak through this form.
        $publicOnly = !hasRole(ROLE_ADMIN, ROLE_AGENT);
        $properties = $db->query("
            SELECT id, title, property_code
            FROM properties
            WHERE status = 'available' AND is_archived = 0
            " . ($publicOnly ? "AND approval_status = 'approved'" : '') . "
            ORDER BY title
        ")->fetchAll();

        [$customerScope, $customerParams] = customerViewScope('c');
        $customers = $db->prepare("
            SELECT c.id, c.full_name
            FROM customers c
            WHERE ({$customerScope})
            ORDER BY c.full_name
        ");
        $customers->execute($customerParams);

        return [
            'properties' => $properties,
            'customers'  => $customers->fetchAll(),
        ];
    }

    public function create(): void
    {
        authorize('reservations.create');
        ['properties' => $properties, 'customers' => $customers] = $this->formLookups();

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            enforceCSRF();
            // A submit from the popup returns to the popup, so a rejected
            // entry is corrected where it was made.
            $failUrl = ($_POST['return_to'] ?? '') === 'modal'
                ? APP_URL . '/index.php?page=reservations&modal=create'
                : APP_URL . '/index.php?page=reservations&action=create';

            $data = [
                'property_id'      => (int)($_POST['property_id'] ?? 0),
                // A buyer reserves in their own name. Reading the id from the
                // session rather than the form is what stops a signed-in
                // customer placing — and paying a deposit on — a hold against
                // somebody else's account by editing one hidden field.
                'customer_id'      => getUserRole() === ROLE_CUSTOMER
                    ? (int) currentCustomerId()
                    : (int)($_POST['customer_id'] ?? 0),
                'reservation_date' => $_POST['reservation_date'] ?: date('Y-m-d'),
                'expiry_date'      => $_POST['expiry_date'] ?: reservationExpiryDate(),
                'deposit_amount'   => (float)($_POST['deposit_amount'] ?? 0),
                'notes'            => sanitize($_POST['notes'] ?? ''),
            ];

            // Errors are collected rather than thrown one at a time, so a form
            // with two problems reports both instead of sending the user round
            // the loop twice. Each one is keyed to its field, which is what
            // puts the message under the control that caused it.
            unset($_SESSION['form_errors']);
            $errors = [];

            if (!$data['property_id']) {
                addFieldError($errors, 'property_id', 'Choose the property being reserved.');
            }
            if (!$data['customer_id']) {
                addFieldError($errors, 'customer_id', getUserRole() === ROLE_CUSTOMER
                    ? 'Your account is not linked to a customer record, so a hold cannot be placed. Contact the office.'
                    : 'Choose the customer holding the reservation.');
            }
            // Level 3 on the write, for the roles that choose the customer.
            // A buyer's id came from the session above and needs no check.
            if ($data['customer_id'] && getUserRole() !== ROLE_CUSTOMER
                && !canActOnCustomer($data['customer_id'])) {
                addFieldError($errors, 'customer_id', 'That customer is not one of yours.');
            }
            // The property must be one the form was allowed to offer.
            if ($data['property_id'] && !$this->isReservable($data['property_id'])) {
                addFieldError($errors, 'property_id', 'That property is not available to reserve.');
            }
            // A hold that expires before it starts is expired the moment it is
            // written — expireOld() would cancel it on the next page load — so
            // it is refused here rather than created and immediately undone.
            if ($data['expiry_date'] < $data['reservation_date']) {
                addFieldError($errors, 'expiry_date', 'The expiry date cannot fall before the reservation date.');
            }
            if ($data['deposit_amount'] < 0) {
                addFieldError($errors, 'deposit_amount', 'A deposit cannot be negative.');
            }

            if ($errors) {
                rejectForm($errors, $data, $failUrl);
            }

            $id = $this->model->create($data);
            if ($id) {
                logAudit('created_reservation', 'reservation', $id);

                redirect(APP_URL . '/index.php?page=reservations');
            }
            setFlash('error', 'Failed to reserve property.');
            $_SESSION['form_data'] = $data;
            redirect($failUrl);
        }

        $formData = $_SESSION['form_data'] ?? [];
        unset($_SESSION['form_data']);

        renderPage(VIEWS_PATH . '/admin/reservations/create.php', [
            'properties' => $properties, 'customers' => $customers,
            'formData'   => $formData,
            'pageTitle' => 'New Reservation',
            'breadcrumbs' => [
                ['label' => 'Reservations', 'url' => APP_URL . '/index.php?page=reservations'],
                ['label' => 'Create'],
            ],
        ]);
    }

    /**
     * Whether a submitted property id is one this user's form could have
     * offered — the same predicate formLookups() builds its options from, so
     * a hand-edited id is refused rather than silently accepted.
     */
    private function isReservable(int $propertyId): bool
    {
        if ($propertyId <= 0) {
            return false;
        }
        $publicOnly = !hasRole(ROLE_ADMIN, ROLE_AGENT);
        $stmt = getDBConnection()->prepare("
            SELECT 1 FROM properties
            WHERE id = ? AND status = 'available' AND is_archived = 0
            " . ($publicOnly ? "AND approval_status = 'approved'" : '') . "
            LIMIT 1
        ");
        $stmt->execute([$propertyId]);
        return (bool) $stmt->fetchColumn();
    }

    /**
     * Cancel a hold and, if nothing else is holding it, free the property.
     *
     * Both this and confirm() used to be plain links. A link that changes a
     * record is a link a browser prefetcher, a link scanner or a third-party
     * page can fire without the user ever clicking it, so the change now
     * requires a POST carrying the session's CSRF token — the same shape the
     * customer and owner login actions already use. A stray GET falls through
     * to the redirect and changes nothing.
     */
    public function cancel(): void
    {
        authorize('reservations.cancel');
        $id = (int)($_GET['id'] ?? 0);

        // Level 3. Releasing a hold frees the property for someone else to
        // take, so it is refused for a reservation this desk does not run.
        authorizeRecord(canManageReservation($this->model->findById($id)), 'reservation', $id);

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            enforceCSRF();
            if ($this->model->cancel($id)) {
                logAudit('cancelled_reservation', 'reservation', $id);
                setFlash('success', 'Reservation cancelled. The property is available again unless another hold is on it.');
            } else {
                setFlash('error', 'That reservation no longer exists.');
            }
        }
        redirect(APP_URL . '/index.php?page=reservations');
    }

    public function confirm(): void
    {
        authorize('reservations.confirm');
        $id = (int)($_GET['id'] ?? 0);

        authorizeRecord(canManageReservation($this->model->findById($id)), 'reservation', $id);

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            enforceCSRF();
            $this->model->confirm($id);
            logAudit('confirmed_reservation', 'reservation', $id);
            setFlash('success', 'Reservation confirmed.');
        }
        redirect(APP_URL . '/index.php?page=reservations');
    }
}
