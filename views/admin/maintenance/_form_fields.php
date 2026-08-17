<?php
/**
 * Maintenance request fields — shared by the full "New Request" page and
 * the quick-add popup, so the two can never drift apart.
 *
 * Expects:  $properties  already scoped to what this user may file against
 * Optional: $fd   entry kept back after a rejected submit
 *           $uid  id prefix for this instance's fields
 *
 * The property list is narrowed by role in includes/property_access.php and
 * re-checked when the row is written, so everything below is presentation:
 * a tenant with a single address gets it filled in rather than a one-item
 * menu, and nobody is offered a property they would only be refused.
 */
$uid = $uid ?? 'mnt';
$fd  = $fd ?? [];
$properties   = $properties ?? [];
$onlyProperty = count($properties) === 1 ? $properties[0] : null;
$scopeHint    = maintenanceScopeHint();
?>
<div class="form-grid--2">
    <div class="form-group">
        <label class="form-label" for="<?= $uid ?>-property">Property *</label>
        <?php if ($onlyProperty): ?>
            <input type="text" class="form-control" id="<?= $uid ?>-property" readonly
                   aria-describedby="<?= $uid ?>-property-hint"
                   value="<?= sanitize($onlyProperty['title']) ?> · <?= sanitize($onlyProperty['property_code']) ?>">
            <input type="hidden" name="property_id" value="<?= (int) $onlyProperty['id'] ?>">
        <?php else: ?>
            <select name="property_id" id="<?= $uid ?>-property" class="form-control" required
                    aria-describedby="<?= $uid ?>-property-hint">
                <option value="">— Select —</option>
                <?php foreach ($properties as $p): ?>
                    <option value="<?= $p['id'] ?>" <?= (int)($fd['property_id'] ?? 0) === (int)$p['id'] ? 'selected' : '' ?>>
                        <?= sanitize($p['title']) ?> · <?= sanitize($p['property_code']) ?>
                    </option>
                <?php endforeach ?>
            </select>
        <?php endif ?>
        <div class="form-hint" id="<?= $uid ?>-property-hint"><?= sanitize($scopeHint) ?></div>
    </div>
    <div class="form-group">
        <label class="form-label" for="<?= $uid ?>-priority">Priority</label>
        <select name="priority" id="<?= $uid ?>-priority" class="form-control">
            <?php foreach (['low' => 'Low', 'medium' => 'Medium', 'high' => 'High', 'urgent' => 'Urgent'] as $value => $label): ?>
                <option value="<?= $value ?>" <?= ($fd['priority'] ?? 'medium') === $value ? 'selected' : '' ?>><?= $label ?></option>
            <?php endforeach ?>
        </select>
    </div>
</div>

<div class="form-group">
    <label class="form-label" for="<?= $uid ?>-issue">Issue Type</label>
    <input type="text" class="form-control" id="<?= $uid ?>-issue" name="issue_type"
           placeholder="e.g. Plumbing, Electrical, HVAC" value="<?= sanitize($fd['issue_type'] ?? '') ?>">
</div>

<div class="form-group">
    <label class="form-label" for="<?= $uid ?>-description">Description *</label>
    <textarea class="form-control" id="<?= $uid ?>-description" name="description" rows="4" required
              placeholder="Describe the issue in detail…"><?= sanitize($fd['description'] ?? '') ?></textarea>
</div>

<div class="form-group">
    <label class="form-label" for="<?= $uid ?>-photos">Photos (optional)</label>
    <input type="file" class="form-control" id="<?= $uid ?>-photos" name="photos[]" accept="image/*" multiple>
    <div class="form-hint">You can attach multiple photos of the issue.</div>
</div>
