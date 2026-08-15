<?php
/**
 * Maintenance Controller
 */
require_once BASE_PATH . '/models/MaintenanceRequest.php';

class MaintenanceController
{
    private MaintenanceRequest $model;

    public function __construct()
    {
        $this->model = new MaintenanceRequest();
    }

    public function index(): void
    {
        requireLogin();
        $role = getUserRole();
        $filters = ['status' => $_GET['status'] ?? '', 'priority' => $_GET['priority'] ?? '', 'search' => $_GET['search'] ?? ''];
        // Maintenance staff only sees their own
        if ($role === ROLE_MAINTENANCE) {
            $filters['assigned_to'] = $_SESSION['user_id'] ?? 0;
        }
        $page = max(1, (int)($_GET['p'] ?? 1));
        $offset = ($page - 1) * ITEMS_PER_PAGE;
        $requests = $this->model->getAll($filters, ITEMS_PER_PAGE, $offset);
        $totalCount = $this->model->count($filters);
        $totalPages = (int) ceil($totalCount / ITEMS_PER_PAGE);

        renderPage(VIEWS_PATH . '/admin/maintenance/index.php', [
            'requests' => $requests, 'filters' => $filters,
            'page' => $page, 'totalPages' => $totalPages, 'totalCount' => $totalCount,
            'pageTitle' => 'Maintenance Requests',
            'breadcrumbs' => [['label' => 'Maintenance']],
            'actionButton' => ['label' => 'New Request', 'icon' => 'bi-plus-lg', 'url' => APP_URL . '/index.php?page=maintenance&action=create'],
        ]);
    }

    public function create(): void
    {
        requireLogin();
        $db = getDBConnection();
        $properties = $db->query("SELECT id, title, property_code FROM properties WHERE is_archived=0 ORDER BY title")->fetchAll();

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            enforceCSRF();
            $data = [
                'property_id' => (int)$_POST['property_id'],
                'issue_type'  => sanitize($_POST['issue_type'] ?? ''),
                'description' => sanitize($_POST['description'] ?? ''),
                'priority'    => $_POST['priority'] ?? 'medium',
            ];
            if (!$data['property_id'] || !$data['description']) {
                setFlash('error', 'Property and description are required.');
                redirect(APP_URL . '/index.php?page=maintenance&action=create');
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
                foreach ($db->query("SELECT id FROM users WHERE role_id=1")->fetchAll() as $a) {
                    notify((int)$a['id'], 'New Maintenance Request', 'Issue reported for property.', 'warning', 'maintenance', $id);
                }
                setFlash('success', 'Maintenance request submitted.');
                redirect(APP_URL . '/index.php?page=maintenance&action=show&id=' . $id);
            }
            setFlash('error', 'Failed to create request.');
            redirect(APP_URL . '/index.php?page=maintenance&action=create');
        }

        renderPage(VIEWS_PATH . '/admin/maintenance/create.php', [
            'properties' => $properties,
            'pageTitle' => 'New Maintenance Request',
            'breadcrumbs' => [
                ['label' => 'Maintenance', 'url' => APP_URL . '/index.php?page=maintenance'],
                ['label' => 'Create'],
            ],
        ]);
    }

    public function show(): void
    {
        requireLogin();
        $id = (int)($_GET['id'] ?? 0);
        $request = $this->model->findById($id);
        if (!$request) { setFlash('error', 'Request not found.'); redirect(APP_URL . '/index.php?page=maintenance'); }

        $db = getDBConnection();
        $technicians = $db->query("SELECT id, full_name FROM users WHERE role_id=5 AND is_active=1 ORDER BY full_name")->fetchAll();
        $photos = json_decode($request['photos'] ?? '[]', true) ?: [];

        renderPage(VIEWS_PATH . '/admin/maintenance/show.php', [
            'request' => $request, 'technicians' => $technicians, 'photos' => $photos,
            'pageTitle' => 'Request ' . $request['request_code'],
            'breadcrumbs' => [
                ['label' => 'Maintenance', 'url' => APP_URL . '/index.php?page=maintenance'],
                ['label' => $request['request_code']],
            ],
        ]);
    }

    public function update(): void
    {
        requireLogin();
        $id = (int)($_GET['id'] ?? 0);
        enforceCSRF();
        $data = [
            'status'           => $_POST['status'] ?? 'new',
            'actual_cost'      => (float)($_POST['actual_cost'] ?? 0),
            'completion_notes' => sanitize($_POST['completion_notes'] ?? ''),
        ];
        if ($data['status'] === 'completed') {
            $data['completion_date'] = date('Y-m-d');
        }
        $this->model->update($id, $data);
        logAudit('updated_maintenance', 'maintenance', $id, '', $data['status']);
        setFlash('success', 'Maintenance request updated.');
        redirect(APP_URL . '/index.php?page=maintenance&action=show&id=' . $id);
    }

    public function assign(): void
    {
        requireRole(ROLE_ADMIN, ROLE_AGENT);
        $id = (int)($_GET['id'] ?? 0);
        enforceCSRF();
        $tech = (int)($_POST['assigned_to'] ?? 0);
        $cost = (float)($_POST['cost_estimate'] ?? 0);
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
