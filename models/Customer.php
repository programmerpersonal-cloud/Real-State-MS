<?php
/**
 * Saxane Real Estate Management System
 * Customer Model
 */

class Customer
{
    private PDO $db;

    public function __construct()
    {
        $this->db = getDBConnection();
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare("SELECT c.*, u.full_name AS created_by_name FROM customers c LEFT JOIN users u ON c.created_by = u.id WHERE c.id = :id");
        $stmt->execute([':id' => $id]);
        return $stmt->fetch() ?: null;
    }

    public function create(array $d): int|false
    {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO customers (user_id, full_name, email, phone, address, gender, national_id, profile_photo,
                    emergency_contact, emergency_phone, employment_status, occupation, guarantor_name, guarantor_contact,
                    customer_type, notes, risk_level, created_by, branch_id)
                VALUES (:uid,:name,:email,:phone,:addr,:gender,:nid,:photo,:ec,:ep,:emp,:occ,:gn,:gc,:ctype,:notes,:risk,:cb,:bid)
            ");
            $stmt->execute([
                ':uid' => $d['user_id'] ?? null, ':name' => $d['full_name'], ':email' => $d['email'] ?? '',
                ':phone' => $d['phone'], ':addr' => $d['address'] ?? '', ':gender' => $d['gender'] ?? null,
                ':nid' => $d['national_id'] ?? '', ':photo' => $d['profile_photo'] ?? null,
                ':ec' => $d['emergency_contact'] ?? '', ':ep' => $d['emergency_phone'] ?? '',
                ':emp' => $d['employment_status'] ?? '', ':occ' => $d['occupation'] ?? '',
                ':gn' => $d['guarantor_name'] ?? '', ':gc' => $d['guarantor_contact'] ?? '',
                ':ctype' => $d['customer_type'] ?? 'both', ':notes' => $d['notes'] ?? '',
                ':risk' => $d['risk_level'] ?? 'low', ':cb' => $_SESSION['user_id'] ?? null,
                ':bid' => $d['branch_id'] ?? null,
            ]);
            return (int) $this->db->lastInsertId();
        } catch (PDOException $e) {
            error_log('Customer create error: ' . $e->getMessage());
            return false;
        }
    }

    public function update(int $id, array $data): bool
    {
        $fields = []; $params = [':id' => $id];
        $allowed = ['full_name','email','phone','address','gender','national_id','profile_photo',
            'emergency_contact','emergency_phone','employment_status','occupation','guarantor_name',
            'guarantor_contact','customer_type','notes','risk_level','is_blacklisted','branch_id'];
        foreach ($allowed as $f) {
            if (array_key_exists($f, $data)) { $fields[] = "$f = :$f"; $params[":$f"] = $data[$f]; }
        }
        if (empty($fields)) return false;
        return $this->db->prepare("UPDATE customers SET " . implode(', ', $fields) . " WHERE id = :id")->execute($params);
    }

    public function getAll(array $filters = [], int $limit = ITEMS_PER_PAGE, int $offset = 0): array
    {
        $where = []; $params = [];
        if (!empty($filters['search'])) {
            $where[] = "(c.full_name LIKE :s OR c.phone LIKE :s OR c.email LIKE :s OR c.national_id LIKE :s)";
            $params[':s'] = '%'.$filters['search'].'%';
        }
        if (!empty($filters['customer_type'])) { $where[] = "c.customer_type = :ct"; $params[':ct'] = $filters['customer_type']; }
        if (isset($filters['is_blacklisted'])) { $where[] = "c.is_blacklisted = :bl"; $params[':bl'] = $filters['is_blacklisted']; }

        $wc = $where ? 'WHERE ' . implode(' AND ', $where) : '';
        $stmt = $this->db->prepare("SELECT c.* FROM customers c {$wc} ORDER BY c.created_at DESC LIMIT :l OFFSET :o");
        foreach ($params as $k => $v) $stmt->bindValue($k, $v);
        $stmt->bindValue(':l', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':o', $offset, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function count(array $filters = []): int
    {
        $where = []; $params = [];
        if (!empty($filters['search'])) { $where[] = "(full_name LIKE :s OR phone LIKE :s)"; $params[':s'] = '%'.$filters['search'].'%'; }
        if (!empty($filters['customer_type'])) { $where[] = "customer_type = :ct"; $params[':ct'] = $filters['customer_type']; }
        $wc = $where ? 'WHERE ' . implode(' AND ', $where) : '';
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM customers {$wc}");
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    public function getRentalHistory(int $customerId): array
    {
        $stmt = $this->db->prepare("
            SELECT l.*, p.title AS property_title, p.property_code
            FROM leases l
            JOIN properties p ON l.property_id = p.id
            WHERE l.customer_id = :cid
            ORDER BY l.start_date DESC
        ");
        $stmt->execute([':cid' => $customerId]);
        return $stmt->fetchAll();
    }

    public function getPaymentHistory(int $customerId): array
    {
        $stmt = $this->db->prepare("
            SELECT py.*, p.title AS property_title
            FROM payments py
            LEFT JOIN properties p ON py.property_id = p.id
            WHERE py.customer_id = :cid
            ORDER BY py.created_at DESC
        ");
        $stmt->execute([':cid' => $customerId]);
        return $stmt->fetchAll();
    }
}
