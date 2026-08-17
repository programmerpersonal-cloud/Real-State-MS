<?php
/**
 * Property Controller
 */
require_once BASE_PATH . '/models/Property.php';
require_once BASE_PATH . '/models/Owner.php';
require_once BASE_PATH . '/models/Document.php';
require_once BASE_PATH . '/models/DocumentCategory.php';

class PropertyController
{
    private Property $model;

    public function __construct()
    {
        $this->model = new Property();
    }

    public function index(): void
    {
        authorize('properties.view');
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

        // The quick-add popup lives on this page, so it needs the same
        // lookups the full form uses — and the entry kept back after a
        // failed submit, so the popup can reopen where the user left off.
        $formData = $_SESSION['form_data'] ?? [];
        unset($_SESSION['form_data']);

        renderPage(VIEWS_PATH . '/admin/properties/index.php', array_merge(self::formLookups(), [
            'properties' => $properties,
            'filters'    => $filters,
            'page'       => $page,
            'totalPages' => $totalPages,
            'totalCount' => $totalCount,
            'formData'   => $formData,
            'openCreateModal' => ($_GET['modal'] ?? '') === 'create',
            'pageTitle'  => 'Properties',
            'breadcrumbs'=> [['label' => 'Properties']],
            'actionButton' => [
                'label' => 'Add Property',
                'icon'  => 'bi-plus-lg',
                'url'   => APP_URL . '/index.php?page=properties&action=create',
                'attrs' => ['data-modal-open' => 'propertyCreateModal'],
            ],
        ]));
    }

    /**
     * Owner / agent / branch option lists, shared by every property form —
     * including the quick-add popup wherever it is hosted, which is why this
     * is reachable from outside the controller.
     *
     * @return array{owners:array,agents:array,branches:array}
     */
    public static function formLookups(): array
    {
        $db = getDBConnection();
        return [
            'owners'   => (new Owner())->getAllSimple(),
            'agents'   => $db->query("SELECT id, full_name FROM users WHERE role_id = 2 AND is_active = 1 ORDER BY full_name")->fetchAll(),
            'branches' => $db->query("SELECT id, name FROM branches WHERE is_active = 1 ORDER BY name")->fetchAll(),
        ];
    }

    public function create(): void
    {
        authorize('properties.create');
        ['owners' => $owners, 'agents' => $agents, 'branches' => $branches] = self::formLookups();

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            enforceCSRF();
            // A submit from a popup returns to that popup, so a rejected
            // entry is corrected where it was typed.
            $failUrl = modalReturnUrl('properties', 'property',
                APP_URL . '/index.php?page=properties&action=create');

            $data = $this->extractPropertyData();
            $errors = $this->validateProperty($data);
            if (!empty($errors)) {
                setFlash('error', implode(' ', $errors));
                // Give back what was typed — including a coordinate that was
                // rejected, which the parsed data drops — so the returning
                // form shows the entry that needs correcting.
                $_SESSION['form_data'] = array_merge($data, [
                    'latitude'  => trim((string)($_POST['latitude'] ?? '')),
                    'longitude' => trim((string)($_POST['longitude'] ?? '')),
                ]);
                redirect($failUrl);
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
            $_SESSION['form_data'] = $data;
            redirect($failUrl);
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
        authorize('properties.edit');
        $id = (int)($_GET['id'] ?? 0);
        $property = $this->model->findById($id);
        if (!$property) { setFlash('error', 'Property not found.'); redirect(APP_URL . '/index.php?page=properties'); }

        ['owners' => $owners, 'agents' => $agents, 'branches' => $branches] = self::formLookups();
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
        authorize('properties.show');
        $id = (int)($_GET['id'] ?? 0);
        $property = $this->model->findById($id);
        // Holding properties.show means this page is part of the role's job,
        // not that every property is. Staff work the whole register; an owner
        // reaches the ones they own, a tenant the home they rent or any live
        // public listing, a technician the address of a job. A missing
        // property and someone else's are refused identically.
        authorizeRecord($property !== null && canViewProperty($property), 'property', $id);
        $images = $this->model->getImages($id);
        $history = $this->model->getHistory($id);

        // Active lease, if any
        $db = getDBConnection();
        $stmt = $db->prepare("SELECT l.*, c.full_name AS customer_name FROM leases l JOIN customers c ON l.customer_id=c.id WHERE l.property_id = ? AND l.status='active' LIMIT 1");
        $stmt->execute([$id]);
        $activeLease = $stmt->fetch() ?: null;

        // Documents. Every signed-in role can reach this page, so the
        // visibility scope is what keeps a browsing customer from a title
        // deed, and _list.php re-checks each row on the way out. The property
        // is passed in because clearance is not role alone: on their own
        // property an owner reads every level.
        $scope     = documentVisibilityScope($property);
        $docModel  = new Document();
        $documents = $docModel->forReference('property', $id, [
            'visibility_in'    => $scope,
            'include_archived' => documentCanManage(),
        ]);
        $documentStats = $docModel->statsForReference('property', $id, $scope);

        renderPage(VIEWS_PATH . '/admin/properties/show.php', [
            'property'      => $property,
            'images'        => $images,
            'history'       => $history,
            'activeLease'   => $activeLease,
            'documents'     => $documents,
            'documentStats' => $documentStats,
            // Only the people who can upload need the form's option lists.
            'categories'    => documentCanManage() ? (new DocumentCategory())->options() : [],
            'categoryMeta'  => documentCanManage() ? (new DocumentCategory())->formMeta() : [],
            'formData'      => (function () { $f = $_SESSION['form_data'] ?? []; unset($_SESSION['form_data']); return $f; })(),
            'openUploadModal' => ($_GET['modal'] ?? '') === 'upload',
            'pageTitle'     => $property['title'],
            'breadcrumbs'   => [
                ['label' => 'Properties', 'url' => APP_URL . '/index.php?page=properties'],
                ['label' => $property['property_code']],
            ],
        ]);
    }

    public function approve(): void
    {
        authorize('properties.approve');
        $id = (int)($_GET['id'] ?? 0);
        $this->model->update($id, ['approval_status' => 'approved']);
        $this->model->logChange($id, 'approved');
        logAudit('approved_property', 'property', $id);
        setFlash('success', 'Property approved.');
        redirect(APP_URL . '/index.php?page=properties&action=show&id=' . $id);
    }

    public function archive(): void
    {
        authorize('properties.archive');
        $id = (int)($_GET['id'] ?? 0);
        $this->model->delete($id);
        logAudit('archived_property', 'property', $id);
        setFlash('success', 'Property archived.');
        redirect(APP_URL . '/index.php?page=properties');
    }

    public function deleteImage(): void
    {
        authorize('properties.delete-image');
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
            'latitude'           => $this->parseCoordinate($_POST['latitude'] ?? '', 90),
            'longitude'          => $this->parseCoordinate($_POST['longitude'] ?? '', 180),
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

    /**
     * A map coordinate, or null when the field was left blank or holds
     * something the column cannot store. The caller reports the difference;
     * see validateProperty().
     *
     * The 8-decimal rounding matches DECIMAL(10,8)/DECIMAL(11,8) — roughly
     * millimetre precision, far beyond what a browser fix delivers.
     */
    private function parseCoordinate(mixed $value, float $max): ?float
    {
        $value = trim((string) $value);
        if ($value === '' || !is_numeric($value)) return null;

        $number = (float) $value;
        return abs($number) <= $max ? round($number, 8) : null;
    }

    private function validateProperty(array $d): array
    {
        $errors = [];
        if (empty($d['title'])) $errors[] = 'Title is required.';
        if (empty($d['location'])) $errors[] = 'Location is required.';

        // Coordinates are optional, but one that was typed and cannot be
        // stored is reported rather than dropped without a word.
        foreach ([['latitude', 'Latitude', 90], ['longitude', 'Longitude', 180]] as [$key, $label, $max]) {
            if (trim((string)($_POST[$key] ?? '')) !== '' && $d[$key] === null) {
                $errors[] = "$label must be a number between -$max and $max.";
            }
        }
        if (($d['latitude'] === null) !== ($d['longitude'] === null)) {
            $errors[] = 'Latitude and longitude must be provided together.';
        }

        if ($d['property_type'] !== 'sale' && empty($d['rent_amount'])) {
            $errors[] = 'Rent amount is required for rentable property.';
        }
        if ($d['property_type'] !== 'rent' && empty($d['price'])) {
            $errors[] = 'Sale price is required for property on sale.';
        }
        return $errors;
    }
}
