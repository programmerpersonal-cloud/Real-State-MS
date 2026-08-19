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
    <div class="card__header"><h2 class="card__title">Edit: <?= sanitize($fd['full_name']) ?></h2></div>
    <div class="card__body">
        <form method="POST" enctype="multipart/form-data" data-validate>
            <?= csrfField() ?>


            <?php require __DIR__ . '/_form_fields.php'; ?>

            <?php if (!empty($fd['account_id'])): ?>
                <?php /* Renaming here also renames the account, so the two lists keep
                         describing one person. Said plainly rather than done quietly. */ ?>
                <div class="form-hint mt-2">
                    <i class="bi bi-info-circle"></i>
                    This customer signs in as <strong><?= sanitize($fd['account_email']) ?></strong>.
                    Changes to the name and phone are applied to that account too.
                </div>
            <?php endif ?>

            <div class="form-actions">
                <a href="<?= APP_URL ?>/index.php?page=customers&amp;action=show&amp;id=<?= (int) $fd['id'] ?>" class="btn btn--outline">Cancel</a>
                <button type="submit" class="btn btn--primary"><i class="bi bi-check-lg" aria-hidden="true"></i> Save changes</button>
            </div>
        </form>
    </div>
</div>
