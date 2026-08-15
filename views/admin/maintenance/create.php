<?php
/**
 * Maintenance — Create
 */
?>
<div class="card" style="max-width:680px;margin:0 auto">
    <div class="card__header"><h3 class="card__title">Report Maintenance Issue</h3></div>
    <div class="card__body">
        <form method="post" enctype="multipart/form-data" data-validate>
            <?= csrfField() ?>
            <div class="form-grid--2">
                <div class="form-group">
                    <label class="form-label">Property *</label>
                    <select name="property_id" class="form-control" required>
                        <option value="">— Select —</option>
                        <?php foreach ($properties as $p): ?>
                            <option value="<?= $p['id'] ?>"><?= sanitize($p['title']) ?> · <?= sanitize($p['property_code']) ?></option>
                        <?php endforeach ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Priority</label>
                    <select name="priority" class="form-control">
                        <option value="low">Low</option>
                        <option value="medium" selected>Medium</option>
                        <option value="high">High</option>
                        <option value="urgent">Urgent</option>
                    </select>
                </div>
            </div>
            <div class="form-group">
                <label class="form-label">Issue Type</label>
                <input type="text" class="form-control" name="issue_type" placeholder="e.g. Plumbing, Electrical, HVAC">
            </div>
            <div class="form-group">
                <label class="form-label">Description *</label>
                <textarea class="form-control" name="description" rows="4" required placeholder="Describe the issue in detail…"></textarea>
            </div>
            <div class="form-group">
                <label class="form-label">Photos (optional)</label>
                <input type="file" class="form-control" name="photos[]" accept="image/*" multiple>
                <div class="form-hint">You can attach multiple photos of the issue.</div>
            </div>
            <div class="form-actions">
                <a href="<?= APP_URL ?>/index.php?page=maintenance" class="btn btn--outline">Cancel</a>
                <button type="submit" class="btn btn--primary"><i class="bi bi-check-lg"></i> Submit Request</button>
            </div>
        </form>
    </div>
</div>
