<?php
/**
 * Status filter bar — one row of pills across the top of an operations list.
 *
 * Every queue in this system is read the same way: what is still open, what
 * needs chasing, what is finished. That question was previously answered by a
 * <select> inside the filter bar, which took three actions to use and showed
 * no quantities — you could not tell there were nine overdue payments without
 * filtering to them first.
 *
 * These are plain links carrying the rest of the query string, so filtering
 * works with scripting off, the result is a shareable URL, and the back button
 * behaves. Page number is dropped: narrowing the list always starts at page one.
 *
 * Expects $statusFilter:
 *   param    string  query parameter name            (default 'status')
 *   value    string  the value currently applied
 *   options  array   [value => label]                required
 *   counts   array   [value => int]                  optional
 *   total    int     count for the "All" pill        optional
 *   all      string  label for the "All" pill        (default 'All')
 *                    pass false when there is no such thing — the property
 *                    approval queue is one of pending/approved/rejected and a
 *                    mixed list is not a queue anyone works from
 *   tones    bool    colour the dots from the status map (default true)
 *
 * The options are the same array the controller validates the request against,
 * so a pill can never offer a value the query would refuse.
 */
$sf     = $statusFilter ?? [];
$param  = $sf['param']  ?? 'status';
$value  = (string) ($sf['value'] ?? '');
$counts = $sf['counts'] ?? [];
$tones  = $sf['tones']  ?? true;

/* The current URL minus the status and the page number — everything else
   (search, sort, secondary filters) rides along. */
$urlFor = static function (string $status) use ($param): string {
    $params = $_GET;
    unset($params['p']);
    if ($status === '') {
        unset($params[$param]);
    } else {
        $params[$param] = $status;
    }
    return APP_URL . '/index.php?' . http_build_query($params);
};

$allLabel = $sf['all'] ?? 'All';
$items    = ($sf['options'] ?? []);
if ($allLabel !== false && $allLabel !== null) {
    $items = ['' => $allLabel] + $items;
}
?>
<nav class="status-tabs" aria-label="Filter by status">
    <?php foreach ($items as $key => $label): ?>
        <?php
        $isActive = (string) $key === $value;
        $count    = $key === '' ? ($sf['total'] ?? null) : ($counts[$key] ?? null);
        // Same tone map as the pills in the rows below, so the filter and the
        // thing it filters to are never two different colours for one status.
        $tone     = ($tones && $key !== '')
            ? ' status-tabs__item--' . substr(getStatusBadgeClass((string) $key), strlen('badge--'))
            : '';
        ?>
        <a class="status-tabs__item<?= $tone ?><?= $isActive ? ' is-active' : '' ?>"
           href="<?= sanitize($urlFor((string) $key)) ?>"
           <?= $isActive ? 'aria-current="true"' : '' ?>>
            <?php if ($tone !== ''): ?>
                <span class="status-tabs__dot" aria-hidden="true"></span>
            <?php endif ?>
            <?= sanitize((string) $label) ?>
            <?php if ($count !== null): ?>
                <span class="status-tabs__count"><?= number_format((int) $count) ?></span>
            <?php endif ?>
        </a>
    <?php endforeach ?>
</nav>
