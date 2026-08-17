<?php
/**
 * Admin Dashboard
 */
$pageTitle    = 'Dashboard';
$pageSubtitle = 'Overview of properties, leases and operations across your portfolio.';
$breadcrumbs  = [['label' => 'Overview']];

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

// Document expiry. Nothing is ever deleted automatically, so this warning is
// the only thing that surfaces a permit about to lapse.
require_once BASE_PATH . '/models/Document.php';
$docModel     = new Document();
$docCounts    = $docModel->expiryCounts();
$expiringDocs = ($docCounts['expiring'] > 0 || $docCounts['expired'] > 0)
    ? array_merge($docModel->expired(5), $docModel->expiring(null, 5))
    : [];

/* ── Quick actions ──────────────────────────────────────────────
   Each one opens the module's own quick-add popup right here, so a
   property, a customer or a payment can be recorded without losing
   the overview. One list drives both the tiles and the dialogs at
   the foot of this file, so a button can never point at a popup
   that was never rendered.

   A tile is offered only when the role may perform the action —
   and, for maintenance, only when there is a property to file
   against, which is the same pair of conditions the module's own
   page checks before showing its button. */
$maintenanceProperties = can('maintenance.create') ? maintenanceSelectableProperties() : [];

$quickActions = array_values(array_filter([
    ['key' => 'property',    'module' => 'properties',  'modal' => 'propertyCreateModal',
     'icon' => 'bi-house-add',          'tone' => 'primary', 'label' => 'Add Property',   'hint' => 'List a new unit'],
    ['key' => 'customer',    'module' => 'customers',   'modal' => 'customerCreateModal',
     'icon' => 'bi-person-plus',        'tone' => 'info',    'label' => 'Add Customer',   'hint' => 'Tenant or buyer'],
    ['key' => 'owner',       'module' => 'owners',      'modal' => 'ownerCreateModal',
     'icon' => 'bi-person-badge',       'tone' => 'purple',  'label' => 'Add Owner',      'hint' => 'Landlord record'],
    ['key' => 'lease',       'module' => 'leases',      'modal' => 'leaseCreateModal',
     'icon' => 'bi-file-earmark-text',  'tone' => 'success', 'label' => 'New Lease',      'hint' => 'Start a tenancy'],
    ['key' => 'payment',     'module' => 'payments',    'modal' => 'paymentCreateModal',
     'icon' => 'bi-cash-coin',          'tone' => 'warning', 'label' => 'Record Payment', 'hint' => 'Rent or one-off'],
    ['key' => 'maintenance', 'module' => 'maintenance', 'modal' => 'maintenanceCreateModal',
     'icon' => 'bi-tools',              'tone' => 'orange',  'label' => 'Maintenance',    'hint' => 'Report an issue',
     'ready' => $maintenanceProperties !== []],
], static fn (array $a): bool => can($a['module'] . '.create') && ($a['ready'] ?? true)));

/** Is this popup one the current user was offered? Nothing else is rendered. */
$hostsAction = static fn (string $key): bool => in_array($key, array_column($quickActions, 'key'), true);

/** The full page behind a quick action — where the tile leads without JS. */
$quickActionUrl = static fn (array $a): string => APP_URL . '/index.php?page=' . $a['module'] . '&action=create';

// The header keeps the primary action of the moment. It opens the same popup
// as the tile below, and still leads to the full form when scripting is off.
$actionButtons = [['label' => 'Export', 'icon' => 'bi-upload', 'class' => 'btn--outline', 'url' => '#']];
if ($hostsAction('property')) {
    $actionButtons[] = [
        'label' => 'New Property', 'icon' => 'bi-plus-lg', 'class' => 'btn--primary',
        'url'   => APP_URL . '/index.php?page=properties&action=create',
        'attrs' => ['data-modal-open' => 'propertyCreateModal'],
    ];
}
?>

<?php if ($expiringDocs): ?>
<div class="notice" role="status">
    <div class="notice__icon"><i class="bi bi-calendar-x" aria-hidden="true"></i></div>
    <div class="notice__body">
        <div class="notice__title">
            <?php if ($docCounts['expired'] > 0): ?>
                <?= $docCounts['expired'] ?> document<?= $docCounts['expired'] === 1 ? '' : 's' ?> expired<?= $docCounts['expiring'] > 0 ? ', ' : '' ?>
            <?php endif ?>
            <?php if ($docCounts['expiring'] > 0): ?>
                <?= $docCounts['expiring'] ?> expiring within <?= documentExpiryWarningDays() ?> days
            <?php endif ?>
        </div>
        <ul class="notice__list">
            <?php foreach (array_slice($expiringDocs, 0, 5) as $d): $st = documentStatus($d); ?>
                <li class="notice__item">
                    <a href="<?= APP_URL ?>/index.php?page=documents&amp;action=show&amp;id=<?= (int) $d['id'] ?>">
                        <?= sanitize($d['title']) ?>
                    </a>
                    <?php if (!empty($d['property_title'])): ?>
                        <span class="text-muted">· <?= sanitize($d['property_title']) ?></span>
                    <?php endif ?>
                    <span class="badge <?= $st['badge'] ?>"><?= sanitize(documentExpiryNote($d)) ?></span>
                </li>
            <?php endforeach ?>
        </ul>
        <a href="<?= APP_URL ?>/index.php?page=documents&amp;state=expired" class="notice__link">
            Review all expiring documents <i class="bi bi-arrow-right" aria-hidden="true"></i>
        </a>
    </div>
</div>
<?php endif ?>

<?php
/* The KPI row, as data — same six figures, same labels, same order. Keeping
   them in a list rather than six copies of the same markup means the glyph,
   the tone and the number can never drift apart, and the tone drives both
   the icon tile and the card's accent from one place.

   Glyphs match the module each figure belongs to, so the row and the rail
   name the same things the same way: Maintenance is the sidebar's wrench,
   Payments is money, Users is the shield. */
$statCards = [
    ['icon' => 'bi-buildings',         'tone' => 'primary', 'label' => 'Total Properties',    'value' => $totalProperties],
    ['icon' => 'bi-key',               'tone' => 'success', 'label' => 'Active Rentals',      'value' => $activeRentals],
    ['icon' => 'bi-people',            'tone' => 'info',    'label' => 'Customers',           'value' => $totalCustomers],
    ['icon' => 'bi-wrench-adjustable', 'tone' => 'warning', 'label' => 'Pending Maintenance', 'value' => $pendingMaint],
    ['icon' => 'bi-cash-stack',        'tone' => 'danger',  'label' => 'Overdue Payments',    'value' => $overduePayments],
    ['icon' => 'bi-shield-check',      'tone' => 'purple',  'label' => 'Active Users',        'value' => $totalUsers],
];
?>
<div class="stats">
    <?php foreach ($statCards as $sc): ?>
        <div class="stat-card">
            <div class="stat-card__icon stat-card__icon--<?= $sc['tone'] ?>">
                <i class="bi <?= $sc['icon'] ?>" aria-hidden="true"></i>
            </div>
            <div class="stat-card__body">
                <div class="stat-card__label"><?= sanitize($sc['label']) ?></div>
                <div class="stat-card__value"><?= $sc['value'] ?></div>
            </div>
        </div>
    <?php endforeach ?>
</div>

<?php
/* ── Revenue ─────────────────────────────────────────────────────
   The question the KPI row above cannot answer: is money still
   arriving at the rate it was. It reads the payments table the
   Payments module reads, so this page can never quote a figure that
   module would disagree with.

   Beside it sits the portfolio's other half of the same question —
   how the listings are split by status — so the two charts that
   describe the business as a whole are read together, on the one
   page whose job is the overview.

   Money follows the same permission as the Reports link in the
   Quick Actions header: a role that may not open Reports is not
   handed the revenue trend here by a side door. */
$canSeeRevenue = can('reports.view');

/* Six whole calendar months, zero-filled before the query runs.
   Grouping payments by month returns only the months that had one,
   and a month with no takings has to read as a zero on the line —
   dropping it closes the gap and flatters the trend. */
$revenueSeries = [];
if ($canSeeRevenue) {
    $thisMonth = new DateTimeImmutable('first day of this month');
    $months    = [];
    for ($i = 5; $i >= 0; $i--) {
        $m = $thisMonth->modify("-{$i} month");
        $months[$m->format('Y-m')] = [
            'label' => $m->format('M'),      // axis: short, six of them fit
            // Tooltip: unambiguous across a year end, and the last bucket says
            // out loud that it is still filling — otherwise a month that is
            // three days old reads as a collapse in takings.
            'full'  => $m->format('F Y') . ($i === 0 ? ' (so far)' : ''),
            'total' => 0.0,
        ];
    }

    // Anchored to the first of the earliest bucket rather than "six months
    // back from today", which would open a seventh, part-month bucket.
    $stmt = $db->prepare("
        SELECT DATE_FORMAT(payment_date, '%Y-%m') AS ym, SUM(amount) AS total
        FROM payments
        WHERE status = 'paid' AND payment_date >= :from
        GROUP BY ym
    ");
    $stmt->execute([':from' => $thisMonth->modify('-5 month')->format('Y-m-d')]);
    foreach ($stmt->fetchAll() as $r) {
        if (isset($months[$r['ym']])) {
            $months[$r['ym']]['total'] = (float) $r['total'];
        }
    }
    $revenueSeries = array_values($months);
}
$revenueTotal = array_sum(array_column($revenueSeries, 'total'));

/* ── Properties by status ────────────────────────────────────────
   Shown only to the roles that hold the property register — an owner
   or a tenant reaches individual listings but never the agency's
   book, so the same permission that opens Properties governs this. */
$statusBreakdown = can('properties.view') ? propertyStatusBreakdown() : [];
$statusTotal     = array_sum(array_column($statusBreakdown, 'count'));

/* The ring is one conic-gradient built from running totals, so each
   segment starts where the last one stopped and the last one lands
   exactly on 100% rather than a rounding error short of it. Drawn in
   CSS rather than on a canvas: the shares are already known here, so
   the figure is complete before any script has run — and stays right
   if the charting library never arrives. */
$ringStops = [];
$ringAt    = 0.0;
foreach ($statusBreakdown as $s) {
    $from    = $ringAt;
    $ringAt += $s['pct'];
    $ringStops[] = sprintf('var(%s) %.3f%% %.3f%%', $s['var'], $from, $ringAt);
}
$ringGradient = $ringStops ? 'conic-gradient(' . implode(',', $ringStops) . ')' : '';

/* What the number inside the ring means, spelled out — the glyph alone
   is just a figure floating in a circle. */
$statusLabel = $statusTotal . ' live listing' . ($statusTotal === 1 ? '' : 's');

/* Two charts share the row and the line gets the wider half; one chart
   on its own takes the full width rather than leaving a column empty. */
$showStatus = $statusBreakdown !== [];
$splitRow   = $canSeeRevenue && $showStatus;
?>

<?php if ($canSeeRevenue || $showStatus): ?>
<div class="chart-grid<?= $splitRow ? ' chart-grid--split' : '' ?>">
    <?php if ($canSeeRevenue): ?>
    <div class="card">
        <div class="card__header">
            <div>
                <h3 class="card__title">Revenue</h3>
                <div class="card__subtitle">Payments received, last 6 months</div>
            </div>
            <div class="chart-figure"><?= formatCurrency($revenueTotal) ?></div>
        </div>
        <div class="card__body">
            <?php if ($revenueTotal <= 0): ?>
                <div class="empty-state">
                    <div class="empty-state__icon"><i class="bi bi-graph-up"></i></div>
                    <div class="empty-state__title">No payments recorded</div>
                    <div class="empty-state__desc">Received payments will chart here.</div>
                </div>
            <?php else: ?>
                <?php /* The canvas is the only version of this figure, so it
                         carries its own summary for a screen reader; the total
                         beside the title says the same thing in text. */ ?>
                <div class="chart-box">
                    <canvas id="dashRevenueChart" role="img"
                            aria-label="Payments received per month over the last six months, totalling <?= sanitize(formatCurrency($revenueTotal)) ?>."></canvas>
                </div>
            <?php endif ?>
        </div>
    </div>
    <?php endif ?>

    <?php if ($showStatus): ?>
    <div class="card">
        <div class="card__header">
            <div>
                <h3 class="card__title">Properties by Status</h3>
                <div class="card__subtitle">Live listings, archived excluded</div>
            </div>
            <a href="<?= APP_URL ?>/index.php?page=properties" class="btn btn--ghost btn--sm">
                View all <i class="bi bi-arrow-right"></i>
            </a>
        </div>
        <div class="card__body">
            <div class="statbreak">
                <?php /* The ring names itself and nothing else: the total it
                         carries is read from the label, and the slices are
                         read from the legend beside it rather than twice. */ ?>
                <div class="statring statring--lg" style="background:<?= $ringGradient ?>"
                     role="img" aria-label="<?= sanitize($statusLabel) ?>">
                    <span class="statring__total" aria-hidden="true"><?= $statusTotal ?></span>
                </div>
                <?php /* The figures in text as well as in the ring: colour is
                         never the only thing saying which status is which. */ ?>
                <ul class="statbreak__legend">
                    <?php foreach ($statusBreakdown as $s): ?>
                        <li class="statbreak__row">
                            <span class="statbreak__dot" style="background:var(<?= $s['var'] ?>)" aria-hidden="true"></span>
                            <span class="statbreak__label"><?= sanitize($s['label']) ?></span>
                            <span class="statbreak__count"><?= $s['count'] ?></span>
                            <span class="statbreak__pct"><?= round($s['pct']) ?>%</span>
                        </li>
                    <?php endforeach ?>
                </ul>
            </div>
        </div>
    </div>
    <?php endif ?>
</div>
<?php endif ?>

<div class="dash-grid">
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
                <ul class="activity dash-feed">
                    <?php foreach ($recentActivity as $act): ?>
                    <li class="activity__item">
                        <div class="activity__dot" aria-hidden="true"></div>
                        <div>
                            <div class="activity__text">
                                <strong><?= sanitize($act['full_name'] ?? 'System') ?></strong>
                                <?= sanitize($act['action']) ?>
                                <?php if ($act['entity_type']): ?>
                                    <span class="activity__tag"><?= sanitize($act['entity_type']) ?></span>
                                <?php endif; ?>
                            </div>
                            <div class="activity__time">
                                <i class="bi bi-clock" aria-hidden="true"></i>
                                <?= formatDateTime($act['created_at']) ?>
                            </div>
                        </div>
                    </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    </div>

    <!-- Quick Actions -->
    <?php /* Sized to its tiles rather than stretched to match the activity
             feed beside it, which is as long as the audit log makes it.
             .dash-grid aligns both columns to the start for this reason. */ ?>
    <div class="card">
        <div class="card__header">
            <div>
                <h3 class="card__title">Quick Actions</h3>
                <div class="card__subtitle">Create a record without leaving this page</div>
            </div>
            <?php if (can('reports.view')): ?>
                <a href="<?= APP_URL ?>/index.php?page=reports" class="btn btn--ghost btn--sm">Reports <i class="bi bi-arrow-right"></i></a>
            <?php endif ?>
        </div>
        <div class="card__body">
            <?php if (empty($quickActions)): ?>
                <div class="empty-state">
                    <div class="empty-state__icon"><i class="bi bi-lightning-charge"></i></div>
                    <div class="empty-state__title">Nothing to add from here</div>
                    <div class="empty-state__desc">Your role does not create records directly.</div>
                </div>
            <?php else: ?>
                <div class="quick-actions">
                    <?php foreach ($quickActions as $qa): ?>
                        <?php /* A link, not a button: the popup is the fast path, and the
                                 full form behind it stays reachable if scripting is off. */ ?>
                        <a class="quick-action" href="<?= $quickActionUrl($qa) ?>" data-modal-open="<?= $qa['modal'] ?>">
                            <span class="quick-action__icon quick-action__icon--<?= $qa['tone'] ?>">
                                <i class="bi <?= $qa['icon'] ?>" aria-hidden="true"></i>
                            </span>
                            <span class="quick-action__text">
                                <span class="quick-action__label"><?= sanitize($qa['label']) ?></span>
                                <span class="quick-action__hint"><?= sanitize($qa['hint']) ?></span>
                            </span>
                            <i class="bi bi-arrow-right quick-action__go" aria-hidden="true"></i>
                        </a>
                    <?php endforeach ?>
                </div>
            <?php endif ?>
        </div>
    </div>
</div>

<?php
/* ── The quick-add popups ────────────────────────────────────────
   The same partials the module lists use, so a form filled in here
   and a form filled in there are literally the same markup posting
   to the same action. $modalHost tells each one it was opened from
   the dashboard, so a rejected entry comes back to this screen
   rather than dumping the user in a list they never asked for.

   Every partial reads its option lists out of the enclosing scope
   by name, so each one is set immediately before the popup that
   needs it — and never left standing for the next popup to pick up
   by accident. */
$modalHost = 'dashboard';
$reopen    = (string) ($_GET['modal'] ?? '');

/* A rejected submit hands back exactly one entry: the one belonging
   to the popup being reopened. The other popups start empty, so no
   stray values are left waiting in a form nobody filled in. */
$rejectedData   = $_SESSION['form_data'] ?? [];
$rejectedErrors = $_SESSION['form_errors'] ?? [];
unset($_SESSION['form_data'], $_SESSION['form_errors']);
?>

<?php if ($hostsAction('property')):
    require_once BASE_PATH . '/controllers/PropertyController.php';
    $openCreateModal = $reopen === 'property';
    $fd = $openCreateModal ? $rejectedData : [];
    ['owners' => $owners, 'agents' => $agents, 'branches' => $branches] = PropertyController::formLookups();
    require VIEWS_PATH . '/admin/properties/_create_modal.php';
endif ?>

<?php if ($hostsAction('customer')):
    $openCreateModal = $reopen === 'customer';
    $fd         = $openCreateModal ? $rejectedData : [];
    $formErrors = $openCreateModal ? $rejectedErrors : [];
    require VIEWS_PATH . '/admin/customers/_create_modal.php';
endif ?>

<?php if ($hostsAction('owner')):
    $openCreateModal = $reopen === 'owner';
    $fd         = $openCreateModal ? $rejectedData : [];
    $formErrors = $openCreateModal ? $rejectedErrors : [];
    require VIEWS_PATH . '/admin/owners/_create_modal.php';
endif ?>

<?php if ($hostsAction('lease')):
    require_once BASE_PATH . '/controllers/LeaseController.php';
    $openCreateModal = $reopen === 'lease';
    $fd = $openCreateModal ? $rejectedData : [];
    ['properties' => $properties, 'customers' => $customers] = LeaseController::formLookups();
    require VIEWS_PATH . '/admin/leases/_create_modal.php';
endif ?>

<?php if ($hostsAction('payment')):
    require_once BASE_PATH . '/controllers/PaymentController.php';
    $openCreateModal = $reopen === 'payment';
    $fd     = $openCreateModal ? $rejectedData : [];
    $leases = PaymentController::activeLeases();
    require VIEWS_PATH . '/admin/payments/_create_modal.php';
endif ?>

<?php if ($hostsAction('maintenance')):
    $openCreateModal = $reopen === 'maintenance';
    $fd         = $openCreateModal ? $rejectedData : [];
    $properties = $maintenanceProperties;   // scoped by role, not the lease list above
    require VIEWS_PATH . '/admin/maintenance/_create_modal.php';
endif ?>

<?php
/* ── Drawing the chart ───────────────────────────────────────────
   Loaded only when there is something to draw, so a fresh install
   showing an empty state does not also fetch the charting library
   to do nothing with it.

   Every colour is read back out of the stylesheet rather than
   written twice, so a change to a token moves the chart with it.
   The fallbacks are what the tokens currently hold, for the case
   where the canvas is drawn before the stylesheet has applied. */
$drawRevenue = $canSeeRevenue && $revenueTotal > 0;
?>
<?php if ($drawRevenue): ?>
<script src="<?= VENDOR_URL ?>/chartjs/chart.umd.min.js"></script>
<script>
(function () {
    if (!window.Chart) return;   // vendor file missing: the text total still stands

    var css   = getComputedStyle(document.documentElement);
    var token = function (name, fallback) { return css.getPropertyValue(name).trim() || fallback; };

    var ink     = token('--text-muted', '#5f6b7e');
    var line    = token('--border', '#e4eaee');
    var surface = token('--surface', '#ffffff');
    var brand   = token('--primary', '#0075c0');

    Chart.defaults.font.family = token('--font', 'Inter, sans-serif');
    Chart.defaults.font.size   = 11;
    Chart.defaults.color       = ink;
    // The rest of the page drops its motion under this query; so does this.
    if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
        Chart.defaults.animation = false;
    }

    // The fill under the revenue line is the brand colour at low alpha.
    // Derived from the token so it cannot drift away from the stroke.
    function rgba(hex, alpha) {
        var h = hex.replace('#', '');
        if (h.length === 3) h = h[0] + h[0] + h[1] + h[1] + h[2] + h[2];
        var n = parseInt(h, 16);
        return 'rgba(' + ((n >> 16) & 255) + ',' + ((n >> 8) & 255) + ',' + (n & 255) + ',' + alpha + ')';
    }

    var symbol = <?= json_encode(currencySymbol(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    var money  = function (v) { return symbol + v.toLocaleString(undefined, { maximumFractionDigits: 0 }); };
    // Axis labels abbreviate so six months of ticks stay readable; the
    // tooltip is where the exact figure is.
    var brief = function (v) {
        if (Math.abs(v) >= 1e6) return symbol + (v / 1e6).toFixed(1).replace(/\.0$/, '') + 'M';
        if (Math.abs(v) >= 1e3) return symbol + (v / 1e3).toFixed(1).replace(/\.0$/, '') + 'K';
        return symbol + v;
    };

    var revenue = <?= json_encode($revenueSeries, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    var revenueEl = document.getElementById('dashRevenueChart');
    if (revenueEl) {
        new Chart(revenueEl, {
            type: 'line',
            data: {
                labels: revenue.map(function (p) { return p.label; }),
                datasets: [{
                    label: 'Revenue',
                    data: revenue.map(function (p) { return p.total; }),
                    borderColor: brand,
                    backgroundColor: rgba(brand, 0.12),
                    borderWidth: 2,
                    fill: true,
                    // Monotone rather than a plain tension: a month with no
                    // takings sits on zero, and ordinary cubic smoothing
                    // overshoots through it and draws the curve below the
                    // axis, which reads as money going out.
                    cubicInterpolationMode: 'monotone',
                    pointRadius: 3,
                    pointHoverRadius: 5,
                    pointBackgroundColor: brand,
                    pointBorderColor: surface,
                    pointBorderWidth: 2
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                // Hovering anywhere in a month's column reads that month,
                // rather than requiring the pointer to find a 3px dot.
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: { display: false },   // one series, already named by the card
                    tooltip: {
                        displayColors: false,
                        callbacks: {
                            title: function (items) { return revenue[items[0].dataIndex].full; },
                            label: function (item) { return money(item.parsed.y); }
                        }
                    }
                },
                scales: {
                    x: {
                        grid:   { display: false },
                        border: { color: line },
                        ticks:  { color: ink }
                    },
                    y: {
                        beginAtZero: true,
                        border: { display: false },
                        grid:   { color: line },
                        ticks:  { color: ink, maxTicksLimit: 5, callback: brief }
                    }
                }
            }
        });
    }
})();
</script>
<?php endif ?>
