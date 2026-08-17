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
            'statusCounts' => $this->model->countsByStatus(),
            'openCreateModal' => ($_GET['modal'] ?? '') === 'create',
            'pageTitle' => 'Reservations',
            'pageSubtitle' => 'Holds placed on properties, and how long each one has left to run.',
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
     * @return array{properties:array,customers:array}
     */
    private function formLookups(): array
    {
        $db = getDBConnection();
        return [
            'properties' => $db->query("SELECT id, title, property_code FROM properties WHERE status='available' AND is_archived=0 ORDER BY title")->fetchAll(),
            'customers'  => $db->query("SELECT id, full_name FROM customers ORDER BY full_name")->fetchAll(),
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
                'customer_id'      => (int)($_POST['customer_id'] ?? 0),
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
                addFieldError($errors, 'customer_id', 'Choose the customer holding the reservation.');
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

            // Booking terms, when a version is published and acceptance is on.
            $bookingTerms = termsRequiredForBooking();
            if ($bookingTerms) {
                $data['terms_accepted'] = !empty($_POST['terms_accepted']) ? 1 : 0;

                if (!$data['terms_accepted']) {
                    addFieldError($errors, 'terms_accepted',
                        'The booking terms must be accepted before a reservation can be created.');
                }

                // A version published while this form sat open is a different
                // agreement from the one on screen, so the stale submission is
                // refused rather than recorded against the wrong wording. This
                // one keeps its own flash: it is not the user's mistake, and
                // the wording has to explain why an accepted box was rejected.
                if ($data['terms_accepted'] && (int) ($_POST['terms_version_id'] ?? 0) !== (int) $bookingTerms['id']) {
                    setFlash('warning', 'The booking terms were updated while this form was open. '
                        . 'Please read the current version and accept it again.');
                    $_SESSION['form_data'] = $data;
                    redirect($failUrl);
                }
            }

            if ($errors) {
                rejectForm($errors, $data, $failUrl);
            }

            $id = $this->model->create($data);
            if ($id) {
                logAudit('created_reservation', 'reservation', $id);

                if ($bookingTerms) {
                    require_once BASE_PATH . '/models/TermsAcceptance.php';
                    (new TermsAcceptance())->record((int) $bookingTerms['id'], [
                        'user_id'        => $_SESSION['user_id'] ?? null,
                        'customer_id'    => (int) $data['customer_id'],
                        'reference_type' => 'reservation',
                        'reference_id'   => $id,
                        'accepted_name'  => $this->customerName((int) $data['customer_id']),
                        'method'         => 'checkbox',
                    ]);
                    logAudit('accepted_terms', 'terms_version', (int) $bookingTerms['id'], '', 'reservation:' . $id);
                }

                setFlash('success', 'Reservation created.');
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
     * The customer's name at the moment of acceptance.
     *
     * Snapshotted into the acceptance record so the log still reads correctly
     * if the customer is later renamed or removed.
     */
    private function customerName(int $customerId): string
    {
        $stmt = getDBConnection()->prepare("SELECT full_name FROM customers WHERE id = ?");
        $stmt->execute([$customerId]);
        return (string) ($stmt->fetchColumn() ?: '');
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

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            enforceCSRF();
            $this->model->confirm($id);
            logAudit('confirmed_reservation', 'reservation', $id);
            setFlash('success', 'Reservation confirmed.');
        }
        redirect(APP_URL . '/index.php?page=reservations');
    }
}
