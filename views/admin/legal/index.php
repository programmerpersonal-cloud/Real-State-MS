<?php
/**
 * Terms & Conditions — the version register.
 *
 * One tab per legal type, each holding that type's version history. The
 * arrangement matters more here than anywhere else in the system: an
 * acceptance record points at an exact version, so the page has to make it
 * obvious which wording is live, which is superseded, and which is still a
 * draft nobody has agreed to.
 *
 * Expects: $types, $versionsByType, $formData, $editingType, $openTypeModal
 */
$legalUrl = APP_URL . '/index.php?page=legal';

$actionButtons = [
    ['label' => 'Acceptance log', 'icon' => 'bi-journal-check', 'class' => 'btn--outline',
     'url' => $legalUrl . '&action=acceptances'],
    ['label' => 'Add type', 'icon' => 'bi-plus-lg', 'class' => 'btn--primary',
     'url' => $legalUrl . '&modal=new-type',
     'attrs' => ['data-modal-open' => 'termsTypeModal']],
];
?>
<div class="alert alert--info">
    <i class="bi bi-shield-check" aria-hidden="true"></i>
    <div>
        Published versions are never edited. Revising terms creates a new version and supersedes the old
        one, so every acceptance record keeps pointing at the exact wording that was agreed to.
    </div>
</div>

<?php if (empty($types)): ?>
    <div class="table-card">
        <?= uiEmptyState([
            'icon'  => 'bi-file-earmark-check',
            'title' => 'No terms configured',
            'desc'  => 'A legal type is a document the business keeps versions of — booking terms, a privacy notice, a tenancy agreement. Add one, then write its first version.',
            'actions' => [[
                'label' => 'Add type', 'icon' => 'bi-plus-lg',
                'url'   => $legalUrl . '&modal=new-type',
                'attrs' => ['data-modal-open' => 'termsTypeModal'],
            ]],
        ]) ?>
    </div>
<?php else: ?>

<div class="tabs" data-tabs role="tablist">
    <?php $first = true; foreach ($types as $t): ?>
        <button type="button" class="tabs__item<?= $first ? ' is-active' : '' ?>"
                data-tab="terms-<?= (int) $t['id'] ?>" role="tab"
                aria-selected="<?= $first ? 'true' : 'false' ?>">
            <?= sanitize($t['name']) ?>
            <span class="tabs__count"><?= (int) $t['version_count'] ?></span>
        </button>
    <?php $first = false; endforeach ?>
</div>

<?php $first = true; foreach ($types as $t):
    $typeId   = (int) $t['id'];
    $versions = $versionsByType[$typeId] ?? [];
    $isLive   = (int) $t['is_active'] === 1;
?>
<div class="tab-panel<?= $first ? ' is-active' : '' ?>" data-panel="terms-<?= $typeId ?>">
    <div class="detail-header detail-header--compact">
        <div class="detail-header__body">
            <div class="detail-header__eyebrow"><?= sanitize($t['slug']) ?></div>
            <h2 class="detail-header__title"><?= sanitize($t['name']) ?></h2>
            <?php if (!empty($t['description'])): ?>
                <p class="detail-header__lede"><?= sanitize($t['description']) ?></p>
            <?php endif ?>

            <div class="detail-header__meta">
                <?= uiStatus($isLive ? 'active' : 'inactive', $isLive ? 'In use' : 'Not in use') ?>
                <?php if ((int) $t['requires_acceptance'] === 1): ?>
                    <?= uiStatus('assigned', 'Acceptance required') ?>
                <?php endif ?>
                <?php if (!empty($t['active_version_code'])): ?>
                    <?= uiStatus('approved', 'Live: ' . $t['active_version_code']
                        . (!empty($t['active_effective_from']) ? ' · from ' . formatDate($t['active_effective_from']) : '')) ?>
                <?php else: ?>
                    <?= uiStatus('pending', 'Nothing published') ?>
                <?php endif ?>
                <span>
                    <i class="bi bi-journal-check" aria-hidden="true"></i>
                    <?= number_format((int) $t['acceptance_count']) ?>
                    acceptance<?= (int) $t['acceptance_count'] === 1 ? '' : 's' ?> on record
                </span>
            </div>
        </div>

        <div class="detail-header__actions">
            <a class="btn btn--primary btn--sm" href="<?= $legalUrl ?>&amp;action=create&amp;doc=<?= $typeId ?>">
                <i class="bi bi-plus-lg" aria-hidden="true"></i> New version
            </a>
            <?= uiRowActions([
                ['label' => 'Edit this type', 'icon' => 'bi-pencil',
                 'url' => $legalUrl . '&modal=type&id=' . $typeId],
                [
                    'label'  => $isLive ? 'Stop using this type' : 'Start using this type',
                    'icon'   => $isLive ? 'bi-toggle-off' : 'bi-toggle-on',
                    'method' => 'post', 'danger' => $isLive,
                    'url' => $legalUrl . '&action=toggle-type',
                    'fields' => ['id' => $typeId],
                    'confirm' => $isLive ? [
                        'title'  => 'Stop using these terms?',
                        'action' => 'Stop using',
                        'record' => $t['name'],
                        'tone'   => 'warning',
                        'body'   => 'They stop being presented for acceptance. Every version and every acceptance already on record is kept exactly as it is.',
                    ] : null,
                ],
            ], 'Actions for ' . $t['name']) ?>
        </div>
    </div>

    <div class="table-card">
        <?php if (empty($versions)): ?>
            <?= uiEmptyState([
                'icon'  => 'bi-file-earmark-text',
                'title' => 'No versions yet',
                'desc'  => 'Write the first version of these terms to publish them. Until then nothing is presented for acceptance.',
                'actions' => [[
                    'label' => 'Write version 1', 'icon' => 'bi-plus-lg',
                    'url'   => $legalUrl . '&action=create&doc=' . $typeId,
                ]],
            ]) ?>
        <?php else: ?>
            <div class="table-wrap">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Version</th>
                            <th>Title</th>
                            <th>Status</th>
                            <th class="cell-date">Effective</th>
                            <th class="cell-num">Accepted by</th>
                            <th>Written by</th>
                            <th class="cell-actions"><span class="sr-only">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($versions as $v): ?>
                            <?php
                            $vid     = (int) $v['id'];
                            $isDraft = $v['status'] === 'draft';
                            $accepts = (int) $v['acceptance_count'];
                            ?>
                            <tr>
                                <td class="cell-tight">
                                    <a href="<?= $legalUrl ?>&amp;action=version&amp;id=<?= $vid ?>" class="table__id">
                                        <?= sanitize($v['version_code']) ?>
                                    </a>
                                </td>
                                <td class="cell-clip">
                                    <a href="<?= $legalUrl ?>&amp;action=version&amp;id=<?= $vid ?>" class="cell-strong">
                                        <?= sanitize($v['title']) ?>
                                    </a>
                                    <?php if (!empty($v['summary'])): ?>
                                        <div class="person__meta"><?= sanitize($v['summary']) ?></div>
                                    <?php endif ?>
                                </td>
                                <td><?= uiStatus($v['status']) ?></td>
                                <td class="cell-date">
                                    <?= formatDate($v['effective_from']) ?>
                                    <?php if (!empty($v['effective_to'])): ?>
                                        <div class="person__meta">until <?= formatDate($v['effective_to']) ?></div>
                                    <?php endif ?>
                                </td>
                                <td class="cell-num">
                                    <?php if ($accepts > 0): ?>
                                        <a href="<?= $legalUrl ?>&amp;action=acceptances&amp;id=<?= $vid ?>">
                                            <?= number_format($accepts) ?>
                                        </a>
                                    <?php else: ?>
                                        <span class="text-subtle">—</span>
                                    <?php endif ?>
                                </td>
                                <td><span class="person__meta"><?= sanitize($v['created_by_name'] ?? '—') ?></span></td>
                                <td class="cell-actions">
                                    <?= uiRowActions(array_merge(
                                        [['label' => 'Read this version', 'icon' => 'bi-eye',
                                          'url' => $legalUrl . '&action=version&id=' . $vid]],
                                        $isDraft ? [
                                            ['label' => 'Edit draft', 'icon' => 'bi-pencil',
                                             'url' => $legalUrl . '&action=edit&id=' . $vid],
                                            ['label' => 'Publish', 'icon' => 'bi-send',
                                             'method' => 'post',
                                             'url' => $legalUrl . '&action=publish',
                                             'fields' => ['id' => $vid],
                                             'confirm' => [
                                                 'title'  => 'Publish these terms?',
                                                 'action' => 'Publish',
                                                 'record' => $v['version_code'] . ' · ' . $v['title'],
                                                 'tone'   => 'primary',
                                                 'body'   => 'This becomes the wording presented for acceptance from its effective date. Any version currently live is superseded and kept on record — nothing already accepted changes.',
                                             ]],
                                        ] : [
                                            ['label' => 'Revise — start a new draft', 'icon' => 'bi-files',
                                             'method' => 'post',
                                             'url' => $legalUrl . '&action=revise',
                                             'fields' => ['id' => $vid],
                                             'confirm' => [
                                                 'title'  => 'Start a new draft from this wording?',
                                                 'action' => 'Create draft',
                                                 'record' => $v['version_code'],
                                                 'tone'   => 'primary',
                                                 'body'   => 'A copy is created as a draft for you to edit. This version stays exactly as it is until the new one is published.',
                                             ]],
                                        ]
                                    ), 'Actions for ' . $v['version_code']) ?>
                                </td>
                            </tr>
                        <?php endforeach ?>
                    </tbody>
                </table>
            </div>
        <?php endif ?>
    </div>
</div>
<?php $first = false; endforeach ?>
<?php endif ?>

<?php require __DIR__ . '/_type_modal.php'; ?>
