<?php
/**
 * Inquiry Controller
 */
require_once BASE_PATH . '/models/Inquiry.php';

class InquiryController
{
    private Inquiry $model;

    public function __construct()
    {
        $this->model = new Inquiry();
    }

    public function index(): void
    {
        requireRole(ROLE_ADMIN, ROLE_AGENT);
        $filters = ['status' => $_GET['status'] ?? '', 'search' => $_GET['search'] ?? ''];
        $page = max(1, (int)($_GET['p'] ?? 1));
        $offset = ($page - 1) * ITEMS_PER_PAGE;
        $inquiries = $this->model->getAll($filters, ITEMS_PER_PAGE, $offset);
        $totalCount = $this->model->count($filters);
        $totalPages = (int) ceil($totalCount / ITEMS_PER_PAGE);

        renderPage(VIEWS_PATH . '/admin/inquiries/index.php', [
            'inquiries' => $inquiries, 'filters' => $filters,
            'page' => $page, 'totalPages' => $totalPages, 'totalCount' => $totalCount,
            'pageTitle' => 'Inquiries',
            'breadcrumbs' => [['label' => 'Inquiries']],
        ]);
    }

    public function show(): void
    {
        requireRole(ROLE_ADMIN, ROLE_AGENT);
        $id = (int)($_GET['id'] ?? 0);
        $inquiry = $this->model->findById($id);
        if (!$inquiry) { setFlash('error', 'Inquiry not found.'); redirect(APP_URL . '/index.php?page=inquiries'); }
        $messages = $this->model->getMessages($id);

        renderPage(VIEWS_PATH . '/admin/inquiries/show.php', [
            'inquiry' => $inquiry, 'messages' => $messages,
            'pageTitle' => 'Inquiry #' . $inquiry['id'],
            'breadcrumbs' => [
                ['label' => 'Inquiries', 'url' => APP_URL . '/index.php?page=inquiries'],
                ['label' => '#' . $inquiry['id']],
            ],
        ]);
    }

    public function reply(): void
    {
        requireRole(ROLE_ADMIN, ROLE_AGENT);
        $id = (int)($_GET['id'] ?? 0);
        enforceCSRF();
        $body = sanitize($_POST['body'] ?? '');
        if (!$body) {
            setFlash('error', 'Reply cannot be empty.');
            redirect(APP_URL . '/index.php?page=inquiries&action=show&id=' . $id);
        }
        $inquiry = $this->model->findById($id);
        // Find a user id to send the message to (use customer's user_id if available, otherwise the assigned user)
        $db = getDBConnection();
        $receiver = 0;
        if ($inquiry['customer_id']) {
            $stmt = $db->prepare("SELECT user_id FROM customers WHERE id = ?");
            $stmt->execute([$inquiry['customer_id']]);
            $receiver = (int)$stmt->fetchColumn();
        }
        if (!$receiver) $receiver = $_SESSION['user_id']; // store as self-message
        $this->model->addMessage($id, $_SESSION['user_id'], $receiver, $body);
        $this->model->update($id, ['status' => 'replied']);
        if ($receiver !== $_SESSION['user_id']) {
            notify($receiver, 'Reply to your inquiry', 'You have a new reply.', 'info', 'inquiry', $id);
        }
        logAudit('replied_inquiry', 'inquiry', $id);
        setFlash('success', 'Reply sent.');
        redirect(APP_URL . '/index.php?page=inquiries&action=show&id=' . $id);
    }

    public function create(): void
    {
        // Public/customer can submit an inquiry
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            enforceCSRF();
            $data = [
                'property_id' => (int)($_POST['property_id'] ?? 0) ?: null,
                'customer_id' => isLoggedIn() && getUserRole() === ROLE_CUSTOMER ? null : null,
                'name'        => sanitize($_POST['name'] ?? ''),
                'email'       => sanitize($_POST['email'] ?? ''),
                'phone'       => sanitize($_POST['phone'] ?? ''),
                'subject'     => sanitize($_POST['subject'] ?? ''),
                'message'     => sanitize($_POST['message'] ?? ''),
            ];
            if (!$data['message']) {
                setFlash('error', 'Message is required.');
                redirect(APP_URL . '/index.php?page=inquiries&action=create');
            }
            $id = $this->model->create($data);
            if ($id) {
                // Notify all admins
                foreach (getDBConnection()->query("SELECT id FROM users WHERE role_id=1")->fetchAll() as $a) {
                    notify((int)$a['id'], 'New Inquiry', $data['subject'] ?: 'New customer inquiry', 'info', 'inquiry', $id);
                }
                setFlash('success', 'Your inquiry has been sent.');
                redirect(APP_URL . '/index.php?page=dashboard');
            }
        }
        renderPage(VIEWS_PATH . '/admin/inquiries/create.php', [
            'pageTitle' => 'Send Inquiry',
            'breadcrumbs' => [['label' => 'Inquiry']],
        ]);
    }
}
