<?php
/**
 * Inquiries — Index
 *
 * One list for every role. What changes between them is the rows (cut by
 * inquiryViewScope) and the columns worth showing: the sender's own enquiry
 * does not need a "From" column naming themselves, and an owner has no use for
 * the internal status the desk works to.
 *
 * Expects: $inquiries, $filters, $totalCount, $scopeHint, $emptyMessage
 */
$isStaff = canAny('inquiries.reply');   // admin/agent: the people working the queue
?>
<form method="get" class="filter-bar">
    <input type="hidden" name="page" value="inquiries">
    <div class="form-group">
        <label class="form-label">Search</label>
        <input class="form-control" name="search" value="<?= sanitize($filters['search'] ?? '') ?>" placeholder="Name, subject…">
    </div>
    <div class="form-group">
        <label class="form-label">Status</label>
        <select class="form-control" name="status">
            <option value="">All</option>
            <?php foreach (['open','pending','replied','closed'] as $s): ?>
                <option value="<?= $s ?>" <?= ($filters['status'] ?? '') === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
            <?php endforeach ?>
        </select>
    </div>
    <button class="btn btn--primary"><i class="bi bi-funnel"></i> Filter</button>
</form>

<div class="card">
    <div class="card__header">
        <div>
            <h3 class="card__title"><?= $totalCount ?> Inquir<?= $totalCount === 1 ? 'y' : 'ies' ?></h3>
            <?php if (!empty($scopeHint)): ?>
                <div class="card__subtitle"><?= sanitize($scopeHint) ?></div>
            <?php endif ?>
        </div>
        <?php if (can('inquiries.create')): ?>
            <a class="btn btn--primary btn--sm" href="<?= APP_URL ?>/index.php?page=inquiries&action=create">
                <i class="bi bi-plus-lg"></i> New Inquiry
            </a>
        <?php endif ?>
    </div>
    <div class="card__body" style="padding:0">
        <?php if (empty($inquiries)): ?>
            <div class="empty-state">
                <div class="empty-state__icon"><i class="bi bi-chat-left-text"></i></div>
                <div class="empty-state__title">No inquiries</div>
                <?php if (!empty($emptyMessage)): ?>
                    <p class="empty-state__desc"><?= sanitize($emptyMessage) ?></p>
                <?php endif ?>
            </div>
        <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead><tr>
                    <?php if ($isStaff): ?><th>From</th><?php endif ?>
                    <th>Property</th><th>Subject</th><th>Message</th><th>Received</th>
                    <?php if ($isStaff): ?><th>Status</th><?php endif ?>
                    <th></th>
                </tr></thead>
                <tbody>
                <?php foreach ($inquiries as $i): ?>
                <tr>
                    <?php if ($isStaff): ?>
                        <td><strong><?= sanitize($i['name'] ?? $i['customer_name'] ?? '—') ?></strong><div class="text-muted" style="font-size:.75rem"><?= sanitize($i['email'] ?: $i['phone']) ?></div></td>
                    <?php endif ?>
                    <td><?= sanitize($i['property_title'] ?? '—') ?></td>
                    <td><?= sanitize($i['subject'] ?: '—') ?></td>
                    <td><?= sanitize(truncate($i['message'], 60)) ?></td>
                    <td><?= formatDateTime($i['created_at']) ?></td>
                    <?php if ($isStaff): ?>
                        <td><span class="badge <?= getStatusBadgeClass($i['status']) ?>"><?= ucfirst($i['status']) ?></span></td>
                    <?php endif ?>
                    <td><a class="btn btn--outline btn--sm" href="<?= APP_URL ?>/index.php?page=inquiries&action=show&id=<?= $i['id'] ?>"><i class="bi bi-eye"></i></a></td>
                </tr>
                <?php endforeach ?>
                </tbody>
            </table>
        </div>
        <div style="padding:0 20px"><?php require VIEWS_PATH . '/components/pagination.php' ?></div>
        <?php endif ?>
    </div>
</div>
