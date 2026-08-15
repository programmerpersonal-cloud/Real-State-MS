<?php
/**
 * Reservation Model — property bookings with expiry.
 */
class Reservation
{
    private PDO $db;

    public function __construct()
    {
        $this->db = getDBConnection();
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare("
            SELECT r.*, c.full_name AS customer_name, p.title AS property_title, p.property_code
            FROM reservations r
            JOIN customers c ON r.customer_id = c.id
            JOIN properties p ON r.property_id = p.id
            WHERE r.id = :id
        ");
        $stmt->execute([':id' => $id]);
        return $stmt->fetch() ?: null;
    }

    public function create(array $d): int|false
    {
        try {
            $this->db->beginTransaction();
            $stmt = $this->db->prepare("
                INSERT INTO reservations (reservation_code, property_id, customer_id, reservation_date,
                    expiry_date, deposit_amount, status, notes, created_by)
                VALUES (:code, :pid, :cid, :rd, :ed, :dep, 'active', :notes, :cb)
            ");
            $stmt->execute([
                ':code' => $d['reservation_code'] ?? generateCode('RSV'),
                ':pid'  => $d['property_id'],
                ':cid'  => $d['customer_id'],
                ':rd'   => $d['reservation_date'] ?? date('Y-m-d'),
                ':ed'   => $d['expiry_date'] ?? reservationExpiryDate(),
                ':dep'  => $d['deposit_amount'] ?? 0,
                ':notes'=> $d['notes'] ?? '',
                ':cb'   => $_SESSION['user_id'] ?? null,
            ]);
            $id = (int) $this->db->lastInsertId();
            // Mark property reserved
            $this->db->prepare("UPDATE properties SET status='reserved' WHERE id = ? AND status='available'")
                     ->execute([$d['property_id']]);
            $this->db->commit();
            return $id;
        } catch (PDOException $e) {
            $this->db->rollBack();
            error_log('Reservation create error: ' . $e->getMessage());
            return false;
        }
    }

    public function update(int $id, array $d): bool
    {
        $allowed = ['expiry_date','deposit_amount','status','notes'];
        $fields = []; $params = [':id' => $id];
        foreach ($allowed as $f) {
            if (array_key_exists($f, $d)) { $fields[] = "$f = :$f"; $params[":$f"] = $d[$f]; }
        }
        if (!$fields) return false;
        return $this->db->prepare("UPDATE reservations SET " . implode(', ', $fields) . " WHERE id = :id")->execute($params);
    }

    public function cancel(int $id): bool
    {
        $r = $this->findById($id);
        if (!$r) return false;
        $this->update($id, ['status' => 'cancelled']);
        // Free the property if no other active reservation
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM reservations WHERE property_id=? AND status='active' AND id != ?");
        $stmt->execute([$r['property_id'], $id]);
        if ((int)$stmt->fetchColumn() === 0) {
            $this->db->prepare("UPDATE properties SET status='available' WHERE id = ? AND status='reserved'")
                     ->execute([$r['property_id']]);
        }
        return true;
    }

    public function confirm(int $id): bool
    {
        return $this->update($id, ['status' => 'confirmed']);
    }

    public function expireOld(): int
    {
        $stmt = $this->db->prepare("UPDATE reservations SET status='expired' WHERE status='active' AND expiry_date < CURDATE()");
        $stmt->execute();
        $affected = $stmt->rowCount();
        // Free properties
        if ($affected > 0) {
            $this->db->exec("
                UPDATE properties SET status='available' WHERE id IN (
                    SELECT property_id FROM reservations WHERE status='expired'
                    AND property_id NOT IN (SELECT property_id FROM reservations WHERE status='active')
                ) AND status='reserved'
            ");
        }
        return $affected;
    }

    public function getAll(array $filters = [], int $limit = ITEMS_PER_PAGE, int $offset = 0): array
    {
        $where = []; $params = [];
        if (!empty($filters['status'])) { $where[] = "r.status = :st"; $params[':st'] = $filters['status']; }
        if (!empty($filters['search'])) {
            $where[] = "(r.reservation_code LIKE :s OR c.full_name LIKE :s OR p.title LIKE :s)";
            $params[':s'] = '%' . $filters['search'] . '%';
        }
        $wc = $where ? 'WHERE ' . implode(' AND ', $where) : '';
        $stmt = $this->db->prepare("
            SELECT r.*, c.full_name AS customer_name, p.title AS property_title, p.property_code
            FROM reservations r
            JOIN customers c ON r.customer_id = c.id
            JOIN properties p ON r.property_id = p.id
            {$wc}
            ORDER BY r.created_at DESC
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
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM reservations {$wc}");
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }
}
