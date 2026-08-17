<?php
/**
 * Agent's dashboard — the agent's own book of business.
 *
 * Every figure is scoped to this agent: properties they hold, tenancies on
 * those properties, enquiries assigned to them, commission owed to them. The
 * router includes this file directly rather than going through a controller,
 * so the queries live here.
 */
$pageTitle    = 'My Dashboard';
$pageSubtitle = 'Your properties, your tenancies and what you are owed.';
$breadcrumbs  = [['label' => 'Overview']];

$db  = getDBConnection();
$uid = (int) $currentUser['id'];

/* Properties and the tenancies on them, in one pass rather than two. */
$book = $db->prepare("
    SELECT COUNT(DISTINCT p.id)                                              AS properties,
           COUNT(DISTINCT CASE WHEN l.status = 'active' THEN l.id END)       AS active_leases
    FROM properties p
    LEFT JOIN leases l ON l.property_id = p.id
    WHERE p.agent_id = ?
");
$book->execute([$uid]);
$b = $book->fetch() ?: ['properties' => 0, 'active_leases' => 0];

$inq = $db->prepare("SELECT COUNT(*) FROM inquiries WHERE assigned_to = ? AND status IN ('open','pending')");
$inq->execute([$uid]);
$pendingInquiries = (int) $inq->fetchColumn();

$comm = $db->prepare("SELECT COALESCE(SUM(amount),0) FROM commissions WHERE agent_id = ? AND status = 'pending'");
$comm->execute([$uid]);
$pendingComm = (float) $comm->fetchColumn();
?>
<div class="stats">
    <div class="stat-card">
        <div class="stat-card__icon stat-card__icon--primary"><i class="bi bi-buildings" aria-hidden="true"></i></div>
        <div class="stat-card__body">
            <div class="stat-card__value"><?= number_format((int) $b['properties']) ?></div>
            <div class="stat-card__label">Properties you handle</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-card__icon stat-card__icon--success"><i class="bi bi-file-text" aria-hidden="true"></i></div>
        <div class="stat-card__body">
            <div class="stat-card__value"><?= number_format((int) $b['active_leases']) ?></div>
            <div class="stat-card__label">Tenancies running</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-card__icon stat-card__icon--warning"><i class="bi bi-chat-dots" aria-hidden="true"></i></div>
        <div class="stat-card__body">
            <div class="stat-card__value"><?= number_format($pendingInquiries) ?></div>
            <div class="stat-card__label">Enquiries waiting on you</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-card__icon stat-card__icon--purple"><i class="bi bi-wallet2" aria-hidden="true"></i></div>
        <div class="stat-card__body">
            <div class="stat-card__value"><?= formatCurrency($pendingComm) ?></div>
            <div class="stat-card__label">Commission not yet paid</div>
        </div>
    </div>
</div>

<?php if ($pendingInquiries > 0): ?>
    <div class="alert alert--warning">
        <i class="bi bi-chat-left-dots-fill" aria-hidden="true"></i>
        <div>
            <?= number_format($pendingInquiries) ?>
            enquir<?= $pendingInquiries === 1 ? 'y is' : 'ies are' ?> assigned to you and unanswered.
            <a href="<?= APP_URL ?>/index.php?page=inquiries&amp;status=open">Open the inbox</a>.
        </div>
    </div>
<?php endif ?>

<div class="card mt-2">
    <div class="card__header"><h3 class="card__title">Common tasks</h3></div>
    <div class="card__body">
        <?php /* Each tile is gated on the permission behind it, so an agent whose
                 role loses a capability stops being offered the shortcut to it. */ ?>
        <div class="quick-actions">
            <?php foreach ([
                ['properties.create', 'properties&action=create', 'bi-house-add',         'primary', 'Add a property', 'List a new one'],
                ['customers.create',  'customers&action=create',  'bi-person-plus',       'info',    'Add a customer', 'Tenant or buyer'],
                ['leases.create',     'leases&action=create',     'bi-file-earmark-plus', 'success', 'New lease',      'Start a tenancy'],
                ['inquiries.view',    'inquiries',                'bi-chat-left-text',    'warning', 'Enquiries',      'Answer what came in'],
            ] as [$perm, $target, $icon, $tone, $label, $hint]): ?>
                <?php if (!can($perm)) continue; ?>
                <a class="quick-action" href="<?= APP_URL ?>/index.php?page=<?= $target ?>">
                    <span class="quick-action__icon quick-action__icon--<?= $tone ?>">
                        <i class="bi <?= $icon ?>" aria-hidden="true"></i>
                    </span>
                    <span class="quick-action__text">
                        <span class="quick-action__label"><?= $label ?></span>
                        <span class="quick-action__hint"><?= $hint ?></span>
                    </span>
                    <i class="bi bi-arrow-right quick-action__go" aria-hidden="true"></i>
                </a>
            <?php endforeach ?>
        </div>
    </div>
</div>
