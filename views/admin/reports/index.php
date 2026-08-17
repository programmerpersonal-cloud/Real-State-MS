<?php
/**
 * Reports — the numbers.
 *
 * The charts used to be drawn in indigo, emerald and amber — a palette from
 * nowhere in this product. They now read the same design tokens the dashboard
 * charts do, and the doughnut takes each slice's colour from the very map that
 * paints the status badge on a property row, so "rented" is one colour
 * everywhere it appears.
 *
 * Vars from ReportController::index().
 */
$reportsUrl = APP_URL . '/index.php?page=reports';

/* Slice colours resolved through the shared status map, so the legend and the
   badges on the Properties register cannot disagree. */
$statusTones = [];
foreach ($byStatus as $s) {
    $statusTones[] = [
        'label' => uiLabel((string) $s['status']),
        'count' => (int) $s['c'],
        'token' => statusToneVar((string) $s['status']),
    ];
}

$rangeUrl = static function (string $key) use ($reportsUrl): string {
    $params = $_GET;
    unset($params['p'], $params['page']);
    $params['range'] = $key;
    return $reportsUrl . '&' . http_build_query($params);
};
?>

<div class="stats">
    <div class="stat-card">
        <div class="stat-card__icon stat-card__icon--primary"><i class="bi bi-buildings" aria-hidden="true"></i></div>
        <div class="stat-card__body">
            <div class="stat-card__value"><?= number_format($stats['total_properties']) ?></div>
            <div class="stat-card__label">Properties on the books</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-card__icon stat-card__icon--success"><i class="bi bi-percent" aria-hidden="true"></i></div>
        <div class="stat-card__body">
            <div class="stat-card__value"><?= $occupancy ?>%</div>
            <div class="stat-card__label"><?= number_format($stats['rented']) ?> let of <?= number_format($stats['total_properties']) ?></div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-card__icon stat-card__icon--info"><i class="bi bi-cash-stack" aria-hidden="true"></i></div>
        <div class="stat-card__body">
            <div class="stat-card__value"><?= formatCurrency($stats['revenue_mtd']) ?></div>
            <div class="stat-card__label">Taken this month</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-card__icon stat-card__icon--purple"><i class="bi bi-graph-up" aria-hidden="true"></i></div>
        <div class="stat-card__body">
            <div class="stat-card__value"><?= formatCurrency($stats['revenue_ytd']) ?></div>
            <div class="stat-card__label">Taken this year</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-card__icon stat-card__icon--warning"><i class="bi bi-clock-history" aria-hidden="true"></i></div>
        <div class="stat-card__body">
            <div class="stat-card__value"><?= number_format($stats['overdue_count']) ?></div>
            <div class="stat-card__label">Instalments overdue</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-card__icon stat-card__icon--danger"><i class="bi bi-exclamation-circle" aria-hidden="true"></i></div>
        <div class="stat-card__body">
            <div class="stat-card__value"><?= formatCurrency($stats['arrears_total']) ?></div>
            <div class="stat-card__label">Outstanding in arrears</div>
        </div>
    </div>
</div>

<div class="grid-2 mt-2">
    <div class="card">
        <div class="card__header">
            <h3 class="card__title">Revenue received</h3>
            <div class="segmented" role="group" aria-label="Change the revenue period">
                <?php foreach ($ranges as $key => $label): ?>
                    <a class="segmented__btn<?= $range === $key ? ' is-active' : '' ?>"
                       href="<?= sanitize($rangeUrl($key)) ?>"
                       <?= $range === $key ? 'aria-current="true"' : '' ?>><?= sanitize($label) ?></a>
                <?php endforeach ?>
            </div>
        </div>
        <div class="card__body">
            <?php if (empty($monthlyRevenue)): ?>
                <?= uiEmptyState([
                    'icon'  => 'bi-graph-up',
                    'title' => 'No payments in this period',
                    'desc'  => 'Nothing has been recorded as paid in the window selected above.',
                ]) ?>
            <?php else: ?>
                <canvas id="revenueChart" height="190"
                        aria-label="Revenue received per month" role="img"></canvas>
            <?php endif ?>
        </div>
    </div>

    <div class="card">
        <div class="card__header"><h3 class="card__title">Where the portfolio stands</h3></div>
        <div class="card__body">
            <?php if (empty($statusTones)): ?>
                <?= uiEmptyState([
                    'icon'  => 'bi-pie-chart',
                    'title' => 'Nothing on the books',
                    'desc'  => 'Add a property and its status appears here.',
                ]) ?>
            <?php else: ?>
                <canvas id="statusChart" height="190"
                        aria-label="Properties by status" role="img"></canvas>
                <?php /* The chart is a picture; this is the same data as text,
                         which is what a screen reader and a printed page get. */ ?>
                <dl class="datalist mt-2">
                    <?php foreach ($statusTones as $s): ?>
                        <div class="datalist__row">
                            <dt>
                                <span class="dot" style="background:var(<?= sanitize($s['token']) ?>)" aria-hidden="true"></span>
                                <?= sanitize($s['label']) ?>
                            </dt>
                            <dd class="num"><?= number_format($s['count']) ?></dd>
                        </div>
                    <?php endforeach ?>
                </dl>
            <?php endif ?>
        </div>
    </div>
</div>

<div class="table-card mt-3">
    <div class="table-head">
        <div class="table-head__title">Agent performance</div>
        <span class="table-head__note">Leases created in the last 30 days</span>
    </div>

    <?php if (empty($agentPerf)): ?>
        <?= uiEmptyState([
            'icon'  => 'bi-person-badge',
            'title' => 'No active agents',
            'desc'  => 'Give an account the Agent role and its figures appear here.',
            'actions' => [[
                'label' => 'Users & Roles', 'icon' => 'bi-people', 'can' => 'users.view',
                'class' => 'btn--outline', 'url' => APP_URL . '/index.php?page=users',
            ]],
        ]) ?>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th>Agent</th>
                        <th class="cell-num">Leases created</th>
                        <th class="cell-num">Monthly rent signed</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($agentPerf as $ap): ?>
                        <tr>
                            <td><?= uiPersonCell($ap['full_name'], $ap['avatar'] ?? null) ?></td>
                            <td class="cell-num">
                                <?php if ((int) $ap['leases_created'] > 0): ?>
                                    <?= number_format((int) $ap['leases_created']) ?>
                                <?php else: ?>
                                    <span class="text-subtle">—</span>
                                <?php endif ?>
                            </td>
                            <td class="cell-num">
                                <?php if ((float) $ap['rent_total'] > 0): ?>
                                    <?= formatCurrency((float) $ap['rent_total']) ?>
                                <?php else: ?>
                                    <span class="text-subtle">—</span>
                                <?php endif ?>
                            </td>
                        </tr>
                    <?php endforeach ?>
                </tbody>
            </table>
        </div>
    <?php endif ?>
</div>

<?php if (!empty($monthlyRevenue) || !empty($statusTones)): ?>
<script src="<?= VENDOR_URL ?>/chartjs/chart.umd.min.js"></script>
<script>
(function () {
    if (!window.Chart) return;   /* vendor file missing: the figures above still stand */

    /* Colours come from the stylesheet, not from this file, so the charts
       cannot drift from the palette the rest of the product uses. The
       fallbacks cover the case where the canvas is drawn before the
       stylesheet has applied. */
    var css   = getComputedStyle(document.documentElement);
    var token = function (name, fallback) { return css.getPropertyValue(name).trim() || fallback; };
    var rgba  = function (hex, a) {
        var h = hex.replace('#', '');
        if (h.length === 3) { h = h[0] + h[0] + h[1] + h[1] + h[2] + h[2]; }
        var n = parseInt(h, 16);
        return 'rgba(' + ((n >> 16) & 255) + ',' + ((n >> 8) & 255) + ',' + (n & 255) + ',' + a + ')';
    };

    var ink   = token('--text-muted', '#5f6b7e');
    var line  = token('--border', '#e4eaee');
    var brand = token('--primary', '#0075c0');

    Chart.defaults.font.family = token('--font', 'Inter, sans-serif');
    Chart.defaults.font.size   = 11;
    Chart.defaults.color       = ink;

    var symbol = <?= json_encode(currencySymbol(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    var brief = function (v) {
        if (Math.abs(v) >= 1e6) return symbol + (v / 1e6).toFixed(1).replace(/\.0$/, '') + 'M';
        if (Math.abs(v) >= 1e3) return symbol + (v / 1e3).toFixed(1).replace(/\.0$/, '') + 'K';
        return symbol + v;
    };
    var full = function (v) { return symbol + Number(v).toLocaleString(undefined, { maximumFractionDigits: 2 }); };

    var revenue = <?= json_encode($monthlyRevenue, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    var revenueEl = document.getElementById('revenueChart');
    if (revenueEl && revenue.length) {
        new Chart(revenueEl, {
            type: 'line',
            data: {
                labels: revenue.map(function (r) { return r.month; }),
                datasets: [{
                    label: 'Revenue',
                    data: revenue.map(function (r) { return parseFloat(r.total); }),
                    borderColor: brand,
                    backgroundColor: rgba(brand, 0.12),
                    borderWidth: 2,
                    tension: 0.35,
                    fill: true,
                    pointBackgroundColor: brand,
                    pointBorderColor: token('--surface', '#ffffff'),
                    pointBorderWidth: 2,
                    pointRadius: 4,
                    pointHoverRadius: 6
                }]
            },
            options: {
                /* The height attribute on the canvas sets the aspect ratio and
                   Chart.js scales to the container width — no wrapper with a
                   fixed height needed, which is what maintainAspectRatio:false
                   would have required. */
                responsive: true,
                plugins: {
                    legend: { display: false },
                    tooltip: { callbacks: { label: function (c) { return full(c.parsed.y); } } }
                },
                scales: {
                    x: { grid: { display: false }, border: { color: line } },
                    y: {
                        beginAtZero: true,
                        border: { display: false },
                        grid: { color: line },
                        ticks: { callback: function (v) { return brief(v); } }
                    }
                }
            }
        });
    }

    var slices = <?= json_encode($statusTones, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    var statusEl = document.getElementById('statusChart');
    if (statusEl && slices.length) {
        new Chart(statusEl, {
            type: 'doughnut',
            data: {
                labels: slices.map(function (s) { return s.label; }),
                datasets: [{
                    data: slices.map(function (s) { return s.count; }),
                    /* The same token that paints the badge on the row. */
                    backgroundColor: slices.map(function (s) { return token(s.token, ink); }),
                    borderColor: token('--surface', '#ffffff'),
                    borderWidth: 2
                }]
            },
            options: {
                /* The height attribute on the canvas sets the aspect ratio and
                   Chart.js scales to the container width — no wrapper with a
                   fixed height needed, which is what maintainAspectRatio:false
                   would have required. */
                responsive: true,
                cutout: '62%',
                plugins: { legend: { display: false } }
            }
        });
    }
})();
</script>
<?php endif ?>
