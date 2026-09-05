<?php
/**
 * Admin Dashboard
 */
$pageTitle    = 'Dashboard';
$pageSubtitle = 'Overview of properties, leases and operations across your portfolio.';
$breadcrumbs  = [['label' => 'Overview']];

/* The figures, the revenue series and the activity feed all come from
   includes/dashboard.php, which the Export button's controller reads too. Two
   copies of these queries would be two answers to "how many properties" the
   day one of them was edited. */
require_once BASE_PATH . '/includes/dashboard.php';

/* Six, not eight: the page of the trail this panel shows is the number of
   rows the card can seat beside the tiles and the ring without scrolling
   inside itself. The whole log is behind "View All", and the export reads
   its own call — so this is the panel's page size, not the system's. */
$recentActivity = dashboardRecentActivity(6);

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

// Export writes what is on this page — the six figures with their notes, the
// revenue months, the status split and the activity feed — as one CSV, from
// the same functions that drew them. It is a download, so it carries the
// download glyph rather than the upload one it wore while it pointed at "#".
$actionButtons = [[
    'label' => 'Export', 'icon' => 'bi-download', 'class' => 'btn--outline',
    'url'   => APP_URL . '/index.php?page=dashboard&amp;action=export',
    'can'   => 'dashboard.export',
]];

// The header also keeps the primary action of the moment. It opens the same
// popup as the tile below, and still leads to the full form when scripting is
// off.
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
                    <?= uiStatus($st['key'], documentExpiryNote($d)) ?>
                </li>
            <?php endforeach ?>
        </ul>
    </div>

    <?php /* Outside .notice__body, so it sits at the end of the banner row
             rather than under it. Same link, same words, one line less —
             and on a dashboard every line this banner takes is a line
             pushed off the bottom of the screen. It wraps under the body
             on narrow widths; see .notice in components.css. */ ?>
    <a href="<?= APP_URL ?>/index.php?page=documents&amp;state=expired" class="notice__link">
        Review all <i class="bi bi-arrow-right" aria-hidden="true"></i>
    </a>
</div>
<?php endif ?>

<?php
/* The KPI row, as data — same six figures, same labels, same order. Keeping
   them in a list rather than six copies of the same markup means the glyph,
   the tone and the number can never drift apart, and the tone drives both
   the icon tile and the card's accent from one place.

   Glyphs match the module each figure belongs to, so the row and the rail
   name the same things the same way: Maintenance is the sidebar's wrench,
   Payments is money, Users is the shield.

   The figures, their one-line notes and their tones are built in
   dashboardStatCards(); this file only draws them. That is what lets the
   Export button in the header write the same row of numbers this row shows,
   without a second set of queries to keep in step. */
$statCards = dashboardStatCards();
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
                <?php if (!empty($sc['note'])): ?>
                    <div class="stat-card__trend<?= !empty($sc['noteTone']) ? ' stat-card__trend--' . $sc['noteTone'] : '' ?>">
                        <?php if (!empty($sc['noteIcon'])): ?>
                            <i class="bi <?= sanitize($sc['noteIcon']) ?>" aria-hidden="true"></i>
                        <?php endif ?>
                        <?= sanitize($sc['note']) ?>
                    </div>
                <?php endif ?>
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

/* Six whole calendar months, zero-filled — see dashboardRevenueSeries(),
   which the export reads for the same six rows. Asked for only when the
   reader may have it: the permission decides whether the query runs, not
   just whether the chart is drawn. */
$revenueSeries = $canSeeRevenue ? dashboardRevenueSeries() : [];
$revenueTotal  = array_sum(array_column($revenueSeries, 'total'));

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
                <h2 class="card__title">Revenue</h2>
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
                <h2 class="card__title">Properties by Status</h2>
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

<?php
/* ── The board ───────────────────────────────────────────────────
   Below the charts the page stops summarising and starts listing, and
   it does that in two rows of panels rather than in three stacks:

     row one   what happened (the audit trail) · what can be done
               about it (the quick-add tiles) · where the money came
               from (the mix ring) — the day, read left to right
     row two   what was signed (the deal book), across the width of
               the two panels above it because it seats six columns,
               and the sales just closed, under the ring they add up
               to

   The markup below is in reading order — feed, actions, mix, deals,
   sales — and the stylesheet places each panel in its cell, so the
   picture reads across while the document still reads down. Below
   1100px the placement is dropped entirely and the panels fall in that
   same source order, feed first.

   Three queries are added here, one per new panel. Each is a single
   statement bounded by a LIMIT or by an enum, so the dashboard's cost
   is three round trips higher and still O(1) in the size of the
   portfolio — see includes/dashboard.php.

   Every panel is gated on the permission that opens the module behind
   it, exactly as the charts above are: the deal book needs the books,
   the money mix follows Reports like the revenue line, and the sales
   list needs Sales. A role without one gets a shorter board, not an
   empty panel. */
$canSeeDeals = can('sales.view') || can('leases.view');
$recentDeals = $canSeeDeals ? dashboardRecentDeals(5) : [];

/* The same window and the same filter as the revenue line above, so the
   figure in the middle of this ring is the figure that line totals to. */
$revenueMix      = $canSeeRevenue ? dashboardRevenueMix() : [];
$revenueMixTotal = array_sum(array_column($revenueMix, 'amount'));

$canSeeSales = can('sales.view');
$recentSales = $canSeeSales ? dashboardRecentSales(4) : [];

/* Built the same way the status ring is: running totals into one
   conic-gradient, so the last slice lands on 100% rather than a rounding
   error short of it, and the whole figure is complete before any script
   has run.

   The same pass places each slice's share on the band it belongs to. A
   conic-gradient starts at twelve o'clock and turns clockwise, so a slice
   spanning s%..e% has its middle at (s+e)/2 × 3.6 degrees, and sine and
   cosine put the label there. The offsets are handed to CSS as bare
   multipliers rather than pixels — the radius is written in the stylesheet
   in terms of the ring's own width and band, so a ring resized there moves
   its labels with it instead of leaving them behind.

   A slice thinner than this cannot hold a figure without it lying across
   its neighbours, so it is read from the legend instead — where every
   slice's share is written out anyway. */
const MIX_LABEL_MIN_PCT = 8.0;

$mixStops  = [];
$mixLabels = [];
$mixAt     = 0.0;
foreach ($revenueMix as $m) {
    $from   = $mixAt;
    $mixAt += $m['pct'];
    $mixStops[] = sprintf('var(%s) %.3f%% %.3f%%', $m['var'], $from, $mixAt);

    if ($m['pct'] < MIX_LABEL_MIN_PCT) {
        continue;
    }
    $angle       = deg2rad(($from + $mixAt) / 2 * 3.6);
    $mixLabels[] = [
        'pct' => round($m['pct']),
        'sin' => sprintf('%.4f', sin($angle)),
        'cos' => sprintf('%.4f', cos($angle)),
    ];
}
$mixGradient = $mixStops ? 'conic-gradient(' . implode(',', $mixStops) . ')' : '';

/* The marker beside an audit line, toned by what the line says happened.
   Read off the verb the trail already recorded rather than from a status
   column the audit table does not have — a made-up state would be worse
   than no colour at all. The tone is decoration on a sentence that spells
   itself out, so nothing is lost when it cannot be told apart. */
$activityTone = static function (string $action): string {
    static $verbs = [
        'success' => ['created', 'added', 'approved', 'completed', 'paid', 'restored', 'signed', 'confirmed', 'renewed'],
        'danger'  => ['deleted', 'removed', 'rejected', 'terminated', 'cancelled', 'failed',
                      'blacklisted', 'denied', 'refused', 'locked'],
        'warning' => ['archived', 'expired', 'suspended', 'overdue', 'reserved'],
        'info'    => ['updated', 'edited', 'assigned', 'changed', 'replied', 'sent', 'exported', 'uploaded'],
    ];

    $a = strtolower($action);
    foreach ($verbs as $tone => $list) {
        foreach ($list as $verb) {
            if (str_contains($a, $verb)) {
                return $tone;
            }
        }
    }
    return 'primary';
};

/* How the recorded action ended, as the pill at the end of the row.
   The audit table has no status column and one is not invented here: an
   entry is written after the fact, so an action that names a refusal
   ("access denied", "login failed") failed, one that names a request
   still waiting ("requested", "submitted for review") is pending, and
   everything else the trail recorded is a change that went through.
   Three outcomes, each read off the verb already in the row, so the
   pill can never disagree with the sentence beside it.

   Returns [tone, label] — the tone dresses the pill, the label is the
   word, and the word is always written out rather than implied by the
   colour. */
$activityOutcome = static function (string $action): array {
    static $ends = [
        ['danger',  'Failed',    ['denied', 'refused', 'failed', 'blocked', 'rejected', 'locked', 'invalid']],
        ['warning', 'Pending',   ['requested', 'submitted', 'pending', 'awaiting', 'review']],
    ];

    $a = strtolower($action);
    foreach ($ends as [$tone, $label, $verbs]) {
        foreach ($verbs as $verb) {
            if (str_contains($a, $verb)) {
                return [$tone, $label];
            }
        }
    }
    return ['success', 'Completed'];
};
?>

<div class="dash-board">

    <!-- ── Row one: what happened, what to do, where the money came from ── -->
    <!-- Recent Activities -->
    <?php /* The audit trail as a register rather than a timeline: the
             date belongs in its own column so a week of entries can be
             read down it, and the entity keeps the tag it always wore.
             Same eight rows, same query, same "View all". */ ?>
    <div class="card dash-panel dash-panel--feed">
        <div class="card__header">
            <div>
                <h2 class="card__title">Recent Activities</h2>
            </div>
            <a href="<?= APP_URL ?>/index.php?page=audit-logs" class="btn btn--outline btn--sm">View All</a>
        </div>
        <div class="card__body card__body--flush">
            <?php if (empty($recentActivity)): ?>
                <div class="empty-state">
                    <div class="empty-state__icon"><i class="bi bi-clock-history"></i></div>
                    <div class="empty-state__title">No activity yet</div>
                    <div class="empty-state__desc">System activity will appear here.</div>
                </div>
            <?php else: ?>
                <?php /* data-no-stack: below 620px of table width a
                         register turns itself into one card per row, which
                         is right for a register and wrong for a panel. A
                         dashboard summary may shed columns — the whole log
                         is behind "View All" — so it sheds them here and
                         stays a table at every width. */ ?>
                <div class="table-card table-card--plain">
                    <div class="table-wrap">
                        <table class="table" data-no-stack>
                            <thead>
                                <tr>
                                    <th class="cell-date">Date</th>
                                    <th>Activity</th>
                                    <th class="cell-tight">Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recentActivity as $act): ?>
                                    <tr>
                                        <?php
                                        /* One line per entry, so eight of
                                           them read as a list rather than
                                           as eight paragraphs of differing
                                           height. The clock and the whole
                                           sentence are on the cell, where a
                                           pointer finds them, and the
                                           complete trail is behind View
                                           All. */
                                        $who  = (string) ($act['full_name'] ?? 'System');
                                        $what = str_replace('_', ' ', (string) $act['action']);
                                        /* The record the entry was written
                                           against, kept on the row's own
                                           tooltip now that the column it
                                           had went to the outcome — the
                                           whole trail, tag and all, is
                                           still behind View All. */
                                        $said = $who . ' ' . $what
                                              . ($act['entity_type'] ? ' — ' . $act['entity_type'] : '');
                                        [$endTone, $endLabel] = $activityOutcome((string) $act['action']);
                                        ?>
                                        <td class="cell-date" title="<?= sanitize(formatDateTime($act['created_at'])) ?>">
                                            <?= formatDate($act['created_at']) ?>
                                        </td>
                                        <td>
                                            <span class="feedline" title="<?= sanitize($said) ?>">
                                                <span class="feedline__dot feedline__dot--<?= $activityTone((string) $act['action']) ?>" aria-hidden="true"></span>
                                                <span class="feedline__text">
                                                    <strong><?= sanitize($who) ?></strong>
                                                    <?= sanitize($what) ?>
                                                </span>
                                            </span>
                                        </td>
                                        <td class="cell-tight">
                                            <span class="status status--<?= $endTone ?>">
                                                <span class="status__dot" aria-hidden="true"></span><?= $endLabel ?>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif ?>
        </div>
    </div>

    <!-- Quick Actions -->
    <?php /* Sized to its tiles rather than stretched to match the register
             beside it, which is as long as the day was. The board aligns
             every panel to the start of its row for this reason. */ ?>
    <div class="card dash-panel dash-panel--actions">
        <div class="card__header">
            <div>
                <h2 class="card__title">Quick Actions</h2>
            </div>
        </div>
        <div class="card__body">
            <?php if (empty($quickActions)): ?>
                <div class="empty-state">
                    <div class="empty-state__icon"><i class="bi bi-lightning-charge"></i></div>
                    <div class="empty-state__title">Nothing to add from here</div>
                    <div class="empty-state__desc">Your role does not create records directly.</div>
                </div>
            <?php else: ?>
                <?php /* The same tiles, standing up: the glyph over the
                         label rather than beside it, so six of them fit
                         the middle track without a caption breaking. The
                         caption is not dropped — it stays in the
                         accessible name, where it was doing its real
                         work anyway. */ ?>
                <div class="quick-actions quick-actions--tiles">
                    <?php foreach ($quickActions as $qa): ?>
                        <?php /* A link, not a button: the popup is the fast path, and the
                                 full form behind it stays reachable if scripting is off. */ ?>
                        <a class="quick-action" href="<?= $quickActionUrl($qa) ?>" data-modal-open="<?= $qa['modal'] ?>">
                            <span class="quick-action__icon quick-action__icon--<?= $qa['tone'] ?>">
                                <i class="bi <?= $qa['icon'] ?>" aria-hidden="true"></i>
                            </span>
                            <span class="quick-action__text">
                                <span class="quick-action__label"><?= sanitize($qa['label']) ?></span>
                                <span class="sr-only"> — <?= sanitize($qa['hint']) ?></span>
                            </span>
                        </a>
                    <?php endforeach ?>
                </div>
            <?php endif ?>
        </div>
    </div>

    <!-- Sales report -->
    <?php if ($canSeeRevenue): ?>
    <div class="card dash-panel dash-panel--mix">
        <div class="card__header">
            <div>
                <?php /* The one caption kept on the board. Every other
                         panel names a period nowhere because it needs
                         none; a total does, and "$4,700 received" over an
                         unstated window is not a figure. */ ?>
                <h2 class="card__title">Sales Report</h2>
                <div class="card__subtitle">Last 6 months</div>
            </div>
            <?= uiRowActions([
                ['label' => 'Open reports', 'icon' => 'bi-graph-up-arrow', 'can' => 'reports.view',
                 'url'   => APP_URL . '/index.php?page=reports'],
                ['label' => 'All payments', 'icon' => 'bi-cash-stack',     'can' => 'payments.view',
                 'url'   => APP_URL . '/index.php?page=payments'],
            ], 'Sales report links') ?>
        </div>
        <div class="card__body">
            <?php if (empty($revenueMix)): ?>
                <div class="empty-state">
                    <div class="empty-state__icon"><i class="bi bi-pie-chart"></i></div>
                    <div class="empty-state__title">Nothing received yet</div>
                    <div class="empty-state__desc">The split by payment type appears once money is taken.</div>
                </div>
            <?php else: ?>
                <div class="statbreak statbreak--split">
                    <?php /* The ring names itself and nothing else. Its
                             total and its shares are written out beside
                             it, so the figures on the band are decoration
                             on a panel that is already complete in text —
                             which is why they are hidden from the reader
                             who is being read to. */ ?>
                    <?php
                    /* The whole breakdown in one sentence. The figures on
                       the band are hidden from a screen reader and the
                       legend beside it is down to a swatch and a name, so
                       this is where a slice's share is actually stated for
                       anyone not looking at the picture — including the
                       thin slices the band was too narrow to label. */
                    $ringSays = 'Payments received over the last six months, '
                        . formatCurrency($revenueMixTotal) . ' in total.';
                    foreach ($revenueMix as $m) {
                        $ringSays .= ' ' . $m['label'] . ', ' . formatCurrency($m['amount'])
                                   . ', ' . round($m['pct']) . '%.';
                    }
                    ?>
                    <div class="statring statring--lg statring--money" style="background:<?= $mixGradient ?>"
                         role="img" aria-label="<?= sanitize($ringSays) ?>">
                        <?php foreach ($mixLabels as $lab): ?>
                            <span class="statring__slice" aria-hidden="true"
                                  style="--sin:<?= $lab['sin'] ?>;--cos:<?= $lab['cos'] ?>"><?= $lab['pct'] ?>%</span>
                        <?php endforeach ?>
                        <span class="statring__total" aria-hidden="true">
                            <span class="statring__figure"><?= formatCurrency($revenueMixTotal) ?></span>
                            <span class="statring__cap">received</span>
                        </span>
                    </div>
                    <?php /* Every slice named, valued and shared out in
                             text — including the thin ones the ring could
                             not label. Colour is never the only thing
                             saying which kind of money is which. */ ?>
                    <ul class="mixlegend">
                        <?php foreach ($revenueMix as $m): ?>
                            <li class="mixlegend__row">
                                <span class="mixlegend__swatch" style="background:var(<?= $m['var'] ?>)" aria-hidden="true"></span>
                                <span class="mixlegend__label"><?= sanitize($m['label']) ?></span>
                                <span class="mixlegend__value"><?= formatCurrency($m['amount']) ?></span>
                            </li>
                        <?php endforeach ?>
                    </ul>
                </div>
            <?php endif ?>
        </div>
    </div>
    <?php endif ?>

    <!-- ── Row two: the deal book, and the sales just closed ── -->
    <!-- Property Overview -->
    <?php /* The deal book: sales and tenancies in one register, because
             "what did we sign lately" is one question and answering it
             in two panels would leave the reader merging them by eye.
             Each row says which book it came from, and a rent carries
             the /mo that stops it being read as a sale price. */ ?>
    <?php if ($canSeeDeals): ?>
    <div class="card dash-panel dash-panel--deals">
        <div class="card__header">
            <div>
                <?php /* No caption: the Type column already says this
                         panel holds both books. */ ?>
                <h2 class="card__title">Property Overview</h2>
            </div>
            <?php /* One book per entry, because the panel merges two and
                     a single "View all" would have to pick one of them.
                     uiRowActions() drops any line the role may not follow
                     and renders nothing at all when none are left. */ ?>
            <?= uiRowActions([
                ['label' => 'All sales',      'icon' => 'bi-cash-coin',          'can' => 'sales.view',
                 'url'   => APP_URL . '/index.php?page=sales'],
                ['label' => 'All leases',     'icon' => 'bi-file-earmark-text',  'can' => 'leases.view',
                 'url'   => APP_URL . '/index.php?page=leases'],
                ['label' => 'All properties', 'icon' => 'bi-buildings',          'can' => 'properties.view',
                 'url'   => APP_URL . '/index.php?page=properties'],
            ], 'Property overview links') ?>
        </div>
        <div class="card__body card__body--flush">
            <?php if (empty($recentDeals)): ?>
                <div class="empty-state">
                    <div class="empty-state__icon"><i class="bi bi-journal-text"></i></div>
                    <div class="empty-state__title">No deals recorded</div>
                    <div class="empty-state__desc">Sales and tenancies will be listed here as they are signed.</div>
                </div>
            <?php else: ?>
                <div class="table-card table-card--plain">
                    <div class="table-wrap">
                        <table class="table" data-no-stack>
                            <thead>
                                <tr>
                                    <th>Customer</th>
                                    <th class="col-mid">Property</th>
                                    <?php /* Which column yields first,
                                             and in what order. Type goes
                                             before Date because a rent
                                             still says so — the figure
                                             carries "/mo" — while a date
                                             dropped is a date gone. */ ?>
                                    <th class="col-lo">Type</th>
                                    <th class="cell-num">Amount</th>
                                    <th class="cell-date col-mid">Date</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recentDeals as $deal): ?>
                                    <tr>
                                        <?php /* The name alone, not the
                                                 avatar cell the registers
                                                 use: a portrait is 50px of
                                                 a track that has six
                                                 columns to seat, and it
                                                 buys nothing here — the
                                                 customer's own page is one
                                                 click away and shows it. */ ?>
                                        <td class="cell-strong cell-clip">
                                            <?php if (can('customers.show')): ?>
                                                <a href="<?= APP_URL ?>/index.php?page=customers&amp;action=show&amp;id=<?= (int) $deal['customer_id'] ?>">
                                                    <?= sanitize($deal['customer_name']) ?>
                                                </a>
                                            <?php else: ?>
                                                <?= sanitize($deal['customer_name']) ?>
                                            <?php endif ?>
                                        </td>
                                        <td class="cell-clip col-mid">
                                            <?php if (can('properties.show')): ?>
                                                <a href="<?= APP_URL ?>/index.php?page=properties&amp;action=show&amp;id=<?= (int) $deal['property_id'] ?>">
                                                    <?= sanitize($deal['property_title']) ?>
                                                </a>
                                            <?php else: ?>
                                                <?= sanitize($deal['property_title']) ?>
                                            <?php endif ?>
                                        </td>
                                        <td class="col-lo">
                                            <span class="dealkind dealkind--<?= $deal['kind'] === 'sale' ? 'sale' : 'rental' ?>">
                                                <?= sanitize($deal['type_label']) ?>
                                            </span>
                                        </td>
                                        <td class="cell-num">
                                            <strong><?= formatCurrency($deal['amount']) ?></strong><?php
                                                if ($deal['suffix'] !== ''): ?><span class="dealkind__suffix"><?= $deal['suffix'] ?></span><?php endif ?>
                                        </td>
                                        <td class="cell-date col-mid"><?= formatDate($deal['deal_date']) ?></td>
                                        <td><?= uiStatus((string) $deal['status']) ?></td>
                                    </tr>
                                <?php endforeach ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif ?>
        </div>
    </div>
    <?php endif ?>

    <!-- Recent Sale -->
    <?php if ($canSeeSales): ?>
    <div class="card dash-panel dash-panel--sales">
        <div class="card__header">
            <div>
                <h2 class="card__title">Recent Sale</h2>
            </div>
            <?= uiRowActions([
                ['label' => 'All sales',      'icon' => 'bi-cash-coin', 'can' => 'sales.view',
                 'url'   => APP_URL . '/index.php?page=sales'],
                ['label' => 'All properties', 'icon' => 'bi-buildings', 'can' => 'properties.view',
                 'url'   => APP_URL . '/index.php?page=properties'],
            ], 'Recent sales links') ?>
        </div>
        <div class="card__body card__body--flush">
            <?php if (empty($recentSales)): ?>
                <div class="empty-state">
                    <div class="empty-state__icon"><i class="bi bi-house-check"></i></div>
                    <div class="empty-state__title">No sales yet</div>
                    <div class="empty-state__desc">Completed sales will be listed here.</div>
                </div>
            <?php else: ?>
                <ul class="salelist">
                    <?php foreach ($recentSales as $s): ?>
                        <?php
                        /* Uploaded cover first, seeded stock photograph
                           otherwise — the panel never renders a broken
                           frame for a listing nobody photographed. */
                        $thumb = uploadUrl($s['cover_path'] ?? null)
                            ?? stockPropertyImage((int) $s['property_id']);
                        $saleUrl = can('properties.show')
                            ? APP_URL . '/index.php?page=properties&action=show&id=' . (int) $s['property_id']
                            : APP_URL . '/index.php?page=sales';
                        ?>
                        <li class="salelist__item">
                            <a class="salelist__link" href="<?= sanitize($saleUrl) ?>">
                                <img class="salelist__thumb" src="<?= sanitize($thumb) ?>" alt="" loading="lazy" decoding="async">
                                <span class="salelist__body">
                                    <span class="salelist__title"><?= sanitize($s['title']) ?></span>
                                    <span class="salelist__meta">
                                        <i class="bi bi-geo-alt" aria-hidden="true"></i>
                                        <?php /* The place in its own element: the row is a flex line, and an
                                                 ellipsis set on the line never reaches the text inside it — a
                                                 long address was being cut off mid-word instead. */ ?>
                                        <span class="salelist__place"><?= sanitize($s['location'] ?: categoryLabel((string) $s['category'])) ?></span>
                                    </span>
                                    <span class="salelist__price"><?= formatCurrency((float) $s['sale_amount']) ?></span>
                                </span>
                            </a>
                            <span class="salelist__status"><?= uiStatus((string) $s['status']) ?></span>
                        </li>
                    <?php endforeach ?>
                </ul>
            <?php endif ?>
        </div>
    </div>
    <?php endif ?>
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
