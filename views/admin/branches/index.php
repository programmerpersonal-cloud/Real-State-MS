<?php
/**
 * Branches — the offices.
 *
 * Short enough that it needs no search or pagination, so it gets neither.
 * What it does need is the thing the old list omitted entirely: how many
 * people are actually attached to each branch, which is the difference
 * between a branch record and a branch.
 *
 * Vars from BranchController::index().
 */
$fd   = $formData ?? [];
$errs = $formErrors ?? [];

$listUrl = APP_URL . '/index.php?page=branches';
?>

<div class="table-card">
    <?php if (empty($branches)): ?>
        <?= uiEmptyState([
            'icon'  => 'bi-diagram-3',
            'title' => 'No branches yet',
            'desc'  => 'A branch groups staff and the properties they handle. Add the first one to start tracking more than one location.',
            'actions' => [[
                'label' => 'Add Branch', 'icon' => 'bi-plus-lg', 'can' => 'branches.create',
                'url'   => $listUrl . '&action=create',
                'attrs' => ['data-modal-open' => 'branchCreateModal'],
            ]],
        ]) ?>
    <?php else: ?>
        <div class="table-head">
            <div class="table-head__title">
                <?= count($branches) ?> <?= count($branches) === 1 ? 'branch' : 'branches' ?>
            </div>
        </div>

        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th>Branch</th>
                        <th class="col-mid">Manager</th>
                        <th class="col-mid">Contact</th>
                        <th class="cell-num">Staff</th>
                        <th>Status</th>
                        <th class="cell-actions"><span class="sr-only">Actions</span></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($branches as $b): ?>
                        <?php
                        $id    = (int) $b['id'];
                        $staff = (int) ($staffCounts[$id] ?? 0);
                        ?>
                        <tr>
                            <td>
                                <?php if (can('branches.edit')): ?>
                                    <a href="<?= $listUrl ?>&amp;action=edit&amp;id=<?= $id ?>" class="cell-strong">
                                        <?= sanitize($b['name']) ?>
                                    </a>
                                <?php else: ?>
                                    <span class="cell-strong"><?= sanitize($b['name']) ?></span>
                                <?php endif ?>
                                <?php if (!empty($b['address'])): ?>
                                    <div class="person__meta">
                                        <i class="bi bi-geo-alt" aria-hidden="true"></i> <?= sanitize($b['address']) ?>
                                    </div>
                                <?php endif ?>
                            </td>
                            <td class="col-mid">
                                <?php if (!empty($b['manager_name'])): ?>
                                    <?= sanitize($b['manager_name']) ?>
                                <?php else: ?>
                                    <span class="text-subtle">Unassigned</span>
                                <?php endif ?>
                            </td>
                            <td class="col-mid">
                                <div><?= sanitize($b['phone'] ?: '—') ?></div>
                                <?php if (!empty($b['email'])): ?>
                                    <div class="person__meta"><?= sanitize($b['email']) ?></div>
                                <?php endif ?>
                            </td>
                            <td class="cell-num">
                                <?php if ($staff > 0): ?>
                                    <a href="<?= APP_URL ?>/index.php?page=users">
                                        <?= number_format($staff) ?>
                                    </a>
                                <?php else: ?>
                                    <span class="text-subtle">—</span>
                                <?php endif ?>
                            </td>
                            <td>
                                <?= uiStatus($b['is_active'] ? 'active' : 'inactive',
                                             $b['is_active'] ? 'Open' : 'Closed') ?>
                            </td>
                            <td class="cell-actions">
                                <?= uiRowActions([
                                    ['label' => 'Edit branch', 'icon' => 'bi-pencil', 'can' => 'branches.edit',
                                     'url' => $listUrl . '&action=edit&id=' . $id],
                                ]) ?>
                            </td>
                        </tr>
                    <?php endforeach ?>
                </tbody>
            </table>
        </div>
    <?php endif ?>
</div>

<?php require __DIR__ . '/_create_modal.php'; ?>
