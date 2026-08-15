<?php
/**
 * Testimonial model — public customer reviews.
 *
 * Only approved rows ever leave this class via getApproved(), so a view can
 * never accidentally publish an unmoderated review, and the aggregate rating
 * in the structured data is always computed from the same set the visitor
 * can actually read on the page.
 */
class Testimonial
{
    private PDO $db;

    public function __construct()
    {
        $this->db = getDBConnection();
    }

    /**
     * Approved reviews for public display, newest-weighted by sort_order.
     * Returns [] when the table is absent so an install that has not run
     * database/testimonials.sql still renders the site.
     */
    public function getApproved(int $limit = 6): array
    {
        try {
            $stmt = $this->db->prepare("
                SELECT t.id, t.author_name, t.author_role, t.author_photo,
                       t.rating, t.body, t.created_at,
                       p.title AS property_title, p.id AS property_id,
                       u.full_name AS agent_name
                FROM testimonials t
                LEFT JOIN properties p ON t.property_id = p.id
                LEFT JOIN users u      ON t.agent_id    = u.id
                WHERE t.is_approved = 1
                ORDER BY t.sort_order ASC, t.created_at DESC
                LIMIT :lim
            ");
            $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll();
        } catch (Throwable $e) {
            return [];
        }
    }

    /** Count + mean rating across all approved reviews (not just the shown page). */
    public function ratingSummary(): array
    {
        try {
            $row = $this->db->query("
                SELECT COUNT(*) AS n, AVG(rating) AS avg_rating
                FROM testimonials WHERE is_approved = 1
            ")->fetch();

            return [
                'count'   => (int) ($row['n'] ?? 0),
                'average' => $row && $row['n'] > 0 ? round((float) $row['avg_rating'], 1) : 0.0,
            ];
        } catch (Throwable $e) {
            return ['count' => 0, 'average' => 0.0];
        }
    }

    /** Every review, approved or not — admin listing only. */
    public function getAll(string $filter = ''): array
    {
        try {
            $where = match ($filter) {
                'pending'  => 'WHERE t.is_approved = 0',
                'approved' => 'WHERE t.is_approved = 1',
                default    => '',
            };
            return $this->db->query("
                SELECT t.*, p.title AS property_title, u.full_name AS agent_name
                FROM testimonials t
                LEFT JOIN properties p ON t.property_id = p.id
                LEFT JOIN users u      ON t.agent_id    = u.id
                {$where}
                ORDER BY t.is_approved ASC, t.sort_order ASC, t.created_at DESC
            ")->fetchAll();
        } catch (Throwable $e) {
            return [];
        }
    }

    public function findById(int $id): ?array
    {
        try {
            $stmt = $this->db->prepare("SELECT * FROM testimonials WHERE id = :id");
            $stmt->execute([':id' => $id]);
            return $stmt->fetch() ?: null;
        } catch (Throwable $e) {
            return null;
        }
    }

    /** Publish / unpublish. Returns the resulting state. */
    public function setApproved(int $id, bool $approved): bool
    {
        try {
            $stmt = $this->db->prepare(
                "UPDATE testimonials SET is_approved = :a WHERE id = :id"
            );
            return $stmt->execute([':a' => $approved ? 1 : 0, ':id' => $id]);
        } catch (Throwable $e) {
            return false;
        }
    }

    public function update(int $id, array $data): bool
    {
        try {
            $stmt = $this->db->prepare("
                UPDATE testimonials SET
                    author_name = :author_name, author_role = :author_role,
                    rating = :rating, body = :body,
                    property_id = :property_id, agent_id = :agent_id,
                    is_approved = :is_approved, sort_order = :sort_order
                WHERE id = :id
            ");
            return $stmt->execute([
                ':author_name' => $data['author_name'],
                ':author_role' => $data['author_role'] ?: null,
                ':rating'      => max(1, min(5, (int) ($data['rating'] ?? 5))),
                ':body'        => $data['body'],
                ':property_id' => $data['property_id'] ?: null,
                ':agent_id'    => $data['agent_id'] ?: null,
                ':is_approved' => !empty($data['is_approved']) ? 1 : 0,
                ':sort_order'  => (int) ($data['sort_order'] ?? 0),
                ':id'          => $id,
            ]);
        } catch (Throwable $e) {
            return false;
        }
    }

    public function delete(int $id): bool
    {
        try {
            return $this->db->prepare("DELETE FROM testimonials WHERE id = :id")
                            ->execute([':id' => $id]);
        } catch (Throwable $e) {
            return false;
        }
    }

    public function create(array $data): int|false
    {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO testimonials
                    (customer_id, property_id, agent_id, author_name, author_role,
                     author_photo, rating, body, is_approved, sort_order)
                VALUES
                    (:customer_id, :property_id, :agent_id, :author_name, :author_role,
                     :author_photo, :rating, :body, :is_approved, :sort_order)
            ");
            $stmt->execute([
                ':customer_id' => $data['customer_id'] ?: null,
                ':property_id' => $data['property_id'] ?: null,
                ':agent_id'    => $data['agent_id'] ?: null,
                ':author_name' => $data['author_name'],
                ':author_role' => $data['author_role'] ?? null,
                ':author_photo'=> $data['author_photo'] ?? null,
                ':rating'      => max(1, min(5, (int) ($data['rating'] ?? 5))),
                ':body'        => $data['body'],
                ':is_approved' => !empty($data['is_approved']) ? 1 : 0,
                ':sort_order'  => (int) ($data['sort_order'] ?? 0),
            ]);
            return (int) $this->db->lastInsertId();
        } catch (Throwable $e) {
            return false;
        }
    }
}
