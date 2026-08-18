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
$errs    = $formErrors ?? [];

$err  = static fn(string $f): string => uiFieldError($errs, $f);
$bad  = static fn(string $f): string => uiInvalidClass($errs, $f);
$aria = static fn(string $f, string $hint = ''): string => uiFieldAria($errs, $f, $hint);

// Staff are logging someone else's enquiry, so their own details would be the
// wrong default.
$defaultName  = $fd['name']  ?? ($isStaff ? '' : ($currentUser['full_name'] ?? ''));
$defaultEmail = $fd['email'] ?? ($isStaff ? '' : ($currentUser['email'] ?? ''));
?>
<div class="card card--narrow">
    <div class="card__header">
        <h3 class="card__title"><?= $isStaff ? 'Log an enquiry' : 'Send an enquiry' ?></h3>
        <span class="text-subtle"><?= $isStaff ? 'Taken by phone or at the counter' : 'The office is notified straight away' ?></span>
    </div>
    <div class="card__body">
        <form method="post" data-validate>
            <?= csrfField() ?>

            <?php require VIEWS_PATH . '/components/ui/error_summary.php'; ?>

            <div class="form-group">
                <label class="form-label" for="inq-property">Property</label>
                <select class="form-control<?= $bad('property_id') ?>" id="inq-property" name="property_id"
                        <?= $aria('property_id', 'inq-property-hint') ?>>
                    <option value="">General enquiry — not about a specific property</option>
                    <?php foreach ($properties as $p): ?>
                        <option value="<?= (int) $p['id'] ?>"
                            <?= (string) ($fd['property_id'] ?? '') === (string) $p['id'] ? 'selected' : '' ?>>
                            <?= sanitize($p['title']) ?><?= $p['property_code'] ? ' — ' . sanitize($p['property_code']) : '' ?>
                        </option>
                    <?php endforeach ?>
                </select>
                <?= $err('property_id') ?>
                <div class="form-hint" id="inq-property-hint">
                    Naming the property routes the message to the agent who handles it.
                </div>
            </div>

            <div class="form-grid--2">
                <div class="form-group">
                    <label class="form-label" for="inq-name">Name <span class="req" aria-hidden="true">*</span></label>
                    <input class="form-control<?= $bad('name') ?>" id="inq-name" name="name" required
                           autocomplete="name" value="<?= sanitize($defaultName) ?>"<?= $aria('name') ?>>
                    <?= $err('name') ?>
                </div>

    <?php $phoneField = ['name' => 'phone', 'id' => 'inq-phone', 'label' => 'Phone', 'value' => $fd['phone'] ?? ''];
          require VIEWS_PATH . '/components/ui/phone_field.php'; ?>
            </div>

            <div class="form-group">
                <label class="form-label" for="inq-email">Email <span class="req" aria-hidden="true">*</span></label>
                <input type="email" class="form-control<?= $bad('email') ?>" id="inq-email" name="email" required
                       autocomplete="email" value="<?= sanitize($defaultEmail) ?>"<?= $aria('email', 'inq-email-hint') ?>>
                <?= $err('email') ?>
                <div class="form-hint" id="inq-email-hint">Replies are sent here and appear on the enquiry thread.</div>
            </div>

            <div class="form-group">
                <label class="form-label" for="inq-subject">Subject</label>
                <input class="form-control<?= $bad('subject') ?>" id="inq-subject" name="subject"
                       placeholder="Viewing request, lease question…"
                       value="<?= sanitize($fd['subject'] ?? '') ?>"<?= $aria('subject') ?>>
                <?= $err('subject') ?>
            </div>

            <div class="form-group">
                <label class="form-label" for="inq-message">Message <span class="req" aria-hidden="true">*</span></label>
                <textarea class="form-control<?= $bad('message') ?>" id="inq-message" name="message" rows="6" required
                          placeholder="What would you like to ask?"<?= $aria('message') ?>><?= sanitize($fd['message'] ?? '') ?></textarea>
                <?= $err('message') ?>
            </div>

            <div class="form-actions">
                <a href="<?= APP_URL ?>/index.php?page=inquiries" class="btn btn--outline">Cancel</a>
                <button type="submit" class="btn btn--primary">
                    <i class="bi bi-send" aria-hidden="true"></i> Send enquiry
                </button>
            </div>
        </form>
    </div>
</div>
