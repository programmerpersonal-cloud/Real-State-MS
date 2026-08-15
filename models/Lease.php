<?php
/**
 * Lease Model — handles rental contracts, renewals, terminations, schedule generation.
 */
class Lease
{
    private PDO $db;

    public function __construct()
    {
        $this->db = getDBConnection();
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare("
            SELECT l.*, c.full_name AS customer_name, c.phone AS customer_phone,
                   p.title AS property_title, p.property_code, p.address AS property_address,
                   o.full_name AS owner_name, u.full_name AS created_by_name
            FROM leases l
            JOIN customers c ON l.customer_id = c.id
            JOIN properties p ON l.property_id = p.id
            LEFT JOIN owners o ON l.owner_id = o.id
            LEFT JOIN users u ON l.created_by = u.id
            WHERE l.id = :id
        ");
        $stmt->execute([':id' => $id]);
        return $stmt->fetch() ?: null;
    }

    public function create(array $d): int|false
    {
        try {
            $this->db->beginTransaction();
            $stmt = $this->db->prepare("
                INSERT INTO leases (lease_code, customer_id, property_id, owner_id, start_date, end_date,
                    rent_amount, deposit_amount, payment_schedule, late_fee_rate, terms, contract_file,
                    signature_status, status, move_in_date, created_by)
                VALUES (:code, :cid, :pid, :oid, :sd, :ed, :rent, :dep, :sched, :lfr, :terms, :cf,
                    :sig, 'active', :move_in, :cb)
            ");
            $stmt->execute([
                ':code' => $d['lease_code'] ?? generateCode('LSE'),
                ':cid'  => $d['customer_id'],
                ':pid'  => $d['property_id'],
                ':oid'  => $d['owner_id'] ?: null,
                ':sd'   => $d['start_date'],
                ':ed'   => $d['end_date'],
                ':rent' => $d['rent_amount'],
                ':dep'  => $d['deposit_amount'] ?? 0,
                ':sched'=> $d['payment_schedule'] ?? 'monthly',
                ':lfr'  => $d['late_fee_rate'] ?? lateFeeRate(),
                ':terms'=> $d['terms'] ?? '',
                ':cf'   => $d['contract_file'] ?? null,
                ':sig'  => $d['signature_status'] ?? 'pending',
                ':move_in' => $d['move_in_date'] ?? $d['start_date'],
                ':cb'   => $_SESSION['user_id'] ?? null,
            ]);
            $leaseId = (int) $this->db->lastInsertId();

            // Create rental record
            $this->db->prepare("
                INSERT INTO rentals (lease_id, property_id, customer_id, move_in_date, monthly_rent, deposit_paid, status)
                VALUES (?,?,?,?,?,?, 'active')
            ")->execute([
                $leaseId, $d['property_id'], $d['customer_id'],
                $d['move_in_date'] ?? $d['start_date'],
                $d['rent_amount'], $d['deposit_amount'] ?? 0,
            ]);

            // Generate payment schedule
            $this->generateSchedule($leaseId, $d['start_date'], $d['end_date'], (float)$d['rent_amount'], $d['payment_schedule'] ?? 'monthly');

            // Record deposit
            if (!empty($d['deposit_amount']) && (float)$d['deposit_amount'] > 0) {
                $this->db->prepare("INSERT INTO deposits (lease_id, customer_id, amount, received_date) VALUES (?,?,?,?)")
                         ->execute([$leaseId, $d['customer_id'], $d['deposit_amount'], $d['move_in_date'] ?? $d['start_date']]);
            }

            // Update property status to rented
            $this->db->prepare("UPDATE properties SET status='rented' WHERE id = ?")->execute([$d['property_id']]);

            $this->db->commit();
            return $leaseId;
        } catch (PDOException $e) {
            $this->db->rollBack();
            error_log('Lease create error: ' . $e->getMessage());
            return false;
        }
    }

    /** Generate payment_schedules rows between two dates. */
    public function generateSchedule(int $leaseId, string $start, string $end, float $amount, string $cadence = 'monthly'): void
    {
        $interval = match ($cadence) {
            'quarterly' => '+3 months',
            'yearly'    => '+1 year',
            default     => '+1 month',
        };
        $current = strtotime($start);
        $endTs = strtotime($end);
        $stmt = $this->db->prepare("INSERT INTO payment_schedules (lease_id, due_date, amount, status) VALUES (?,?,?, 'pending')");
        while ($current <= $endTs) {
            $stmt->execute([$leaseId, date('Y-m-d', $current), $amount]);
            $current = strtotime($interval, $current);
        }
    }

    public function update(int $id, array $d): bool
    {
        $allowed = ['start_date','end_date','rent_amount','deposit_amount','payment_schedule','late_fee_rate',
            'terms','contract_file','signature_status','status','termination_reason','move_in_date','move_out_date'];
        $fields = []; $params = [':id' => $id];
        foreach ($allowed as $f) {
            if (array_key_exists($f, $d)) { $fields[] = "$f = :$f"; $params[":$f"] = $d[$f]; }
        }
        if (!$fields) return false;
        return $this->db->prepare("UPDATE leases SET " . implode(', ', $fields) . " WHERE id = :id")->execute($params);
    }

    public function getAll(array $filters = [], int $limit = ITEMS_PER_PAGE, int $offset = 0): array
    {
        $where = []; $params = [];
        if (!empty($filters['status'])) { $where[] = "l.status = :st"; $params[':st'] = $filters['status']; }
        if (!empty($filters['search'])) {
            $where[] = "(l.lease_code LIKE :s OR c.full_name LIKE :s OR p.title LIKE :s OR p.property_code LIKE :s)";
            $params[':s'] = '%' . $filters['search'] . '%';
        }
        if (!empty($filters['customer_id'])) { $where[] = "l.customer_id = :cid"; $params[':cid'] = $filters['customer_id']; }
        if (!empty($filters['property_id'])) { $where[] = "l.property_id = :pid"; $params[':pid'] = $filters['property_id']; }
        $wc = $where ? 'WHERE ' . implode(' AND ', $where) : '';
        $stmt = $this->db->prepare("
            SELECT l.*, c.full_name AS customer_name, p.title AS property_title, p.property_code
            FROM leases l
            JOIN customers c ON l.customer_id = c.id
            JOIN properties p ON l.property_id = p.id
            {$wc}
            ORDER BY l.created_at DESC
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
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM leases {$wc}");
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    /** Renew an existing lease — extend end_date, optionally update rent, create new contract record. */
    public function renew(int $id, string $newEnd, ?float $newRent = null): bool
    {
        $current = $this->findById($id);
        if (!$current) return false;
        $oldEnd = $current['end_date'];
        $update = ['end_date' => $newEnd, 'status' => 'renewed'];
        if ($newRent !== null) $update['rent_amount'] = $newRent;
        $this->update($id, $update);
        $this->update($id, ['status' => 'active']); // re-activate after renewal logging
        $this->generateSchedule($id, $oldEnd, $newEnd, $newRent ?? (float)$current['rent_amount'], $current['payment_schedule']);
        return true;
    }

    public function terminate(int $id, string $reason, ?string $moveOut = null): bool
    {
        $this->update($id, [
            'status' => 'terminated',
            'termination_reason' => $reason,
            'move_out_date' => $moveOut ?? date('Y-m-d'),
        ]);
        // Free up property
        $lease = $this->findById($id);
        if ($lease) {
            $this->db->prepare("UPDATE properties SET status = 'available' WHERE id = ?")->execute([$lease['property_id']]);
            $this->db->prepare("UPDATE rentals SET status='terminated', move_out_date = ? WHERE lease_id = ?")
                     ->execute([$moveOut ?? date('Y-m-d'), $id]);
        }
        return true;
    }

    public function getPaymentSchedule(int $leaseId): array
    {
        $stmt = $this->db->prepare("SELECT * FROM payment_schedules WHERE lease_id = ? ORDER BY due_date");
        $stmt->execute([$leaseId]);
        return $stmt->fetchAll();
    }

    /** Sum unpaid amounts on a lease. */
    public function getArrears(int $leaseId): float
    {
        $stmt = $this->db->prepare("
            SELECT COALESCE(SUM(amount + penalty),0)
            FROM payment_schedules
            WHERE lease_id = ? AND status IN ('pending','overdue','partial')
              AND due_date < CURDATE()
        ");
        $stmt->execute([$leaseId]);
        return (float) $stmt->fetchColumn();
    }

    /** Check whether a property already has an active lease overlapping the given range. */
    public function hasOverlap(int $propertyId, string $start, string $end, ?int $excludeLease = null): bool
    {
        $sql = "SELECT COUNT(*) FROM leases WHERE property_id = :pid AND status='active'
                AND NOT (end_date < :start OR start_date > :end)";
        $params = [':pid' => $propertyId, ':start' => $start, ':end' => $end];
        if ($excludeLease) { $sql .= " AND id != :ex"; $params[':ex'] = $excludeLease; }
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return ((int)$stmt->fetchColumn()) > 0;
    }

    /** Mark overdue schedules. Run periodically (or on dashboard load). */
    public function markOverdue(): int
    {
        $stmt = $this->db->prepare("
            UPDATE payment_schedules
            SET status = 'overdue'
            WHERE status = 'pending' AND due_date < CURDATE()
        ");
        $stmt->execute();
        return $stmt->rowCount();
    }
}
