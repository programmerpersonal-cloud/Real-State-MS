<?php
/**
 * The drill-down record table — one column set per record family.
 *
 * Columns are chosen to answer "is this the right row?" and nothing more.
 * A payment shows its reference, its date, what it was for and how much; it
 * does not show the customer's contact details, because identifying a
 * transaction does not require them and a panel that reaches records is the
 * wrong place to widen what a reader can see. §11.
 *
 * Where a row is a record the reader may open, its code links to it — but
 * only where they hold the permission for that module. Reports grants sight
 * of a figure; it does not grant the record page behind it, and the link is
 * drawn or not drawn accordingly.
 *
 * Expects: $ddRows, $ddResult (from _drilldown.php), $window, $filters, $ddTab
 */
$dtRows = $ddRows ?? [];
$dtCarry = !empty($compare) ? ['compare' => '1'] : [];

/* A record link, where the reader may follow it. can() is advisory here and
   that is the correct use of it: the destination enforces its own access, and
   this only decides whether to offer a door somebody cannot open. */
$dtLink = static function (string $page, string $permission, $id, string $text): string {
    $dtText = sanitize($text !== '' ? $text : '—');

    return can($permission)
        ? '<a class="hash" href="' . sanitize(APP_URL . '/index.php?page=' . $page . '&action=show&id=' . (int) $id) . '">'
          . $dtText . '</a>'
        : '<span class="hash">' . $dtText . '</span>';
};

/* A property cell: the title, with its code underneath. The same treatment
   every other report table gives it. */
$dtProperty = static function (array $dtR) use ($dtLink): string {
    $dtTitle = (string) ($dtR['property_title'] ?? $dtR['title'] ?? '');
    if ($dtTitle === '') {
        return '<span class="text-subtle">Not linked to a property</span>';
    }

    $dtOut = can('properties.show')
        ? '<a class="tp-name" href="' . sanitize(APP_URL . '/index.php?page=properties&action=show&id=' . (int) ($dtR['property_id'] ?? 0)) . '">'
          . sanitize($dtTitle) . '</a>'
        : '<span class="tp-name">' . sanitize($dtTitle) . '</span>';

    if (($dtR['property_code'] ?? '') !== '') {
        $dtOut .= '<div class="tp-code">' . sanitize((string) $dtR['property_code']) . '</div>';
    }

    return $dtOut;
};

/* Money that is genuinely absent, kept apart from money that is nought. */
$dtMoney = static fn($dtV): string => $dtV === null
    ? '<span class="text-subtle" aria-label="not recorded">—</span>'
    : sanitize(formatCurrency((float) $dtV));

$dtText = static fn($dtV): string => ($dtV === null || $dtV === '')
    ? '<span class="text-subtle">—</span>'
    : sanitize((string) $dtV);
?>

<table class="table rdata__table">
    <caption class="sr-only">
        <?= sanitize($ddResult['label']) ?><?= $keyLabel !== '' ? ' — ' . sanitize($keyLabel) : '' ?>.
        <?= number_format((int) $ddResult['total']) ?> records in scope,
        page <?= (int) $ddResult['page'] ?> of <?= (int) $ddResult['pages'] ?>.
    </caption>

    <?php switch ($ddResult['source']):
        case 'payments':
        case 'agent_payments': ?>
        <thead>
            <tr>
                <th scope="col">Reference</th>
                <th scope="col" class="col-lo">Date</th>
                <th scope="col">Property</th>
                <th scope="col" class="col-mid">Payer</th>
                <th scope="col" class="col-mid">Type</th>
                <th scope="col" class="col-lo">Method</th>
                <th scope="col">Status</th>
                <th scope="col" class="col-lo">Received by</th>
                <th scope="col" class="cell-num">Amount</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($dtRows as $dtR): ?>
                <tr>
                    <th scope="row"><?= $dtLink('payments', 'payments.show', $dtR['id'], (string) $dtR['payment_code']) ?></th>
                    <td class="col-lo pr-date"><?= sanitize(formatDate((string) $dtR['payment_date'])) ?></td>
                    <td><?= $dtProperty($dtR) ?></td>
                    <td class="col-mid"><?= $dtText($dtR['customer_name'] ?? null) ?></td>
                    <td class="col-mid"><?= sanitize(uiLabel((string) $dtR['payment_type'])) ?></td>
                    <td class="col-lo"><?= ($dtR['payment_method'] ?? '') === ''
                        ? '<span class="text-subtle">Not recorded</span>'
                        : sanitize(uiLabel((string) $dtR['payment_method'])) ?></td>
                    <td><?= uiStatus((string) $dtR['status']) ?></td>
                    <td class="col-lo"><?= $dtText($dtR['received_by_name'] ?? null) ?></td>
                    <td class="cell-num tp-money"><?= $dtMoney($dtR['amount']) ?></td>
                </tr>
            <?php endforeach ?>
        </tbody>
        <?php break; ?>

    <?php case 'schedules': ?>
        <thead>
            <tr>
                <th scope="col">Lease</th>
                <th scope="col">Property</th>
                <th scope="col" class="col-mid">Tenant</th>
                <th scope="col">Due</th>
                <th scope="col" class="col-lo">Paid</th>
                <th scope="col">Status</th>
                <th scope="col" class="cell-num col-lo">Penalty</th>
                <th scope="col" class="cell-num">Amount due</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($dtRows as $dtR): ?>
                <?php $dtLate = (int) ($dtR['days_late'] ?? 0); ?>
                <tr>
                    <th scope="row"><?= $dtLink('leases', 'leases.show', $dtR['lease_id'], (string) $dtR['lease_code']) ?></th>
                    <td><?= $dtProperty($dtR) ?></td>
                    <td class="col-mid"><?= $dtText($dtR['tenant_name'] ?? null) ?></td>
                    <td class="pr-date">
                        <?= sanitize(formatDate((string) $dtR['due_date'])) ?>
                        <?php if ($dtR['status'] === 'overdue' && $dtLate > 0): ?>
                            <div class="tp-code"><?= (int) $dtLate ?> days late</div>
                        <?php endif ?>
                    </td>
                    <td class="col-lo pr-date"><?= $dtR['paid_date']
                        ? sanitize(formatDate((string) $dtR['paid_date']))
                        : '<span class="text-subtle">—</span>' ?></td>
                    <td><?= uiStatus((string) $dtR['status']) ?></td>
                    <td class="cell-num col-lo"><?= (float) $dtR['penalty'] > 0
                        ? $dtMoney($dtR['penalty'])
                        : '<span class="text-subtle">—</span>' ?></td>
                    <td class="cell-num tp-money"><?= $dtMoney($dtR['due_total']) ?></td>
                </tr>
            <?php endforeach ?>
        </tbody>
        <?php break; ?>

    <?php case 'properties':
        case 'agent_properties': ?>
        <thead>
            <tr>
                <th scope="col">Property</th>
                <th scope="col" class="col-mid">Category</th>
                <th scope="col" class="col-lo">Location</th>
                <th scope="col">State</th>
                <th scope="col" class="col-lo">Recorded</th>
                <th scope="col" class="col-mid">Agent</th>
                <th scope="col" class="cell-num">Revenue</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($dtRows as $dtR): ?>
                <?php
                /* Derived from records, exactly as the portfolio table
                   derives it. "Recorded" beside it is what the register
                   itself claims, and the two disagreeing is a finding the
                   data-quality panel already counts. */
                $dtState = match (true) {
                    !empty($dtR['is_sold'])     => 'Sold',
                    !empty($dtR['is_occupied']) => 'Occupied',
                    !empty($dtR['is_reserved']) => 'Reserved',
                    default                     => 'Available',
                };
                ?>
                <tr>
                    <th scope="row"><?= $dtProperty($dtR) ?></th>
                    <td class="col-mid"><?= sanitize(categoryLabel((string) $dtR['category'])) ?></td>
                    <td class="col-lo"><?= $dtText($dtR['location'] ?? null) ?></td>
                    <td><?= sanitize($dtState) ?></td>
                    <td class="col-lo"><?= sanitize(uiLabel((string) $dtR['recorded_status'])) ?></td>
                    <td class="col-mid"><?= $dtText($dtR['agent_name'] ?? null) ?></td>
                    <td class="cell-num tp-money"><?= $dtR['revenue'] === null
                        ? '<span class="text-subtle" aria-label="not applicable">—</span>'
                        : $dtMoney($dtR['revenue']) ?></td>
                </tr>
            <?php endforeach ?>
        </tbody>
        <?php break; ?>

    <?php case 'leases': ?>
        <thead>
            <tr>
                <th scope="col">Lease</th>
                <th scope="col">Property</th>
                <th scope="col" class="col-mid">Tenant</th>
                <th scope="col" class="col-lo">Started</th>
                <th scope="col">Ends</th>
                <th scope="col" class="cell-num col-lo">Rent</th>
                <th scope="col" class="cell-num">Outstanding</th>
                <th scope="col" class="cell-num col-mid">Arrears</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($dtRows as $dtR): ?>
                <?php $dtDays = (int) ($dtR['days_left'] ?? 0); ?>
                <tr>
                    <th scope="row"><?= $dtLink('leases', 'leases.show', $dtR['id'], (string) $dtR['lease_code']) ?></th>
                    <td><?= $dtProperty($dtR) ?></td>
                    <td class="col-mid"><?= $dtText($dtR['tenant_name'] ?? null) ?></td>
                    <td class="col-lo pr-date"><?= sanitize(formatDate((string) $dtR['start_date'])) ?></td>
                    <td class="pr-date">
                        <?= sanitize(formatDate((string) $dtR['end_date'])) ?>
                        <div class="tp-code">
                            <?= $dtDays < 0
                                ? 'expired ' . abs($dtDays) . ' days ago'
                                : $dtDays . ' days left' ?>
                        </div>
                    </td>
                    <td class="cell-num col-lo tp-money"><?= $dtMoney($dtR['rent_amount']) ?></td>
                    <td class="cell-num tp-money"><?= $dtMoney($dtR['outstanding']) ?></td>
                    <td class="cell-num col-mid tp-money"><?= $dtMoney($dtR['arrears']) ?></td>
                </tr>
            <?php endforeach ?>
        </tbody>
        <?php break; ?>

    <?php case 'sales': ?>
        <thead>
            <tr>
                <th scope="col">Sale</th>
                <th scope="col" class="col-lo">Sale date</th>
                <th scope="col">Property</th>
                <th scope="col" class="col-mid">Buyer</th>
                <th scope="col">Status</th>
                <th scope="col" class="col-lo">Agent</th>
                <th scope="col" class="cell-num col-lo">Commission</th>
                <th scope="col" class="cell-num">Value</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($dtRows as $dtR): ?>
                <tr>
                    <th scope="row"><?= $dtLink('sales', 'sales.show', $dtR['id'], (string) $dtR['sale_code']) ?></th>
                    <td class="col-lo pr-date"><?= sanitize(formatDate((string) $dtR['sale_date'])) ?></td>
                    <td><?= $dtProperty($dtR) ?></td>
                    <td class="col-mid"><?= $dtText($dtR['buyer_name'] ?? null) ?></td>
                    <td><?= uiStatus((string) $dtR['status']) ?></td>
                    <td class="col-lo"><?= $dtText($dtR['agent_name'] ?? null) ?></td>
                    <td class="cell-num col-lo tp-money"><?= $dtMoney($dtR['commission_amount']) ?></td>
                    <td class="cell-num tp-money"><?= $dtMoney($dtR['sale_amount']) ?></td>
                </tr>
            <?php endforeach ?>
        </tbody>
        <?php break; ?>

    <?php case 'reservations': ?>
        <thead>
            <tr>
                <th scope="col">Reference</th>
                <th scope="col">Property</th>
                <th scope="col" class="col-mid">Customer</th>
                <th scope="col" class="col-lo">Reserved</th>
                <th scope="col">Expires</th>
                <th scope="col">Status</th>
                <th scope="col" class="cell-num">Deposit</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($dtRows as $dtR): ?>
                <?php $dtDays = (int) ($dtR['days_left'] ?? 0); ?>
                <tr>
                    <th scope="row"><?= $dtLink('reservations', 'reservations.view', $dtR['id'], (string) $dtR['reservation_code']) ?></th>
                    <td><?= $dtProperty($dtR) ?></td>
                    <td class="col-mid"><?= $dtText($dtR['customer_name'] ?? null) ?></td>
                    <td class="col-lo pr-date"><?= sanitize(formatDate((string) $dtR['reservation_date'])) ?></td>
                    <td class="pr-date">
                        <?= sanitize(formatDate((string) $dtR['expiry_date'])) ?>
                        <?php if ($dtDays < 0): ?>
                            <div class="tp-code">lapsed <?= abs($dtDays) ?> days ago</div>
                        <?php endif ?>
                    </td>
                    <td><?= uiStatus((string) $dtR['status']) ?></td>
                    <td class="cell-num tp-money"><?= $dtMoney($dtR['deposit_amount']) ?></td>
                </tr>
            <?php endforeach ?>
        </tbody>
        <?php break; ?>

    <?php case 'maintenance': ?>
        <thead>
            <tr>
                <th scope="col">Request</th>
                <th scope="col">Property</th>
                <th scope="col" class="col-mid">Type</th>
                <th scope="col">Priority</th>
                <th scope="col">Status</th>
                <th scope="col" class="col-lo">Raised</th>
                <th scope="col" class="col-mid">Completed</th>
                <th scope="col" class="cell-num col-lo">Cost</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($dtRows as $dtR): ?>
                <tr>
                    <th scope="row"><?= $dtLink('maintenance', 'maintenance.show', $dtR['id'], (string) $dtR['request_code']) ?></th>
                    <td><?= $dtProperty($dtR) ?></td>
                    <td class="col-mid"><?= $dtText($dtR['issue_type'] ?? null) ?></td>
                    <td><?= uiPriority((string) $dtR['priority']) ?></td>
                    <td><?= uiStatus((string) $dtR['status']) ?></td>
                    <td class="col-lo pr-date">
                        <?= sanitize(formatDate((string) $dtR['raised_on'])) ?>
                        <div class="tp-code"><?= (int) $dtR['age_days'] ?> days old</div>
                    </td>
                    <td class="col-mid pr-date">
                        <?php if ($dtR['completion_date']): ?>
                            <?= sanitize(formatDate((string) $dtR['completion_date'])) ?>
                            <?php if ($dtR['resolution_days'] !== null): ?>
                                <div class="tp-code">in <?= (int) $dtR['resolution_days'] ?> days</div>
                            <?php endif ?>
                        <?php else: ?>
                            <?php /* Not "0 days". A request with no completion
                                     date has no resolution time, and inventing
                                     one is the thing this report refuses to
                                     do anywhere else. */ ?>
                            <span class="text-subtle">Not completed</span>
                        <?php endif ?>
                    </td>
                    <td class="cell-num col-lo tp-money"><?= $dtMoney($dtR['actual_cost']) ?></td>
                </tr>
            <?php endforeach ?>
        </tbody>
        <?php break; ?>

    <?php default: ?>
        <tbody>
            <tr><td>No table is defined for this record type.</td></tr>
        </tbody>
    <?php endswitch ?>
</table>
