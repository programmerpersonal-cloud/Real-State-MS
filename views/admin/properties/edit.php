<?php
/**
 * Property — edit.
 *
 * Renders the same shared field set as the create page and the quick-add
 * popup. It used to carry its own hand-written copy of every control, which
 * had drifted: no section headings, no field errors, a different set of
 * assignment fields, and a native confirm() on the image delete.
 *
 * Edit-only additions: the status field, and the images already on file.
 */
$fd  = $formData;
$uid = 'pe';
$showStatus = true;

$pageTitle   = 'Edit Property';
$breadcrumbs = [
    ['label' => 'Properties', 'url' => APP_URL . '/index.php?page=properties'],
    ['label' => sanitize($fd['title']), 'url' => APP_URL . '/index.php?page=properties&action=show&id=' . (int) $fd['id']],
    ['label' => 'Edit'],
];

$showUrl = APP_URL . '/index.php?page=properties&action=show&id=' . (int) $fd['id'];
?>

<div class="card">
    <div class="card__header">
        <h3 class="card__title">Edit <?= sanitize($fd['title']) ?></h3>
        <span class="table__id"><?= sanitize($fd['property_code']) ?></span>
    </div>

    <div class="card__body">
        <form method="POST" enctype="multipart/form-data" data-validate>
            <?= csrfField() ?>

            <?php /* Server-side rejections first, above the fields they refer to.
                     The client-side twin is built by components.js when a submit
                     is stopped in the browser. */ ?>
            <?php require VIEWS_PATH . '/components/ui/error_summary.php'; ?>

            <?php require __DIR__ . '/_form_fields.php'; ?>

            <?php if (!empty($images)): ?>
                <h4 class="form-section">Images on file</h4>
                <div class="gallery">
                    <?php foreach ($images as $img): ?>
                        <div class="gallery__item">
                            <img src="<?= APP_URL . '/' . sanitize($img['file_path']) ?>"
                                 alt="" loading="lazy" width="180" height="180">
                            <?php if ($img['is_cover']): ?>
                                <span class="gallery__cover-badge">Cover</span>
                            <?php endif ?>
                            <?php if (can('properties.delete-image')): ?>
                                <a class="gallery__remove"
                                   href="<?= APP_URL ?>/index.php?page=properties&amp;action=delete-image&amp;id=<?= (int) $fd['id'] ?>&amp;img_id=<?= (int) $img['id'] ?>"
                                   aria-label="Delete this image"
                                   data-confirm="The file is removed from the server. This cannot be undone."
                                   data-confirm-title="Delete this image?"
                                   data-confirm-action="Delete image"
                                   data-confirm-record="<?= sanitize(basename((string) $img['file_path'])) ?>">
                                    <i class="bi bi-trash" aria-hidden="true"></i>
                                </a>
                            <?php endif ?>
                        </div>
                    <?php endforeach ?>
                </div>
            <?php endif ?>

            <div class="form-actions">
                <button type="submit" class="btn btn--primary">
                    <i class="bi bi-check-lg" aria-hidden="true"></i> Save changes
                </button>
                <a href="<?= $showUrl ?>" class="btn btn--outline">Cancel</a>
            </div>
        </form>
    </div>
</div>
