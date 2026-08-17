<?php
/**
 * Sale Model — property sales transactions.
 */
class Sale
{
    /**
     * Sortable columns, keyed by the token a request may ask for. The request
     * supplies a key, never a column; anything unrecognised resolves to
     * 'newest' rather than reaching the ORDER BY as text.
     */
    public const SORTS = [
        'newest'      => 's.created_at DESC',
        'oldest'      => 's.created_at ASC',
        'date_desc'   => 's.sale_date DESC, s.id DESC',
        'date_asc'    => 's.sale_date ASC, s.id ASC',
        'amount_desc' => 's.sale_amount DESC',
        'amount_asc'  => 's.sale_amount ASC',
        'comm_desc'   => 's.commission_amount DESC',
        'comm_asc'    => 's.commission_amount ASC',
        'code_asc'    => 's.sale_code ASC',
        'code_desc'   => 's.sale_code DESC',
        'status_asc'  => 's.status ASC, s.sale_date DESC',
        'status_desc' => 's.status DESC, s.sale_date DESC',
    ];

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

    /**
     * The WHERE clause and its bound parameters for one filter set.
     *
     * Shared by getAll() and count(), which had drifted: count() knew only
     * about `status`, so a search reported the unfiltered total and offered
     * pages that then rendered empty.
     *
     * @return array{0:string, 1:array<string, mixed>}
     */
    private function buildWhere(array $filters): array
    {
        $where = []; $params = [];
        if (!empty($filters['status'])) { $where[] = "s.status = :st"; $params[':st'] = $filters['status']; }
        if (!empty($filters['payment_type'])) { $where[] = "s.payment_type = :pt"; $params[':pt'] = $filters['payment_type']; }
        if (!empty($filters['agent_id'])) { $where[] = "s.agent_id = :aid"; $params[':aid'] = (int) $filters['agent_id']; }
        if (!empty($filters['property_id'])) { $where[] = "s.property_id = :pid"; $params[':pid'] = (int) $filters['property_id']; }
        if (!empty($filters['search'])) {
            $where[] = "(s.sale_code LIKE :s OR c.full_name LIKE :s OR p.title LIKE :s OR p.property_code LIKE :s)";
            $params[':s'] = '%' . $filters['search'] . '%';
        }

        return [$where ? 'WHERE ' . implode(' AND ', $where) : '', $params];
    }

    private const JOINS = "
        FROM sales s
        JOIN customers c ON s.customer_id = c.id
        JOIN properties p ON s.property_id = p.id
        LEFT JOIN users u ON s.agent_id = u.id
    ";

    public function getAll(array $filters = [], int $limit = ITEMS_PER_PAGE, int $offset = 0): array
    {
        [$wc, $params] = $this->buildWhere($filters);
        $orderBy = self::SORTS[$filters['sort'] ?? ''] ?? self::SORTS['newest'];

        $stmt = $this->db->prepare("
            SELECT s.*, c.full_name AS customer_name, c.profile_photo AS customer_photo,
                   p.title AS property_title, p.property_code,
                   u.full_name AS agent_name, u.avatar AS agent_avatar
            " . self::JOINS . "
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
        $stmt = $this->db->prepare("SELECT COUNT(*) " . self::JOINS . " {$wc}");
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Deal count and value in each status, for the ledger cards.
     *
     * One grouped query; its cost does not change with the number of sales.
     *
     * @return array<string, array{cnt:int, total:float}>
     */
    public function totalsByStatus(): array
    {
        $rows = $this->db->query("
            SELECT status, COUNT(*) AS cnt, COALESCE(SUM(sale_amount),0) AS total
            FROM sales GROUP BY status
        ")->fetchAll();

        $out = [];
        foreach ($rows as $r) {
            $out[$r['status']] = ['cnt' => (int) $r['cnt'], 'total' => (float) $r['total']];
        }
        return $out;
    }
}
