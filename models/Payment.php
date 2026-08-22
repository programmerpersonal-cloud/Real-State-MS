<?php
/**
 * Payment Model — rent, sale, deposit, refund, late_fee transactions.
 */
class Payment
{
    /**
     * Sortable columns, keyed by the token a request may ask for. A request
     * supplies a key, never a column; anything unrecognised resolves to
     * 'newest' rather than reaching the ORDER BY as text.
     *
     * Payment date leads the money columns because a ledger is read in date
     * order far more often than in amount order.
     */
    public const SORTS = [
        'newest'      => 'py.created_at DESC',
        'oldest'      => 'py.created_at ASC',
        'date_desc'   => 'py.payment_date DESC, py.id DESC',
        'date_asc'    => 'py.payment_date ASC, py.id ASC',
        'amount_desc' => 'py.amount DESC',
        'amount_asc'  => 'py.amount ASC',
        'code_asc'    => 'py.payment_code ASC',
        'code_desc'   => 'py.payment_code DESC',
        'status_asc'  => 'py.status ASC, py.payment_date DESC',
        'status_desc' => 'py.status DESC, py.payment_date DESC',
    ];

    private PDO $db;

    public function __construct()
    {
        $this->db = getDBConnection();
    }

    /**
     * One payment, with the property's owner_id and agent_id attached so
     * canViewPayment() can decide a receipt without a second query.
     */
    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare("
            SELECT py.*, c.full_name AS customer_name, c.phone AS customer_phone,
                   p.title AS property_title, p.property_code,
                   p.owner_id AS property_owner_id, p.agent_id AS property_agent_id,
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

    /**
     * The WHERE clause and its bound parameters for one filter set.
     *
     * Shared by getAll() and count(). They had drifted: count() knew only
     * about `status`, so filtering by type or searching gave a header and a
     * page count describing a different result set from the rows underneath.
     *
     * @return array{0:string, 1:array<string, mixed>}
     */
    private function buildWhere(array $filters): array
    {
        // The access scope leads and is never optional, so the ledger, its
        // heading count and its status cards all describe the same rows.
        [$scope, $params] = paymentViewScope('py', 'p');
        $where = ['(' . $scope . ')'];

        if (!empty($filters['status'])) { $where[] = "py.status = :st"; $params[':st'] = $filters['status']; }
        if (!empty($filters['payment_type'])) { $where[] = "py.payment_type = :pt"; $params[':pt'] = $filters['payment_type']; }
        if (!empty($filters['payment_method'])) { $where[] = "py.payment_method = :pm"; $params[':pm'] = $filters['payment_method']; }
        if (!empty($filters['customer_id'])) { $where[] = "py.customer_id = :cid"; $params[':cid'] = (int) $filters['customer_id']; }
        // Bound and cast, for the property detail page's Payments tab.
        if (!empty($filters['property_id'])) { $where[] = "py.property_id = :pid"; $params[':pid'] = (int) $filters['property_id']; }
        if (!empty($filters['search'])) {
            $where[] = "(py.payment_code LIKE :s OR py.receipt_number LIKE :s OR c.full_name LIKE :s OR p.title LIKE :s)";
            $params[':s'] = '%' . $filters['search'] . '%';
        }

        return ['WHERE ' . implode(' AND ', $where), $params];
    }

    public function getAll(array $filters = [], int $limit = ITEMS_PER_PAGE, int $offset = 0): array
    {
        [$wc, $params] = $this->buildWhere($filters);
        $orderBy = self::SORTS[$filters['sort'] ?? ''] ?? self::SORTS['newest'];

        $stmt = $this->db->prepare("
            SELECT py.*, c.full_name AS customer_name, c.profile_photo AS customer_photo,
                   p.title AS property_title, p.property_code
            FROM payments py
            JOIN customers c ON py.customer_id = c.id
            LEFT JOIN properties p ON py.property_id = p.id
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
            FROM payments py
            JOIN customers c ON py.customer_id = c.id
            LEFT JOIN properties p ON py.property_id = p.id
            {$wc}
        ");
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Transaction count and value in each status, for the ledger cards.
     *
     * Scoped like every other read here. Unscoped, these four figures were the
     * agency's entire turnover, printed above a list of three rows for the
     * tenant who could see three.
     *
     * @return array<string, array{status:string, cnt:int, total:float}>
     */
    public function totalsByStatus(array $filters = []): array
    {
        unset($filters['status']);
        [$wc, $params] = $this->buildWhere($filters);

        $stmt = $this->db->prepare("
            SELECT py.status, COUNT(*) AS cnt, COALESCE(SUM(py.amount),0) AS total
            FROM payments py
            JOIN customers c ON py.customer_id = c.id
            LEFT JOIN properties p ON py.property_id = p.id
            {$wc}
            GROUP BY py.status
        ");
        $stmt->execute($params);

        $out = [];
        foreach ($stmt->fetchAll() as $r) $out[$r['status']] = $r;
        return $out;
    }
}
