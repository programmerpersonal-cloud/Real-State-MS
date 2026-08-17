<?php
/**
 * Public documents for a listing.
 *
 * Only ever receives rows the route already restricted to visibility='public'
 * and status='active' on an approved, unarchived property. Every link goes to
 * the same authorised endpoint the admin side uses, so a document that is later
 * made private stops downloading immediately.
 *
 * Expects: $publicDocuments
 */
if (empty($publicDocuments)) return;
?>
<ul class="doc-list">
    <?php foreach ($publicDocuments as $d): ?>
        <li class="doc-list__item">
            <i class="bi <?= sanitize(fileTypeIcon($d['file_type'] ?? '')) ?> doc-list__icon"></i>
            <div class="doc-list__body">
                <span class="doc-list__name"><?= sanitize($d['title']) ?></span>
                <span class="doc-list__meta">
                    <?= sanitize(fileTypeLabel($d['file_type'] ?? '')) ?>
                    · <?= formatBytes((int) ($d['file_size'] ?? 0)) ?>
                    <?php if (!empty($d['category_name'])): ?>
                        · <?= sanitize($d['category_name']) ?>
                    <?php endif ?>
                </span>
                <?php if (!empty($d['description'])): ?>
                    <span class="doc-list__desc"><?= sanitize(truncate($d['description'], 140)) ?></span>
                <?php endif ?>
            </div>
            <a class="btn btn--outline btn--sm" href="<?= sanitize(documentUrl((int) $d['id'])) ?>">
                <i class="bi bi-download"></i> Download
            </a>
        </li>
    <?php endforeach ?>
</ul>
