<?php
$pageTitle = 'Agent Dashboard';
$breadcrumbs = [['label' => 'Overview']];
$db = getDBConnection();
$uid = $currentUser['id'];
$assignedProps = $db->prepare("SELECT COUNT(*) FROM properties WHERE agent_id = ?"); $assignedProps->execute([$uid]); $assignedProps = $assignedProps->fetchColumn();
$activeLeases = $db->prepare("SELECT COUNT(*) FROM leases l JOIN properties p ON l.property_id = p.id WHERE p.agent_id = ? AND l.status = 'active'"); $activeLeases->execute([$uid]); $activeLeases = $activeLeases->fetchColumn();
$pendingInquiries = $db->prepare("SELECT COUNT(*) FROM inquiries WHERE assigned_to = ? AND status IN ('open','pending')"); $pendingInquiries->execute([$uid]); $pendingInquiries = $pendingInquiries->fetchColumn();
$pendingComm = $db->prepare("SELECT COALESCE(SUM(amount),0) FROM commissions WHERE agent_id = ? AND status = 'pending'"); $pendingComm->execute([$uid]); $pendingComm = $pendingComm->fetchColumn();
?>
<div class="stats">
    <div class="stat-card"><div class="stat-card__icon stat-card__icon--primary"><i class="bi bi-buildings"></i></div><div><div class="stat-card__value"><?= $assignedProps ?></div><div class="stat-card__label">Assigned Properties</div></div></div>
    <div class="stat-card"><div class="stat-card__icon stat-card__icon--success"><i class="bi bi-file-text"></i></div><div><div class="stat-card__value"><?= $activeLeases ?></div><div class="stat-card__label">Active Leases</div></div></div>
    <div class="stat-card"><div class="stat-card__icon stat-card__icon--warning"><i class="bi bi-chat-dots"></i></div><div><div class="stat-card__value"><?= $pendingInquiries ?></div><div class="stat-card__label">Pending Inquiries</div></div></div>
    <div class="stat-card"><div class="stat-card__icon stat-card__icon--purple"><i class="bi bi-wallet2"></i></div><div><div class="stat-card__value"><?= formatCurrency($pendingComm) ?></div><div class="stat-card__label">Pending Commission</div></div></div>
</div>
<div class="card">
    <div class="card__header"><h3 class="card__title">Quick Actions</h3></div>
    <div class="card__body" style="display:flex;gap:12px;flex-wrap:wrap">
        <a href="<?= APP_URL ?>/index.php?page=properties&action=create" class="btn btn--primary"><i class="bi bi-plus-lg"></i> Add Property</a>
        <a href="<?= APP_URL ?>/index.php?page=customers&action=create" class="btn btn--outline"><i class="bi bi-person-plus"></i> Add Customer</a>
        <a href="<?= APP_URL ?>/index.php?page=inquiries" class="btn btn--outline"><i class="bi bi-chat-left-text"></i> View Inquiries</a>
    </div>
</div>
