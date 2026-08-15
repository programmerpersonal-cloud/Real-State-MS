<?php
/**
 * Inquiry Model
 */
class Inquiry
{
    private PDO $db;

    public function __construct()
    {
        $this->db = getDBConnection();
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare("
            SELECT i.*, p.title AS property_title, c.full_name AS customer_name,
                   u.full_name AS assigned_name
            FROM inquiries i
            LEFT JOIN properties p ON i.property_id = p.id
            LEFT JOIN customers c ON i.customer_id = c.id
            LEFT JOIN users u ON i.assigned_to = u.id
            WHERE i.id = :id
        ");
        $stmt->execute([':id' => $id]);
        return $stmt->fetch() ?: null;
    }

    public function create(array $d): int|false
    {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO inquiries (property_id, customer_id, name, email, phone, subject, message, status)
                VALUES (:pid, :cid, :name, :em, :ph, :sub, :msg, 'open')
            ");
            $stmt->execute([
                ':pid' => $d['property_id'] ?? null,
                ':cid' => $d['customer_id'] ?? null,
                ':name'=> $d['name'] ?? '',
                ':em'  => $d['email'] ?? '',
                ':ph'  => $d['phone'] ?? '',
                ':sub' => $d['subject'] ?? '',
                ':msg' => $d['message'],
            ]);
            return (int) $this->db->lastInsertId();
        } catch (PDOException $e) {
            error_log('Inquiry create error: ' . $e->getMessage());
            return false;
        }
    }

    public function update(int $id, array $d): bool
    {
        $allowed = ['status','assigned_to','subject','message'];
        $fields = []; $params = [':id' => $id];
        foreach ($allowed as $f) {
            if (array_key_exists($f, $d)) { $fields[] = "$f = :$f"; $params[":$f"] = $d[$f]; }
        }
        if (!$fields) return false;
        return $this->db->prepare("UPDATE inquiries SET " . implode(', ', $fields) . " WHERE id = :id")->execute($params);
    }

    public function getAll(array $filters = [], int $limit = ITEMS_PER_PAGE, int $offset = 0): array
    {
        $where = []; $params = [];
        if (!empty($filters['status'])) { $where[] = "i.status = :st"; $params[':st'] = $filters['status']; }
        if (!empty($filters['search'])) {
            $where[] = "(i.name LIKE :s OR i.email LIKE :s OR i.subject LIKE :s OR i.message LIKE :s)";
            $params[':s'] = '%' . $filters['search'] . '%';
        }
        $wc = $where ? 'WHERE ' . implode(' AND ', $where) : '';
        $stmt = $this->db->prepare("
            SELECT i.*, p.title AS property_title, c.full_name AS customer_name,
                   u.full_name AS assigned_name
            FROM inquiries i
            LEFT JOIN properties p ON i.property_id = p.id
            LEFT JOIN customers c ON i.customer_id = c.id
            LEFT JOIN users u ON i.assigned_to = u.id
            {$wc}
            ORDER BY i.created_at DESC
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
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM inquiries {$wc}");
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    public function getMessages(int $inquiryId): array
    {
        $stmt = $this->db->prepare("
            SELECT m.*, u.full_name AS sender_name
            FROM messages m
            JOIN users u ON m.sender_id = u.id
            WHERE m.inquiry_id = ?
            ORDER BY m.created_at ASC
        ");
        $stmt->execute([$inquiryId]);
        return $stmt->fetchAll();
    }

    public function addMessage(int $inquiryId, int $senderId, int $receiverId, string $body): int
    {
        $stmt = $this->db->prepare("
            INSERT INTO messages (sender_id, receiver_id, inquiry_id, subject, body)
            VALUES (?,?,?, 'Re: inquiry', ?)
        ");
        $stmt->execute([$senderId, $receiverId, $inquiryId, $body]);
        return (int) $this->db->lastInsertId();
    }
}
