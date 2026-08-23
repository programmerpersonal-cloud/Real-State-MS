<?php
$pageTitle = 'Add Property';
$breadcrumbs = [['label' => 'Properties', 'url' => APP_URL . '/index.php?page=properties'], ['label' => 'Add New']];
$fd = $formData ?? [];
$uid = 'pc';
?>

<div class="card">
    <div class="card__header">
        <h2 class="card__title">Property details</h2>
        <?php /* What happens to this person's listing on save, not a general
                 rule — an administrator publishes directly, an agent submits
                 for review, and only one of those is true for the reader. */ ?>
        <span class="text-subtle">
            <?= hasRole(ROLE_ADMIN)
                ? 'Published on save — listings you create are approved automatically'
                : 'Submitted for review — an administrator approves it before it goes live' ?>
        </span>
    </div>
    <div class="card__body">
        <form method="POST" enctype="multipart/form-data" data-validate>
            <?= csrfField() ?>


            <?php require __DIR__ . '/_form_fields.php'; ?>

            <div class="form-actions">
                <button type="submit" class="btn btn--primary">
                    <i class="bi bi-check-lg" aria-hidden="true"></i> Create property
                </button>
                <a href="<?= APP_URL ?>/index.php?page=properties" class="btn btn--outline">Cancel</a>
            </div>
        </form>
    </div>
</div>
