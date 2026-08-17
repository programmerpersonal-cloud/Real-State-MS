<?php
/**
 * Testimonials — the moderation queue.
 *
 * The only public content on the site that quotes a named person, so nothing
 * reaches the marketing pages without a decision here. Pending rows sort
 * first because they are the ones waiting on one.
 *
 * Vars from TestimonialController::index().
 */
$fd   = $formData ?? [];
$errs = $formErrors ?? [];

$listUrl = APP_URL . '/index.php?page=testimonials';
$pending = array_filter($testimonials, static fn(array $t): bool => !$t['is_approved']);

/* Counted from the rows already loaded when the list is unfiltered. Under a
   filter the page only holds one side of the split, so the counts come off
   the summary the controller already fetched instead of a new query. */
$publishedCount = (int) $summary['count'];
$pendingCount   = $filter === '' ? count($pending) : null;

$statusFilter = [
    'param'   => 'filter',
    'value'   => $filter,
    'options' => $filters,
    'counts'  => array_filter([
        'approved' => $publishedCount,
        'pending'  => $pendingCount,
    ], static fn($v): bool => $v !== null),
    'total'   => $filter === '' ? count($testimonials) : null,
    'all'     => 'All reviews',
    'tones'   => false,
];
?>

<div class="stats mb-2">
    <div class="stat-card">
        <div class="stat-card__icon stat-card__icon--success"><i class="bi bi-check-circle" aria-hidden="true"></i></div>
        <div class="stat-card__body">
            <div class="stat-card__value"><?= number_format($publishedCount) ?></div>
            <div class="stat-card__label">Live on the site</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-card__icon stat-card__icon--warning"><i class="bi bi-hourglass-split" aria-hidden="true"></i></div>
        <div class="stat-card__body">
            <div class="stat-card__value"><?= $pendingCount !== null ? number_format($pendingCount) : '—' ?></div>
            <div class="stat-card__label">Awaiting a decision</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-card__icon stat-card__icon--purple"><i class="bi bi-star" aria-hidden="true"></i></div>
        <div class="stat-card__body">
            <div class="stat-card__value"><?= $publishedCount ? number_format($summary['average'], 1) : '—' ?></div>
            <div class="stat-card__label">Published average</div>
        </div>
    </div>
</div>

<div class="alert alert--info">
    <i class="bi bi-globe" aria-hidden="true"></i>
    <div>
        Only approved reviews appear on the public site, and the star rating in the
        site's structured data is calculated from them — so an unapproved review
        changes nothing a visitor or a search engine sees.
    </div>
</div>

<?php require VIEWS_PATH . '/components/ui/status_filter.php'; ?>

<div class="table-card">
    <?php if (empty($testimonials)): ?>
        <?= uiEmptyState([
            'icon'     => 'bi-chat-quote',
            'filtered' => $filter !== '',
            'title'    => $filter !== '' ? 'Nothing in this state' : 'No reviews yet',
            'desc'     => $filter !== ''
                ? 'No review is currently ' . strtolower($filters[$filter] ?? 'here') . '.'
                : 'Add a real review from a customer. Nothing is published until you approve it, and the testimonials section stays hidden on the home page while this is empty.',
            'clearUrl' => $listUrl,
            'actions'  => [[
                'label' => 'Add the first review', 'icon' => 'bi-plus-lg', 'can' => 'testimonials.form',
                'url'   => $listUrl . '&action=form',
                'attrs' => ['data-modal-open' => 'testimonialCreateModal'],
            ]],
        ]) ?>
    <?php else: ?>
        <div class="table-head">
            <div class="table-head__title">
                <?= count($testimonials) ?> <?= count($testimonials) === 1 ? 'review' : 'reviews' ?>
                <?php if ($filter !== ''): ?><span class="table-head__count">matching</span><?php endif ?>
            </div>
        </div>

        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th>Author</th>
                        <th>Rating</th>
                        <th>Review</th>
                        <th>Related to</th>
                        <th class="cell-date">Added</th>
                        <th>Status</th>
                        <th class="cell-actions"><span class="sr-only">Actions</span></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($testimonials as $t): ?>
                        <?php
                        $id       = (int) $t['id'];
                        $approved = (bool) $t['is_approved'];
                        $rating   = max(0, min(5, (int) $t['rating']));
                        ?>
                        <tr>
                            <td>
                                <div class="cell-strong"><?= sanitize($t['author_name']) ?></div>
                                <?php if ($t['author_role']): ?>
                                    <div class="person__meta"><?= sanitize($t['author_role']) ?></div>
                                <?php endif ?>
                            </td>
                            <td class="cell-tight">
                                <span class="stars" role="img"
                                      aria-label="<?= $rating ?> out of 5 stars">
                                    <?php for ($i = 1; $i <= 5; $i++): ?>
                                        <i class="bi bi-star<?= $i <= $rating ? '-fill' : '' ?>" aria-hidden="true"></i>
                                    <?php endfor ?>
                                </span>
                            </td>
                            <td class="cell-clip"><?= sanitize(truncate($t['body'], 80)) ?></td>
                            <td>
                                <?php if (!empty($t['property_title'])): ?>
                                    <div><?= sanitize($t['property_title']) ?></div>
                                <?php endif ?>
                                <?php if (!empty($t['agent_name'])): ?>
                                    <div class="person__meta"><?= sanitize($t['agent_name']) ?></div>
                                <?php endif ?>
                                <?php if (empty($t['property_title']) && empty($t['agent_name'])): ?>
                                    <span class="text-subtle">—</span>
                                <?php endif ?>
                            </td>
                            <td class="cell-date"><?= formatDate($t['created_at']) ?></td>
                            <td>
                                <?= uiStatus($approved ? 'active' : 'pending',
                                             $approved ? 'Live' : 'Awaiting approval') ?>
                            </td>
                            <td class="cell-actions">
                                <?= uiRowActions([
                                    ['label' => 'Edit review', 'icon' => 'bi-pencil', 'can' => 'testimonials.form',
                                     'url' => $listUrl . '&action=form&id=' . $id],
                                    [
                                        'label' => $approved ? 'Hide from the site' : 'Publish to the site',
                                        'icon'  => $approved ? 'bi-eye-slash' : 'bi-check-lg',
                                        'can' => 'testimonials.approve', 'method' => 'post',
                                        'url' => $listUrl . '&action=approve',
                                        'fields' => ['id' => $id, 'approve' => $approved ? '' : '1'],
                                        'confirm' => $approved ? null : [
                                            'title'  => 'Publish this review?',
                                            'action' => 'Publish',
                                            'record' => $t['author_name'],
                                            'tone'   => 'primary',
                                            'body'   => 'It appears on the home page under this person\'s name, and counts towards the average rating shown to search engines.',
                                        ],
                                    ],
                                    ['label' => 'Delete review', 'icon' => 'bi-trash',
                                     'can' => 'testimonials.delete', 'method' => 'post', 'danger' => true,
                                     'url' => $listUrl . '&action=delete',
                                     'fields' => ['id' => $id],
                                     'confirm' => [
                                         'title'  => 'Delete this review permanently?',
                                         'action' => 'Delete review',
                                         'record' => $t['author_name'],
                                         'tone'   => 'danger',
                                         'body'   => 'The review is removed for good. To take it off the site without losing it, hide it instead.',
                                     ]],
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
