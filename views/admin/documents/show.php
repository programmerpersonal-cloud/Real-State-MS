<?php
/**
 * Documents — Detail
 *
 * Reachable by anyone cleared to see the document, so every management control
 * is gated separately from the page itself.
 *
 * Expects: $doc
 */
$state     = documentStatus($doc);
$note      = documentExpiryNote($doc);
$canInline = in_array($doc['file_type'] ?? '', DOCUMENT_INLINE_TYPES, true);
$canManage = documentCanManage();
$propId    = (int) ($doc['reference_id'] ?? 0);

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
<div style="display:grid;grid-template-columns:2fr 1fr;gap:20px">
    <div>
        <div class="card mb-3">
            <div class="card__header">
                <h3 class="card__title"><?= sanitize($doc['title']) ?></h3>
                <span class="badge <?= $state['badge'] ?>"><?= sanitize($state['label']) ?></span>
            </div>
            <div class="card__body">
                <?php if (!empty($doc['description'])): ?>
                    <p><?= nl2br(sanitize($doc['description'])) ?></p>
                <?php else: ?>
                    <p class="text-subtle">No description was provided.</p>
                <?php endif ?>

                <?php if ($note !== ''): ?>
                    <div class="alert alert--<?= $state['key'] === 'expired' ? 'danger' : ($state['key'] === 'expiring' ? 'warning' : 'info') ?>" style="margin-top:16px">
                        <i class="bi bi-calendar-event"></i>
                        <div>
                            <?= sanitize($note) ?> (<?= formatDate($doc['expiry_date']) ?>).
                            <?php if ($state['key'] === 'expired'): ?>
                                It is kept on file — expired documents are never deleted automatically.
                            <?php endif ?>
                        </div>
                    </div>
                <?php endif ?>

                <?php if (($doc['status'] ?? '') === 'archived'): ?>
                    <div class="alert alert--info" style="margin-top:16px">
                        <i class="bi bi-archive"></i>
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
            <div class="card__header"><h3 class="card__title">File</h3></div>
            <div class="card__body">
                <div style="display:flex;align-items:center;gap:16px">
                    <i class="bi <?= sanitize(fileTypeIcon($doc['file_type'] ?? '')) ?>" style="font-size:2.5rem;color:var(--text-subtle)"></i>
                    <div style="flex:1">
                        <div><strong><?= sanitize($doc['file_name']) ?></strong></div>
                        <div class="text-muted" style="font-size:.8rem">
                            <?= sanitize(fileTypeLabel($doc['file_type'] ?? '')) ?>
                            · <?= formatBytes((int) $doc['file_size']) ?>
                            · uploaded <?= formatDateTime($doc['created_at']) ?>
                        </div>
                    </div>
                    <a class="btn btn--primary btn--sm" href="<?= sanitize(documentUrl((int) $doc['id'])) ?>">
                        <i class="bi bi-download"></i> Download
                    </a>
                </div>

                <?php if (!empty($doc['checksum'])): ?>
                    <div class="form-hint" style="margin-top:14px">
                        <i class="bi bi-fingerprint"></i>
                        SHA-256 <code style="font-size:.7rem"><?= sanitize($doc['checksum']) ?></code>
                        — recorded at upload so the stored file can be shown to be unaltered.
                    </div>
                <?php endif ?>
            </div>
        </div>
    </div>

    <div>
        <div class="card mb-3">
            <div class="card__header"><h3 class="card__title">Details</h3></div>
            <div class="card__body">
                <table style="width:100%;font-size:.875rem">
                    <tr><td class="text-muted" style="padding:8px 0;width:42%">Code</td>
                        <td style="padding:8px 0"><code><?= sanitize($doc['document_code'] ?? '—') ?></code></td></tr>
                    <tr><td class="text-muted" style="padding:8px 0">Category</td>
                        <td style="padding:8px 0">
                            <i class="bi <?= sanitize($doc['category_icon'] ?: 'bi-file-earmark') ?>"></i>
                            <?= sanitize($doc['category_name'] ?? 'Uncategorised') ?>
                        </td></tr>
                    <tr><td class="text-muted" style="padding:8px 0">Visibility</td>
                        <td style="padding:8px 0">
                            <i class="bi <?= ($doc['visibility'] ?? '') === 'public' ? 'bi-globe' : (($doc['visibility'] ?? '') === 'staff' ? 'bi-people' : 'bi-lock') ?>"></i>
                            <?= sanitize(DOC_VISIBILITIES[$doc['visibility'] ?? 'private'] ?? 'Private') ?>
                        </td></tr>
                    <?php if (!empty($doc['doc_number'])): ?>
                    <tr><td class="text-muted" style="padding:8px 0">Reference no.</td>
                        <td style="padding:8px 0"><?= sanitize($doc['doc_number']) ?></td></tr>
                    <?php endif ?>
                    <tr><td class="text-muted" style="padding:8px 0">Document date</td>
                        <td style="padding:8px 0"><?= formatDate($doc['document_date']) ?></td></tr>
                    <tr><td class="text-muted" style="padding:8px 0">Expires</td>
                        <td style="padding:8px 0"><?= formatDate($doc['expiry_date']) ?></td></tr>
                    <tr><td class="text-muted" style="padding:8px 0">Uploaded by</td>
                        <td style="padding:8px 0"><?= sanitize($doc['uploaded_by_name'] ?? 'Unknown') ?></td></tr>
                    <tr><td class="text-muted" style="padding:8px 0">Uploaded</td>
                        <td style="padding:8px 0"><?= formatDateTime($doc['created_at']) ?></td></tr>
                </table>
            </div>
        </div>

        <?php if ($propId > 0): ?>
        <div class="card mb-3">
            <div class="card__header"><h3 class="card__title">Attached to</h3></div>
            <div class="card__body">
                <a class="btn btn--outline btn--sm" href="<?= APP_URL ?>/index.php?page=properties&amp;action=show&amp;id=<?= $propId ?>">
                    <i class="bi bi-buildings"></i> Open the property
                </a>
            </div>
        </div>
        <?php endif ?>

        <?php if ($canManage): ?>
        <div class="card">
            <div class="card__header"><h3 class="card__title">Manage</h3></div>
            <div class="card__body stack">
                <?php /* The browser's own confirm() cannot name the document it
                         is about to remove, so both destructive actions hand
                         over to the shared dialog, which can. */ ?>
                <?php if (($doc['status'] ?? 'active') === 'archived'): ?>
                    <form method="post" action="<?= APP_URL ?>/index.php?page=documents&amp;action=restore">
                        <?= csrfField() ?>
                        <input type="hidden" name="id" value="<?= (int) $doc['id'] ?>">
                        <button class="btn btn--outline btn--sm btn--block">
                            <i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i> Restore document
                        </button>
                    </form>
                <?php else: ?>
                    <form method="post" action="<?= APP_URL ?>/index.php?page=documents&amp;action=archive">
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
                    <form method="post" action="<?= APP_URL ?>/index.php?page=documents&amp;action=delete">
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
    </div>
</div>
