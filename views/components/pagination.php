<?php
/**
 * Pagination Component
 *
 * Expects: $page (current page), $totalPages
 *
 * Every link says where it goes. The arrows carried an icon and nothing else,
 * so a screen reader read two unnamed links at each end of the row; the
 * numbered links read as bare digits with no indication of which one is the
 * page you are on. aria-current is what carries that, not the class.
 *
 * A disabled arrow is rendered as a <span>, not a dead <a href="#">: a link
 * that goes nowhere is still a tab stop and still announces itself as a link,
 * which is worse than not being there.
 */
if (empty($totalPages) || $totalPages < 2) return;

$current = (int) ($page ?? 1);
$range   = 2;
$start   = max(1, $current - $range);
$end     = min($totalPages, $current + $range);

/** One page link, or the current page marked as such. */
$link = static function (int $n) use ($current): string {
    $isCurrent = $n === $current;
    return '<a class="pagination__link' . ($isCurrent ? ' is-active' : '') . '"'
         . ' href="' . paginateUrl($n) . '"'
         . ' aria-label="Page ' . $n . '"'
         . ($isCurrent ? ' aria-current="page"' : '')
         . '>' . $n . '</a>';
};
?>
<nav class="pagination" aria-label="Pagination">
    <?php if ($current > 1): ?>
        <a class="pagination__link" href="<?= paginateUrl($current - 1) ?>" rel="prev"
           aria-label="Previous page">
            <i class="bi bi-chevron-left" aria-hidden="true"></i>
        </a>
    <?php else: ?>
        <span class="pagination__link is-disabled" aria-hidden="true">
            <i class="bi bi-chevron-left"></i>
        </span>
    <?php endif ?>

    <?php if ($start > 1): ?>
        <?= $link(1) ?>
        <?php if ($start > 2): ?><span class="pagination__ellipsis" aria-hidden="true">…</span><?php endif ?>
    <?php endif ?>

    <?php for ($i = $start; $i <= $end; $i++): ?>
        <?= $link($i) ?>
    <?php endfor ?>

    <?php if ($end < $totalPages): ?>
        <?php if ($end < $totalPages - 1): ?><span class="pagination__ellipsis" aria-hidden="true">…</span><?php endif ?>
        <?= $link($totalPages) ?>
    <?php endif ?>

    <?php if ($current < $totalPages): ?>
        <a class="pagination__link" href="<?= paginateUrl($current + 1) ?>" rel="next"
           aria-label="Next page">
            <i class="bi bi-chevron-right" aria-hidden="true"></i>
        </a>
    <?php else: ?>
        <span class="pagination__link is-disabled" aria-hidden="true">
            <i class="bi bi-chevron-right"></i>
        </span>
    <?php endif ?>
</nav>
