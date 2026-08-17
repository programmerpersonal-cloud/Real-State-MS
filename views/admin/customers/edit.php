<?php
/**
 * Customer — Edit
 *
 * Account/login access is managed from the customer's detail page, so this form
 * stays purely the business record. It shares _form_fields.php with the create
 * page and the quick-add popup, so a field added in one appears in all three —
 * and the duplicate-identity checks apply identically to an edit.
 */
$pageTitle = 'Edit Customer';
$breadcrumbs = [['label' => 'Customers', 'url' => APP_URL . '/index.php?page=customers'], ['label' => $formData['full_name']]];
$fd  = $formData;
$uid = 'ce';
$showAccount = false;
?>
<div class="card">
    <div class="card__header"><h3 class="card__title">Edit: <?= sanitize($fd['full_name']) ?></h3></div>
    <div class="card__body">
        <form method="POST" enctype="multipart/form-data" data-validate>
            <?= csrfField() ?>

            <?php require __DIR__ . '/_form_fields.php'; ?>

            <?php if (!empty($fd['account_id'])): ?>
                <?php /* Renaming here also renames the account, so the two lists keep
                         describing one person. Said plainly rather than done quietly. */ ?>
                <div class="form-hint" style="margin-top:14px">
                    <i class="bi bi-info-circle"></i>
                    This customer signs in as <strong><?= sanitize($fd['account_email']) ?></strong>.
                    Changes to the name and phone are applied to that account too.
                </div>
            <?php endif ?>

            <div style="display:flex;gap:12px;margin-top:28px">
                <button type="submit" class="btn btn--primary btn--lg"><i class="bi bi-check-lg"></i> Save Changes</button>
                <a href="<?= APP_URL ?>/index.php?page=customers&action=show&id=<?= $fd['id'] ?>" class="btn btn--outline btn--lg">Cancel</a>
            </div>
        </form>
    </div>
</div>
