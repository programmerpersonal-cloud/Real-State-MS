<?php
/**
 * Document Categories — Index
 *
 * Reordering uses up/down buttons that POST rather than drag-and-drop: this
 * application has no AJAX layer, and buttons are honest about that. They stay
 * visible in the row rather than folding into the action menu, because moving
 * a category is the thing this page is opened to do and a two-click menu for
 * a one-place nudge would be worse than the arrows.
 *
 * Everything else — edit, deactivate, delete — is the standard row menu, so
 * this table reads the same as every other table in the product.
 *
 * Expects: $categories, $formData, $editing, $openModal, $visibilities
 */
$last = count($categories) - 1;

$moveUrl = APP_URL . '/index.php?page=document-categories&action=move';
?>
<div class="table-card">
    <div class="table-head">
        <div class="table-head__title">
            <?= count($categories) ?> categor<?= count($categories) === 1 ? 'y' : 'ies' ?>
        </div>
        <span class="table-head__note">The order here is the order staff see when filing a document</span>
    </div>

    <?php if (empty($categories)): ?>
        <?= uiEmptyState([
            'icon'  => 'bi-tags',
            'title' => 'No categories yet',
            'desc'  => 'Add the document types your team files against a property — a lease, a title deed, '
                     . 'an inspection report. Each one becomes a filing option and a filter.',
            'actions' => [[
                'label' => 'Add a category', 'icon' => 'bi-plus-lg',
                'can'   => 'document-categories.save',
                'url'   => APP_URL . '/index.php?page=document-categories&modal=create',
            ]],
        ]) ?>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th class="cell-tight">Order</th>
                        <th>Category</th>
                        <th class="col-mid">Default visibility</th>
                        <th class="col-lo">Expiry</th>
                        <th>In use</th>
                        <th>Status</th>
                        <th class="cell-actions"><span class="sr-only">Actions</span></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($categories as $i => $c): ?>
                    <?php
                    $id     = (int) $c['id'];
                    $active = (int) $c['is_active'] === 1;
                    $inUse  = (int) $c['doc_count'];
                    ?>
                    <tr<?= $active ? '' : ' class="is-muted-row"' ?>>
                        <td class="cell-tight">
                            <div class="btn-group">
                                <form class="inline-form" method="post" action="<?= $moveUrl ?>">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="id" value="<?= $id ?>">
                                    <input type="hidden" name="direction" value="up">
                                    <button class="btn btn--outline btn--sm" <?= $i === 0 ? 'disabled' : '' ?>
                                            aria-label="Move <?= sanitize($c['name']) ?> up">
                                        <i class="bi bi-chevron-up" aria-hidden="true"></i>
                                    </button>
                                </form>
                                <form class="inline-form" method="post" action="<?= $moveUrl ?>">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="id" value="<?= $id ?>">
                                    <input type="hidden" name="direction" value="down">
                                    <button class="btn btn--outline btn--sm" <?= $i === $last ? 'disabled' : '' ?>
                                            aria-label="Move <?= sanitize($c['name']) ?> down">
                                        <i class="bi bi-chevron-down" aria-hidden="true"></i>
                                    </button>
                                </form>
                            </div>
                        </td>

                        <td>
                            <span class="filecell">
                                <i class="bi <?= sanitize($c['icon'] ?: 'bi-file-earmark') ?> filecell__icon" aria-hidden="true"></i>
                                <span class="filecell__body">
                                    <span class="cell-strong"><?= sanitize($c['name']) ?></span>
                                    <?php if (!empty($c['description'])): ?>
                                        <span class="person__meta"><?= sanitize($c['description']) ?></span>
                                    <?php endif ?>
                                    <span class="person__meta"><code><?= sanitize($c['slug']) ?></code></span>
                                </span>
                            </span>
                        </td>

                        <td class="col-mid">
                            <i class="bi <?= $c['default_visibility'] === 'public'
                                ? 'bi-globe'
                                : ($c['default_visibility'] === 'staff' ? 'bi-people' : 'bi-lock') ?>"
                               aria-hidden="true"></i>
                            <?= sanitize($visibilities[$c['default_visibility']] ?? $c['default_visibility']) ?>
                        </td>

                        <td class="col-lo">
                            <?php if ((int) $c['requires_expiry'] === 1): ?>
                                <i class="bi bi-calendar-check" aria-hidden="true"></i> Tracked
                            <?php else: ?>
                                <span class="text-subtle">—</span>
                            <?php endif ?>
                        </td>

                        <td>
                            <?php if ($inUse > 0): ?>
                                <a href="<?= APP_URL ?>/index.php?page=documents&amp;category_id=<?= $id ?>">
                                    <?= number_format($inUse) ?> document<?= $inUse === 1 ? '' : 's' ?>
                                </a>
                            <?php else: ?>
                                <span class="text-subtle">Unused</span>
                            <?php endif ?>
                        </td>

                        <td><?= uiStatus($active ? 'active' : 'inactive') ?></td>

                        <td class="cell-actions">
                            <?php
                            $actions = [[
                                'label' => 'Edit category', 'icon' => 'bi-pencil',
                                'can'   => 'document-categories.save',
                                'url'   => APP_URL . '/index.php?page=document-categories&modal=edit&id=' . $id,
                            ], [
                                'label'  => $active ? 'Deactivate' : 'Reactivate',
                                'icon'   => $active ? 'bi-toggle-on' : 'bi-toggle-off',
                                'can'    => 'document-categories.toggle',
                                'method' => 'post',
                                'url'    => APP_URL . '/index.php?page=document-categories&action=toggle',
                                'fields' => ['id' => $id],
                            ]];

                            /* Deleting is only offered where it is possible: a category
                               with documents filed under it is deactivated instead, and
                               the menu says which of the two is on the table rather than
                               showing a dead control. */
                            if ($inUse === 0) {
                                $actions[] = [
                                    'label'  => 'Delete category', 'icon' => 'bi-trash', 'danger' => true,
                                    'can'    => 'document-categories.delete',
                                    'method' => 'post',
                                    'url'    => APP_URL . '/index.php?page=document-categories&action=delete',
                                    'fields' => ['id' => $id],
                                    'confirm' => [
                                        'title'  => 'Delete this category?',
                                        'body'   => 'Nothing is filed under it, so no document is affected. The category itself is removed.',
                                        'action' => 'Delete category',
                                        'record' => $c['name'],
                                        'tone'   => 'danger',
                                    ],
                                ];
                            }
                            ?>
                            <?= uiRowActions($actions, 'Actions for ' . $c['name']) ?>
                        </td>
                    </tr>
                <?php endforeach ?>
                </tbody>
            </table>
        </div>
    <?php endif ?>
</div>

<?php require __DIR__ . '/_category_modal.php'; ?>
