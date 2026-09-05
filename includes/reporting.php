<?php
/**
 * Reporting foundation — the window, the comparison, the filters and the
 * data-quality checks every report is built on.
 *
 * The Phase 0 audit found that the Reports page was not wrong because anyone
 * wrote a careless query. It was wrong because six business definitions had
 * never been written down anywhere, so each query answered a slightly
 * different question and nothing reconciled: occupancy counted sale-only
 * stock in its denominator, revenue counted a payment dated three weeks into
 * the future, and "overdue" meant one thing on this page and another on the
 * Payments register.
 *
 * This file is where those definitions live now. Nothing in it draws
 * anything; it decides what a period is, what the period before it is, which
 * filters are real, and which of the numbers already in the database
 * contradict each other. Every report built on top of it inherits one answer
 * to each question rather than inventing its own.
 *
 * Loaded from includes/init.php after record_access.php, because the
 * data-quality checks read the same scopes the reports do.
 */

// ─── The vocabulary ────────────────────────────────────────────────────
//
// Every allowlist the request is measured against is declared here, as a
// constant, next to the thing it describes. A value that is not in one of
// these lists does not become a filter — it becomes nothing at all, which is
// the only way a request can be prevented from reaching SQL as an expression
// rather than as a value.

/** Falls back here whenever the request asks for a range that does not exist. */
const REPORT_RANGE_DEFAULT = 'last30';

/**
 * The longest custom span that will be honoured, in days.
 *
 * Five years. Not a security limit — the dates are bound parameters either
 * way — but a report covering a century groups into four hundred buckets and
 * draws a chart nobody can read, and the honest answer to that request is a
 * shorter one.
 */
const REPORT_MAX_SPAN_DAYS = 1827;

/** properties.category, verbatim. A category is an ENUM here, not a table. */
const REPORT_CATEGORIES = ['house', 'apartment', 'villa', 'land', 'office', 'commercial', 'warehouse'];

/** payments.status, verbatim. */
const REPORT_PAYMENT_STATUSES = ['pending', 'paid', 'partial', 'overdue', 'cancelled'];

/** payments.payment_method, verbatim. There is no mobile-money member. */
const REPORT_PAYMENT_METHODS = ['cash', 'bank_transfer', 'check', 'card', 'other'];

/**
 * The payments that count as collected operating revenue — approved
 * decision 2. A deposit is money held against a tenancy, not money earned,
 * and a refund is money going the other way; both are reported separately
 * and neither is added to revenue.
 */
const REPORT_REVENUE_TYPES = ['rent', 'sale', 'late_fee', 'other'];

/**
 * The business stream a payment belongs to — approved decision 3.
 *
 * reference_type, not payment_type. reference_type names the contract the
 * money was taken against and is written by the code that created the
 * payment; payment_type is a label chosen on a form, and the audit found one
 * row where the two disagree. Where they conflict the reference wins and the
 * row is counted by reportPaymentMismatches() so the conflict is visible
 * rather than silently resolved.
 */
const REPORT_STREAM_RENTAL = 'lease';
const REPORT_STREAM_SALE   = 'sale';

// ─── Windows ───────────────────────────────────────────────────────────

/**
 * Today, as one value.
 *
 * Every date decision in a report resolves against this rather than calling
 * the clock again, so a report that takes two seconds to build cannot have
 * its window end on one day and its comparison start on another.
 */
function reportToday(): DateTimeImmutable
{
    static $today = null;

    return $today ??= new DateTimeImmutable('today');
}

/**
 * The ranges a request may ask for, keyed by the token it sends.
 *
 * The token is all the request supplies. It never sends a date expression, an
 * interval or a number of months — reportResolveRange() turns the token into
 * two dates and those are bound as parameters.
 *
 * @return array<string,string>
 */
function reportRanges(): array
{
    return [
        'today'        => 'Today',
        'last7'        => 'Last 7 days',
        'last30'       => 'Last 30 days',
        'this_month'   => 'This month',
        'last_month'   => 'Last month',
        'this_quarter' => 'This quarter',
        'this_year'    => 'This year',
        'custom'       => 'Custom range',
    ];
}

/**
 * A range token, resolved to its two calendar dates.
 *
 * 'custom' is deliberately absent: it has no fixed answer and is handled by
 * reportCustomRange(), which has to validate what the request sent.
 *
 * @return array{0:DateTimeImmutable,1:DateTimeImmutable}
 */
function reportResolveRange(string $key, ?DateTimeImmutable $today = null): array
{
    $today ??= reportToday();
    $year   = (int) $today->format('Y');

    switch ($key) {
        case 'today':
            return [$today, $today];

        // Inclusive of today, which is what "last 7 days" means to the person
        // asking: six days back plus today is seven days of trading.
        case 'last7':
            return [$today->modify('-6 days'), $today];

        case 'last30':
            return [$today->modify('-29 days'), $today];

        case 'this_month':
            return [$today->modify('first day of this month'), $today->modify('last day of this month')];

        case 'last_month':
            $first = $today->modify('first day of last month');
            return [$first, $first->modify('last day of this month')];

        case 'this_quarter':
            $startMonth = (intdiv((int) $today->format('n') - 1, 3) * 3) + 1;
            $first      = $today->setDate($year, $startMonth, 1);
            return [$first, $first->modify('+3 months')->modify('-1 day')];

        case 'this_year':
            return [$today->setDate($year, 1, 1), $today->setDate($year, 12, 31)];
    }

    // Unknown token. Reached only if reportRanges() and this switch ever drift
    // apart, and it fails to the default rather than to no window at all.
    return [$today->modify('-29 days'), $today];
}

/**
 * A date the request supplied, or null.
 *
 * createFromFormat() alone is not enough: it happily reads '2026-13-45' as a
 * date in 2027 and hands it back without complaint. Formatting the result and
 * comparing it with the input is what catches that, and it is the difference
 * between a nonsense range quietly returning nonsense figures and a nonsense
 * range being refused.
 */
function reportParseDate(mixed $value): ?DateTimeImmutable
{
    if (!is_string($value) || $value === '') {
        return null;
    }

    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

    return ($date && $date->format('Y-m-d') === $value) ? $date : null;
}

/**
 * The two dates behind ?range=custom, or a reason they were refused.
 *
 * Refusal is never an error page. An unusable custom range falls back to the
 * default window and says so in a notice, because a report that renders the
 * last thirty days with a line explaining why is more use than one that
 * renders nothing.
 *
 * @return array{0:?DateTimeImmutable,1:?DateTimeImmutable,2:?string}
 */
function reportCustomRange(array $query, ?DateTimeImmutable $today = null): array
{
    $today ??= reportToday();

    $from = reportParseDate($query['from'] ?? null);
    $to   = reportParseDate($query['to'] ?? null);

    if (!$from || !$to) {
        return [null, null, 'Those dates could not be read, so the last 30 days are shown instead.'];
    }

    // Reversed rather than rejected: someone who picked the end date first has
    // described a real period, just backwards, and swapping is what they meant.
    if ($from > $to) {
        [$from, $to] = [$to, $from];
    }

    if ($from->diff($to)->days + 1 > REPORT_MAX_SPAN_DAYS) {
        return [null, null, 'That range is longer than five years, so the last 30 days are shown instead.'];
    }

    return [$from, $to, null];
}

/**
 * How finely a span of days should be grouped.
 *
 * Returns a token, never a format string. reportGrainExpression() turns the
 * token into SQL from a fixed map, so no part of a GROUP BY is ever assembled
 * from something the request sent.
 */
function reportGrain(int $days): string
{
    if ($days <= 31) {
        return 'day';
    }
    if ($days <= 120) {
        return 'week';
    }
    if ($days <= 1096) {
        return 'month';
    }

    return 'quarter';
}

/**
 * The SQL expression that buckets a date column at a given grain.
 *
 * $column is supplied by our own code — a qualified column name we wrote —
 * and $grain is one of four tokens. Neither comes from the request.
 */
function reportGrainExpression(string $grain, string $column): string
{
    switch ($grain) {
        case 'day':
            return "DATE_FORMAT({$column}, '%Y-%m-%d')";

        // ISO week with its ISO year: %x-W%v pairs correctly across a new year,
        // where %Y-W%v puts the last days of December in week 1 of the wrong year.
        case 'week':
            return "DATE_FORMAT({$column}, '%x-W%v')";

        case 'quarter':
            return "CONCAT(YEAR({$column}), '-Q', QUARTER({$column}))";
    }

    return "DATE_FORMAT({$column}, '%Y-%m')";
}

/** A bucket key, as a person would read it on an axis. */
function reportGrainLabel(string $grain, string $bucket): string
{
    switch ($grain) {
        case 'day':
            $d = reportParseDate($bucket);
            return $d ? $d->format('j M') : $bucket;

        case 'week':
            return str_replace('-W', ' wk ', $bucket);

        case 'month':
            $d = DateTimeImmutable::createFromFormat('!Y-m-d', $bucket . '-01');
            return $d ? $d->format('M Y') : $bucket;
    }

    return $bucket;
}

/**
 * The reporting window, resolved from the request.
 *
 * Returns everything a query or a heading could want to know about the period
 * being reported on:
 *
 *   key, label      what was asked for
 *   from, to        the window as the calendar describes it
 *   to_capped       the window as far as it has actually happened
 *   prev_from/to    the immediately preceding window of equal length
 *   days            the length both windows share
 *   grain           how to bucket a series across it
 *   is_partial      whether the period is still running
 *   notice          why a custom range was not honoured, when it was not
 *
 * The distinction between `to` and `to_capped` is what makes the comparison
 * honest. "This month" on the 23rd is 23 days of trading, and setting it
 * against a full previous month would report a collapse in revenue every
 * month until the last day of it. Both windows are measured to `to_capped`,
 * so twenty-three days are always compared with twenty-three days.
 *
 * @return array<string,mixed>
 */
function reportWindow(?array $query = null): array
{
    $query ??= $_GET;
    $today  = reportToday();
    $ranges = reportRanges();
    $notice = null;

    $key = uiPick($query['range'] ?? '', array_keys($ranges)) ?: REPORT_RANGE_DEFAULT;

    if ($key === 'custom') {
        [$from, $to, $notice] = reportCustomRange($query, $today);
        if (!$from || !$to) {
            $key = REPORT_RANGE_DEFAULT;
            [$from, $to] = reportResolveRange($key, $today);
        }
    } else {
        [$from, $to] = reportResolveRange($key, $today);
    }

    // A window that has not finished is measured to today. A window that lies
    // entirely in the future has no elapsed days at all, and is measured to
    // its own start so the arithmetic below still describes one real day
    // rather than a negative period.
    $capped = $to > $today ? $today : $to;
    if ($capped < $from) {
        $capped = $from;
    }

    $days     = $from->diff($capped)->days + 1;
    $prevTo   = $from->modify('-1 day');
    $prevFrom = $prevTo->modify('-' . ($days - 1) . ' days');

    return [
        'key'        => $key,
        'label'      => $key === 'custom'
            ? $from->format('j M Y') . ' – ' . $to->format('j M Y')
            : $ranges[$key],
        'from'       => $from->format('Y-m-d'),
        'to'         => $to->format('Y-m-d'),
        'to_capped'  => $capped->format('Y-m-d'),
        'prev_from'  => $prevFrom->format('Y-m-d'),
        'prev_to'    => $prevTo->format('Y-m-d'),
        'days'       => $days,
        'grain'      => reportGrain($days),
        'is_partial' => $to > $today,
        'today'      => $today->format('Y-m-d'),
        'notice'     => $notice,
    ];
}

// ─── Comparison ────────────────────────────────────────────────────────

/**
 * One period's figure set against the one before it.
 *
 * The awkward case is a previous period of zero. Dividing by it produces
 * infinity, and rendering "↑ ∞%" or the "+100%" that most dashboards quietly
 * substitute are both lies about what happened. Here the percentage stays
 * null and the label says what is actually true — that there was nothing
 * before to compare against.
 *
 * @return array{current:float,previous:float,difference:float,percentage:?float,direction:string,label:string}
 */
function reportDelta(float $current, float $previous): array
{
    $difference = $current - $previous;
    $base       = abs($previous);
    $percentage = null;

    if ($base > 0.0) {
        $percentage = round($difference / $base * 100, 1);
    } elseif ($current == 0.0) {
        // Nothing then, nothing now. That is a flat line, not a missing answer.
        $percentage = 0.0;
    }

    // A hair either side of zero is not a movement; on money it is rounding.
    $direction = abs($difference) < 0.005 ? 'flat' : ($difference > 0 ? 'up' : 'down');

    if ($direction === 'flat') {
        $label = 'No change vs previous period';
    } elseif ($percentage === null) {
        $label = 'New this period — nothing recorded previously';
    } else {
        $label = sprintf(
            '%s%s%% vs previous period',
            $percentage > 0 ? '+' : '−',
            number_format(abs($percentage), 1)
        );
    }

    return [
        'current'    => $current,
        'previous'   => $previous,
        'difference' => $difference,
        'percentage' => $percentage,
        'direction'  => $direction,
        'label'      => $label,
    ];
}

// ─── Formatting ────────────────────────────────────────────────────────

/**
 * Money, shortened, for an axis tick or a tile that has no room.
 *
 * Reads the configured currency symbol like formatCurrency() does, so a
 * report cannot end up quoting a different currency from the receipt.
 */
function reportCompact(float $value): string
{
    $symbol = currencySymbol();
    $sign   = $value < 0 ? '−' : '';
    $abs    = abs($value);

    if ($abs >= 1000000) {
        return $sign . $symbol . rtrim(rtrim(number_format($abs / 1000000, 1), '0'), '.') . 'M';
    }
    if ($abs >= 1000) {
        return $sign . $symbol . rtrim(rtrim(number_format($abs / 1000, 1), '0'), '.') . 'K';
    }

    return $sign . $symbol . number_format($abs);
}

/** A rate, or an em dash when there was nothing to take a rate of. */
function reportPercent(?float $value, int $decimals = 1): string
{
    return $value === null ? '—' : number_format($value, $decimals) . '%';
}

/**
 * A share of a total, guarded against the empty denominator.
 *
 * Returns null rather than zero when there is no total: "0% of nothing" and
 * "no data" are different statements and only one of them is true.
 */
function reportShare(float $part, float $total, int $decimals = 1): ?float
{
    return $total > 0.0 ? round($part / $total * 100, $decimals) : null;
}

// ─── Filters ───────────────────────────────────────────────────────────

/**
 * The filters a report accepts, and what each one is allowed to be.
 *
 * Only filters the current schema can actually honour appear here. There is
 * no tenant filter and no property-status filter: the first would need a
 * customer scope that varies per report, and the second would filter on
 * properties.status, which the audit proved is not maintained.
 *
 * @return array<string,array{type:string,label:string}>
 */
function reportFilterSpec(): array
{
    return [
        'property'       => ['type' => 'id',   'label' => 'Property'],
        'category'       => ['type' => 'enum', 'label' => 'Category'],
        'agent'          => ['type' => 'id',   'label' => 'Agent'],
        'owner'          => ['type' => 'id',   'label' => 'Owner'],
        'location'       => ['type' => 'list', 'label' => 'Location'],
        'payment_status' => ['type' => 'enum', 'label' => 'Payment status'],
        'payment_method' => ['type' => 'enum', 'label' => 'Payment method'],
    ];
}

/**
 * Whether one property id sits inside the reader's own scope.
 *
 * Deliberately wider than canActOnProperty(), which also demands the property
 * be unarchived and the caller be able to manage it. A report may legitimately
 * cover a property that has since been filed away — the money it took is
 * still the company's money — so this asks only whether the reader is allowed
 * to see the row at all.
 */
function reportPropertyInScope(int $id): bool
{
    if ($id <= 0) {
        return false;
    }

    [$scope, $params] = propertyRecordScope('p');
    $stmt = getDBConnection()->prepare("SELECT 1 FROM properties p WHERE p.id = :rf_id AND ({$scope}) LIMIT 1");
    $stmt->execute($params + [':rf_id' => $id]);

    return (bool) $stmt->fetchColumn();
}

/** Whether one owner id sits inside the reader's own scope. */
function reportOwnerInScope(int $id): bool
{
    if ($id <= 0) {
        return false;
    }

    [$scope, $params] = ownerViewScope('o');
    $stmt = getDBConnection()->prepare("SELECT 1 FROM owners o WHERE o.id = :rf_id AND ({$scope}) LIMIT 1");
    $stmt->execute($params + [':rf_id' => $id]);

    return (bool) $stmt->fetchColumn();
}

/**
 * The locations that appear on properties the reader may see.
 *
 * Doubles as the allowlist a submitted ?location= is measured against, so the
 * filter can only ever name a place that exists in the reader's own portfolio
 * — which means the value is validated by the same query that would have
 * offered it, and the two cannot drift apart.
 *
 * @return string[]
 */
function reportLocationOptions(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    [$scope, $params] = propertyRecordScope('p');
    $stmt = getDBConnection()->prepare("
        SELECT DISTINCT p.location
        FROM properties p
        WHERE p.location IS NOT NULL AND p.location <> '' AND ({$scope})
        ORDER BY p.location
    ");
    $stmt->execute($params);

    return $cache = array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

/**
 * The agents whose figures the reader is entitled to break out.
 *
 * An administrator gets the desk. An agent gets themselves and nobody else —
 * enforced here rather than only in the scope predicates, so ?agent=7 typed
 * by agent 6 is refused outright instead of quietly returning an empty
 * report that leaks the fact that agent 7 exists.
 *
 * @return array<int,string>
 */
function reportAgentOptions(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    $sql = "SELECT u.id, u.full_name
            FROM users u
            JOIN roles r ON u.role_id = r.id
            WHERE r.name = :rf_role AND u.is_active = 1";
    $params = [':rf_role' => ROLE_AGENT];

    if (getUserRole() === ROLE_AGENT) {
        $sql .= " AND u.id = :rf_self";
        $params[':rf_self'] = recordScopeUserId();
    }

    $stmt = getDBConnection()->prepare($sql . " ORDER BY u.full_name");
    $stmt->execute($params);

    $out = [];
    foreach ($stmt->fetchAll() as $row) {
        $out[(int) $row['id']] = (string) $row['full_name'];
    }

    return $cache = $out;
}

/**
 * The request's filters, validated into values a query can be handed.
 *
 * Every filter is either a member of an allowlist, or an id the reader has
 * been confirmed to hold, or absent. There is no fourth outcome: a value that
 * fails becomes null and its name is recorded in 'rejected', so the page can
 * say that a filter was dropped rather than pretending it was applied.
 *
 * @return array<string,mixed>
 */
function reportFilters(?array $query = null): array
{
    $query ??= $_GET;
    $out = ['rejected' => []];

    foreach (array_keys(reportFilterSpec()) as $name) {
        $raw = $query[$name] ?? '';
        if ($raw === '' || $raw === null || is_array($raw)) {
            $out[$name] = null;
            continue;
        }

        $value = null;

        switch ($name) {
            case 'property':
                $id = (int) filter_var($raw, FILTER_VALIDATE_INT);
                $value = ($id > 0 && reportPropertyInScope($id)) ? $id : null;
                break;

            case 'owner':
                $id = (int) filter_var($raw, FILTER_VALIDATE_INT);
                $value = ($id > 0 && reportOwnerInScope($id)) ? $id : null;
                break;

            case 'agent':
                $id = (int) filter_var($raw, FILTER_VALIDATE_INT);
                $value = isset(reportAgentOptions()[$id]) ? $id : null;
                break;

            case 'location':
                $value = uiPick($raw, reportLocationOptions()) ?: null;
                break;

            case 'category':
                $value = uiPick($raw, REPORT_CATEGORIES) ?: null;
                break;

            case 'payment_status':
                $value = uiPick($raw, REPORT_PAYMENT_STATUSES) ?: null;
                break;

            case 'payment_method':
                $value = uiPick($raw, REPORT_PAYMENT_METHODS) ?: null;
                break;
        }

        $out[$name] = $value;
        if ($value === null) {
            $out['rejected'][] = $name;
        }
    }

    return $out;
}

/** How many filters are actually narrowing the report. */
function reportFilterCount(array $filters): int
{
    $n = 0;
    foreach (array_keys(reportFilterSpec()) as $name) {
        if (($filters[$name] ?? null) !== null) {
            $n++;
        }
    }

    return $n;
}

/**
 * The window and the filters as query parameters, so a link can carry them.
 *
 * This is what makes a tab a real destination rather than a state change: the
 * strip hands each tab the reader's current period and filters, and every
 * report in the module is a URL that can be bookmarked or sent to whoever
 * needs to answer for the number on it.
 *
 * @return array<string,string>
 */
function reportQueryParams(array $window, array $filters, array $overrides = []): array
{
    $params = ['page' => 'reports', 'range' => $window['key']];

    if ($window['key'] === 'custom') {
        $params['from'] = $window['from'];
        $params['to']   = $window['to'];
    }

    foreach (array_keys(reportFilterSpec()) as $name) {
        if (($filters[$name] ?? null) !== null) {
            $params[$name] = (string) $filters[$name];
        }
    }

    foreach ($overrides as $k => $v) {
        if ($v === null) {
            unset($params[$k]);
        } else {
            $params[$k] = (string) $v;
        }
    }

    return $params;
}

/** A report URL carrying the current period and filters. */
function reportUrl(array $window, array $filters, array $overrides = []): string
{
    return APP_URL . '/index.php?' . http_build_query(reportQueryParams($window, $filters, $overrides));
}

/**
 * A drill-down URL: the same report, the same period, the same filters, plus
 * the metric being asked about.
 *
 * Built on reportUrl() rather than beside it, which is what makes §10's
 * promise structural: a drill-down inherits the parent report's context
 * because it is *the same query string* with two parameters added, not
 * because somebody remembered to copy the filters across. A period, a custom
 * range, a comparison and five filter chips all travel without this function
 * knowing what any of them are.
 *
 * The key is whatever names the slice — a stream, a status, a chart bucket, a
 * property id. It is not validated here: this builds links, and a link the
 * application wrote is no more trustworthy than one somebody typed by the
 * time it comes back. ReportDrilldown and CoreAnalytics check it on arrival.
 */
function reportDrillUrl(
    array $window,
    array $filters,
    string $tab,
    string $metric,
    string $key = '',
    array $overrides = []
): string {
    return reportUrl($window, $filters, [
        'tab'    => $tab,
        'action' => 'drill',
        'metric' => $metric,
        'key'    => $key === '' ? null : $key,
    ] + $overrides);
}

// ─── Data quality ──────────────────────────────────────────────────────
//
// These do not correct anything. They count the rows where the database
// already contradicts itself, so a figure that is arithmetically right but
// built on a contradiction can say so instead of looking certain.

/**
 * Payments whose reference and type imply different business streams.
 *
 * Approved decision 3 settles the arithmetic — reference_type wins — but a
 * settled tiebreak is not the same as a clean ledger. Every row counted here
 * is one where somebody recorded a sale payment against a tenancy or the
 * reverse, and the revenue split is only as good as the number of them.
 *
 * The sample rows are gated on payments.view: the count is a health
 * indicator anyone reading the report may see, the transactions behind it
 * are not.
 *
 * @return array{count:int,amount:float,rows:array}
 */
function reportPaymentMismatches(int $sampleSize = 10): array
{
    [$scope, $params] = paymentViewScope('py', 'p');

    // Only genuine contradictions. A deposit, a refund, a late fee or an
    // 'other' can legitimately hang off either kind of contract, so they are
    // not conflicts and are not counted as ones.
    $conflict = "(
        (py.reference_type = 'lease' AND py.payment_type = 'sale')
        OR (py.reference_type = 'sale' AND py.payment_type = 'rent')
        OR (py.reference_type = 'reservation' AND py.payment_type IN ('rent','sale'))
    )";

    $db   = getDBConnection();
    $stmt = $db->prepare("
        SELECT COUNT(*) AS cnt, COALESCE(SUM(py.amount), 0) AS amount
        FROM payments py
        LEFT JOIN properties p ON py.property_id = p.id
        WHERE py.status <> 'cancelled' AND {$conflict} AND ({$scope})
    ");
    $stmt->execute($params);
    $summary = $stmt->fetch() ?: ['cnt' => 0, 'amount' => 0];

    $rows = [];
    if ((int) $summary['cnt'] > 0 && can('payments.view')) {
        // LIMIT is an integer this file chose, clamped, never a request value.
        $limit = max(1, min(50, $sampleSize));
        $stmt  = $db->prepare("
            SELECT py.id, py.payment_code, py.amount, py.payment_date,
                   py.reference_type, py.reference_id, py.payment_type
            FROM payments py
            LEFT JOIN properties p ON py.property_id = p.id
            WHERE py.status <> 'cancelled' AND {$conflict} AND ({$scope})
            ORDER BY py.payment_date DESC, py.id DESC
            LIMIT {$limit}
        ");
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
    }

    return [
        'count'  => (int) $summary['cnt'],
        'amount' => (float) $summary['amount'],
        'rows'   => $rows,
    ];
}

/**
 * Properties whose recorded status contradicts their own records.
 *
 * The audit found five. None of them is a reporting bug — they are the
 * consequence of properties.status not being written when a lease is signed
 * or a reservation confirmed — and none of them is fixed here. Reports
 * derives occupancy from leases precisely so that it does not need
 * properties.status to be right; this counts how wrong it is anyway, because
 * the register itself is still showing those five as available.
 *
 * @return array<string,array{count:int,label:string,detail:string}>
 */
function reportPropertyStateIssues(): array
{
    [$scope, $params] = propertyRecordScope('p');

    $activeLease = "EXISTS (SELECT 1 FROM leases dq_l
                            WHERE dq_l.property_id = p.id AND dq_l.status = 'active')";
    $heldNow = "EXISTS (SELECT 1 FROM reservations dq_r
                        WHERE dq_r.property_id = p.id
                          AND dq_r.status IN ('active','confirmed')
                          AND dq_r.expiry_date >= CURDATE())";
    $completedSale = "EXISTS (SELECT 1 FROM sales dq_s
                              WHERE dq_s.property_id = p.id AND dq_s.status = 'completed')";

    $checks = [
        'let_not_rented' => [
            'label'  => 'Let, but the register says available',
            'detail' => 'An active lease exists and the property status is not "rented".',
            'where'  => "{$activeLease} AND p.status <> 'rented'",
        ],
        'rented_no_lease' => [
            'label'  => 'Marked rented with no active lease',
            'detail' => 'The property status says "rented" and no lease is active against it.',
            'where'  => "NOT {$activeLease} AND p.status = 'rented'",
        ],
        'held_not_reserved' => [
            'label'  => 'Held, but not shown as reserved',
            'detail' => 'A live reservation exists and the property status is not "reserved".',
            'where'  => "{$heldNow} AND p.status <> 'reserved'",
        ],
        'sold_no_sale' => [
            'label'  => 'Marked sold with no completed sale',
            'detail' => 'The property status says "sold" and no sale against it has completed.',
            'where'  => "NOT {$completedSale} AND p.status = 'sold'",
        ],
    ];

    // One pass. Four conditional counts over the same rows rather than four
    // scans of the register for four numbers nobody reads separately.
    $selects = [];
    foreach ($checks as $key => $check) {
        $selects[] = "COALESCE(SUM({$check['where']}), 0) AS `{$key}`";
    }

    $stmt = getDBConnection()->prepare("
        SELECT " . implode(",\n               ", $selects) . "
        FROM properties p
        WHERE p.is_archived = 0 AND ({$scope})
    ");
    $stmt->execute($params);
    $counts = $stmt->fetch() ?: [];

    $out = [];
    foreach ($checks as $key => $check) {
        $out[$key] = [
            'count'  => (int) ($counts[$key] ?? 0),
            'label'  => $check['label'],
            'detail' => $check['detail'],
        ];
    }

    return $out;
}

/**
 * Everything the reports know to be inconsistent, in one call.
 *
 * 'clean' is the question a page actually asks — whether to draw the warning
 * at all — so it is answered here rather than by every caller re-summing the
 * parts and getting it subtly different.
 *
 * @return array<string,mixed>
 */
function reportDataQuality(): array
{
    $payments = reportPaymentMismatches();
    $states   = reportPropertyStateIssues();

    $stateTotal = 0;
    foreach ($states as $s) {
        $stateTotal += $s['count'];
    }

    $total = $payments['count'] + $stateTotal;

    return [
        'payments'    => $payments,
        'states'      => $states,
        'state_total' => $stateTotal,
        'total'       => $total,
        'clean'       => $total === 0,
    ];
}
