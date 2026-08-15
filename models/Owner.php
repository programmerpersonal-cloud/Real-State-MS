<?php
/**
 * Saxane Real Estate Management System
 * Owner Model
 */

class Owner
{
    private PDO $db;

    public function __construct()
    {
        $this->db = getDBConnection();
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM owners WHERE id = :id");
        $stmt->execute([':id' => $id]);
        return $stmt->fetch() ?: null;
    }

    public function create(array $d): int|false
    {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO owners (user_id, full_name, phone, email, address, national_id, bank_name, bank_account, commission_rate, revenue_share, notes)
                VALUES (:uid,:name,:phone,:email,:addr,:nid,:bank,:acct,:comm,:rev,:notes)
            ");
            $stmt->execute([
                ':uid' => $d['user_id'] ?? null, ':name' => $d['full_name'], ':phone' => $d['phone'],
                ':email' => $d['email'] ?? '', ':addr' => $d['address'] ?? '', ':nid' => $d['national_id'] ?? '',
                ':bank' => $d['bank_name'] ?? '', ':acct' => $d['bank_account'] ?? '',
                ':comm' => $d['commission_rate'] ?? commissionRate(), ':rev' => $d['revenue_share'] ?? null,
                ':notes' => $d['notes'] ?? '',
            ]);
            return (int) $this->db->lastInsertId();
        } catch (PDOException $e) {
            error_log('Owner create error: ' . $e->getMessage());
            return false;
        }
    }

    public function update(int $id, array $data): bool
    {
        $fields = []; $params = [':id' => $id];
        $allowed = ['full_name','phone','email','address','national_id','bank_name','bank_account','commission_rate','revenue_share','notes'];
        foreach ($allowed as $f) {
            if (array_key_exists($f, $data)) { $fields[] = "$f = :$f"; $params[":$f"] = $data[$f]; }
        }
        if (empty($fields)) return false;
        return $this->db->prepare("UPDATE owners SET " . implode(', ', $fields) . " WHERE id = :id")->execute($params);
    }

    public function getAll(array $filters = [], int $limit = ITEMS_PER_PAGE, int $offset = 0): array
    {
        $where = []; $params = [];
        if (!empty($filters['search'])) {
            $where[] = "(full_name LIKE :s OR phone LIKE :s OR email LIKE :s)";
            $params[':s'] = '%'.$filters['search'].'%';
        }
        $wc = $where ? 'WHERE ' . implode(' AND ', $where) : '';
        $stmt = $this->db->prepare("SELECT * FROM owners {$wc} ORDER BY created_at DESC LIMIT :l OFFSET :o");
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
        $wc = $where ? 'WHERE ' . implode(' AND ', $where) : '';
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM owners {$wc}");
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    public function getProperties(int $ownerId): array
    {
        $stmt = $this->db->prepare("SELECT * FROM properties WHERE owner_id = :oid AND is_archived = 0 ORDER BY created_at DESC");
        $stmt->execute([':oid' => $ownerId]);
        return $stmt->fetchAll();
    }

    public function getAllSimple(): array
    {
        return $this->db->query("SELECT id, full_name FROM owners ORDER BY full_name")->fetchAll();
    }
}
