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
    /** Where a conversation has got to — the inquiries.status enum. */
    public const STATUSES = [
        'open'    => 'Open',
        'pending' => 'Pending',
        'replied' => 'Replied',
        'closed'  => 'Closed',
    ];

    private Inquiry $model;

    public function __construct()
    {
        $this->model = new Inquiry();
    }

    public function index(): void
    {
        authorize('inquiries.view');

        // The status is checked against the same list the pills are built
        // from, and the sort key resolves through Inquiry::SORTS. The search
        // term stays a bound parameter inside the model, ANDed with the
        // access scope rather than replacing it.
        $filters = [
            'status' => uiPick($_GET['status'] ?? '', array_keys(self::STATUSES)),
            'search' => trim((string) ($_GET['search'] ?? '')),
            'sort'   => uiSortValue(array_keys(Inquiry::SORTS), 'newest'),
        ];
        $page = max(1, (int)($_GET['p'] ?? 1));
        $offset = ($page - 1) * ITEMS_PER_PAGE;
        $inquiries = $this->model->getAll($filters, ITEMS_PER_PAGE, $offset);
        $totalCount = $this->model->count($filters);
        $totalPages = (int) ceil($totalCount / ITEMS_PER_PAGE);

        renderPage(VIEWS_PATH . '/admin/inquiries/index.php', [
            'inquiries' => $inquiries, 'filters' => $filters,
            'page' => $page, 'totalPages' => $totalPages, 'totalCount' => $totalCount,
            'statuses' => self::STATUSES,
            // One added query behind the status pills, carrying the same
            // access scope as the list itself — an owner's counts describe
            // their own correspondence, not the agency's whole inbox.
            'statusCounts' => $this->model->countsByStatus($filters),
            // Why this list holds what it holds, in the reader's own terms. A
            // user shown four of the agency's four hundred enquiries should be
            // able to read the reason rather than assume the page is broken.
            'scopeHint'    => inquiryScopeHint(),
            'emptyMessage' => inquiryEmptyScopeMessage(),
            'pageTitle' => $this->listTitle(),
            'pageSubtitle' => inquiryScopeHint(),
            'breadcrumbs' => [['label' => $this->listTitle()]],
            'actionButton' => can('inquiries.create') ? [
                'label' => 'New Inquiry',
                'icon'  => 'bi-plus-lg',
                'url'   => APP_URL . '/index.php?page=inquiries&action=create',
            ] : null,
        ]);
    }

    public function show(): void
    {
        authorize('inquiries.show');
        $id = (int)($_GET['id'] ?? 0);
        $inquiry = $this->model->findById($id);

        // Level 3. Holding inquiries.show opens the page; it does not open
        // every enquiry on it. Nothing above this line has read the row's
        // contents, and nothing below runs if the row is not theirs.
        //
        // A missing enquiry takes the same refusal as somebody else's. The
        // earlier "not found" redirect was an existence oracle: a real id
        // answered 403 and an invented one answered a redirect, which is
        // enough to map the table by walking `?id=`.
        authorizeRecord($inquiry !== null && canViewInquiry($inquiry), 'inquiry', $id);

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

        // Replying to an enquiry you are not allowed to read is the same
        // breach as reading it, so it is refused the same way.
        authorizeRecord($inquiry !== null && canViewInquiry($inquiry), 'inquiry', $id);

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
            // Keyed to their fields, so the message lands under the control
            // rather than as a flash above a form the user must re-read.
            unset($_SESSION['form_errors']);
            $errors  = [];
            $failUrl = APP_URL . '/index.php?page=inquiries&action=create';

            normalisePhoneFields($data);
            // No required list: an enquiry needs a name and a way to reply,
            // and which of email or phone that is stays this module's rule.
            validateSharedFields($data, $errors);

            if (!$data['message']) {
                addFieldError($errors, 'message', 'Write the enquiry itself — this is what the office will read.');
            }
            // Someone has to be reachable, or a reply has nowhere to go. A
            // signed-in customer is already on file, so this only bites the
            // enquiries typed on someone else's behalf.
            if (!$data['customer_id'] && $data['email'] === '' && $data['phone'] === '') {
                addFieldError($errors, 'email', 'Give an email address or a phone number so the enquiry can be answered.');
            }
            if ($data['email'] !== '' && !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
                addFieldError($errors, 'email', 'That does not look like an email address.');
            }
            if ($errors) {
                rejectForm($errors, $data, $failUrl);
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
        // so no record scope applies — anyone who can reach a listing can ask
        // about it. What does apply is the approval state: a listing still
        // waiting to go live is not advertised, and naming it in this form
        // published it to every customer who opened the page. Staff, who work
        // the register rather than the shop window, keep the full list.
        $publicOnly = !hasRole(ROLE_ADMIN, ROLE_AGENT);
        $properties = getDBConnection()->query("
            SELECT id, title, property_code
            FROM properties
            WHERE is_archived = 0 AND status = 'available'
            " . ($publicOnly ? "AND approval_status = 'approved'" : '') . "
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
