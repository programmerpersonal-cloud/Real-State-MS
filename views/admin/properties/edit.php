<?php
$pageTitle = 'Edit Property';
$breadcrumbs = [['label' => 'Properties', 'url' => APP_URL . '/index.php?page=properties'], ['label' => sanitize($formData['title'])]];
$fd = $formData;
?>
<div class="card">
    <div class="card__header"><h3 class="card__title">Edit: <?= sanitize($fd['title']) ?></h3></div>
    <div class="card__body">
        <form method="POST" enctype="multipart/form-data" data-validate>
            <?= csrfField() ?>
            <div class="form-row">
                <div class="form-group"><label class="form-label">Title *</label><input type="text" name="title" class="form-control" value="<?= sanitize($fd['title']) ?>" required></div>
                <div class="form-group"><label class="form-label">Location *</label><input type="text" name="location" class="form-control" value="<?= sanitize($fd['location']) ?>" required></div>
            </div>
            <div class="form-row">
                <div class="form-group"><label class="form-label">Type</label><select name="property_type" class="form-control"><?php foreach(['rent','sale','both'] as $t): ?><option value="<?=$t?>" <?=$fd['property_type']===$t?'selected':''?>><?=ucfirst($t)?></option><?php endforeach;?></select></div>
                <div class="form-group"><label class="form-label">Category</label><select name="category" class="form-control"><?php foreach(['apartment','house','villa','land','office','commercial','warehouse'] as $c):?><option value="<?=$c?>" <?=$fd['category']===$c?'selected':''?>><?=ucfirst($c)?></option><?php endforeach;?></select></div>
            </div>
            <div class="form-group"><label class="form-label">Description</label><textarea name="description" class="form-control" rows="3"><?=sanitize($fd['description'])?></textarea></div>
            <div class="form-group"><label class="form-label">Address</label><input type="text" name="address" class="form-control" value="<?=sanitize($fd['address'])?>"></div>
            <div class="form-row" style="grid-template-columns:repeat(4,1fr)">
                <div class="form-group"><label class="form-label">Size (sqm)</label><input type="number" step="0.01" name="size_sqm" class="form-control" value="<?=$fd['size_sqm']?>"></div>
                <div class="form-group"><label class="form-label">Rooms</label><input type="number" name="num_rooms" class="form-control" value="<?=$fd['num_rooms']?>"></div>
                <div class="form-group"><label class="form-label">Bathrooms</label><input type="number" name="num_bathrooms" class="form-control" value="<?=$fd['num_bathrooms']?>"></div>
                <div class="form-group"><label class="form-label">Floors</label><input type="number" name="num_floors" class="form-control" value="<?=$fd['num_floors']?>"></div>
            </div>
            <div class="form-row" style="grid-template-columns:repeat(3,1fr)">
                <div class="form-group"><label class="form-label">Price ($)</label><input type="number" step="0.01" name="price" class="form-control" value="<?=$fd['price']?>"></div>
                <div class="form-group"><label class="form-label">Rent ($)</label><input type="number" step="0.01" name="rent_amount" class="form-control" value="<?=$fd['rent_amount']?>"></div>
                <div class="form-group"><label class="form-label">Deposit ($)</label><input type="number" step="0.01" name="deposit_amount" class="form-control" value="<?=$fd['deposit_amount']?>"></div>
            </div>
            <div style="display:flex;gap:24px;margin-bottom:20px">
                <label style="display:flex;align-items:center;gap:6px;font-size:.875rem;cursor:pointer"><input type="checkbox" name="is_furnished" <?=$fd['is_furnished']?'checked':''?>> Furnished</label>
                <label style="display:flex;align-items:center;gap:6px;font-size:.875rem;cursor:pointer"><input type="checkbox" name="has_parking" <?=$fd['has_parking']?'checked':''?>> Parking</label>
                <label style="display:flex;align-items:center;gap:6px;font-size:.875rem;cursor:pointer"><input type="checkbox" name="has_security" <?=$fd['has_security']?'checked':''?>> Security</label>
            </div>
            <div class="form-group"><label class="form-label">Utilities</label><input type="text" name="utilities_included" class="form-control" value="<?=sanitize($fd['utilities_included'])?>"></div>
            <div class="form-row" style="grid-template-columns:repeat(3,1fr)">
                <div class="form-group"><label class="form-label">Status</label><select name="status" class="form-control"><?php foreach(['available','reserved','rented','sold','inactive','maintenance'] as $s):?><option value="<?=$s?>" <?=$fd['status']===$s?'selected':''?>><?=ucfirst($s)?></option><?php endforeach;?></select></div>
                <div class="form-group"><label class="form-label">Owner</label><select name="owner_id" class="form-control"><option value="">—</option><?php foreach($owners as $o):?><option value="<?=$o['id']?>" <?=$fd['owner_id']==$o['id']?'selected':''?>><?=sanitize($o['full_name'])?></option><?php endforeach;?></select></div>
                <div class="form-group"><label class="form-label">Agent</label><select name="agent_id" class="form-control"><option value="">—</option><?php foreach($agents as $a):?><option value="<?=$a['id']?>" <?=$fd['agent_id']==$a['id']?'selected':''?>><?=sanitize($a['full_name'])?></option><?php endforeach;?></select></div>
            </div>

            <!-- Existing Images -->
            <?php if (!empty($images)): ?>
            <div class="form-group">
                <label class="form-label">Current Images</label>
                <div style="display:flex;gap:12px;flex-wrap:wrap">
                    <?php foreach ($images as $img): ?>
                    <div style="position:relative;width:120px">
                        <img src="<?= APP_URL . '/' . $img['file_path'] ?>" style="width:120px;height:90px;object-fit:cover;border-radius:8px;border:1px solid var(--border)">
                        <?php if ($img['is_cover']): ?><span class="badge badge--primary" style="position:absolute;top:4px;left:4px;font-size:.6rem">Cover</span><?php endif; ?>
                        <a href="<?= APP_URL ?>/index.php?page=properties&action=delete-image&id=<?=$fd['id']?>&img_id=<?=$img['id']?>" class="btn btn--danger btn--sm" style="position:absolute;top:4px;right:4px;padding:2px 6px;font-size:.7rem" onclick="return confirm('Delete this image?')"><i class="bi bi-trash"></i></a>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <div class="form-group"><label class="form-label">Add More Images</label><input type="file" name="images[]" class="form-control" accept="image/*" multiple></div>

            <div style="display:flex;gap:12px;margin-top:28px">
                <button type="submit" class="btn btn--primary btn--lg"><i class="bi bi-check-lg"></i> Save Changes</button>
                <a href="<?= APP_URL ?>/index.php?page=properties&action=show&id=<?=$fd['id']?>" class="btn btn--outline btn--lg">Cancel</a>
            </div>
        </form>
    </div>
</div>
