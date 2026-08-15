<?php
/**
 * Customer Controller
 */
require_once BASE_PATH . '/models/Customer.php';

class CustomerController
{
    private Customer $model;

    public function __construct()
    {
        $this->model = new Customer();
    }

    public function index(): void
    {
        requireRole(ROLE_ADMIN, ROLE_AGENT);
        $filters = [
            'search'        => $_GET['search'] ?? '',
            'customer_type' => $_GET['customer_type'] ?? '',
        ];
        if (isset($_GET['blacklisted']) && $_GET['blacklisted'] !== '') {
            $filters['is_blacklisted'] = (int)$_GET['blacklisted'];
        }
        $page = max(1, (int)($_GET['p'] ?? 1));
        $offset = ($page - 1) * ITEMS_PER_PAGE;
        $customers = $this->model->getAll($filters, ITEMS_PER_PAGE, $offset);
        $totalCount = $this->model->count($filters);
        $totalPages = (int) ceil($totalCount / ITEMS_PER_PAGE);

        renderPage(VIEWS_PATH . '/admin/customers/index.php', [
            'customers'  => $customers,
            'filters'    => $filters,
            'page'       => $page,
            'totalPages' => $totalPages,
            'totalCount' => $totalCount,
            'pageTitle'  => 'Customers',
            'breadcrumbs'=> [['label' => 'Customers']],
            'actionButton' => ['label' => 'Add Customer', 'icon' => 'bi-plus-lg', 'url' => APP_URL . '/index.php?page=customers&action=create'],
        ]);
    }

    public function create(): void
    {
        requireRole(ROLE_ADMIN, ROLE_AGENT);
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            enforceCSRF();
            $data = $this->extractData();
            $errors = [];
            if (empty($data['full_name'])) $errors[] = 'Full name is required.';
            if (empty($data['phone'])) $errors[] = 'Phone is required.';
            if (!empty($errors)) {
                setFlash('error', implode(' ', $errors));
                $_SESSION['form_data'] = $data;
                redirect(APP_URL . '/index.php?page=customers&action=create');
            }
            if (!empty($_FILES['profile_photo']['name'])) {
                $data['profile_photo'] = uploadFile($_FILES['profile_photo'], 'avatars', ALLOWED_IMAGE_TYPES);
            }
            $id = $this->model->create($data);
            if ($id) {
                logAudit('created_customer', 'customer', $id);
                setFlash('success', 'Customer created successfully!');
                redirect(APP_URL . '/index.php?page=customers&action=show&id=' . $id);
            }
            setFlash('error', 'Failed to create customer.');
            redirect(APP_URL . '/index.php?page=customers&action=create');
        }
        $formData = $_SESSION['form_data'] ?? [];
        unset($_SESSION['form_data']);

        renderPage(VIEWS_PATH . '/admin/customers/create.php', [
            'formData'   => $formData,
            'pageTitle'  => 'New Customer',
            'breadcrumbs'=> [
                ['label' => 'Customers', 'url' => APP_URL . '/index.php?page=customers'],
                ['label' => 'Create'],
            ],
        ]);
    }

    public function edit(): void
    {
        requireRole(ROLE_ADMIN, ROLE_AGENT);
        $id = (int)($_GET['id'] ?? 0);
        $customer = $this->model->findById($id);
        if (!$customer) { setFlash('error', 'Customer not found.'); redirect(APP_URL . '/index.php?page=customers'); }

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            enforceCSRF();
            $data = $this->extractData();
            if (!empty($_FILES['profile_photo']['name'])) {
                $data['profile_photo'] = uploadFile($_FILES['profile_photo'], 'avatars', ALLOWED_IMAGE_TYPES);
            }
            $this->model->update($id, $data);
            logAudit('updated_customer', 'customer', $id);
            setFlash('success', 'Customer updated successfully!');
            redirect(APP_URL . '/index.php?page=customers&action=show&id=' . $id);
        }

        renderPage(VIEWS_PATH . '/admin/customers/edit.php', [
            'formData'   => $customer,
            'customer'   => $customer,
            'pageTitle'  => 'Edit Customer',
            'breadcrumbs'=> [
                ['label' => 'Customers', 'url' => APP_URL . '/index.php?page=customers'],
                ['label' => sanitize($customer['full_name']), 'url' => APP_URL . '/index.php?page=customers&action=show&id=' . $id],
                ['label' => 'Edit'],
            ],
        ]);
    }

    public function show(): void
    {
        requireRole(ROLE_ADMIN, ROLE_AGENT);
        $id = (int)($_GET['id'] ?? 0);
        $customer = $this->model->findById($id);
        if (!$customer) { setFlash('error', 'Customer not found.'); redirect(APP_URL . '/index.php?page=customers'); }
        $rentalHistory = $this->model->getRentalHistory($id);
        $paymentHistory = $this->model->getPaymentHistory($id);

        renderPage(VIEWS_PATH . '/admin/customers/show.php', [
            'customer'       => $customer,
            'rentalHistory'  => $rentalHistory,
            'paymentHistory' => $paymentHistory,
            'pageTitle'      => $customer['full_name'],
            'breadcrumbs'    => [
                ['label' => 'Customers', 'url' => APP_URL . '/index.php?page=customers'],
                ['label' => sanitize($customer['full_name'])],
            ],
        ]);
    }

    public function blacklist(): void
    {
        requireRole(ROLE_ADMIN);
        $id = (int)($_GET['id'] ?? 0);
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            enforceCSRF();
            $reason = $_POST['reason'] ?? 'other';
            $description = sanitize($_POST['description'] ?? '');
            $db = getDBConnection();
            $db->prepare("INSERT INTO blacklist_records (customer_id, reason, description, blacklisted_by) VALUES (?,?,?,?)")
               ->execute([$id, $reason, $description, $_SESSION['user_id'] ?? null]);
            $this->model->update($id, ['is_blacklisted' => 1]);
            logAudit('blacklisted_customer', 'customer', $id, '', $reason);
            setFlash('success', 'Customer blacklisted.');
        }
        redirect(APP_URL . '/index.php?page=customers&action=show&id=' . $id);
    }

    public function unlist(): void
    {
        requireRole(ROLE_ADMIN);
        $id = (int)($_GET['id'] ?? 0);
        $db = getDBConnection();
        $db->prepare("UPDATE blacklist_records SET is_active=0, lifted_at=NOW(), lifted_by=? WHERE customer_id=? AND is_active=1")
           ->execute([$_SESSION['user_id'] ?? null, $id]);
        $this->model->update($id, ['is_blacklisted' => 0]);
        logAudit('unblacklisted_customer', 'customer', $id);
        setFlash('success', 'Customer removed from blacklist.');
        redirect(APP_URL . '/index.php?page=customers&action=show&id=' . $id);
    }

    private function extractData(): array
    {
        return [
            'full_name'         => sanitize($_POST['full_name'] ?? ''),
            'email'             => sanitize($_POST['email'] ?? ''),
            'phone'             => sanitize($_POST['phone'] ?? ''),
            'address'           => sanitize($_POST['address'] ?? ''),
            'gender'            => $_POST['gender'] ?: null,
            'national_id'       => sanitize($_POST['national_id'] ?? ''),
            'emergency_contact' => sanitize($_POST['emergency_contact'] ?? ''),
            'emergency_phone'   => sanitize($_POST['emergency_phone'] ?? ''),
            'employment_status' => sanitize($_POST['employment_status'] ?? ''),
            'occupation'        => sanitize($_POST['occupation'] ?? ''),
            'guarantor_name'    => sanitize($_POST['guarantor_name'] ?? ''),
            'guarantor_contact' => sanitize($_POST['guarantor_contact'] ?? ''),
            'customer_type'     => $_POST['customer_type'] ?? 'both',
            'notes'             => sanitize($_POST['notes'] ?? ''),
            'risk_level'        => $_POST['risk_level'] ?? 'low',
        ];
    }
}
