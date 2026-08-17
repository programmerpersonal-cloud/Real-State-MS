<?php
/**
 * Document table — the one renderer for a list of documents, shared by the
 * standalone Documents page and the card on a property.
 *
 * Every row is filtered by the caller's query *and* checked again here with
 * documentVisibilityAllows(), because the property page is reachable by any
 * signed-in role and a missed filter there would leak a title deed.
 *
 * Expects:  $documents      rows to render
 * Optional: $listProperty   property row when rendered inside a property page
 *           $showProperty   show the Property column (default: true)
 *           $emptyTitle / $emptyText / $emptyIcon
 *           $listId         suffix so two tables on one page keep unique ids
 */
$showProperty = $showProperty ?? true;
$listProperty = $listProperty ?? null;
$emptyIcon    = $emptyIcon ?? 'bi-folder2-open';
$emptyTitle   = $emptyTitle ?? 'No documents yet';
$emptyText    = $emptyText ?? 'Upload title deeds, agreements, permits and reports to keep them on file here.';

$canManage = documentCanManage();
$canDelete = documentCanDelete();

// The property page passes one property for every row; the standalone list
// carries the property on each row instead.
$visible = array_filter($documents ?? [], static function (array $d) use ($listProperty): bool {
    return documentVisibilityAllows($d, $listProperty ?: (
        isset($d['approval_status'])
            ? ['approval_status' => $d['approval_status'], 'is_archived' => $d['is_archived'] ?? 0]
            : null
    ));
});
?>
<?php if (empty($visible)): ?>
    <div class="empty-state">
        <div class="empty-state__icon"><i class="bi <?= sanitize($emptyIcon) ?>"></i></div>
        <div class="empty-state__title"><?= sanitize($emptyTitle) ?></div>
        <div class="empty-state__desc"><?= sanitize($emptyText) ?></div>
        <?php if ($canManage): ?>
            <button type="button" class="btn btn--primary btn--sm" data-modal-open="documentUploadModal" style="margin-top:14px">
                <i class="bi bi-upload"></i> Upload Document
            </button>
        <?php endif ?>
    </div>
<?php else: ?>
<div class="table-wrap">
    <table class="table">
        <thead>
            <tr>
                <th>Document</th>
                <th>Category</th>
                <?php if ($showProperty): ?><th>Property</th><?php endif ?>
                <th>Status</th>
                <th>Uploaded</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($visible as $d):
            $state     = documentStatus($d);
            $note      = documentExpiryNote($d);
            $canInline = in_array($d['file_type'] ?? '', DOCUMENT_INLINE_TYPES, true);
            $propId    = (int) ($listProperty['id'] ?? $d['reference_id'] ?? 0);
        ?>
            <tr>
                <td>
                    <div style="display:flex;align-items:flex-start;gap:10px">
                        <i class="bi <?= sanitize(fileTypeIcon($d['file_type'] ?? '')) ?>" style="font-size:1.35rem;color:var(--text-subtle);line-height:1.2"></i>
                        <div>
                            <a href="<?= APP_URL ?>/index.php?page=documents&amp;action=show&amp;id=<?= (int) $d['id'] ?>">
                                <strong><?= sanitize($d['title']) ?></strong>
                            </a>
                            <div class="text-muted" style="font-size:.75rem;margin-top:2px">
                                <?= sanitize(fileTypeLabel($d['file_type'] ?? '')) ?>
                                · <?= formatBytes((int) ($d['file_size'] ?? 0)) ?>
                                <?php if (!empty($d['doc_number'])): ?>
                                    · <code><?= sanitize($d['doc_number']) ?></code>
                                <?php endif ?>
                            </div>
                        </div>
                    </div>
                </td>
                <td>
                    <span class="tag">
                        <i class="bi <?= sanitize($d['category_icon'] ?: 'bi-file-earmark') ?>"></i>
                        <?= sanitize($d['category_name'] ?? 'Uncategorised') ?>
                    </span>
                    <div class="text-muted" style="font-size:.7rem;margin-top:4px">
                        <i class="bi <?= ($d['visibility'] ?? '') === 'public' ? 'bi-globe' : (($d['visibility'] ?? '') === 'staff' ? 'bi-people' : 'bi-lock') ?>"></i>
                        <?= sanitize(DOC_VISIBILITIES[$d['visibility'] ?? 'private'] ?? 'Private') ?>
                    </div>
                </td>
                <?php if ($showProperty): ?>
                <td>
                    <?php if (!empty($d['property_title'])): ?>
                        <a href="<?= APP_URL ?>/index.php?page=properties&amp;action=show&amp;id=<?= (int) $d['reference_id'] ?>">
                            <?= sanitize($d['property_title']) ?>
                        </a>
                    <?php else: ?>
                        <span class="text-subtle">—</span>
                    <?php endif ?>
                </td>
                <?php endif ?>
                <td>
                    <span class="badge <?= $state['badge'] ?>"><?= sanitize($state['label']) ?></span>
                    <?php if ($note !== ''): ?>
                        <div class="text-muted" style="font-size:.7rem;margin-top:4px"><?= sanitize($note) ?></div>
                    <?php endif ?>
                </td>
                <td>
                    <?= formatDate($d['created_at']) ?>
                    <div class="text-muted" style="font-size:.7rem"><?= sanitize($d['uploaded_by_name'] ?? 'Unknown') ?></div>
                </td>
                <td>
                    <div class="btn-group">
                        <?php if ($canInline): ?>
                            <a class="btn btn--outline btn--sm" title="View"
                               href="<?= sanitize(documentUrl((int) $d['id'], 'inline')) ?>" target="_blank" rel="noopener">
                                <i class="bi bi-eye"></i>
                            </a>
                        <?php endif ?>
                        <a class="btn btn--outline btn--sm" title="Download"
                           href="<?= sanitize(documentUrl((int) $d['id'])) ?>">
                            <i class="bi bi-download"></i>
                        </a>
                        <?php if ($canManage): ?>
                            <a class="btn btn--outline btn--sm" title="Edit"
                               href="<?= APP_URL ?>/index.php?page=documents&amp;action=edit&amp;id=<?= (int) $d['id'] ?>">
                                <i class="bi bi-pencil"></i>
                            </a>
                            <?php if (($d['status'] ?? 'active') === 'archived'): ?>
                                <form method="post" action="<?= APP_URL ?>/index.php?page=documents&amp;action=restore" style="display:inline">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="id" value="<?= (int) $d['id'] ?>">
                                    <input type="hidden" name="property_id" value="<?= $propId ?>">
                                    <button class="btn btn--outline btn--sm" title="Restore"><i class="bi bi-arrow-counterclockwise"></i></button>
                                </form>
                            <?php else: ?>
                                <form method="post" action="<?= APP_URL ?>/index.php?page=documents&amp;action=archive" style="display:inline">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="id" value="<?= (int) $d['id'] ?>">
                                    <input type="hidden" name="property_id" value="<?= $propId ?>">
                                    <button class="btn btn--outline btn--sm" title="Archive"
                                            onclick="return confirm('Archive this document? It stays on file and can be restored.')">
                                        <i class="bi bi-archive"></i>
                                    </button>
                                </form>
                            <?php endif ?>
                        <?php endif ?>
                        <?php if ($canDelete): ?>
                            <form method="post" action="<?= APP_URL ?>/index.php?page=documents&amp;action=delete" style="display:inline">
                                <?= csrfField() ?>
                                <input type="hidden" name="id" value="<?= (int) $d['id'] ?>">
                                <input type="hidden" name="property_id" value="<?= $propId ?>">
                                <button class="btn btn--danger btn--sm" title="Delete permanently"
                                        onclick="return confirm('Delete this document permanently? The file is removed from the server and this cannot be undone.')">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </form>
                        <?php endif ?>
                    </div>
                </td>
            </tr>
        <?php endforeach ?>
        </tbody>
    </table>
</div>
<?php endif ?>
