<?php
/**
 * Ledger summary — the money in each status, and the filter for it.
 *
 * The payments page carried two separate controls that answered overlapping
 * questions: a strip of read-only totals at the top, and a status <select>
 * in the filter bar below it. Someone who saw "Overdue 4,800" then had to
 * scroll to a dropdown, pick "overdue" and submit to see which four payments
 * that was.
 *
 * So the totals *are* the filter. Each card states a status, what it is worth
 * and how many records make it up, and clicking it narrows the table beneath.
 * They are links, so this works with scripting off and produces a URL that can
 * be shared with whoever needs to chase the total.
 *
 * Expects $ledger:
 *   param    string  query parameter name          (default 'status')
 *   value    string  the value currently applied
 *   options  array   [value => label]              required, sets the order
 *   totals   array   [value => ['cnt'=>int,'total'=>float]]
 *   all      array   ['label'=>…, 'cnt'=>int, 'total'=>float]  optional
 */
$lg     = $ledger ?? [];
$param  = $lg['param'] ?? 'status';
$value  = (string) ($lg['value'] ?? '');
$totals = $lg['totals'] ?? [];

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

$cards = [];
if (!empty($lg['all'])) {
    $cards[''] = ['label' => $lg['all']['label'] ?? 'All', 'cnt' => $lg['all']['cnt'] ?? 0, 'total' => $lg['all']['total'] ?? 0];
}
foreach (($lg['options'] ?? []) as $key => $label) {
    $row = $totals[$key] ?? [];
    $cards[$key] = ['label' => $label, 'cnt' => (int) ($row['cnt'] ?? 0), 'total' => (float) ($row['total'] ?? 0)];
}
?>
<div class="ledger" role="group" aria-label="Filter by payment status">
    <?php foreach ($cards as $key => $card): ?>
        <?php
        $isActive = (string) $key === $value;
        // Tone from the same map the row badges read, so a status is one
        // colour across the whole page.
        $tone = $key === '' ? 'muted'
            : substr(getStatusBadgeClass((string) $key), strlen('badge--'));
        ?>
        <a class="ledger__card ledger__card--<?= sanitize($tone) ?><?= $isActive ? ' is-active' : '' ?>"
           href="<?= sanitize($urlFor((string) $key)) ?>"
           <?= $isActive ? 'aria-current="true"' : '' ?>>
            <span class="ledger__label">
                <span class="ledger__dot" aria-hidden="true"></span>
                <?= sanitize((string) $card['label']) ?>
            </span>
            <span class="ledger__value"><?= formatCurrency((float) $card['total']) ?></span>
            <span class="ledger__meta">
                <?= number_format((int) $card['cnt']) ?>
                <?= (int) $card['cnt'] === 1 ? 'payment' : 'payments' ?>
            </span>
        </a>
    <?php endforeach ?>
</div>
