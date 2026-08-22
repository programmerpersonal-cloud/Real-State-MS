<?php
/**
 * Report Controller — analytics dashboards.
 *
 * This page previously issued seventeen queries, twelve of them single-value
 * COUNT/SUM scalars that walked the same table two, three and four times over.
 * They are now conditional aggregates: one pass over `properties` answers the
 * portfolio counts *and* the doughnut, one pass over `payments` answers both
 * revenue figures, and so on. Same numbers, same definitions — fewer trips.
 */
class ReportController
{
    /**
     * How far back the revenue chart reaches.
     *
     * Keyed by the token a request may ask for, valued by the number of months
     * — so the request never supplies the integer that reaches the query.
     */
    public const RANGES = [
        '6m'  => ['label' => 'Last 6 months',  'months' => 6],
        '12m' => ['label' => 'Last 12 months', 'months' => 12],
        '24m' => ['label' => 'Last 2 years',   'months' => 24],
    ];

    public function index(): void
    {
        authorize('reports.view');
        $db = getDBConnection();

        $range  = uiPick($_GET['range'] ?? '', array_keys(self::RANGES)) ?: '6m';
        $months = self::RANGES[$range]['months'];

        // Every figure below is cut by the reader's record scope. This page is
        // held by administrators and agents; without the scope an agent read
        // the whole company's turnover, arrears and client count off a page
        // that is supposed to describe their own book. The predicates are the
        // same ones the modules themselves use, so a total here and the list
        // it summarises can never disagree.
        //
        // Each scope is fetched once and reused, and every query below is the
        // same single pass it was before — the clause is added to the WHERE,
        // not to the number of round trips.
        [$propertyScope, $propertyParams]   = propertyRecordScope('p');
        [$leaseScope, $leaseParams]         = leaseViewScope('l', 'p');
        [$paymentScope, $paymentParams]     = paymentViewScope('py', 'p');
        [$customerScope, $customerParams]   = customerViewScope('c');

        // ── Portfolio ────────────────────────────────────────────────
        // One pass. The doughnut and the four headline counts were five
        // separate scans of the same rows for the same answer.
        $stmt = $db->prepare("
            SELECT p.status, COUNT(*) AS c
            FROM properties p
            WHERE p.is_archived = 0 AND ({$propertyScope})
            GROUP BY p.status
        ");
        $stmt->execute($propertyParams);
        $byStatus = $stmt->fetchAll();
        $statusCounts = array_map('intval', array_column($byStatus, 'c', 'status'));

        // ── People, arrears, revenue ─────────────────────────────────
        $stmt = $db->prepare("
            SELECT COUNT(*) AS total, COALESCE(SUM(c.is_blacklisted = 1), 0) AS blacklisted
            FROM customers c
            WHERE ({$customerScope})
        ");
        $stmt->execute($customerParams);
        $customers = $stmt->fetch() ?: [];

        // Arrears reach payment_schedules through the lease they belong to,
        // which is what carries the access scope.
        $stmt = $db->prepare("
            SELECT COALESCE(SUM(ps.status = 'overdue'), 0) AS overdue_count,
                   COALESCE(SUM(CASE WHEN ps.status IN ('overdue','partial') THEN ps.amount + ps.penalty END), 0) AS arrears_total
            FROM payment_schedules ps
            JOIN leases l ON ps.lease_id = l.id
            JOIN properties p ON l.property_id = p.id
            WHERE ({$leaseScope})
        ");
        $stmt->execute($leaseParams);
        $arrears = $stmt->fetch() ?: [];

        $stmt = $db->prepare("
            SELECT COALESCE(SUM(CASE WHEN YEAR(py.payment_date) = YEAR(CURDATE())
                                     THEN py.amount END), 0) AS ytd,
                   COALESCE(SUM(CASE WHEN YEAR(py.payment_date) = YEAR(CURDATE())
                                      AND MONTH(py.payment_date) = MONTH(CURDATE())
                                     THEN py.amount END), 0) AS mtd
            FROM payments py
            LEFT JOIN properties p ON py.property_id = p.id
            WHERE py.status = 'paid' AND ({$paymentScope})
        ");
        $stmt->execute($paymentParams);
        $revenue = $stmt->fetch() ?: [];

        $stmt = $db->prepare("
            SELECT COUNT(*)
            FROM leases l
            JOIN properties p ON l.property_id = p.id
            WHERE l.status = 'active' AND ({$leaseScope})
        ");
        $stmt->execute($leaseParams);
        $activeLeases = (int) $stmt->fetchColumn();

        // Commission is already a per-agent ledger, so an agent's figure is
        // the one written against their own name.
        $commissionSql = "SELECT COALESCE(SUM(amount),0) FROM commissions WHERE status = 'pending'";
        $commissionParams = [];
        if (getUserRole() === ROLE_AGENT) {
            $commissionSql .= " AND agent_id = :uid";
            $commissionParams[':uid'] = (int) $_SESSION['user_id'];
        }
        $stmt = $db->prepare($commissionSql);
        $stmt->execute($commissionParams);
        $commissionPending = (float) $stmt->fetchColumn();

        $totalProperties = array_sum($statusCounts);

        $stats = [
            'total_properties'   => $totalProperties,
            'available'          => $statusCounts['available'] ?? 0,
            'rented'             => $statusCounts['rented'] ?? 0,
            'sold'               => $statusCounts['sold'] ?? 0,
            'active_leases'      => $activeLeases,
            'total_customers'    => (int) ($customers['total'] ?? 0),
            'blacklisted'        => (int) ($customers['blacklisted'] ?? 0),
            'overdue_count'      => (int) ($arrears['overdue_count'] ?? 0),
            'arrears_total'      => (float) ($arrears['arrears_total'] ?? 0),
            'revenue_ytd'        => (float) ($revenue['ytd'] ?? 0),
            'revenue_mtd'        => (float) ($revenue['mtd'] ?? 0),
            'commission_pending' => $commissionPending,
        ];

        // Revenue by month. The window is a bound integer resolved from
        // self::RANGES, never a value carried in from the request.
        $stmt = $db->prepare("
            SELECT DATE_FORMAT(py.payment_date, '%Y-%m') AS month, SUM(py.amount) AS total
            FROM payments py
            LEFT JOIN properties p ON py.property_id = p.id
            WHERE py.status = 'paid'
              AND py.payment_date >= DATE_SUB(CURDATE(), INTERVAL :months MONTH)
              AND ({$paymentScope})
            GROUP BY month ORDER BY month
        ");
        foreach ($paymentParams as $k => $v) $stmt->bindValue($k, $v);
        $stmt->bindValue(':months', $months, PDO::PARAM_INT);
        $stmt->execute();
        $monthlyRevenue = $stmt->fetchAll();

        // Agent performance (leases created in the last 30 days). The
        // leaderboard is an administrator's view of the desk; an agent sees
        // their own line on it and not their colleagues' numbers.
        $perfSql = "
            SELECT u.full_name, u.avatar, COUNT(l.id) AS leases_created,
                   COALESCE(SUM(l.rent_amount),0) AS rent_total
            FROM users u
            LEFT JOIN leases l ON l.created_by = u.id AND l.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
            WHERE u.role_id = 2 AND u.is_active = 1
        ";
        $perfParams = [];
        if (getUserRole() === ROLE_AGENT) {
            $perfSql .= " AND u.id = :uid";
            $perfParams[':uid'] = (int) $_SESSION['user_id'];
        }
        $perfSql .= "
            GROUP BY u.id, u.full_name, u.avatar
            ORDER BY leases_created DESC, rent_total DESC LIMIT 10
        ";
        $stmt = $db->prepare($perfSql);
        $stmt->execute($perfParams);
        $agentPerf = $stmt->fetchAll();

        $occupancy = $totalProperties > 0 ? round($stats['rented'] / $totalProperties * 100, 1) : 0;

        renderPage(VIEWS_PATH . '/admin/reports/index.php', [
            'stats' => $stats,
            'monthlyRevenue' => $monthlyRevenue,
            'byStatus' => $byStatus,
            'agentPerf' => $agentPerf,
            'occupancy' => $occupancy,
            'ranges' => array_map(static fn(array $r): string => $r['label'], self::RANGES),
            'range'  => $range,
            'pageTitle' => 'Reports & Analytics',
            'pageSubtitle' => getUserRole() === ROLE_AGENT
                ? 'Your properties, your money and your queue, as they stand right now.'
                : 'The portfolio, the money and the queue, as they stand right now.',
            'breadcrumbs' => [['label' => 'Reports']],
        ]);
    }

    public function occupancy(): void { $this->index(); }
    public function revenue(): void { $this->index(); }
    public function commission(): void { $this->index(); }
    public function arrears(): void { $this->index(); }
}
