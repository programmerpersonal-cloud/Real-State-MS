<?php
/**
 * Sale Model — property sales transactions.
 */
class Sale
{
    private PDO $db;

    public function __construct()
    {
        $this->db = getDBConnection();
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare("
            SELECT s.*, c.full_name AS customer_name, c.phone AS customer_phone,
                   p.title AS property_title, p.property_code, u.full_name AS agent_name
            FROM sales s
            JOIN customers c ON s.customer_id = c.id
            JOIN properties p ON s.property_id = p.id
            LEFT JOIN users u ON s.agent_id = u.id
            WHERE s.id = :id
        ");
        $stmt->execute([':id' => $id]);
        return $stmt->fetch() ?: null;
    }

    public function create(array $d): int|false
    {
        try {
            $this->db->beginTransaction();
            $stmt = $this->db->prepare("
                INSERT INTO sales (sale_code, property_id, customer_id, sale_amount, tax_amount,
                    tax_rate, commission_amount, payment_type, status, sale_date, agent_id, notes)
                VALUES (:code, :pid, :cid, :amt, :tax, :trate, :comm, :pt, :st, :sd, :aid, :notes)
            ");
            $stmt->execute([
                ':code' => $d['sale_code'] ?? generateCode('SAL'),
                ':pid'  => $d['property_id'],
                ':cid'  => $d['customer_id'],
                ':amt'  => $d['sale_amount'],
                // Rate is stored alongside the amount so a receipt reprinted after
                // the rate changes still shows the tax that was actually charged.
                ':tax'  => $d['tax_amount'] ?? 0,
                ':trate'=> $d['tax_rate'] ?? taxRate(),
                ':comm' => $d['commission_amount'] ?? 0,
                ':pt'   => $d['payment_type'] ?? 'full',
                ':st'   => $d['status'] ?? 'pending',
                ':sd'   => $d['sale_date'] ?? date('Y-m-d'),
                ':aid'  => $d['agent_id'] ?? null,
                ':notes'=> $d['notes'] ?? '',
            ]);
            $saleId = (int) $this->db->lastInsertId();

            // Update property status
            $this->db->prepare("UPDATE properties SET status = ? WHERE id = ?")
                     ->execute([$d['status'] === 'completed' ? 'sold' : 'reserved', $d['property_id']]);

            // Commission record
            if (!empty($d['agent_id']) && !empty($d['commission_amount'])) {
                $this->db->prepare("
                    INSERT INTO commissions (agent_id, reference_type, reference_id, amount, percentage, status)
                    VALUES (?, 'sale', ?, ?, ?, 'pending')
                ")->execute([$d['agent_id'], $saleId, $d['commission_amount'],
                            (float)$d['commission_amount'] / max(1,(float)$d['sale_amount']) * 100]);
            }

            $this->db->commit();
            return $saleId;
        } catch (PDOException $e) {
            $this->db->rollBack();
            error_log('Sale create error: ' . $e->getMessage());
            return false;
        }
    }

    public function update(int $id, array $d): bool
    {
        $allowed = ['sale_amount','commission_amount','payment_type','status','sale_date','agent_id','notes'];
        $fields = []; $params = [':id' => $id];
        foreach ($allowed as $f) {
            if (array_key_exists($f, $d)) { $fields[] = "$f = :$f"; $params[":$f"] = $d[$f]; }
        }
        if (!$fields) return false;
        return $this->db->prepare("UPDATE sales SET " . implode(', ', $fields) . " WHERE id = :id")->execute($params);
    }

    public function getAll(array $filters = [], int $limit = ITEMS_PER_PAGE, int $offset = 0): array
    {
        $where = []; $params = [];
        if (!empty($filters['status'])) { $where[] = "s.status = :st"; $params[':st'] = $filters['status']; }
        if (!empty($filters['search'])) {
            $where[] = "(s.sale_code LIKE :s OR c.full_name LIKE :s OR p.title LIKE :s)";
            $params[':s'] = '%' . $filters['search'] . '%';
        }
        $wc = $where ? 'WHERE ' . implode(' AND ', $where) : '';
        $stmt = $this->db->prepare("
            SELECT s.*, c.full_name AS customer_name, p.title AS property_title, p.property_code,
                   u.full_name AS agent_name
            FROM sales s
            JOIN customers c ON s.customer_id = c.id
            JOIN properties p ON s.property_id = p.id
            LEFT JOIN users u ON s.agent_id = u.id
            {$wc}
            ORDER BY s.created_at DESC
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
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM sales {$wc}");
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }
}
