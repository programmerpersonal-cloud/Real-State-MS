<?php
/**
 * Admin Dashboard
 */
$pageTitle    = 'Dashboard';
$pageSubtitle = 'Overview of properties, leases and operations across your portfolio.';
$breadcrumbs  = [['label' => 'Overview']];
$actionButtons = [
    ['label' => 'Export',      'icon' => 'bi-upload',  'class' => 'btn--outline', 'url' => '#'],
    ['label' => 'New Property','icon' => 'bi-plus-lg', 'class' => 'btn--primary', 'url' => APP_URL . '/index.php?page=properties&action=create'],
];

// Fetch stats
$db = getDBConnection();
$totalProperties = $db->query("SELECT COUNT(*) FROM properties")->fetchColumn();
$activeRentals   = $db->query("SELECT COUNT(*) FROM leases WHERE status = 'active'")->fetchColumn();
$totalCustomers  = $db->query("SELECT COUNT(*) FROM customers")->fetchColumn();
$pendingMaint    = $db->query("SELECT COUNT(*) FROM maintenance_requests WHERE status IN ('new','under_review','assigned')")->fetchColumn();
$overduePayments = $db->query("SELECT COUNT(*) FROM payments WHERE status = 'overdue'")->fetchColumn();
$totalUsers      = $db->query("SELECT COUNT(*) FROM users WHERE is_active = 1")->fetchColumn();

// Recent activity
$recentActivity = $db->query("SELECT a.*, u.full_name FROM audit_logs a LEFT JOIN users u ON a.user_id = u.id ORDER BY a.created_at DESC LIMIT 8")->fetchAll();
?>

<div class="stats">
    <div class="stat-card">
        <div class="stat-card__icon stat-card__icon--primary"><i class="bi bi-buildings"></i></div>
        <div class="stat-card__body">
            <div class="stat-card__label">Total Properties</div>
            <div class="stat-card__value"><?= $totalProperties ?></div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-card__icon stat-card__icon--success"><i class="bi bi-key"></i></div>
        <div class="stat-card__body">
            <div class="stat-card__label">Active Rentals</div>
            <div class="stat-card__value"><?= $activeRentals ?></div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-card__icon stat-card__icon--info"><i class="bi bi-people"></i></div>
        <div class="stat-card__body">
            <div class="stat-card__label">Customers</div>
            <div class="stat-card__value"><?= $totalCustomers ?></div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-card__icon stat-card__icon--warning"><i class="bi bi-wrench"></i></div>
        <div class="stat-card__body">
            <div class="stat-card__label">Pending Maintenance</div>
            <div class="stat-card__value"><?= $pendingMaint ?></div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-card__icon stat-card__icon--danger"><i class="bi bi-exclamation-circle"></i></div>
        <div class="stat-card__body">
            <div class="stat-card__label">Overdue Payments</div>
            <div class="stat-card__value"><?= $overduePayments ?></div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-card__icon stat-card__icon--purple"><i class="bi bi-shield-check"></i></div>
        <div class="stat-card__body">
            <div class="stat-card__label">Active Users</div>
            <div class="stat-card__value"><?= $totalUsers ?></div>
        </div>
    </div>
</div>

<div class="grid-2">
    <!-- Recent Activity -->
    <div class="card">
        <div class="card__header">
            <div>
                <h3 class="card__title">Recent Activity</h3>
                <div class="card__subtitle">Latest changes across the system</div>
            </div>
            <a href="<?= APP_URL ?>/index.php?page=audit-logs" class="btn btn--ghost btn--sm">View all <i class="bi bi-arrow-right"></i></a>
        </div>
        <div class="card__body" style="padding:0">
            <?php if (empty($recentActivity)): ?>
                <div class="empty-state">
                    <div class="empty-state__icon"><i class="bi bi-clock-history"></i></div>
                    <div class="empty-state__title">No activity yet</div>
                    <div class="empty-state__desc">System activity will appear here.</div>
                </div>
            <?php else: ?>
                <ul class="activity" style="padding:8px 20px 16px">
                    <?php foreach ($recentActivity as $act): ?>
                    <li class="activity__item">
                        <div class="activity__dot"></div>
                        <div>
                            <div class="activity__text">
                                <strong><?= sanitize($act['full_name'] ?? 'System') ?></strong>
                                <?= sanitize($act['action']) ?>
                                <?php if ($act['entity_type']): ?>
                                    <span class="text-subtle">· <?= sanitize($act['entity_type']) ?></span>
                                <?php endif; ?>
                            </div>
                            <div class="activity__time"><?= formatDateTime($act['created_at']) ?></div>
                        </div>
                    </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    </div>

    <!-- Quick Actions -->
    <div class="card">
        <div class="card__header">
            <div>
                <h3 class="card__title">Quick Actions</h3>
                <div class="card__subtitle">Shortcuts to common tasks</div>
            </div>
        </div>
        <div class="card__body" style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
            <a href="<?= APP_URL ?>/index.php?page=properties&action=create" class="btn btn--outline" style="justify-content:flex-start">
                <i class="bi bi-plus-circle"></i> Add Property
            </a>
            <a href="<?= APP_URL ?>/index.php?page=customers&action=create" class="btn btn--outline" style="justify-content:flex-start">
                <i class="bi bi-person-plus"></i> Add Customer
            </a>
            <a href="<?= APP_URL ?>/index.php?page=leases&action=create" class="btn btn--outline" style="justify-content:flex-start">
                <i class="bi bi-file-plus"></i> New Lease
            </a>
            <a href="<?= APP_URL ?>/index.php?page=payments&action=create" class="btn btn--outline" style="justify-content:flex-start">
                <i class="bi bi-cash"></i> Record Payment
            </a>
            <a href="<?= APP_URL ?>/index.php?page=maintenance&action=create" class="btn btn--outline" style="justify-content:flex-start">
                <i class="bi bi-tools"></i> Maintenance
            </a>
            <a href="<?= APP_URL ?>/index.php?page=reports" class="btn btn--outline" style="justify-content:flex-start">
                <i class="bi bi-bar-chart"></i> View Reports
            </a>
        </div>
    </div>
</div>
