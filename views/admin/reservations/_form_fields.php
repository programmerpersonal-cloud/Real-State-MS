<?php
/**
 * Reservation form fields — shared by the full "New Reservation" page and
 * the quick-add popup, so the two can never drift apart.
 *
 * Expects:  $properties, $customers
 * Optional: $fd   form data kept back after a rejected submit
 *           $uid  id prefix for this instance's fields
 */
$uid = $uid ?? 'res';
$fd  = $fd ?? [];
?>
<div class="form-group">
    <label class="form-label" for="<?= $uid ?>-property">Property *</label>
    <select name="property_id" id="<?= $uid ?>-property" class="form-control" required>
        <option value="">— Select an available property —</option>
        <?php foreach ($properties as $p): ?>
            <option value="<?= $p['id'] ?>" <?= (int)($fd['property_id'] ?? 0) === (int)$p['id'] ? 'selected' : '' ?>>
                <?= sanitize($p['title']) ?> · <?= sanitize($p['property_code']) ?>
            </option>
        <?php endforeach ?>
    </select>
    <?php if (empty($properties)): ?>
        <div class="form-hint">No property is available to reserve right now.</div>
    <?php endif ?>
</div>

<div class="form-group">
    <label class="form-label" for="<?= $uid ?>-customer">Customer *</label>
    <select name="customer_id" id="<?= $uid ?>-customer" class="form-control" required>
        <option value="">— Select customer —</option>
        <?php foreach ($customers as $c): ?>
            <option value="<?= $c['id'] ?>" <?= (int)($fd['customer_id'] ?? 0) === (int)$c['id'] ? 'selected' : '' ?>>
                <?= sanitize($c['full_name']) ?>
            </option>
        <?php endforeach ?>
    </select>
</div>

<div class="form-grid--2">
    <div class="form-group">
        <label class="form-label" for="<?= $uid ?>-from">Reservation Date</label>
        <input type="date" id="<?= $uid ?>-from" class="form-control" name="reservation_date"
               value="<?= sanitize($fd['reservation_date'] ?? date('Y-m-d')) ?>">
    </div>
    <div class="form-group">
        <label class="form-label" for="<?= $uid ?>-until">Expires On</label>
        <input type="date" id="<?= $uid ?>-until" class="form-control" name="expiry_date"
               value="<?= sanitize($fd['expiry_date'] ?? reservationExpiryDate()) ?>">
        <div class="form-hint">Holds run <?= reservationExpiryDays() ?> days by default.</div>
    </div>
</div>

<div class="form-group">
    <label class="form-label" for="<?= $uid ?>-deposit">Deposit (optional)</label>
    <input type="number" step="0.01" min="0" id="<?= $uid ?>-deposit" class="form-control"
           name="deposit_amount" value="<?= sanitize((string)($fd['deposit_amount'] ?? '0')) ?>">
</div>

<div class="form-group">
    <label class="form-label" for="<?= $uid ?>-notes">Notes</label>
    <textarea name="notes" id="<?= $uid ?>-notes" class="form-control" rows="3"><?= sanitize($fd['notes'] ?? '') ?></textarea>
</div>

<?php
/* Booking terms. Rendered only when a version is published and acceptance is
   switched on, so an install that has not written its terms yet still takes
   bookings. Living in this shared partial means it appears in both the full
   page and the quick-add popup automatically. */
$bookingTerms = termsRequiredForBooking();
?>
<?php if ($bookingTerms): ?>
<div class="form-group">
    <h4 class="form-section">Booking terms</h4>
    <label class="form-checkbox">
        <input type="checkbox" name="terms_accepted" value="1" required
               <?= !empty($fd['terms_accepted']) ? 'checked' : '' ?>>
        <span>
            The customer has read and accepted the
            <a href="<?= APP_URL ?>/index.php?page=legal&amp;action=version&amp;id=<?= (int) $bookingTerms['id'] ?>"
               target="_blank" rel="noopener"><?= sanitize($bookingTerms['title']) ?></a>
            (<?= sanitize($bookingTerms['version_code']) ?>).
        </span>
    </label>
    <input type="hidden" name="terms_version_id" value="<?= (int) $bookingTerms['id'] ?>">
    <div class="form-hint">
        <i class="bi bi-shield-check"></i>
        Effective <?= formatDate($bookingTerms['effective_from']) ?>.
        The exact version accepted is recorded against this reservation and is never altered by later revisions.
    </div>
</div>
<?php endif ?>
