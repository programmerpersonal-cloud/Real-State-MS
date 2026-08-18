<?php
/**
 * Sales — Show
 */
$s = $sale;
?>
<div class="card measure-md" >
    <div class="card__header">
        <h3 class="card__title">Sale <?= sanitize($s['sale_code']) ?></h3>
        <span class="badge <?= getStatusBadgeClass($s['status']) ?>"><?= ucfirst($s['status']) ?></span>
    </div>
    <div class="card__body">
        <div class="profile-meta">
            <div class="profile-meta__row"><span class="profile-meta__label">Property</span><span class="profile-meta__value"><a href="<?= APP_URL ?>/index.php?page=properties&action=show&id=<?= $s['property_id'] ?>"><?= sanitize($s['property_title']) ?></a></span></div>
            <div class="profile-meta__row"><span class="profile-meta__label">Buyer</span><span class="profile-meta__value"><a href="<?= APP_URL ?>/index.php?page=customers&action=show&id=<?= $s['customer_id'] ?>"><?= sanitize($s['customer_name']) ?></a></span></div>
            <div class="profile-meta__row"><span class="profile-meta__label">Sale Amount</span><span class="profile-meta__value"><?= formatCurrency((float)$s['sale_amount']) ?></span></div>
            <?php if ((float)($s['tax_amount'] ?? 0) > 0): ?>
            <div class="profile-meta__row"><span class="profile-meta__label">Tax<?= (float)($s['tax_rate'] ?? 0) > 0 ? ' (' . rtrim(rtrim(number_format((float)$s['tax_rate'], 2), '0'), '.') . '%)' : '' ?></span><span class="profile-meta__value"><?= formatCurrency((float)$s['tax_amount']) ?></span></div>
            <div class="profile-meta__row"><span class="profile-meta__label">Buyer Total</span><span class="profile-meta__value"><strong><?= formatCurrency((float)$s['sale_amount'] + (float)$s['tax_amount']) ?></strong></span></div>
            <?php endif ?>
            <div class="profile-meta__row"><span class="profile-meta__label">Commission</span><span class="profile-meta__value"><?= formatCurrency((float)$s['commission_amount']) ?></span></div>
            <div class="profile-meta__row"><span class="profile-meta__label">Payment Type</span><span class="profile-meta__value"><?= ucfirst($s['payment_type']) ?></span></div>
            <div class="profile-meta__row"><span class="profile-meta__label">Sale Date</span><span class="profile-meta__value"><?= formatDate($s['sale_date']) ?></span></div>
            <?php if ($s['agent_name']): ?>
            <div class="profile-meta__row"><span class="profile-meta__label">Agent</span><span class="profile-meta__value"><?= sanitize($s['agent_name']) ?></span></div>
            <?php endif ?>
        </div>
        <?php if ($s['notes']): ?>
            <div class="section-title">Notes</div>
            <div class="prose"><?= nl2br(sanitize($s['notes'])) ?></div>
        <?php endif ?>
    </div>
</div>
