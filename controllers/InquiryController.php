<?php
/**
 * Inquiry Controller
 *
 * Open to everyone who has a legitimate interest in an enquiry, which is more
 * roles than it used to be. The module is no longer staff-only; what differs
 * per role is which rows come back (inquiryViewScope) and what may be done to
 * them (the permission matrix). An owner reads interest in their properties,
 * a tenant follows their own correspondence, the agency works the queue.
 *
 * Every action names the permission it needs rather than the job titles that
 * happen to hold it, so granting an existing role one more capability is an
 * edit to the matrix and nothing else.
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
        authorize('inquiries.view');
        $filters = ['status' => $_GET['status'] ?? '', 'search' => $_GET['search'] ?? ''];
        $page = max(1, (int)($_GET['p'] ?? 1));
        $offset = ($page - 1) * ITEMS_PER_PAGE;
        $inquiries = $this->model->getAll($filters, ITEMS_PER_PAGE, $offset);
        $totalCount = $this->model->count($filters);
        $totalPages = (int) ceil($totalCount / ITEMS_PER_PAGE);

        renderPage(VIEWS_PATH . '/admin/inquiries/index.php', [
            'inquiries' => $inquiries, 'filters' => $filters,
            'page' => $page, 'totalPages' => $totalPages, 'totalCount' => $totalCount,
            // Why this list holds what it holds, in the reader's own terms. A
            // user shown four of the agency's four hundred enquiries should be
            // able to read the reason rather than assume the page is broken.
            'scopeHint'    => inquiryScopeHint(),
            'emptyMessage' => inquiryEmptyScopeMessage(),
            'pageTitle' => $this->listTitle(),
            'breadcrumbs' => [['label' => $this->listTitle()]],
        ]);
    }

    public function show(): void
    {
        authorize('inquiries.show');
        $id = (int)($_GET['id'] ?? 0);
        $inquiry = $this->model->findById($id);
        if (!$inquiry) { setFlash('error', 'Inquiry not found.'); redirect(APP_URL . '/index.php?page=inquiries'); }

        // Level 3. Holding inquiries.show opens the page; it does not open
        // every enquiry on it. Nothing above this line has read the row's
        // contents, and nothing below runs if the row is not theirs.
        authorizeRecord(canViewInquiry($inquiry), 'inquiry', $id);

        $messages = $this->model->getMessages($id);

        renderPage(VIEWS_PATH . '/admin/inquiries/show.php', [
            'inquiry' => $inquiry, 'messages' => $messages,
            'pageTitle' => 'Inquiry #' . $inquiry['id'],
            'breadcrumbs' => [
                ['label' => $this->listTitle(), 'url' => APP_URL . '/index.php?page=inquiries'],
                ['label' => '#' . $inquiry['id']],
            ],
        ]);
    }

    public function reply(): void
    {
        authorize('inquiries.reply');
        $id = (int)($_GET['id'] ?? 0);
        enforceCSRF();
        $body = sanitize($_POST['body'] ?? '');
        if (!$body) {
            setFlash('error', 'Reply cannot be empty.');
            redirect(APP_URL . '/index.php?page=inquiries&action=show&id=' . $id);
        }
        $inquiry = $this->model->findById($id);
        if (!$inquiry) { setFlash('error', 'Inquiry not found.'); redirect(APP_URL . '/index.php?page=inquiries'); }

        // Replying to an enquiry you are not allowed to read is the same
        // breach as reading it, so it is refused the same way.
        authorizeRecord(canViewInquiry($inquiry), 'inquiry', $id);

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
        authorize('inquiries.create');

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            enforceCSRF();
            $data = [
                'property_id' => (int)($_POST['property_id'] ?? 0) ?: null,
                'customer_id' => currentCustomerId(),
                'name'        => sanitize($_POST['name'] ?? ''),
                'email'       => sanitize($_POST['email'] ?? ''),
                'phone'       => sanitize($_POST['phone'] ?? ''),
                'subject'     => sanitize($_POST['subject'] ?? ''),
                'message'     => sanitize($_POST['message'] ?? ''),
            ];
            if (!$data['message']) {
                // Hand the entry back rather than making them retype it.
                $_SESSION['form_data'] = $data;
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
                // Back to the list, where the sender can now watch for a
                // reply — the scope shows them their own enquiry.
                redirect(APP_URL . '/index.php?page=inquiries');
            }
            $_SESSION['form_data'] = $data;
            setFlash('error', 'The inquiry could not be saved. Please try again.');
        }

        // Properties open to enquiry are the ones already advertised publicly,
        // so no access scope applies — anyone who can reach a listing can ask
        // about it.
        $properties = getDBConnection()->query("
            SELECT id, title, property_code
            FROM properties
            WHERE is_archived = 0 AND status = 'available'
            ORDER BY title
        ")->fetchAll();

        renderPage(VIEWS_PATH . '/admin/inquiries/create.php', [
            'properties'  => $properties,
            'pageTitle'   => 'Send Inquiry',
            'breadcrumbs' => [
                ['label' => $this->listTitle(), 'url' => APP_URL . '/index.php?page=inquiries'],
                ['label' => 'New'],
            ],
        ]);
    }

    /**
     * What this list is called for the person reading it — the agency's inbox,
     * an owner's interest, a tenant's own correspondence. Matches the sidebar
     * wording so the page title and the link that reached it agree.
     */
    private function listTitle(): string
    {
        switch (getUserRole()) {
            case ROLE_OWNER:    return 'Property Inquiries';
            case ROLE_CUSTOMER: return 'My Inquiries';
        }
        return 'Inquiries';
    }
}
