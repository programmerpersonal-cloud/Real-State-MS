<?php
/**
 * The property registers, as one strip of pills.
 *
 * Three lists answer three different questions about the same table —
 * what is on the books, what is waiting on a decision, what has been filed
 * away — and before this the second and third had no entry point at all: a
 * property could be archived from a row menu and then existed nowhere any
 * screen could reach it.
 *
 * Written as navigation rather than a filter, and it matters which:
 *   · each pill is a real URL, so the three lists are bookmarkable, shareable
 *     and reachable with the back button;
 *   · `aria-current="page"` states which register is open, because the tint
 *     alone is not an answer for anyone who cannot see it;
 *   · a pill the viewer's role cannot open is absent, not disabled — an agent
 *     holds neither properties.approve nor properties.archive, so they simply
 *     see the register and nothing that would refuse them.
 *
 * The counts come from one query (Property::stateCounts()) under the same
 * access scope as the lists themselves, so an agent's numbers describe their
 * own listings and never the whole office's.
 *
 * Expects: $activeRegister  'active' | 'approvals' | 'archived'
 *          $stateCounts     from Property::stateCounts()
 */
$counts   = $stateCounts ?? [];
$base     = APP_URL . '/index.php?page=properties';
$current  = $activeRegister ?? 'active';

$registers = array_values(array_filter([
    [
        'key'   => 'active',
        'label' => 'Active register',
        'url'   => $base,
        'count' => $counts['active'] ?? null,
        'tone'  => 'success',
        'when'  => can('properties.view'),
    ],
    [
        'key'   => 'approvals',
        'label' => 'Pending approval',
        'url'   => $base . '&action=approvals',
        'count' => $counts['pending'] ?? null,
        'tone'  => 'warning',
        'when'  => can('properties.approve'),
    ],
    [
        'key'   => 'archived',
        'label' => 'Archived',
        'url'   => $base . '&action=archived',
        'count' => $counts['archived'] ?? null,
        'tone'  => 'muted',
        'when'  => can('properties.archive'),
    ],
], static fn(array $r): bool => $r['when']));

// One reachable register is not a choice, and a strip of one pill is a label
// pretending to be navigation.
if (count($registers) < 2) {
    return;
}
?>
<nav class="status-tabs" aria-label="Property registers">
    <?php foreach ($registers as $r): ?>
        <?php $isActive = $r['key'] === $current; ?>
        <a class="status-tabs__item status-tabs__item--<?= $r['tone'] ?><?= $isActive ? ' is-active' : '' ?>"
           href="<?= sanitize($r['url']) ?>"
           <?= $isActive ? 'aria-current="page"' : '' ?>>
            <span class="status-tabs__dot" aria-hidden="true"></span>
            <?= sanitize($r['label']) ?>
            <?php if ($r['count'] !== null): ?>
                <span class="status-tabs__count"><?= number_format((int) $r['count']) ?></span>
            <?php endif ?>
        </a>
    <?php endforeach ?>
</nav>
