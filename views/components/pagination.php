<?php
/**
 * Pagination Component
 * Expects: $page (current page), $totalPages
 */
if (empty($totalPages) || $totalPages < 2) return;
$current = (int)($page ?? 1);
$range = 2;
$start = max(1, $current - $range);
$end   = min($totalPages, $current + $range);
?>
<nav class="pagination" aria-label="Pagination">
    <a class="pagination__link <?= $current <= 1 ? 'is-disabled' : '' ?>"
       href="<?= $current > 1 ? paginateUrl($current - 1) : '#' ?>">
        <i class="bi bi-chevron-left"></i>
    </a>
    <?php if ($start > 1): ?>
        <a class="pagination__link" href="<?= paginateUrl(1) ?>">1</a>
        <?php if ($start > 2): ?><span class="pagination__ellipsis">…</span><?php endif; ?>
    <?php endif; ?>

    <?php for ($i = $start; $i <= $end; $i++): ?>
        <a class="pagination__link <?= $i === $current ? 'is-active' : '' ?>"
           href="<?= paginateUrl($i) ?>"><?= $i ?></a>
    <?php endfor; ?>

    <?php if ($end < $totalPages): ?>
        <?php if ($end < $totalPages - 1): ?><span class="pagination__ellipsis">…</span><?php endif; ?>
        <a class="pagination__link" href="<?= paginateUrl($totalPages) ?>"><?= $totalPages ?></a>
    <?php endif; ?>

    <a class="pagination__link <?= $current >= $totalPages ? 'is-disabled' : '' ?>"
       href="<?= $current < $totalPages ? paginateUrl($current + 1) : '#' ?>">
        <i class="bi bi-chevron-right"></i>
    </a>
</nav>
