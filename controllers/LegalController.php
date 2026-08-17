<?php
/**
 * Legal Controller — Terms & Conditions configuration and version history.
 *
 * The editing rules here exist to protect the acceptance records, not to be
 * awkward: a draft can be changed freely, a published version never can. When
 * the terms change, an administrator revises them into a new version and
 * activates it, which supersedes the old one and leaves it — and every
 * acceptance pointing at it — exactly as it was.
 */
require_once BASE_PATH . '/models/TermsDocument.php';
require_once BASE_PATH . '/models/TermsVersion.php';
require_once BASE_PATH . '/models/TermsAcceptance.php';

class LegalController
{
    private TermsDocument $types;
    private TermsVersion $versions;
    private TermsAcceptance $acceptances;

    public function __construct()
    {
        $this->types       = new TermsDocument();
        $this->versions    = new TermsVersion();
        $this->acceptances = new TermsAcceptance();
    }

    /** Overview: every legal type, its live version and its history. */
    public function index(): void
    {
        authorize('legal.view');

        $types = $this->types->getAll();

        // One version list per type, so the tabs render without a query per click.
        $versionsByType = [];
        foreach ($types as $t) {
            $versionsByType[(int) $t['id']] = $this->versions->forDocument((int) $t['id']);
        }

        $formData = $_SESSION['form_data'] ?? [];
        unset($_SESSION['form_data']);

        renderPage(VIEWS_PATH . '/admin/legal/index.php', [
            'types'          => $types,
            'versionsByType' => $versionsByType,
            'formData'       => $formData,
            'editingType'    => ($_GET['modal'] ?? '') === 'type' && !empty($_GET['id'])
                ? $this->types->findById((int) $_GET['id']) : null,
            'openTypeModal'  => in_array($_GET['modal'] ?? '', ['type', 'new-type'], true),
            'pageTitle'      => 'Terms & Conditions',
            'pageSubtitle'   => 'Versioned legal content. Published versions are never edited — revising creates a new version.',
            'breadcrumbs'    => [['label' => 'Terms & Conditions']],
        ]);
    }

    /** One version, rendered read-only, with its acceptance summary. */
    public function version(): void
    {
        authorize('legal.version');

        $id      = (int) ($_GET['id'] ?? 0);
        $version = $id > 0 ? $this->versions->findById($id) : null;

        if (!$version) {
            setFlash('error', 'That terms version does not exist.');
            redirect(APP_URL . '/index.php?page=legal');
        }

        renderPage(VIEWS_PATH . '/admin/legal/version_show.php', [
            'version'     => $version,
            'editable'    => $this->versions->isEditable($id),
            'pageTitle'   => $version['name'] . ' ' . $version['version_code'],
            'breadcrumbs' => [
                ['label' => 'Terms & Conditions', 'url' => APP_URL . '/index.php?page=legal'],
                ['label' => $version['version_code']],
            ],
        ]);
    }

    /** New draft: GET renders the editor, POST stores it. */
    public function create(): void
    {
        authorize('legal.create');

        $typeId = (int) ($_GET['doc'] ?? $_POST['terms_document_id'] ?? 0);
        $type   = $typeId > 0 ? $this->types->findById($typeId) : null;

        if (!$type) {
            setFlash('error', 'Choose which terms you want to write.');
            redirect(APP_URL . '/index.php?page=legal');
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            enforceCSRF();

            $data   = $this->extractVersion();
            $errors = $this->validateVersion($data);

            if ($errors) {
                setFlash('error', implode(' ', $errors));
                $_SESSION['form_data'] = $data;
                redirect(APP_URL . '/index.php?page=legal&action=create&doc=' . $typeId);
            }

            $id = $this->versions->create($typeId, $data);
            if (!$id) {
                setFlash('error', 'The version could not be saved.');
                $_SESSION['form_data'] = $data;
                redirect(APP_URL . '/index.php?page=legal&action=create&doc=' . $typeId);
            }

            logAudit('created_terms_version', 'terms_version', $id, '', $type['name'] . ' draft');

            // "Save and publish" activates in the same step; plain save leaves
            // it as a draft so it can be reviewed first.
            if (!empty($_POST['publish'])) {
                $this->publishAndRedirect($id, $data['effective_from']); // never returns
            }

            setFlash('success', 'Draft saved. Review it, then publish when you are ready.');
            redirect(APP_URL . '/index.php?page=legal&action=edit&id=' . $id);
        }

        $formData = $_SESSION['form_data'] ?? [];
        unset($_SESSION['form_data']);

        renderPage(VIEWS_PATH . '/admin/legal/version_form.php', [
            'type'        => $type,
            'version'     => null,
            'formData'    => $formData,
            'nextCode'    => 'v' . $this->versions->nextVersionNumber($typeId),
            'pageTitle'   => 'New ' . $type['name'] . ' version',
            'breadcrumbs' => [
                ['label' => 'Terms & Conditions', 'url' => APP_URL . '/index.php?page=legal'],
                ['label' => 'New version'],
            ],
        ]);
    }

    /** Edit a draft. Refuses anything already published. */
    public function edit(): void
    {
        authorize('legal.edit');

        $id      = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
        $version = $id > 0 ? $this->versions->findById($id) : null;

        if (!$version) {
            setFlash('error', 'That terms version does not exist.');
            redirect(APP_URL . '/index.php?page=legal');
        }

        if (!$this->versions->isEditable($id)) {
            setFlash('warning', 'Published terms cannot be edited — that is what makes the acceptance records '
                . 'meaningful. Use Revise to start a new version from this wording.');
            redirect(APP_URL . '/index.php?page=legal&action=version&id=' . $id);
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            enforceCSRF();

            $data   = $this->extractVersion();
            $errors = $this->validateVersion($data);

            if ($errors) {
                setFlash('error', implode(' ', $errors));
                $_SESSION['form_data'] = $data;
                redirect(APP_URL . '/index.php?page=legal&action=edit&id=' . $id);
            }

            $this->versions->update($id, $data);
            logAudit('updated_terms_version', 'terms_version', $id, '', $version['name'] . ' ' . $version['version_code']);

            if (!empty($_POST['publish'])) {
                $this->publishAndRedirect($id, $data['effective_from']); // never returns
            }

            setFlash('success', 'Draft saved.');
            redirect(APP_URL . '/index.php?page=legal&action=edit&id=' . $id);
        }

        $formData = $_SESSION['form_data'] ?? [];
        unset($_SESSION['form_data']);

        renderPage(VIEWS_PATH . '/admin/legal/version_form.php', [
            'type'        => $this->types->findById((int) $version['terms_document_id']),
            'version'     => $version,
            'formData'    => $formData ?: $version,
            'nextCode'    => $version['version_code'],
            'pageTitle'   => 'Edit ' . $version['version_code'],
            'breadcrumbs' => [
                ['label' => 'Terms & Conditions', 'url' => APP_URL . '/index.php?page=legal'],
                ['label' => $version['version_code']],
            ],
        ]);
    }

    /**
     * Server-rendered preview.
     *
     * Deliberately not a JavaScript twin of renderLegalText(): a second
     * implementation would drift, and the whole point of a preview is that it
     * shows exactly what will be published.
     */
    public function preview(): void
    {
        authorize('legal.preview');
        enforceCSRF();

        renderPage(VIEWS_PATH . '/admin/legal/preview.php', [
            'title'       => trim((string) ($_POST['title'] ?? 'Untitled')),
            'body'        => (string) ($_POST['body'] ?? ''),
            'backUrl'     => (string) ($_POST['back_url'] ?? APP_URL . '/index.php?page=legal'),
            'pageTitle'   => 'Preview',
            'breadcrumbs' => [
                ['label' => 'Terms & Conditions', 'url' => APP_URL . '/index.php?page=legal'],
                ['label' => 'Preview'],
            ],
        ]);
    }

    /** Make a draft live, superseding whatever it replaces. */
    public function publish(): void
    {
        authorize('legal.publish');
        enforceCSRF();

        $id = (int) ($_POST['id'] ?? 0);
        $this->publishAndRedirect($id, trim((string) ($_POST['effective_from'] ?? '')));
    }

    private function publishAndRedirect(int $id, string $effectiveFrom): never
    {
        $version = $id > 0 ? $this->versions->findById($id) : null;

        if (!$version) {
            setFlash('error', 'That terms version does not exist.');
            redirect(APP_URL . '/index.php?page=legal');
        }

        if ($effectiveFrom !== '' && !$this->isValidDate($effectiveFrom)) {
            setFlash('error', 'Enter a valid effective date.');
            redirect(APP_URL . '/index.php?page=legal&action=version&id=' . $id);
        }

        $previous = $this->versions->activeFor((string) $version['slug']);

        if ($this->versions->activate($id, $effectiveFrom ?: null)) {
            logAudit(
                'published_terms_version',
                'terms_version',
                $id,
                $previous ? $previous['version_code'] . ' (active)' : 'none',
                $version['version_code'] . ' (active)'
            );
            setFlash('success', $previous
                ? $version['name'] . ' ' . $version['version_code'] . ' is now live. '
                    . $previous['version_code'] . ' has been superseded and kept on record.'
                : $version['name'] . ' ' . $version['version_code'] . ' is now live.');
        } else {
            setFlash('error', 'That version could not be published.');
        }

        redirect(APP_URL . '/index.php?page=legal&action=version&id=' . $id);
    }

    /** Take the live version down, leaving the type unpublished. */
    public function withdraw(): void
    {
        authorize('legal.withdraw');
        enforceCSRF();

        $id      = (int) ($_POST['id'] ?? 0);
        $version = $id > 0 ? $this->versions->findById($id) : null;

        if (!$version) {
            setFlash('error', 'That terms version does not exist.');
            redirect(APP_URL . '/index.php?page=legal');
        }

        if ($this->versions->withdraw($id)) {
            logAudit('withdrew_terms_version', 'terms_version', $id, 'active', 'withdrawn');
            setFlash('warning', $version['name'] . ' now has no published version. '
                . 'Anything that requires acceptance of it will stop asking until you publish again.');
        } else {
            setFlash('error', 'Only the live version can be withdrawn.');
        }

        redirect(APP_URL . '/index.php?page=legal&action=version&id=' . $id);
    }

    /** Copy a published version into a new draft to start revising it. */
    public function revise(): void
    {
        authorize('legal.revise');
        enforceCSRF();

        $id    = (int) ($_POST['id'] ?? 0);
        $draft = $id > 0 ? $this->versions->duplicateAsDraft($id) : false;

        if (!$draft) {
            setFlash('error', 'That version could not be revised.');
            redirect(APP_URL . '/index.php?page=legal');
        }

        logAudit('created_terms_version', 'terms_version', $draft, 'revised from #' . $id, 'draft');
        setFlash('info', 'A new draft has been started from that wording. The published version is unchanged.');
        redirect(APP_URL . '/index.php?page=legal&action=edit&id=' . $draft);
    }

    /** Delete a draft that was never published. */
    public function deleteDraft(): void
    {
        authorize('legal.delete-draft');
        enforceCSRF();

        $id = (int) ($_POST['id'] ?? 0);
        if ($id > 0 && $this->versions->deleteDraft($id)) {
            logAudit('deleted_terms_version', 'terms_version', $id, 'draft', '');
            setFlash('success', 'Draft deleted.');
        } else {
            setFlash('error', 'Only an unpublished draft can be deleted.');
        }

        redirect(APP_URL . '/index.php?page=legal');
    }

    /** The acceptance log — who agreed to what, and when. */
    public function acceptances(): void
    {
        authorize('legal.acceptances');

        $filters = [
            'terms_version_id' => (int) ($_GET['id'] ?? 0),
            'slug'             => trim((string) ($_GET['slug'] ?? '')),
            'search'           => trim((string) ($_GET['search'] ?? '')),
        ];

        $page   = max(1, (int) ($_GET['p'] ?? 1));
        $offset = ($page - 1) * ITEMS_PER_PAGE;

        $rows       = $this->acceptances->getAll($filters, ITEMS_PER_PAGE, $offset);
        $totalCount = $this->acceptances->count($filters);

        renderPage(VIEWS_PATH . '/admin/legal/acceptances.php', [
            'acceptances'  => $rows,
            'filters'      => $filters,
            'types'        => $this->types->getAll(),
            'version'      => $filters['terms_version_id'] > 0
                ? $this->versions->findById($filters['terms_version_id']) : null,
            'page'         => $page,
            'totalPages'   => (int) ceil($totalCount / ITEMS_PER_PAGE),
            'totalCount'   => $totalCount,
            'pageTitle'    => 'Terms Acceptance Log',
            'pageSubtitle' => 'A permanent record of which version each person agreed to. These rows are never edited.',
            'breadcrumbs'  => [
                ['label' => 'Terms & Conditions', 'url' => APP_URL . '/index.php?page=legal'],
                ['label' => 'Acceptance log'],
            ],
        ]);
    }

    /** Create or rename a legal type. */
    public function saveType(): void
    {
        authorize('legal.save-type');
        enforceCSRF();

        $id   = (int) ($_POST['id'] ?? 0);
        $data = [
            'name'                => trim((string) ($_POST['name'] ?? '')),
            'description'         => trim((string) ($_POST['description'] ?? '')),
            'requires_acceptance' => !empty($_POST['requires_acceptance']) ? 1 : 0,
            'is_active'           => !empty($_POST['is_active']) ? 1 : 0,
        ];

        $errors = [];
        if ($data['name'] === '') {
            $errors[] = 'A name is required.';
        } elseif (mb_strlen($data['name']) > 120) {
            $errors[] = 'The name must be 120 characters or fewer.';
        }

        if ($errors) {
            setFlash('error', implode(' ', $errors));
            $_SESSION['form_data'] = $data + ['id' => $id];
            redirect(APP_URL . '/index.php?page=legal&modal=' . ($id > 0 ? 'type&id=' . $id : 'new-type'));
        }

        if ($id > 0) {
            $this->types->update($id, $data);
            logAudit('updated_terms_document', 'terms_document', $id, '', $data['name']);
            setFlash('success', 'Terms type updated.');
        } else {
            $slug = TermsDocument::slugify($data['name']);
            $base = $slug;
            $n    = 2;
            while ($this->types->slugExists($slug)) {
                $slug = $base . '_' . $n++;
            }
            $data['slug'] = $slug;

            $newId = $this->types->create($data);
            if (!$newId) {
                setFlash('error', 'The terms type could not be created.');
                redirect(APP_URL . '/index.php?page=legal');
            }
            logAudit('created_terms_document', 'terms_document', $newId, '', $data['name']);
            setFlash('success', 'Terms type added. Write its first version to publish it.');
        }

        redirect(APP_URL . '/index.php?page=legal');
    }

    public function toggleType(): void
    {
        authorize('legal.toggle-type');
        enforceCSRF();

        $id   = (int) ($_POST['id'] ?? 0);
        $type = $id > 0 ? $this->types->findById($id) : null;

        if (!$type) {
            setFlash('error', 'Terms type not found.');
            redirect(APP_URL . '/index.php?page=legal');
        }

        $active = (int) $type['is_active'] === 1;
        $this->types->setActive($id, !$active);
        logAudit('updated_terms_document', 'terms_document', $id,
            $active ? 'active' : 'inactive', $active ? 'inactive' : 'active');

        setFlash('success', $active
            ? $type['name'] . ' deactivated. Existing acceptance records are unaffected.'
            : $type['name'] . ' reactivated.');

        redirect(APP_URL . '/index.php?page=legal');
    }

    /* ─── Internals ──────────────────────────────────────────────────── */

    /**
     * Body text is stored raw and escaped at render time by renderLegalText(),
     * which escapes before it generates any markup. Sanitising here as well
     * would bake entities into the stored wording — and the stored wording is
     * what the acceptance hash is taken over.
     */
    private function extractVersion(): array
    {
        return [
            'title'          => trim((string) ($_POST['title'] ?? '')),
            'summary'        => trim((string) ($_POST['summary'] ?? '')),
            'body'           => trim((string) ($_POST['body'] ?? '')),
            'effective_from' => trim((string) ($_POST['effective_from'] ?? '')),
        ];
    }

    private function validateVersion(array $d): array
    {
        $errors = [];

        if ($d['title'] === '') {
            $errors[] = 'A title is required.';
        } elseif (mb_strlen($d['title']) > 200) {
            $errors[] = 'The title must be 200 characters or fewer.';
        }

        if ($d['body'] === '') {
            $errors[] = 'The terms text cannot be empty.';
        }

        if (mb_strlen($d['summary']) > 255) {
            $errors[] = 'The change note must be 255 characters or fewer.';
        }

        if ($d['effective_from'] !== '' && !$this->isValidDate($d['effective_from'])) {
            $errors[] = 'Enter a valid effective date.';
        }

        return $errors;
    }

    private function isValidDate(string $value): bool
    {
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $d !== false && $d->format('Y-m-d') === $value;
    }
}
