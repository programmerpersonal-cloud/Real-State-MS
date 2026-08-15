<?php
/**
 * Testimonials — Add / Edit (admin)
 */
$t    = $testimonial ?? null;
$id   = (int) ($t['id'] ?? 0);
$rate = (int) ($t['rating'] ?? 5);
?>

<form method="POST" action="<?= APP_URL ?>/index.php?page=testimonials&action=save">
    <?= csrfField() ?>
    <input type="hidden" name="id" value="<?= $id ?>">

    <div class="card">
        <div class="card__header">
            <h3 class="card__title"><?= $id ? 'Edit review' : 'Add a review' ?></h3>
            <p class="card__subtitle">
                Record what the customer actually said. Publishing invented reviews alongside
                star-rating markup risks a search penalty and misleads buyers.
            </p>
        </div>

        <div class="card__body">
            <div class="form-grid form-grid--2">
                <div class="form-group">
                    <label class="form-label" for="t-name">Author name <span class="text-danger">*</span></label>
                    <input class="form-control" id="t-name" name="author_name" required
                           value="<?= sanitize($t['author_name'] ?? '') ?>"
                           placeholder="The customer's name">
                </div>

                <div class="form-group">
                    <label class="form-label" for="t-role">Role / context</label>
                    <input class="form-control" id="t-role" name="author_role"
                           value="<?= sanitize($t['author_role'] ?? '') ?>"
                           placeholder="e.g. Tenant · Borama">
                    <p class="form-hint">Shown under the name. Leave blank for "Verified customer".</p>
                </div>
            </div>

            <div class="form-group">
                <label class="form-label" for="t-rating">Rating</label>
                <select class="form-control" id="t-rating" name="rating">
                    <?php for ($i = 5; $i >= 1; $i--): ?>
                        <option value="<?= $i ?>" <?= $rate === $i ? 'selected' : '' ?>>
                            <?= str_repeat('★', $i) . str_repeat('☆', 5 - $i) ?> — <?= $i ?> out of 5
                        </option>
                    <?php endfor; ?>
                </select>
            </div>

            <div class="form-group">
                <label class="form-label" for="t-body">Review <span class="text-danger">*</span></label>
                <textarea class="form-control" id="t-body" name="body" rows="5" required
                          placeholder="In the customer's own words."><?= sanitize($t['body'] ?? '') ?></textarea>
            </div>

            <div class="form-grid form-grid--3">
                <div class="form-group">
                    <label class="form-label" for="t-prop">Related property ID</label>
                    <input class="form-control" id="t-prop" name="property_id" type="number" min="0"
                           value="<?= (int) ($t['property_id'] ?? 0) ?: '' ?>" placeholder="Optional">
                </div>
                <div class="form-group">
                    <label class="form-label" for="t-agent">Related agent (user ID)</label>
                    <input class="form-control" id="t-agent" name="agent_id" type="number" min="0"
                           value="<?= (int) ($t['agent_id'] ?? 0) ?: '' ?>" placeholder="Optional">
                </div>
                <div class="form-group">
                    <label class="form-label" for="t-sort">Sort order</label>
                    <input class="form-control" id="t-sort" name="sort_order" type="number"
                           value="<?= (int) ($t['sort_order'] ?? 0) ?>">
                    <p class="form-hint">Lower numbers appear first.</p>
                </div>
            </div>

            <div class="form-checkbox">
                <input type="checkbox" id="t-approved" name="is_approved" value="1"
                       <?= !empty($t['is_approved']) ? 'checked' : '' ?>>
                <label for="t-approved">
                    Publish on the public site
                    <span class="form-hint" style="display:block">
                        Approved reviews appear on the home page and feed the site's aggregate
                        star rating. Leave unchecked to save as a draft.
                    </span>
                </label>
            </div>
        </div>

        <div class="card__footer">
            <div class="form-actions">
                <a class="btn btn--ghost" href="<?= APP_URL ?>/index.php?page=testimonials">Cancel</a>
                <button class="btn btn--primary" type="submit">
                    <i class="bi bi-check-lg"></i> <?= $id ? 'Save changes' : 'Add review' ?>
                </button>
            </div>
        </div>
    </div>
</form>
