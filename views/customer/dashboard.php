<?php
$pageTitle = 'My Dashboard';
$breadcrumbs = [['label' => 'Overview']];
$db = getDBConnection();
$uid = $currentUser['id'];
// Find customer record linked to user
$custStmt = $db->prepare("SELECT id FROM customers WHERE user_id = ? LIMIT 1"); $custStmt->execute([$uid]); $custId = $custStmt->fetchColumn();
$activeLease = 0; $savedProps = 0; $pendingPayments = 0;
if ($custId) {
    $activeLease = $db->prepare("SELECT COUNT(*) FROM leases WHERE customer_id = ? AND status = 'active'"); $activeLease->execute([$custId]); $activeLease = $activeLease->fetchColumn();
    $pendingPayments = $db->prepare("SELECT COUNT(*) FROM payments WHERE customer_id = ? AND status IN ('pending','overdue')"); $pendingPayments->execute([$custId]); $pendingPayments = $pendingPayments->fetchColumn();
}
$savedProps = $db->prepare("SELECT COUNT(*) FROM favorites WHERE user_id = ?"); $savedProps->execute([$uid]); $savedProps = $savedProps->fetchColumn();
$unreadNotifs = getUnreadNotificationCount();
?>
<div class="stats">
    <div class="stat-card"><div class="stat-card__icon stat-card__icon--success"><i class="bi bi-key"></i></div><div><div class="stat-card__value"><?= $activeLease ?></div><div class="stat-card__label">Active Lease</div></div></div>
    <div class="stat-card"><div class="stat-card__icon stat-card__icon--danger"><i class="bi bi-exclamation-circle"></i></div><div><div class="stat-card__value"><?= $pendingPayments ?></div><div class="stat-card__label">Pending Payments</div></div></div>
    <div class="stat-card"><div class="stat-card__icon stat-card__icon--primary"><i class="bi bi-heart"></i></div><div><div class="stat-card__value"><?= $savedProps ?></div><div class="stat-card__label">Saved Properties</div></div></div>
    <div class="stat-card"><div class="stat-card__icon stat-card__icon--info"><i class="bi bi-bell"></i></div><div><div class="stat-card__value"><?= $unreadNotifs ?></div><div class="stat-card__label">Notifications</div></div></div>
</div>
