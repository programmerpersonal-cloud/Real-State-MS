<?php
/**
 * Inquiry — Show / Thread
 *
 * Reached by staff working the enquiry, by the owner of the property it
 * concerns, and by the person who sent it. The thread reads the same for all
 * three; only the reply box is conditional, because answering on the agency's
 * behalf is the agency's job.
 */
$i = $inquiry;
$canReply = can('inquiries.reply');
?>
<div class="<?= $canReply ? 'grid-2' : '' ?>">
    <div>
        <div class="card mb-3">
            <div class="card__header">
                <h3 class="card__title">Inquiry from <?= sanitize($i['name'] ?? $i['customer_name'] ?? '—') ?></h3>
                <span class="badge <?= getStatusBadgeClass($i['status']) ?>"><?= ucfirst($i['status']) ?></span>
            </div>
            <div class="card__body">
                <div class="profile-meta">
                    <?php if ($i['email']): ?>
                    <div class="profile-meta__row"><span class="profile-meta__label">Email</span><span class="profile-meta__value"><?= sanitize($i['email']) ?></span></div>
                    <?php endif ?>
                    <?php if ($i['phone']): ?>
                    <div class="profile-meta__row"><span class="profile-meta__label">Phone</span><span class="profile-meta__value"><?= sanitize($i['phone']) ?></span></div>
                    <?php endif ?>
                    <?php if ($i['property_title']): ?>
                    <div class="profile-meta__row"><span class="profile-meta__label">Property</span><span class="profile-meta__value"><a href="<?= APP_URL ?>/index.php?page=properties&action=show&id=<?= $i['property_id'] ?>"><?= sanitize($i['property_title']) ?></a></span></div>
                    <?php endif ?>
                    <div class="profile-meta__row"><span class="profile-meta__label">Received</span><span class="profile-meta__value"><?= formatDateTime($i['created_at']) ?></span></div>
                </div>
                <div class="section-title"><?= sanitize($i['subject'] ?: 'Message') ?></div>
                <div class="prose"><?= nl2br(sanitize($i['message'])) ?></div>
            </div>
        </div>

        <!-- Conversation thread -->
        <?php if (!empty($messages)): ?>
        <div class="card">
            <div class="card__header"><h3 class="card__title">Conversation</h3></div>
            <div class="card__body">
                <?php foreach ($messages as $m): ?>
                    <div style="padding:10px 14px;background:var(--body-bg);border-radius:var(--radius-sm);margin-bottom:10px">
                        <div style="display:flex;justify-content:space-between;font-size:.78rem;color:var(--text-muted)">
                            <strong style="color:var(--text)"><?= sanitize($m['sender_name']) ?></strong>
                            <span><?= formatDateTime($m['created_at']) ?></span>
                        </div>
                        <div style="margin-top:6px;font-size:.875rem"><?= nl2br(sanitize($m['body'])) ?></div>
                    </div>
                <?php endforeach ?>
            </div>
        </div>
        <?php endif ?>
    </div>

    <?php if ($canReply): ?>
    <div>
        <div class="card">
            <div class="card__header"><h3 class="card__title">Reply</h3></div>
            <div class="card__body">
                <form method="post" action="<?= APP_URL ?>/index.php?page=inquiries&action=reply&id=<?= $i['id'] ?>">
                    <?= csrfField() ?>
                    <div class="form-group">
                        <textarea name="body" class="form-control" rows="6" placeholder="Write a reply…" required></textarea>
                    </div>
                    <button type="submit" class="btn btn--primary btn--block"><i class="bi bi-send"></i> Send Reply</button>
                </form>
            </div>
        </div>
    </div>
    <?php endif ?>
</div>
