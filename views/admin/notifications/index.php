<?php
/**
 * Notifications — Index
 */
$unread = array_filter($notifications, fn($n) => !$n['is_read']);
?>
<?php if (!empty($unread)): ?>
<div style="display:flex;justify-content:flex-end;margin-bottom:12px">
    <a class="btn btn--outline btn--sm" href="<?= APP_URL ?>/index.php?page=notifications&action=read-all"><i class="bi bi-check2-all"></i> Mark all as read</a>
</div>
<?php endif ?>

<div class="card">
    <div class="card__body" style="padding:0">
        <?php if (empty($notifications)): ?>
            <div class="empty-state">
                <div class="empty-state__icon"><i class="bi bi-bell-slash"></i></div>
                <div class="empty-state__title">No notifications yet</div>
            </div>
        <?php else: ?>
        <ul style="list-style:none">
            <?php foreach ($notifications as $n): ?>
            <li style="display:flex;gap:14px;padding:16px 24px;border-bottom:1px solid var(--border);background:<?= $n['is_read'] ? 'transparent' : 'var(--primary-light)' ?>">
                <div style="width:36px;height:36px;border-radius:8px;display:flex;align-items:center;justify-content:center;flex-shrink:0;background:var(--card-bg);border:1px solid var(--border)">
                    <i class="bi bi-<?= $n['type'] === 'warning' ? 'exclamation-triangle' : ($n['type'] === 'success' ? 'check-circle' : 'info-circle') ?>"></i>
                </div>
                <div style="flex:1">
                    <div style="font-weight:600;font-size:.9rem"><?= sanitize($n['title']) ?></div>
                    <?php if ($n['message']): ?>
                    <div style="font-size:.85rem;color:var(--text-muted);margin-top:2px"><?= sanitize($n['message']) ?></div>
                    <?php endif ?>
                    <div style="font-size:.72rem;color:var(--text-muted);margin-top:6px"><?= formatDateTime($n['created_at']) ?></div>
                </div>
                <?php if (!$n['is_read']): ?>
                <a class="btn btn--outline btn--sm" href="<?= APP_URL ?>/index.php?page=notifications&action=read&id=<?= $n['id'] ?>"><i class="bi bi-check"></i></a>
                <?php endif ?>
            </li>
            <?php endforeach ?>
        </ul>
        <?php endif ?>
    </div>
</div>
