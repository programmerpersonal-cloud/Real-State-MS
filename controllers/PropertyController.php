<?php
/**
 * Property Controller
 */
require_once BASE_PATH . '/models/Property.php';
require_once BASE_PATH . '/models/Owner.php';

class PropertyController
{
    private Property $model;

    public function __construct()
    {
        $this->model = new Property();
    }

    public function index(): void
    {
        requireRole(ROLE_ADMIN, ROLE_AGENT);
        $filters = [
            'search'        => $_GET['search'] ?? '',
            'property_type' => $_GET['property_type'] ?? '',
            'category'      => $_GET['category'] ?? '',
            'status'        => $_GET['status'] ?? '',
        ];
        $page = max(1, (int)($_GET['p'] ?? 1));
        $offset = ($page - 1) * ITEMS_PER_PAGE;
        $properties = $this->model->getAll($filters, ITEMS_PER_PAGE, $offset);
        $totalCount = $this->model->count($filters);
        $totalPages = (int) ceil($totalCount / ITEMS_PER_PAGE);

        renderPage(VIEWS_PATH . '/admin/properties/index.php', [
            'properties' => $properties,
            'filters'    => $filters,
            'page'       => $page,
            'totalPages' => $totalPages,
            'totalCount' => $totalCount,
            'pageTitle'  => 'Properties',
            'breadcrumbs'=> [['label' => 'Properties']],
            'actionButton' => ['label' => 'Add Property', 'icon' => 'bi-plus-lg', 'url' => APP_URL . '/index.php?page=properties&action=create'],
        ]);
    }

    public function create(): void
    {
        requireRole(ROLE_ADMIN, ROLE_AGENT);
        $ownerModel = new Owner();
        $owners = $ownerModel->getAllSimple();
        $db = getDBConnection();
        $agents = $db->query("SELECT id, full_name FROM users WHERE role_id = 2 AND is_active = 1 ORDER BY full_name")->fetchAll();
        $branches = $db->query("SELECT id, name FROM branches WHERE is_active = 1 ORDER BY name")->fetchAll();

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            enforceCSRF();
            $data = $this->extractPropertyData();
            $errors = $this->validateProperty($data);
            if (!empty($errors)) {
                setFlash('error', implode(' ', $errors));
                $_SESSION['form_data'] = $data;
                redirect(APP_URL . '/index.php?page=properties&action=create');
            }
            $id = $this->model->create($data);
            if ($id) {
                if (!empty($_FILES['images']['name'][0])) {
                    foreach ($_FILES['images']['tmp_name'] as $i => $tmp) {
                        $file = [
                            'name' => $_FILES['images']['name'][$i],
                            'tmp_name' => $tmp,
                            'error' => $_FILES['images']['error'][$i],
                            'size' => $_FILES['images']['size'][$i]
                        ];
                        $path = uploadFile($file, 'properties', ALLOWED_IMAGE_TYPES);
                        if ($path) $this->model->addImage($id, $path, $i === 0);
                    }
                }
                $this->model->logChange($id, 'created');
                logAudit('created_property', 'property', $id);
                setFlash('success', 'Property created successfully!');
                redirect(APP_URL . '/index.php?page=properties&action=show&id=' . $id);
            }
            setFlash('error', 'Failed to create property.');
            redirect(APP_URL . '/index.php?page=properties&action=create');
        }

        $formData = $_SESSION['form_data'] ?? [];
        unset($_SESSION['form_data']);

        renderPage(VIEWS_PATH . '/admin/properties/create.php', [
            'formData' => $formData,
            'owners'   => $owners,
            'agents'   => $agents,
            'branches' => $branches,
            'pageTitle' => 'New Property',
            'breadcrumbs' => [
                ['label' => 'Properties', 'url' => APP_URL . '/index.php?page=properties'],
                ['label' => 'Create'],
            ],
        ]);
    }

    public function edit(): void
    {
        requireRole(ROLE_ADMIN, ROLE_AGENT);
        $id = (int)($_GET['id'] ?? 0);
        $property = $this->model->findById($id);
        if (!$property) { setFlash('error', 'Property not found.'); redirect(APP_URL . '/index.php?page=properties'); }

        $ownerModel = new Owner();
        $owners = $ownerModel->getAllSimple();
        $db = getDBConnection();
        $agents = $db->query("SELECT id, full_name FROM users WHERE role_id = 2 AND is_active = 1 ORDER BY full_name")->fetchAll();
        $branches = $db->query("SELECT id, name FROM branches WHERE is_active = 1 ORDER BY name")->fetchAll();
        $images = $this->model->getImages($id);

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            enforceCSRF();
            $data = $this->extractPropertyData();
            $oldStatus = $property['status'];
            $this->model->update($id, $data);
            if ($oldStatus !== $data['status']) {
                $this->model->logChange($id, 'status_changed', 'status', $oldStatus, $data['status']);
            }
            if (!empty($_FILES['images']['name'][0])) {
                foreach ($_FILES['images']['tmp_name'] as $i => $tmp) {
                    $file = [
                        'name' => $_FILES['images']['name'][$i],
                        'tmp_name' => $tmp,
                        'error' => $_FILES['images']['error'][$i],
                        'size' => $_FILES['images']['size'][$i]
                    ];
                    $path = uploadFile($file, 'properties', ALLOWED_IMAGE_TYPES);
                    if ($path) $this->model->addImage($id, $path);
                }
            }
            $this->model->logChange($id, 'updated');
            logAudit('updated_property', 'property', $id);
            setFlash('success', 'Property updated successfully!');
            redirect(APP_URL . '/index.php?page=properties&action=show&id=' . $id);
        }

        renderPage(VIEWS_PATH . '/admin/properties/edit.php', [
            'formData' => $property,
            'property' => $property,
            'owners'   => $owners,
            'agents'   => $agents,
            'branches' => $branches,
            'images'   => $images,
            'pageTitle' => 'Edit Property',
            'breadcrumbs' => [
                ['label' => 'Properties', 'url' => APP_URL . '/index.php?page=properties'],
                ['label' => 'Edit'],
            ],
        ]);
    }

    public function show(): void
    {
        requireLogin();
        $id = (int)($_GET['id'] ?? 0);
        $property = $this->model->findById($id);
        if (!$property) { setFlash('error', 'Property not found.'); redirect(APP_URL . '/index.php?page=properties'); }
        $images = $this->model->getImages($id);
        $history = $this->model->getHistory($id);

        // Active lease, if any
        $db = getDBConnection();
        $stmt = $db->prepare("SELECT l.*, c.full_name AS customer_name FROM leases l JOIN customers c ON l.customer_id=c.id WHERE l.property_id = ? AND l.status='active' LIMIT 1");
        $stmt->execute([$id]);
        $activeLease = $stmt->fetch() ?: null;

        renderPage(VIEWS_PATH . '/admin/properties/show.php', [
            'property'    => $property,
            'images'      => $images,
            'history'     => $history,
            'activeLease' => $activeLease,
            'pageTitle'   => $property['title'],
            'breadcrumbs' => [
                ['label' => 'Properties', 'url' => APP_URL . '/index.php?page=properties'],
                ['label' => $property['property_code']],
            ],
        ]);
    }

    public function approve(): void
    {
        requireRole(ROLE_ADMIN);
        $id = (int)($_GET['id'] ?? 0);
        $this->model->update($id, ['approval_status' => 'approved']);
        $this->model->logChange($id, 'approved');
        logAudit('approved_property', 'property', $id);
        setFlash('success', 'Property approved.');
        redirect(APP_URL . '/index.php?page=properties&action=show&id=' . $id);
    }

    public function archive(): void
    {
        requireRole(ROLE_ADMIN);
        $id = (int)($_GET['id'] ?? 0);
        $this->model->delete($id);
        logAudit('archived_property', 'property', $id);
        setFlash('success', 'Property archived.');
        redirect(APP_URL . '/index.php?page=properties');
    }

    public function deleteImage(): void
    {
        requireRole(ROLE_ADMIN, ROLE_AGENT);
        $imgId = (int)($_GET['img_id'] ?? 0);
        $propId = (int)($_GET['id'] ?? 0);
        $this->model->deleteImage($imgId);
        setFlash('success', 'Image deleted.');
        redirect(APP_URL . '/index.php?page=properties&action=edit&id=' . $propId);
    }

    private function extractPropertyData(): array
    {
        return [
            'title'              => sanitize($_POST['title'] ?? ''),
            'property_type'      => $_POST['property_type'] ?? 'rent',
            'category'           => $_POST['category'] ?? 'apartment',
            'description'        => sanitize($_POST['description'] ?? ''),
            'location'           => sanitize($_POST['location'] ?? ''),
            'address'            => sanitize($_POST['address'] ?? ''),
            'size_sqm'           => $_POST['size_sqm'] !== '' ? $_POST['size_sqm'] : null,
            'num_rooms'          => (int)($_POST['num_rooms'] ?? 0),
            'num_bathrooms'      => (int)($_POST['num_bathrooms'] ?? 0),
            'num_floors'         => (int)($_POST['num_floors'] ?? 1),
            'price'              => $_POST['price'] !== '' ? $_POST['price'] : null,
            'rent_amount'        => $_POST['rent_amount'] !== '' ? $_POST['rent_amount'] : null,
            'deposit_amount'     => $_POST['deposit_amount'] !== '' ? $_POST['deposit_amount'] : null,
            'is_furnished'       => isset($_POST['is_furnished']) ? 1 : 0,
            'has_parking'        => isset($_POST['has_parking']) ? 1 : 0,
            'has_security'       => isset($_POST['has_security']) ? 1 : 0,
            'utilities_included' => sanitize($_POST['utilities_included'] ?? ''),
            'status'             => $_POST['status'] ?? 'available',
            'owner_id'           => $_POST['owner_id'] ?: null,
            'agent_id'           => $_POST['agent_id'] ?: null,
            'branch_id'          => $_POST['branch_id'] ?: null,
        ];
    }

    private function validateProperty(array $d): array
    {
        $errors = [];
        if (empty($d['title'])) $errors[] = 'Title is required.';
        if (empty($d['location'])) $errors[] = 'Location is required.';
        if ($d['property_type'] !== 'sale' && empty($d['rent_amount'])) {
            $errors[] = 'Rent amount is required for rentable property.';
        }
        if ($d['property_type'] !== 'rent' && empty($d['price'])) {
            $errors[] = 'Sale price is required for property on sale.';
        }
        return $errors;
    }
}
