<?php
/**
 * Customer Portal — saved properties.
 *
 * Removing a shortlist entry is a POST now, so the heart cannot be tripped by
 * a link somebody else wrote. It is the same round trip either way — the card
 * simply carries a form instead of an anchor.
 */
?>
<?php if (empty($properties)): ?>
    <div class="table-card">
        <?= uiEmptyState([
            'icon'  => 'bi-heart',
            'title' => 'Nothing saved yet',
            'desc'  => 'Tap the heart on any listing and it is kept here, so you can compare a shortlist rather than hunting for the same properties twice.',
            'actions' => [[
                'label' => 'Browse listings', 'icon' => 'bi-search',
                'url'   => APP_URL . '/index.php?page=properties-public',
            ]],
        ]) ?>
    </div>
<?php else: ?>
    <div class="grid-auto">
        <?php foreach ($properties as $p): ?>
            <?php
            $pid   = (int) $p['id'];
            $price = $p['rent_amount']
                ? formatCurrency((float) $p['rent_amount']) . '<span class="prop-card__per">/mo</span>'
                : ($p['price'] ? formatCurrency((float) $p['price']) : '<span class="text-subtle">Not priced</span>');
            ?>
            <div class="prop-card">
                <div class="prop-card__cover">
                    <?php if ($p['cover']): ?>
                        <img src="<?= APP_URL . '/' . sanitize($p['cover']) ?>"
                             alt="<?= sanitize($p['title']) ?>" loading="lazy">
                    <?php else: ?>
                        <div class="prop-card__placeholder"><i class="bi bi-image" aria-hidden="true"></i></div>
                    <?php endif ?>
                    <?= uiStatus($p['status']) ?>
                </div>

                <div class="prop-card__body">
                    <div class="prop-card__title">
                        <a href="<?= APP_URL ?>/index.php?page=properties&amp;action=show&amp;id=<?= $pid ?>">
                            <?= sanitize($p['title']) ?>
                        </a>
                    </div>
                    <div class="prop-card__meta">
                        <i class="bi bi-geo-alt" aria-hidden="true"></i>
                        <?= sanitize($p['location'] ?: 'Location not set') ?>
                    </div>
                    <div class="prop-card__price"><?= $price ?></div>
                    <div class="prop-card__specs">
                        <span><i class="bi bi-door-closed" aria-hidden="true"></i> <?= (int) $p['num_rooms'] ?></span>
                        <span><i class="bi bi-droplet" aria-hidden="true"></i> <?= (int) $p['num_bathrooms'] ?></span>
                        <?php if ($p['size_sqm']): ?>
                            <span><i class="bi bi-rulers" aria-hidden="true"></i> <?= (int) $p['size_sqm'] ?> m²</span>
                        <?php endif ?>
                    </div>
                    <?php if (!empty($p['saved_at'])): ?>
                        <div class="prop-card__stats">
                            <span><i class="bi bi-bookmark-heart" aria-hidden="true"></i>
                                Saved <?= formatDate($p['saved_at']) ?></span>
                        </div>
                    <?php endif ?>
                </div>

                <div class="prop-card__foot">
                    <a class="btn btn--outline btn--sm"
                       href="<?= APP_URL ?>/index.php?page=properties&amp;action=show&amp;id=<?= $pid ?>">
                        <i class="bi bi-eye" aria-hidden="true"></i> View
                    </a>
                    <form method="POST"
                          action="<?= APP_URL ?>/index.php?page=favorites&amp;action=remove&amp;property_id=<?= $pid ?>">
                        <?= csrfField() ?>
                        <button type="submit" class="btn btn--ghost btn--sm"
                                aria-label="Remove <?= sanitize($p['title']) ?> from your shortlist">
                            <i class="bi bi-heart-fill text-danger" aria-hidden="true"></i>
                        </button>
                    </form>
                </div>
            </div>
        <?php endforeach ?>
    </div>
<?php endif ?>
