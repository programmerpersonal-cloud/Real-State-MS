<?php
/**
 * Maintenance — Show
 */
$r = $request;
// Decided by the controller from the role *and* the property relationship —
// an agent holds no panel over a colleague's listing, and a technician sees
// the update form only for the job actually assigned to them. The matching
// checks run again when either form posts.
$canAssign = $canAssign ?? false;
$canUpdate = $canManage ?? false;

/* Contextual messaging about this job. The page header carries it rather than
   a card, because coordinating a repair is something you do *about* the whole
   request, not about one panel of it — and because a technician reading this
   on a phone should find it before scrolling.

   communicationEntryPoint() decides whether it appears at all: a technician
   gets the agent responsible for the property, or the managing office when no
   agent is assigned, and an unrelated reader gets nothing. */
$__msgEntry = communicationEntryPoint(['maintenance_request_id' => (int) $r['id']]);
if ($__msgEntry) {
    $actionButtons = [[
        'label' => $__msgEntry['label'],
        'icon'  => $__msgEntry['icon'],
        'url'   => $__msgEntry['url'],
        'class' => 'btn--outline',
    ]];
}
?>
<div class="grid-2">
    <div class="card">
        <div class="card__header"><h2 class="card__title">Request Details</h2></div>
        <div class="card__body">
            <div class="profile-meta">
                <div class="profile-meta__row"><span class="profile-meta__label">Code</span><span class="profile-meta__value"><?= sanitize($r['request_code']) ?></span></div>
                <div class="profile-meta__row"><span class="profile-meta__label">Property</span><span class="profile-meta__value"><a href="<?= APP_URL ?>/index.php?page=properties&action=show&id=<?= $r['property_id'] ?>"><?= sanitize($r['property_title']) ?></a></span></div>
                <div class="profile-meta__row"><span class="profile-meta__label">Issue Type</span><span class="profile-meta__value"><?= sanitize($r['issue_type'] ?: '—') ?></span></div>
                <div class="profile-meta__row"><span class="profile-meta__label">Priority</span><span class="profile-meta__value"><?= uiPriority((string) $r['priority']) ?></span></div>
                <div class="profile-meta__row"><span class="profile-meta__label">Status</span><span class="profile-meta__value"><?= uiStatus($r['status']) ?></span></div>
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
                <?php /* The image carries the link's name: an empty alt inside an
                         anchor leaves the link with nothing to announce at all. */ ?>
                <?php foreach ($photos as $i => $ph): ?>
                    <div class="gallery__item">
                        <a href="<?= APP_URL . '/' . $ph ?>" target="_blank" rel="noopener"
                           aria-label="Photo <?= $i + 1 ?> of <?= count($photos) ?>, full size">
                            <img src="<?= APP_URL . '/' . $ph ?>"
                                 alt="Photo <?= $i + 1 ?> of the reported issue" loading="lazy">
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
            <div class="card__header"><h2 class="card__title">Assign Technician</h2></div>
            <div class="card__body">
                <form method="post" action="<?= APP_URL ?>/index.php?page=maintenance&action=assign&id=<?= $r['id'] ?>">
                    <?= csrfField() ?>
                    <div class="form-group">
                        <label class="form-label" for="mr-tech">Technician</label>
                        <select id="mr-tech" name="assigned_to" class="form-control" required>
                            <option value="">— Select —</option>
                            <?php foreach ($technicians as $t): ?>
                                <option value="<?= $t['id'] ?>"><?= sanitize($t['full_name']) ?></option>
                            <?php endforeach ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="mr-estimate">Cost Estimate</label>
                        <input type="number" step="0.01" class="form-control" id="mr-estimate" name="cost_estimate" value="<?= $r['cost_estimate'] ?>">
                    </div>
                    <button type="submit" class="btn btn--primary btn--block"><i class="bi bi-person-check"></i> Assign</button>
                </form>
            </div>
        </div>
        <?php endif ?>

        <?php if ($canUpdate && $r['status'] !== 'completed'): ?>
        <div class="card">
            <div class="card__header"><h2 class="card__title">Update Status</h2></div>
            <div class="card__body">
                <form method="post" action="<?= APP_URL ?>/index.php?page=maintenance&action=update&id=<?= $r['id'] ?>">
                    <?= csrfField() ?>
                    <div class="form-group">
                        <label class="form-label" for="mr-status">Status</label>
                        <select id="mr-status" name="status" class="form-control">
                            <?php foreach (['under_review','assigned','in_progress','completed','rejected','cancelled'] as $s): ?>
                                <option value="<?= $s ?>" <?= $r['status'] === $s ? 'selected' : '' ?>><?= ucfirst(str_replace('_',' ',$s)) ?></option>
                            <?php endforeach ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="mr-actual">Actual Cost</label>
                        <input type="number" step="0.01" class="form-control" id="mr-actual" name="actual_cost" value="<?= $r['actual_cost'] ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="mr-notes">Completion Notes</label>
                        <textarea id="mr-notes" name="completion_notes" class="form-control" rows="3"><?= sanitize($r['completion_notes']) ?></textarea>
                    </div>
                    <button type="submit" class="btn btn--success btn--block"><i class="bi bi-check2-all"></i> Update</button>
                </form>
            </div>
        </div>
        <?php endif ?>
    </div>
</div>
