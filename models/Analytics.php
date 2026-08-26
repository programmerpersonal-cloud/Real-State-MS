<?php
/**
 * Analytics — the base every report is built on.
 *
 * This class exists to hold three things that were being restated, slightly
 * differently, in every query the old Reports page ran: the connection, the
 * period, and the reader's access scope. The third is the one that matters.
 * The Phase 0 audit found the scopes were applied correctly, but applied by
 * hand, six times, from memory — and a seventh query that forgot would have
 * leaked the company's turnover onto a page meant to describe one agent's
 * own book. Here a subclass cannot forget, because it never writes a WHERE
 * clause without asking for one.
 *
 * Nothing in this file defines a business figure. It defines how to ask.
 * What the figures mean is settled in models/CoreAnalytics.php, against the
 * definitions approved after the audit.
 *
 * Parameter names are prefixed by origin so several can coexist in one
 * statement without colliding: `:ra_*` from the record scopes,
 * `:w_*` from the window, `:f_*` from the filters.
 */
abstract class Analytics
{
    protected PDO $db;

    /** @var array<string,mixed> from reportWindow() */
    protected array $window;

    /** @var array<string,mixed> from reportFilters() */
    protected array $filters;

    /** Scopes are fetched once per alias pair and reused. @var array<string,array> */
    private array $scopeCache = [];

    /**
     * @param array<string,mixed> $window  reportWindow()
     * @param array<string,mixed> $filters reportFilters()
     */
    public function __construct(array $window, array $filters = [])
    {
        $this->db      = getDBConnection();
        $this->window  = $window;
        $this->filters = $filters;
    }

    /** The window this instance reports on. */
    public function window(): array
    {
        return $this->window;
    }

    /** The filters this instance reports under. */
    public function filters(): array
    {
        return $this->filters;
    }

    // ─── The period ────────────────────────────────────────────────────

    /**
     * The window's dates, bound.
     *
     * `to` is the capped end, not the calendar end: a period that has not
     * finished is only reported as far as it has happened, and the comparison
     * window is the same number of days so the two are answerable against
     * each other. `today` rides along because approved decision 2 forbids
     * counting a payment dated later than it as collected, and every revenue
     * query therefore needs it.
     *
     * @return array<string,string>
     */
    protected function periodParams(bool $previous = false): array
    {
        return $previous
            ? [':w_from' => $this->window['prev_from'], ':w_to' => $this->window['prev_to'], ':w_today' => $this->window['today']]
            : [':w_from' => $this->window['from'],      ':w_to' => $this->window['to_capped'], ':w_today' => $this->window['today']];
    }

    /** `col BETWEEN :w_from AND :w_to`, for a date column this code named. */
    protected function withinPeriod(string $column): string
    {
        return "{$column} BETWEEN :w_from AND :w_to";
    }

    /** The bucketing expression for this window's grain. */
    protected function bucket(string $column): string
    {
        return reportGrainExpression($this->window['grain'], $column);
    }

    // ─── Access scopes ─────────────────────────────────────────────────
    //
    // Thin wrappers over includes/record_access.php. They exist so a subclass
    // asks for "the payment scope" rather than remembering which helper takes
    // which aliases, and so each is resolved once per request instead of once
    // per query.

    /** @return array{0:string,1:array} */
    protected function scope(string $kind, string $a = '', string $b = ''): array
    {
        $key = $kind . '|' . $a . '|' . $b;
        if (isset($this->scopeCache[$key])) {
            return $this->scopeCache[$key];
        }

        switch ($kind) {
            case 'property': $scope = propertyRecordScope($a ?: 'p'); break;
            case 'lease':    $scope = leaseViewScope($a ?: 'l', $b ?: 'p'); break;
            case 'payment':  $scope = paymentViewScope($a ?: 'py', $b ?: 'p'); break;
            case 'sale':     $scope = saleViewScope($a ?: 's', $b ?: 'p'); break;
            case 'reservation': $scope = reservationViewScope($a ?: 'r', $b ?: 'p'); break;
            case 'maintenance': $scope = maintenanceViewScope($a ?: 'm', $b ?: 'p'); break;
            case 'customer': $scope = customerViewScope($a ?: 'c'); break;
            case 'owner':    $scope = ownerViewScope($a ?: 'o'); break;

            // Fail closed. An unrecognised scope name is a programming error,
            // and the safe outcome of a programming error in an access check
            // is no rows rather than all of them.
            default:         $scope = ['0 = 1', []];
        }

        return $this->scopeCache[$key] = $scope;
    }

    // ─── Filters ───────────────────────────────────────────────────────

    /**
     * The filters that narrow a query by property, as SQL.
     *
     * Every one of these is a column on `properties`, so any query that can
     * reach the properties table can honour them — which is why they are
     * built once here rather than per report. The values were validated by
     * reportFilters() before they arrived; they are still bound, never
     * interpolated.
     *
     * @return array{0:string,1:array} predicate fragment (may be ''), params
     */
    protected function propertyFilters(string $p = 'p'): array
    {
        $sql    = '';
        $params = [];

        if (($id = $this->filters['property'] ?? null) !== null) {
            $sql .= " AND {$p}.id = :f_property";
            $params[':f_property'] = $id;
        }
        if (($category = $this->filters['category'] ?? null) !== null) {
            $sql .= " AND {$p}.category = :f_category";
            $params[':f_category'] = $category;
        }
        if (($location = $this->filters['location'] ?? null) !== null) {
            $sql .= " AND {$p}.location = :f_location";
            $params[':f_location'] = $location;
        }
        if (($agent = $this->filters['agent'] ?? null) !== null) {
            $sql .= " AND {$p}.agent_id = :f_agent";
            $params[':f_agent'] = $agent;
        }
        if (($owner = $this->filters['owner'] ?? null) !== null) {
            $sql .= " AND {$p}.owner_id = :f_owner";
            $params[':f_owner'] = $owner;
        }

        return [$sql, $params];
    }

    /**
     * The filters that narrow a payments *ledger* query.
     *
     * Deliberately not applied to revenue. Collected revenue is defined as
     * paid payments and nothing else (approved decision 2), so a
     * ?payment_status=pending on a revenue figure would be a request to
     * contradict its own definition. The status and method filters belong to
     * reports that describe the ledger itself, and those call this.
     *
     * @return array{0:string,1:array}
     */
    protected function paymentLedgerFilters(string $py = 'py'): array
    {
        $sql    = '';
        $params = [];

        if (($status = $this->filters['payment_status'] ?? null) !== null) {
            $sql .= " AND {$py}.status = :f_pay_status";
            $params[':f_pay_status'] = $status;
        }
        if (($method = $this->filters['payment_method'] ?? null) !== null) {
            $sql .= " AND {$py}.payment_method = :f_pay_method";
            $params[':f_pay_method'] = $method;
        }

        return [$sql, $params];
    }

    /**
     * The payment-method filter, on its own.
     *
     * Separate from paymentLedgerFilters() because this one is safe to apply
     * to *every* payment query, revenue included. Narrowing to card payments
     * leaves "collected revenue" a correctly named figure — the revenue that
     * arrived by card. Narrowing by status would not: status is what defines
     * collected revenue, so filtering on it renames the metric without
     * renaming the tile. That filter is therefore never exposed, and the
     * status breakdown analyses the dimension instead.
     *
     * @return array{0:string,1:array}
     */
    protected function paymentMethodFilter(string $py = 'py'): array
    {
        $method = $this->filters['payment_method'] ?? null;
        if ($method === null) {
            return ['', []];
        }

        return [" AND {$py}.payment_method = :f_pay_method", [':f_pay_method' => $method]];
    }

    /**
     * A previous-period series, folded onto the current period's buckets.
     *
     * Extracted from what CoreAnalytics::revenueComparisonSeries() worked out
     * in Phase 3, because payment activity needs exactly the same trick and
     * two copies of it would drift. Two windows of identical length can still
     * span a different number of weeks or months depending on which day they
     * start, so bucketing each by its own calendar produces series of
     * different lengths — and the only ways to draw those together are to drop
     * a bucket or stretch one. Summing the previous window by day-offset from
     * *its* start against this window's bucket boundaries gives day one
     * against day one, and equal lengths by construction.
     *
     * @param array<string,float> $daily previous-window totals keyed Y-m-d
     * @return array<int,array{bucket:string,label:string,total:float}>
     */
    protected function foldOntoCurrentBuckets(array $daily): array
    {
        $from    = new DateTimeImmutable($this->window['from']);
        $offsets = [];
        foreach ($this->bucketStarts() as $start) {
            // Clamped at zero: the first bucket snaps back to its Monday or
            // its first-of-month, which can sit before the window opens.
            $offsets[] = max(0, (int) $from->diff($start)->format('%r%a'));
        }

        $prevFrom = new DateTimeImmutable($this->window['prev_from']);
        $prevTo   = new DateTimeImmutable($this->window['prev_to']);
        $grain    = $this->window['grain'];

        $series = [];
        foreach ($offsets as $i => $offset) {
            $bucketFrom = $prevFrom->modify("+{$offset} days");
            $bucketTo   = isset($offsets[$i + 1])
                ? $prevFrom->modify('+' . ($offsets[$i + 1] - 1) . ' days')
                : $prevTo;

            $total  = 0.0;
            $cursor = $bucketFrom;
            while ($cursor <= $bucketTo && $cursor <= $prevTo) {
                $total += $daily[$cursor->format('Y-m-d')] ?? 0.0;
                $cursor = $cursor->modify('+1 day');
            }

            $series[] = [
                'bucket' => $bucketFrom->format('Y-m-d'),
                // Labelled with the dates it actually covers, so a tooltip can
                // name the previous period's days rather than borrowing this
                // period's label.
                'label'  => $grain === 'day'
                    ? $bucketFrom->format('j M Y')
                    : $bucketFrom->format('j M') . ' – ' . $bucketTo->format('j M Y'),
                'total'  => $total,
            ];
        }

        return $series;
    }

    // ─── Running a query ───────────────────────────────────────────────
    //
    // Every read goes through one of these three. They are not an abstraction
    // over PDO so much as a single place where a report's SQL is prepared and
    // executed, which is what makes it possible to say with confidence that
    // no report in this module builds a statement by concatenating a value.

    /**
     * The subset of $params the statement actually mentions.
     *
     * The connection runs with emulated prepares — it has to, so a named
     * placeholder can be repeated, which several of the access scopes rely on
     * — and emulation is strict the other way: handing execute() a parameter
     * the SQL never names is an HY093 error, not a harmless extra.
     *
     * That bites because periodParams() offers three values and not every
     * query wants all three. The rent-ledger series buckets on due_date and
     * has no use for :w_today, and passing it threw the whole report.
     * Filtering here rather than trimming at each call site means the next
     * query to want two of the three cannot reintroduce the bug.
     *
     * The trailing lookahead matters: without it, ':w_to' matches inside
     * ':w_today' and the filter keeps a parameter the statement does not
     * have. It is written as a negated character class rather than \b so the
     * expression contains no backslash escape — this line has already been
     * mangled once in transit, and a corrupted boundary here silently drops
     * every bound parameter and takes the whole report down with a syntax
     * error from the database.
     */
    private function usedParams(string $sql, array $params): array
    {
        $out = [];
        foreach ($params as $name => $value) {
            if (preg_match('/' . preg_quote((string) $name, '/') . '(?![A-Za-z0-9_])/', $sql) === 1) {
                $out[$name] = $value;
            }
        }

        return $out;
    }

    /** @return array<int,array<string,mixed>> */
    protected function rows(string $sql, array $params = []): array
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($this->usedParams($sql, $params));

        return $stmt->fetchAll();
    }

    /** @return array<string,mixed> */
    protected function row(string $sql, array $params = []): array
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($this->usedParams($sql, $params));

        return $stmt->fetch() ?: [];
    }

    protected function value(string $sql, array $params = []): mixed
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($this->usedParams($sql, $params));

        return $stmt->fetchColumn();
    }

    protected function amount(string $sql, array $params = []): float
    {
        return (float) $this->value($sql, $params);
    }

    protected function count(string $sql, array $params = []): int
    {
        return (int) $this->value($sql, $params);
    }

    // ─── Series ────────────────────────────────────────────────────────

    /**
     * A bucketed series, filled in.
     *
     * SQL returns only the buckets that have rows in them, which draws a
     * chart that skips the quiet weeks and reads as though they never
     * happened. This walks the window at its own grain and puts a zero
     * everywhere the query returned nothing, so a flat stretch looks flat
     * rather than compressed.
     *
     * $from and $to default to the current window, and are passed explicitly
     * when filling the *previous* period's series — the comparison walks its
     * own dates at the same grain, so the two come back with matching bucket
     * counts and can be aligned by position.
     *
     * @param array<int,array<string,mixed>> $rows rows of [bucket, total]
     * @return array<int,array{bucket:string,label:string,total:float}>
     */
    protected function fillSeries(
        array $rows,
        string $bucketKey = 'bucket',
        string $valueKey = 'total',
        ?string $from = null,
        ?string $to = null
    ): array {
        $found = [];
        foreach ($rows as $r) {
            $found[(string) $r[$bucketKey]] = (float) $r[$valueKey];
        }

        $grain = $this->window['grain'];
        $step  = ['day' => '+1 day', 'week' => '+1 week', 'month' => '+1 month', 'quarter' => '+3 months'][$grain] ?? '+1 month';

        // Snapped to the start of its own bucket before stepping, and that
        // detail is load-bearing. A six-month window beginning on 24 February
        // and stepping a calendar month at a time walks 24 Feb, 24 Mar … 24
        // Jul, and then 24 Aug — which is past a window ending on the 23rd, so
        // the loop stops and August never appears at all. The current month
        // silently vanished from the chart. Starting each cursor at the first
        // of the month, the Monday of the week, or the first day of the
        // quarter is what makes the last bucket land inside the window.
        $cursor = $this->bucketStart(new DateTimeImmutable($from ?? $this->window['from']), $grain);
        $end    = new DateTimeImmutable($to ?? $this->window['to_capped']);
        $series = [];
        $seen   = [];

        // Bounded by construction: the window is capped at five years and the
        // coarsest grain still advances, so this cannot run away.
        while ($cursor <= $end) {
            $key = $this->bucketKeyFor($cursor, $grain);
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $series[] = [
                    'bucket' => $key,
                    'label'  => reportGrainLabel($grain, $key),
                    'total'  => $found[$key] ?? 0.0,
                ];
            }
            $cursor = $cursor->modify($step);
        }

        return $series;
    }

    /**
     * The start date of every bucket in a window, in order.
     *
     * The same walk fillSeries() makes, exposed so a comparison series can be
     * folded onto the *current* period's bucket boundaries instead of its own
     * calendar. That distinction is not academic: a 54-day quarter beginning
     * on a Wednesday spans eight ISO weeks, and the equally long window before
     * it spans nine. Bucketing each by its own calendar produces series of
     * different lengths, and the only ways to draw them together are to drop a
     * bucket or to stretch one — the first hides real money, the second
     * invents a shape. Folding the previous period onto this one's offsets
     * gives day one against day one, which is what the comparison claims.
     *
     * @return DateTimeImmutable[]
     */
    protected function bucketStarts(?string $from = null, ?string $to = null): array
    {
        $grain  = $this->window['grain'];
        $step   = ['day' => '+1 day', 'week' => '+1 week', 'month' => '+1 month', 'quarter' => '+3 months'][$grain] ?? '+1 month';
        $cursor = $this->bucketStart(new DateTimeImmutable($from ?? $this->window['from']), $grain);
        $end    = new DateTimeImmutable($to ?? $this->window['to_capped']);

        $out = [];
        while ($cursor <= $end) {
            $out[] = $cursor;
            $cursor = $cursor->modify($step);
        }

        return $out;
    }

    /**
     * The first moment of the bucket a date falls in.
     *
     * The mirror of the grain steps used above: whatever advances the cursor
     * by one bucket has to be matched by something that puts it at the start
     * of one, or the walk drifts off the calendar it is supposed to describe.
     */
    private function bucketStart(DateTimeImmutable $d, string $grain): DateTimeImmutable
    {
        switch ($grain) {
            case 'week':
                return $d->modify('monday this week');

            case 'month':
                return $d->modify('first day of this month');

            case 'quarter':
                $startMonth = (intdiv((int) $d->format('n') - 1, 3) * 3) + 1;
                return $d->setDate((int) $d->format('Y'), $startMonth, 1);
        }

        return $d;
    }

    /**
     * The bucket key PHP must produce to match what MySQL produced.
     *
     * These two formats have to agree exactly or the fill above silently
     * zeroes every real bucket, so they are written next to each other:
     * this is the mirror of reportGrainExpression().
     */
    private function bucketKeyFor(DateTimeImmutable $d, string $grain): string
    {
        switch ($grain) {
            case 'day':     return $d->format('Y-m-d');
            case 'week':    return $d->format('o-\WW');   // ISO year + ISO week, as %x-W%v
            case 'quarter': return $d->format('Y') . '-Q' . (string) (intdiv((int) $d->format('n') - 1, 3) + 1);
        }

        return $d->format('Y-m');
    }
}
