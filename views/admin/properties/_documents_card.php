<?php
/**
 * Documents card on the property detail page.
 *
 * Tabs split the documents by lifecycle state so an expiring permit is visible
 * without hunting. This is the first use of the .tabs / .tab-panel component
 * that already existed in style.css and main.js.
 *
 * Expects: $property, $documents, $documentStats
 *          $categories, $categoryMeta, $formData, $openUploadModal (staff only)
 */
$canManage = documentCanManage();
$stats     = $documentStats ?? ['total' => 0, 'active' => 0, 'expiring' => 0, 'expired' => 0, 'archived' => 0];

// One pass over the rows rather than four queries — the list is small and
// already loaded.
$buckets = ['active' => [], 'expiring' => [], 'expired' => [], 'archived' => []];
foreach ($documents as $d) {
    $buckets[documentStatus($d)['key']][] = $d;
}

// Only offer tabs that have something in them, so the strip does not fill with
// empty states. "All" is always first and always present.
$tabs = ['all' => ['label' => 'All', 'count' => count($documents)]];
foreach (['active' => 'Active', 'expiring' => 'Expiring', 'expired' => 'Expired', 'archived' => 'Archived'] as $key => $label) {
    if ($buckets[$key]) {
        $tabs[$key] = ['label' => $label, 'count' => count($buckets[$key])];
    }
}
?>
<div class="card mb-3">
    <div class="card__header">
        <div>
            <h3 class="card__title">
                <i class="bi bi-folder2-open"></i> Documents
            </h3>
            <?php if ($stats['expired'] > 0 || $stats['expiring'] > 0): ?>
                <p class="card__subtitle">
                    <?php if ($stats['expired'] > 0): ?>
                        <span style="color:var(--danger)">
                            <?= $stats['expired'] ?> expired
                        </span><?= $stats['expiring'] > 0 ? ' · ' : '' ?>
                    <?php endif ?>
                    <?php if ($stats['expiring'] > 0): ?>
                        <span style="color:var(--warning)">
                            <?= $stats['expiring'] ?> expiring within <?= documentExpiryWarningDays() ?> days
                        </span>
                    <?php endif ?>
                </p>
            <?php endif ?>
        </div>
        <?php if ($canManage): ?>
            <button type="button" class="btn btn--primary btn--sm" data-modal-open="documentUploadModal">
                <i class="bi bi-upload"></i> Upload
            </button>
        <?php endif ?>
    </div>

    <?php if (empty($documents)): ?>
        <div class="card__body card__body--flush">
            <div class="empty-state">
                <div class="empty-state__icon"><i class="bi bi-folder2-open"></i></div>
                <div class="empty-state__title">No documents on file</div>
                <div class="empty-state__desc">
                    <?php if ($canManage): ?>
                        Upload title deeds, agreements, permits and reports to keep them with this property.
                    <?php else: ?>
                        Nothing has been published for this property.
                    <?php endif ?>
                </div>
                <?php if ($canManage): ?>
                    <button type="button" class="btn btn--primary btn--sm mt-2" data-modal-open="documentUploadModal" >
                        <i class="bi bi-upload"></i> Upload Document
                    </button>
                <?php endif ?>
            </div>
        </div>
    <?php else: ?>
        <div class="card__body card__body--flush">
            <?php if (count($tabs) > 1): ?>
            <div class="tabs" data-tabs style="padding:0 20px">
                <?php $first = true; foreach ($tabs as $key => $tab): ?>
                    <button type="button" class="tabs__item<?= $first ? ' is-active' : '' ?>" data-tab="doc-<?= $key ?>">
                        <?= sanitize($tab['label']) ?>
                        <span class="tabs__count"><?= $tab['count'] ?></span>
                    </button>
                <?php $first = false; endforeach ?>
            </div>
            <?php endif ?>

            <?php
            // Shared by every panel below.
            $showProperty = false;
            $listProperty = $property;

            $first = true;
            foreach ($tabs as $key => $tab):
                $documentsForPanel = $key === 'all' ? $documents : $buckets[$key];
            ?>
                <div class="tab-panel<?= $first ? ' is-active' : '' ?>" data-panel="doc-<?= $key ?>">
                    <?php
                        $documents_backup = $documents;
                        $documents = $documentsForPanel;
                        $emptyTitle = 'Nothing here';
                        $emptyText  = 'No documents in this state.';
                        require VIEWS_PATH . '/admin/documents/_list.php';
                        $documents = $documents_backup;
                    ?>
                </div>
            <?php $first = false; endforeach ?>
        </div>

        <div class="card__footer" style="display:flex;justify-content:space-between;align-items:center;gap:12px">
            <span class="person__meta">
                <i class="bi bi-shield-lock"></i>
                Files are stored outside the public folder and served only to authorised users.
            </span>
            <?php if ($canManage): ?>
                <a class="btn btn--outline btn--sm"
                   href="<?= APP_URL ?>/index.php?page=documents&amp;property_id=<?= (int) $property['id'] ?>">
                    Open in Documents <i class="bi bi-arrow-right"></i>
                </a>
            <?php endif ?>
        </div>
    <?php endif ?>
</div>

<?php
// The upload popup lives outside the card so the card can sit inside a grid
// column without the dialog inheriting its width.
if ($canManage) {
    $fixedProperty = $property;
    require VIEWS_PATH . '/admin/documents/_upload_modal.php';
}
?>
