<?php
/**
 * Property form fields — shared by the full "Add Property" page and the
 * quick-add popup, so the two can never drift apart.
 *
 * Expects:  $fd, $owners, $agents, $branches
 * Optional: $uid  id prefix for this instance's fields
 */
$uid = $uid ?? 'prop';
?>
<div class="form-group">
    <label class="form-label" for="<?= $uid ?>-title">Title *</label>
    <input type="text" id="<?= $uid ?>-title" name="title" class="form-control"
           placeholder="e.g. Two-bedroom apartment with parking"
           value="<?= sanitize($fd['title'] ?? '') ?>" required>
</div>

<?php require __DIR__ . '/_location_fields.php'; ?>

<div class="form-row">
    <div class="form-group">
        <label class="form-label" for="<?= $uid ?>-type">Property Type</label>
        <select name="property_type" id="<?= $uid ?>-type" class="form-control">
            <option value="rent" <?= ($fd['property_type'] ?? '') === 'rent' ? 'selected' : '' ?>>For Rent</option>
            <option value="sale" <?= ($fd['property_type'] ?? '') === 'sale' ? 'selected' : '' ?>>For Sale</option>
            <option value="both" <?= ($fd['property_type'] ?? '') === 'both' ? 'selected' : '' ?>>Rent &amp; Sale</option>
        </select>
    </div>
    <div class="form-group">
        <label class="form-label" for="<?= $uid ?>-category">Category</label>
        <select name="category" id="<?= $uid ?>-category" class="form-control">
            <?php foreach (['apartment','house','villa','land','office','commercial','warehouse'] as $c): ?>
            <option value="<?= $c ?>" <?= ($fd['category'] ?? '') === $c ? 'selected' : '' ?>><?= ucfirst($c) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
</div>

<div class="form-group">
    <label class="form-label" for="<?= $uid ?>-description">Description</label>
    <textarea name="description" id="<?= $uid ?>-description" class="form-control" rows="3"><?= sanitize($fd['description'] ?? '') ?></textarea>
</div>

<h4 class="form-section">Size &amp; layout</h4>

<div class="form-row form-row--4">
    <div class="form-group">
        <label class="form-label" for="<?= $uid ?>-size">Size (sqm)</label>
        <input type="number" step="0.01" min="0" id="<?= $uid ?>-size" name="size_sqm" class="form-control" value="<?= sanitize((string)($fd['size_sqm'] ?? '')) ?>">
    </div>
    <div class="form-group">
        <label class="form-label" for="<?= $uid ?>-rooms">Rooms</label>
        <input type="number" min="0" id="<?= $uid ?>-rooms" name="num_rooms" class="form-control" value="<?= sanitize((string)($fd['num_rooms'] ?? '0')) ?>">
    </div>
    <div class="form-group">
        <label class="form-label" for="<?= $uid ?>-baths">Bathrooms</label>
        <input type="number" min="0" id="<?= $uid ?>-baths" name="num_bathrooms" class="form-control" value="<?= sanitize((string)($fd['num_bathrooms'] ?? '0')) ?>">
    </div>
    <div class="form-group">
        <label class="form-label" for="<?= $uid ?>-floors">Floors</label>
        <input type="number" min="0" id="<?= $uid ?>-floors" name="num_floors" class="form-control" value="<?= sanitize((string)($fd['num_floors'] ?? '1')) ?>">
    </div>
</div>

<h4 class="form-section">Pricing</h4>

<div class="form-row form-row--3">
    <div class="form-group">
        <label class="form-label" for="<?= $uid ?>-price">Sale Price ($)</label>
        <input type="number" step="0.01" min="0" id="<?= $uid ?>-price" name="price" class="form-control" value="<?= sanitize((string)($fd['price'] ?? '')) ?>">
    </div>
    <div class="form-group">
        <label class="form-label" for="<?= $uid ?>-rent">Monthly Rent ($)</label>
        <input type="number" step="0.01" min="0" id="<?= $uid ?>-rent" name="rent_amount" class="form-control" value="<?= sanitize((string)($fd['rent_amount'] ?? '')) ?>">
    </div>
    <div class="form-group">
        <label class="form-label" for="<?= $uid ?>-deposit">Deposit ($)</label>
        <input type="number" step="0.01" min="0" id="<?= $uid ?>-deposit" name="deposit_amount" class="form-control" value="<?= sanitize((string)($fd['deposit_amount'] ?? '')) ?>">
    </div>
</div>

<div style="display:flex;gap:24px;margin-bottom:20px;flex-wrap:wrap">
    <label style="display:flex;align-items:center;gap:6px;font-size:.875rem;cursor:pointer">
        <input type="checkbox" name="is_furnished" <?= !empty($fd['is_furnished']) ? 'checked' : '' ?>> Furnished
    </label>
    <label style="display:flex;align-items:center;gap:6px;font-size:.875rem;cursor:pointer">
        <input type="checkbox" name="has_parking" <?= !empty($fd['has_parking']) ? 'checked' : '' ?>> Parking
    </label>
    <label style="display:flex;align-items:center;gap:6px;font-size:.875rem;cursor:pointer">
        <input type="checkbox" name="has_security" <?= !empty($fd['has_security']) ? 'checked' : '' ?>> Security
    </label>
</div>

<div class="form-group">
    <label class="form-label" for="<?= $uid ?>-utilities">Utilities Included</label>
    <input type="text" id="<?= $uid ?>-utilities" name="utilities_included" class="form-control"
           placeholder="e.g. Water, Electricity" value="<?= sanitize($fd['utilities_included'] ?? '') ?>">
</div>

<h4 class="form-section">Assignment</h4>

<div class="form-row form-row--3">
    <div class="form-group">
        <label class="form-label" for="<?= $uid ?>-owner">Owner</label>
        <select name="owner_id" id="<?= $uid ?>-owner" class="form-control">
            <option value="">— None —</option>
            <?php foreach ($owners as $o): ?>
            <option value="<?= $o['id'] ?>" <?= ($fd['owner_id'] ?? '') == $o['id'] ? 'selected' : '' ?>><?= sanitize($o['full_name']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="form-group">
        <label class="form-label" for="<?= $uid ?>-agent">Assigned Agent</label>
        <select name="agent_id" id="<?= $uid ?>-agent" class="form-control">
            <option value="">— None —</option>
            <?php foreach ($agents as $a): ?>
            <option value="<?= $a['id'] ?>" <?= ($fd['agent_id'] ?? '') == $a['id'] ? 'selected' : '' ?>><?= sanitize($a['full_name']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="form-group">
        <label class="form-label" for="<?= $uid ?>-branch">Branch</label>
        <select name="branch_id" id="<?= $uid ?>-branch" class="form-control">
            <option value="">— None —</option>
            <?php foreach ($branches as $b): ?>
            <option value="<?= $b['id'] ?>" <?= ($fd['branch_id'] ?? '') == $b['id'] ? 'selected' : '' ?>><?= sanitize($b['name']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
</div>

<div class="form-group">
    <label class="form-label" for="<?= $uid ?>-images">Property Images</label>
    <input type="file" id="<?= $uid ?>-images" name="images[]" class="form-control" accept="image/*" multiple>
    <div class="form-hint">Upload up to 10 images. First image becomes cover.</div>
</div>
