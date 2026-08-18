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
 *           $sortable       column headers link to a re-sort (default: false —
 *                           only true where this list owns the page URL, or a
 *                           sort click on a property page would reorder that
 *                           page's query string instead)
 *           $emptyTitle / $emptyText / $emptyIcon
 *           $emptyFiltered  wording for "filters excluded everything"
 *           $emptyClearUrl  where "Clear filters" goes
 */
$showProperty = $showProperty ?? true;
$listProperty = $listProperty ?? null;
$sortable     = $sortable ?? false;
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

/* A header that sorts only where sorting means something; a plain <th>
   otherwise. Same call site either way, so the two copies of this table
   cannot drift into different column sets. */
$th = static function (string $label, array $keys, string $class = '') use ($sortable): string {
    return $sortable
        ? uiSortHeader($label, $keys, 'sort', $class)
        : '<th' . ($class !== '' ? ' class="' . $class . '"' : '') . '>' . sanitize($label) . '</th>';
};

$visIcon = ['public' => 'bi-globe', 'staff' => 'bi-people', 'private' => 'bi-lock'];
?>
<?php if (empty($visible)): ?>
    <?= uiEmptyState([
        'icon'     => $emptyIcon,
        'filtered' => !empty($emptyFiltered),
        'title'    => $emptyTitle,
        'desc'     => $emptyText,
        'clearUrl' => $emptyClearUrl ?? '',
        'actions'  => $canManage ? [[
            'label' => 'Upload Document', 'icon' => 'bi-upload',
            'url'   => APP_URL . '/index.php?page=documents&modal=upload',
            'attrs' => ['data-modal-open' => 'documentUploadModal'],
        ]] : [],
    ]) ?>
<?php else: ?>
<div class="table-wrap">
    <table class="table">
        <thead>
            <tr>
                <?= $th('Document', ['asc' => 'title_asc', 'desc' => 'title_desc']) ?>
                <?= $th('Category', ['asc' => 'cat_asc'], 'col-mid') ?>
                <?php if ($showProperty): ?><th class="col-mid">Property</th><?php endif ?>
                <?= $th('State', ['asc' => 'expiry_asc', 'desc' => 'expiry_desc']) ?>
                <?= $th('Uploaded', ['desc' => 'newest', 'asc' => 'oldest'], 'cell-date col-lo') ?>
                <th class="cell-actions"><span class="sr-only">Actions</span></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($visible as $d):
            $id        = (int) $d['id'];
            $state     = documentStatus($d);
            $note      = documentExpiryNote($d);
            $canInline = in_array($d['file_type'] ?? '', DOCUMENT_INLINE_TYPES, true);
            $propId    = (int) ($listProperty['id'] ?? $d['reference_id'] ?? 0);
            $archived  = ($d['status'] ?? 'active') === 'archived';
            $vis       = $d['visibility'] ?? 'private';
        ?>
            <tr>
                <td>
                    <span class="filecell">
                        <i class="bi <?= sanitize(fileTypeIcon($d['file_type'] ?? '')) ?> filecell__icon" aria-hidden="true"></i>
                        <span class="filecell__body">
                            <a href="<?= APP_URL ?>/index.php?page=documents&amp;action=show&amp;id=<?= $id ?>" class="cell-strong">
                                <?= sanitize($d['title']) ?>
                            </a>
                            <span class="person__meta">
                                <?= sanitize(fileTypeLabel($d['file_type'] ?? '')) ?>
                                · <?= formatBytes((int) ($d['file_size'] ?? 0)) ?>
                                <?php if (!empty($d['doc_number'])): ?>
                                    · <?= sanitize($d['doc_number']) ?>
                                <?php endif ?>
                            </span>
                        </span>
                    </span>
                </td>
                <td class="col-mid">
                    <span class="tag">
                        <i class="bi <?= sanitize($d['category_icon'] ?: 'bi-file-earmark') ?>" aria-hidden="true"></i>
                        <?= sanitize($d['category_name'] ?? 'Uncategorised') ?>
                    </span>
                    <div class="person__meta">
                        <i class="bi <?= $visIcon[$vis] ?? 'bi-lock' ?>" aria-hidden="true"></i>
                        <?= sanitize(DOC_VISIBILITIES[$vis] ?? 'Private') ?>
                    </div>
                </td>
                <?php if ($showProperty): ?>
                <td class="cell-clip col-mid">
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
                    <?= uiStatus($state['key'], $state['label']) ?>
                    <?php if ($note !== ''): ?>
                        <div class="person__meta"><?= sanitize($note) ?></div>
                    <?php endif ?>
                </td>
                <td class="cell-date col-lo">
                    <?= formatDate($d['created_at']) ?>
                    <div class="person__meta"><?= sanitize($d['uploaded_by_name'] ?? 'Unknown') ?></div>
                </td>
                <td class="cell-actions">
                    <?php
                    /* Archive, restore and delete all change state, so each is a
                       CSRF-signed POST rather than a link. The two native
                       confirm() prompts that used to guard them are gone: the
                       shared dialog names the document being acted on, which a
                       browser prompt cannot. */
                    $actions = [];
                    if ($canInline) {
                        $actions[] = ['label' => 'View', 'icon' => 'bi-eye',
                            'url' => documentUrl($id, 'inline'),
                            'attrs' => ['target' => '_blank', 'rel' => 'noopener']];
                    }
                    $actions[] = ['label' => 'Download', 'icon' => 'bi-download', 'url' => documentUrl($id)];
                    $actions[] = ['label' => 'Details', 'icon' => 'bi-info-circle', 'can' => 'documents.show',
                        'url' => APP_URL . '/index.php?page=documents&action=show&id=' . $id];

                    // The controller reads these out of the request body, as it
                    // always has. Passing them as `fields` keeps that contract
                    // exactly rather than moving the id into a query string.
                    $post = ['id' => $id, 'property_id' => $propId];

                    if ($canManage) {
                        $actions[] = ['label' => 'Edit', 'icon' => 'bi-pencil', 'can' => 'documents.edit',
                            'url' => APP_URL . '/index.php?page=documents&action=edit&id=' . $id];

                        $actions[] = $archived
                            ? ['label' => 'Restore', 'icon' => 'bi-arrow-counterclockwise',
                               'can' => 'documents.restore', 'method' => 'post', 'fields' => $post,
                               'url' => APP_URL . '/index.php?page=documents&action=restore']
                            : ['label' => 'Archive', 'icon' => 'bi-archive',
                               'can' => 'documents.archive', 'method' => 'post', 'fields' => $post,
                               'url' => APP_URL . '/index.php?page=documents&action=archive',
                               'confirm' => [
                                   'title'  => 'Archive this document?',
                                   'action' => 'Archive',
                                   'record' => $d['title'],
                                   'tone'   => 'warning',
                                   'body'   => 'It stays on file and can be restored at any time. It stops appearing in the active library.',
                               ]];
                    }
                    if ($canDelete) {
                        $actions[] = ['label' => 'Delete permanently', 'icon' => 'bi-trash',
                            'can' => 'documents.delete', 'method' => 'post', 'danger' => true,
                            'fields' => $post,
                            'url' => APP_URL . '/index.php?page=documents&action=delete',
                            'confirm' => [
                                'title'  => 'Delete this document permanently?',
                                'action' => 'Delete permanently',
                                'record' => $d['title'],
                                'tone'   => 'danger',
                                'body'   => 'The file is removed from the server and the record with it. This cannot be undone — archive it instead if you may need it again.',
                            ]];
                    }
                    ?>
                    <?= uiRowActions($actions, 'Actions for ' . $d['title']) ?>
                </td>
            </tr>
        <?php endforeach ?>
        </tbody>
    </table>
</div>
<?php endif ?>
