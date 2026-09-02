<?php
/**
 * Backup Schedule — the three cadences and when each next fires.
 *
 * "Next run" is the stored next_run_at, which is null for an inactive
 * schedule. That is shown as "Not scheduled" rather than a computed date,
 * because a date next to a switch that is off is a promise the system has not
 * made.
 *
 * Expects: $schedules, $canManage
 */
$dayNames = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday',
             5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'];

$ordinal = static function (int $n): string {
    $suffix = ($n % 100 >= 11 && $n % 100 <= 13) ? 'th' : (['th', 'st', 'nd', 'rd'][$n % 10] ?? 'th');
    return $n . $suffix;
};
?>
<div class="card">
    <div class="card__header">
        <div>
            <div class="card__title">Backup Schedule</div>
            <div class="card__subtitle">Times shown in <?= sanitize(backupTimezone()->getName()) ?></div>
        </div>
        <?php if ($canManage): ?>
            <a class="btn btn--ghost btn--sm" href="<?= APP_URL ?>/index.php?page=backup&amp;action=settings">
                <i class="bi bi-pencil" aria-hidden="true"></i> Edit
            </a>
        <?php endif ?>
    </div>

    <div class="card__body card__body--flush">
        <ul class="sched">
            <?php foreach ($schedules as $s):
                $active = !empty($s['is_active']);
                $when   = match ($s['frequency']) {
                    'daily'   => 'Every day',
                    'weekly'  => 'Every ' . ($dayNames[(int) $s['day_of_week']] ?? 'week'),
                    'monthly' => 'The ' . $ordinal((int) $s['day_of_month']) . ' of each month',
                    default   => ucfirst((string) $s['frequency']),
                };
            ?>
                <li class="sched__row<?= $active ? '' : ' sched__row--off' ?>">
                    <span class="sched__dot" aria-hidden="true"></span>
                    <div class="sched__body">
                        <div class="sched__name">
                            <?= ucfirst(sanitize($s['frequency'])) ?>
                            <span class="status status--<?= $active ? 'success' : 'muted' ?>">
                                <span class="status__dot"></span><?= $active ? 'Active' : 'Off' ?>
                            </span>
                        </div>
                        <div class="sched__meta">
                            <?= sanitize($when) ?> at <?= substr((string) $s['run_at'], 0, 5) ?>
                            · <?= sanitize(backupTypes()[$s['backup_type']] ?? '') ?>
                        </div>
                        <div class="sched__meta">
                            <?php if ($active && !empty($s['next_run_at'])): ?>
                                <i class="bi bi-arrow-right-short" aria-hidden="true"></i>
                                Next <?= backupScheduleWhen($s['next_run_at'], 'M d, H:i') ?>
                            <?php else: ?>
                                <span class="text-subtle">Not scheduled</span>
                            <?php endif ?>
                            · keeps <?= (int) $s['retention_days'] ?> days
                        </div>
                        <?php if (!empty($s['last_run_at'])): ?>
                            <div class="sched__meta">
                                Last ran <?= backupAgo($s['last_run_at']) ?>
                                — <?= sanitize($s['last_status']) ?>
                            </div>
                        <?php endif ?>
                    </div>
                </li>
            <?php endforeach ?>
        </ul>
    </div>

    <?php /* Said plainly because it is the commonest way a backup schedule
             silently does nothing: the rows are configured, the switch is on,
             and no scheduler is installed to act on them. */ ?>
    <div class="card__footer">
        <span class="card__footer-note">
            <i class="bi bi-terminal" aria-hidden="true"></i>
            Schedules fire from the command-line runner, never from a browser.
        </span>
    </div>
</div>
