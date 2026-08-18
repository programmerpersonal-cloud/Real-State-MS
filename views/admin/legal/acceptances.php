<?php
/**
 * Terms acceptance log.
 *
 * Read-only by design — there is no edit or delete control anywhere on this
 * page, and no model method behind one either.
 *
 * Expects: $acceptances, $filters, $types, $version, $page, $totalPages, $totalCount
 */
?>
<div class="alert alert--info mb-2" >
    <i class="bi bi-shield-lock"></i>
    <div>
        Every row records the exact version accepted, with a copy of that version's content hash taken at
        the moment of acceptance. Revising the terms afterwards leaves these records untouched.
    </div>
</div>

<?php if ($version): ?>
<div class="card mb-3">
    <div class="card__body" style="display:flex;justify-content:space-between;align-items:center;gap:16px;flex-wrap:wrap">
        <div>
            <strong><?= sanitize($version['name']) ?> <code><?= sanitize($version['version_code']) ?></code></strong>
            <span class="badge <?= getStatusBadgeClass($version['status']) ?>" style="margin-left:8px"><?= ucfirst($version['status']) ?></span>
            <div class="text-muted" style="font-size:.8rem;margin-top:4px">
                Effective <?= formatDate($version['effective_from']) ?>
                <?= $version['effective_to'] ? ' until ' . formatDate($version['effective_to']) : '' ?>
            </div>
        </div>
        <div class="btn-group">
            <a class="btn btn--outline btn--sm" href="<?= APP_URL ?>/index.php?page=legal&amp;action=version&amp;id=<?= (int) $version['id'] ?>">
                <i class="bi bi-file-text"></i> Read this version
            </a>
            <a class="btn btn--outline btn--sm" href="<?= APP_URL ?>/index.php?page=legal&amp;action=acceptances">
                All acceptances
            </a>
        </div>
    </div>
</div>
<?php endif ?>

<?php
/* The last page still carrying a hand-built filter form. Moved onto the
   shared toolbar so it gains the same compact bar, the same control heights
   and the same popover behaviour as every other register — and so the legacy
   .filter-bar rules can be deleted rather than kept alive for one caller.

   `keep` carries the two parameters this view needs beyond ?page=: the action
   that selects the acceptance log, and the version being filtered to. Both
   were hidden inputs before and remain hidden inputs now, so the query string
   this form produces is unchanged. */
$toolbar = [
    'page' => 'legal',
    'keep' => array_filter([
        'action' => 'acceptances',
        'id'     => !empty($filters['terms_version_id']) ? (int) $filters['terms_version_id'] : '',
    ]),
    'search' => [
        'name'        => 'search',
        'value'       => $filters['search'] ?? '',
        'label'       => 'Search acceptances',
        'placeholder' => 'Search by person, version or terms…',
    ],
    'filters' => [
        ['name' => 'slug', 'label' => 'Terms type', 'value' => $filters['slug'] ?? '',
         'options' => array_column($types, 'name', 'slug'), 'all' => 'Any type'],
    ],
];
?>
<?php require VIEWS_PATH . '/components/ui/list_toolbar.php'; ?>

<div class="card">
    <div class="card__header">
        <h3 class="card__title"><?= $totalCount ?> Acceptance<?= $totalCount === 1 ? '' : 's' ?></h3>
    </div>
    <div class="card__body card__body--flush">
        <?php if (empty($acceptances)): ?>
            <div class="empty-state">
                <div class="empty-state__icon"><i class="bi bi-journal-check"></i></div>
                <div class="empty-state__title">No acceptances recorded</div>
                <div class="empty-state__desc">
                    Records appear here as customers agree to terms during a booking or other process.
                </div>
            </div>
        <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th>Accepted</th><th>Terms</th><th>Who</th>
                        <th>Context</th><th>Integrity</th><th>Origin</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($acceptances as $a):
                    $intact = hash_equals((string) $a['current_hash'], (string) $a['content_hash']);
                ?>
                    <tr>
                        <td>
                            <?= formatDateTime($a['accepted_at']) ?>
                        </td>
                        <td>
                            <a href="<?= APP_URL ?>/index.php?page=legal&amp;action=version&amp;id=<?= (int) $a['terms_version_id'] ?>">
                                <?= sanitize($a['terms_name']) ?>
                            </a>
                            <div class="person__meta">
                                <code><?= sanitize($a['version_code']) ?></code>
                                <span class="badge <?= getStatusBadgeClass($a['version_status']) ?>" style="font-size:.6rem"><?= ucfirst($a['version_status']) ?></span>
                            </div>
                        </td>
                        <td>
                            <?= sanitize($a['customer_name'] ?? $a['accepted_name'] ?: '—') ?>
                            <?php if (!empty($a['user_name'])): ?>
                                <div class="person__meta">recorded by <?= sanitize($a['user_name']) ?></div>
                            <?php endif ?>
                        </td>
                        <td>
                            <?php if (!empty($a['reference_type'])): ?>
                                <span class="tag"><?= sanitize(ucfirst($a['reference_type'])) ?> #<?= (int) $a['reference_id'] ?></span>
                            <?php else: ?>
                                <span class="text-subtle">—</span>
                            <?php endif ?>
                            <div class="person__meta"><?= sanitize($a['acceptance_method']) ?></div>
                        </td>
                        <td>
                            <?php if ($intact): ?>
                                <span class="badge badge--success" title="The recorded hash matches the stored version">
                                    <i class="bi bi-check2"></i> Verified
                                </span>
                            <?php else: ?>
                                <span class="badge badge--danger" title="The stored version no longer matches what was accepted">
                                    <i class="bi bi-exclamation-triangle"></i> Mismatch
                                </span>
                            <?php endif ?>
                            <div class="text-subtle" style="font-size:.65rem;margin-top:2px">
                                <?= sanitize(substr((string) $a['content_hash'], 0, 16)) ?>…
                            </div>
                        </td>
                        <td class="person__meta">
                            <?= sanitize($a['ip_address'] ?: '—') ?>
                        </td>
                    </tr>
                <?php endforeach ?>
                </tbody>
            </table>
        </div>
        <div style="padding:0 20px"><?php require VIEWS_PATH . '/components/pagination.php' ?></div>
        <?php endif ?>
    </div>
</div>
