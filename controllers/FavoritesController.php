<?php
/**
 * Favorites Controller — customer-saved properties.
 */
class FavoritesController
{
    public function index(): void
    {
        authorize('favorites.view');
        $db = getDBConnection();
        $stmt = $db->prepare("
            SELECT p.*, f.id AS fav_id, f.created_at AS saved_at,
                   (SELECT file_path FROM property_images WHERE property_id = p.id AND is_cover=1 LIMIT 1) AS cover
            FROM favorites f
            JOIN properties p ON f.property_id = p.id
            WHERE f.user_id = ? AND p.is_archived = 0
            ORDER BY f.created_at DESC
        ");
        $stmt->execute([$_SESSION['user_id']]);
        $properties = $stmt->fetchAll();

        renderPage(VIEWS_PATH . '/customer/favorites.php', [
            'properties' => $properties,
            'pageTitle' => 'Saved Properties',
            'breadcrumbs' => [['label' => 'Favorites']],
        ]);
    }

    public function add(): void
    {
        authorize('favorites.add');
        $pid = (int)($_GET['property_id'] ?? 0);
        if ($pid) {
            $db = getDBConnection();
            try {
                $db->prepare("INSERT IGNORE INTO favorites (user_id, property_id) VALUES (?, ?)")
                   ->execute([$_SESSION['user_id'], $pid]);
                setFlash('success', 'Saved to favorites.');
            } catch (PDOException $e) { /* ignore duplicate */ }
        }
        redirect($_SERVER['HTTP_REFERER'] ?? APP_URL . '/index.php?page=favorites');
    }

    public function remove(): void
    {
        authorize('favorites.remove');
        $pid = (int)($_GET['property_id'] ?? 0);
        if ($pid) {
            getDBConnection()->prepare("DELETE FROM favorites WHERE user_id = ? AND property_id = ?")
                             ->execute([$_SESSION['user_id'], $pid]);
            setFlash('success', 'Removed from favorites.');
        }
        redirect($_SERVER['HTTP_REFERER'] ?? APP_URL . '/index.php?page=favorites');
    }
}
