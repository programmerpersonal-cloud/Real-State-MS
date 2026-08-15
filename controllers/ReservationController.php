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
        requireRole(ROLE_ADMIN, ROLE_AGENT);
        $filters = ['status' => $_GET['status'] ?? '', 'search' => $_GET['search'] ?? ''];
        $page = max(1, (int)($_GET['p'] ?? 1));
        $offset = ($page - 1) * ITEMS_PER_PAGE;
        $reservations = $this->model->getAll($filters, ITEMS_PER_PAGE, $offset);
        $totalCount = $this->model->count($filters);
        $totalPages = (int) ceil($totalCount / ITEMS_PER_PAGE);

        renderPage(VIEWS_PATH . '/admin/reservations/index.php', [
            'reservations' => $reservations, 'filters' => $filters,
            'page' => $page, 'totalPages' => $totalPages, 'totalCount' => $totalCount,
            'pageTitle' => 'Reservations',
            'breadcrumbs' => [['label' => 'Reservations']],
            'actionButton' => ['label' => 'New Reservation', 'icon' => 'bi-plus-lg', 'url' => APP_URL . '/index.php?page=reservations&action=create'],
        ]);
    }

    public function create(): void
    {
        requireRole(ROLE_ADMIN, ROLE_AGENT, ROLE_CUSTOMER);
        $db = getDBConnection();
        $properties = $db->query("SELECT id, title, property_code FROM properties WHERE status='available' AND is_archived=0 ORDER BY title")->fetchAll();
        $customers = $db->query("SELECT id, full_name FROM customers ORDER BY full_name")->fetchAll();

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            enforceCSRF();
            $data = [
                'property_id'      => (int)$_POST['property_id'],
                'customer_id'      => (int)$_POST['customer_id'],
                'reservation_date' => $_POST['reservation_date'] ?? date('Y-m-d'),
                'expiry_date'      => $_POST['expiry_date'] ?: reservationExpiryDate(),
                'deposit_amount'   => (float)($_POST['deposit_amount'] ?? 0),
                'notes'            => sanitize($_POST['notes'] ?? ''),
            ];
            if (!$data['property_id'] || !$data['customer_id']) {
                setFlash('error', 'Property and customer are required.');
                redirect(APP_URL . '/index.php?page=reservations&action=create');
            }
            $id = $this->model->create($data);
            if ($id) {
                logAudit('created_reservation', 'reservation', $id);
                setFlash('success', 'Reservation created.');
                redirect(APP_URL . '/index.php?page=reservations');
            }
            setFlash('error', 'Failed to reserve property.');
            redirect(APP_URL . '/index.php?page=reservations&action=create');
        }

        renderPage(VIEWS_PATH . '/admin/reservations/create.php', [
            'properties' => $properties, 'customers' => $customers,
            'pageTitle' => 'New Reservation',
            'breadcrumbs' => [
                ['label' => 'Reservations', 'url' => APP_URL . '/index.php?page=reservations'],
                ['label' => 'Create'],
            ],
        ]);
    }

    public function cancel(): void
    {
        requireRole(ROLE_ADMIN, ROLE_AGENT);
        $id = (int)($_GET['id'] ?? 0);
        $this->model->cancel($id);
        logAudit('cancelled_reservation', 'reservation', $id);
        setFlash('success', 'Reservation cancelled.');
        redirect(APP_URL . '/index.php?page=reservations');
    }

    public function confirm(): void
    {
        requireRole(ROLE_ADMIN, ROLE_AGENT);
        $id = (int)($_GET['id'] ?? 0);
        $this->model->confirm($id);
        logAudit('confirmed_reservation', 'reservation', $id);
        setFlash('success', 'Reservation confirmed.');
        redirect(APP_URL . '/index.php?page=reservations');
    }
}
