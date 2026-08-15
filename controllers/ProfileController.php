<?php
/**
 * Profile Controller — current user editing own profile.
 */
require_once BASE_PATH . '/models/User.php';

class ProfileController
{
    private User $model;

    public function __construct()
    {
        $this->model = new User();
    }

    public function index(): void
    {
        requireLogin();
        $user = $this->model->findById($_SESSION['user_id']);
        renderPage(VIEWS_PATH . '/admin/profile/index.php', [
            'user' => $user,
            'pageTitle' => 'My Profile',
            'breadcrumbs' => [['label' => 'Profile']],
        ]);
    }

    public function update(): void
    {
        requireLogin();
        enforceCSRF();
        $id = (int)$_SESSION['user_id'];
        $data = [
            'full_name' => sanitize($_POST['full_name']),
            'email'     => sanitize($_POST['email']),
            'phone'     => sanitize($_POST['phone'] ?? ''),
        ];
        if (!empty($_FILES['avatar']['name'])) {
            $path = uploadFile($_FILES['avatar'], 'avatars', ALLOWED_IMAGE_TYPES);
            if ($path) {
                $data['avatar'] = $path;
                $_SESSION['user_avatar'] = $path;
            }
        }
        $this->model->update($id, $data);
        // Refresh session
        $_SESSION['user_name'] = $data['full_name'];
        $_SESSION['user_email'] = $data['email'];

        // Change password if provided
        if (!empty($_POST['new_password'])) {
            if (strlen($_POST['new_password']) >= 8 && $_POST['new_password'] === ($_POST['confirm_password'] ?? '')) {
                $this->model->changePassword($id, $_POST['new_password']);
                setFlash('success', 'Profile and password updated.');
            } else {
                setFlash('error', 'Profile saved, but passwords did not match or were too short.');
            }
        } else {
            setFlash('success', 'Profile updated.');
        }
        redirect(APP_URL . '/index.php?page=profile');
    }
}
