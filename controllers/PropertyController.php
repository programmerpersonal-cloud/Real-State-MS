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
    /**
     * The enum values this module accepts, with the labels the UI shows.
     *
     * One list per enum, read by both the filter <select> and the validation
     * that guards the query. Keeping them together is the point: an option
     * offered in the form is by construction an option the filter accepts,
     * and a value absent here is dropped rather than passed to the model.
     *
     * These mirror the ENUM definitions on the properties table.
     */
    private const LISTING_TYPES = [
        'rent' => 'For Rent',
        'sale' => 'For Sale',
        'both' => 'Rent or Sale',
    ];

    private const CATEGORIES = [
        'apartment'  => 'Apartment',
        'house'      => 'House',
        'villa'      => 'Villa',
        'land'       => 'Land',
        'office'     => 'Office',
        'commercial' => 'Commercial',
        'warehouse'  => 'Warehouse',
    ];

    private const STATUSES = [
        'available'   => 'Available',
        'reserved'    => 'Reserved',
        'rented'      => 'Rented',
        'sold'        => 'Sold',
        'maintenance' => 'Maintenance',
        'inactive'    => 'Inactive',
    ];

    private Property $model;

    public function __construct()
    {
        $this->model = new Property();
    }

    /**
     * A request value, but only if it is one we recognise.
     *
     * Anything else becomes '' — an absent filter — so an unexpected value
     * widens the result set rather than reaching the query or the page.
     *
     * @param string[] $allowed
     */
    private static function pick(mixed $value, array $allowed): string
    {
        $value = is_string($value) ? $value : '';

        return in_array($value, $allowed, true) ? $value : '';
    }

    public function index(): void
    {
        authorize('properties.view');

        // Enumerated filters are validated against the same lists the form
        // builds its <option>s from, so a hand-edited ?status=<script> is an
        // empty filter rather than a value carried into the query. The free
        // text and the ids stay bound parameters inside buildFilters().
        $filters = [
            'search'        => trim((string) ($_GET['search'] ?? '')),
            'property_type' => self::pick($_GET['property_type'] ?? '', array_keys(self::LISTING_TYPES)),
            'category'      => self::pick($_GET['category'] ?? '', array_keys(self::CATEGORIES)),
            'status'        => self::pick($_GET['status'] ?? '', array_keys(self::STATUSES)),
            'owner_id'      => max(0, (int) ($_GET['owner_id'] ?? 0)) ?: '',
            // Never interpolated: the model looks this key up in Property::SORTS
            // and falls back to 'newest' for anything it does not recognise.
            'sort'          => uiSortValue(array_keys(Property::SORTS), 'newest'),
        ];

        $page = max(1, (int)($_GET['p'] ?? 1));
        $offset = ($page - 1) * ITEMS_PER_PAGE;
        $properties = $this->model->getAll($filters, ITEMS_PER_PAGE, $offset);
        $totalCount = $this->model->count($filters);
        $totalPages = (int) ceil($totalCount / ITEMS_PER_PAGE);

        // Covers for the whole page in one query, for the grid view. Batched
        // rather than fetched per card: at 20 rows the obvious version costs
        // 20 round trips to draw one screen.
        $covers = $this->model->getCoversFor(array_column($properties, 'id'));

        // The quick-add popup lives on this page, so it needs the same
        // lookups the full form uses — and the entry kept back after a
        // failed submit, so the popup can reopen where the user left off.
        $formData = $_SESSION['form_data'] ?? [];
        unset($_SESSION['form_data']);

        renderPage(VIEWS_PATH . '/admin/properties/index.php', array_merge(self::formLookups(), [
            'properties' => $properties,
            'covers'     => $covers,
            'filters'    => $filters,
            // The filter controls are built from the same lists the request
            // was validated against, so the two can never disagree.
            'listingTypes' => self::LISTING_TYPES,
            'categories'   => self::CATEGORIES,
            'statuses'     => self::STATUSES,
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
                // Give back what was typed — including a coordinate that was
                // rejected, which the parsed data drops — so the returning
                // form shows the entry that needs correcting.
                rejectForm($errors, array_merge($data, [
                    'latitude'  => trim((string)($_POST['latitude'] ?? '')),
                    'longitude' => trim((string)($_POST['longitude'] ?? '')),
                ]), $failUrl);
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

            // Edit ran no validation at all, so a title or a location could be
            // emptied here that create() would have refused, and a rejected
            // coordinate was silently dropped. Same rules as create, applied
            // to the same fields — a record cannot be edited into a state it
            // could not have been created in.
            $errors = $this->validateProperty($data);
            if (!empty($errors)) {
                rejectForm($errors, array_merge($data, [
                    'id'        => $id,
                    'latitude'  => trim((string)($_POST['latitude'] ?? '')),
                    'longitude' => trim((string)($_POST['longitude'] ?? '')),
                ]), APP_URL . '/index.php?page=properties&action=edit&id=' . $id);
            }

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

        /* ── Related records, one query per tab ────────────────────────
           The detail page gained Reservations, Maintenance and Payments
           tabs, so it gained exactly three queries — each a single scoped
           SELECT for the whole tab, never one per row, and each behind the
           permission that governs the module it belongs to. A role without
           that permission does not pay for the query and does not get the
           tab.

           MaintenanceRequest::getAll() carries its own view scope on top of
           this, so a technician sees the jobs that are theirs rather than
           every job at the address. */
        require_once BASE_PATH . '/models/Reservation.php';
        require_once BASE_PATH . '/models/MaintenanceRequest.php';
        require_once BASE_PATH . '/models/Payment.php';

        $reservations = can('reservations.view')
            ? (new Reservation())->getAll(['property_id' => $id], 50, 0)
            : [];
        $maintenance = can('maintenance.view')
            ? (new MaintenanceRequest())->getAll(['property_id' => $id], 50, 0)
            : [];
        // Payments are the owner's income and the tenant's record; the same
        // pair who may read the tenancy above may read what was paid on it.
        $payments = (can('payments.view') || ownsProperty($property))
            ? (new Payment())->getAll(['property_id' => $id], 50, 0)
            : [];

        renderPage(VIEWS_PATH . '/admin/properties/show.php', [
            'property'      => $property,
            'images'        => $images,
            'history'       => $history,
            'activeLease'   => $activeLease,
            'documents'     => $documents,
            'documentStats' => $documentStats,
            'reservations'  => $reservations,
            'maintenance'   => $maintenance,
            'payments'      => $payments,
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

    /**
     * The rules are unchanged; only the shape of the result is.
     *
     * Each message is now recorded against the field it belongs to as well as
     * in the returned list, so the form that comes back can outline the box
     * that needs fixing instead of only printing a sentence at the top of the
     * page. addFieldError() writes both.
     */
    private function validateProperty(array $d): array
    {
        // Clear any keyed errors left by an earlier rejected attempt, so a
        // field corrected on the second try does not come back still outlined.
        unset($_SESSION['form_errors']);

        $errors = [];
        if (empty($d['title']))    addFieldError($errors, 'title', 'Title is required.');
        if (empty($d['location'])) addFieldError($errors, 'location', 'Location is required.');

        // Coordinates are optional, but one that was typed and cannot be
        // stored is reported rather than dropped without a word.
        foreach ([['latitude', 'Latitude', 90], ['longitude', 'Longitude', 180]] as [$key, $label, $max]) {
            if (trim((string)($_POST[$key] ?? '')) !== '' && $d[$key] === null) {
                addFieldError($errors, $key, "$label must be a number between -$max and $max.");
            }
        }
        if (($d['latitude'] === null) !== ($d['longitude'] === null)) {
            // Reported against whichever half is missing, so the outline lands
            // on the box that still needs a value.
            addFieldError($errors, $d['latitude'] === null ? 'latitude' : 'longitude',
                'Latitude and longitude must be provided together.');
        }

        if ($d['property_type'] !== 'sale' && empty($d['rent_amount'])) {
            addFieldError($errors, 'rent_amount', 'Rent amount is required for rentable property.');
        }
        if ($d['property_type'] !== 'rent' && empty($d['price'])) {
            addFieldError($errors, 'price', 'Sale price is required for property on sale.');
        }
        return $errors;
    }
}
