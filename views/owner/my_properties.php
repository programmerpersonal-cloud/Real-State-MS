<?php
/**
 * Owner Portal — My Properties
 *
 * The owner's way into their own asset. Each card is a link to the shared
 * property detail page, which re-checks ownership on arrival — the grid is
 * navigation, not the access rule.
 *
 * The three counters exist because they are the questions an owner actually
 * opens this page to answer: is it let, is anything broken, is the paperwork
 * on file. Anything the agency does internally stays on the agency's screens.
 *
 * Expects: $properties, $ownerLinked
 */
$grid = 'display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:18px';
?>
<?php if (empty($properties)): ?>
    <div class="card">
        <div class="card__body">
            <div class="empty-state">
                <div class="empty-state__icon"><i class="bi bi-buildings"></i></div>
                <div class="empty-state__title">No properties yet</div>
                <p class="empty-state__desc">
                    <?php if (empty($ownerLinked)): ?>
                        Your account is not linked to an owner profile yet, so there is nothing to show.
                        The managing office can connect it for you.
                    <?php else: ?>
                        No properties are registered to you at the moment. Anything the office
                        adds under your name will appear here.
                    <?php endif ?>
                </p>
            </div>
        </div>
    </div>
<?php else: ?>
    <div style="<?= $grid ?>">
        <?php foreach ($properties as $p):
            $detailUrl = APP_URL . '/index.php?page=properties&action=show&id=' . (int) $p['id'];
            $cover     = $p['cover_image'] ?? '';
            $openIssues = (int) ($p['open_issues'] ?? 0);
            $docCount   = (int) ($p['document_count'] ?? 0);
            $price = $p['rent_amount']
                ? formatCurrency((float) $p['rent_amount']) . '<span class="text-muted" style="font-size:.78rem;font-weight:500">/mo</span>'
                : ($p['price'] ? formatCurrency((float) $p['price']) : '<span class="text-subtle">Not priced</span>');
        ?>
        <a class="prop-card" href="<?= $detailUrl ?>" style="display:block;text-decoration:none;color:inherit">
            <div class="prop-card__cover">
                <?php if ($cover): ?>
                    <img src="<?= APP_URL . '/' . sanitize($cover) ?>"
                         alt="<?= sanitize($p['title']) ?>" loading="lazy">
                <?php else: ?>
                    <div style="display:flex;align-items:center;justify-content:center;height:100%;color:var(--text-subtle)">
                        <i class="bi bi-image" style="font-size:1.8rem"></i>
                    </div>
                <?php endif ?>
                <span class="badge <?= getStatusBadgeClass($p['status']) ?>"><?= ucfirst($p['status']) ?></span>
            </div>

            <div class="prop-card__body">
                <div class="prop-card__title"><?= sanitize($p['title']) ?></div>
                <div class="prop-card__meta">
                    <i class="bi bi-geo-alt"></i>
                    <span><?= sanitize($p['location'] ?: 'Location not set') ?></span>
                </div>

                <div class="prop-card__price"><?= $price ?></div>

                <div class="prop-card__specs">
                    <span title="Bedrooms"><i class="bi bi-door-closed"></i> <?= (int) $p['num_rooms'] ?></span>
                    <span title="Bathrooms"><i class="bi bi-droplet"></i> <?= (int) $p['num_bathrooms'] ?></span>
                    <?php if ($p['size_sqm']): ?>
                        <span title="Floor area"><i class="bi bi-rulers"></i> <?= (int) $p['size_sqm'] ?> m²</span>
                    <?php endif ?>
                </div>

                <?php
                // Occupancy and the two counters that decide whether this
                // property needs the owner's attention today.
                ?>
                <div style="border-top:1px solid var(--border);margin-top:12px;padding-top:10px;font-size:.76rem;color:var(--text-muted);display:flex;flex-wrap:wrap;gap:12px">
                    <span title="Current tenant">
                        <i class="bi bi-person"></i>
                        <?= $p['tenant_name'] ? sanitize($p['tenant_name']) : 'No active tenant' ?>
                    </span>
                    <?php if ($openIssues > 0): ?>
                        <span style="color:var(--warning);font-weight:600" title="Open maintenance requests">
                            <i class="bi bi-wrench-adjustable"></i>
                            <?= $openIssues ?> open issue<?= $openIssues === 1 ? '' : 's' ?>
                        </span>
                    <?php endif ?>
                    <span title="Documents on file">
                        <i class="bi bi-folder2"></i>
                        <?= $docCount ?> document<?= $docCount === 1 ? '' : 's' ?>
                    </span>
                </div>

                <div style="margin-top:12px;font-size:.78rem;color:var(--primary);font-weight:600">
                    View details <i class="bi bi-arrow-right"></i>
                </div>
            </div>
        </a>
        <?php endforeach ?>
    </div>
<?php endif ?>
