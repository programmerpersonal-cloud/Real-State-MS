<?php
/**
 * Customer Portal — Favorites
 */
?>
<?php if (empty($properties)): ?>
<div class="empty-state">
    <div class="empty-state__icon"><i class="bi bi-heart"></i></div>
    <div class="empty-state__title">No saved properties</div>
    <div class="empty-state__desc">Click the heart icon on a property to save it here.</div>
</div>
<?php else: ?>
<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:20px">
    <?php foreach ($properties as $p): ?>
    <div class="prop-card">
        <div class="prop-card__cover">
            <?php if ($p['cover']): ?>
                <img src="<?= APP_URL . '/' . $p['cover'] ?>" alt="">
            <?php else: ?>
                <div style="display:flex;align-items:center;justify-content:center;height:100%;color:var(--text-muted)"><i class="bi bi-image" style="font-size:2rem"></i></div>
            <?php endif ?>
            <span class="badge <?= getStatusBadgeClass($p['status']) ?>"><?= ucfirst($p['status']) ?></span>
        </div>
        <div class="prop-card__body">
            <div class="prop-card__title"><?= sanitize($p['title']) ?></div>
            <div class="prop-card__meta"><i class="bi bi-geo-alt"></i> <?= sanitize($p['location']) ?></div>
            <div class="prop-card__price">
                <?= $p['rent_amount'] ? formatCurrency((float)$p['rent_amount']) . '/mo' : ($p['price'] ? formatCurrency((float)$p['price']) : '—') ?>
            </div>
            <div class="prop-card__specs">
                <span><i class="bi bi-door-closed"></i> <?= $p['num_rooms'] ?></span>
                <span><i class="bi bi-droplet"></i> <?= $p['num_bathrooms'] ?></span>
                <?php if ($p['size_sqm']): ?><span><i class="bi bi-rulers"></i> <?= $p['size_sqm'] ?>m²</span><?php endif ?>
            </div>
            <div style="display:flex;gap:6px;margin-top:12px">
                <a class="btn btn--outline btn--sm" href="<?= APP_URL ?>/index.php?page=properties&action=show&id=<?= $p['id'] ?>"><i class="bi bi-eye"></i> View</a>
                <a class="btn btn--danger btn--sm" href="<?= APP_URL ?>/index.php?page=favorites&action=remove&property_id=<?= $p['id'] ?>"><i class="bi bi-heart-fill"></i></a>
            </div>
        </div>
    </div>
    <?php endforeach ?>
</div>
<?php endif ?>
