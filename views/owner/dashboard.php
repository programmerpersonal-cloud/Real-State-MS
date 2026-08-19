<?php
/**
 * Owner's dashboard — the state of their own portfolio.
 *
 * Every figure is scoped to the owner record linked to this account. Where no
 * owner record is linked there is nothing to scope to, so the page says so
 * rather than showing three honest-looking zeros — an unlinked account and an
 * empty portfolio are different problems with different fixes. The router
 * includes this file directly rather than going through a controller, so the
 * queries live here.
 */
$pageTitle    = 'My Portfolio';
$pageSubtitle = 'Your properties, who is in them and what needs attention.';
$breadcrumbs  = [['label' => 'Overview']];

$db  = getDBConnection();
$uid = (int) $currentUser['id'];

$link = $db->prepare("SELECT id FROM owners WHERE user_id = ? LIMIT 1");
$link->execute([$uid]);
$ownerId = (int) $link->fetchColumn();

$owned        = ['total' => 0, 'let' => 0];
$activeLeases = 0;
$pendingMaint = 0;

if ($ownerId) {
    /* Held and let in one pass. The second figure is what makes the first mean
       anything, and as a conditional aggregate it costs no extra round trip. */
    $stmt = $db->prepare("
        SELECT COUNT(*) AS total, COALESCE(SUM(status = 'rented'), 0) AS `let`
        FROM properties WHERE owner_id = ?
    ");
    $stmt->execute([$ownerId]);
    $owned = $stmt->fetch() ?: $owned;

    $stmt = $db->prepare("SELECT COUNT(*) FROM leases WHERE owner_id = ? AND status = 'active'");
    $stmt->execute([$ownerId]);
    $activeLeases = (int) $stmt->fetchColumn();

    $stmt = $db->prepare("
        SELECT COUNT(*)
        FROM maintenance_requests mr
        JOIN properties p ON mr.property_id = p.id
        WHERE p.owner_id = ? AND mr.status NOT IN ('completed','rejected','cancelled')
    ");
    $stmt->execute([$ownerId]);
    $pendingMaint = (int) $stmt->fetchColumn();
}

$total = (int) $owned['total'];
$let   = (int) $owned['let'];
?>
<?php if (!$ownerId): ?>
    <div class="table-card">
        <?= uiEmptyState([
            'icon'  => 'bi-person-badge',
            'title' => 'Your account is not linked to an owner profile yet',
            'desc'  => 'Until the managing office connects this login to your owner record there is '
                     . 'nothing to report on. They can do it in a moment — everything below fills in '
                     . 'by itself once they have.',
            'actions' => [[
                'label' => 'Your profile', 'icon' => 'bi-person', 'class' => 'btn--outline',
                'url'   => APP_URL . '/index.php?page=profile',
            ]],
        ]) ?>
    </div>
<?php else: ?>
    <div class="stats">
        <div class="stat-card">
            <div class="stat-card__icon stat-card__icon--primary"><i class="bi bi-buildings" aria-hidden="true"></i></div>
            <div class="stat-card__body">
                <div class="stat-card__label">Properties you own</div>
                <div class="stat-card__value"><?= number_format($total) ?></div>
                <?php if ($total > 0): ?>
                    <div class="stat-card__trend"><?= number_format($let) ?> currently let</div>
                <?php endif ?>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-card__icon stat-card__icon--success"><i class="bi bi-file-text" aria-hidden="true"></i></div>
            <div class="stat-card__body">
                <div class="stat-card__label">Tenancies running</div>
                <div class="stat-card__value"><?= number_format($activeLeases) ?></div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-card__icon stat-card__icon--warning"><i class="bi bi-wrench-adjustable" aria-hidden="true"></i></div>
            <div class="stat-card__body">
                <div class="stat-card__label">Repairs outstanding</div>
                <div class="stat-card__value"><?= number_format($pendingMaint) ?></div>
            </div>
        </div>
    </div>

    <?php if ($pendingMaint > 0): ?>
        <div class="alert alert--warning">
            <i class="bi bi-wrench-adjustable" aria-hidden="true"></i>
            <div>
                <?= number_format($pendingMaint) ?>
                repair<?= $pendingMaint === 1 ? ' is' : 's are' ?> open on your properties.
                <a href="<?= APP_URL ?>/index.php?page=maintenance">See what is outstanding</a>.
            </div>
        </div>
    <?php elseif ($total === 0): ?>
        <div class="alert alert--info">
            <i class="bi bi-info-circle" aria-hidden="true"></i>
            <div>
                No properties are registered under your name yet. Anything the office adds
                appears here and under <a href="<?= APP_URL ?>/index.php?page=my-properties">My properties</a>.
            </div>
        </div>
    <?php endif ?>

    <div class="card mt-2">
        <div class="card__header"><h2 class="card__title">Common tasks</h2></div>
        <div class="card__body">
            <?php /* Each tile is gated on the permission behind it, so an owner whose
                     role loses a capability stops being offered the shortcut to it. */ ?>
            <div class="quick-actions">
                <?php foreach ([
                    ['my-properties.view',  'my-properties', 'bi-buildings',          'primary', 'My properties',    'Everything you own'],
                    ['my-income.view',      'my-income',     'bi-graph-up',           'success', 'Income',           'What has reached you'],
                    ['maintenance.create',  'maintenance&action=create', 'bi-wrench-adjustable', 'warning', 'Report a repair', 'Tell the office'],
                    ['inquiries.view',      'inquiries',     'bi-chat-left-text',     'info',    'Interest received', 'Enquiries on your properties'],
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
<?php endif ?>
