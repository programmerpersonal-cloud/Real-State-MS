<?php
/**
 * Reports — Dashboard with Chart.js charts.
 */
?>
<div class="stats">
    <div class="stat-card">
        <div class="stat-card__icon stat-card__icon--primary"><i class="bi bi-buildings"></i></div>
        <div><div class="stat-card__value"><?= $stats['total_properties'] ?></div><div class="stat-card__label">Properties</div></div>
    </div>
    <div class="stat-card">
        <div class="stat-card__icon stat-card__icon--success"><i class="bi bi-percent"></i></div>
        <div><div class="stat-card__value"><?= $occupancy ?>%</div><div class="stat-card__label">Occupancy</div></div>
    </div>
    <div class="stat-card">
        <div class="stat-card__icon stat-card__icon--info"><i class="bi bi-cash-stack"></i></div>
        <div><div class="stat-card__value"><?= formatCurrency($stats['revenue_mtd']) ?></div><div class="stat-card__label">Revenue MTD</div></div>
    </div>
    <div class="stat-card">
        <div class="stat-card__icon stat-card__icon--purple"><i class="bi bi-graph-up"></i></div>
        <div><div class="stat-card__value"><?= formatCurrency($stats['revenue_ytd']) ?></div><div class="stat-card__label">Revenue YTD</div></div>
    </div>
    <div class="stat-card">
        <div class="stat-card__icon stat-card__icon--warning"><i class="bi bi-clock-history"></i></div>
        <div><div class="stat-card__value"><?= $stats['overdue_count'] ?></div><div class="stat-card__label">Overdue Invoices</div></div>
    </div>
    <div class="stat-card">
        <div class="stat-card__icon stat-card__icon--danger"><i class="bi bi-exclamation-circle"></i></div>
        <div><div class="stat-card__value"><?= formatCurrency($stats['arrears_total']) ?></div><div class="stat-card__label">Arrears Total</div></div>
    </div>
</div>

<div class="grid-2">
    <div class="card">
        <div class="card__header"><h3 class="card__title">Revenue — Last 6 Months</h3></div>
        <div class="card__body">
            <canvas id="revenueChart" height="180"></canvas>
        </div>
    </div>
    <div class="card">
        <div class="card__header"><h3 class="card__title">Properties by Status</h3></div>
        <div class="card__body">
            <canvas id="statusChart" height="180"></canvas>
        </div>
    </div>
</div>

<div class="card mt-3">
    <div class="card__header"><h3 class="card__title">Agent Performance (Last 30 days)</h3></div>
    <div class="card__body" style="padding:0">
        <?php if (empty($agentPerf)): ?>
            <div class="empty-state"><div class="empty-state__desc">No agents to display.</div></div>
        <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>Agent</th><th>Leases Created</th><th>Rent Volume</th></tr></thead>
                <tbody>
                <?php foreach ($agentPerf as $ap): ?>
                <tr>
                    <td><strong><?= sanitize($ap['full_name']) ?></strong></td>
                    <td><?= $ap['leases_created'] ?></td>
                    <td><?= formatCurrency((float)$ap['rent_total']) ?></td>
                </tr>
                <?php endforeach ?>
                </tbody>
            </table>
        </div>
        <?php endif ?>
    </div>
</div>

<script src="<?= VENDOR_URL ?>/chartjs/chart.umd.min.js"></script>
<script>
const revenueData = <?= json_encode($monthlyRevenue) ?>;
const statusData = <?= json_encode($byStatus) ?>;

new Chart(document.getElementById('revenueChart'), {
    type: 'line',
    data: {
        labels: revenueData.map(r => r.month),
        datasets: [{
            label: 'Revenue',
            data: revenueData.map(r => parseFloat(r.total)),
            borderColor: '#6366f1',
            backgroundColor: 'rgba(99,102,241,.1)',
            tension: 0.35, fill: true, pointBackgroundColor: '#6366f1', pointBorderColor: '#fff', pointRadius: 5
        }]
    },
    options: { responsive: true, plugins: { legend: { display: false } } }
});

new Chart(document.getElementById('statusChart'), {
    type: 'doughnut',
    data: {
        labels: statusData.map(s => s.status),
        datasets: [{
            data: statusData.map(s => s.c),
            backgroundColor: ['#10b981','#f59e0b','#3b82f6','#8b5cf6','#94a3b8','#f97316']
        }]
    },
    options: { responsive: true, plugins: { legend: { position: 'bottom' } } }
});
</script>
