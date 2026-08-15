<?php
$pageTitle = 'Owner Dashboard';
$breadcrumbs = [['label' => 'Overview']];
$db = getDBConnection();
$uid = $currentUser['id'];
$ownerStmt = $db->prepare("SELECT id FROM owners WHERE user_id = ? LIMIT 1"); $ownerStmt->execute([$uid]); $ownerId = $ownerStmt->fetchColumn();
$ownedProps = 0; $activeLeases = 0; $pendingMaint = 0;
if ($ownerId) {
    $ownedProps = $db->prepare("SELECT COUNT(*) FROM properties WHERE owner_id = ?"); $ownedProps->execute([$ownerId]); $ownedProps = $ownedProps->fetchColumn();
    $activeLeases = $db->prepare("SELECT COUNT(*) FROM leases WHERE owner_id = ? AND status = 'active'"); $activeLeases->execute([$ownerId]); $activeLeases = $activeLeases->fetchColumn();
    $pendingMaint = $db->prepare("SELECT COUNT(*) FROM maintenance_requests mr JOIN properties p ON mr.property_id = p.id WHERE p.owner_id = ? AND mr.status NOT IN ('completed','rejected','cancelled')"); $pendingMaint->execute([$ownerId]); $pendingMaint = $pendingMaint->fetchColumn();
}
?>
<div class="stats">
    <div class="stat-card"><div class="stat-card__icon stat-card__icon--primary"><i class="bi bi-buildings"></i></div><div><div class="stat-card__value"><?= $ownedProps ?></div><div class="stat-card__label">Owned Properties</div></div></div>
    <div class="stat-card"><div class="stat-card__icon stat-card__icon--success"><i class="bi bi-file-text"></i></div><div><div class="stat-card__value"><?= $activeLeases ?></div><div class="stat-card__label">Active Leases</div></div></div>
    <div class="stat-card"><div class="stat-card__icon stat-card__icon--warning"><i class="bi bi-wrench"></i></div><div><div class="stat-card__value"><?= $pendingMaint ?></div><div class="stat-card__label">Pending Maintenance</div></div></div>
</div>
