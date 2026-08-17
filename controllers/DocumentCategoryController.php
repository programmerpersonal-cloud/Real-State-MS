<?php
/**
 * Document Category Controller — administrators configure the document types.
 *
 * Reordering is done with up/down buttons that POST, rather than drag-and-drop:
 * this application has no AJAX anywhere, and a pair of buttons is honest about
 * that instead of pretending to be something it is not.
 */
require_once BASE_PATH . '/models/DocumentCategory.php';

class DocumentCategoryController
{
    private DocumentCategory $model;

    public function __construct()
    {
        $this->model = new DocumentCategory();
    }

    public function index(): void
    {
        authorize('document-categories.view');

        $formData = $_SESSION['form_data'] ?? [];
        unset($_SESSION['form_data']);

        // ?modal=edit&id= reopens the popup over the row being changed.
        $editId  = (int) ($_GET['id'] ?? 0);
        $editing = ($_GET['modal'] ?? '') === 'edit' && $editId > 0
            ? $this->model->findById($editId)
            : null;

        renderPage(VIEWS_PATH . '/admin/documents/categories.php', [
            'categories'    => $this->model->getAll(),
            'formData'      => $formData,
            'editing'       => $editing,
            'openModal'     => in_array($_GET['modal'] ?? '', ['create', 'edit'], true),
            'visibilities'  => DOC_VISIBILITIES,
            'pageTitle'     => 'Document Categories',
            'pageSubtitle'  => 'The document types staff can file against a property.',
            'breadcrumbs'   => [
                ['label' => 'Documents', 'url' => APP_URL . '/index.php?page=documents'],
                ['label' => 'Categories'],
            ],
            'actionButton'  => [
                'label' => 'Add Category',
                'icon'  => 'bi-plus-lg',
                'url'   => APP_URL . '/index.php?page=document-categories&modal=create',
                'attrs' => ['data-modal-open' => 'categoryModal'],
            ],
        ]);
    }

    /** Create or update, depending on whether an id came along. */
    public function save(): void
    {
        authorize('document-categories.save');
        enforceCSRF();

        $id   = (int) ($_POST['id'] ?? 0);
        $data = [
            'name'               => trim((string) ($_POST['name'] ?? '')),
            'description'        => trim((string) ($_POST['description'] ?? '')),
            'icon'               => trim((string) ($_POST['icon'] ?? '')) ?: 'bi-file-earmark-text',
            'default_visibility' => (string) ($_POST['default_visibility'] ?? 'staff'),
            'requires_expiry'    => !empty($_POST['requires_expiry']) ? 1 : 0,
            'is_active'          => !empty($_POST['is_active']) ? 1 : 0,
        ];

        $errors = [];
        if ($data['name'] === '') {
            $errors[] = 'A category name is required.';
        } elseif (mb_strlen($data['name']) > 100) {
            $errors[] = 'The category name must be 100 characters or fewer.';
        }
        if (!array_key_exists($data['default_visibility'], DOC_VISIBILITIES)) {
            $errors[] = 'Choose a valid default visibility.';
        }
        // Only Bootstrap Icons class names — this string is printed into markup.
        if (!preg_match('/^bi-[a-z0-9-]{1,40}$/', $data['icon'])) {
            $errors[] = 'The icon must be a Bootstrap Icons name, such as bi-file-earmark-text.';
        }

        $failUrl = APP_URL . '/index.php?page=document-categories&modal=' . ($id > 0 ? 'edit&id=' . $id : 'create');

        if ($errors) {
            setFlash('error', implode(' ', $errors));
            $_SESSION['form_data'] = $data + ['id' => $id];
            redirect($failUrl);
        }

        if ($id > 0) {
            $before = $this->model->findById($id);
            if (!$before) {
                setFlash('error', 'That category no longer exists.');
                redirect(APP_URL . '/index.php?page=document-categories');
            }
            $this->model->update($id, $data);
            logAudit(
                'updated_document_category',
                'document_category',
                $id,
                json_encode(array_intersect_key($before, $data), JSON_UNESCAPED_UNICODE),
                json_encode($data, JSON_UNESCAPED_UNICODE)
            );
            setFlash('success', 'Category updated.');
        } else {
            // Keep the generated slug unique without making the admin think
            // about slugs at all.
            $slug = DocumentCategory::slugify($data['name']);
            $base = $slug;
            $n    = 2;
            while ($this->model->slugExists($slug)) {
                $slug = $base . '_' . $n++;
            }
            $data['slug'] = $slug;

            $newId = $this->model->create($data);
            if (!$newId) {
                setFlash('error', 'The category could not be created.');
                $_SESSION['form_data'] = $data;
                redirect($failUrl);
            }
            logAudit('created_document_category', 'document_category', $newId, '', $data['name']);
            setFlash('success', 'Category added.');
        }

        redirect(APP_URL . '/index.php?page=document-categories');
    }

    public function toggle(): void
    {
        authorize('document-categories.toggle');
        enforceCSRF();

        $id  = (int) ($_POST['id'] ?? 0);
        $cat = $id > 0 ? $this->model->findById($id) : null;

        if (!$cat) {
            setFlash('error', 'Category not found.');
            redirect(APP_URL . '/index.php?page=document-categories');
        }

        $active = (int) $cat['is_active'] === 1;
        $this->model->setActive($id, !$active);
        logAudit('updated_document_category', 'document_category', $id,
            $active ? 'active' : 'inactive', $active ? 'inactive' : 'active');

        setFlash('success', $active
            ? 'Category deactivated. Existing documents keep it; new uploads cannot choose it.'
            : 'Category reactivated.');

        redirect(APP_URL . '/index.php?page=document-categories');
    }

    public function move(): void
    {
        authorize('document-categories.move');
        enforceCSRF();

        $id        = (int) ($_POST['id'] ?? 0);
        $direction = ($_POST['direction'] ?? '') === 'up' ? 'up' : 'down';

        if ($id < 1 || !$this->model->move($id, $direction)) {
            setFlash('info', 'That category is already at the ' . ($direction === 'up' ? 'top' : 'bottom') . '.');
        }

        redirect(APP_URL . '/index.php?page=document-categories');
    }

    /**
     * Delete, but only while nothing references the category. The database
     * would refuse anyway (fk_doc_category is RESTRICT); checking here lets the
     * message explain what to do instead.
     */
    public function delete(): void
    {
        authorize('document-categories.delete');
        enforceCSRF();

        $id  = (int) ($_POST['id'] ?? 0);
        $cat = $id > 0 ? $this->model->findById($id) : null;

        if (!$cat) {
            setFlash('error', 'Category not found.');
            redirect(APP_URL . '/index.php?page=document-categories');
        }

        $used = $this->model->countDocuments($id);
        if ($used > 0) {
            setFlash('warning', 'This category holds ' . $used . ' document' . ($used === 1 ? '' : 's')
                . ', so it cannot be deleted. Deactivate it instead to hide it from new uploads.');
            redirect(APP_URL . '/index.php?page=document-categories');
        }

        if ($this->model->delete($id)) {
            logAudit('deleted_document_category', 'document_category', $id, $cat['name'], '');
            setFlash('success', 'Category deleted.');
        } else {
            setFlash('error', 'The category could not be deleted.');
        }

        redirect(APP_URL . '/index.php?page=document-categories');
    }
}
