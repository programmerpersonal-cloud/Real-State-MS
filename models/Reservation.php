<?php
/**
 * Reservation Model — property bookings with expiry.
 */
class Reservation
{
    /**
     * Sortable columns, keyed by the token a request may ask for.
     *
     * The request never supplies a column name — it supplies one of these
     * keys, and anything unrecognised falls back to 'newest'. That is what
     * keeps `?sort=` out of the SQL string it is concatenated into.
     */
    public const SORTS = [
        'newest'       => 'r.created_at DESC',
        'oldest'       => 'r.created_at ASC',
        'expiry_asc'   => 'r.expiry_date ASC',
        'expiry_desc'  => 'r.expiry_date DESC',
        'code_asc'     => 'r.reservation_code ASC',
        'code_desc'    => 'r.reservation_code DESC',
        'deposit_desc' => 'r.deposit_amount DESC',
        'deposit_asc'  => 'r.deposit_amount ASC',
        'status_asc'   => 'r.status ASC, r.created_at DESC',
        'status_desc'  => 'r.status DESC, r.created_at DESC',
    ];

    private PDO $db;

    public function __construct()
    {
        $this->db = getDBConnection();
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare("
            SELECT r.*, c.full_name AS customer_name, p.title AS property_title, p.property_code,
                   p.owner_id AS property_owner_id, p.agent_id AS property_agent_id
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

    /**
     * The WHERE clause and its bound parameters for one filter set.
     *
     * Shared by getAll() and count() because they were drifting: count()
     * only knew about `status`, so a search returned two rows under a header
     * reading "12 reservations" and paginated as though the other ten were
     * still there, on pages that rendered empty.
     *
     * Every value is bound. The only text placed into the SQL is the clause
     * this method builds from its own fixed strings.
     *
     * @return array{0:string, 1:array<string, mixed>}
     */
    private function buildWhere(array $filters): array
    {
        // The access scope leads and is never optional, so the list, its
        // heading count and its status pills all describe the same holds.
        [$scope, $params] = reservationViewScope('r', 'p');
        $where = ['(' . $scope . ')'];

        if (!empty($filters['status'])) { $where[] = "r.status = :st"; $params[':st'] = $filters['status']; }
        // Bound and cast, for the property detail page's Reservations tab.
        if (!empty($filters['property_id'])) { $where[] = "r.property_id = :pid"; $params[':pid'] = (int) $filters['property_id']; }
        if (!empty($filters['customer_id'])) { $where[] = "r.customer_id = :cid"; $params[':cid'] = (int) $filters['customer_id']; }
        if (!empty($filters['search'])) {
            $where[] = "(r.reservation_code LIKE :s OR c.full_name LIKE :s OR p.title LIKE :s OR p.property_code LIKE :s)";
            $params[':s'] = '%' . $filters['search'] . '%';
        }

        return ['WHERE ' . implode(' AND ', $where), $params];
    }

    public function getAll(array $filters = [], int $limit = ITEMS_PER_PAGE, int $offset = 0): array
    {
        [$wc, $params] = $this->buildWhere($filters);
        $orderBy = self::SORTS[$filters['sort'] ?? ''] ?? self::SORTS['newest'];

        $stmt = $this->db->prepare("
            SELECT r.*, c.full_name AS customer_name, p.title AS property_title, p.property_code
            FROM reservations r
            JOIN customers c ON r.customer_id = c.id
            JOIN properties p ON r.property_id = p.id
            {$wc}
            ORDER BY {$orderBy}
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
        // The same joins as getAll(), because the search reaches across them.
        [$wc, $params] = $this->buildWhere($filters);
        $stmt = $this->db->prepare("
            SELECT COUNT(*)
            FROM reservations r
            JOIN customers c ON r.customer_id = c.id
            JOIN properties p ON r.property_id = p.id
            {$wc}
        ");
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    /**
     * How many reservations sit in each status, for the filter counts.
     *
     * One grouped query rather than one count per status — it answers the
     * whole row of tabs in a single round trip, and its cost does not change
     * with the number of statuses the enum grows to.
     *
     * Carries the same access scope as the list, so the pills count the holds
     * the reader can actually open.
     *
     * @return array<string, int>
     */
    public function countsByStatus(array $filters = []): array
    {
        unset($filters['status']);
        [$wc, $params] = $this->buildWhere($filters);

        $stmt = $this->db->prepare("
            SELECT r.status, COUNT(*) AS n
            FROM reservations r
            JOIN customers c ON r.customer_id = c.id
            JOIN properties p ON r.property_id = p.id
            {$wc}
            GROUP BY r.status
        ");
        $stmt->execute($params);

        return array_map('intval', array_column($stmt->fetchAll(), 'n', 'status'));
    }
}
