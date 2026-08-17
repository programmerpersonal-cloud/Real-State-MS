<?php
/**
 * Testimonial fields — shared by the add/edit page and the quick-add
 * popup, so the entry points can never drift apart.
 *
 * Optional: $t    existing testimonial, or entry kept after a rejected save
 *           $uid  id prefix for this instance's fields
 */
$uid  = $uid ?? 'tst';
$t    = $t ?? [];
$rate = (int) ($t['rating'] ?? 5);
?>
<div class="form-grid form-grid--2">
    <div class="form-group">
        <label class="form-label" for="<?= $uid ?>-name">Author name <span class="text-danger">*</span></label>
        <input class="form-control" id="<?= $uid ?>-name" name="author_name" required
               value="<?= sanitize($t['author_name'] ?? '') ?>"
               placeholder="The customer's name">
    </div>

    <div class="form-group">
        <label class="form-label" for="<?= $uid ?>-role">Role / context</label>
        <input class="form-control" id="<?= $uid ?>-role" name="author_role"
               value="<?= sanitize($t['author_role'] ?? '') ?>"
               placeholder="e.g. Tenant · Borama">
        <p class="form-hint">Shown under the name. Leave blank for "Verified customer".</p>
    </div>
</div>

<div class="form-group">
    <label class="form-label" for="<?= $uid ?>-rating">Rating</label>
    <select class="form-control" id="<?= $uid ?>-rating" name="rating">
        <?php for ($i = 5; $i >= 1; $i--): ?>
            <option value="<?= $i ?>" <?= $rate === $i ? 'selected' : '' ?>>
                <?= str_repeat('★', $i) . str_repeat('☆', 5 - $i) ?> — <?= $i ?> out of 5
            </option>
        <?php endfor; ?>
    </select>
</div>

<div class="form-group">
    <label class="form-label" for="<?= $uid ?>-body">Review <span class="text-danger">*</span></label>
    <textarea class="form-control" id="<?= $uid ?>-body" name="body" rows="5" required
              placeholder="In the customer's own words."><?= sanitize($t['body'] ?? '') ?></textarea>
</div>

<div class="form-grid form-grid--3">
    <div class="form-group">
        <label class="form-label" for="<?= $uid ?>-prop">Related property ID</label>
        <input class="form-control" id="<?= $uid ?>-prop" name="property_id" type="number" min="0"
               value="<?= (int) ($t['property_id'] ?? 0) ?: '' ?>" placeholder="Optional">
    </div>
    <div class="form-group">
        <label class="form-label" for="<?= $uid ?>-agent">Related agent (user ID)</label>
        <input class="form-control" id="<?= $uid ?>-agent" name="agent_id" type="number" min="0"
               value="<?= (int) ($t['agent_id'] ?? 0) ?: '' ?>" placeholder="Optional">
    </div>
    <div class="form-group">
        <label class="form-label" for="<?= $uid ?>-sort">Sort order</label>
        <input class="form-control" id="<?= $uid ?>-sort" name="sort_order" type="number"
               value="<?= (int) ($t['sort_order'] ?? 0) ?>">
        <p class="form-hint">Lower numbers appear first.</p>
    </div>
</div>

<div class="form-checkbox">
    <input type="checkbox" id="<?= $uid ?>-approved" name="is_approved" value="1"
           <?= !empty($t['is_approved']) ? 'checked' : '' ?>>
    <label for="<?= $uid ?>-approved">
        Publish on the public site
        <span class="form-hint" style="display:block">
            Approved reviews appear on the home page and feed the site's aggregate
            star rating. Leave unchecked to save as a draft.
        </span>
    </label>
</div>
