<?php
/**
 * Inquiries — Create
 *
 * Reached by a tenant asking the office something, and by staff logging an
 * enquiry that arrived by phone or at the counter. The contact fields are
 * prefilled from the signed-in account and left editable, because those two
 * cases disagree about whose details belong in them: a tenant is writing about
 * themselves, a member of staff is writing down someone else.
 *
 * Expects: $properties (available listings, may be empty)
 */
$isStaff = can('inquiries.reply');
$fd      = $_SESSION['form_data'] ?? [];
unset($_SESSION['form_data']);

// Staff are logging someone else's enquiry, so their own details would be the
// wrong default.
$defaultName  = $fd['name']  ?? ($isStaff ? '' : ($currentUser['full_name'] ?? ''));
$defaultEmail = $fd['email'] ?? ($isStaff ? '' : ($currentUser['email'] ?? ''));
?>
<div class="card" style="max-width:680px;margin:0 auto">
    <div class="card__header">
        <h3 class="card__title"><?= $isStaff ? 'Log an Inquiry' : 'Send an Inquiry' ?></h3>
    </div>
    <div class="card__body">
        <form method="post" data-validate>
            <?= csrfField() ?>

            <div class="form-group">
                <label class="form-label" for="inq-property">Property</label>
                <select class="form-control" id="inq-property" name="property_id">
                    <option value="">General enquiry — not about a specific property</option>
                    <?php foreach ($properties as $p): ?>
                        <option value="<?= (int) $p['id'] ?>"
                            <?= (string) ($fd['property_id'] ?? '') === (string) $p['id'] ? 'selected' : '' ?>>
                            <?= sanitize($p['title']) ?><?= $p['property_code'] ? ' — ' . sanitize($p['property_code']) : '' ?>
                        </option>
                    <?php endforeach ?>
                </select>
                <div class="form-hint">Naming the property routes your message to the agent who handles it.</div>
            </div>

            <div class="form-group">
                <label class="form-label" for="inq-name">Name <span aria-hidden="true">*</span></label>
                <input class="form-control" id="inq-name" name="name" required
                       value="<?= sanitize($defaultName) ?>">
            </div>

            <div class="form-group">
                <label class="form-label" for="inq-email">Email <span aria-hidden="true">*</span></label>
                <input type="email" class="form-control" id="inq-email" name="email" required
                       value="<?= sanitize($defaultEmail) ?>">
                <div class="form-hint">Replies are sent here and appear on the enquiry thread.</div>
            </div>

            <div class="form-group">
                <label class="form-label" for="inq-phone">Phone</label>
                <input class="form-control" id="inq-phone" name="phone"
                       value="<?= sanitize($fd['phone'] ?? '') ?>">
            </div>

            <div class="form-group">
                <label class="form-label" for="inq-subject">Subject</label>
                <input class="form-control" id="inq-subject" name="subject"
                       placeholder="Viewing request, lease question…"
                       value="<?= sanitize($fd['subject'] ?? '') ?>">
            </div>

            <div class="form-group">
                <label class="form-label" for="inq-message">Message <span aria-hidden="true">*</span></label>
                <textarea class="form-control" id="inq-message" name="message" rows="6" required
                          placeholder="What would you like to ask?"><?= sanitize($fd['message'] ?? '') ?></textarea>
            </div>

            <div class="form-actions">
                <a href="<?= APP_URL ?>/index.php?page=inquiries" class="btn btn--outline">Cancel</a>
                <button type="submit" class="btn btn--primary"><i class="bi bi-send"></i> Send Inquiry</button>
            </div>
        </form>
    </div>
</div>
