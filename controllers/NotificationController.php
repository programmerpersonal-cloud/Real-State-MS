<?php
/**
 * Notification Controller — in-app notification inbox.
 */
class NotificationController
{
    public function index(): void
    {
        requireLogin();
        $db = getDBConnection();
        $stmt = $db->prepare("SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 100");
        $stmt->execute([$_SESSION['user_id']]);
        $notifications = $stmt->fetchAll();

        renderPage(VIEWS_PATH . '/admin/notifications/index.php', [
            'notifications' => $notifications,
            'pageTitle' => 'Notifications',
            'breadcrumbs' => [['label' => 'Notifications']],
        ]);
    }

    public function markRead(): void
    {
        requireLogin();
        $id = (int)($_GET['id'] ?? 0);
        $db = getDBConnection();
        $db->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?")
           ->execute([$id, $_SESSION['user_id']]);
        redirect($_SERVER['HTTP_REFERER'] ?? (APP_URL . '/index.php?page=notifications'));
    }

    public function readAll(): void
    {
        requireLogin();
        getDBConnection()->prepare("UPDATE notifications SET is_read=1 WHERE user_id = ?")
                         ->execute([$_SESSION['user_id']]);
        setFlash('success', 'All notifications marked as read.');
        redirect(APP_URL . '/index.php?page=notifications');
    }
}
