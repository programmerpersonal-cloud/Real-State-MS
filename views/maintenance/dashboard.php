<?php
/**
 * Maintenance Staff Dashboard
 */
$db = getDBConnection();
$uid = $currentUser['id'];

$assigned = $db->prepare("SELECT COUNT(*) FROM maintenance_requests WHERE assigned_to = ? AND status IN ('assigned','in_progress')");
$assigned->execute([$uid]); $assigned = (int)$assigned->fetchColumn();

$completed = $db->prepare("SELECT COUNT(*) FROM maintenance_requests WHERE assigned_to = ? AND status = 'completed'");
$completed->execute([$uid]); $completed = (int)$completed->fetchColumn();

$urgent = $db->prepare("SELECT COUNT(*) FROM maintenance_requests WHERE assigned_to = ? AND priority IN ('urgent','high') AND status != 'completed'");
$urgent->execute([$uid]); $urgent = (int)$urgent->fetchColumn();

$jobs = $db->prepare("
    SELECT m.*, p.title AS property_title
    FROM maintenance_requests m
    JOIN properties p ON m.property_id = p.id
    WHERE m.assigned_to = ? AND m.status IN ('assigned','in_progress')
    ORDER BY FIELD(m.priority,'urgent','high','medium','low'), m.created_at
");
$jobs->execute([$uid]); $jobs = $jobs->fetchAll();
?>
<div class="stats">
    <div class="stat-card"><div class="stat-card__icon stat-card__icon--info"><i class="bi bi-tools"></i></div><div><div class="stat-card__value"><?= $assigned ?></div><div class="stat-card__label">Active Jobs</div></div></div>
    <div class="stat-card"><div class="stat-card__icon stat-card__icon--danger"><i class="bi bi-exclamation-triangle"></i></div><div><div class="stat-card__value"><?= $urgent ?></div><div class="stat-card__label">Urgent / High</div></div></div>
    <div class="stat-card"><div class="stat-card__icon stat-card__icon--success"><i class="bi bi-check2-all"></i></div><div><div class="stat-card__value"><?= $completed ?></div><div class="stat-card__label">Completed</div></div></div>
</div>

<div class="card">
    <div class="card__header"><h3 class="card__title">My Active Jobs</h3></div>
    <div class="card__body" style="padding:0">
        <?php if (empty($jobs)): ?>
            <div class="empty-state"><div class="empty-state__icon"><i class="bi bi-emoji-smile"></i></div><div class="empty-state__title">No active assignments</div></div>
        <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>Code</th><th>Property</th><th>Priority</th><th>Issue</th><th>Status</th><th></th></tr></thead>
                <tbody>
                <?php
                $pc = ['urgent' => 'danger', 'high' => 'orange', 'medium' => 'warning', 'low' => 'info'];
                foreach ($jobs as $j): ?>
                <tr>
                    <td><code><?= sanitize($j['request_code']) ?></code></td>
                    <td><?= sanitize($j['property_title']) ?></td>
                    <td><span class="badge badge--<?= $pc[$j['priority']] ?? 'muted' ?>"><?= ucfirst($j['priority']) ?></span></td>
                    <td><?= sanitize(truncate($j['description'], 60)) ?></td>
                    <td><span class="badge <?= getStatusBadgeClass($j['status']) ?>"><?= ucfirst(str_replace('_',' ',$j['status'])) ?></span></td>
                    <td><a class="btn btn--primary btn--sm" href="<?= APP_URL ?>/index.php?page=maintenance&action=show&id=<?= $j['id'] ?>">Work</a></td>
                </tr>
                <?php endforeach ?>
                </tbody>
            </table>
        </div>
        <?php endif ?>
    </div>
</div>
