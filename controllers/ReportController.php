<?php
/**
 * Report Controller — analytics dashboards.
 */
class ReportController
{
    public function index(): void
    {
        requireRole(ROLE_ADMIN, ROLE_AGENT);
        $db = getDBConnection();

        $stats = [
            'total_properties' => (int) $db->query("SELECT COUNT(*) FROM properties WHERE is_archived=0")->fetchColumn(),
            'available'        => (int) $db->query("SELECT COUNT(*) FROM properties WHERE status='available' AND is_archived=0")->fetchColumn(),
            'rented'           => (int) $db->query("SELECT COUNT(*) FROM properties WHERE status='rented' AND is_archived=0")->fetchColumn(),
            'sold'             => (int) $db->query("SELECT COUNT(*) FROM properties WHERE status='sold' AND is_archived=0")->fetchColumn(),
            'active_leases'    => (int) $db->query("SELECT COUNT(*) FROM leases WHERE status='active'")->fetchColumn(),
            'total_customers'  => (int) $db->query("SELECT COUNT(*) FROM customers")->fetchColumn(),
            'blacklisted'      => (int) $db->query("SELECT COUNT(*) FROM customers WHERE is_blacklisted=1")->fetchColumn(),
            'overdue_count'    => (int) $db->query("SELECT COUNT(*) FROM payment_schedules WHERE status='overdue'")->fetchColumn(),
            'arrears_total'    => (float) $db->query("SELECT COALESCE(SUM(amount+penalty),0) FROM payment_schedules WHERE status IN ('overdue','partial')")->fetchColumn(),
            'revenue_ytd'      => (float) $db->query("SELECT COALESCE(SUM(amount),0) FROM payments WHERE status='paid' AND YEAR(payment_date)=YEAR(CURDATE())")->fetchColumn(),
            'revenue_mtd'      => (float) $db->query("SELECT COALESCE(SUM(amount),0) FROM payments WHERE status='paid' AND MONTH(payment_date)=MONTH(CURDATE()) AND YEAR(payment_date)=YEAR(CURDATE())")->fetchColumn(),
            'commission_pending'=> (float) $db->query("SELECT COALESCE(SUM(amount),0) FROM commissions WHERE status='pending'")->fetchColumn(),
        ];

        // Revenue by month (last 6 months)
        $monthlyRevenue = $db->query("
            SELECT DATE_FORMAT(payment_date, '%Y-%m') AS month, SUM(amount) AS total
            FROM payments
            WHERE status='paid' AND payment_date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
            GROUP BY month ORDER BY month
        ")->fetchAll();

        // Properties by status
        $byStatus = $db->query("SELECT status, COUNT(*) AS c FROM properties WHERE is_archived=0 GROUP BY status")->fetchAll();

        // Agent performance (leases created in last 30 days)
        $agentPerf = $db->query("
            SELECT u.full_name, COUNT(l.id) AS leases_created,
                   COALESCE(SUM(l.rent_amount),0) AS rent_total
            FROM users u
            LEFT JOIN leases l ON l.created_by = u.id AND l.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
            WHERE u.role_id = 2 AND u.is_active = 1
            GROUP BY u.id, u.full_name
            ORDER BY leases_created DESC LIMIT 10
        ")->fetchAll();

        $occupancy = $stats['total_properties'] > 0 ? round($stats['rented'] / $stats['total_properties'] * 100, 1) : 0;

        renderPage(VIEWS_PATH . '/admin/reports/index.php', [
            'stats' => $stats,
            'monthlyRevenue' => $monthlyRevenue,
            'byStatus' => $byStatus,
            'agentPerf' => $agentPerf,
            'occupancy' => $occupancy,
            'pageTitle' => 'Reports & Analytics',
            'breadcrumbs' => [['label' => 'Reports']],
        ]);
    }

    public function occupancy(): void { $this->index(); }
    public function revenue(): void { $this->index(); }
    public function commission(): void { $this->index(); }
    public function arrears(): void { $this->index(); }
}
