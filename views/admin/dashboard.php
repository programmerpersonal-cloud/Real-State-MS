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
<div class="alert alert--warning" style="margin-bottom:18px">
    <i class="bi bi-calendar-x"></i>
    <div style="flex:1">
        <strong>
            <?php if ($docCounts['expired'] > 0): ?>
                <?= $docCounts['expired'] ?> document<?= $docCounts['expired'] === 1 ? '' : 's' ?> expired<?= $docCounts['expiring'] > 0 ? ', ' : '' ?>
            <?php endif ?>
            <?php if ($docCounts['expiring'] > 0): ?>
                <?= $docCounts['expiring'] ?> expiring within <?= documentExpiryWarningDays() ?> days
            <?php endif ?>
        </strong>
        <ul style="margin:8px 0 0;padding-left:18px;font-size:.82rem">
            <?php foreach (array_slice($expiringDocs, 0, 5) as $d): $st = documentStatus($d); ?>
                <li style="margin-bottom:3px">
                    <a href="<?= APP_URL ?>/index.php?page=documents&amp;action=show&amp;id=<?= (int) $d['id'] ?>">
                        <?= sanitize($d['title']) ?>
                    </a>
                    <?php if (!empty($d['property_title'])): ?>
                        <span class="text-muted">· <?= sanitize($d['property_title']) ?></span>
                    <?php endif ?>
                    — <span class="badge <?= $st['badge'] ?>" style="font-size:.6rem"><?= sanitize(documentExpiryNote($d)) ?></span>
                </li>
            <?php endforeach ?>
        </ul>
        <a href="<?= APP_URL ?>/index.php?page=documents&amp;state=expired" style="font-size:.82rem">
            Review all expiring documents <i class="bi bi-arrow-right"></i>
        </a>
    </div>
</div>
<?php endif ?>

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
    <?php /* Sized to its tiles rather than stretched to match the activity
             feed beside it, which is as long as the audit log makes it. */ ?>
    <div class="card" style="align-self:start">
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
                                <i class="bi <?= $qa['icon'] ?>"></i>
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
