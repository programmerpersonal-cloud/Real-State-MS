<?php
/**
 * Documents — Index
 *
 * Expects: $documents, $filters, $categories, $categoryMeta, $properties,
 *          $page, $totalPages, $totalCount, $formData, $openUploadModal
 */
$counts = (new Document())->expiryCounts();
?>
<?php if ($counts['expired'] > 0 || $counts['expiring'] > 0): ?>
<div class="alert alert--warning" style="margin-bottom:16px">
    <i class="bi bi-exclamation-triangle"></i>
    <div>
        <?php if ($counts['expired'] > 0): ?>
            <strong><?= $counts['expired'] ?></strong> document<?= $counts['expired'] === 1 ? ' has' : 's have' ?> expired.
            <a href="<?= APP_URL ?>/index.php?page=documents&amp;state=expired">Review</a>.
        <?php endif ?>
        <?php if ($counts['expiring'] > 0): ?>
            <strong><?= $counts['expiring'] ?></strong> expiring within <?= documentExpiryWarningDays() ?> days.
            <a href="<?= APP_URL ?>/index.php?page=documents&amp;state=expiring">Review</a>.
        <?php endif ?>
        Nothing is deleted automatically.
    </div>
</div>
<?php endif ?>

<form method="get" class="filter-bar">
    <input type="hidden" name="page" value="documents">
    <div class="form-group">
        <label class="form-label">Search</label>
        <input class="form-control" name="search" value="<?= sanitize($filters['search'] ?? '') ?>"
               placeholder="Name, reference, code…">
    </div>
    <div class="form-group">
        <label class="form-label">Category</label>
        <select class="form-control" name="category_id">
            <option value="">All</option>
            <?php foreach ($categories as $cid => $cname): ?>
                <option value="<?= (int) $cid ?>" <?= (int) ($filters['category_id'] ?? 0) === (int) $cid ? 'selected' : '' ?>>
                    <?= sanitize($cname) ?>
                </option>
            <?php endforeach ?>
        </select>
    </div>
    <div class="form-group">
        <label class="form-label">Status</label>
        <select class="form-control" name="state">
            <option value="">All</option>
            <?php foreach (['active' => 'Active', 'expiring' => 'Expiring soon', 'expired' => 'Expired', 'archived' => 'Archived'] as $k => $label): ?>
                <option value="<?= $k ?>" <?= ($filters['state'] ?? '') === $k ? 'selected' : '' ?>><?= $label ?></option>
            <?php endforeach ?>
        </select>
    </div>
    <div class="form-group">
        <label class="form-label">Visibility</label>
        <select class="form-control" name="visibility">
            <option value="">All</option>
            <?php foreach (DOC_VISIBILITIES as $k => $label): ?>
                <?php if (!in_array($k, documentVisibilityScope(), true)) continue; ?>
                <option value="<?= $k ?>" <?= ($filters['visibility'] ?? '') === $k ? 'selected' : '' ?>><?= sanitize($label) ?></option>
            <?php endforeach ?>
        </select>
    </div>
    <button class="btn btn--primary"><i class="bi bi-funnel"></i> Filter</button>
    <a class="btn btn--outline" href="<?= APP_URL ?>/index.php?page=document-categories">
        <i class="bi bi-tags"></i> Categories
    </a>
</form>

<div class="card">
    <div class="card__header">
        <h3 class="card__title"><?= $totalCount ?> Document<?= $totalCount === 1 ? '' : 's' ?></h3>
        <span class="text-muted" style="font-size:.8rem">
            <i class="bi bi-shield-lock"></i> Served through an authorised endpoint, never by direct URL
        </span>
    </div>
    <div class="card__body" style="padding:0">
        <?php require __DIR__ . '/_list.php'; ?>
        <?php if (!empty($documents)): ?>
            <div style="padding:0 20px"><?php require VIEWS_PATH . '/components/pagination.php' ?></div>
        <?php endif ?>
    </div>
</div>

<?php require __DIR__ . '/_upload_modal.php'; ?>
