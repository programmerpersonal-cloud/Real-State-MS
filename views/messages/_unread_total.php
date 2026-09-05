<?php
/**
 * The one-line count under the inbox heading.
 *
 * Its own file only because the live updater refreshes it: the span itself is
 * a role=status live region and must survive the update — replacing the
 * element would stop it announcing — so what gets swapped is what is inside
 * it, and that has to be renderable on its own.
 *
 * Expects: $unreadTotal $totalCount
 */
?>
<?php if (($unreadTotal ?? 0) > 0): ?>
    <?= number_format($unreadTotal) ?> unread message<?= (int) $unreadTotal === 1 ? '' : 's' ?>
<?php else: ?>
    <span class="sr-only">No unread messages</span>
    <span aria-hidden="true"><?= number_format($totalCount ?? 0) ?> conversation<?= (int) ($totalCount ?? 0) === 1 ? '' : 's' ?></span>
<?php endif; ?>
