<?php
/**
 * Maintenance request fields — shared by the full "New Request" page and
 * the quick-add popup, so the two can never drift apart.
 *
 * Expects:  $properties  already scoped to what this user may file against
 * Optional: $fd    entry kept back after a rejected submit
 *           $errs  field-keyed errors from the same rejection
 *           $uid   id prefix for this instance's fields
 *
 * The property list is narrowed by role in includes/property_access.php and
 * re-checked when the row is written, so everything below is presentation:
 * a tenant with a single address gets it filled in rather than a one-item
 * menu, and nobody is offered a property they would only be refused.
 */
$uid  = $uid ?? 'mnt';
$fd   = $fd ?? [];
$errs = $errs ?? ($formErrors ?? []);

$err  = static fn(string $f): string => uiFieldError($errs, $f);
$bad  = static fn(string $f): string => uiInvalidClass($errs, $f);
$aria = static fn(string $f, string $hint = ''): string => uiFieldAria($errs, $f, $hint);

$properties   = $properties ?? [];
$onlyProperty = count($properties) === 1 ? $properties[0] : null;
$scopeHint    = maintenanceScopeHint();
/* Most pressing first, matching the pills and the queue's own ordering. */
$priorities = $priorities ?? ['urgent' => 'Urgent', 'high' => 'High', 'medium' => 'Medium', 'low' => 'Low'];
?>
<div class="form-grid--2">
    <div class="form-group">
        <label class="form-label" for="<?= $uid ?>-property">Property <span class="req" aria-hidden="true">*</span></label>
        <?php if ($onlyProperty): ?>
            <input type="text" class="form-control" id="<?= $uid ?>-property" readonly
                   aria-describedby="<?= $uid ?>-property-hint"
                   value="<?= sanitize($onlyProperty['title']) ?> · <?= sanitize($onlyProperty['property_code']) ?>">
            <input type="hidden" name="property_id" value="<?= (int) $onlyProperty['id'] ?>">
        <?php else: ?>
            <select name="property_id" id="<?= $uid ?>-property" class="form-control<?= $bad('property_id') ?>" required
                    <?= $aria('property_id', $uid . '-property-hint') ?>>
                <option value="">— Select —</option>
                <?php foreach ($properties as $p): ?>
                    <option value="<?= $p['id'] ?>" <?= (int)($fd['property_id'] ?? 0) === (int)$p['id'] ? 'selected' : '' ?>>
                        <?= sanitize($p['title']) ?> · <?= sanitize($p['property_code']) ?>
                    </option>
                <?php endforeach ?>
            </select>
        <?php endif ?>
        <?= $err('property_id') ?>
        <div class="form-hint" id="<?= $uid ?>-property-hint"><?= sanitize($scopeHint) ?></div>
    </div>
    <div class="form-group">
        <label class="form-label" for="<?= $uid ?>-priority">How urgent</label>
        <select name="priority" id="<?= $uid ?>-priority" class="form-control<?= $bad('priority') ?>"
                <?= $aria('priority', $uid . '-priority-hint') ?>>
            <?php foreach ($priorities as $value => $label): ?>
                <option value="<?= sanitize((string) $value) ?>"
                    <?= ($fd['priority'] ?? 'medium') === $value ? 'selected' : '' ?>><?= sanitize($label) ?></option>
            <?php endforeach ?>
        </select>
        <?= $err('priority') ?>
        <div class="form-hint" id="<?= $uid ?>-priority-hint">Urgent jobs go to the top of the queue.</div>
    </div>
</div>

<div class="form-group">
    <label class="form-label" for="<?= $uid ?>-issue">Kind of fault</label>
    <input type="text" class="form-control<?= $bad('issue_type') ?>" id="<?= $uid ?>-issue" name="issue_type"
           placeholder="e.g. Plumbing, Electrical, HVAC" value="<?= sanitize($fd['issue_type'] ?? '') ?>"
           <?= $aria('issue_type') ?>>
    <?= $err('issue_type') ?>
</div>

<div class="form-group">
    <label class="form-label" for="<?= $uid ?>-description">What is wrong <span class="req" aria-hidden="true">*</span></label>
    <textarea class="form-control<?= $bad('description') ?>" id="<?= $uid ?>-description" name="description" rows="4" required
              placeholder="Where in the property, what happens, and since when…"
              <?= $aria('description', $uid . '-desc-hint') ?>><?= sanitize($fd['description'] ?? '') ?></textarea>
    <?= $err('description') ?>
    <div class="form-hint" id="<?= $uid ?>-desc-hint">
        Enough detail that whoever attends knows what to bring.
    </div>
</div>

<?php /* The zone is the label, so clicking anywhere in it opens the picker and
         the real input can stay hidden by the CSS. initUploadZone() writes
         what was chosen into [data-upload-label]. */ ?>
<div class="form-group">
    <span class="form-label" id="<?= $uid ?>-photos-label">Photos</span>
    <label class="upload-zone">
        <input type="file" id="<?= $uid ?>-photos" name="photos[]" accept="image/*" multiple
               data-upload-input aria-labelledby="<?= $uid ?>-photos-label">
        <i class="bi bi-camera" aria-hidden="true"></i>
        <span class="upload-zone__title" data-upload-label>Add photographs of the fault</span>
        <span class="upload-zone__hint">Optional · JPG, PNG or WebP · a picture usually saves a visit</span>
    </label>
</div>
