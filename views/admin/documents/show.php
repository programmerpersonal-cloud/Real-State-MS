<?php
/**
 * Documents — Detail
 *
 * Reachable by anyone cleared to see the document, so every management control
 * is gated separately from the page itself.
 *
 * Onto the shared detail layout in step 4: the two-column grid was an inline
 * `grid-template-columns:2fr 1fr` this page declared for itself, and the
 * Details panel was a <table> pretending to be a description list. Both now
 * use the components the property, customer and owner pages already proved.
 *
 * Expects: $doc
 */
$state     = documentStatus($doc);
$note      = documentExpiryNote($doc);
$canInline = in_array($doc['file_type'] ?? '', DOCUMENT_INLINE_TYPES, true);
$canManage = documentCanManage();
$propId    = (int) ($doc['reference_id'] ?? 0);
$vis       = $doc['visibility'] ?? 'private';
$visIcon   = ['public' => 'bi-globe', 'staff' => 'bi-people', 'private' => 'bi-lock'];

$pageHeaderVariant = 'record';

$actionButtons = [];
if ($canInline) {
    $actionButtons[] = ['label' => 'View', 'icon' => 'bi-eye', 'class' => 'btn--outline',
                        'url' => documentUrl((int) $doc['id'], 'inline')];
}
$actionButtons[] = ['label' => 'Download', 'icon' => 'bi-download', 'class' => 'btn--primary',
                    'url' => documentUrl((int) $doc['id'])];
if ($canManage) {
    $actionButtons[] = ['label' => 'Edit', 'icon' => 'bi-pencil', 'class' => 'btn--outline',
                        'url' => APP_URL . '/index.php?page=documents&action=edit&id=' . (int) $doc['id']];
}
?>
<div class="detail-cols">
    <div class="detail-cols__main">
        <div class="card mb-3">
            <div class="card__header">
                <h2 class="card__title"><?= sanitize($doc['title']) ?></h2>
                <?= uiStatus($state['key'], $state['label']) ?>
            </div>
            <div class="card__body">
                <?php if (!empty($doc['description'])): ?>
                    <p class="prose"><?= nl2br(sanitize($doc['description'])) ?></p>
                <?php else: ?>
                    <p class="text-subtle">No description was provided.</p>
                <?php endif ?>

                <?php if ($note !== ''): ?>
                    <div class="alert alert--<?= $state['key'] === 'expired' ? 'danger' : ($state['key'] === 'expiring' ? 'warning' : 'info') ?> mt-2">
                        <i class="bi bi-calendar-event" aria-hidden="true"></i>
                        <div>
                            <?= sanitize($note) ?> (<?= formatDate($doc['expiry_date']) ?>).
                            <?php if ($state['key'] === 'expired'): ?>
                                It is kept on file — expired documents are never deleted automatically.
                            <?php endif ?>
                        </div>
                    </div>
                <?php endif ?>

                <?php if (($doc['status'] ?? '') === 'archived'): ?>
                    <div class="alert alert--info mt-2">
                        <i class="bi bi-archive" aria-hidden="true"></i>
                        <div>
                            Archived <?= formatDateTime($doc['archived_at']) ?>
                            by <?= sanitize($doc['archived_by_name'] ?? 'a colleague') ?>.
                            It is hidden from the public listing and from non-staff users.
                        </div>
                    </div>
                <?php endif ?>
            </div>
        </div>

        <div class="card">
            <div class="card__header"><h2 class="card__title">File</h2></div>
            <div class="card__body">
                <?php /* The same filecell the document register uses, so a file
                         looks like the same object in the list and on its own
                         page. */ ?>
                <div class="filecell filecell--lg">
                    <i class="bi <?= sanitize(fileTypeIcon($doc['file_type'] ?? '')) ?> filecell__icon" aria-hidden="true"></i>
                    <span class="filecell__body">
                        <strong><?= sanitize($doc['file_name']) ?></strong>
                        <span class="person__meta">
                            <?= sanitize(fileTypeLabel($doc['file_type'] ?? '')) ?>
                            · <?= formatBytes((int) $doc['file_size']) ?>
                            · uploaded <?= formatDateTime($doc['created_at']) ?>
                        </span>
                    </span>
                    <a class="btn btn--primary btn--sm" href="<?= sanitize(documentUrl((int) $doc['id'])) ?>">
                        <i class="bi bi-download" aria-hidden="true"></i> Download
                    </a>
                </div>

                <?php if (!empty($doc['checksum'])): ?>
                    <div class="form-hint mt-2">
                        <i class="bi bi-fingerprint" aria-hidden="true"></i>
                        SHA-256 <code class="hash"><?= sanitize($doc['checksum']) ?></code>
                        — recorded at upload so the stored file can be shown to be unaltered.
                    </div>
                <?php endif ?>
            </div>
        </div>
    </div>

    <aside class="detail-cols__side">
        <div class="card mb-3">
            <div class="card__header"><h2 class="card__title">Details</h2></div>
            <div class="card__body">
                <dl class="datalist">
                    <div class="datalist__row"><dt>Code</dt>
                        <dd class="num"><?= sanitize($doc['document_code'] ?: '—') ?></dd>
                    </div>
                    <div class="datalist__row"><dt>Category</dt>
                        <dd>
                            <i class="bi <?= sanitize($doc['category_icon'] ?: 'bi-file-earmark') ?>" aria-hidden="true"></i>
                            <?= sanitize($doc['category_name'] ?? 'Uncategorised') ?>
                        </dd>
                    </div>
                    <div class="datalist__row"><dt>Visibility</dt>
                        <dd>
                            <i class="bi <?= $visIcon[$vis] ?? 'bi-lock' ?>" aria-hidden="true"></i>
                            <?= sanitize(DOC_VISIBILITIES[$vis] ?? 'Private') ?>
                        </dd>
                    </div>
                    <?php if (!empty($doc['doc_number'])): ?>
                        <div class="datalist__row"><dt>Reference no.</dt>
                            <dd class="num"><?= sanitize($doc['doc_number']) ?></dd>
                        </div>
                    <?php endif ?>
                    <div class="datalist__row"><dt>Document date</dt>
                        <dd class="num"><?= formatDate($doc['document_date']) ?></dd>
                    </div>
                    <div class="datalist__row"><dt>Expires</dt>
                        <dd class="num"><?= $doc['expiry_date'] ? formatDate($doc['expiry_date']) : '<span class="text-subtle">No expiry</span>' ?></dd>
                    </div>
                    <div class="datalist__row"><dt>Uploaded by</dt>
                        <dd><?= sanitize($doc['uploaded_by_name'] ?? 'Unknown') ?></dd>
                    </div>
                    <div class="datalist__row"><dt>Uploaded</dt>
                        <dd class="num"><?= formatDateTime($doc['created_at']) ?></dd>
                    </div>
                </dl>
            </div>
        </div>

        <?php if ($propId > 0): ?>
            <div class="card mb-3">
                <div class="card__header"><h2 class="card__title">Attached to</h2></div>
                <div class="card__body">
                    <a class="btn btn--outline btn--sm btn--block"
                       href="<?= APP_URL ?>/index.php?page=properties&amp;action=show&amp;id=<?= $propId ?>">
                        <i class="bi bi-buildings" aria-hidden="true"></i> Open the property
                    </a>
                </div>
            </div>
        <?php endif ?>

        <?php if ($canManage): ?>
            <div class="card">
                <div class="card__header"><h2 class="card__title">Manage</h2></div>
                <div class="card__body stack">
                    <?php /* The browser's own confirm() cannot name the document it
                             is about to remove, so both destructive actions hand
                             over to the shared dialog, which can. */ ?>
                    <?php if (($doc['status'] ?? 'active') === 'archived'): ?>
                        <form class="inline-form" method="post" action="<?= APP_URL ?>/index.php?page=documents&amp;action=restore">
                            <?= csrfField() ?>
                            <input type="hidden" name="id" value="<?= (int) $doc['id'] ?>">
                            <button class="btn btn--outline btn--sm btn--block">
                                <i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i> Restore document
                            </button>
                        </form>
                    <?php else: ?>
                        <form class="inline-form" method="post" action="<?= APP_URL ?>/index.php?page=documents&amp;action=archive">
                            <?= csrfField() ?>
                            <input type="hidden" name="id" value="<?= (int) $doc['id'] ?>">
                            <button class="btn btn--outline btn--sm btn--block"
                                    data-confirm="It stays on file and can be restored at any time. It stops appearing in the active library."
                                    data-confirm-title="Archive this document?"
                                    data-confirm-action="Archive"
                                    data-confirm-record="<?= sanitize($doc['title']) ?>"
                                    data-confirm-tone="warning">
                                <i class="bi bi-archive" aria-hidden="true"></i> Archive document
                            </button>
                        </form>
                    <?php endif ?>

                    <?php if (documentCanDelete()): ?>
                        <form class="inline-form" method="post" action="<?= APP_URL ?>/index.php?page=documents&amp;action=delete">
                            <?= csrfField() ?>
                            <input type="hidden" name="id" value="<?= (int) $doc['id'] ?>">
                            <button class="btn btn--danger btn--sm btn--block"
                                    data-confirm="The file is removed from the server and the record with it. This cannot be undone."
                                    data-confirm-title="Delete this document permanently?"
                                    data-confirm-action="Delete permanently"
                                    data-confirm-record="<?= sanitize($doc['title']) ?>"
                                    data-confirm-tone="danger">
                                <i class="bi bi-trash" aria-hidden="true"></i> Delete permanently
                            </button>
                        </form>
                        <div class="form-hint">Archiving is usually the right choice — it keeps the record.</div>
                    <?php endif ?>
                </div>
            </div>
        <?php endif ?>
    </aside>
</div>
