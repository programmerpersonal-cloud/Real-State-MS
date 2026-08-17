<?php
/**
 * Terms & Conditions — Index
 *
 * One tab per legal type, each holding that type's version history. Uses the
 * .tabs component from style.css / main.js.
 *
 * Expects: $types, $versionsByType, $formData, $editingType, $openTypeModal
 */
$actionButtons = [
    ['label' => 'Acceptance log', 'icon' => 'bi-journal-check', 'class' => 'btn--outline',
     'url' => APP_URL . '/index.php?page=legal&action=acceptances'],
    ['label' => 'Add type', 'icon' => 'bi-plus-lg', 'class' => 'btn--primary',
     'url' => APP_URL . '/index.php?page=legal&modal=new-type',
     'attrs' => ['data-modal-open' => 'termsTypeModal']],
];
?>
<div class="alert alert--info" style="margin-bottom:16px">
    <i class="bi bi-shield-check"></i>
    <div>
        Published versions are never edited. Revising terms creates a new version and supersedes the old
        one, so every acceptance record keeps pointing at the exact wording that was agreed to.
    </div>
</div>

<?php if (empty($types)): ?>
    <div class="card"><div class="card__body">
        <div class="empty-state">
            <div class="empty-state__icon"><i class="bi bi-file-earmark-check"></i></div>
            <div class="empty-state__title">No terms configured</div>
            <div class="empty-state__desc">Add a legal type, then write its first version.</div>
        </div>
    </div></div>
<?php else: ?>

<div class="card">
    <div class="card__body" style="padding:0">
        <div class="tabs" data-tabs style="padding:0 20px">
            <?php $first = true; foreach ($types as $t): ?>
                <button type="button" class="tabs__item<?= $first ? ' is-active' : '' ?>" data-tab="terms-<?= (int) $t['id'] ?>">
                    <?= sanitize($t['name']) ?>
                    <span class="tabs__count"><?= (int) $t['version_count'] ?></span>
                </button>
            <?php $first = false; endforeach ?>
        </div>

        <?php $first = true; foreach ($types as $t):
            $versions = $versionsByType[(int) $t['id']] ?? [];
        ?>
        <div class="tab-panel<?= $first ? ' is-active' : '' ?>" data-panel="terms-<?= (int) $t['id'] ?>">
            <div style="padding:20px 24px;display:flex;justify-content:space-between;align-items:flex-start;gap:16px;flex-wrap:wrap">
                <div>
                    <h3 style="margin:0 0 4px"><?= sanitize($t['name']) ?></h3>
                    <p class="text-muted" style="margin:0;font-size:.85rem"><?= sanitize($t['description']) ?></p>
                    <div style="margin-top:10px;display:flex;gap:8px;flex-wrap:wrap">
                        <span class="badge <?= (int) $t['is_active'] === 1 ? 'badge--success' : 'badge--muted' ?>">
                            <?= (int) $t['is_active'] === 1 ? 'Active' : 'Inactive' ?>
                        </span>
                        <?php if ((int) $t['requires_acceptance'] === 1): ?>
                            <span class="badge badge--info">Acceptance required</span>
                        <?php endif ?>
                        <?php if (!empty($t['active_version_code'])): ?>
                            <span class="badge badge--primary">
                                Live: <?= sanitize($t['active_version_code']) ?>
                                <?php if (!empty($t['active_effective_from'])): ?>
                                    · from <?= formatDate($t['active_effective_from']) ?>
                                <?php endif ?>
                            </span>
                        <?php else: ?>
                            <span class="badge badge--warning">Nothing published</span>
                        <?php endif ?>
                        <span class="text-muted" style="font-size:.78rem;align-self:center">
                            <code><?= sanitize($t['slug']) ?></code>
                            · <?= (int) $t['acceptance_count'] ?> acceptance<?= (int) $t['acceptance_count'] === 1 ? '' : 's' ?>
                        </span>
                    </div>
                </div>
                <div class="btn-group">
                    <a class="btn btn--primary btn--sm" href="<?= APP_URL ?>/index.php?page=legal&amp;action=create&amp;doc=<?= (int) $t['id'] ?>">
                        <i class="bi bi-plus-lg"></i> New version
                    </a>
                    <a class="btn btn--outline btn--sm" title="Edit type"
                       href="<?= APP_URL ?>/index.php?page=legal&amp;modal=type&amp;id=<?= (int) $t['id'] ?>">
                        <i class="bi bi-pencil"></i>
                    </a>
                    <form method="post" action="<?= APP_URL ?>/index.php?page=legal&amp;action=toggle-type" style="display:inline">
                        <?= csrfField() ?>
                        <input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
                        <button class="btn btn--outline btn--sm" title="<?= (int) $t['is_active'] === 1 ? 'Deactivate' : 'Reactivate' ?>">
                            <i class="bi <?= (int) $t['is_active'] === 1 ? 'bi-toggle-on' : 'bi-toggle-off' ?>"></i>
                        </button>
                    </form>
                </div>
            </div>

            <?php if (empty($versions)): ?>
                <div class="empty-state">
                    <div class="empty-state__icon"><i class="bi bi-file-earmark-text"></i></div>
                    <div class="empty-state__title">No versions yet</div>
                    <div class="empty-state__desc">Write the first version of these terms to publish them.</div>
                    <a class="btn btn--primary btn--sm" style="margin-top:14px"
                       href="<?= APP_URL ?>/index.php?page=legal&amp;action=create&amp;doc=<?= (int) $t['id'] ?>">
                        <i class="bi bi-plus-lg"></i> Write version 1
                    </a>
                </div>
            <?php else: ?>
            <div class="table-wrap">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Version</th><th>Title</th><th>Status</th>
                            <th>Effective</th><th>Acceptances</th><th>Author</th><th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($versions as $v): ?>
                        <tr>
                            <td><code><?= sanitize($v['version_code']) ?></code></td>
                            <td>
                                <a href="<?= APP_URL ?>/index.php?page=legal&amp;action=version&amp;id=<?= (int) $v['id'] ?>">
                                    <?= sanitize($v['title']) ?>
                                </a>
                                <?php if (!empty($v['summary'])): ?>
                                    <div class="text-muted" style="font-size:.75rem"><?= sanitize($v['summary']) ?></div>
                                <?php endif ?>
                            </td>
                            <td><span class="badge <?= getStatusBadgeClass($v['status']) ?>"><?= ucfirst($v['status']) ?></span></td>
                            <td>
                                <?= formatDate($v['effective_from']) ?>
                                <?php if (!empty($v['effective_to'])): ?>
                                    <div class="text-muted" style="font-size:.72rem">until <?= formatDate($v['effective_to']) ?></div>
                                <?php endif ?>
                            </td>
                            <td>
                                <?php if ((int) $v['acceptance_count'] > 0): ?>
                                    <a href="<?= APP_URL ?>/index.php?page=legal&amp;action=acceptances&amp;id=<?= (int) $v['id'] ?>">
                                        <?= (int) $v['acceptance_count'] ?>
                                    </a>
                                <?php else: ?>
                                    <span class="text-subtle">0</span>
                                <?php endif ?>
                            </td>
                            <td class="text-muted" style="font-size:.8rem"><?= sanitize($v['created_by_name'] ?? '—') ?></td>
                            <td>
                                <div class="btn-group">
                                    <a class="btn btn--outline btn--sm" title="View"
                                       href="<?= APP_URL ?>/index.php?page=legal&amp;action=version&amp;id=<?= (int) $v['id'] ?>">
                                        <i class="bi bi-eye"></i>
                                    </a>
                                    <?php if ($v['status'] === 'draft'): ?>
                                        <a class="btn btn--outline btn--sm" title="Edit draft"
                                           href="<?= APP_URL ?>/index.php?page=legal&amp;action=edit&amp;id=<?= (int) $v['id'] ?>">
                                            <i class="bi bi-pencil"></i>
                                        </a>
                                        <form method="post" action="<?= APP_URL ?>/index.php?page=legal&amp;action=publish" style="display:inline">
                                            <?= csrfField() ?>
                                            <input type="hidden" name="id" value="<?= (int) $v['id'] ?>">
                                            <button class="btn btn--success btn--sm" title="Publish"
                                                    onclick="return confirm('Publish <?= sanitize($v['version_code']) ?>? Any live version is superseded and kept on record.')">
                                                <i class="bi bi-send"></i>
                                            </button>
                                        </form>
                                    <?php else: ?>
                                        <form method="post" action="<?= APP_URL ?>/index.php?page=legal&amp;action=revise" style="display:inline">
                                            <?= csrfField() ?>
                                            <input type="hidden" name="id" value="<?= (int) $v['id'] ?>">
                                            <button class="btn btn--outline btn--sm" title="Start a new draft from this wording">
                                                <i class="bi bi-files"></i>
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
        </div>
        <?php $first = false; endforeach ?>
    </div>
</div>
<?php endif ?>

<?php require __DIR__ . '/_type_modal.php'; ?>
