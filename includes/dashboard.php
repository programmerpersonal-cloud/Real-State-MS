<?php
/**
 * The dashboard's figures, as data.
 *
 * Lifted out of views/admin/dashboard.php when the Export button in that
 * page's header stopped being an ornament. The export writes the same six
 * figures, the same six months of revenue and the same activity feed the page
 * draws, and the only way that stays true through the next change is for both
 * to read one function rather than two copies of the same SQL.
 *
 * Nothing here decides who may see a figure. The page gates the revenue chart
 * on reports.view and the status ring on properties.view; DashboardController
 * gates the matching sections of the file the same way. These functions answer
 * "what is the number" — the caller answers "may this reader have it".
 *
 * propertyStatusBreakdown() is deliberately not repeated here: it already
 * lives in functions.php, already carries the reader's record scope, and the
 * ring and the exported section both call it.
 */

/**
 * The six headline figures, each with the line of context printed under it.
 *
 * A bare count answers "how many" and leaves "so what" on the table: three
 * active rentals is a different morning depending on whether two of them end
 * this quarter, and an arrears count of nine means nothing until it is read as
 * money. The note is part of the figure, so it travels with it — into the card
 * on screen and into the Note column of the export.
 *
 * `icon`, `tone` and `noteTone` dress the card and are ignored by the export;
 * `label`, `value` and `note` are the figure itself.
 *
 * The context line comes from a single statement of scalar sub-selects rather
 * than six more COUNTs. This is the page every role loads first, and each of
 * these is an indexed aggregate over one table with no dependence on a row
 * above it — so the whole context row costs one round trip and stays O(1) in
 * the size of the portfolio.
 *
 * @return array<int, array<string, mixed>>
 */
function dashboardStatCards(): array
{
    $db = getDBConnection();

    $totalProperties = (int) $db->query("SELECT COUNT(*) FROM properties")->fetchColumn();
    $activeRentals   = (int) $db->query("SELECT COUNT(*) FROM leases WHERE status = 'active'")->fetchColumn();
    $totalCustomers  = (int) $db->query("SELECT COUNT(*) FROM customers")->fetchColumn();
    $pendingMaint    = (int) $db->query("SELECT COUNT(*) FROM maintenance_requests WHERE status IN ('new','under_review','assigned')")->fetchColumn();
    $overduePayments = (int) $db->query("SELECT COUNT(*) FROM payments WHERE status = 'overdue'")->fetchColumn();
    $totalUsers      = (int) $db->query("SELECT COUNT(*) FROM users WHERE is_active = 1")->fetchColumn();

    $ctx = $db->query("
        SELECT
          (SELECT COUNT(*) FROM properties
            WHERE status = 'available' AND is_archived = 0 AND approval_status = 'approved') AS available,
          (SELECT COUNT(*) FROM leases
            WHERE status = 'active' AND end_date BETWEEN CURDATE() AND CURDATE() + INTERVAL 60 DAY) AS ending,
          (SELECT COUNT(*) FROM customers
            WHERE created_at >= CURDATE() - INTERVAL 30 DAY) AS new_customers,
          (SELECT COUNT(*) FROM maintenance_requests
            WHERE status IN ('new','under_review','assigned') AND priority IN ('high','urgent')) AS urgent,
          (SELECT COALESCE(SUM(amount), 0) FROM payments WHERE status = 'overdue') AS arrears,
          (SELECT COUNT(*) FROM users
            WHERE is_active = 1 AND last_seen_at >= NOW() - INTERVAL 7 DAY) AS seen_week
    ")->fetch() ?: [];

    $num     = static fn (string $key): int => (int) ($ctx[$key] ?? 0);
    $arrears = (float) ($ctx['arrears'] ?? 0);

    /* The tone belongs to the line, not to the card. "9 overdue" is a problem
       and says so; "3 ending within 60 days" is a diary entry and stays
       neutral. A row where every line is coloured is a row where none of them
       mean anything — and the colour is never the only channel, because each
       line spells out in words what it is reporting. */
    return [
        ['icon' => 'bi-buildings', 'tone' => 'primary', 'label' => 'Total Properties', 'value' => $totalProperties,
         'note' => $num('available') . ' available to let or sell'],

        ['icon' => 'bi-key', 'tone' => 'success', 'label' => 'Active Rentals', 'value' => $activeRentals,
         'note'     => $num('ending') > 0
            ? $num('ending') . ' ending within 60 days'
            : 'None ending within 60 days',
         'noteIcon' => $num('ending') > 0 ? 'bi-calendar-event' : null,
         'noteTone' => $num('ending') > 0 ? 'warn' : null],

        ['icon' => 'bi-people', 'tone' => 'info', 'label' => 'Customers', 'value' => $totalCustomers,
         'note'     => $num('new_customers') > 0
            ? '+' . $num('new_customers') . ' in the last 30 days'
            : 'None added in the last 30 days',
         'noteIcon' => $num('new_customers') > 0 ? 'bi-arrow-up-short' : null,
         'noteTone' => $num('new_customers') > 0 ? 'up' : null],

        ['icon' => 'bi-wrench-adjustable', 'tone' => 'warning', 'label' => 'Pending Maintenance', 'value' => $pendingMaint,
         'note'     => $num('urgent') > 0
            ? $num('urgent') . ' high or urgent'
            : 'Nothing urgent in the queue',
         'noteIcon' => $num('urgent') > 0 ? 'bi-exclamation-triangle' : null,
         'noteTone' => $num('urgent') > 0 ? 'warn' : null],

        ['icon' => 'bi-cash-stack', 'tone' => 'danger', 'label' => 'Overdue Payments', 'value' => $overduePayments,
         'note'     => $arrears > 0 ? formatCurrency($arrears) . ' outstanding' : 'Nothing outstanding',
         'noteTone' => $arrears > 0 ? 'down' : null],

        ['icon' => 'bi-shield-check', 'tone' => 'purple', 'label' => 'Active Users', 'value' => $totalUsers,
         'note' => $num('seen_week') . ' signed in this week'],
    ];
}

/**
 * Payments received, six whole calendar months, oldest first.
 *
 * Zero-filled before the query runs: grouping payments by month returns only
 * the months that had one, and a month with no takings has to read as a zero
 * on the line — dropping it closes the gap and flatters the trend.
 *
 * `label` is the axis tick. `full` names the month unambiguously across a year
 * end and says out loud when the last bucket is still filling — otherwise a
 * month that is three days old reads as a collapse in takings.
 *
 * @return array<int, array{label: string, full: string, total: float}>
 */
function dashboardRevenueSeries(): array
{
    $thisMonth = new DateTimeImmutable('first day of this month');
    $months    = [];
    for ($i = 5; $i >= 0; $i--) {
        $m = $thisMonth->modify("-{$i} month");
        $months[$m->format('Y-m')] = [
            'label' => $m->format('M'),
            'full'  => $m->format('F Y') . ($i === 0 ? ' (so far)' : ''),
            'total' => 0.0,
        ];
    }

    // Anchored to the first of the earliest bucket rather than "six months
    // back from today", which would open a seventh, part-month bucket.
    $stmt = getDBConnection()->prepare("
        SELECT DATE_FORMAT(payment_date, '%Y-%m') AS ym, SUM(amount) AS total
        FROM payments
        WHERE status = 'paid' AND payment_date >= :from
        GROUP BY ym
    ");
    $stmt->execute([':from' => $thisMonth->modify('-5 month')->format('Y-m-d')]);
    foreach ($stmt->fetchAll() as $r) {
        if (isset($months[$r['ym']])) {
            $months[$r['ym']]['total'] = (float) $r['total'];
        }
    }

    return array_values($months);
}

/**
 * The newest entries in the audit trail, with the name behind each one.
 *
 * The card shows eight; the export takes more, because a file is scrolled in a
 * spreadsheet rather than read in a panel. The ceiling is there so a hand-typed
 * limit cannot ask this page to render the whole trail.
 *
 * @return array<int, array<string, mixed>>
 */
function dashboardRecentActivity(int $limit = 8): array
{
    $stmt = getDBConnection()->prepare("
        SELECT a.*, u.full_name
        FROM audit_logs a
        LEFT JOIN users u ON a.user_id = u.id
        ORDER BY a.created_at DESC
        LIMIT :l
    ");
    $stmt->bindValue(':l', max(1, min(500, $limit)), PDO::PARAM_INT);
    $stmt->execute();

    return $stmt->fetchAll();
}

/* ─────────────────────────────────────────────────────────────────
   The three panels added alongside the activity feed and the quick
   actions: the deal book, the mix behind the revenue total, and the
   sales the agency closed most recently.

   Each is one statement, each is bounded — by a LIMIT or by the six
   values payment_type can hold — so the dashboard's query count grows
   by exactly three and none of them grows with the portfolio.
   ───────────────────────────────────────────────────────────────── */

/**
 * The newest entries on both books, sales and tenancies in one list.
 *
 * From this page's point of view a sale and a lease are the same event: a
 * customer, a property, a figure and a date. Splitting them into two panels
 * would ask the reader to merge them by eye to answer "what happened this
 * week", which is the only question the panel is for — so they are merged
 * here, in SQL, and each row says which book it came from.
 *
 * A sale's date is its sale_date, which is nullable, so it falls back to the
 * day the record was written rather than sorting to the bottom as NULL. A
 * lease is dated by its start — the day the tenancy became real.
 *
 * Each half is limited before the union and the union is limited again: the
 * database never sorts more than 2 × $limit rows however long the books get.
 *
 * @return array<int, array<string, mixed>>
 */
function dashboardRecentDeals(int $limit = 6): array
{
    $n = max(1, min(50, $limit));

    $stmt = getDBConnection()->prepare("
        (SELECT 'sale' AS kind,
                s.id            AS id,
                s.property_id   AS property_id,
                p.title         AS property_title,
                c.id            AS customer_id,
                c.full_name     AS customer_name,
                c.profile_photo AS customer_photo,
                s.sale_amount   AS amount,
                COALESCE(s.sale_date, DATE(s.created_at)) AS deal_date,
                s.status        AS status
           FROM sales s
           JOIN properties p ON p.id = s.property_id
           JOIN customers  c ON c.id = s.customer_id
          ORDER BY deal_date DESC, s.id DESC
          LIMIT :ls)
        UNION ALL
        (SELECT 'lease', l.id, l.property_id, p.title, c.id, c.full_name, c.profile_photo,
                l.rent_amount, l.start_date, l.status
           FROM leases l
           JOIN properties p ON p.id = l.property_id
           JOIN customers  c ON c.id = l.customer_id
          ORDER BY l.start_date DESC, l.id DESC
          LIMIT :ll)
        ORDER BY deal_date DESC, id DESC
        LIMIT :lo
    ");
    $stmt->bindValue(':ls', $n, PDO::PARAM_INT);
    $stmt->bindValue(':ll', $n, PDO::PARAM_INT);
    $stmt->bindValue(':lo', $n, PDO::PARAM_INT);
    $stmt->execute();

    /* "Sale" and "Rental" rather than the raw kind: the column is read as a
       word by a person, not matched as a key. The figure carries its own
       suffix for the same reason — a rent of 800 and a sale of 800 are not
       the same number, and the column would otherwise say they were. */
    return array_map(static function (array $r): array {
        $isSale          = $r['kind'] === 'sale';
        $r['type_label'] = $isSale ? 'Sale' : 'Rental';
        $r['amount']     = (float) $r['amount'];
        $r['suffix']     = $isSale ? '' : '/mo';
        return $r;
    }, $stmt->fetchAll());
}

/**
 * The revenue total, split by what the money was for.
 *
 * Deliberately the same window and the same filter as dashboardRevenueSeries()
 * — paid payments, six whole calendar months — so the figure in the middle of
 * the ring is the figure the line chart above it totals to. Two panels on one
 * screen quoting two different "revenue" numbers is worse than either panel
 * alone, and the only way that stays true is for both to be the same query
 * with a different GROUP BY.
 *
 * Bounded by the enum: payment_type holds six values, so this returns at most
 * six rows no matter how many payments exist.
 *
 * @return array<int, array{key:string,label:string,amount:float,pct:float,var:string}>
 */
function dashboardRevenueMix(int $months = 6): array
{
    /* The tones are the ones already in the palette, one per kind of money,
       assigned so the two biggest slices in a normal month — rent and sales —
       are the two most distinguishable hues. */
    static $tones = [
        'rent'     => '--primary', 'sale'   => '--success', 'deposit' => '--info',
        'late_fee' => '--warning', 'refund' => '--danger',  'other'   => '--purple',
    ];
    /* Named for what the money was, not for what a listing is offered as:
       uiLabel() reads 'rent' and 'sale' as a property's type and answers
       "For Rent" / "For Sale", which is the wrong sentence in a column of
       takings. */
    static $labels = [
        'rent'     => 'Rent',      'sale'   => 'Sales',   'deposit' => 'Deposits',
        'late_fee' => 'Late fees', 'refund' => 'Refunds', 'other'   => 'Other income',
    ];

    $from = (new DateTimeImmutable('first day of this month'))
        ->modify('-' . max(0, $months - 1) . ' month');

    $stmt = getDBConnection()->prepare("
        SELECT payment_type, SUM(amount) AS total
        FROM payments
        WHERE status = 'paid' AND payment_date >= :from
        GROUP BY payment_type
    ");
    $stmt->execute([':from' => $from->format('Y-m-d')]);
    $rows = $stmt->fetchAll();

    $total = 0.0;
    foreach ($rows as $r) {
        $total += (float) $r['total'];
    }
    if ($total <= 0) {
        return [];   // nothing was taken: the panel shows its empty state
    }

    $mix = [];
    foreach ($rows as $r) {
        $amount = (float) $r['total'];
        if ($amount <= 0) {
            continue;   // a nought is not worth a slice or a legend row
        }
        $key   = (string) $r['payment_type'];
        $mix[] = [
            'key'    => $key,
            'label'  => $labels[$key] ?? uiLabel($key),
            'amount' => $amount,
            'pct'    => $amount / $total * 100,
            'var'    => $tones[$key] ?? '--text-subtle',
        ];
    }

    // Largest share first, so the ring reads clockwise from the biggest slice
    // and the legend beside it is already in the order the eye wants.
    usort($mix, static fn (array $a, array $b): int => $b['amount'] <=> $a['amount']);

    return $mix;
}

/**
 * The most recently closed sales, with the listing's cover photo.
 *
 * The cover is fetched by correlated sub-select rather than a join: the outer
 * LIMIT is applied first, so the sub-select runs once per row shown — four
 * times — instead of the join fanning every sale out across all of its
 * property's images and then being de-duplicated.
 *
 * A listing with no uploads is not excluded; propertyImage() gives it a
 * seeded stock photograph, so the panel never renders a broken frame.
 *
 * @return array<int, array<string, mixed>>
 */
function dashboardRecentSales(int $limit = 4): array
{
    $stmt = getDBConnection()->prepare("
        SELECT s.id, s.sale_code, s.sale_amount, s.status,
               COALESCE(s.sale_date, DATE(s.created_at)) AS sale_on,
               p.id AS property_id, p.title, p.location, p.category,
               (SELECT pi.file_path
                  FROM property_images pi
                 WHERE pi.property_id = p.id
                 ORDER BY pi.is_cover DESC, pi.sort_order ASC, pi.id ASC
                 LIMIT 1) AS cover_path
          FROM sales s
          JOIN properties p ON p.id = s.property_id
         ORDER BY sale_on DESC, s.id DESC
         LIMIT :l
    ");
    $stmt->bindValue(':l', max(1, min(20, $limit)), PDO::PARAM_INT);
    $stmt->execute();

    return $stmt->fetchAll();
}
