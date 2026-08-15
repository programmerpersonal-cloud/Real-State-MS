<?php
/**
 * Testimonials — Index (admin)
 *
 * Moderation queue for the reviews shown on the public home page. Pending
 * rows sort first because they are the ones needing a decision.
 */
$pending = array_filter($testimonials, fn($t) => !$t['is_approved']);
?>

<div class="stats" style="margin-bottom:20px">
    <div class="stat-card">
        <div class="stat-card__icon stat-card__icon--primary"><i class="bi bi-chat-quote"></i></div>
        <div class="stat-card__body">
            <div class="stat-card__value"><?= count($testimonials) ?></div>
            <div class="stat-card__label">Total reviews</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-card__icon stat-card__icon--success"><i class="bi bi-check-circle"></i></div>
        <div class="stat-card__body">
            <div class="stat-card__value"><?= (int) $summary['count'] ?></div>
            <div class="stat-card__label">Published</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-card__icon stat-card__icon--warning"><i class="bi bi-hourglass-split"></i></div>
        <div class="stat-card__body">
            <div class="stat-card__value"><?= count($pending) ?></div>
            <div class="stat-card__label">Awaiting approval</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-card__icon stat-card__icon--purple"><i class="bi bi-star"></i></div>
        <div class="stat-card__body">
            <div class="stat-card__value"><?= $summary['count'] ? number_format($summary['average'], 1) : '—' ?></div>
            <div class="stat-card__label">Average rating</div>
        </div>
    </div>
</div>

<div class="toolbar" style="margin-bottom:16px">
    <form method="get" class="filter-bar" style="margin:0">
        <input type="hidden" name="page" value="testimonials">
        <div class="form-group">
            <label class="form-label">Show</label>
            <select class="form-control" name="filter" onchange="this.form.submit()">
                <option value=""         <?= $filter === ''         ? 'selected' : '' ?>>All reviews</option>
                <option value="pending"  <?= $filter === 'pending'  ? 'selected' : '' ?>>Awaiting approval</option>
                <option value="approved" <?= $filter === 'approved' ? 'selected' : '' ?>>Published</option>
            </select>
        </div>
    </form>
    <div class="toolbar__right">
        <a class="btn btn--primary" href="<?= APP_URL ?>/index.php?page=testimonials&action=form">
            <i class="bi bi-plus-lg"></i> Add testimonial
        </a>
    </div>
</div>

<div class="card">
    <div class="card__header">
        <h3 class="card__title">Customer reviews</h3>
        <p class="card__subtitle">
            Only approved reviews appear on the public site, and the star rating in the
            site's structured data is calculated from them.
        </p>
    </div>
    <div class="card__body" style="padding:0">
        <?php if (empty($testimonials)): ?>
            <div class="empty-state">
                <div class="empty-state__icon"><i class="bi bi-chat-quote"></i></div>
                <div class="empty-state__title">No reviews yet</div>
                <div class="empty-state__desc">
                    Add a real review from a customer. Nothing is published until you approve it,
                    and the testimonials section stays hidden on the home page while this is empty.
                </div>
                <a class="btn btn--primary mt-1" href="<?= APP_URL ?>/index.php?page=testimonials&action=form">
                    <i class="bi bi-plus-lg"></i> Add the first review
                </a>
            </div>
        <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th>Author</th><th>Rating</th><th>Review</th>
                        <th>Related to</th><th>Added</th><th>Status</th><th></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($testimonials as $t): ?>
                    <tr>
                        <td>
                            <strong><?= sanitize($t['author_name']) ?></strong>
                            <?php if ($t['author_role']): ?>
                                <div class="text-muted" style="font-size:.75rem"><?= sanitize($t['author_role']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td style="white-space:nowrap;color:var(--gold)">
                            <?php for ($i = 1; $i <= 5; $i++): ?>
                                <i class="bi bi-star<?= $i <= (int) $t['rating'] ? '-fill' : '' ?>"></i>
                            <?php endfor; ?>
                        </td>
                        <td><?= sanitize(truncate($t['body'], 70)) ?></td>
                        <td>
                            <?= sanitize($t['property_title'] ?? '') ?>
                            <?php if (!empty($t['agent_name'])): ?>
                                <div class="text-muted" style="font-size:.75rem"><?= sanitize($t['agent_name']) ?></div>
                            <?php endif; ?>
                            <?= empty($t['property_title']) && empty($t['agent_name']) ? '—' : '' ?>
                        </td>
                        <td><?= formatDate($t['created_at']) ?></td>
                        <td>
                            <span class="badge <?= $t['is_approved'] ? 'badge--success' : 'badge--warning' ?>">
                                <?= $t['is_approved'] ? 'Published' : 'Pending' ?>
                            </span>
                        </td>
                        <td>
                            <div class="btn-group">
                                <form method="POST" style="display:inline"
                                      action="<?= APP_URL ?>/index.php?page=testimonials&action=approve">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
                                    <input type="hidden" name="approve" value="<?= $t['is_approved'] ? '' : '1' ?>">
                                    <button class="btn btn--sm <?= $t['is_approved'] ? 'btn--outline' : 'btn--success' ?>"
                                            title="<?= $t['is_approved'] ? 'Hide from the public site' : 'Publish to the home page' ?>">
                                        <i class="bi bi-<?= $t['is_approved'] ? 'eye-slash' : 'check-lg' ?>"></i>
                                    </button>
                                </form>

                                <a class="btn btn--outline btn--sm" title="Edit"
                                   href="<?= APP_URL ?>/index.php?page=testimonials&action=form&id=<?= (int) $t['id'] ?>">
                                    <i class="bi bi-pencil"></i>
                                </a>

                                <form method="POST" style="display:inline"
                                      action="<?= APP_URL ?>/index.php?page=testimonials&action=delete"
                                      onsubmit="return confirm('Delete this review permanently?')">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
                                    <button class="btn btn--danger btn--sm" title="Delete">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>
