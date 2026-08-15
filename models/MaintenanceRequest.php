<?php
/**
 * MaintenanceRequest Model
 */
class MaintenanceRequest
{
    private PDO $db;

    public function __construct()
    {
        $this->db = getDBConnection();
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare("
            SELECT m.*, p.title AS property_title, p.property_code,
                   c.full_name AS customer_name,
                   r.full_name AS reporter_name,
                   a.full_name AS assigned_name
            FROM maintenance_requests m
            JOIN properties p ON m.property_id = p.id
            LEFT JOIN customers c ON m.customer_id = c.id
            LEFT JOIN users r ON m.reported_by = r.id
            LEFT JOIN users a ON m.assigned_to = a.id
            WHERE m.id = :id
        ");
        $stmt->execute([':id' => $id]);
        return $stmt->fetch() ?: null;
    }

    public function create(array $d): int|false
    {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO maintenance_requests (request_code, property_id, customer_id, reported_by,
                    issue_type, description, priority, photos, status)
                VALUES (:code, :pid, :cid, :rb, :it, :desc, :pri, :ph, 'new')
            ");
            $stmt->execute([
                ':code' => $d['request_code'] ?? generateCode('MNT'),
                ':pid'  => $d['property_id'],
                ':cid'  => $d['customer_id'] ?? null,
                ':rb'   => $_SESSION['user_id'] ?? null,
                ':it'   => $d['issue_type'] ?? '',
                ':desc' => $d['description'],
                ':pri'  => $d['priority'] ?? 'medium',
                ':ph'   => $d['photos'] ?? '',
            ]);
            return (int) $this->db->lastInsertId();
        } catch (PDOException $e) {
            error_log('Maintenance create error: ' . $e->getMessage());
            return false;
        }
    }

    public function update(int $id, array $d): bool
    {
        $allowed = ['issue_type','description','priority','assigned_to','cost_estimate','actual_cost',
            'status','completion_date','completion_notes','photos'];
        $fields = []; $params = [':id' => $id];
        foreach ($allowed as $f) {
            if (array_key_exists($f, $d)) { $fields[] = "$f = :$f"; $params[":$f"] = $d[$f]; }
        }
        if (!$fields) return false;
        return $this->db->prepare("UPDATE maintenance_requests SET " . implode(', ', $fields) . " WHERE id = :id")->execute($params);
    }

    public function getAll(array $filters = [], int $limit = ITEMS_PER_PAGE, int $offset = 0): array
    {
        $where = []; $params = [];
        if (!empty($filters['status'])) { $where[] = "m.status = :st"; $params[':st'] = $filters['status']; }
        if (!empty($filters['priority'])) { $where[] = "m.priority = :pr"; $params[':pr'] = $filters['priority']; }
        if (!empty($filters['assigned_to'])) { $where[] = "m.assigned_to = :at"; $params[':at'] = $filters['assigned_to']; }
        if (!empty($filters['search'])) {
            $where[] = "(m.request_code LIKE :s OR m.description LIKE :s OR p.title LIKE :s)";
            $params[':s'] = '%' . $filters['search'] . '%';
        }
        $wc = $where ? 'WHERE ' . implode(' AND ', $where) : '';
        $stmt = $this->db->prepare("
            SELECT m.*, p.title AS property_title, p.property_code,
                   c.full_name AS customer_name, a.full_name AS assigned_name
            FROM maintenance_requests m
            JOIN properties p ON m.property_id = p.id
            LEFT JOIN customers c ON m.customer_id = c.id
            LEFT JOIN users a ON m.assigned_to = a.id
            {$wc}
            ORDER BY FIELD(m.priority,'urgent','high','medium','low'), m.created_at DESC
            LIMIT :l OFFSET :o
        ");
        foreach ($params as $k => $v) $stmt->bindValue($k, $v);
        $stmt->bindValue(':l', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':o', $offset, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function count(array $filters = []): int
    {
        $where = []; $params = [];
        if (!empty($filters['status'])) { $where[] = "status = :st"; $params[':st'] = $filters['status']; }
        $wc = $where ? 'WHERE ' . implode(' AND ', $where) : '';
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM maintenance_requests {$wc}");
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }
}
