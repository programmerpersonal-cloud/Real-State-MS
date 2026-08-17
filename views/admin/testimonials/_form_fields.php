<?php
/**
 * Testimonial fields — shared by the add/edit page and the quick-add
 * popup, so the entry points can never drift apart.
 *
 * Optional: $t     existing testimonial, or entry kept after a rejected save
 *           $errs  field-keyed errors from the same rejection
 *           $uid   id prefix for this instance's fields
 */
$uid  = $uid ?? 'tst';
$t    = $t ?? [];
$errs = $errs ?? ($formErrors ?? []);
$rate = (int) ($t['rating'] ?? 5);

$err  = static fn(string $f): string => uiFieldError($errs, $f);
$bad  = static fn(string $f): string => uiInvalidClass($errs, $f);
$aria = static fn(string $f, string $hint = ''): string => uiFieldAria($errs, $f, $hint);
?>
<div class="form-grid--2">
    <div class="form-group">
        <label class="form-label" for="<?= $uid ?>-name">Author name <span class="req" aria-hidden="true">*</span></label>
        <input class="form-control<?= $bad('author_name') ?>" id="<?= $uid ?>-name" name="author_name" required
               value="<?= sanitize($t['author_name'] ?? '') ?>"
               placeholder="The customer's name"<?= $aria('author_name') ?>>
        <?= $err('author_name') ?>
    </div>

    <div class="form-group">
        <label class="form-label" for="<?= $uid ?>-role">Role or context</label>
        <input class="form-control<?= $bad('author_role') ?>" id="<?= $uid ?>-role" name="author_role"
               value="<?= sanitize($t['author_role'] ?? '') ?>"
               placeholder="e.g. Tenant · Borama"<?= $aria('author_role', $uid . '-role-hint') ?>>
        <?= $err('author_role') ?>
        <p class="form-hint" id="<?= $uid ?>-role-hint">Shown under the name. Leave blank for &ldquo;Verified customer&rdquo;.</p>
    </div>
</div>

<div class="form-group">
    <label class="form-label" for="<?= $uid ?>-rating">Rating</label>
    <select class="form-control<?= $bad('rating') ?>" id="<?= $uid ?>-rating" name="rating"
            <?= $aria('rating', $uid . '-rating-hint') ?>>
        <?php for ($i = 5; $i >= 1; $i--): ?>
            <option value="<?= $i ?>" <?= $rate === $i ? 'selected' : '' ?>>
                <?= str_repeat('★', $i) . str_repeat('☆', 5 - $i) ?> — <?= $i ?> out of 5
            </option>
        <?php endfor ?>
    </select>
    <?= $err('rating') ?>
    <p class="form-hint" id="<?= $uid ?>-rating-hint">
        Once published this counts towards the average shown to search engines.
    </p>
</div>

<div class="form-group">
    <label class="form-label" for="<?= $uid ?>-body">Review <span class="req" aria-hidden="true">*</span></label>
    <textarea class="form-control<?= $bad('body') ?>" id="<?= $uid ?>-body" name="body" rows="5" required
              placeholder="In the customer's own words."<?= $aria('body') ?>><?= sanitize($t['body'] ?? '') ?></textarea>
    <?= $err('body') ?>
</div>

<h4 class="form-section">Optional links</h4>

<div class="form-grid--3">
    <div class="form-group">
        <label class="form-label" for="<?= $uid ?>-prop">Property ID</label>
        <input class="form-control<?= $bad('property_id') ?>" id="<?= $uid ?>-prop" name="property_id"
               type="number" min="0" value="<?= (int) ($t['property_id'] ?? 0) ?: '' ?>"
               placeholder="Optional"<?= $aria('property_id') ?>>
        <?= $err('property_id') ?>
    </div>
    <div class="form-group">
        <label class="form-label" for="<?= $uid ?>-agent">Agent user ID</label>
        <input class="form-control<?= $bad('agent_id') ?>" id="<?= $uid ?>-agent" name="agent_id"
               type="number" min="0" value="<?= (int) ($t['agent_id'] ?? 0) ?: '' ?>"
               placeholder="Optional"<?= $aria('agent_id') ?>>
        <?= $err('agent_id') ?>
    </div>
    <div class="form-group">
        <label class="form-label" for="<?= $uid ?>-sort">Sort order</label>
        <input class="form-control<?= $bad('sort_order') ?>" id="<?= $uid ?>-sort" name="sort_order"
               type="number" value="<?= (int) ($t['sort_order'] ?? 0) ?>"<?= $aria('sort_order', $uid . '-sort-hint') ?>>
        <?= $err('sort_order') ?>
        <p class="form-hint" id="<?= $uid ?>-sort-hint">Lower numbers appear first.</p>
    </div>
</div>

<div class="check-row">
    <label class="check">
        <input type="checkbox" id="<?= $uid ?>-approved" name="is_approved" value="1"
               <?= !empty($t['is_approved']) ? 'checked' : '' ?>>
        <span>Publish on the public site</span>
    </label>
</div>
<div class="form-hint">
    Approved reviews appear on the home page under this person's name and feed the
    site's aggregate star rating. Leave unchecked to save it as a draft.
</div>
