<?php
/**
 * Document Controller — property paperwork.
 *
 * download() is the only route in the application that emits a file, and it is
 * reachable without a session so that documents published on a listing work for
 * an anonymous visitor. Everything it does is therefore ordered defensively:
 * see the comment block on the method itself.
 *
 * Every other action is staff-only and follows the house pattern —
 * requireRole(), enforceCSRF() on POST, then redirect with a flash.
 */
require_once BASE_PATH . '/models/Document.php';
require_once BASE_PATH . '/models/DocumentCategory.php';

class DocumentController
{
    /**
     * The lifecycle a document is filtered by.
     *
     * Derived rather than stored: `status` is only active/archived, and the
     * expiry states are worked out from expiry_date against today's date in
     * the database, so the badge on a row and the filter that found it can
     * never disagree. One list, read by the state pills and the validator.
     */
    public const STATES = [
        'active'   => 'In force',
        'expiring' => 'Expiring soon',
        'expired'  => 'Expired',
        'archived' => 'Archived',
    ];

    private Document $model;
    private DocumentCategory $categories;

    public function __construct()
    {
        $this->model      = new Document();
        $this->categories = new DocumentCategory();
    }

    /* ─────────────────────────────────────────────────────────────────
       Delivery
       ───────────────────────────────────────────────────────────────── */

    /**
     * Stream a document to the browser.
     *
     * Routed from the PUBLIC switch in index.php, ahead of requireLogin(), so a
     * public document on a listing works for a visitor with no account. The
     * method takes responsibility for its own authorisation in a fixed order:
     *
     *   1. reject anything but GET/HEAD
     *   2. load the row (404 if absent)
     *   3. apply the public kill-switch and the property's own visibility
     *   4. demand a session for anything not public
     *   5. re-check the full visibility rule
     *   6. resolve the path, refusing traversal and symlinks
     *   7. audit, then stream
     *
     * Denials are deliberately terse. A signed-in user gets an honest 403; a
     * visitor gets 404, so the endpoint cannot be used to discover which
     * document ids exist.
     */
    public function download(): void
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        if (!in_array($method, ['GET', 'HEAD'], true)) {
            header('Allow: GET, HEAD');
            $this->deny(405);
        }

        $id = (int) ($_GET['id'] ?? 0);
        if ($id < 1) {
            $this->deny(404);
        }

        $doc = $this->model->findForDelivery($id);
        if (!$doc) {
            $this->deny(404);
        }

        // The parent property, when there is one. Public visibility is granted
        // *through* the listing, so an unapproved or archived property takes
        // its documents down with it. owner_id rides along because an owner's
        // clearance on their own property is decided from it — omit it and
        // they are refused their own title deed.
        $property = !empty($doc['property_id']) ? [
            'id'              => $doc['property_id'],
            'owner_id'        => $doc['property_owner_id'] ?? null,
            'approval_status' => $doc['approval_status'] ?? '',
            'is_archived'     => $doc['is_archived'] ?? 1,
        ] : null;

        // Anything not publicly readable needs a session before we go further.
        // requireLogin() redirects (and exits), which is the right answer for a
        // pasted link: the user signs in and lands back here.
        if (!$this->isPubliclyReadable($doc, $property)) {
            requireLogin();
        }

        // Clearance, then ownership. Both have to say yes: an agent is cleared
        // for every staff-level document in the company but holds only the
        // paperwork on their own listings, and the file is what actually
        // leaves the building.
        if (!documentVisibilityAllows($doc, $property) || !documentRecordAllows($doc)) {
            $this->deny(isLoggedIn() ? 403 : 404);
        }

        // Path resolution refuses traversal, absolute paths and symlinks that
        // point outside the store. Null means "no such file" for any reason.
        $full = documentStoragePath($doc['file_path'] ?? '');
        if ($full === null) {
            error_log('Document ' . $id . ': unresolvable path ' . ($doc['file_path'] ?? ''));
            $this->deny(404);
        }

        // Audited before streaming, so an aborted transfer is still recorded.
        logAudit(
            'downloaded_document',
            'document',
            $id,
            '',
            trim(($doc['document_code'] ?? '') . ' ' . ($doc['title'] ?? ''))
        );

        $this->stream($full, $doc);
    }

    /**
     * Is this document readable without signing in?
     *
     * Public visibility alone is not enough: the document must be active, the
     * site-wide switch on, and the parent listing actually published.
     */
    private function isPubliclyReadable(array $doc, ?array $property): bool
    {
        return ($doc['visibility'] ?? '') === 'public'
            && ($doc['status'] ?? '') === 'active'
            && documentPublicEnabled()
            && ($property === null || propertyIsPubliclyVisible($property));
    }

    /**
     * Emit the file. Nothing may have been printed before this runs.
     *
     * The headers and the inline/attachment decision now live in
     * streamStoredFile() (includes/documents.php), shared with the message
     * attachment endpoint so both deliver private bytes under one set of
     * rules. The behaviour here is unchanged: the same recognised-type list,
     * the same inline allow-list, the same filename handling.
     */
    private function stream(string $full, array $doc): void
    {
        streamStoredFile(
            $full,
            (string) ($doc['file_type'] ?? ''),
            (string) ($doc['file_name'] ?: ($doc['title'] ?? 'document')),
            ALLOWED_DOCUMENT_TYPES,
            DOCUMENT_INLINE_TYPES,
            ($_GET['disposition'] ?? '') === 'inline'
        );
    }

    /**
     * End the request with a bare status.
     *
     * Never renderPage() — the admin layout would try to draw a sidebar for a
     * signed-out visitor and emit HTML after binary headers had been sent.
     */
    private function deny(int $code): never
    {
        http_response_code($code);
        header('Content-Type: text/plain; charset=UTF-8');
        echo match ($code) {
            403     => 'You do not have permission to view this document.',
            405     => 'Method not allowed.',
            default => 'Document not found.',
        };
        exit;
    }

    /* ─────────────────────────────────────────────────────────────────
       Admin screens
       ───────────────────────────────────────────────────────────────── */

    public function index(): void
    {
        authorize('documents.view');

        // Whatever this reader is cleared for. Used three times below: to cut
        // the list, to validate the visibility filter, and to scope the counts.
        $scope = documentVisibilityScope();

        $filters = [
            'search'      => trim((string) ($_GET['search'] ?? '')),
            'category_id' => max(0, (int) ($_GET['category_id'] ?? 0)),
            // Only a level this reader is cleared for. Asking for one they are
            // not becomes an absent filter rather than an empty page that looks
            // like the documents were deleted.
            'visibility'  => uiPick($_GET['visibility'] ?? '', $scope),
            'state'       => uiPick($_GET['state'] ?? '', array_keys(self::STATES)),
            'reference_id'  => max(0, (int) ($_GET['property_id'] ?? 0)),
            'reference_type' => !empty($_GET['property_id']) ? 'property' : '',
            // Staff see everything they are cleared for, archived included, so
            // the Archived filter has something to find.
            'visibility_in'    => $scope,
            'include_archived' => true,
            // The other half of the answer: clearance says which levels this
            // reader may see, this says whose paperwork is theirs to hold.
            'record_scoped'    => true,
            // Never interpolated: Document::SORTS resolves this key.
            'sort' => uiSortValue(array_keys(Document::SORTS), 'newest'),
        ];

        $page   = max(1, (int) ($_GET['p'] ?? 1));
        $offset = ($page - 1) * ITEMS_PER_PAGE;

        $documents  = $this->model->getAll($filters, ITEMS_PER_PAGE, $offset);
        $totalCount = $this->model->count($filters);
        $totalPages = (int) ceil($totalCount / ITEMS_PER_PAGE);

        $formData = $_SESSION['form_data'] ?? [];
        unset($_SESSION['form_data']);

        renderPage(VIEWS_PATH . '/admin/documents/index.php', array_merge($this->formLookups(), [
            'documents'       => $documents,
            'filters'         => $filters,
            'page'            => $page,
            'totalPages'      => $totalPages,
            'totalCount'      => $totalCount,
            'formData'        => $formData,
            'states'          => self::STATES,
            'visibilities'    => array_intersect_key(DOC_VISIBILITIES, array_flip($scope)),
            // Moved out of the view, which was issuing its own query. Same one
            // query as before — it already ran on every page load — now also
            // answering the count on each state pill instead of only the
            // expiry banner.
            'expiryCounts'    => $this->model->expiryCounts($scope),
            'openUploadModal' => ($_GET['modal'] ?? '') === 'upload',
            'pageTitle'       => 'Documents',
            'pageSubtitle'    => 'Legal paperwork, certificates and attachments held against your properties.',
            'breadcrumbs'     => [['label' => 'Documents']],
            'actionButton'    => [
                'label' => 'Upload Document',
                'icon'  => 'bi-upload',
                'url'   => APP_URL . '/index.php?page=documents&modal=upload',
                'attrs' => ['data-modal-open' => 'documentUploadModal'],
            ],
        ]));
    }

    /** Read-only detail page, open to anyone cleared to see the document. */
    public function show(): void
    {
        authorize('documents.show');

        $id  = (int) ($_GET['id'] ?? 0);
        $doc = $id > 0 ? $this->model->findForDelivery($id) : null;

        // owner_id included so an owner opening a document on their own
        // property is cleared here the same way the download endpoint clears
        // them; without it the page and the file would disagree.
        $property = $doc && !empty($doc['property_id']) ? [
            'id'              => $doc['property_id'],
            'owner_id'        => $doc['property_owner_id'] ?? null,
            'approval_status' => $doc['approval_status'] ?? '',
            'is_archived'     => $doc['is_archived'] ?? 1,
        ] : null;

        // A refusal is a 403 that explains itself, the way every other record
        // check in the application answers — not a bounce to the dashboard,
        // which reads as the application being broken. A document that does
        // not exist is refused identically to one that is not theirs, so the
        // response cannot be used to discover which ids are real.
        authorizeRecord(
            $doc !== null
                && documentVisibilityAllows($doc, $property)
                && documentRecordAllows($doc),
            'document',
            $id
        );

        $full = $this->model->findById($id);

        renderPage(VIEWS_PATH . '/admin/documents/show.php', [
            'doc'         => $full,
            'pageTitle'   => $full['title'] ?? 'Document',
            'breadcrumbs' => [
                ['label' => 'Documents', 'url' => APP_URL . '/index.php?page=documents'],
                ['label' => $full['document_code'] ?? 'Document'],
            ],
        ]);
    }

    /** POST-only upload. The form lives in a modal on two different pages. */
    public function create(): void
    {
        authorize('documents.create');

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            redirect(APP_URL . '/index.php?page=documents&modal=upload');
        }

        enforceCSRF();

        $data    = $this->extractData();
        $failUrl = $this->returnUrl($data, true);
        $errors  = $this->validate($data);

        // Level 3 on the write, checked before the file is moved onto disk so
        // a refused upload leaves no orphan in the store. The <select> is
        // already scoped, so an id outside the user's reach was typed in by
        // hand.
        if (!$errors && $data['reference_type'] === 'property'
            && !canActOnProperty((int) $data['reference_id'])) {
            logAudit('denied_document_upload', 'property', (int) $data['reference_id'],
                '', 'property outside ' . getUserRole() . ' scope');
            $errors[] = 'That property is not one you manage.';
        }

        // The file is only moved onto disk once the metadata is known good, so
        // a rejected submission never leaves an orphan in the store.
        $fileMeta = null;
        if (!$errors) {
            $fileMeta = storeDocumentFile($_FILES['document_file'] ?? ['error' => UPLOAD_ERR_NO_FILE], $errors);
        }

        if ($errors || !$fileMeta) {
            setFlash('error', implode(' ', $errors ?: ['The document could not be uploaded.']));
            $_SESSION['form_data'] = $data;
            redirect($failUrl);
        }

        $id = $this->model->create(array_merge($data, $fileMeta));

        if (!$id) {
            // The row failed, so the file it would have pointed at is litter.
            deleteDocumentFile($fileMeta['file_path']);
            setFlash('error', 'The document could not be saved.');
            $_SESSION['form_data'] = $data;
            redirect($failUrl);
        }

        logAudit('uploaded_document', 'document', $id, '', $data['title'] . ' (' . $data['visibility'] . ')');
        setFlash('success', 'Document uploaded.');
        redirect($this->returnUrl($data, false));
    }

    /** Edit metadata. The stored file itself is immutable — replace by re-uploading. */
    public function edit(): void
    {
        authorize('documents.edit');

        $id  = (int) ($_GET['id'] ?? 0);
        $doc = $id > 0 ? $this->model->findById($id) : null;

        // Level 3. `documents.edit` says an agent maintains paperwork, not
        // that they maintain everyone's — visibility is one of the fields this
        // form rewrites, so an unchecked edit could publish another desk's
        // title deed to the public listing.
        authorizeRecord($doc !== null && documentRecordAllows($doc), 'document', $id);

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            enforceCSRF();

            $data   = $this->extractData();
            $errors = $this->validate($data);

            if ($errors) {
                setFlash('error', implode(' ', $errors));
                $_SESSION['form_data'] = $data;
                redirect(APP_URL . '/index.php?page=documents&action=edit&id=' . $id);
            }

            $before = [
                'title'      => $doc['title'],
                'visibility' => $doc['visibility'],
                'expiry'     => $doc['expiry_date'],
                'category'   => $doc['category_id'],
            ];

            $this->model->update($id, [
                'category_id'   => $data['category_id'],
                'title'         => $data['title'],
                'description'   => $data['description'],
                'doc_number'    => $data['doc_number'],
                'document_date' => $data['document_date'],
                'expiry_date'   => $data['expiry_date'],
                'visibility'    => $data['visibility'],
            ]);

            logAudit(
                'updated_document',
                'document',
                $id,
                json_encode($before, JSON_UNESCAPED_UNICODE),
                json_encode([
                    'title'      => $data['title'],
                    'visibility' => $data['visibility'],
                    'expiry'     => $data['expiry_date'],
                    'category'   => $data['category_id'],
                ], JSON_UNESCAPED_UNICODE)
            );

            setFlash('success', 'Document updated.');
            redirect(APP_URL . '/index.php?page=documents&action=show&id=' . $id);
        }

        $formData = $_SESSION['form_data'] ?? [];
        unset($_SESSION['form_data']);

        renderPage(VIEWS_PATH . '/admin/documents/edit.php', array_merge($this->formLookups(), [
            'doc'         => $doc,
            'formData'    => $formData ?: $doc,
            'pageTitle'   => 'Edit Document',
            'breadcrumbs' => [
                ['label' => 'Documents', 'url' => APP_URL . '/index.php?page=documents'],
                ['label' => $doc['document_code'] ?? '', 'url' => APP_URL . '/index.php?page=documents&action=show&id=' . $id],
                ['label' => 'Edit'],
            ],
        ]));
    }

    public function archive(): void
    {
        authorize('documents.archive');
        enforceCSRF();

        $id  = (int) ($_POST['id'] ?? 0);
        $doc = $id > 0 ? $this->model->findById($id) : null;

        // Withdrawing another desk's document takes it off their listing.
        authorizeRecord($doc !== null && documentRecordAllows($doc), 'document', $id);

        if ($this->model->archive($id)) {
            logAudit('archived_document', 'document', $id, 'active', 'archived');
            setFlash('success', 'Document archived. It stays on file and can be restored.');
        } else {
            setFlash('info', 'That document is already archived.');
        }

        redirect($this->backUrl());
    }

    public function restore(): void
    {
        authorize('documents.restore');
        enforceCSRF();

        $id  = (int) ($_POST['id'] ?? 0);
        $doc = $id > 0 ? $this->model->findById($id) : null;

        // Restoring is the mirror of archiving, and was the way round it:
        // without this an agent could bring back a document another desk had
        // deliberately withdrawn.
        authorizeRecord($doc !== null && documentRecordAllows($doc), 'document', $id);

        if ($this->model->restore($id)) {
            logAudit('restored_document', 'document', $id, 'archived', 'active');
            setFlash('success', 'Document restored.');
        } else {
            setFlash('error', 'That document could not be restored.');
        }

        redirect($this->backUrl());
    }

    /** Permanent deletion. Administrators only; everyone else archives. */
    public function delete(): void
    {
        authorize('documents.delete');
        enforceCSRF();

        $id  = (int) ($_POST['id'] ?? 0);
        $doc = $id > 0 ? $this->model->findById($id) : null;

        authorizeRecord($doc !== null && documentRecordAllows($doc), 'document', $id);

        if ($this->model->delete($id)) {
            logAudit('deleted_document', 'document', $id, trim(($doc['document_code'] ?? '') . ' ' . $doc['title']), '');
            setFlash('success', 'Document deleted permanently.');
        } else {
            setFlash('error', 'The document could not be deleted.');
        }

        redirect($this->backUrl());
    }

    /* ─────────────────────────────────────────────────────────────────
       Internals
       ───────────────────────────────────────────────────────────────── */

    /** Option lists the upload and edit forms need. */
    private function formLookups(): array
    {
        $db = getDBConnection();

        // Scoped: an agent files paperwork against their own listings. This is
        // what the form offers; create() re-checks the submitted id.
        [$scope, $params] = propertyRecordScope('p');
        $properties = $db->prepare("
            SELECT p.id, p.title, p.property_code
            FROM properties p
            WHERE p.is_archived = 0 AND ({$scope})
            ORDER BY p.title
        ");
        $properties->execute($params);

        return [
            'categories'   => $this->categories->options(),
            'categoryMeta' => $this->categories->formMeta(),
            'properties'   => $properties->fetchAll(),
        ];
    }

    /**
     * Pull the submitted fields.
     *
     * Text is stored raw and escaped at output. Running sanitize() here would
     * put HTML entities in the database and then escape them again on the way
     * out — the double-encoding bug the settings migration had to repair.
     */
    private function extractData(): array
    {
        return [
            'category_id'    => (int) ($_POST['category_id'] ?? 0),
            'title'          => trim((string) ($_POST['title'] ?? '')),
            'description'    => trim((string) ($_POST['description'] ?? '')),
            'doc_number'     => trim((string) ($_POST['doc_number'] ?? '')),
            'document_date'  => trim((string) ($_POST['document_date'] ?? '')),
            'expiry_date'    => trim((string) ($_POST['expiry_date'] ?? '')),
            'visibility'     => (string) ($_POST['visibility'] ?? 'staff'),
            'reference_type' => 'property',
            'reference_id'   => (int) ($_POST['property_id'] ?? 0),
            'return_to'      => (string) ($_POST['return_to'] ?? ''),
        ];
    }

    /**
     * Validate. Returns a flat list of sentences, matching how
     * PropertyController::validateProperty() reports problems.
     */
    private function validate(array $d): array
    {
        $errors = [];

        if ($d['title'] === '') {
            $errors[] = 'A document name is required.';
        } elseif (mb_strlen($d['title']) > 200) {
            $errors[] = 'The document name must be 200 characters or fewer.';
        }

        if ($d['category_id'] < 1) {
            $errors[] = 'Choose a document category.';
        } elseif (!$this->categories->findById($d['category_id'])) {
            $errors[] = 'That document category no longer exists.';
        }

        if ($d['reference_id'] < 1) {
            $errors[] = 'Choose the property this document belongs to.';
        }

        if (!array_key_exists($d['visibility'], DOC_VISIBILITIES)) {
            $errors[] = 'Choose a valid visibility level.';
        }

        foreach (['document_date' => 'document date', 'expiry_date' => 'expiry date'] as $field => $label) {
            if ($d[$field] !== '' && !$this->isValidDate($d[$field])) {
                $errors[] = 'Enter a valid ' . $label . '.';
            }
        }

        if ($d['document_date'] !== '' && $d['expiry_date'] !== ''
            && $this->isValidDate($d['document_date']) && $this->isValidDate($d['expiry_date'])
            && $d['expiry_date'] < $d['document_date']) {
            $errors[] = 'The expiry date cannot be before the document date.';
        }

        if (mb_strlen($d['doc_number']) > 100) {
            $errors[] = 'The reference number must be 100 characters or fewer.';
        }

        return $errors;
    }

    private function isValidDate(string $value): bool
    {
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $d !== false && $d->format('Y-m-d') === $value;
    }

    /**
     * Where to send the user after an upload.
     *
     * Uploading from a property page returns there; uploading from the
     * documents list returns to the list. On failure the modal is reopened so
     * the entry can be corrected where it was made.
     */
    private function returnUrl(array $d, bool $failed): string
    {
        if ($d['return_to'] === 'property' && $d['reference_id'] > 0) {
            return APP_URL . '/index.php?page=properties&action=show&id=' . $d['reference_id']
                 . ($failed ? '&modal=upload' : '');
        }

        return APP_URL . '/index.php?page=documents' . ($failed ? '&modal=upload' : '');
    }

    /** Return target for the row actions, which post from either screen. */
    private function backUrl(): string
    {
        $propertyId = (int) ($_POST['property_id'] ?? 0);
        if ($propertyId > 0) {
            return APP_URL . '/index.php?page=properties&action=show&id=' . $propertyId;
        }
        return APP_URL . '/index.php?page=documents';
    }
}
