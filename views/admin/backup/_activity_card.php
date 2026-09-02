<?php
/**
 * Recent Activities.
 *
 * A filtered view of audit_logs, not a second activity log. The module writes
 * one trail through backupAudit(); this reads it back. That is what keeps the
 * feed and the audit screen from ever telling different stories about the same
 * event.
 *
 * A run started by the scheduler has no user, and the feed says "Scheduler"
 * rather than leaving the line blank — an unattended run and an unattributed
 * one look identical otherwise, and only one of them is a problem.
 *
 * Expects: $activity, $lastRestore
 */
?>
<div class="card">
    <div class="card__header">
        <div>
            <div class="card__title">Recent Activities</div>
            <div class="card__subtitle">From the system audit trail</div>
        </div>
        <?php if (can('audit-logs.view')): ?>
            <a class="btn btn--ghost btn--sm"
               href="<?= APP_URL ?>/index.php?page=audit-logs&amp;search=backup">
                View all
            </a>
        <?php endif ?>
    </div>

    <div class="card__body card__body--flush">
        <?php if (empty($activity)): ?>
            <div class="card__body">
                <p class="form-hint">Nothing has happened yet. Backup and restore actions appear here as they are recorded.</p>
            </div>
        <?php else: ?>
            <ul class="feed">
                <?php foreach ($activity as $a): ?>
                    <?php
                    [$icon, $tone, $label] = backupActivityMeta((string) $a['action']);

                    // backupAudit() prefixes unattended runs; the marker is
                    // stripped for display and shown as an actor instead.
                    $detail = (string) ($a['new_value'] ?? '');
                    $isCli  = str_starts_with($detail, 'Scheduler (CLI)');
                    if ($isCli) {
                        $detail = trim(substr($detail, strlen('Scheduler (CLI)')), " ·");
                    }
                    $actor = $a['full_name'] ?: ($isCli ? 'Scheduler' : 'System');
                    ?>
                    <li class="feed__item">
                        <span class="feed__icon feed__icon--<?= $tone ?>">
                            <i class="bi <?= $icon ?>" aria-hidden="true"></i>
                        </span>
                        <div class="feed__body">
                            <div class="feed__title"><?= sanitize($label) ?></div>
                            <?php if ($detail !== ''): ?>
                                <p class="feed__text"><?= sanitize(truncate($detail, 90)) ?></p>
                            <?php endif ?>
                            <div class="feed__meta">
                                <?= sanitize($actor) ?> · <?= backupAgo($a['created_at']) ?>
                            </div>
                        </div>
                    </li>
                <?php endforeach ?>
            </ul>
        <?php endif ?>
    </div>

    <?php if ($lastRestore): ?>
        <div class="card__footer">
            <span class="card__footer-note">
                <i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i>
                Last restore: <?= sanitize($lastRestore['restore_type']) ?>,
                <?= sanitize($lastRestore['status']) ?>, <?= backupAgo($lastRestore['created_at']) ?>
            </span>
        </div>
    <?php endif ?>
</div>
