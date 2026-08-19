<?php
/**
 * Maintenance technician's dashboard — the day's work.
 *
 * The three counters were three separate COUNT queries over the same table
 * for the same rows; they are one conditional aggregate now. The queries stay
 * in the view because the router includes this file directly rather than
 * going through a controller — changing that is a routing change, not a
 * presentation one.
 */
$db  = getDBConnection();
$uid = (int) $currentUser['id'];

$tally = $db->prepare("
    SELECT COALESCE(SUM(status IN ('assigned','in_progress')), 0)                        AS active,
           COALESCE(SUM(status = 'completed'), 0)                                        AS completed,
           COALESCE(SUM(priority IN ('urgent','high') AND status <> 'completed'), 0)     AS urgent
    FROM maintenance_requests WHERE assigned_to = ?
");
$tally->execute([$uid]);
$t = $tally->fetch() ?: ['active' => 0, 'completed' => 0, 'urgent' => 0];

$jobs = $db->prepare("
    SELECT m.*, p.title AS property_title, p.property_code, p.address AS property_address
    FROM maintenance_requests m
    JOIN properties p ON m.property_id = p.id
    WHERE m.assigned_to = ? AND m.status IN ('assigned','in_progress')
    ORDER BY FIELD(m.priority,'urgent','high','medium','low'), m.created_at
");
$jobs->execute([$uid]);
$jobs = $jobs->fetchAll();
?>
<div class="stats">
    <div class="stat-card">
        <div class="stat-card__icon stat-card__icon--info"><i class="bi bi-tools" aria-hidden="true"></i></div>
        <div class="stat-card__body">
            <div class="stat-card__value"><?= number_format((int) $t['active']) ?></div>
            <div class="stat-card__label">Jobs on your list</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-card__icon stat-card__icon--danger"><i class="bi bi-exclamation-triangle" aria-hidden="true"></i></div>
        <div class="stat-card__body">
            <div class="stat-card__value"><?= number_format((int) $t['urgent']) ?></div>
            <div class="stat-card__label">Urgent or high priority</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-card__icon stat-card__icon--success"><i class="bi bi-check2-all" aria-hidden="true"></i></div>
        <div class="stat-card__body">
            <div class="stat-card__value"><?= number_format((int) $t['completed']) ?></div>
            <div class="stat-card__label">Finished, all time</div>
        </div>
    </div>
</div>

<div class="table-card mt-2">
    <div class="table-head">
        <div class="table-head__title">Your active jobs</div>
        <span class="table-head__note">Most urgent first</span>
    </div>

    <?php if (empty($jobs)): ?>
        <?= uiEmptyState([
            'icon'  => 'bi-emoji-smile',
            'title' => 'Nothing assigned to you',
            'desc'  => 'When the office assigns you a job it appears here, with the address and what is wrong.',
            'actions' => [[
                'label' => 'All requests', 'icon' => 'bi-tools', 'can' => 'maintenance.view',
                'class' => 'btn--outline', 'url' => APP_URL . '/index.php?page=maintenance',
            ]],
        ]) ?>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th>Priority</th>
                        <th>Job</th>
                        <th>Where</th>
                        <th class="col-lo">What is wrong</th>
                        <th class="col-mid">Status</th>
                        <th class="cell-actions"><span class="sr-only">Actions</span></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($jobs as $j): ?>
                        <?php $id = (int) $j['id']; ?>
                        <tr>
                            <td class="cell-tight"><?= uiPriority((string) $j['priority']) ?></td>
                            <td class="cell-tight">
                                <a href="<?= APP_URL ?>/index.php?page=maintenance&amp;action=show&amp;id=<?= $id ?>" class="table__id">
                                    <?= sanitize($j['request_code']) ?>
                                </a>
                            </td>
                            <td>
                                <div class="cell-strong"><?= sanitize($j['property_title']) ?></div>
                                <?php if (!empty($j['property_address'])): ?>
                                    <div class="person__meta">
                                        <i class="bi bi-geo-alt" aria-hidden="true"></i> <?= sanitize($j['property_address']) ?>
                                    </div>
                                <?php endif ?>
                            </td>
                            <td class="cell-clip col-lo">
                                <?php if (!empty($j['issue_type'])): ?>
                                    <div class="cell-strong"><?= sanitize($j['issue_type']) ?></div>
                                <?php endif ?>
                                <div class="person__meta"><?= sanitize(truncate($j['description'], 70)) ?></div>
                            </td>
                            <td class="col-mid"><?= uiStatus($j['status']) ?></td>
                            <td class="cell-actions">
                                <a class="btn btn--primary btn--sm"
                                   href="<?= APP_URL ?>/index.php?page=maintenance&amp;action=show&amp;id=<?= $id ?>">
                                    Open <i class="bi bi-arrow-right" aria-hidden="true"></i>
                                </a>
                            </td>
                        </tr>
                    <?php endforeach ?>
                </tbody>
            </table>
        </div>
    <?php endif ?>
</div>
