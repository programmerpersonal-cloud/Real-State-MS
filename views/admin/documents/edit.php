<?php
/**
 * Documents — Edit
 *
 * Metadata only. The stored file is immutable: replacing it would leave the
 * audit trail claiming one thing was downloaded when a different file was
 * served, so a new file means a new document.
 *
 * Expects: $doc, $formData, $categories, $categoryMeta, $properties
 */
$fd     = $formData ?? $doc;
$uid    = 'de';
$isEdit = true;
$state  = documentStatus($doc);
$actionButton = [
    'label' => 'View document',
    'icon'  => 'bi-eye',
    'class' => 'btn--outline',
    'url'   => APP_URL . '/index.php?page=documents&action=show&id=' . (int) $doc['id'],
];
?>
<div class="card">
    <div class="card__header">
        <h2 class="card__title"><?= sanitize($doc['title']) ?></h2>
        <?= uiStatus($state['key'], $state['label']) ?>
    </div>

    <div class="card__body">
        <div class="alert alert--info mb-2">
            <i class="bi bi-info-circle"></i>
            <div>
                The file itself cannot be swapped out — <code><?= sanitize($doc['file_name']) ?></code>
                (<?= sanitize(fileTypeLabel($doc['file_type'] ?? '')) ?>, <?= formatBytes((int) $doc['file_size']) ?>)
                stays as uploaded, so the download history always refers to the same bytes.
                To supersede it, upload a replacement and archive this one.
            </div>
        </div>

        <form method="POST" data-validate
              action="<?= APP_URL ?>/index.php?page=documents&amp;action=edit&amp;id=<?= (int) $doc['id'] ?>">
            <?= csrfField() ?>
            <?php
                $fixedProperty = null;
                require __DIR__ . '/_form_fields.php';
            ?>
            <div class="form-actions">
                <button type="submit" class="btn btn--primary"><i class="bi bi-check-lg"></i> Save changes</button>
                <a class="btn btn--outline" href="<?= APP_URL ?>/index.php?page=documents&amp;action=show&amp;id=<?= (int) $doc['id'] ?>">Cancel</a>
            </div>
        </form>
    </div>
</div>
