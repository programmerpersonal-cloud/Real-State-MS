<?php
/**
 * Document Categories — Index
 *
 * Reordering uses up/down buttons that POST rather than drag-and-drop: this
 * application has no AJAX layer, and buttons are honest about that.
 *
 * Expects: $categories, $formData, $editing, $openModal, $visibilities
 */
$last = count($categories) - 1;
?>
<div class="card">
    <div class="card__header">
        <h3 class="card__title"><?= count($categories) ?> Categor<?= count($categories) === 1 ? 'y' : 'ies' ?></h3>
        <span class="person__meta">
            The order here is the order staff see when filing a document.
        </span>
    </div>
    <div class="card__body card__body--flush">
        <?php if (empty($categories)): ?>
            <div class="empty-state">
                <div class="empty-state__icon"><i class="bi bi-tags"></i></div>
                <div class="empty-state__title">No categories yet</div>
                <div class="empty-state__desc">Add the document types your team files against a property.</div>
                <button type="button" class="btn btn--primary btn--sm mt-2" data-modal-open="categoryModal" >
                    <i class="bi bi-plus-lg"></i> Add Category
                </button>
            </div>
        <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th class="cell-tight">Order</th>
                        <th>Category</th>
                        <th>Default visibility</th>
                        <th>Expiry</th>
                        <th>In use</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($categories as $i => $c): ?>
                    <tr<?= (int) $c['is_active'] === 0 ? ' class="is-muted-row"' : '' ?>>
                        <td>
                            <div class="btn-group">
                                <form class="inline-form" method="post" action="<?= APP_URL ?>/index.php?page=document-categories&amp;action=move">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
                                    <input type="hidden" name="direction" value="up">
                                    <button class="btn btn--outline btn--sm" title="Move up" <?= $i === 0 ? 'disabled' : '' ?>>
                                        <i class="bi bi-chevron-up"></i>
                                    </button>
                                </form>
                                <form class="inline-form" method="post" action="<?= APP_URL ?>/index.php?page=document-categories&amp;action=move">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
                                    <input type="hidden" name="direction" value="down">
                                    <button class="btn btn--outline btn--sm" title="Move down" <?= $i === $last ? 'disabled' : '' ?>>
                                        <i class="bi bi-chevron-down"></i>
                                    </button>
                                </form>
                            </div>
                        </td>
                        <td>
                            <span class="filecell">
                                <i class="bi <?= sanitize($c['icon'] ?: 'bi-file-earmark') ?> filecell__icon" aria-hidden="true"></i>
                                <span class="filecell__body">
                                    <strong><?= sanitize($c['name']) ?></strong>
                                    <?php if (!empty($c['description'])): ?>
                                        <div class="person__meta"><?= sanitize($c['description']) ?></div>
                                    <?php endif ?>
                                    <div class="person__meta"><code><?= sanitize($c['slug']) ?></code></div>
                                </span>
                            </span>
                        </td>
                        <td>
                            <i class="bi <?= $c['default_visibility'] === 'public' ? 'bi-globe' : ($c['default_visibility'] === 'staff' ? 'bi-people' : 'bi-lock') ?>"></i>
                            <?= sanitize($visibilities[$c['default_visibility']] ?? $c['default_visibility']) ?>
                        </td>
                        <td>
                            <?php if ((int) $c['requires_expiry'] === 1): ?>
                                <span class="badge badge--info">Tracked</span>
                            <?php else: ?>
                                <span class="text-subtle">—</span>
                            <?php endif ?>
                        </td>
                        <td>
                            <?php if ((int) $c['doc_count'] > 0): ?>
                                <a href="<?= APP_URL ?>/index.php?page=documents&amp;category_id=<?= (int) $c['id'] ?>">
                                    <?= (int) $c['doc_count'] ?> document<?= (int) $c['doc_count'] === 1 ? '' : 's' ?>
                                </a>
                            <?php else: ?>
                                <span class="text-subtle">Unused</span>
                            <?php endif ?>
                        </td>
                        <td>
                            <span class="badge <?= (int) $c['is_active'] === 1 ? 'badge--success' : 'badge--muted' ?>">
                                <?= (int) $c['is_active'] === 1 ? 'Active' : 'Inactive' ?>
                            </span>
                        </td>
                        <td>
                            <div class="btn-group">
                                <a class="btn btn--outline btn--sm" title="Edit"
                                   href="<?= APP_URL ?>/index.php?page=document-categories&amp;modal=edit&amp;id=<?= (int) $c['id'] ?>">
                                    <i class="bi bi-pencil"></i>
                                </a>
                                <form class="inline-form" method="post" action="<?= APP_URL ?>/index.php?page=document-categories&amp;action=toggle">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
                                    <button class="btn btn--outline btn--sm"
                                            title="<?= (int) $c['is_active'] === 1 ? 'Deactivate' : 'Reactivate' ?>">
                                        <i class="bi <?= (int) $c['is_active'] === 1 ? 'bi-toggle-on' : 'bi-toggle-off' ?>"></i>
                                    </button>
                                </form>
                                <?php if ((int) $c['doc_count'] === 0): ?>
                                    <form class="inline-form" method="post" action="<?= APP_URL ?>/index.php?page=document-categories&amp;action=delete">
                                        <?= csrfField() ?>
                                        <input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
                                        <button class="btn btn--danger btn--sm" title="Delete"
                                                data-confirm="Nothing is filed under it, so no document is affected. The category itself is removed."
                                                data-confirm-title="Delete this category?"
                                                data-confirm-action="Delete category"
                                                data-confirm-record="<?= sanitize($c['name']) ?>"
                                                data-confirm-tone="danger">
                                            <i class="bi bi-trash" aria-hidden="true"></i>
                                        </button>
                                    </form>
                                <?php else: ?>
                                    <button class="btn btn--outline btn--sm" disabled
                                            title="In use by <?= (int) $c['doc_count'] ?> document(s) — deactivate instead">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                <?php endif ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach ?>
                </tbody>
            </table>
        </div>
        <?php endif ?>
    </div>
</div>

<?php require __DIR__ . '/_category_modal.php'; ?>
