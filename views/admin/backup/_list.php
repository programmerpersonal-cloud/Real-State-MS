<?php
/**
 * The backup register.
 *
 * Five columns and a menu. The Status column carries two facts, not one — what
 * the run did and whether the archive has been proved — because "completed"
 * and "verified" are different promises and collapsing them is how an
 * unverified archive ends up being trusted during an incident.
 *
 * Every state-changing action is a CSRF-signed POST through uiRowActions(),
 * never a link: an `?action=delete&id=…` that a prefetcher can follow is a
 * deleted backup nobody asked for.
 *
 * Expects: $backups, $activeType, $canRestore, $canDelete, $canVerify,
 *          $canManage, $canCreate, $listUrl, $busy
 */
$typeIcon = [
    'full'     => 'bi-box-seam',
    'database' => 'bi-database',
    'files'    => 'bi-folder2-open',
];

/* One reading of a row's real state, shared by the badge and the actions so
   the two cannot disagree — a row that shows "Failed" must not also offer
   Restore. */
$stateOf = static function (array $b): array {
    if ($b['status'] === 'deleted')  return ['key' => 'archived', 'label' => 'Deleted',    'note' => 'Archive removed'];
    if ($b['status'] === 'failed')   return ['key' => 'rejected', 'label' => 'Failed',     'note' => (string) ($b['failure_message'] ?: $b['verification_note'] ?: '')];
    if ($b['status'] === 'running')  return ['key' => 'pending',  'label' => 'Running',    'note' => 'In progress'];
    if ($b['status'] === 'pending')  return ['key' => 'pending',  'label' => 'Queued',     'note' => ''];
    if ($b['verification_status'] === 'passed') return ['key' => 'approved', 'label' => 'Verified', 'note' => ''];

    // Completed but unproved. Deliberately amber: it is not a failure, and it
    // is not something to rely on either.
    return ['key' => 'under_review', 'label' => 'Unverified', 'note' => 'Not yet proved'];
};
?>
<?php if (empty($backups)): ?>
    <?= uiEmptyState([
        'icon'     => 'bi-shield-check',
        'filtered' => $activeType !== '',
        'title'    => $activeType !== '' ? 'No backups of this kind' : 'No backups yet',
        'desc'     => $activeType !== ''
            ? 'Nothing has been backed up under this type. Other types may still hold recovery points.'
            : 'Nothing has been backed up. Until a backup exists and passes verification, there is no way to recover this system.',
        'clearUrl' => $listUrl,
        'actions'  => ($canCreate && !$busy) ? [[
            'label' => 'Create the first backup', 'icon' => 'bi-plus-lg',
            'url'   => $listUrl . '&modal=create',
            'attrs' => ['data-modal-open' => 'backupCreateModal'],
        ]] : [],
    ]) ?>
<?php else: ?>
<div class="table-wrap">
    <table class="table">
        <thead>
            <tr>
                <?= uiSortHeader('Backup Name', ['asc' => 'name_asc', 'desc' => 'name_desc']) ?>
                <th class="col-mid">Type</th>
                <?= uiSortHeader('Size', ['desc' => 'largest', 'asc' => 'smallest'], 'sort', 'col-lo') ?>
                <th>Status</th>
                <?= uiSortHeader('Created', ['desc' => 'newest', 'asc' => 'oldest'], 'sort', 'cell-date col-mid') ?>
                <th class="cell-actions"><span class="sr-only">Actions</span></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($backups as $b):
            $state   = $stateOf($b);
            $pid     = (string) $b['public_id'];
            $gone    = $b['status'] === 'deleted';
            $usable  = $b['status'] === 'verified' && $b['verification_status'] === 'passed';
            $hasFile = !$gone && !empty($b['file_name']);
            $post    = ['id' => $pid, 'return_type' => $activeType];
        ?>
            <tr<?= $gone ? ' class="is-muted-row"' : '' ?>>
                <td>
                    <span class="filecell">
                        <i class="bi <?= $typeIcon[$b['type']] ?? 'bi-archive' ?> filecell__icon" aria-hidden="true"></i>
                        <span class="filecell__body">
                            <span class="cell-strong"><?= sanitize($b['name']) ?></span>
                            <span class="person__meta">
                                <?php if ($b['source'] === 'scheduled'): ?>
                                    <i class="bi bi-calendar-event" aria-hidden="true"></i> Scheduled
                                <?php elseif ($b['source'] === 'emergency'): ?>
                                    <i class="bi bi-life-preserver" aria-hidden="true"></i> Pre-restore safety copy
                                <?php else: ?>
                                    <i class="bi bi-person" aria-hidden="true"></i>
                                    <?= sanitize($b['created_by_name'] ?? 'Manual') ?>
                                <?php endif ?>
                                <?php if (!empty($b['is_protected'])): ?>
                                    · <i class="bi bi-lock-fill" aria-hidden="true"></i> Protected
                                <?php endif ?>
                            </span>
                        </span>
                    </span>
                </td>
                <td class="col-mid">
                    <span class="tag"><?= sanitize(backupTypes()[$b['type']] ?? $b['type']) ?></span>
                    <?php if ((int) $b['entry_count'] > 0): ?>
                        <div class="person__meta"><?= number_format((int) $b['entry_count']) ?> entries</div>
                    <?php endif ?>
                </td>
                <td class="col-lo cell-num">
                    <?= $hasFile ? formatBytes((int) $b['file_size']) : '<span class="text-subtle">—</span>' ?>
                </td>
                <td>
                    <?= uiStatus($state['key'], $state['label']) ?>
                    <?php if ($state['note'] !== ''): ?>
                        <div class="person__meta cell-clip" title="<?= sanitize($state['note']) ?>">
                            <?= sanitize(truncate($state['note'], 48)) ?>
                        </div>
                    <?php endif ?>
                </td>
                <td class="cell-date col-mid">
                    <?= backupWhen($b['created_at'], 'M d, Y') ?>
                    <div class="person__meta">
                        <?= backupAgo($b['completed_at'] ?: $b['created_at']) ?>
                        <?php if (!empty($b['expires_at']) && empty($b['is_protected']) && !$gone): ?>
                            · expires <?= backupWhen($b['expires_at'], 'M d, Y') ?>
                        <?php endif ?>
                    </div>
                </td>
                <td class="cell-actions">
                    <?php
                    $actions = [];

                    if ($hasFile) {
                        // Gated on backup.restore, not backup.view — holding the
                        // archive is equivalent to being able to restore it
                        // elsewhere. See BackupController's class comment.
                        $actions[] = ['label' => 'Download', 'icon' => 'bi-download',
                            'can' => 'backup.restore',
                            'url' => $listUrl . '&action=download&id=' . urlencode($pid)];
                    }

                    if ($hasFile) {
                        $actions[] = ['label' => 'Verify now', 'icon' => 'bi-patch-check',
                            'can' => 'backup.verify', 'method' => 'post', 'fields' => $post,
                            'url' => $listUrl . '&action=verify'];
                    }

                    if ($usable && $canRestore && !$busy) {
                        // Opens the restore dialog pre-filled with this row.
                        // Not a POST: the dialog is where the confirmation
                        // phrase and password are collected, and the POST
                        // happens from there.
                        $actions[] = ['label' => 'Restore from this', 'icon' => 'bi-arrow-counterclockwise',
                            'url' => $listUrl . '&modal=restore&id=' . urlencode($pid),
                            'attrs' => [
                                'data-modal-open'  => 'backupRestoreModal',
                                'data-fill-id'     => $pid,
                                'data-fill-record' => $b['name'] . ' · ' . (backupTypes()[$b['type']] ?? ''),
                                'data-backup-type' => $b['type'],
                            ]];
                    }

                    if ($hasFile && $canManage) {
                        $actions[] = empty($b['is_protected'])
                            ? ['label' => 'Protect from cleanup', 'icon' => 'bi-lock',
                               'method' => 'post', 'fields' => $post + ['protect' => 1],
                               'url' => $listUrl . '&action=protect']
                            : ['label' => 'Release retention hold', 'icon' => 'bi-unlock',
                               'method' => 'post', 'fields' => $post + ['protect' => 0],
                               'url' => $listUrl . '&action=protect',
                               'confirm' => [
                                   'title'  => 'Release the retention hold?',
                                   'action' => 'Release hold',
                                   'record' => $b['name'],
                                   'tone'   => 'warning',
                                   'body'   => 'This backup becomes eligible for automatic cleanup again and may be removed by the next retention sweep.',
                               ]];
                    }

                    if ($hasFile && $canDelete) {
                        $actions[] = ['label' => 'Delete permanently', 'icon' => 'bi-trash',
                            'can' => 'backup.delete', 'method' => 'post', 'danger' => true,
                            'fields' => $post,
                            'url' => $listUrl . '&action=delete',
                            'confirm' => [
                                'title'  => 'Delete this backup permanently?',
                                'action' => 'Delete permanently',
                                'record' => $b['name'],
                                'tone'   => 'danger',
                                'body'   => 'The archive is erased from disk and cannot be recovered. If this is the newest verified backup, the system will be left with an older recovery point or none at all.',
                            ]];
                    }
                    ?>
                    <?= uiRowActions($actions, 'Actions for ' . $b['name']) ?>
                </td>
            </tr>
        <?php endforeach ?>
        </tbody>
    </table>
</div>
<?php endif ?>
