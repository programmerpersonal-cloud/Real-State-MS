<?php
/**
 * Reservation Controller
 */
require_once BASE_PATH . '/models/Reservation.php';

class ReservationController
{
    private Reservation $model;

    public function __construct()
    {
        $this->model = new Reservation();
        $this->model->expireOld();
    }

    public function index(): void
    {
        authorize('reservations.view');
        $filters = ['status' => $_GET['status'] ?? '', 'search' => $_GET['search'] ?? ''];
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
            'openCreateModal' => ($_GET['modal'] ?? '') === 'create',
            'pageTitle' => 'Reservations',
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
            if (!$data['property_id'] || !$data['customer_id']) {
                setFlash('error', 'Property and customer are required.');
                $_SESSION['form_data'] = $data;
                redirect($failUrl);
            }

            // Booking terms, when a version is published and acceptance is on.
            $bookingTerms = termsRequiredForBooking();
            if ($bookingTerms) {
                $data['terms_accepted'] = !empty($_POST['terms_accepted']) ? 1 : 0;

                if (!$data['terms_accepted']) {
                    setFlash('error', 'The booking terms must be accepted before a reservation can be created.');
                    $_SESSION['form_data'] = $data;
                    redirect($failUrl);
                }

                // A version published while this form sat open is a different
                // agreement from the one on screen, so the stale submission is
                // refused rather than recorded against the wrong wording.
                if ((int) ($_POST['terms_version_id'] ?? 0) !== (int) $bookingTerms['id']) {
                    setFlash('warning', 'The booking terms were updated while this form was open. '
                        . 'Please read the current version and accept it again.');
                    $_SESSION['form_data'] = $data;
                    redirect($failUrl);
                }
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

    public function cancel(): void
    {
        authorize('reservations.cancel');
        $id = (int)($_GET['id'] ?? 0);
        $this->model->cancel($id);
        logAudit('cancelled_reservation', 'reservation', $id);
        setFlash('success', 'Reservation cancelled.');
        redirect(APP_URL . '/index.php?page=reservations');
    }

    public function confirm(): void
    {
        authorize('reservations.confirm');
        $id = (int)($_GET['id'] ?? 0);
        $this->model->confirm($id);
        logAudit('confirmed_reservation', 'reservation', $id);
        setFlash('success', 'Reservation confirmed.');
        redirect(APP_URL . '/index.php?page=reservations');
    }
}
