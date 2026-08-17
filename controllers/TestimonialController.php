<?php
/**
 * Testimonial Controller — moderation of public customer reviews.
 *
 * Reviews are the only public content on the site that quotes a named person,
 * so nothing reaches the marketing pages without an admin approving it here.
 */
require_once BASE_PATH . '/models/Testimonial.php';

class TestimonialController
{
    private Testimonial $model;

    public function __construct()
    {
        $this->model = new Testimonial();
    }

    public function index(): void
    {
        authorize('testimonials.view');

        $filter       = $_GET['filter'] ?? '';
        $testimonials = $this->model->getAll($filter);
        $summary      = $this->model->ratingSummary();

        // The quick-add popup lives on this page, so it needs the entry kept
        // back after a rejected save to reopen where the user left off.
        $formData = $_SESSION['form_data'] ?? [];
        unset($_SESSION['form_data']);

        renderPage(VIEWS_PATH . '/admin/testimonials/index.php', [
            'testimonials' => $testimonials,
            'filter'       => $filter,
            'summary'      => $summary,
            'formData'     => $formData,
            'openCreateModal' => ($_GET['modal'] ?? '') === 'create',
            'pageTitle'    => 'Testimonials',
            'breadcrumbs'  => [['label' => 'Testimonials']],
        ]);
    }

    public function form(): void
    {
        authorize('testimonials.form');

        $id          = (int) ($_GET['id'] ?? 0);
        $testimonial = $id > 0 ? $this->model->findById($id) : null;

        if ($id > 0 && !$testimonial) {
            setFlash('error', 'Testimonial not found.');
            redirect(APP_URL . '/index.php?page=testimonials');
        }

        $formData = $_SESSION['form_data'] ?? [];
        unset($_SESSION['form_data']);

        renderPage(VIEWS_PATH . '/admin/testimonials/form.php', [
            'testimonial' => $testimonial,
            'formData'    => $formData,
            'pageTitle'   => $testimonial ? 'Edit testimonial' : 'Add testimonial',
            'breadcrumbs' => [
                ['label' => 'Testimonials', 'url' => APP_URL . '/index.php?page=testimonials'],
                ['label' => $testimonial ? 'Edit' : 'Add'],
            ],
        ]);
    }

    public function save(): void
    {
        authorize('testimonials.save');
        enforceCSRF();

        $id   = (int) ($_POST['id'] ?? 0);
        $data = [
            'author_name' => trim((string) ($_POST['author_name'] ?? '')),
            'author_role' => trim((string) ($_POST['author_role'] ?? '')),
            'rating'      => (int) ($_POST['rating'] ?? 5),
            'body'        => trim((string) ($_POST['body'] ?? '')),
            'property_id' => (int) ($_POST['property_id'] ?? 0) ?: null,
            'agent_id'    => (int) ($_POST['agent_id'] ?? 0) ?: null,
            'is_approved' => !empty($_POST['is_approved']),
            'sort_order'  => (int) ($_POST['sort_order'] ?? 0),
        ];

        if ($data['author_name'] === '' || $data['body'] === '') {
            setFlash('error', 'A name and the review text are both required.');
            $_SESSION['form_data'] = $data;
            // A save from the popup returns to the popup, so a rejected
            // review is corrected where it was typed.
            redirect(($_POST['return_to'] ?? '') === 'modal'
                ? APP_URL . '/index.php?page=testimonials&modal=create'
                : APP_URL . '/index.php?page=testimonials&action=form' . ($id ? '&id=' . $id : ''));
        }

        $ok = $id > 0 ? $this->model->update($id, $data) : (bool) $this->model->create($data);

        setFlash($ok ? 'success' : 'error', $ok
            ? 'Testimonial saved.' . ($data['is_approved'] ? ' It is now live on the home page.' : ' It stays hidden until approved.')
            : 'Could not save the testimonial.');
        redirect(APP_URL . '/index.php?page=testimonials');
    }

    /** Toggle publication straight from the list. */
    public function approve(): void
    {
        authorize('testimonials.approve');
        enforceCSRF();

        $id      = (int) ($_POST['id'] ?? 0);
        $approve = !empty($_POST['approve']);

        if ($this->model->setApproved($id, $approve)) {
            setFlash('success', $approve
                ? 'Testimonial published — it is now visible on the home page.'
                : 'Testimonial hidden from the public site.');
        } else {
            setFlash('error', 'Could not update the testimonial.');
        }
        redirect(APP_URL . '/index.php?page=testimonials');
    }

    public function delete(): void
    {
        authorize('testimonials.delete');
        enforceCSRF();

        $id = (int) ($_POST['id'] ?? 0);
        setFlash(
            $this->model->delete($id) ? 'success' : 'error',
            $this->model->findById($id) ? 'Could not delete the testimonial.' : 'Testimonial deleted.'
        );
        redirect(APP_URL . '/index.php?page=testimonials');
    }
}
