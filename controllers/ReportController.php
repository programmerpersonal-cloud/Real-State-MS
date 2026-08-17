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

        // ── Portfolio ────────────────────────────────────────────────
        // One pass. The doughnut and the four headline counts were five
        // separate scans of the same rows for the same answer.
        $byStatus = $db->query("
            SELECT status, COUNT(*) AS c
            FROM properties WHERE is_archived = 0
            GROUP BY status
        ")->fetchAll();
        $statusCounts = array_map('intval', array_column($byStatus, 'c', 'status'));

        // ── People, arrears, revenue ─────────────────────────────────
        $customers = $db->query("
            SELECT COUNT(*) AS total, COALESCE(SUM(is_blacklisted = 1), 0) AS blacklisted
            FROM customers
        ")->fetch() ?: [];

        $arrears = $db->query("
            SELECT COALESCE(SUM(status = 'overdue'), 0) AS overdue_count,
                   COALESCE(SUM(CASE WHEN status IN ('overdue','partial') THEN amount + penalty END), 0) AS arrears_total
            FROM payment_schedules
        ")->fetch() ?: [];

        $revenue = $db->query("
            SELECT COALESCE(SUM(CASE WHEN YEAR(payment_date) = YEAR(CURDATE())
                                     THEN amount END), 0) AS ytd,
                   COALESCE(SUM(CASE WHEN YEAR(payment_date) = YEAR(CURDATE())
                                      AND MONTH(payment_date) = MONTH(CURDATE())
                                     THEN amount END), 0) AS mtd
            FROM payments WHERE status = 'paid'
        ")->fetch() ?: [];

        $totalProperties = array_sum($statusCounts);

        $stats = [
            'total_properties'   => $totalProperties,
            'available'          => $statusCounts['available'] ?? 0,
            'rented'             => $statusCounts['rented'] ?? 0,
            'sold'               => $statusCounts['sold'] ?? 0,
            'active_leases'      => (int) $db->query("SELECT COUNT(*) FROM leases WHERE status='active'")->fetchColumn(),
            'total_customers'    => (int) ($customers['total'] ?? 0),
            'blacklisted'        => (int) ($customers['blacklisted'] ?? 0),
            'overdue_count'      => (int) ($arrears['overdue_count'] ?? 0),
            'arrears_total'      => (float) ($arrears['arrears_total'] ?? 0),
            'revenue_ytd'        => (float) ($revenue['ytd'] ?? 0),
            'revenue_mtd'        => (float) ($revenue['mtd'] ?? 0),
            'commission_pending' => (float) $db->query("SELECT COALESCE(SUM(amount),0) FROM commissions WHERE status='pending'")->fetchColumn(),
        ];

        // Revenue by month. The window is a bound integer resolved from
        // self::RANGES, never a value carried in from the request.
        $stmt = $db->prepare("
            SELECT DATE_FORMAT(payment_date, '%Y-%m') AS month, SUM(amount) AS total
            FROM payments
            WHERE status = 'paid' AND payment_date >= DATE_SUB(CURDATE(), INTERVAL :months MONTH)
            GROUP BY month ORDER BY month
        ");
        $stmt->bindValue(':months', $months, PDO::PARAM_INT);
        $stmt->execute();
        $monthlyRevenue = $stmt->fetchAll();

        // Agent performance (leases created in the last 30 days)
        $agentPerf = $db->query("
            SELECT u.full_name, u.avatar, COUNT(l.id) AS leases_created,
                   COALESCE(SUM(l.rent_amount),0) AS rent_total
            FROM users u
            LEFT JOIN leases l ON l.created_by = u.id AND l.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
            WHERE u.role_id = 2 AND u.is_active = 1
            GROUP BY u.id, u.full_name, u.avatar
            ORDER BY leases_created DESC, rent_total DESC LIMIT 10
        ")->fetchAll();

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
            'pageSubtitle' => 'The portfolio, the money and the queue, as they stand right now.',
            'breadcrumbs' => [['label' => 'Reports']],
        ]);
    }

    public function occupancy(): void { $this->index(); }
    public function revenue(): void { $this->index(); }
    public function commission(): void { $this->index(); }
    public function arrears(): void { $this->index(); }
}
