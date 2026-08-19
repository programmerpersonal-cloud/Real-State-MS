<?php
/**
 * Property form fields — shared by the full "Add Property" page, the edit
 * page and the quick-add popup, so the three can never drift apart.
 *
 * Expects:  $fd, $owners, $agents, $branches
 * Optional: $uid  id prefix for this instance's fields
 *
 * Field-level messages arrive in $formErrors, keyed by field name, so a
 * rejected submit outlines the box that needs fixing rather than only
 * printing a sentence at the top of the page. The three helpers below wrap
 * the shared uiField* functions so each control reads as one line.
 */
$uid  = $uid ?? 'prop';
$fd   = $fd ?? [];
$errs = $formErrors ?? [];

// Status is an edit-time field. A new listing is always created as available
// and pending approval, so offering the full status list on the create form
// would invite someone to record a property as already sold before it exists.
$showStatus = $showStatus ?? false;

/** The error text under a field, or nothing. */
$err = static fn(string $f): string => uiFieldError($errs, $f);
/** The outline on a rejected control. */
$bad = static fn(string $f): string => uiInvalidClass($errs, $f);
/** aria-invalid + aria-describedby, tying the message to the control. */
$aria = static fn(string $f, string $hint = ''): string => uiFieldAria($errs, $f, $hint);
?>

<h3 class="form-section">Basic information</h3>

<div class="form-group">
    <label class="form-label" for="<?= $uid ?>-title">Title <span class="req" aria-hidden="true">*</span></label>
    <input type="text" id="<?= $uid ?>-title" name="title" class="form-control<?= $bad('title') ?>"
           placeholder="e.g. Two-bedroom apartment with parking"
           value="<?= sanitize($fd['title'] ?? '') ?>" required<?= $aria('title') ?>>
    <?= $err('title') ?>
</div>

<div class="form-row">
    <div class="form-group">
        <label class="form-label" for="<?= $uid ?>-type">Listing type</label>
        <select name="property_type" id="<?= $uid ?>-type" class="form-control">
            <option value="rent" <?= ($fd['property_type'] ?? '') === 'rent' ? 'selected' : '' ?>>For Rent</option>
            <option value="sale" <?= ($fd['property_type'] ?? '') === 'sale' ? 'selected' : '' ?>>For Sale</option>
            <option value="both" <?= ($fd['property_type'] ?? '') === 'both' ? 'selected' : '' ?>>Rent &amp; Sale</option>
        </select>
        <div class="form-hint" id="<?= $uid ?>-type-hint">Decides which price below is required.</div>
    </div>
    <div class="form-group">
        <label class="form-label" for="<?= $uid ?>-category">Property type</label>
        <select name="category" id="<?= $uid ?>-category" class="form-control">
            <?php foreach (['apartment','house','villa','land','office','commercial','warehouse'] as $c): ?>
                <option value="<?= $c ?>" <?= ($fd['category'] ?? '') === $c ? 'selected' : '' ?>><?= sanitize(uiLabel($c)) ?></option>
            <?php endforeach ?>
        </select>
    </div>
</div>

<h3 class="form-section">Location</h3>

<?php require __DIR__ . '/_location_fields.php'; ?>

<h3 class="form-section">Size &amp; layout</h3>

<div class="form-row form-row--4">
    <div class="form-group">
        <label class="form-label" for="<?= $uid ?>-size">Size (m²)</label>
        <input type="number" step="0.01" min="0" id="<?= $uid ?>-size" name="size_sqm"
               class="form-control<?= $bad('size_sqm') ?>" value="<?= sanitize((string)($fd['size_sqm'] ?? '')) ?>"<?= $aria('size_sqm') ?>>
        <?= $err('size_sqm') ?>
    </div>
    <div class="form-group">
        <label class="form-label" for="<?= $uid ?>-rooms">Bedrooms</label>
        <input type="number" min="0" id="<?= $uid ?>-rooms" name="num_rooms" class="form-control"
               value="<?= sanitize((string)($fd['num_rooms'] ?? '0')) ?>">
    </div>
    <div class="form-group">
        <label class="form-label" for="<?= $uid ?>-baths">Bathrooms</label>
        <input type="number" min="0" id="<?= $uid ?>-baths" name="num_bathrooms" class="form-control"
               value="<?= sanitize((string)($fd['num_bathrooms'] ?? '0')) ?>">
    </div>
    <div class="form-group">
        <label class="form-label" for="<?= $uid ?>-floors">Floors</label>
        <input type="number" min="0" id="<?= $uid ?>-floors" name="num_floors" class="form-control"
               value="<?= sanitize((string)($fd['num_floors'] ?? '1')) ?>">
    </div>
</div>

<div class="check-row">
    <label class="check">
        <input type="checkbox" name="is_furnished" <?= !empty($fd['is_furnished']) ? 'checked' : '' ?>>
        <span>Furnished</span>
    </label>
    <label class="check">
        <input type="checkbox" name="has_parking" <?= !empty($fd['has_parking']) ? 'checked' : '' ?>>
        <span>Parking</span>
    </label>
    <label class="check">
        <input type="checkbox" name="has_security" <?= !empty($fd['has_security']) ? 'checked' : '' ?>>
        <span>Security</span>
    </label>
</div>

<h3 class="form-section">Financial</h3>

<div class="form-row form-row--3">
    <div class="form-group">
        <label class="form-label" for="<?= $uid ?>-price">Sale price (<?= sanitize(currencySymbol()) ?>)</label>
        <input type="number" step="0.01" min="0" id="<?= $uid ?>-price" name="price"
               class="form-control<?= $bad('price') ?>" value="<?= sanitize((string)($fd['price'] ?? '')) ?>"<?= $aria('price') ?>>
        <?= $err('price') ?>
    </div>
    <div class="form-group">
        <label class="form-label" for="<?= $uid ?>-rent">Monthly rent (<?= sanitize(currencySymbol()) ?>)</label>
        <input type="number" step="0.01" min="0" id="<?= $uid ?>-rent" name="rent_amount"
               class="form-control<?= $bad('rent_amount') ?>" value="<?= sanitize((string)($fd['rent_amount'] ?? '')) ?>"<?= $aria('rent_amount') ?>>
        <?= $err('rent_amount') ?>
    </div>
    <div class="form-group">
        <label class="form-label" for="<?= $uid ?>-deposit">Deposit (<?= sanitize(currencySymbol()) ?>)</label>
        <input type="number" step="0.01" min="0" id="<?= $uid ?>-deposit" name="deposit_amount"
               class="form-control" value="<?= sanitize((string)($fd['deposit_amount'] ?? '')) ?>">
    </div>
</div>

<div class="form-group">
    <label class="form-label" for="<?= $uid ?>-utilities">Utilities included</label>
    <input type="text" id="<?= $uid ?>-utilities" name="utilities_included" class="form-control"
           placeholder="e.g. Water, Electricity" value="<?= sanitize($fd['utilities_included'] ?? '') ?>">
</div>

<h3 class="form-section">Ownership &amp; assignment</h3>

<?php if ($showStatus): ?>
    <div class="form-group">
        <label class="form-label" for="<?= $uid ?>-status">Status</label>
        <select name="status" id="<?= $uid ?>-status" class="form-control">
            <?php foreach (['available','reserved','rented','sold','maintenance','inactive'] as $s): ?>
                <option value="<?= $s ?>" <?= ($fd['status'] ?? '') === $s ? 'selected' : '' ?>><?= sanitize(uiLabel($s)) ?></option>
            <?php endforeach ?>
        </select>
        <div class="form-hint">Reservations, leases and sales set this themselves — change it by hand only to correct a record.</div>
    </div>
<?php endif ?>

<div class="form-row form-row--3">
    <div class="form-group">
        <label class="form-label" for="<?= $uid ?>-owner">Owner</label>
        <select name="owner_id" id="<?= $uid ?>-owner" class="form-control">
            <option value="">— None —</option>
            <?php foreach ($owners as $o): ?>
                <option value="<?= $o['id'] ?>" <?= ($fd['owner_id'] ?? '') == $o['id'] ? 'selected' : '' ?>><?= sanitize($o['full_name']) ?></option>
            <?php endforeach ?>
        </select>
    </div>
    <div class="form-group">
        <label class="form-label" for="<?= $uid ?>-agent">Assigned agent</label>
        <select name="agent_id" id="<?= $uid ?>-agent" class="form-control">
            <option value="">— None —</option>
            <?php foreach ($agents as $a): ?>
                <option value="<?= $a['id'] ?>" <?= ($fd['agent_id'] ?? '') == $a['id'] ? 'selected' : '' ?>><?= sanitize($a['full_name']) ?></option>
            <?php endforeach ?>
        </select>
    </div>
    <div class="form-group">
        <label class="form-label" for="<?= $uid ?>-branch">Branch</label>
        <select name="branch_id" id="<?= $uid ?>-branch" class="form-control">
            <option value="">— None —</option>
            <?php foreach ($branches as $b): ?>
                <option value="<?= $b['id'] ?>" <?= ($fd['branch_id'] ?? '') == $b['id'] ? 'selected' : '' ?>><?= sanitize($b['name']) ?></option>
            <?php endforeach ?>
        </select>
    </div>
</div>

<h3 class="form-section">Media</h3>

<?php /* The zone is the label, so clicking anywhere in it opens the picker and
         the real file input can stay hidden. initUploadZone() writes what was
         chosen into [data-upload-label] — without that a hidden input gives no
         sign anything was picked. */ ?>
<div class="form-group">
    <span class="form-label" id="<?= $uid ?>-images-label">Property images</span>
    <label class="upload-zone">
        <input type="file" id="<?= $uid ?>-images" name="images[]" accept="image/*" multiple
               data-upload-input aria-labelledby="<?= $uid ?>-images-label">
        <i class="bi bi-cloud-arrow-up" aria-hidden="true"></i>
        <span class="upload-zone__title" data-upload-label>Choose images or drop them here</span>
        <span class="upload-zone__hint">Up to 10 files · JPG, PNG or WebP · the first becomes the cover</span>
    </label>
</div>

<h3 class="form-section">Additional information</h3>

<div class="form-group">
    <label class="form-label" for="<?= $uid ?>-description">Description</label>
    <textarea name="description" id="<?= $uid ?>-description" class="form-control" rows="4"
              placeholder="What makes this property worth viewing — condition, aspect, nearby amenities."><?= sanitize($fd['description'] ?? '') ?></textarea>
    <div class="form-hint">Shown on the public listing.</div>
</div>
