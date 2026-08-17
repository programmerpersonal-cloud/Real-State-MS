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
?>
<?php if (empty($properties)): ?>
    <div class="table-card">
        <?= uiEmptyState([
            'icon'  => 'bi-buildings',
            'title' => 'No properties yet',
            'desc'  => empty($ownerLinked)
                ? 'Your account is not linked to an owner profile yet, so there is nothing to show. The managing office can connect it for you.'
                : 'No properties are registered to you at the moment. Anything the office adds under your name will appear here.',
        ]) ?>
    </div>
<?php else: ?>
    <div class="grid-auto">
        <?php foreach ($properties as $p):
            $detailUrl = APP_URL . '/index.php?page=properties&action=show&id=' . (int) $p['id'];
            $cover     = $p['cover_image'] ?? '';
            $openIssues = (int) ($p['open_issues'] ?? 0);
            $docCount   = (int) ($p['document_count'] ?? 0);
            $price = $p['rent_amount']
                ? formatCurrency((float) $p['rent_amount']) . '<span class="prop-card__per">/mo</span>'
                : ($p['price'] ? formatCurrency((float) $p['price']) : '<span class="text-subtle">Not priced</span>');
        ?>
        <a class="prop-card prop-card--link" href="<?= $detailUrl ?>">
            <div class="prop-card__cover">
                <?php if ($cover): ?>
                    <img src="<?= APP_URL . '/' . sanitize($cover) ?>"
                         alt="<?= sanitize($p['title']) ?>" loading="lazy">
                <?php else: ?>
                    <div class="prop-card__placeholder">
                        <i class="bi bi-image" aria-hidden="true"></i>
                    </div>
                <?php endif ?>
                <?= uiStatus($p['status']) ?>
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
                <div class="prop-card__stats">
                    <span>
                        <i class="bi bi-person" aria-hidden="true"></i>
                        <?= $p['tenant_name'] ? sanitize($p['tenant_name']) : 'No active tenant' ?>
                    </span>
                    <?php if ($openIssues > 0): ?>
                        <span class="text-warning">
                            <i class="bi bi-wrench-adjustable" aria-hidden="true"></i>
                            <?= $openIssues ?> open issue<?= $openIssues === 1 ? '' : 's' ?>
                        </span>
                    <?php endif ?>
                    <span>
                        <i class="bi bi-folder2" aria-hidden="true"></i>
                        <?= $docCount ?> document<?= $docCount === 1 ? '' : 's' ?>
                    </span>
                </div>

                <div class="prop-card__cta">
                    View details <i class="bi bi-arrow-right" aria-hidden="true"></i>
                </div>
            </div>
        </a>
        <?php endforeach ?>
    </div>
<?php endif ?>
