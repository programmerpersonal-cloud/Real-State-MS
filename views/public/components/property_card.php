<?php
/**
 * Property Card — the primary discovery component.
 *
 * Rendered via renderPropertyCard() in includes/functions.php, which
 * supplies: $property, $cover, $savedIds, $eager.
 *
 * Interaction model
 * -----------------
 * The title link carries a stretched ::after so the whole card is
 * clickable, while the favourite button sits above it in z-order and
 * stays independently focusable. That gives mouse users a big target
 * and keyboard/screen-reader users exactly two sensible stops per card
 * (open the listing, save the listing) instead of five.
 *
 * The favourite control is a real POST form against the existing
 * FavoritesController, so it works with JavaScript disabled.
 */

$pid      = (int) ($property['id'] ?? 0);
$price    = propertyPrice($property);
// Falls back to seeded stock photography, so a card is never a bare icon.
$imgUrl   = propertyImage($property, $cover);
$photoNum = (int) ($cover['total'] ?? 0);
$isSaved  = in_array($pid, $savedIds, true);
$status   = (string) ($property['status'] ?? 'available');
$category = (string) ($property['category'] ?? '');
$loading  = $eager ? 'eager' : 'lazy';

// Escaped for use directly in href attributes.
$detailUrl = sanitize(APP_URL . '/index.php?page=listing&id=' . $pid);
$title     = $property['title'] ?: 'Untitled property';
?>
<article class="pcard">

    <div class="pcard__media">
        <img src="<?= sanitize($imgUrl) ?>"
             alt="<?= sanitize($title) ?><?= $property['location'] ? ' in ' . sanitize($property['location']) : '' ?>"
             loading="<?= $loading ?>" decoding="async"
             width="640" height="480">

        <div class="pcard__tags">
            <span class="tag tag--glass"><?= $price['label'] ?></span>
            <?php if ($status !== 'available'): ?>
                <span class="tag <?= statusTagClass($status) ?>"><?= ucfirst(sanitize($status)) ?></span>
            <?php endif; ?>
        </div>

        <?php if ($photoNum > 1): ?>
            <span class="pcard__count">
                <i class="bi bi-images" aria-hidden="true"></i><?= $photoNum ?>
            </span>
        <?php endif; ?>

        <?php if (isLoggedIn()): ?>
            <?php $favAction = $isSaved ? 'remove' : 'add'; ?>
            <form method="POST"
                  action="<?= APP_URL ?>/index.php?page=favorites&amp;action=<?= $favAction ?>&amp;property_id=<?= $pid ?>"
                  style="display:contents">
                <button type="submit"
                        class="pcard__fav <?= $isSaved ? 'is-saved' : '' ?>"
                        aria-pressed="<?= $isSaved ? 'true' : 'false' ?>"
                        title="<?= $isSaved ? 'Remove from saved' : 'Save this property' ?>"
                        aria-label="<?= $isSaved ? 'Remove' : 'Save' ?> <?= sanitize($title) ?> <?= $isSaved ? 'from' : 'to' ?> saved properties">
                    <i class="bi bi-heart" aria-hidden="true"></i>
                </button>
            </form>
        <?php else: ?>
            <a href="<?= APP_URL ?>/index.php?page=login" class="pcard__fav"
               title="Sign in to save properties"
               aria-label="Sign in to save <?= sanitize($title) ?>">
                <i class="bi bi-heart" aria-hidden="true"></i>
            </a>
        <?php endif; ?>
    </div>

    <div class="pcard__body">
        <p class="pcard__price">
            <?= formatCurrency($price['amount']) ?>
            <?php if ($price['suffix']): ?><small><?= $price['suffix'] ?></small><?php endif; ?>
        </p>

        <h3 class="pcard__title">
            <a href="<?= $detailUrl ?>"><?= sanitize($title) ?></a>
        </h3>

        <p class="pcard__loc">
            <i class="bi bi-geo-alt" aria-hidden="true"></i>
            <span><?= sanitize($property['location'] ?: 'Location on request') ?></span>
        </p>

        <?php /* List view only — hidden by CSS in the grid layout. */ ?>
        <?php if (!empty($property['description'])): ?>
            <p class="pcard__desc"><?= sanitize($property['description']) ?></p>
        <?php endif; ?>

        <?php /* Abbreviations are fine visually; aria-label spells them out. */ ?>
        <div class="pcard__specs">
            <span aria-label="<?= (int) $property['num_rooms'] ?> bedrooms">
                <i class="bi bi-door-open" aria-hidden="true"></i><?= (int) $property['num_rooms'] ?> bd
            </span>
            <span aria-label="<?= (int) $property['num_bathrooms'] ?> bathrooms">
                <i class="bi bi-droplet" aria-hidden="true"></i><?= (int) $property['num_bathrooms'] ?> ba
            </span>
            <?php if (!empty($property['size_sqm'])): ?>
                <span aria-label="<?= number_format((float) $property['size_sqm']) ?> square metres">
                    <i class="bi bi-rulers" aria-hidden="true"></i><?= number_format((float) $property['size_sqm']) ?> m²
                </span>
            <?php endif; ?>
            <?php if ($category): ?>
                <span aria-label="Property type: <?= sanitize($category) ?>">
                    <i class="<?= categoryIcon($category) ?>" aria-hidden="true"></i><?= ucfirst(sanitize($category)) ?>
                </span>
            <?php endif; ?>
        </div>
    </div>
</article>
