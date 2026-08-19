<?php
/**
 * Document form fields — shared by the upload popup, the standalone upload
 * page and the edit page, so the three can never drift apart.
 *
 * Expects:  $categories    [id => name]  active categories
 * Optional: $categoryMeta  [id => ['default_visibility','requires_expiry',…]]
 *           $properties    property list; omitted when the property is fixed
 *           $fixedProperty property row when uploading from a property page
 *           $fd            form data kept back after a rejected submit
 *           $uid           id prefix for this instance's fields
 *           $isEdit        true on the edit page — the file input is hidden
 */
$uid           = $uid ?? 'doc';
$fd            = $fd ?? [];
$isEdit        = $isEdit ?? false;
$categoryMeta  = $categoryMeta ?? [];
$fixedProperty = $fixedProperty ?? null;

// Picking a category should pre-select the visibility that category is
// normally filed under, so the safe choice is the default rather than a
// decision every uploader has to remember to make.
$visibilityDefaults = [];
foreach ($categoryMeta as $cid => $meta) {
    $visibilityDefaults[$cid] = $meta['default_visibility'];
}
?>
<div class="form-group">
    <label class="form-label" for="<?= $uid ?>-title">Document name <span class="req" aria-hidden="true">*</span></label>
    <input type="text" id="<?= $uid ?>-title" name="title" class="form-control" required maxlength="200"
           value="<?= sanitize($fd['title'] ?? '') ?>" placeholder="e.g. Title deed — Plot 14/B">
</div>

<div class="form-grid--2">
    <div class="form-group">
        <label class="form-label" for="<?= $uid ?>-category">Category <span class="req" aria-hidden="true">*</span></label>
        <select name="category_id" id="<?= $uid ?>-category" class="form-control" required
                data-doc-category data-visibility-map="<?= sanitize(json_encode($visibilityDefaults, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)) ?>">
            <option value="">— Select a category —</option>
            <?php foreach ($categories as $cid => $cname): ?>
                <option value="<?= (int) $cid ?>" <?= (int) ($fd['category_id'] ?? 0) === (int) $cid ? 'selected' : '' ?>>
                    <?= sanitize($cname) ?>
                </option>
            <?php endforeach ?>
        </select>
        <?php if (empty($categories)): ?>
            <div class="form-hint">
                No active categories.
                <a href="<?= APP_URL ?>/index.php?page=document-categories">Add one first</a>.
            </div>
        <?php endif ?>
    </div>

    <div class="form-group">
        <label class="form-label" for="<?= $uid ?>-visibility">Visibility <span class="req" aria-hidden="true">*</span></label>
        <select name="visibility" id="<?= $uid ?>-visibility" class="form-control" required data-doc-visibility>
            <?php foreach (DOC_VISIBILITIES as $key => $label): ?>
                <option value="<?= $key ?>" <?= ($fd['visibility'] ?? 'staff') === $key ? 'selected' : '' ?>>
                    <?= sanitize($label) ?>
                </option>
            <?php endforeach ?>
        </select>
        <div class="form-hint" data-doc-visibility-hint>
            <?= sanitize(DOC_VISIBILITY_HINTS[$fd['visibility'] ?? 'staff'] ?? '') ?>
        </div>
    </div>
</div>

<?php if ($fixedProperty): ?>
    <input type="hidden" name="property_id" value="<?= (int) $fixedProperty['id'] ?>">
<?php else: ?>
    <div class="form-group">
        <label class="form-label" for="<?= $uid ?>-property">Property <span class="req" aria-hidden="true">*</span></label>
        <select name="property_id" id="<?= $uid ?>-property" class="form-control" required>
            <option value="">— Select property —</option>
            <?php foreach (($properties ?? []) as $p): ?>
                <option value="<?= $p['id'] ?>" <?= (int) ($fd['reference_id'] ?? $fd['property_id'] ?? 0) === (int) $p['id'] ? 'selected' : '' ?>>
                    <?= sanitize($p['title']) ?> · <?= sanitize($p['property_code']) ?>
                </option>
            <?php endforeach ?>
        </select>
    </div>
<?php endif ?>

<div class="form-group">
    <label class="form-label" for="<?= $uid ?>-description">Description</label>
    <textarea name="description" id="<?= $uid ?>-description" class="form-control" rows="2"
              placeholder="What this document is, and anything a colleague would need to know."><?= sanitize($fd['description'] ?? '') ?></textarea>
</div>

<h3 class="form-section">Reference &amp; dates</h3>

<div class="form-row form-row--3">
    <div class="form-group">
        <label class="form-label" for="<?= $uid ?>-number">Document / reference no.</label>
        <input type="text" id="<?= $uid ?>-number" name="doc_number" class="form-control" maxlength="100"
               value="<?= sanitize($fd['doc_number'] ?? '') ?>" placeholder="e.g. TD-2024-0198">
    </div>
    <div class="form-group">
        <label class="form-label" for="<?= $uid ?>-date">Document date</label>
        <input type="date" id="<?= $uid ?>-date" name="document_date" class="form-control"
               value="<?= sanitize(substr((string) ($fd['document_date'] ?? ''), 0, 10)) ?>">
    </div>
    <div class="form-group">
        <label class="form-label" for="<?= $uid ?>-expiry">Expiry date</label>
        <input type="date" id="<?= $uid ?>-expiry" name="expiry_date" class="form-control"
               value="<?= sanitize(substr((string) ($fd['expiry_date'] ?? ''), 0, 10)) ?>">
        <div class="form-hint">Optional. Expiring documents are flagged <?= documentExpiryWarningDays() ?> days ahead — nothing is ever deleted.</div>
    </div>
</div>

<?php if (!$isEdit): ?>
<h3 class="form-section">File</h3>

<div class="form-group">
    <label class="upload-zone" for="<?= $uid ?>-file">
        <i class="bi bi-cloud-arrow-up"></i>
        <span data-upload-label>Choose a file to upload</span>
        <span class="upload-zone__hint">
            PDF, JPG, PNG, WEBP, Word or Excel · up to <?= formatBytes(documentMaxBytes()) ?>
        </span>
        <input type="file" id="<?= $uid ?>-file" name="document_file" required
               accept=".pdf,.jpg,.jpeg,.png,.webp,.doc,.docx,.xls,.xlsx"
               data-upload-input>
    </label>
    <div class="form-hint">
        <i class="bi bi-shield-lock"></i>
        Stored outside the public web folder. Only people cleared for this document's visibility can download it.
    </div>
</div>
<?php endif ?>
