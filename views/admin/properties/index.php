<?php
/* Properties — Index (vars provided by controller) */
$fd = $formData ?? [];
?>
<!-- Filters -->
<div class="card mb-3">
    <div class="card__body" style="padding:16px 24px">
        <form method="GET" style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap">
            <input type="hidden" name="page" value="properties">
            <div class="form-group" style="margin:0;flex:1;min-width:200px">
                <label class="form-label">Search</label>
                <input type="text" name="search" class="form-control" placeholder="Title, code, location..." value="<?= sanitize($filters['search'] ?? '') ?>">
            </div>
            <div class="form-group" style="margin:0">
                <label class="form-label">Type</label>
                <select name="property_type" class="form-control">
                    <option value="">All Types</option>
                    <option value="rent" <?= ($filters['property_type'] ?? '') === 'rent' ? 'selected' : '' ?>>Rent</option>
                    <option value="sale" <?= ($filters['property_type'] ?? '') === 'sale' ? 'selected' : '' ?>>Sale</option>
                    <option value="both" <?= ($filters['property_type'] ?? '') === 'both' ? 'selected' : '' ?>>Both</option>
                </select>
            </div>
            <div class="form-group" style="margin:0">
                <label class="form-label">Category</label>
                <select name="category" class="form-control">
                    <option value="">All</option>
                    <?php foreach (['apartment','house','villa','land','office','commercial','warehouse'] as $cat): ?>
                    <option value="<?= $cat ?>" <?= ($filters['category'] ?? '') === $cat ? 'selected' : '' ?>><?= ucfirst($cat) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group" style="margin:0">
                <label class="form-label">Status</label>
                <select name="status" class="form-control">
                    <option value="">All</option>
                    <?php foreach (['available','reserved','rented','sold','inactive','maintenance'] as $st): ?>
                    <option value="<?= $st ?>" <?= ($filters['status'] ?? '') === $st ? 'selected' : '' ?>><?= ucfirst($st) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit" class="btn btn--primary btn--sm"><i class="bi bi-search"></i> Filter</button>
            <a href="<?= APP_URL ?>/index.php?page=properties" class="btn btn--outline btn--sm">Clear</a>
        </form>
    </div>
</div>

<!-- Properties Table -->
<div class="card">
    <div class="card__header">
        <h3 class="card__title"><?= $totalCount ?> Properties</h3>
    </div>
    <div class="card__body" style="padding:0">
        <?php if (empty($properties)): ?>
            <div class="empty-state">
                <div class="empty-state__icon"><i class="bi bi-buildings"></i></div>
                <div class="empty-state__title">No properties found</div>
                <div class="empty-state__desc">Try adjusting your filters or add a new property.</div>
                <button type="button" class="btn btn--primary btn--sm" data-modal-open="propertyCreateModal" style="margin-top:14px">
                    <i class="bi bi-plus-lg"></i> Add Property
                </button>
            </div>
        <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th>Code</th>
                        <th>Title</th>
                        <th>Type</th>
                        <th>Category</th>
                        <th>Location</th>
                        <th>Price/Rent</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($properties as $p): ?>
                    <tr>
                        <td><strong><?= sanitize($p['property_code']) ?></strong></td>
                        <td>
                            <a href="<?= APP_URL ?>/index.php?page=properties&action=show&id=<?= $p['id'] ?>">
                                <?= sanitize(truncate($p['title'], 40)) ?>
                            </a>
                        </td>
                        <td><?= ucfirst($p['property_type']) ?></td>
                        <td><?= ucfirst($p['category']) ?></td>
                        <td><?= sanitize(truncate($p['location'], 25)) ?></td>
                        <td>
                            <?php if ($p['rent_amount']): ?>
                                <?= formatCurrency($p['rent_amount']) ?>/mo
                            <?php elseif ($p['price']): ?>
                                <?= formatCurrency($p['price']) ?>
                            <?php else: ?>—<?php endif; ?>
                        </td>
                        <td><span class="badge <?= getStatusBadgeClass($p['status']) ?>"><?= ucfirst($p['status']) ?></span></td>
                        <td>
                            <a href="<?= APP_URL ?>/index.php?page=properties&action=show&id=<?= $p['id'] ?>" class="btn btn--outline btn--sm" title="View"><i class="bi bi-eye"></i></a>
                            <a href="<?= APP_URL ?>/index.php?page=properties&action=edit&id=<?= $p['id'] ?>" class="btn btn--outline btn--sm" title="Edit"><i class="bi bi-pencil"></i></a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if ($totalPages > 1): ?>
        <div class="card__footer" style="display:flex;justify-content:center;gap:6px">
            <?php for ($i = 1; $i <= $totalPages; $i++): ?>
            <a href="<?= APP_URL ?>/index.php?page=properties&p=<?= $i ?>&<?= http_build_query($filters) ?>"
               class="btn btn--sm <?= $i === $page ? 'btn--primary' : 'btn--outline' ?>"><?= $i ?></a>
            <?php endfor; ?>
        </div>
        <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<?php require __DIR__ . '/_create_modal.php'; ?>
