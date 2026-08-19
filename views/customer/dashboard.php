<?php
/**
 * Tenant's dashboard — their tenancy, their money, their shortlist.
 *
 * Two of these figures need the account to be linked to a customer record and
 * two do not, so an unlinked account still gets a working page and is told
 * plainly which half is missing and why. The router includes this file
 * directly rather than going through a controller, so the queries live here.
 */
$pageTitle    = 'My Dashboard';
$pageSubtitle = 'Your tenancy, what is due and the properties you have saved.';
$breadcrumbs  = [['label' => 'Overview']];

$db  = getDBConnection();
$uid = (int) $currentUser['id'];

$link = $db->prepare("SELECT id FROM customers WHERE user_id = ? LIMIT 1");
$link->execute([$uid]);
$custId = (int) $link->fetchColumn();

$activeLease  = 0;
$owing        = ['count' => 0, 'amount' => 0.0];

if ($custId) {
    $stmt = $db->prepare("SELECT COUNT(*) FROM leases WHERE customer_id = ? AND status = 'active'");
    $stmt->execute([$custId]);
    $activeLease = (int) $stmt->fetchColumn();

    /* How many and how much, in one pass. A tenant asks both questions at
       once, and the count alone does not answer either of them. */
    $stmt = $db->prepare("
        SELECT COUNT(*) AS `count`, COALESCE(SUM(amount), 0) AS amount
        FROM payments WHERE customer_id = ? AND status IN ('pending','overdue')
    ");
    $stmt->execute([$custId]);
    $owing = $stmt->fetch() ?: $owing;
}

$saved = $db->prepare("SELECT COUNT(*) FROM favorites WHERE user_id = ?");
$saved->execute([$uid]);
$savedProps = (int) $saved->fetchColumn();

$unreadNotifs = getUnreadNotificationCount();
$owedCount    = (int) $owing['count'];
$owedAmount   = (float) $owing['amount'];
?>
<div class="stats">
    <div class="stat-card">
        <div class="stat-card__icon stat-card__icon--success"><i class="bi bi-key" aria-hidden="true"></i></div>
        <div class="stat-card__body">
            <div class="stat-card__label">Tenancy</div>
            <div class="stat-card__value"><?= $activeLease > 0 ? 'Active' : 'None' ?></div>
            <?php if ($activeLease > 1): ?>
                <div class="stat-card__trend"><?= number_format($activeLease) ?> leases in your name</div>
            <?php endif ?>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-card__icon stat-card__icon--<?= $owedCount > 0 ? 'danger' : 'info' ?>">
            <i class="bi bi-<?= $owedCount > 0 ? 'exclamation-circle' : 'check2-circle' ?>" aria-hidden="true"></i>
        </div>
        <div class="stat-card__body">
            <div class="stat-card__label">Payments outstanding</div>
            <div class="stat-card__value"><?= number_format($owedCount) ?></div>
            <?php if ($owedCount > 0): ?>
                <div class="stat-card__trend stat-card__trend--down"><?= formatCurrency($owedAmount) ?> due</div>
            <?php endif ?>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-card__icon stat-card__icon--primary"><i class="bi bi-heart" aria-hidden="true"></i></div>
        <div class="stat-card__body">
            <div class="stat-card__label">Saved properties</div>
            <div class="stat-card__value"><?= number_format($savedProps) ?></div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-card__icon stat-card__icon--purple"><i class="bi bi-bell" aria-hidden="true"></i></div>
        <div class="stat-card__body">
            <div class="stat-card__label">Unread notifications</div>
            <div class="stat-card__value"><?= number_format((int) $unreadNotifs) ?></div>
        </div>
    </div>
</div>

<?php if ($owedCount > 0): ?>
    <div class="alert alert--warning">
        <i class="bi bi-exclamation-circle-fill" aria-hidden="true"></i>
        <div>
            <?= formatCurrency($owedAmount) ?> is outstanding across
            <?= number_format($owedCount) ?> payment<?= $owedCount === 1 ? '' : 's' ?>.
            <a href="<?= APP_URL ?>/index.php?page=my-payments">See what is due</a>.
        </div>
    </div>
<?php elseif (!$custId): ?>
    <?php /* Favourites and notifications work off the login; a tenancy and its
             payments hang off a customer record, which only the office can
             attach. Saying which half is missing saves a support call. */ ?>
    <div class="alert alert--info">
        <i class="bi bi-info-circle" aria-hidden="true"></i>
        <div>
            Your login is not connected to a customer record yet, so no tenancy or payments
            are shown. The managing office can connect it — your saved properties and
            notifications work either way.
        </div>
    </div>
<?php endif ?>

<div class="card mt-2">
    <div class="card__header"><h2 class="card__title">Common tasks</h2></div>
    <div class="card__body">
        <?php /* Each tile is gated on the permission behind it, so a tenant whose
                 role loses a capability stops being offered the shortcut to it. */ ?>
        <div class="quick-actions">
            <?php foreach ([
                ['my-lease.view',      'my-lease',                  'bi-file-earmark-text',  'success', 'My tenancy',      'Terms and schedule'],
                ['my-payments.view',   'my-payments',               'bi-credit-card',        'info',    'My payments',     'History and receipts'],
                ['maintenance.create', 'maintenance&action=create', 'bi-wrench-adjustable',  'warning', 'Report a problem', 'Something needs fixing'],
                ['favorites.view',     'favorites',                 'bi-heart',              'primary', 'Saved properties', 'Your shortlist'],
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
