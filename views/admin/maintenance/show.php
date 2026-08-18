<?php
/**
 * Maintenance — Show
 */
$r = $request;
$priorityClass = ['urgent' => 'danger', 'high' => 'orange', 'medium' => 'warning', 'low' => 'info'];
// Decided by the controller from the role *and* the property relationship —
// an agent holds no panel over a colleague's listing, and a technician sees
// the update form only for the job actually assigned to them. The matching
// checks run again when either form posts.
$canAssign = $canAssign ?? false;
$canUpdate = $canManage ?? false;
?>
<div class="grid-2">
    <div class="card">
        <div class="card__header"><h3 class="card__title">Request Details</h3></div>
        <div class="card__body">
            <div class="profile-meta">
                <div class="profile-meta__row"><span class="profile-meta__label">Code</span><span class="profile-meta__value"><?= sanitize($r['request_code']) ?></span></div>
                <div class="profile-meta__row"><span class="profile-meta__label">Property</span><span class="profile-meta__value"><a href="<?= APP_URL ?>/index.php?page=properties&action=show&id=<?= $r['property_id'] ?>"><?= sanitize($r['property_title']) ?></a></span></div>
                <div class="profile-meta__row"><span class="profile-meta__label">Issue Type</span><span class="profile-meta__value"><?= sanitize($r['issue_type'] ?: '—') ?></span></div>
                <div class="profile-meta__row"><span class="profile-meta__label">Priority</span><span class="profile-meta__value"><span class="badge badge--<?= $priorityClass[$r['priority']] ?? 'muted' ?>"><?= ucfirst($r['priority']) ?></span></span></div>
                <div class="profile-meta__row"><span class="profile-meta__label">Status</span><span class="profile-meta__value"><span class="badge <?= getStatusBadgeClass($r['status']) ?>"><?= ucfirst(str_replace('_',' ',$r['status'])) ?></span></span></div>
                <div class="profile-meta__row"><span class="profile-meta__label">Reported By</span><span class="profile-meta__value"><?= sanitize($r['reporter_name'] ?? 'System') ?></span></div>
                <div class="profile-meta__row"><span class="profile-meta__label">Assigned To</span><span class="profile-meta__value"><?= sanitize($r['assigned_name'] ?? '—') ?></span></div>
                <div class="profile-meta__row"><span class="profile-meta__label">Estimate</span><span class="profile-meta__value"><?= formatCurrency((float)$r['cost_estimate']) ?></span></div>
                <div class="profile-meta__row"><span class="profile-meta__label">Actual Cost</span><span class="profile-meta__value"><?= formatCurrency((float)$r['actual_cost']) ?></span></div>
                <?php if ($r['completion_date']): ?>
                <div class="profile-meta__row"><span class="profile-meta__label">Completed</span><span class="profile-meta__value"><?= formatDate($r['completion_date']) ?></span></div>
                <?php endif ?>
            </div>

            <div class="section-title">Description</div>
            <div class="prose"><?= nl2br(sanitize($r['description'])) ?></div>

            <?php if ($r['completion_notes']): ?>
                <div class="section-title">Completion Notes</div>
                <div class="prose"><?= nl2br(sanitize($r['completion_notes'])) ?></div>
            <?php endif ?>

            <?php if (!empty($photos)): ?>
                <div class="section-title">Attached Photos</div>
                <div class="gallery">
                <?php foreach ($photos as $ph): ?>
                    <div class="gallery__item">
                        <a href="<?= APP_URL . '/' . $ph ?>" target="_blank">
                            <img src="<?= APP_URL . '/' . $ph ?>" alt="">
                        </a>
                    </div>
                <?php endforeach ?>
                </div>
            <?php endif ?>
        </div>
    </div>

    <div>
        <?php if ($canAssign && in_array($r['status'], ['new','under_review'])): ?>
        <div class="card mb-3">
            <div class="card__header"><h3 class="card__title">Assign Technician</h3></div>
            <div class="card__body">
                <form method="post" action="<?= APP_URL ?>/index.php?page=maintenance&action=assign&id=<?= $r['id'] ?>">
                    <?= csrfField() ?>
                    <div class="form-group">
                        <label class="form-label">Technician</label>
                        <select name="assigned_to" class="form-control" required>
                            <option value="">— Select —</option>
                            <?php foreach ($technicians as $t): ?>
                                <option value="<?= $t['id'] ?>"><?= sanitize($t['full_name']) ?></option>
                            <?php endforeach ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Cost Estimate</label>
                        <input type="number" step="0.01" class="form-control" name="cost_estimate" value="<?= $r['cost_estimate'] ?>">
                    </div>
                    <button type="submit" class="btn btn--primary btn--block"><i class="bi bi-person-check"></i> Assign</button>
                </form>
            </div>
        </div>
        <?php endif ?>

        <?php if ($canUpdate && $r['status'] !== 'completed'): ?>
        <div class="card">
            <div class="card__header"><h3 class="card__title">Update Status</h3></div>
            <div class="card__body">
                <form method="post" action="<?= APP_URL ?>/index.php?page=maintenance&action=update&id=<?= $r['id'] ?>">
                    <?= csrfField() ?>
                    <div class="form-group">
                        <label class="form-label">Status</label>
                        <select name="status" class="form-control">
                            <?php foreach (['under_review','assigned','in_progress','completed','rejected','cancelled'] as $s): ?>
                                <option value="<?= $s ?>" <?= $r['status'] === $s ? 'selected' : '' ?>><?= ucfirst(str_replace('_',' ',$s)) ?></option>
                            <?php endforeach ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Actual Cost</label>
                        <input type="number" step="0.01" class="form-control" name="actual_cost" value="<?= $r['actual_cost'] ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Completion Notes</label>
                        <textarea name="completion_notes" class="form-control" rows="3"><?= sanitize($r['completion_notes']) ?></textarea>
                    </div>
                    <button type="submit" class="btn btn--success btn--block"><i class="bi bi-check2-all"></i> Update</button>
                </form>
            </div>
        </div>
        <?php endif ?>
    </div>
</div>
