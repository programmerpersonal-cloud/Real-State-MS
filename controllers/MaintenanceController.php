<?php
/**
 * Maintenance Controller
 *
 * Authorization runs at all three levels described in includes/permissions.php:
 *
 *   1. authorize('maintenance.x')  may this role use this action at all
 *   2. authorizeRecord(...)        may this user touch this particular request
 *   3. the scope predicate         which properties the row may reference,
 *                                  enforced inside the SQL that writes it
 *
 * Level 3 is the one that cannot be bypassed: MaintenanceRequest::create()
 * carries the property scope into the INSERT itself. The checks in here exist
 * to produce a readable refusal and to stop work — file uploads, notifications
 * — before it starts. Removing one would cost a good error message, not the
 * guarantee.
 */
require_once BASE_PATH . '/models/MaintenanceRequest.php';

class MaintenanceController
{
    /** The job lifecycle — the maintenance_requests.status enum, in order. */
    public const STATUSES = [
        'new'          => 'New',
        'under_review' => 'Under review',
        'assigned'     => 'Assigned',
        'in_progress'  => 'In progress',
        'completed'    => 'Completed',
        'rejected'     => 'Rejected',
        'cancelled'    => 'Cancelled',
    ];

    /** How urgent, most pressing first — the priority enum. */
    public const PRIORITIES = [
        'urgent' => 'Urgent',
        'high'   => 'High',
        'medium' => 'Medium',
        'low'    => 'Low',
    ];

    private MaintenanceRequest $model;

    public function __construct()
    {
        $this->model = new MaintenanceRequest();
    }

    public function index(): void
    {
        authorize('maintenance.view');
        // The list itself is scoped inside the model, so no role branching is
        // needed here: an owner's query returns their properties' requests,
        // a technician's returns their jobs, an admin's returns everything.
        //
        // Status and priority are checked against the same maps their controls
        // are built from, and the sort key resolves through
        // MaintenanceRequest::SORTS. Neither reaches SQL as request text.
        $filters = [
            'status'   => uiPick($_GET['status'] ?? '', array_keys(self::STATUSES)),
            'priority' => uiPick($_GET['priority'] ?? '', array_keys(self::PRIORITIES)),
            'search'   => trim((string) ($_GET['search'] ?? '')),
            'sort'     => uiSortValue(array_keys(MaintenanceRequest::SORTS), 'priority'),
        ];
        $page = max(1, (int)($_GET['p'] ?? 1));
        $offset = ($page - 1) * ITEMS_PER_PAGE;
        $requests = $this->model->getAll($filters, ITEMS_PER_PAGE, $offset);
        $totalCount = $this->model->count($filters);
        $totalPages = (int) ceil($totalCount / ITEMS_PER_PAGE);

        // The quick-add popup lives on this page, so it needs the property
        // list the full form uses and the entry kept back after a reject.
        $formData = $_SESSION['form_data'] ?? [];
        unset($_SESSION['form_data']);

        $properties = maintenanceSelectableProperties();
        // Both levels have to hold before the form is worth offering: the role
        // must own the action, and there must be a property to file against.
        // Either one missing makes the button a dead end.
        $canCreate = can('maintenance.create') && $properties !== [];

        renderPage(VIEWS_PATH . '/admin/maintenance/index.php', [
            'requests' => $requests, 'filters' => $filters,
            'page' => $page, 'totalPages' => $totalPages, 'totalCount' => $totalCount,
            'properties' => $properties,
            'canCreate'  => $canCreate,
            'formData'   => $formData,
            'statuses'   => self::STATUSES,
            'priorities' => self::PRIORITIES,
            // One added query behind the status pills: a GROUP BY carrying the
            // same access scope as the list, so a technician's counts describe
            // their own queue rather than the whole company's workload.
            'statusCounts' => $this->model->countsByStatus($filters),
            'openCreateModal' => ($_GET['modal'] ?? '') === 'create',
            'pageTitle' => 'Maintenance Requests',
            'pageSubtitle' => 'The work queue, most urgent first.',
            'breadcrumbs' => [['label' => 'Maintenance']],
            'actionButton' => $canCreate ? [
                'label' => 'New Request',
                'icon'  => 'bi-plus-lg',
                'url'   => APP_URL . '/index.php?page=maintenance&action=create',
                'attrs' => ['data-modal-open' => 'maintenanceCreateModal'],
            ] : null,
        ]);
    }

    public function create(): void
    {
        authorize('maintenance.create');
        $db = getDBConnection();
        $properties = maintenanceSelectableProperties();

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            enforceCSRF();
            // A submit from a popup returns to that popup, so a rejected
            // entry is corrected where it was typed.
            $failUrl = modalReturnUrl('maintenance', 'maintenance',
                APP_URL . '/index.php?page=maintenance&action=create');

            $data = [
                'property_id' => (int)($_POST['property_id'] ?? 0),
                'issue_type'  => sanitize($_POST['issue_type'] ?? ''),
                'description' => sanitize($_POST['description'] ?? ''),
                'priority'    => $_POST['priority'] ?? 'medium',
            ];

            // Keyed to their fields so each message lands under the control
            // that caused it, and both appear at once rather than one per trip.
            unset($_SESSION['form_errors']);
            $errors = [];

            if (!$data['property_id']) {
                addFieldError($errors, 'property_id', 'Choose the property the fault is at.');
            }
            if ($data['description'] === '') {
                addFieldError($errors, 'description', 'Describe the fault so whoever attends knows what to bring.');
            }
            if (!isset(self::PRIORITIES[$data['priority']])) {
                addFieldError($errors, 'priority', 'Choose how urgent this is.');
            }
            if ($errors) {
                rejectForm($errors, $data, $failUrl);
            }

            // Checked before anything is uploaded: a request for a property
            // the user has no relationship with is refused without leaving
            // orphaned files on disk. The submitted id is kept out of the
            // message so the response cannot be used to probe which ids exist.
            if (!canFileMaintenanceFor($data['property_id'])) {
                logAudit('denied_maintenance_create', 'property', $data['property_id'],
                    '', 'property outside ' . getUserRole() . ' scope');
                setFlash('error', 'You can only report issues for your own properties.');
                $_SESSION['form_data'] = $data;
                redirect($failUrl);
            }

            // A tenant's own report is attributed to their customer record, so
            // the request shows up against the right person on the property.
            if (getUserRole() === ROLE_CUSTOMER) {
                $data['customer_id'] = currentCustomerId();
            }

            // Photo uploads (multiple)
            $photos = [];
            if (!empty($_FILES['photos']['name'][0])) {
                foreach ($_FILES['photos']['tmp_name'] as $i => $tmp) {
                    $file = ['name' => $_FILES['photos']['name'][$i], 'tmp_name' => $tmp,
                             'error' => $_FILES['photos']['error'][$i], 'size' => $_FILES['photos']['size'][$i]];
                    $path = uploadFile($file, 'maintenance', ALLOWED_IMAGE_TYPES);
                    if ($path) $photos[] = $path;
                }
            }
            $data['photos'] = json_encode($photos);
            $id = $this->model->create($data);
            if ($id) {
                logAudit('created_maintenance', 'maintenance', $id);
                // Notify admins
                $property = $this->propertySummary($data['property_id']);
                foreach ($db->query("SELECT id FROM users WHERE role_id=1")->fetchAll() as $a) {
                    notify((int)$a['id'], 'New Maintenance Request',
                        'Issue reported for ' . $property . '.', 'warning', 'maintenance', $id);
                }
                setFlash('success', 'Maintenance request submitted.');
                redirect(APP_URL . '/index.php?page=maintenance&action=show&id=' . $id);
            }
            setFlash('error', 'Failed to create request.');
            $_SESSION['form_data'] = $data;
            redirect($failUrl);
        }

        $formData = $_SESSION['form_data'] ?? [];
        unset($_SESSION['form_data']);

        renderPage(VIEWS_PATH . '/admin/maintenance/create.php', [
            'properties' => $properties,
            'formData'   => $formData,
            // The form's <option>s and the validator read one list.
            'priorities' => self::PRIORITIES,
            'pageTitle' => 'New Maintenance Request',
            'breadcrumbs' => [
                ['label' => 'Maintenance', 'url' => APP_URL . '/index.php?page=maintenance'],
                ['label' => 'Create'],
            ],
        ]);
    }

    /** Readable property name for a notification body. */
    private function propertySummary(int $propertyId): string
    {
        $stmt = getDBConnection()->prepare("SELECT title, property_code FROM properties WHERE id = ?");
        $stmt->execute([$propertyId]);
        $p = $stmt->fetch();
        return $p ? $p['title'] . ' (' . $p['property_code'] . ')' : 'a property';
    }

    public function show(): void
    {
        authorize('maintenance.show');
        $id = (int)($_GET['id'] ?? 0);
        $request = $this->model->findById($id);
        // A request that does not exist and one belonging to another owner are
        // refused identically. Distinguishing them would confirm that a given
        // request code is real, which is itself a disclosure.
        authorizeRecord($request !== null && canViewMaintenanceRequest($request), 'maintenance', $id);

        $db = getDBConnection();
        $technicians = $db->query("SELECT id, full_name FROM users WHERE role_id=5 AND is_active=1 ORDER BY full_name")->fetchAll();
        $photos = json_decode($request['photos'] ?? '[]', true) ?: [];

        renderPage(VIEWS_PATH . '/admin/maintenance/show.php', [
            'request' => $request, 'technicians' => $technicians, 'photos' => $photos,
            'canManage' => canManageMaintenanceRequest($request),
            'canAssign' => canAssignMaintenanceRequest($request),
            'pageTitle' => 'Request ' . $request['request_code'],
            'breadcrumbs' => [
                ['label' => 'Maintenance', 'url' => APP_URL . '/index.php?page=maintenance'],
                ['label' => $request['request_code']],
            ],
        ]);
    }

    public function update(): void
    {
        authorize('maintenance.update');
        $id = (int)($_GET['id'] ?? 0);
        enforceCSRF();
        // Previously this action had no check at all beyond being signed in:
        // any account could rewrite the status and costs of any request by
        // posting its id. Editing is now the property's own people only.
        $request = $this->model->findById($id);
        authorizeRecord($request !== null && canManageMaintenanceRequest($request), 'maintenance', $id);

        // The status went to the enum column exactly as posted. Anything
        // outside the enum would be refused by MySQL as a truncation, which is
        // a poor way to find out a form was tampered with — and in a lax SQL
        // mode it would have been silently blanked instead.
        $status = uiPick($_POST['status'] ?? '', array_keys(self::STATUSES));
        if ($status === '') {
            setFlash('error', 'That is not a state a request can be in.');
            redirect(APP_URL . '/index.php?page=maintenance&action=show&id=' . $id);
        }

        $data = [
            'status'           => $status,
            'actual_cost'      => max(0, (float)($_POST['actual_cost'] ?? 0)),
            'completion_notes' => sanitize($_POST['completion_notes'] ?? ''),
        ];
        if ($data['status'] === 'completed') {
            $data['completion_date'] = date('Y-m-d');
        }
        $this->model->update($id, $data);
        logAudit('updated_maintenance', 'maintenance', $id, $request['status'], $data['status']);
        setFlash('success', 'Maintenance request updated.');
        redirect(APP_URL . '/index.php?page=maintenance&action=show&id=' . $id);
    }

    public function assign(): void
    {
        authorize('maintenance.assign');
        $id = (int)($_GET['id'] ?? 0);
        enforceCSRF();
        // authorize() proves the role holds the action; this proves the
        // property. An agent cannot dispatch a technician to a colleague's
        // listing, and a technician cannot hand their job to someone else.
        $request = $this->model->findById($id);
        authorizeRecord($request !== null && canAssignMaintenanceRequest($request), 'maintenance', $id);

        // The posted id went straight into assigned_to, so any user id at all
        // could be made the technician on a job — and would then be notified
        // about it. It is now checked against the same list the <select> is
        // built from: active users in the technician role.
        $tech = (int)($_POST['assigned_to'] ?? 0);
        if ($tech) {
            $stmt = getDBConnection()->prepare(
                "SELECT id FROM users WHERE id = ? AND role_id = 5 AND is_active = 1"
            );
            $stmt->execute([$tech]);
            if (!$stmt->fetchColumn()) {
                setFlash('error', 'That is not an active technician.');
                redirect(APP_URL . '/index.php?page=maintenance&action=show&id=' . $id);
            }
        }

        $cost = max(0, (float)($_POST['cost_estimate'] ?? 0));
        $this->model->update($id, [
            'assigned_to' => $tech ?: null,
            'cost_estimate' => $cost,
            'status' => 'assigned',
        ]);
        if ($tech) notify($tech, 'Maintenance Assigned', 'You have been assigned a new maintenance request.', 'info', 'maintenance', $id);
        logAudit('assigned_maintenance', 'maintenance', $id);
        setFlash('success', 'Technician assigned.');
        redirect(APP_URL . '/index.php?page=maintenance&action=show&id=' . $id);
    }
}
