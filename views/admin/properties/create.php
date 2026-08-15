<?php
$pageTitle = 'Add Property';
$breadcrumbs = [['label' => 'Properties', 'url' => APP_URL . '/index.php?page=properties'], ['label' => 'Add New']];
$fd = $formData ?? [];
?>

<div class="card">
    <div class="card__header"><h3 class="card__title">Property Details</h3></div>
    <div class="card__body">
        <form method="POST" enctype="multipart/form-data" data-validate>
            <?= csrfField() ?>

            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Title *</label>
                    <input type="text" name="title" class="form-control" value="<?= sanitize($fd['title'] ?? '') ?>" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Location *</label>
                    <input type="text" name="location" class="form-control" value="<?= sanitize($fd['location'] ?? '') ?>" required>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Property Type</label>
                    <select name="property_type" class="form-control">
                        <option value="rent" <?= ($fd['property_type'] ?? '') === 'rent' ? 'selected' : '' ?>>For Rent</option>
                        <option value="sale" <?= ($fd['property_type'] ?? '') === 'sale' ? 'selected' : '' ?>>For Sale</option>
                        <option value="both" <?= ($fd['property_type'] ?? '') === 'both' ? 'selected' : '' ?>>Rent & Sale</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Category</label>
                    <select name="category" class="form-control">
                        <?php foreach (['apartment','house','villa','land','office','commercial','warehouse'] as $c): ?>
                        <option value="<?= $c ?>" <?= ($fd['category'] ?? '') === $c ? 'selected' : '' ?>><?= ucfirst($c) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="form-group">
                <label class="form-label">Description</label>
                <textarea name="description" class="form-control" rows="3"><?= sanitize($fd['description'] ?? '') ?></textarea>
            </div>

            <div class="form-group">
                <label class="form-label">Full Address</label>
                <input type="text" name="address" class="form-control" value="<?= sanitize($fd['address'] ?? '') ?>">
            </div>

            <div class="form-row" style="grid-template-columns:repeat(4,1fr)">
                <div class="form-group">
                    <label class="form-label">Size (sqm)</label>
                    <input type="number" step="0.01" name="size_sqm" class="form-control" value="<?= $fd['size_sqm'] ?? '' ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">Rooms</label>
                    <input type="number" name="num_rooms" class="form-control" value="<?= $fd['num_rooms'] ?? '0' ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">Bathrooms</label>
                    <input type="number" name="num_bathrooms" class="form-control" value="<?= $fd['num_bathrooms'] ?? '0' ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">Floors</label>
                    <input type="number" name="num_floors" class="form-control" value="<?= $fd['num_floors'] ?? '1' ?>">
                </div>
            </div>

            <div class="form-row" style="grid-template-columns:repeat(3,1fr)">
                <div class="form-group">
                    <label class="form-label">Sale Price ($)</label>
                    <input type="number" step="0.01" name="price" class="form-control" value="<?= $fd['price'] ?? '' ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">Monthly Rent ($)</label>
                    <input type="number" step="0.01" name="rent_amount" class="form-control" value="<?= $fd['rent_amount'] ?? '' ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">Deposit ($)</label>
                    <input type="number" step="0.01" name="deposit_amount" class="form-control" value="<?= $fd['deposit_amount'] ?? '' ?>">
                </div>
            </div>

            <div style="display:flex;gap:24px;margin-bottom:20px">
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
                <label class="form-label">Utilities Included</label>
                <input type="text" name="utilities_included" class="form-control" placeholder="e.g. Water, Electricity" value="<?= sanitize($fd['utilities_included'] ?? '') ?>">
            </div>

            <div class="form-row" style="grid-template-columns:repeat(3,1fr)">
                <div class="form-group">
                    <label class="form-label">Owner</label>
                    <select name="owner_id" class="form-control">
                        <option value="">— None —</option>
                        <?php foreach ($owners as $o): ?>
                        <option value="<?= $o['id'] ?>" <?= ($fd['owner_id'] ?? '') == $o['id'] ? 'selected' : '' ?>><?= sanitize($o['full_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Assigned Agent</label>
                    <select name="agent_id" class="form-control">
                        <option value="">— None —</option>
                        <?php foreach ($agents as $a): ?>
                        <option value="<?= $a['id'] ?>" <?= ($fd['agent_id'] ?? '') == $a['id'] ? 'selected' : '' ?>><?= sanitize($a['full_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Branch</label>
                    <select name="branch_id" class="form-control">
                        <option value="">— None —</option>
                        <?php foreach ($branches as $b): ?>
                        <option value="<?= $b['id'] ?>" <?= ($fd['branch_id'] ?? '') == $b['id'] ? 'selected' : '' ?>><?= sanitize($b['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="form-group">
                <label class="form-label">Property Images</label>
                <input type="file" name="images[]" class="form-control" accept="image/*" multiple>
                <div class="form-hint">Upload up to 10 images. First image becomes cover.</div>
            </div>

            <div style="display:flex;gap:12px;margin-top:28px">
                <button type="submit" class="btn btn--primary btn--lg"><i class="bi bi-check-lg"></i> Create Property</button>
                <a href="<?= APP_URL ?>/index.php?page=properties" class="btn btn--outline btn--lg">Cancel</a>
            </div>
        </form>
    </div>
</div>
