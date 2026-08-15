<?php
/**
 * Payment Model — rent, sale, deposit, refund, late_fee transactions.
 */
class Payment
{
    private PDO $db;

    public function __construct()
    {
        $this->db = getDBConnection();
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare("
            SELECT py.*, c.full_name AS customer_name, c.phone AS customer_phone,
                   p.title AS property_title, p.property_code,
                   u.full_name AS received_by_name
            FROM payments py
            JOIN customers c ON py.customer_id = c.id
            LEFT JOIN properties p ON py.property_id = p.id
            LEFT JOIN users u ON py.received_by = u.id
            WHERE py.id = :id
        ");
        $stmt->execute([':id' => $id]);
        return $stmt->fetch() ?: null;
    }

    public function create(array $d): int|false
    {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO payments (payment_code, payment_type, reference_type, reference_id,
                    customer_id, property_id, amount, due_date, payment_date, payment_method,
                    received_by, receipt_number, balance_remaining, penalty_amount, status, notes)
                VALUES (:code, :ptype, :rtype, :rid, :cid, :pid, :amt, :dd, :pd, :pm, :rb,
                    :rn, :br, :pen, :st, :notes)
            ");
            $stmt->execute([
                ':code'  => $d['payment_code'] ?? generateCode('PAY'),
                ':ptype' => $d['payment_type'],
                ':rtype' => $d['reference_type'],
                ':rid'   => $d['reference_id'],
                ':cid'   => $d['customer_id'],
                ':pid'   => $d['property_id'] ?? null,
                ':amt'   => $d['amount'],
                ':dd'    => $d['due_date'] ?? null,
                ':pd'    => $d['payment_date'] ?? date('Y-m-d'),
                ':pm'    => $d['payment_method'] ?? 'cash',
                ':rb'    => $_SESSION['user_id'] ?? null,
                ':rn'    => $d['receipt_number'] ?? generateCode('RCP'),
                ':br'    => $d['balance_remaining'] ?? 0,
                ':pen'   => $d['penalty_amount'] ?? 0,
                ':st'    => $d['status'] ?? 'paid',
                ':notes' => $d['notes'] ?? '',
            ]);
            $paymentId = (int) $this->db->lastInsertId();

            // If this satisfies a schedule, mark it paid
            if (!empty($d['schedule_id'])) {
                $this->db->prepare("UPDATE payment_schedules SET status='paid', paid_date=?, payment_id=? WHERE id=?")
                         ->execute([$d['payment_date'] ?? date('Y-m-d'), $paymentId, $d['schedule_id']]);
            }

            return $paymentId;
        } catch (PDOException $e) {
            error_log('Payment create error: ' . $e->getMessage());
            return false;
        }
    }

    public function getAll(array $filters = [], int $limit = ITEMS_PER_PAGE, int $offset = 0): array
    {
        $where = []; $params = [];
        if (!empty($filters['status'])) { $where[] = "py.status = :st"; $params[':st'] = $filters['status']; }
        if (!empty($filters['payment_type'])) { $where[] = "py.payment_type = :pt"; $params[':pt'] = $filters['payment_type']; }
        if (!empty($filters['customer_id'])) { $where[] = "py.customer_id = :cid"; $params[':cid'] = $filters['customer_id']; }
        if (!empty($filters['search'])) {
            $where[] = "(py.payment_code LIKE :s OR c.full_name LIKE :s)";
            $params[':s'] = '%' . $filters['search'] . '%';
        }
        $wc = $where ? 'WHERE ' . implode(' AND ', $where) : '';
        $stmt = $this->db->prepare("
            SELECT py.*, c.full_name AS customer_name, p.title AS property_title, p.property_code
            FROM payments py
            JOIN customers c ON py.customer_id = c.id
            LEFT JOIN properties p ON py.property_id = p.id
            {$wc}
            ORDER BY py.created_at DESC
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
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM payments {$wc}");
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    public function totalsByStatus(): array
    {
        $stmt = $this->db->query("SELECT status, COUNT(*) AS cnt, COALESCE(SUM(amount),0) AS total FROM payments GROUP BY status");
        $out = [];
        foreach ($stmt->fetchAll() as $r) $out[$r['status']] = $r;
        return $out;
    }
}
