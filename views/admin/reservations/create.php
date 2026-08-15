<?php
/**
 * Reservations — Create
 */
?>
<div class="card" style="max-width:600px;margin:0 auto">
    <div class="card__header"><h3 class="card__title">New Reservation</h3></div>
    <div class="card__body">
        <form method="post" data-validate>
            <?= csrfField() ?>
            <div class="form-group">
                <label class="form-label">Property *</label>
                <select name="property_id" class="form-control" required>
                    <option value="">— Select an available property —</option>
                    <?php foreach ($properties as $p): ?>
                        <option value="<?= $p['id'] ?>"><?= sanitize($p['title']) ?> · <?= sanitize($p['property_code']) ?></option>
                    <?php endforeach ?>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">Customer *</label>
                <select name="customer_id" class="form-control" required>
                    <option value="">— Select customer —</option>
                    <?php foreach ($customers as $c): ?>
                        <option value="<?= $c['id'] ?>"><?= sanitize($c['full_name']) ?></option>
                    <?php endforeach ?>
                </select>
            </div>
            <div class="form-grid--2">
                <div class="form-group">
                    <label class="form-label">Reservation Date</label>
                    <input type="date" class="form-control" name="reservation_date" value="<?= date('Y-m-d') ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">Expires On</label>
                    <input type="date" class="form-control" name="expiry_date" value="<?= reservationExpiryDate() ?>">
                </div>
            </div>
            <div class="form-group">
                <label class="form-label">Deposit (optional)</label>
                <input type="number" step="0.01" class="form-control" name="deposit_amount" value="0">
            </div>
            <div class="form-group">
                <label class="form-label">Notes</label>
                <textarea name="notes" class="form-control" rows="3"></textarea>
            </div>
            <div class="form-actions">
                <a href="<?= APP_URL ?>/index.php?page=reservations" class="btn btn--outline">Cancel</a>
                <button type="submit" class="btn btn--primary"><i class="bi bi-check-lg"></i> Reserve</button>
            </div>
        </form>
    </div>
</div>
