<?php
/**
 * CoreAnalytics — the six business definitions, as queries.
 *
 * One method per approved decision from the Phase 0 audit. Nothing else in
 * the reporting module is permitted to answer these questions its own way;
 * where a later report needs occupancy or collected revenue it calls in here,
 * so there is exactly one place where each definition can be read, argued
 * with, or corrected.
 *
 * Each method carries the decision it implements and, where the audit found
 * the old page doing something different, what it used to do and why that was
 * wrong. That history is the point: the figures on this page changed, and
 * anyone comparing a printout from last week with one from today deserves to
 * find out here why they disagree.
 */
require_once __DIR__ . '/Analytics.php';

class CoreAnalytics extends Analytics
{
    // ─── Shared predicates ─────────────────────────────────────────────
    //
    // Every one of these was, until Phase 10, written out again in each
    // method that needed it -- six copies of the live-lease test alone,
    // identical but for the subquery alias. Drill-down is what made that
    // untenable: a panel claiming to list "the 3 occupied properties" has to
    // select the rows the tile counted, and the only way to guarantee that is
    // for both to ask the same question through the same code.
    //
    // `$tag` keeps the subquery aliases distinct so these can appear more
    // than once in a statement, and nest, without colliding.

    /**
     * The three commercial states, as SQL a property row can be tested with.
     *
     * All three are proved by a record -- a live lease, an unexpired hold, a
     * completed sale -- rather than by properties.status, which approved
     * decision 5 established is not maintained.
     *
     * @return array{leased:string,held:string,sold:string}
     */
    private function commercialState(string $tag, string $p = 'p'): array
    {
        return [
            'leased' => "EXISTS (SELECT 1 FROM leases {$tag}_l
                                 WHERE {$tag}_l.property_id = {$p}.id
                                   AND {$tag}_l.status = 'active'
                                   AND {$tag}_l.end_date >= :w_today)",
            'held'   => "EXISTS (SELECT 1 FROM reservations {$tag}_r
                                 WHERE {$tag}_r.property_id = {$p}.id
                                   AND {$tag}_r.status IN ('active','confirmed')
                                   AND {$tag}_r.expiry_date >= :w_today)",
            'sold'   => "EXISTS (SELECT 1 FROM sales {$tag}_s
                                 WHERE {$tag}_s.property_id = {$p}.id AND {$tag}_s.status = 'completed')",
        ];
    }

    /**
     * The rentable universe -- approved decision 1's denominator, as SQL.
     *
     * Not archived, approved, lettable by type, not withdrawn, and not
     * already sold. The drill-down behind the occupancy tile selects from
     * exactly this, which is why the list it shows can never be longer or
     * shorter than the figure it was opened from.
     */
    private function rentableWhere(string $tag, string $p = 'p'): string
    {
        $state = $this->commercialState($tag, $p);

        return "{$p}.is_archived = 0
              AND {$p}.approval_status = 'approved'
              AND {$p}.property_type IN ('rent', 'both')
              AND {$p}.status <> 'inactive'
              AND NOT {$state['sold']}";
    }

    // ─── Decision 1 · Occupancy ────────────────────────────────────────

    /**
     * Occupancy, derived from leases.
     *
     * The old page computed `properties.status = 'rented'` ÷ every property on
     * the books and reported 5.9%. Both halves were wrong. The denominator
     * counted ten sale-only listings and one sold unit as rentable stock, and
     * the numerator trusted `properties.status`, which the audit proved is not
     * written when a lease is signed — two of the three occupied properties
     * were still recorded as available. The true figure was 50%.
     *
     * So the rentable universe comes from `properties` and occupancy comes
     * from `leases`, which is approved decision 1:
     *
     *   rentable   not archived, approved, lettable by type, not withdrawn,
     *              and not already sold under a completed sale
     *   occupied   a rentable property carrying a live lease
     *   vacant     the remainder
     *
     * `status <> 'inactive'` is the one use of properties.status here, and it
     * is a legitimate one: inactive is a lifecycle state somebody sets
     * deliberately to withdraw a listing, not a commercial state derived from
     * another table (approved decision 5).
     *
     * A "live" lease is `status = 'active'` and not past its end date. The
     * second half guards against the failure mode the audit exposed
     * elsewhere — a status that nothing rolls forward — without inventing a
     * rule about leases that have not started yet. `active_any` is returned
     * alongside so the two can be compared: if they ever diverge, leases are
     * being left active past their end and that is worth knowing.
     *
     * @return array<string,mixed>
     */
    public function occupancy(): array
    {
        [$scope, $scopeParams]   = $this->scope('property', 'p');
        [$filterSql, $filterParams] = $this->propertyFilters('p');

        $liveLease = $this->commercialState('oc')['leased'];
        $anyActive = "EXISTS (SELECT 1 FROM leases oc_a
                              WHERE oc_a.property_id = p.id AND oc_a.status = 'active')";

        $row = $this->row("
            SELECT COUNT(*) AS rentable,
                   COALESCE(SUM({$liveLease}), 0) AS occupied,
                   COALESCE(SUM({$anyActive}), 0) AS active_any
            FROM properties p
            WHERE {$this->rentableWhere('oc')}
              AND ({$scope})
              {$filterSql}
        ", $scopeParams + $filterParams + [':w_today' => $this->window['today']]);

        $rentable = (int) ($row['rentable'] ?? 0);
        $occupied = (int) ($row['occupied'] ?? 0);

        return [
            'rentable'   => $rentable,
            'occupied'   => $occupied,
            'vacant'     => max(0, $rentable - $occupied),
            'active_any' => (int) ($row['active_any'] ?? 0),
            'rate'       => reportShare((float) $occupied, (float) $rentable),
        ];
    }

    // ─── Decision 2 · Collected revenue ────────────────────────────────

    /**
     * Money actually received inside the window.
     *
     * Three rules, all of which the old query was missing:
     *
     *   status = 'paid'          settled, and therefore not cancelled
     *   payment_date <= today    the audit found a $500 payment dated 24 days
     *                            into the future being reported as taken
     *   payment_type in the      a deposit is a liability held against a
     *   revenue set              tenancy and a refund is money going out;
     *                            neither is earnings. Both are reported by
     *                            depositsAndRefunds() instead.
     *
     * Removing the future-dated row alone moved the year-to-date figure from
     * $4,700 to $4,200.
     */
    public function collectedRevenue(bool $previous = false): float
    {
        [$sql, $params] = $this->revenueQuery(null, $previous);

        return $this->amount($sql, $params);
    }

    /**
     * Collected revenue split by the contract it was taken against.
     *
     * Approved decision 3: `reference_type` is authoritative. It is written by
     * the code that creates the payment and names the contract the money
     * belongs to; `payment_type` is a label chosen on a form, and the audit
     * found a row where the two disagree — enough to move the split by $600
     * depending on which you believe. Whichever way that row is eventually
     * corrected, the count of such rows is surfaced separately by
     * reportPaymentMismatches() rather than being quietly absorbed here.
     *
     * 'reservation' is kept as its own line and is deliberately not folded
     * into sales: a holding deposit on a property nobody has yet bought is
     * not a sale, and calling it one would overstate the sales book.
     *
     * @return array<string,float>
     */
    public function revenueByStream(bool $previous = false): array
    {
        $streams = [
            'rental'      => REPORT_STREAM_RENTAL,
            'sale'        => REPORT_STREAM_SALE,
            'reservation' => 'reservation',
        ];

        $out   = [];
        $total = 0.0;
        foreach ($streams as $name => $referenceType) {
            [$sql, $params] = $this->revenueQuery($referenceType, $previous);
            $out[$name] = $this->amount($sql, $params);
            $total += $out[$name];
        }

        $out['total'] = $total;

        return $out;
    }

    /**
     * Collected revenue over time, bucketed at the window's grain.
     *
     * With $previous the same series is drawn for the comparison window. Both
     * are filled at the same grain over windows of equal length, so the two
     * come back with matching bucket counts and the chart can align them by
     * position — day one against day one — rather than by calendar label,
     * which would set 1 August against 1 July and call it a comparison.
     *
     * @return array<int,array{bucket:string,label:string,total:float}>
     */
    public function revenueSeries(?string $referenceType = null, bool $previous = false): array
    {
        [$scope, $scopeParams]      = $this->scope('payment', 'py', 'p');
        [$filterSql, $filterParams] = $this->propertyFilters('p');

        $streamSql = '';
        $params    = $scopeParams + $filterParams + $this->periodParams($previous);
        if ($referenceType !== null) {
            $streamSql = " AND py.reference_type = :f_stream";
            $params[':f_stream'] = $referenceType;
        }

        $bucket = $this->bucket('py.payment_date');
        $types  = $this->revenueTypeList();

        $rows = $this->rows("
            SELECT {$bucket} AS bucket, COALESCE(SUM(py.amount), 0) AS total
            FROM payments py
            LEFT JOIN properties p ON py.property_id = p.id
            WHERE py.status = 'paid'
              AND py.payment_date IS NOT NULL
              AND py.payment_date <= :w_today
              AND {$this->withinPeriod('py.payment_date')}
              AND py.payment_type IN ({$types})
              AND ({$scope})
              {$streamSql}
              {$filterSql}
            GROUP BY bucket
            ORDER BY bucket
        ", $params);

        return $previous
            ? $this->fillSeries($rows, 'bucket', 'total', $this->window['prev_from'], $this->window['prev_to'])
            : $this->fillSeries($rows);
    }

    /**
     * The previous period's revenue, folded onto this period's buckets.
     *
     * Not simply revenueSeries(previous: true). That buckets the comparison
     * window by its own calendar, and two windows of identical length can
     * still span a different number of weeks or months depending on which day
     * they start — a 54-day quarter beginning on a Wednesday covers eight ISO
     * weeks, the 54 days before it cover nine. Drawing those together means
     * dropping a bucket or stretching one, and both lie about the comparison.
     *
     * So the previous period is summed by *offset*: this period's bucket
     * boundaries are measured as days from its own start, and the same
     * offsets are applied to the previous window. Bucket three is "days 15 to
     * 21 of the period" in both series, which is what "day one against day
     * one" actually requires and what makes the two arrays the same length by
     * construction rather than by trimming.
     *
     * @return array<int,array{bucket:string,label:string,total:float}>
     */
    public function revenueComparisonSeries(): array
    {
        [$scope, $scopeParams]      = $this->scope('payment', 'py', 'p');
        [$filterSql, $filterParams] = $this->propertyFilters('p');
        $types = $this->revenueTypeList();

        // Daily totals, because the folding below decides the buckets.
        $rows = $this->rows("
            SELECT DATE(py.payment_date) AS d, COALESCE(SUM(py.amount), 0) AS total
            FROM payments py
            LEFT JOIN properties p ON py.property_id = p.id
            WHERE py.status = 'paid'
              AND py.payment_date IS NOT NULL
              AND py.payment_date <= :w_today
              AND {$this->withinPeriod('py.payment_date')}
              AND py.payment_type IN ({$types})
              AND ({$scope})
              {$filterSql}
            GROUP BY d
        ", $scopeParams + $filterParams + $this->periodParams(true));

        $daily = [];
        foreach ($rows as $r) {
            $daily[(string) $r['d']] = (float) $r['total'];
        }

        // The folding itself lives in Analytics::foldOntoCurrentBuckets(),
        // because payment activity needs the identical trick and two copies
        // of it would eventually disagree. Behaviour is unchanged.
        return $this->foldOntoCurrentBuckets($daily);
    }

    /**
     * Deposits held and refunds paid out — reported, never added to revenue.
     *
     * Approved decision 2 excludes both from collected operating revenue.
     * Excluding them silently would be its own kind of dishonesty, so they
     * are counted here under their own names and can be shown as a separate,
     * explicitly labelled figure.
     *
     * @return array<string,float>
     */
    public function depositsAndRefunds(bool $previous = false): array
    {
        [$scope, $scopeParams]      = $this->scope('payment', 'py', 'p');
        [$filterSql, $filterParams] = $this->propertyFilters('p');

        $row = $this->row("
            SELECT COALESCE(SUM(CASE WHEN py.payment_type = 'deposit' THEN py.amount END), 0) AS deposits,
                   COALESCE(SUM(CASE WHEN py.payment_type = 'refund'  THEN py.amount END), 0) AS refunds
            FROM payments py
            LEFT JOIN properties p ON py.property_id = p.id
            WHERE py.status = 'paid'
              AND py.payment_date IS NOT NULL
              AND py.payment_date <= :w_today
              AND {$this->withinPeriod('py.payment_date')}
              AND ({$scope})
              {$filterSql}
        ", $scopeParams + $filterParams + $this->periodParams($previous));

        return [
            'deposits' => (float) ($row['deposits'] ?? 0),
            'refunds'  => (float) ($row['refunds'] ?? 0),
        ];
    }

    // ─── Decision 4 · Arrears and the rent ledger ──────────────────────

    /**
     * Expected, collected, outstanding and arrears — four figures, two
     * ledgers, kept apart.
     *
     * `payment_schedules` is what rent was invoiced; `payments` is what was
     * received. Approved decision 4 keeps them separate and forbids adding
     * them into one total, because they are not two halves of a sum — they
     * are two accounts of the same money and the audit found them $600 apart.
     *
     * Two things here are deliberately not window-bounded:
     *
     *   arrears          a running balance. What is owed is what is owed; it
     *                    is not a figure that "happened" inside August.
     *   outstanding      likewise, everything still unsettled.
     *
     * The rest are, and which date they are bounded *by* is the subtle part.
     * A schedule row has two dates — when the rent fell due and when it was
     * settled — and a payment has one. Comparing an amount bounded by
     * due_date against one bounded by payment_date measures the calendar as
     * much as the books: August rent paid in September would show up as a
     * shortfall in August and a windfall in September, in a ledger that
     * actually balances. So the two questions are asked on their own axes:
     *
     *   collection_rate   settled ÷ expected, both on due_date. Of what fell
     *                     due in this period, how much has been paid.
     *   outstanding       everything not yet settled, as at today
     *   not_yet_due       the part of that which is not overdue — the
     *                     distinction the Financial report exists to make,
     *                     because "outstanding" and "in arrears" are read as
     *                     the same word by everyone except an accountant
     *   ledger_gap        cash received vs schedules marked settled, both on
     *                     the date the money moved. Anything but zero means
     *                     a payment was taken without its schedule row being
     *                     closed, or the reverse — which is the $600
     *                     discrepancy the audit found, not a timing effect.
     *
     * @return array<string,mixed>
     */
    public function rentLedger(bool $previous = false): array
    {
        [$leaseScope, $leaseParams] = $this->scope('lease', 'l', 'p');
        [$filterSql, $filterParams] = $this->propertyFilters('p');

        $schedule = $this->row("
            SELECT COALESCE(SUM(CASE WHEN {$this->withinPeriod('ps.due_date')}
                                     THEN ps.amount + ps.penalty END), 0) AS expected,
                   COALESCE(SUM(CASE WHEN ps.status = 'paid' AND {$this->withinPeriod('ps.due_date')}
                                     THEN ps.amount END), 0) AS settled,
                   COALESCE(SUM(CASE WHEN ps.status = 'paid'
                                      AND ps.paid_date IS NOT NULL
                                      AND ps.paid_date <= :w_today
                                      AND {$this->withinPeriod('ps.paid_date')}
                                     THEN ps.amount END), 0) AS settled_when_paid,
                   COALESCE(SUM(CASE WHEN ps.status IN ('overdue','partial')
                                     THEN ps.amount + ps.penalty END), 0) AS arrears,
                   COALESCE(SUM(ps.status = 'overdue'), 0) AS overdue_count,
                   COALESCE(SUM(CASE WHEN ps.status <> 'paid'
                                     THEN ps.amount + ps.penalty END), 0) AS outstanding,
                   COALESCE(SUM(ps.status <> 'paid'), 0) AS outstanding_count,
                   COALESCE(SUM(CASE WHEN ps.status = 'pending'
                                     THEN ps.amount + ps.penalty END), 0) AS not_yet_due,
                   COALESCE(SUM(ps.status = 'pending'), 0) AS not_yet_due_count
            FROM payment_schedules ps
            JOIN leases l     ON ps.lease_id = l.id
            JOIN properties p ON l.property_id = p.id
            WHERE ({$leaseScope})
              {$filterSql}
        ", $leaseParams + $filterParams + $this->periodParams($previous));

        [$sql, $params] = $this->revenueQuery(REPORT_STREAM_RENTAL, $previous);
        $collected = $this->amount($sql, $params);

        $expected    = (float) ($schedule['expected'] ?? 0);
        $settled     = (float) ($schedule['settled'] ?? 0);
        $settledPaid = (float) ($schedule['settled_when_paid'] ?? 0);

        return [
            'expected'          => $expected,
            'collected'         => $collected,
            'settled_on_ledger' => $settled,
            // Both sides measured on the date the money moved, so this is the
            // two accounts disagreeing rather than the calendar. Zero is the
            // only comfortable value; anything else means a payment was taken
            // without its schedule row being closed, or the reverse.
            'ledger_gap'        => $collected - $settledPaid,
            // Running balances, and therefore null for a past window. The
            // schedule records the state a row is in *now*, not the state it
            // was in in July — so "what was outstanding then" is not a
            // question this table can answer, and a comparison that quietly
            // repeated today's figure would be the worst possible answer to
            // it. The comparison panel prints "not available" instead.
            'outstanding'       => $previous ? null : (float) ($schedule['outstanding'] ?? 0),
            'outstanding_count' => $previous ? null : (int) ($schedule['outstanding_count'] ?? 0),
            'not_yet_due'       => $previous ? null : (float) ($schedule['not_yet_due'] ?? 0),
            'not_yet_due_count' => $previous ? null : (int) ($schedule['not_yet_due_count'] ?? 0),
            'arrears'           => $previous ? null : (float) ($schedule['arrears'] ?? 0),
            'overdue_count'     => $previous ? null : (int) ($schedule['overdue_count'] ?? 0),
            'collection_rate'   => reportShare($settled, $expected),
        ];
    }

    /**
     * The payments ledger's own overdue count.
     *
     * Kept in a separate method with a separate name on purpose. The audit
     * found the Reports page saying 7 overdue while the Payments register
     * said 0, because `payment_schedules.status` and `payments.status` are
     * two different ledgers and only the first is maintained. Approved
     * decision 4 forbids adding this into arrears. It is reported, where it
     * is reported at all, as what it is: the state of the payments table.
     *
     * @return array<string,mixed>
     */
    public function paymentLedgerOverdue(): array
    {
        [$scope, $scopeParams]      = $this->scope('payment', 'py', 'p');
        [$filterSql, $filterParams] = $this->propertyFilters('p');

        $row = $this->row("
            SELECT COUNT(*) AS cnt, COALESCE(SUM(py.amount + py.penalty_amount), 0) AS amount
            FROM payments py
            LEFT JOIN properties p ON py.property_id = p.id
            WHERE py.status = 'overdue' AND ({$scope}) {$filterSql}
        ", $scopeParams + $filterParams);

        return [
            'count'  => (int) ($row['cnt'] ?? 0),
            'amount' => (float) ($row['amount'] ?? 0),
        ];
    }

    /**
     * Expected and settled scheduled rent over time.
     *
     * Both series are bucketed on `due_date`, which is the whole point: the
     * question "how much of what fell due has been paid" is only answerable
     * when numerator and denominator sit on the same axis. Money received is
     * a different series on a different axis (revenueSeries, on payment_date)
     * and the two are never drawn as though they were one measurement.
     *
     * @return array{expected:array,settled:array}
     */
    public function rentLedgerSeries(bool $previous = false): array
    {
        [$leaseScope, $leaseParams] = $this->scope('lease', 'l', 'p');
        [$filterSql, $filterParams] = $this->propertyFilters('p');

        $bucket = $this->bucket('ps.due_date');

        $rows = $this->rows("
            SELECT {$bucket} AS bucket,
                   COALESCE(SUM(ps.amount + ps.penalty), 0) AS expected,
                   COALESCE(SUM(CASE WHEN ps.status = 'paid' THEN ps.amount END), 0) AS settled
            FROM payment_schedules ps
            JOIN leases l     ON ps.lease_id = l.id
            JOIN properties p ON l.property_id = p.id
            WHERE {$this->withinPeriod('ps.due_date')}
              AND ({$leaseScope})
              {$filterSql}
            GROUP BY bucket
            ORDER BY bucket
        ", $leaseParams + $filterParams + $this->periodParams($previous));

        $from = $previous ? $this->window['prev_from'] : null;
        $to   = $previous ? $this->window['prev_to'] : null;

        return [
            'expected' => $this->fillSeries($rows, 'bucket', 'expected', $from, $to),
            'settled'  => $this->fillSeries($rows, 'bucket', 'settled', $from, $to),
        ];
    }

    /**
     * The rent ledger, per property.
     *
     * Every column comes off `payment_schedules` on the due-date axis —
     * expected, settled, outstanding and arrears all from one table, so a row
     * cannot contradict itself the way a row mixing invoiced and received
     * money would. Collected cash per property is a different question and is
     * answered by topPropertiesByRevenue(); the two are deliberately not
     * merged into one table, because a reader would take adjacent columns to
     * be on the same footing and they are not.
     *
     * Only properties with something scheduled in the window appear. A
     * property with no rent due has no collection rate, and printing 0%
     * against it would say something false.
     *
     * @return array<int,array<string,mixed>>
     */
    public function rentLedgerByProperty(int $limit = 20): array
    {
        [$leaseScope, $leaseParams] = $this->scope('lease', 'l', 'p');
        [$filterSql, $filterParams] = $this->propertyFilters('p');

        // An integer this class chose, clamped. Never a request value.
        $limit = max(1, min(100, $limit));

        return $this->rows("
            SELECT p.id,
                   p.property_code,
                   p.title,
                   p.category,
                   COALESCE(SUM(CASE WHEN {$this->withinPeriod('ps.due_date')}
                                     THEN ps.amount + ps.penalty END), 0) AS expected,
                   COALESCE(SUM(CASE WHEN ps.status = 'paid' AND {$this->withinPeriod('ps.due_date')}
                                     THEN ps.amount END), 0) AS settled,
                   COALESCE(SUM(CASE WHEN ps.status <> 'paid'
                                     THEN ps.amount + ps.penalty END), 0) AS outstanding,
                   COALESCE(SUM(CASE WHEN ps.status IN ('overdue','partial')
                                     THEN ps.amount + ps.penalty END), 0) AS arrears,
                   COALESCE(SUM(ps.status = 'overdue'), 0) AS overdue_count
            FROM payment_schedules ps
            JOIN leases l     ON ps.lease_id = l.id
            JOIN properties p ON l.property_id = p.id
            WHERE ({$leaseScope})
              {$filterSql}
            GROUP BY p.id, p.property_code, p.title, p.category
            HAVING expected > 0
            ORDER BY expected DESC, p.property_code ASC
            LIMIT {$limit}
        ", $leaseParams + $filterParams + $this->periodParams());
    }

    /**
     * Payments marked paid but dated after today.
     *
     * Approved decision 2 keeps these out of collected revenue, and the audit
     * found one — $500 dated 24 days ahead — which the old page was reporting
     * as money in the bank. Excluding it silently would be its own kind of
     * dishonesty: a reader comparing this report against the payments
     * register would find a gap and no explanation for it. So the exclusion
     * is counted and shown.
     *
     * @return array{count:int,amount:float}
     */
    public function futureDatedExcluded(): array
    {
        [$scope, $scopeParams]      = $this->scope('payment', 'py', 'p');
        [$filterSql, $filterParams] = $this->propertyFilters('p');
        $types = $this->revenueTypeList();

        $row = $this->row("
            SELECT COUNT(*) AS cnt, COALESCE(SUM(py.amount), 0) AS amount
            FROM payments py
            LEFT JOIN properties p ON py.property_id = p.id
            WHERE py.status = 'paid'
              AND py.payment_date IS NOT NULL
              AND py.payment_date > :w_today
              AND py.payment_type IN ({$types})
              AND ({$scope})
              {$filterSql}
        ", $scopeParams + $filterParams + [':w_today' => $this->window['today']]);

        return [
            'count'  => (int) ($row['cnt'] ?? 0),
            'amount' => (float) ($row['amount'] ?? 0),
        ];
    }

    // ─── Decision 5 · Inventory and commercial state ───────────────────

    /**
     * The portfolio, counted twice over: what state a listing is in
     * administratively, and what is commercially true of it.
     *
     * Approved decision 5 separates the two because one column was being
     * asked to carry both, and could not. The lifecycle half is
     * `properties`' own business — archived, awaiting approval, withdrawn —
     * and reading it from `properties` is correct. The commercial half is
     * derived from the records that actually prove it: a lease for occupied,
     * a live reservation for held, a completed sale for sold.
     *
     * The two halves will not add up to the same total, and that is the
     * finding rather than a fault: `reportPropertyStateIssues()` counts
     * exactly where they disagree.
     *
     * @return array<string,mixed>
     */
    public function inventory(): array
    {
        [$scope, $scopeParams]      = $this->scope('property', 'p');
        [$filterSql, $filterParams] = $this->propertyFilters('p');

        ['leased' => $liveLease, 'held' => $heldNow, 'sold' => $soldOff] = $this->commercialState('iv');

        $row = $this->row("
            SELECT COUNT(*) AS total,
                   COALESCE(SUM(p.is_archived = 1), 0) AS archived,
                   COALESCE(SUM(p.is_archived = 0 AND p.approval_status = 'pending'), 0) AS pending_approval,
                   COALESCE(SUM(p.is_archived = 0 AND p.approval_status = 'rejected'), 0) AS rejected,
                   COALESCE(SUM(p.is_archived = 0 AND p.approval_status = 'approved'), 0) AS active_listings,
                   COALESCE(SUM(p.is_archived = 0 AND p.status = 'inactive'), 0) AS withdrawn,
                   COALESCE(SUM(p.is_archived = 0 AND {$soldOff}), 0) AS sold,
                   COALESCE(SUM(p.is_archived = 0 AND NOT {$soldOff} AND {$liveLease}), 0) AS occupied,
                   COALESCE(SUM(p.is_archived = 0 AND NOT {$soldOff} AND NOT {$liveLease} AND {$heldNow}), 0) AS reserved
            FROM properties p
            WHERE ({$scope})
              {$filterSql}
        ", $scopeParams + $filterParams + [':w_today' => $this->window['today']]);

        $occupancy = $this->occupancy();

        return [
            'lifecycle' => [
                'total'            => (int) ($row['total'] ?? 0),
                'active_listings'  => (int) ($row['active_listings'] ?? 0),
                'pending_approval' => (int) ($row['pending_approval'] ?? 0),
                'rejected'         => (int) ($row['rejected'] ?? 0),
                'archived'         => (int) ($row['archived'] ?? 0),
                'withdrawn'        => (int) ($row['withdrawn'] ?? 0),
            ],
            'commercial' => [
                'occupied'  => (int) ($row['occupied'] ?? 0),
                'reserved'  => (int) ($row['reserved'] ?? 0),
                'sold'      => (int) ($row['sold'] ?? 0),
                'vacant'    => $occupancy['vacant'],
                'rentable'  => $occupancy['rentable'],
            ],
            'occupancy' => $occupancy,
        ];
    }

    /**
     * The register's own account of itself, by recorded status.
     *
     * This is `properties.status` read at face value, and it is the one place
     * in this class where that is the right thing to do — the question being
     * asked is literally "what does the register say", which is what the
     * portfolio doughnut draws. It is emphatically not where occupancy comes
     * from: approved decision 1 sends that to leases precisely because this
     * column is not maintained, and the distance between the two answers is
     * counted by reportPropertyStateIssues().
     *
     * @return array<int,array{status:string,c:int}>
     */
    public function recordedStatusBreakdown(): array
    {
        [$scope, $scopeParams]      = $this->scope('property', 'p');
        [$filterSql, $filterParams] = $this->propertyFilters('p');

        return $this->rows("
            SELECT p.status, COUNT(*) AS c
            FROM properties p
            WHERE p.is_archived = 0 AND ({$scope}) {$filterSql}
            GROUP BY p.status
            ORDER BY c DESC, p.status ASC
        ", $scopeParams + $filterParams);
    }

    // ─── Decision 6 · Agent performance ────────────────────────────────

    /**
     * What each agent actually did, as separate measures.
     *
     * Approved decision 6: no composite score. A single number would have to
     * weight a completed sale against a signed tenancy against rent collected,
     * and nobody has decided what those weights are — inventing them here
     * would be a business decision smuggled in as arithmetic.
     *
     * The old leaderboard counted `leases.created_by` within a fixed 30 days
     * and rendered five agents with a dash in every cell, because every lease
     * in the system was written by an administrator and all of them were
     * older than a month. Each measure below therefore names its own source:
     *
     *   properties_managed  properties.agent_id      — the assignment
     *   active_leases       leases on their property — the book they carry
     *   leases_created      leases.created_by        — paperwork they wrote
     *   sales_completed     sales.agent_id           — deals closed
     *   rental_revenue      payments via their property, reference_type lease
     *   sales_revenue       payments via their property, reference_type sale
     *   revenue_received    payments.received_by     — money they took in
     *   commission_pending  commissions.agent_id     — their own ledger
     *
     * The last two revenue columns are different questions and will not
     * agree. `rental_revenue` is what the agent's own listings earned;
     * `revenue_received` is what passed through their hands, including rent
     * on a colleague's property they happened to take at the counter. On the
     * current data agent 7 shows $0 of the first and $1,200 of the second,
     * which is not an error in either — it is what happens when eight of the
     * nine payments on file sit on properties with no agent assigned at all.
     * unattributedRevenue() counts that directly, because a leaderboard of
     * zeroes is worth nothing without the reason beside it.
     *
     * `properties_managed` and `active_leases` are as-at-now; everything else
     * is bounded by the window. That mixture is deliberate — a book is a
     * standing quantity and a sale is an event — and each column has to be
     * labelled accordingly wherever it is drawn.
     *
     * An agent reading this sees one row: their own. That is enforced here as
     * well as in reportAgentOptions(), because a leaderboard is the one report
     * where a missing scope leaks colleagues' earnings.
     *
     * @return array<int,array<string,mixed>>
     */
    public function agentPerformance(): array
    {
        $params = $this->periodParams() + [':ap_role' => ROLE_AGENT];

        $selfOnly = '';
        if (getUserRole() === ROLE_AGENT) {
            $selfOnly = " AND u.id = :ap_self";
            $params[':ap_self'] = recordScopeUserId();
        }

        $types = $this->revenueTypeList();

        // Correlated subqueries rather than seven joins: each is a keyed
        // lookup on an indexed column, the outer set is the agent roster
        // rather than a table of any size, and the alternative is a single
        // statement whose GROUP BY has to be right about seven different
        // grains at once.
        return $this->rows("
            SELECT u.id, u.full_name, u.avatar,

                   (SELECT COUNT(*) FROM properties ap
                     WHERE ap.agent_id = u.id AND ap.is_archived = 0) AS properties_managed,

                   (SELECT COUNT(*) FROM leases al
                      JOIN properties alp ON al.property_id = alp.id
                     WHERE alp.agent_id = u.id
                       AND al.status = 'active'
                       AND al.end_date >= :w_today) AS active_leases,

                   (SELECT COUNT(*) FROM leases cl
                     WHERE cl.created_by = u.id
                       AND DATE(cl.created_at) BETWEEN :w_from AND :w_to) AS leases_created,

                   (SELECT COUNT(*) FROM sales cs
                     WHERE cs.agent_id = u.id AND cs.status = 'completed'
                       AND cs.sale_date BETWEEN :w_from AND :w_to) AS sales_completed,

                   (SELECT COALESCE(SUM(cs.sale_amount), 0) FROM sales cs
                     WHERE cs.agent_id = u.id AND cs.status = 'completed'
                       AND cs.sale_date BETWEEN :w_from AND :w_to) AS sales_value,

                   (SELECT COALESCE(SUM(rp.amount), 0) FROM payments rp
                      JOIN properties rpp ON rp.property_id = rpp.id
                     WHERE rpp.agent_id = u.id
                       AND rp.status = 'paid'
                       AND rp.reference_type = 'lease'
                       AND rp.payment_type IN ({$types})
                       AND rp.payment_date IS NOT NULL
                       AND rp.payment_date <= :w_today
                       AND rp.payment_date BETWEEN :w_from AND :w_to) AS rental_revenue,

                   (SELECT COALESCE(SUM(sp.amount), 0) FROM payments sp
                      JOIN properties spp ON sp.property_id = spp.id
                     WHERE spp.agent_id = u.id
                       AND sp.status = 'paid'
                       AND sp.reference_type = 'sale'
                       AND sp.payment_type IN ({$types})
                       AND sp.payment_date IS NOT NULL
                       AND sp.payment_date <= :w_today
                       AND sp.payment_date BETWEEN :w_from AND :w_to) AS sales_revenue,

                   (SELECT COALESCE(SUM(vp.amount), 0) FROM payments vp
                     WHERE vp.received_by = u.id
                       AND vp.status = 'paid'
                       AND vp.payment_type IN ({$types})
                       AND vp.payment_date IS NOT NULL
                       AND vp.payment_date <= :w_today
                       AND vp.payment_date BETWEEN :w_from AND :w_to) AS revenue_received,

                   (SELECT COALESCE(SUM(cm.amount), 0) FROM commissions cm
                     WHERE cm.agent_id = u.id AND cm.status = 'pending') AS commission_pending

            FROM users u
            JOIN roles r ON u.role_id = r.id
            WHERE r.name = :ap_role AND u.is_active = 1
              {$selfOnly}
            ORDER BY u.full_name
        ", $params);
    }

    /**
     * Collected revenue that belongs to no agent.
     *
     * Not a business figure — a diagnostic, and the one that explains why the
     * agent leaderboard looks emptier than the desk actually is. A payment on
     * a property with no `agent_id` cannot be attributed to anybody, so it is
     * absent from every agent's row while still being counted in the
     * company's total. Any gap between the two is this.
     *
     * Window-bounded, which is why it lives here rather than alongside the
     * other data-quality checks in includes/reporting.php: those describe the
     * database, this describes the period being reported on.
     *
     * @return array{amount:float,count:int,share:?float}
     */
    public function unattributedRevenue(): array
    {
        [$scope, $scopeParams]      = $this->scope('payment', 'py', 'p');
        [$filterSql, $filterParams] = $this->propertyFilters('p');
        $types = $this->revenueTypeList();

        $row = $this->row("
            SELECT COUNT(*) AS cnt, COALESCE(SUM(py.amount), 0) AS amount
            FROM payments py
            LEFT JOIN properties p ON py.property_id = p.id
            WHERE py.status = 'paid'
              AND py.payment_date IS NOT NULL
              AND py.payment_date <= :w_today
              AND {$this->withinPeriod('py.payment_date')}
              AND py.payment_type IN ({$types})
              AND (py.property_id IS NULL OR p.agent_id IS NULL)
              AND ({$scope})
              {$filterSql}
        ", $scopeParams + $filterParams + $this->periodParams());

        $amount = (float) ($row['amount'] ?? 0);

        return [
            'amount' => $amount,
            'count'  => (int) ($row['cnt'] ?? 0),
            'share'  => reportShare($amount, $this->collectedRevenue()),
        ];
    }

    /**
     * The properties that brought in the most money this period.
     *
     * "Performance" is one transparent measure — collected eligible revenue,
     * the same definition the revenue KPI uses — because it is the only
     * per-property figure this schema supports without inventing weights. A
     * composite of revenue, occupancy and collection rate would need someone
     * to decide what each is worth, and nobody has.
     *
     * Only properties that actually took money appear. A ranked table padded
     * out to five rows with properties that earned nothing is not a ranking,
     * it is a list of properties with a number beside it.
     *
     * The agent column is the assignment, and where there is none it says so
     * rather than showing a blank that reads as an agent who did nothing —
     * eight of the nine payments on file sit on unassigned properties, so
     * this is the common case rather than the edge one.
     *
     * @return array<int,array<string,mixed>>
     */
    public function topPropertiesByRevenue(int $limit = 5): array
    {
        [$scope, $scopeParams]      = $this->scope('payment', 'py', 'p');
        [$filterSql, $filterParams] = $this->propertyFilters('p');
        $types = $this->revenueTypeList();

        // Clamped, and an integer this class chose. It never comes from the
        // request, which is why it can be interpolated at all.
        $limit = max(1, min(50, $limit));

        ['leased' => $liveLease, 'held' => $heldNow, 'sold' => $soldOff] = $this->commercialState('tp');

        return $this->rows("
            SELECT p.id,
                   p.property_code,
                   p.title,
                   p.category,
                   p.location,
                   p.agent_id,
                   u.full_name AS agent_name,
                   COUNT(*)          AS payments,
                   SUM(py.amount)    AS collected,
                   {$soldOff}        AS is_sold,
                   {$liveLease}      AS is_occupied,
                   {$heldNow}        AS is_reserved
            FROM payments py
            JOIN properties p ON py.property_id = p.id
            LEFT JOIN users u ON p.agent_id = u.id
            WHERE py.status = 'paid'
              AND py.payment_date IS NOT NULL
              AND py.payment_date <= :w_today
              AND {$this->withinPeriod('py.payment_date')}
              AND py.payment_type IN ({$types})
              AND ({$scope})
              {$filterSql}
            GROUP BY p.id, p.property_code, p.title, p.category, p.location,
                     p.agent_id, u.full_name
            HAVING collected > 0
            ORDER BY collected DESC, p.property_code ASC
            LIMIT {$limit}
        ", $scopeParams + $filterParams + $this->periodParams());
    }

    // ─── Maintenance · the work queue ──────────────────────────────────
    //
    // Two clocks again, and here they matter more than anywhere else. How
    // many requests came in during a period is a period figure. What is open
    // *now*, and how long it has been open, is current state — a backlog is
    // not something the database records the history of, so a backlog cannot
    // be compared with last month's.
    //
    // "Open" means a request nobody has closed: new, under review, assigned
    // or in progress. Completed, rejected and cancelled are all finished, and
    // only the first of those three is a resolution.

    /** The statuses that mean somebody still has work to do. */
    private const MAINTENANCE_OPEN = ['new', 'under_review', 'assigned', 'in_progress'];

    /** Every status the schema declares, in workflow order. */
    private const MAINTENANCE_STATUSES = [
        'new', 'under_review', 'assigned', 'in_progress', 'completed', 'rejected', 'cancelled',
    ];

    /**
     * Requests raised in the window, plus the open workload as it stands.
     *
     * The two halves are labelled apart in the return value because they
     * answer different questions and only the first responds to the date
     * picker.
     *
     * @return array<string,mixed>
     */
    public function maintenanceSummary(bool $previous = false): array
    {
        [$scope, $params]           = $this->scope('maintenance', 'm', 'p');
        [$filterSql, $filterParams] = $this->propertyFilters('p');
        $open = "'" . implode("','", self::MAINTENANCE_OPEN) . "'";

        // Period half: what came in and what was finished, on created_at and
        // completion_date respectively.
        $period = $this->row("
            SELECT COUNT(*) AS raised,
                   COALESCE(SUM(m.priority IN ('high','urgent')), 0) AS raised_urgent
            FROM maintenance_requests m
            JOIN properties p ON m.property_id = p.id
            WHERE {$this->withinPeriod('DATE(m.created_at)')}
              AND ({$scope}) {$filterSql}
        ", $params + $filterParams + $this->periodParams($previous));

        $completedInWindow = $this->row("
            SELECT COUNT(*) AS completed,
                   COALESCE(SUM(m.actual_cost), 0) AS cost
            FROM maintenance_requests m
            JOIN properties p ON m.property_id = p.id
            WHERE m.status = 'completed'
              AND m.completion_date IS NOT NULL
              AND m.completion_date <= :w_today
              AND {$this->withinPeriod('m.completion_date')}
              AND ({$scope}) {$filterSql}
        ", $params + $filterParams + $this->periodParams($previous));

        // Current half: the workload, unbounded by any window.
        $now = $this->row("
            SELECT COUNT(*) AS total,
                   COALESCE(SUM(m.status IN ({$open})), 0) AS open,
                   COALESCE(SUM(m.status = 'in_progress'), 0) AS in_progress,
                   COALESCE(SUM(m.status IN ('new','under_review')), 0) AS awaiting,
                   COALESCE(SUM(m.status = 'assigned'), 0) AS assigned,
                   COALESCE(SUM(m.status = 'completed'), 0) AS completed_ever,
                   COALESCE(SUM(m.status IN ({$open}) AND m.priority IN ('high','urgent')), 0) AS open_urgent,
                   COALESCE(SUM(m.status IN ({$open}) AND m.assigned_to IS NULL), 0) AS open_unassigned
            FROM maintenance_requests m
            JOIN properties p ON m.property_id = p.id
            WHERE ({$scope}) {$filterSql}
        ", $params + $filterParams);

        return [
            // Period
            'raised'           => (int) ($period['raised'] ?? 0),
            'raised_urgent'    => (int) ($period['raised_urgent'] ?? 0),
            'completed'        => (int) ($completedInWindow['completed'] ?? 0),
            'completed_cost'   => (float) ($completedInWindow['cost'] ?? 0),
            // Current state
            'total'            => (int) ($now['total'] ?? 0),
            'open'             => (int) ($now['open'] ?? 0),
            'awaiting'         => (int) ($now['awaiting'] ?? 0),
            'assigned'         => (int) ($now['assigned'] ?? 0),
            'in_progress'      => (int) ($now['in_progress'] ?? 0),
            'completed_ever'   => (int) ($now['completed_ever'] ?? 0),
            'open_urgent'      => (int) ($now['open_urgent'] ?? 0),
            'open_unassigned'  => (int) ($now['open_unassigned'] ?? 0),
        ];
    }

    /**
     * Every request by status, in workflow order, zeros kept.
     *
     * The zeros are the point on a workflow: three requests all sitting at
     * "assigned" with nothing before or after them is a queue that has
     * stalled, and a chart that dropped the empty stages would show one bar
     * and say nothing.
     *
     * Current state, not window-bounded — a status is what a request is now.
     *
     * @return array<int,array<string,mixed>>
     */
    public function maintenanceStatusBreakdown(): array
    {
        [$scope, $params]           = $this->scope('maintenance', 'm', 'p');
        [$filterSql, $filterParams] = $this->propertyFilters('p');

        $rows = $this->rows("
            SELECT m.status, COUNT(*) AS requests
            FROM maintenance_requests m
            JOIN properties p ON m.property_id = p.id
            WHERE ({$scope}) {$filterSql}
            GROUP BY m.status
        ", $params + $filterParams);

        $found = [];
        foreach ($rows as $r) {
            $found[(string) $r['status']] = (int) $r['requests'];
        }

        $tones = [
            'new' => '--info', 'under_review' => '--info', 'assigned' => '--warning',
            'in_progress' => '--primary', 'completed' => '--success',
            'rejected' => '--text-subtle', 'cancelled' => '--text-subtle',
        ];

        $out = [];
        foreach (self::MAINTENANCE_STATUSES as $status) {
            $out[] = [
                'status'   => $status,
                'label'    => uiLabel($status),
                'tone'     => $tones[$status] ?? '--primary',
                'requests' => $found[$status] ?? 0,
                'is_open'  => in_array($status, self::MAINTENANCE_OPEN, true),
            ];
        }

        return $out;
    }

    /**
     * Open requests by priority.
     *
     * Priority on a closed request is history; on an open one it is a
     * decision about what to do next, which is the only reason to chart it.
     * Every level the schema declares is returned, including the empty ones —
     * "no urgent work outstanding" is worth being able to see.
     *
     * @return array<int,array<string,mixed>>
     */
    public function maintenancePriorityBreakdown(): array
    {
        [$scope, $params]           = $this->scope('maintenance', 'm', 'p');
        [$filterSql, $filterParams] = $this->propertyFilters('p');
        $open = "'" . implode("','", self::MAINTENANCE_OPEN) . "'";

        $rows = $this->rows("
            SELECT m.priority, COUNT(*) AS requests
            FROM maintenance_requests m
            JOIN properties p ON m.property_id = p.id
            WHERE m.status IN ({$open}) AND ({$scope}) {$filterSql}
            GROUP BY m.priority
        ", $params + $filterParams);

        $found = [];
        foreach ($rows as $r) {
            $found[(string) $r['priority']] = (int) $r['requests'];
        }

        $levels = ['urgent' => '--danger', 'high' => '--orange', 'medium' => '--warning', 'low' => '--info'];

        $out = [];
        foreach ($levels as $level => $tone) {
            $out[] = [
                'priority' => $level,
                'label'    => uiLabel($level),
                'tone'     => $tone,
                'requests' => $found[$level] ?? 0,
            ];
        }

        return $out;
    }

    /**
     * How long the open requests have been waiting.
     *
     * Age is measured from `created_at` to today, which the schema fully
     * supports. It is emphatically *not* an SLA breach: this system records
     * no target response time and no due date for maintenance, so nothing
     * here is "overdue" — it is simply old, and how old is a decision for
     * whoever reads it.
     *
     * @return array<int,array<string,mixed>>
     */
    public function maintenanceAgeing(): array
    {
        [$scope, $params]           = $this->scope('maintenance', 'm', 'p');
        [$filterSql, $filterParams] = $this->propertyFilters('p');
        $open = "'" . implode("','", self::MAINTENANCE_OPEN) . "'";

        $age = "DATEDIFF(:w_today, DATE(m.created_at))";

        $row = $this->row("
            SELECT COALESCE(SUM({$age} BETWEEN 0 AND 3), 0)   AS d3,
                   COALESCE(SUM({$age} BETWEEN 4 AND 7), 0)   AS d7,
                   COALESCE(SUM({$age} BETWEEN 8 AND 14), 0)  AS d14,
                   COALESCE(SUM({$age} > 14), 0)              AS d15,
                   COALESCE(MAX({$age}), 0)                   AS oldest,
                   COALESCE(ROUND(AVG({$age}), 1), 0)         AS average
            FROM maintenance_requests m
            JOIN properties p ON m.property_id = p.id
            WHERE m.status IN ({$open}) AND ({$scope}) {$filterSql}
        ", $params + $filterParams + [':w_today' => $this->window['today']]);

        return [
            'buckets' => [
                ['key' => 'd3',  'label' => '0–3 days',    'requests' => (int) ($row['d3'] ?? 0),  'tone' => '--success'],
                ['key' => 'd7',  'label' => '4–7 days',    'requests' => (int) ($row['d7'] ?? 0),  'tone' => '--info'],
                ['key' => 'd14', 'label' => '8–14 days',   'requests' => (int) ($row['d14'] ?? 0), 'tone' => '--warning'],
                ['key' => 'd15', 'label' => '15+ days',    'requests' => (int) ($row['d15'] ?? 0), 'tone' => '--danger'],
            ],
            'oldest'  => (int) ($row['oldest'] ?? 0),
            'average' => (float) ($row['average'] ?? 0),
        ];
    }

    /**
     * Requests raised and completed over time.
     *
     * Raised is dated by `created_at`, completed by `completion_date` — two
     * different events on two different columns, drawn together because the
     * gap between them is the backlog forming or clearing.
     *
     * @return array{raised:array,completed:array}
     */
    public function maintenanceSeries(): array
    {
        [$scope, $params]           = $this->scope('maintenance', 'm', 'p');
        [$filterSql, $filterParams] = $this->propertyFilters('p');

        $raisedBucket = $this->bucket('DATE(m.created_at)');
        $raised = $this->rows("
            SELECT {$raisedBucket} AS bucket, COUNT(*) AS n
            FROM maintenance_requests m
            JOIN properties p ON m.property_id = p.id
            WHERE {$this->withinPeriod('DATE(m.created_at)')}
              AND ({$scope}) {$filterSql}
            GROUP BY bucket ORDER BY bucket
        ", $params + $filterParams + $this->periodParams());

        $doneBucket = $this->bucket('m.completion_date');
        $done = $this->rows("
            SELECT {$doneBucket} AS bucket, COUNT(*) AS n
            FROM maintenance_requests m
            JOIN properties p ON m.property_id = p.id
            WHERE m.status = 'completed'
              AND m.completion_date IS NOT NULL
              AND m.completion_date <= :w_today
              AND {$this->withinPeriod('m.completion_date')}
              AND ({$scope}) {$filterSql}
            GROUP BY bucket ORDER BY bucket
        ", $params + $filterParams + $this->periodParams());

        return [
            'raised'    => $this->fillSeries($raised, 'bucket', 'n'),
            'completed' => $this->fillSeries($done, 'bucket', 'n'),
        ];
    }

    /**
     * Resolution time, or an honest refusal.
     *
     * Measured from `created_at` to `completion_date`, which is the only pair
     * of columns in this table that means what it needs to mean. `updated_at`
     * is deliberately not used: it moves on any edit, so a request touched
     * yesterday would report as resolved yesterday whatever actually
     * happened.
     *
     * `available` is false when no completed request carries a completion
     * date, and every caller must respect it. On the current data that is
     * every request — nothing has ever been completed — so the report shows
     * "not available" rather than a fabricated average.
     *
     * @return array<string,mixed>
     */
    public function maintenanceResolution(): array
    {
        [$scope, $params]           = $this->scope('maintenance', 'm', 'p');
        [$filterSql, $filterParams] = $this->propertyFilters('p');

        $days = "DATEDIFF(m.completion_date, DATE(m.created_at))";

        $row = $this->row("
            SELECT COUNT(*) AS resolved,
                   COALESCE(ROUND(AVG({$days}), 1), 0) AS average,
                   COALESCE(MIN({$days}), 0) AS fastest,
                   COALESCE(MAX({$days}), 0) AS slowest
            FROM maintenance_requests m
            JOIN properties p ON m.property_id = p.id
            WHERE m.status = 'completed'
              AND m.completion_date IS NOT NULL
              AND m.completion_date >= DATE(m.created_at)
              AND ({$scope}) {$filterSql}
        ", $params + $filterParams);

        $resolved = (int) ($row['resolved'] ?? 0);

        return [
            'available' => $resolved > 0,
            'resolved'  => $resolved,
            'average'   => $resolved > 0 ? (float) $row['average'] : null,
            'fastest'   => $resolved > 0 ? (int) $row['fastest'] : null,
            'slowest'   => $resolved > 0 ? (int) $row['slowest'] : null,
        ];
    }

    /**
     * Cost recorded against maintenance.
     *
     * Estimate and actual are kept apart and never netted. A request with an
     * estimate and no actual has not been paid for yet; one with an actual and
     * no estimate was never costed in advance. Both are common and neither is
     * an error.
     *
     * @return array<string,mixed>
     */
    public function maintenanceCosts(): array
    {
        [$scope, $params]           = $this->scope('maintenance', 'm', 'p');
        [$filterSql, $filterParams] = $this->propertyFilters('p');

        $row = $this->row("
            SELECT COALESCE(SUM(m.cost_estimate), 0) AS estimate,
                   COALESCE(SUM(m.actual_cost), 0) AS actual,
                   COALESCE(SUM(m.cost_estimate > 0), 0) AS with_estimate,
                   COALESCE(SUM(m.actual_cost > 0), 0) AS with_actual
            FROM maintenance_requests m
            JOIN properties p ON m.property_id = p.id
            WHERE ({$scope}) {$filterSql}
        ", $params + $filterParams);

        return [
            'estimate'      => (float) ($row['estimate'] ?? 0),
            'actual'        => (float) ($row['actual'] ?? 0),
            'with_estimate' => (int) ($row['with_estimate'] ?? 0),
            'with_actual'   => (int) ($row['with_actual'] ?? 0),
        ];
    }

    /**
     * The open queue: urgency first, then longest waiting.
     *
     * @param string $mode 'open' for the queue, 'completed' for finished work
     * @return array<int,array<string,mixed>>
     */
    public function maintenanceTable(string $mode = 'open', int $limit = 25, int $offset = 0): array
    {
        [$scope, $params]           = $this->scope('maintenance', 'm', 'p');
        [$filterSql, $filterParams] = $this->propertyFilters('p');

        $limit  = max(1, min(100, $limit));
        $offset = max(0, min(100000, $offset));
        $open   = "'" . implode("','", self::MAINTENANCE_OPEN) . "'";

        // Literals this class chose; the request supplies only the tab name,
        // which the controller resolved through its own allowlist.
        if ($mode === 'completed') {
            $where = "m.status = 'completed'";
            $order = "m.completion_date DESC, m.id DESC";
        } else {
            $where = "m.status IN ({$open})";
            // FIELD() puts urgent first and low last, then oldest first
            // inside each level — the order somebody working the queue wants.
            $order = "FIELD(m.priority,'urgent','high','medium','low'), m.created_at ASC, m.id ASC";
        }

        return $this->rows("
            SELECT m.id, m.request_code, m.issue_type, m.priority, m.status,
                   DATE(m.created_at) AS raised_on, m.completion_date,
                   m.cost_estimate, m.actual_cost, m.assigned_to,
                   DATEDIFF(:w_today, DATE(m.created_at)) AS age_days,
                   CASE WHEN m.completion_date IS NOT NULL
                        THEN DATEDIFF(m.completion_date, DATE(m.created_at)) END AS resolution_days,
                   p.id AS property_id, p.title AS property_title, p.property_code, p.category,
                   staff.full_name AS assigned_name,
                   reporter.full_name AS reported_by_name
            FROM maintenance_requests m
            JOIN properties p ON m.property_id = p.id
            LEFT JOIN users staff ON m.assigned_to = staff.id
            LEFT JOIN users reporter ON m.reported_by = reporter.id
            WHERE {$where} AND ({$scope}) {$filterSql}
            ORDER BY {$order}
            LIMIT {$limit} OFFSET {$offset}
        ", $params + $filterParams + [':w_today' => $this->window['today']]);
    }

    /** How many rows the queue or the completed list holds. */
    public function maintenanceTableCount(string $mode = 'open'): int
    {
        [$scope, $params]           = $this->scope('maintenance', 'm', 'p');
        [$filterSql, $filterParams] = $this->propertyFilters('p');
        $open = "'" . implode("','", self::MAINTENANCE_OPEN) . "'";
        $where = $mode === 'completed' ? "m.status = 'completed'" : "m.status IN ({$open})";

        return $this->count("
            SELECT COUNT(*) FROM maintenance_requests m
            JOIN properties p ON m.property_id = p.id
            WHERE {$where} AND ({$scope}) {$filterSql}
        ", $params + $filterParams);
    }

    /**
     * Properties by how much maintenance they generate.
     *
     * A count, not a score. With three requests across two properties this is
     * a short list rather than a ranking, and the view says so instead of
     * dressing it up as a league table.
     *
     * @return array<int,array<string,mixed>>
     */
    public function maintenanceByProperty(int $limit = 10): array
    {
        [$scope, $params]           = $this->scope('maintenance', 'm', 'p');
        [$filterSql, $filterParams] = $this->propertyFilters('p');
        $limit = max(1, min(50, $limit));
        $open  = "'" . implode("','", self::MAINTENANCE_OPEN) . "'";

        return $this->rows("
            SELECT p.id, p.title, p.property_code, p.category,
                   COUNT(*) AS requests,
                   COALESCE(SUM(m.status IN ({$open})), 0) AS open_requests,
                   COALESCE(SUM(m.status = 'completed'), 0) AS completed_requests,
                   COALESCE(SUM(m.priority IN ('high','urgent')), 0) AS urgent_requests,
                   COALESCE(SUM(m.actual_cost), 0) AS cost
            FROM maintenance_requests m
            JOIN properties p ON m.property_id = p.id
            WHERE ({$scope}) {$filterSql}
            GROUP BY p.id, p.title, p.property_code, p.category
            ORDER BY requests DESC, open_requests DESC, p.property_code ASC
            LIMIT {$limit}
        ", $params + $filterParams);
    }

    /**
     * How consistent the issue-type text is.
     *
     * `issue_type` is a free-text VARCHAR with no vocabulary behind it. The
     * same measurement the location analysis uses decides whether it is worth
     * charting: near one distinct value per request means it is prose, not a
     * dimension. It currently holds three values for three requests, two of
     * which are test noise, so the report lists them and does not chart them.
     *
     * @return array<string,mixed>
     */
    public function maintenanceIssueTypes(int $limit = 15): array
    {
        [$scope, $params]           = $this->scope('maintenance', 'm', 'p');
        [$filterSql, $filterParams] = $this->propertyFilters('p');
        $limit = max(1, min(50, $limit));

        $summary = $this->row("
            SELECT COUNT(*) AS total,
                   COUNT(DISTINCT m.issue_type) AS distinct_values,
                   COALESCE(SUM(m.issue_type IS NULL OR TRIM(m.issue_type) = ''), 0) AS blank
            FROM maintenance_requests m
            JOIN properties p ON m.property_id = p.id
            WHERE ({$scope}) {$filterSql}
        ", $params + $filterParams);

        $rows = $this->rows("
            SELECT m.issue_type, COUNT(*) AS requests,
                   COALESCE(SUM(m.status IN ('new','under_review','assigned','in_progress')), 0) AS open_requests
            FROM maintenance_requests m
            JOIN properties p ON m.property_id = p.id
            WHERE m.issue_type IS NOT NULL AND TRIM(m.issue_type) <> ''
              AND ({$scope}) {$filterSql}
            GROUP BY m.issue_type
            ORDER BY requests DESC, m.issue_type ASC
            LIMIT {$limit}
        ", $params + $filterParams);

        $total    = (int) ($summary['total'] ?? 0);
        $distinct = (int) ($summary['distinct_values'] ?? 0);

        return [
            'total'    => $total,
            'distinct' => $distinct,
            'blank'    => (int) ($summary['blank'] ?? 0),
            'rows'     => $rows,
            'spread'   => $total > 0 ? round($distinct / $total, 2) : null,
            'usable'   => $total > 0 && ($distinct / $total) <= 0.5,
        ];
    }

    /**
     * Maintenance records that do not agree with themselves.
     *
     * @return array<string,array{count:int}>
     */
    public function maintenanceIntegrityFlags(): array
    {
        [$scope, $params]           = $this->scope('maintenance', 'm', 'p');
        [$filterSql, $filterParams] = $this->propertyFilters('p');
        $open = "'" . implode("','", self::MAINTENANCE_OPEN) . "'";

        $row = $this->row("
            SELECT COALESCE(SUM(m.status IN ({$open}) AND m.assigned_to IS NULL), 0) AS open_unassigned,
                   COALESCE(SUM(m.status = 'assigned' AND m.assigned_to IS NULL), 0) AS assigned_no_staff,
                   COALESCE(SUM(m.status = 'completed' AND m.completion_date IS NULL), 0) AS completed_no_date,
                   COALESCE(SUM(m.status <> 'completed' AND m.completion_date IS NOT NULL), 0) AS open_with_date,
                   COALESCE(SUM(m.completion_date IS NOT NULL AND m.completion_date < DATE(m.created_at)), 0) AS completed_before_raised,
                   COALESCE(SUM(m.issue_type IS NULL OR TRIM(m.issue_type) = ''), 0) AS no_type,
                   COALESCE(SUM(m.actual_cost < 0 OR m.cost_estimate < 0), 0) AS negative_cost,
                   COALESCE(SUM(m.status = 'completed' AND m.actual_cost = 0 AND m.cost_estimate > 0), 0) AS completed_no_cost
            FROM maintenance_requests m
            JOIN properties p ON m.property_id = p.id
            WHERE ({$scope}) {$filterSql}
        ", $params + $filterParams);

        // A request whose property row has gone. The JOIN above hides these,
        // so it is asked with a LEFT JOIN instead.
        $orphan = $this->count("
            SELECT COUNT(*) FROM maintenance_requests m
            LEFT JOIN properties p ON m.property_id = p.id
            WHERE p.id IS NULL
        ");

        $n = static fn($v): array => ['count' => (int) $v];

        return [
            'open_unassigned'         => $n($row['open_unassigned'] ?? 0),
            'assigned_no_staff'       => $n($row['assigned_no_staff'] ?? 0),
            'completed_no_date'       => $n($row['completed_no_date'] ?? 0),
            'open_with_date'          => $n($row['open_with_date'] ?? 0),
            'completed_before_raised' => $n($row['completed_before_raised'] ?? 0),
            'no_type'                 => $n($row['no_type'] ?? 0),
            'negative_cost'           => $n($row['negative_cost'] ?? 0),
            'completed_no_cost'       => $n($row['completed_no_cost'] ?? 0),
            'orphan_property'         => $n($orphan),
        ];
    }

    // ─── Sales · the deal book and the holds on it ─────────────────────
    //
    // The distinction this section exists to hold: a property listed for sale
    // is not a pending sale, a pending sale is not a completed one, and a
    // reservation is none of the three. The schema offers exactly three sale
    // statuses — pending, completed, cancelled — and no more are invented.
    //
    // Money follows the same rule as everywhere else in the module. A
    // completed sale's `sale_amount` is contract value, not cash; cash is
    // whatever `payments` recorded against it under the approved revenue
    // definition. The two are reported side by side and never added.

    /**
     * The sale book for the window, by status, in one pass.
     *
     * Bounded on `sale_date`, and completed sales additionally capped at
     * today: a sale dated next month has not completed yet, whatever its
     * status column says. Same rule the revenue definition applies to
     * payments, for the same reason.
     *
     * @return array<string,mixed>
     */
    public function salesSummary(bool $previous = false): array
    {
        [$scope, $params]           = $this->scope('sale', 's', 'p');
        [$filterSql, $filterParams] = $this->propertyFilters('p');

        $row = $this->row("
            SELECT COUNT(*) AS total,
                   COALESCE(SUM(s.sale_amount), 0) AS total_value,

                   COALESCE(SUM(s.status = 'completed' AND s.sale_date <= :w_today), 0) AS completed,
                   COALESCE(SUM(CASE WHEN s.status = 'completed' AND s.sale_date <= :w_today
                                     THEN s.sale_amount END), 0) AS completed_value,
                   COALESCE(SUM(CASE WHEN s.status = 'completed' AND s.sale_date <= :w_today
                                     THEN s.commission_amount END), 0) AS commission,

                   COALESCE(SUM(s.status = 'pending'), 0) AS pending,
                   COALESCE(SUM(CASE WHEN s.status = 'pending' THEN s.sale_amount END), 0) AS pending_value,

                   COALESCE(SUM(s.status = 'cancelled'), 0) AS cancelled,
                   COALESCE(SUM(CASE WHEN s.status = 'cancelled' THEN s.sale_amount END), 0) AS cancelled_value,

                   COALESCE(SUM(s.status = 'completed' AND s.sale_date > :w_today), 0) AS future_completed
            FROM sales s
            JOIN properties p ON s.property_id = p.id
            WHERE s.sale_date IS NOT NULL
              AND {$this->withinPeriod('s.sale_date')}
              AND ({$scope})
              {$filterSql}
        ", $params + $filterParams + $this->periodParams($previous));

        $completed = (int) ($row['completed'] ?? 0);

        return [
            'total'            => (int) ($row['total'] ?? 0),
            'total_value'      => (float) ($row['total_value'] ?? 0),
            'completed'        => $completed,
            'completed_value'  => (float) ($row['completed_value'] ?? 0),
            'commission'       => (float) ($row['commission'] ?? 0),
            'pending'          => (int) ($row['pending'] ?? 0),
            'pending_value'    => (float) ($row['pending_value'] ?? 0),
            'cancelled'        => (int) ($row['cancelled'] ?? 0),
            'cancelled_value'  => (float) ($row['cancelled_value'] ?? 0),
            'future_completed' => (int) ($row['future_completed'] ?? 0),
            // Only where something completed. An average over no sales is not
            // a small average, it is no average at all.
            'average'          => $completed > 0 ? (float) $row['completed_value'] / $completed : null,
        ];
    }

    /**
     * The pipeline: the three real statuses, including the empty ones.
     *
     * Zeros are kept here rather than dropped, because on a pipeline they are
     * the finding. "Nothing completed this period" is a statement worth
     * printing; a chart that quietly omits the completed bar leaves the
     * reader to notice an absence, which nobody does.
     *
     * @return array<int,array<string,mixed>>
     */
    public function salesPipeline(): array
    {
        [$scope, $params]           = $this->scope('sale', 's', 'p');
        [$filterSql, $filterParams] = $this->propertyFilters('p');

        $rows = $this->rows("
            SELECT s.status, COUNT(*) AS deals, COALESCE(SUM(s.sale_amount), 0) AS value
            FROM sales s
            JOIN properties p ON s.property_id = p.id
            WHERE s.sale_date IS NOT NULL
              AND {$this->withinPeriod('s.sale_date')}
              AND ({$scope})
              {$filterSql}
            GROUP BY s.status
        ", $params + $filterParams + $this->periodParams());

        $found = [];
        foreach ($rows as $r) {
            $found[(string) $r['status']] = $r;
        }

        $out = [];
        foreach (['pending' => '--warning', 'completed' => '--success', 'cancelled' => '--text-subtle'] as $status => $tone) {
            $out[] = [
                'status' => $status,
                'label'  => uiLabel($status),
                'tone'   => $tone,
                'deals'  => (int) ($found[$status]['deals'] ?? 0),
                'value'  => (float) ($found[$status]['value'] ?? 0),
            ];
        }

        return $out;
    }

    /**
     * Deal value by property category.
     *
     * All statuses, because the question is what kind of stock the sale book
     * is made of — a half-million-pound apartment still pending is the
     * dominant thing in the pipeline whether or not it closes. The completed
     * split rides alongside so the two are never confused.
     *
     * @return array<int,array<string,mixed>>
     */
    public function salesByCategory(): array
    {
        [$scope, $params]           = $this->scope('sale', 's', 'p');
        [$filterSql, $filterParams] = $this->propertyFilters('p');

        return $this->rows("
            SELECT p.category,
                   COUNT(*) AS deals,
                   COALESCE(SUM(s.sale_amount), 0) AS value,
                   COALESCE(SUM(s.status = 'completed' AND s.sale_date <= :w_today), 0) AS completed,
                   COALESCE(SUM(CASE WHEN s.status = 'completed' AND s.sale_date <= :w_today
                                     THEN s.sale_amount END), 0) AS completed_value
            FROM sales s
            JOIN properties p ON s.property_id = p.id
            WHERE s.sale_date IS NOT NULL
              AND {$this->withinPeriod('s.sale_date')}
              AND ({$scope})
              {$filterSql}
            GROUP BY p.category
            ORDER BY value DESC, p.category ASC
        ", $params + $filterParams + $this->periodParams());
    }

    /**
     * Sale value over time, on the sale_date axis.
     *
     * Two series: everything recorded, and the completed subset. Drawn
     * together they answer the only question a sales trend is ever asked —
     * how much is moving, and how much of it actually closed.
     *
     * @return array{recorded:array,completed:array}
     */
    public function salesSeries(): array
    {
        [$scope, $params]           = $this->scope('sale', 's', 'p');
        [$filterSql, $filterParams] = $this->propertyFilters('p');

        $bucket = $this->bucket('s.sale_date');

        $rows = $this->rows("
            SELECT {$bucket} AS bucket,
                   COALESCE(SUM(s.sale_amount), 0) AS recorded,
                   COALESCE(SUM(CASE WHEN s.status = 'completed' AND s.sale_date <= :w_today
                                     THEN s.sale_amount END), 0) AS completed
            FROM sales s
            JOIN properties p ON s.property_id = p.id
            WHERE s.sale_date IS NOT NULL
              AND {$this->withinPeriod('s.sale_date')}
              AND ({$scope})
              {$filterSql}
            GROUP BY bucket
            ORDER BY bucket
        ", $params + $filterParams + $this->periodParams());

        return [
            'recorded'  => $this->fillSeries($rows, 'bucket', 'recorded'),
            'completed' => $this->fillSeries($rows, 'bucket', 'completed'),
        ];
    }

    /**
     * The sale register: one row per deal.
     *
     * `collected` is cash actually received against the sale, under the
     * approved revenue definition — not the contract value. On the current
     * data every deal shows zero collected, which is correct: no payment has
     * ever been recorded with a sale reference.
     *
     * @return array<int,array<string,mixed>>
     */
    public function salesRegister(int $limit = 25, int $offset = 0): array
    {
        [$scope, $params]           = $this->scope('sale', 's', 'p');
        [$filterSql, $filterParams] = $this->propertyFilters('p');
        [$payScope, $payParams]     = $this->scope('payment', 'py', 'pp');
        $types = $this->revenueTypeList();

        $limit  = max(1, min(100, $limit));
        $offset = max(0, min(100000, $offset));

        // Correlated rather than joined: a sale with three payments must not
        // become three rows in a register that promises one row per deal.
        $collected = "(SELECT COALESCE(SUM(py.amount), 0)
                         FROM payments py
                         LEFT JOIN properties pp ON py.property_id = pp.id
                        WHERE py.reference_type = 'sale'
                          AND py.reference_id = s.id
                          AND py.status = 'paid'
                          AND py.payment_date IS NOT NULL
                          AND py.payment_date <= :w_today
                          AND py.payment_type IN ({$types})
                          AND ({$payScope}))";

        return $this->rows("
            SELECT s.id, s.sale_code, s.sale_date, s.sale_amount, s.commission_amount,
                   s.payment_type, s.status,
                   p.id AS property_id, p.title AS property_title, p.property_code, p.category,
                   c.full_name AS buyer_name,
                   u.full_name AS agent_name, s.agent_id,
                   {$collected} AS collected
            FROM sales s
            JOIN properties p ON s.property_id = p.id
            LEFT JOIN customers c ON s.customer_id = c.id
            LEFT JOIN users u ON s.agent_id = u.id
            WHERE s.sale_date IS NOT NULL
              AND {$this->withinPeriod('s.sale_date')}
              AND ({$scope})
              {$filterSql}
            ORDER BY s.sale_date DESC, s.id DESC
            LIMIT {$limit} OFFSET {$offset}
        ", $params + $filterParams + $payParams + $this->periodParams()
           + [':w_today' => $this->window['today']]);
    }

    /** How many deals the register pages through. */
    public function salesRegisterCount(): int
    {
        [$scope, $params]           = $this->scope('sale', 's', 'p');
        [$filterSql, $filterParams] = $this->propertyFilters('p');

        return $this->count("
            SELECT COUNT(*) FROM sales s
            JOIN properties p ON s.property_id = p.id
            WHERE s.sale_date IS NOT NULL
              AND {$this->withinPeriod('s.sale_date')}
              AND ({$scope}) {$filterSql}
        ", $params + $filterParams + $this->periodParams());
    }

    /**
     * Reservations: holds on property, counted by whether they still stand.
     *
     * An expired hold is not an active one however its status column reads,
     * and the two are never added. `deposit_amount` is the only money a
     * reservation carries and it is a deposit — held, not earned, and never
     * folded into sales value.
     *
     * Deliberately not window-bounded: a hold is a current-state fact.
     *
     * @return array<string,mixed>
     */
    public function reservationSummary(): array
    {
        [$scope, $params]           = $this->scope('reservation', 'r', 'p');
        [$filterSql, $filterParams] = $this->propertyFilters('p');

        $row = $this->row("
            SELECT COUNT(*) AS total,
                   COALESCE(SUM(r.status IN ('active','confirmed') AND r.expiry_date >= :w_today), 0) AS live,
                   COALESCE(SUM(CASE WHEN r.status IN ('active','confirmed') AND r.expiry_date >= :w_today
                                     THEN r.deposit_amount END), 0) AS live_deposits,
                   COALESCE(SUM(r.status = 'confirmed' AND r.expiry_date >= :w_today), 0) AS confirmed_live,
                   COALESCE(SUM(r.status IN ('active','confirmed') AND r.expiry_date < :w_today), 0) AS lapsed,
                   COALESCE(SUM(CASE WHEN r.status IN ('active','confirmed') AND r.expiry_date < :w_today
                                     THEN r.deposit_amount END), 0) AS lapsed_deposits,
                   COALESCE(SUM(r.status = 'expired'), 0) AS marked_expired,
                   COALESCE(SUM(r.status = 'cancelled'), 0) AS cancelled
            FROM reservations r
            JOIN properties p ON r.property_id = p.id
            WHERE ({$scope}) {$filterSql}
        ", $params + $filterParams + [':w_today' => $this->window['today']]);

        return [
            'total'           => (int) ($row['total'] ?? 0),
            'live'            => (int) ($row['live'] ?? 0),
            'live_deposits'   => (float) ($row['live_deposits'] ?? 0),
            'confirmed_live'  => (int) ($row['confirmed_live'] ?? 0),
            // Still flagged active or confirmed, but past their expiry date.
            'lapsed'          => (int) ($row['lapsed'] ?? 0),
            'lapsed_deposits' => (float) ($row['lapsed_deposits'] ?? 0),
            'marked_expired'  => (int) ($row['marked_expired'] ?? 0),
            'cancelled'       => (int) ($row['cancelled'] ?? 0),
        ];
    }

    /**
     * The reservation queue, soonest to expire first.
     *
     * Lapsed holds sort to the top because they are the ones needing a
     * decision: either the deposit is returned and the property released, or
     * the hold is renewed.
     *
     * @return array<int,array<string,mixed>>
     */
    public function reservationQueue(int $limit = 25): array
    {
        [$scope, $params]           = $this->scope('reservation', 'r', 'p');
        [$filterSql, $filterParams] = $this->propertyFilters('p');

        $limit = max(1, min(100, $limit));

        return $this->rows("
            SELECT r.id, r.reservation_code, r.reservation_date, r.expiry_date,
                   r.deposit_amount, r.status,
                   DATEDIFF(r.expiry_date, :w_today) AS days_left,
                   p.id AS property_id, p.title AS property_title, p.property_code,
                   c.full_name AS customer_name
            FROM reservations r
            JOIN properties p ON r.property_id = p.id
            LEFT JOIN customers c ON r.customer_id = c.id
            WHERE r.status IN ('active','confirmed')
              AND ({$scope})
              {$filterSql}
            ORDER BY r.expiry_date ASC, r.id ASC
            LIMIT {$limit}
        ", $params + $filterParams + [':w_today' => $this->window['today']]);
    }

    /**
     * Sale and reservation records that do not agree with themselves.
     *
     * Not window-bounded: a bad record is a bad record whichever period is on
     * screen.
     *
     * @return array<string,array{count:int,amount:float}>
     */
    public function salesIntegrityFlags(): array
    {
        [$scope, $params]           = $this->scope('sale', 's', 'p');
        [$filterSql, $filterParams] = $this->propertyFilters('p');
        [$rScope, $rParams]         = $this->scope('reservation', 'r', 'p');

        $sale = $this->row("
            SELECT COALESCE(SUM(s.sale_amount <= 0), 0) AS bad_amount,
                   COALESCE(SUM(s.sale_date IS NULL), 0) AS no_date,
                   COALESCE(SUM(s.status = 'completed' AND s.sale_date > :w_today), 0) AS future_completed,
                   COALESCE(SUM(s.agent_id IS NULL), 0) AS no_agent
            FROM sales s
            JOIN properties p ON s.property_id = p.id
            WHERE ({$scope}) {$filterSql}
        ", $params + $filterParams + [':w_today' => $this->window['today']]);

        // A sale whose property row has gone. The JOIN above would hide these,
        // so it is asked separately with a LEFT JOIN.
        $orphan = $this->count("
            SELECT COUNT(*) FROM sales s
            LEFT JOIN properties p ON s.property_id = p.id
            WHERE p.id IS NULL
        ");

        // More than one completed sale on the same property.
        $dup = $this->count("
            SELECT COUNT(*) FROM (
                SELECT s.property_id FROM sales s
                JOIN properties p ON s.property_id = p.id
                WHERE s.status = 'completed' AND ({$scope}) {$filterSql}
                GROUP BY s.property_id HAVING COUNT(*) > 1
            ) d
        ", $params + $filterParams);

        // Properties whose record claims a sale that never completed.
        [$pScope, $pParams] = $this->scope('property', 'p');
        [$pFilterSql, $pFilterParams] = $this->propertyFilters('p');
        $soldNoSale = $this->count("
            SELECT COUNT(*) FROM properties p
            WHERE p.is_archived = 0 AND p.status = 'sold'
              AND NOT EXISTS (SELECT 1 FROM sales sn WHERE sn.property_id = p.id AND sn.status = 'completed')
              AND ({$pScope}) {$pFilterSql}
        ", $pParams + $pFilterParams);

        $resv = $this->row("
            SELECT COALESCE(SUM(r.status IN ('active','confirmed') AND r.expiry_date < :w_today), 0) AS lapsed,
                   COALESCE(SUM(CASE WHEN r.status IN ('active','confirmed') AND r.expiry_date < :w_today
                                     THEN r.deposit_amount END), 0) AS lapsed_deposits,
                   COALESCE(SUM(r.expiry_date < r.reservation_date), 0) AS expiry_before_start
            FROM reservations r
            JOIN properties p ON r.property_id = p.id
            WHERE ({$rScope}) {$pFilterSql}
        ", $rParams + $pFilterParams + [':w_today' => $this->window['today']]);

        $n = static fn($v, float $amt = 0.0): array => ['count' => (int) $v, 'amount' => $amt];

        return [
            'bad_amount'          => $n($sale['bad_amount'] ?? 0),
            'no_date'             => $n($sale['no_date'] ?? 0),
            'future_completed'    => $n($sale['future_completed'] ?? 0),
            'no_agent'            => $n($sale['no_agent'] ?? 0),
            'orphan_property'     => $n($orphan),
            'duplicate_completed' => $n($dup),
            'sold_no_sale'        => $n($soldNoSale),
            'lapsed_reservations' => $n($resv['lapsed'] ?? 0, (float) ($resv['lapsed_deposits'] ?? 0)),
            'expiry_before_start' => $n($resv['expiry_before_start'] ?? 0),
        ];
    }

    // ─── Rentals · tenancies and the rent roll ─────────────────────────
    //
    // The rental report reuses the approved rent ledger wholesale — expected,
    // settled, arrears and not-yet-due all come from rentLedger() and
    // rentLedgerSeries() rather than being re-derived here. What these
    // methods add is the tenancy dimension: how many are running, when they
    // end, and which one owes what.

    /**
     * Active tenancies and the rent they contract for.
     *
     * "Active" is `status = 'active'` and not past its end date — the same
     * live-lease test occupancy() uses, so the two can never disagree about
     * how many tenancies are running.
     *
     * `rent_roll` is the sum of contracted monthly rent across those
     * tenancies. It is a standing figure, not a period one: it says what the
     * book is worth per month today, and deliberately does not move with the
     * reporting window.
     *
     * @return array<string,mixed>
     */
    public function leaseSummary(): array
    {
        [$scope, $params]           = $this->scope('lease', 'l', 'p');
        [$filterSql, $filterParams] = $this->propertyFilters('p');

        $row = $this->row("
            SELECT COUNT(*) AS active,
                   COALESCE(SUM(l.rent_amount), 0) AS rent_roll,
                   COALESCE(AVG(l.rent_amount), 0) AS average_rent,
                   COALESCE(SUM(l.end_date <= DATE_ADD(:w_today, INTERVAL 60 DAY)), 0) AS ending_soon
            FROM leases l
            JOIN properties p ON l.property_id = p.id
            WHERE l.status = 'active' AND l.end_date >= :w_today
              AND ({$scope})
              {$filterSql}
        ", $params + $filterParams + [':w_today' => $this->window['today']]);

        return [
            'active'       => (int) ($row['active'] ?? 0),
            'rent_roll'    => (float) ($row['rent_roll'] ?? 0),
            'average_rent' => (int) ($row['active'] ?? 0) > 0 ? (float) $row['average_rent'] : null,
            'ending_soon'  => (int) ($row['ending_soon'] ?? 0),
        ];
    }

    /**
     * When the running tenancies end.
     *
     * The buckets are mutually exclusive and an expired lease is never
     * "expiring soon" — it has already gone, which is a different and more
     * urgent problem. `expired` here means a lease still flagged active whose
     * end date has passed: nothing rolls that status forward automatically, so
     * the count is a queue rather than a statistic.
     *
     * @return array<int,array<string,mixed>>
     */
    public function leaseExpiryBuckets(): array
    {
        [$scope, $params]           = $this->scope('lease', 'l', 'p');
        [$filterSql, $filterParams] = $this->propertyFilters('p');

        $row = $this->row("
            SELECT COALESCE(SUM(l.end_date < :w_today), 0) AS expired,
                   COALESCE(SUM(l.end_date >= :w_today
                                AND l.end_date <= DATE_ADD(:w_today, INTERVAL 7 DAY)), 0) AS d7,
                   COALESCE(SUM(l.end_date > DATE_ADD(:w_today, INTERVAL 7 DAY)
                                AND l.end_date <= DATE_ADD(:w_today, INTERVAL 30 DAY)), 0) AS d30,
                   COALESCE(SUM(l.end_date > DATE_ADD(:w_today, INTERVAL 30 DAY)
                                AND l.end_date <= DATE_ADD(:w_today, INTERVAL 60 DAY)), 0) AS d60,
                   COALESCE(SUM(l.end_date > DATE_ADD(:w_today, INTERVAL 60 DAY)), 0) AS beyond
            FROM leases l
            JOIN properties p ON l.property_id = p.id
            WHERE l.status = 'active' AND ({$scope}) {$filterSql}
        ", $params + $filterParams + [':w_today' => $this->window['today']]);

        return [
            ['key' => 'expired', 'label' => 'Already expired',   'count' => (int) ($row['expired'] ?? 0), 'tone' => '--danger'],
            ['key' => 'd7',      'label' => 'Within 7 days',     'count' => (int) ($row['d7'] ?? 0),      'tone' => '--warning'],
            ['key' => 'd30',     'label' => 'Within 30 days',    'count' => (int) ($row['d30'] ?? 0),     'tone' => '--orange'],
            ['key' => 'd60',     'label' => 'Within 60 days',    'count' => (int) ($row['d60'] ?? 0),     'tone' => '--info'],
            ['key' => 'beyond',  'label' => 'More than 60 days', 'count' => (int) ($row['beyond'] ?? 0),  'tone' => '--success'],
        ];
    }

    /**
     * One row per tenancy, with its own slice of the rent ledger.
     *
     * The ledger columns are per-lease aggregates computed in the same pass,
     * so a tenancy cannot appear twice and no per-row query is issued. Which
     * matters: joining leases to payment_schedules directly would multiply the
     * lease row by its instalment count, and a table promising one row per
     * tenancy would quietly stop keeping that promise.
     *
     * `expected` and `settled` are bounded by the reporting window on the
     * due-date axis, matching the approved collection-rate definition.
     * `outstanding` and `arrears` are running balances across the whole
     * tenancy, which is why a lease can show more outstanding than expected.
     *
     * @param string $mode 'active' for the running book, 'attention' for the
     *                     queue of expired and soon-ending tenancies
     * @return array<int,array<string,mixed>>
     */
    public function leaseTable(string $mode = 'active', int $limit = 25, int $offset = 0): array
    {
        [$scope, $params]           = $this->scope('lease', 'l', 'p');
        [$filterSql, $filterParams] = $this->propertyFilters('p');

        $limit  = max(1, min(100, $limit));
        $offset = max(0, min(100000, $offset));

        // Written here, never taken from the request.
        $where = $mode === 'attention'
            ? "l.status = 'active' AND l.end_date <= DATE_ADD(:w_today, INTERVAL 60 DAY)"
            : "l.status = 'active' AND l.end_date >= :w_today";
        $order = $mode === 'attention' ? 'l.end_date ASC' : 'l.end_date ASC';

        return $this->rows("
            SELECT l.id, l.lease_code, l.start_date, l.end_date, l.rent_amount, l.status,
                   DATEDIFF(l.end_date, :w_today) AS days_left,
                   p.id AS property_id, p.title AS property_title, p.property_code, p.status AS property_status,
                   c.full_name AS tenant_name,
                   COALESCE(SUM(CASE WHEN ps.due_date BETWEEN :w_from AND :w_to
                                     THEN ps.amount + ps.penalty END), 0) AS expected,
                   COALESCE(SUM(CASE WHEN ps.status = 'paid' AND ps.due_date BETWEEN :w_from AND :w_to
                                     THEN ps.amount END), 0) AS settled,
                   COALESCE(SUM(CASE WHEN ps.status <> 'paid'
                                     THEN ps.amount + ps.penalty END), 0) AS outstanding,
                   COALESCE(SUM(CASE WHEN ps.status IN ('overdue','partial')
                                     THEN ps.amount + ps.penalty END), 0) AS arrears,
                   COALESCE(SUM(ps.status = 'overdue'), 0) AS overdue_count
            FROM leases l
            JOIN properties p ON l.property_id = p.id
            LEFT JOIN customers c ON l.customer_id = c.id
            LEFT JOIN payment_schedules ps ON ps.lease_id = l.id
            WHERE {$where} AND ({$scope}) {$filterSql}
            GROUP BY l.id, l.lease_code, l.start_date, l.end_date, l.rent_amount, l.status,
                     p.id, p.title, p.property_code, p.status, c.full_name
            ORDER BY {$order}, l.id ASC
            LIMIT {$limit} OFFSET {$offset}
        ", $params + $filterParams + $this->periodParams() + [':w_today' => $this->window['today']]);
    }

    /** How many tenancies the active table pages through. */
    public function leaseTableCount(string $mode = 'active'): int
    {
        [$scope, $params]           = $this->scope('lease', 'l', 'p');
        [$filterSql, $filterParams] = $this->propertyFilters('p');

        $where = $mode === 'attention'
            ? "l.status = 'active' AND l.end_date <= DATE_ADD(:w_today, INTERVAL 60 DAY)"
            : "l.status = 'active' AND l.end_date >= :w_today";

        return $this->count("
            SELECT COUNT(*) FROM leases l
            JOIN properties p ON l.property_id = p.id
            WHERE {$where} AND ({$scope}) {$filterSql}
        ", $params + $filterParams + [':w_today' => $this->window['today']]);
    }

    /**
     * Tenancy records that do not agree with themselves.
     *
     * Every one of these is a condition the schema permits and no workflow
     * prevents. The last is the one worth reading twice: a terminated tenancy
     * that still carries unpaid instalments contributes to company-wide
     * arrears and to the outstanding balance, so the money is being reported
     * against a let that has already ended.
     *
     * @return array<string,array{count:int,amount:float}>
     */
    public function leaseIntegrityFlags(): array
    {
        [$scope, $params]           = $this->scope('lease', 'l', 'p');
        [$filterSql, $filterParams] = $this->propertyFilters('p');

        $row = $this->row("
            SELECT COALESCE(SUM(l.status = 'active' AND l.end_date < :w_today), 0) AS active_past_end,
                   COALESCE(SUM(l.end_date < l.start_date), 0) AS end_before_start,
                   COALESCE(SUM(l.move_out_date IS NOT NULL AND l.move_in_date IS NOT NULL
                                AND l.move_out_date < l.move_in_date), 0) AS moveout_before_movein,
                   COALESCE(SUM(l.rent_amount <= 0), 0) AS zero_rent,
                   COALESCE(SUM(l.status = 'active' AND p.status <> 'rented'), 0) AS status_disagrees
            FROM leases l
            JOIN properties p ON l.property_id = p.id
            WHERE ({$scope}) {$filterSql}
        ", $params + $filterParams + [':w_today' => $this->window['today']]);

        // Two active tenancies on one property at once — the schema allows it
        // and nothing checks for it.
        $dup = $this->count("
            SELECT COUNT(*) FROM (
                SELECT l.property_id
                FROM leases l
                JOIN properties p ON l.property_id = p.id
                WHERE l.status = 'active' AND ({$scope}) {$filterSql}
                GROUP BY l.property_id
                HAVING COUNT(*) > 1
            ) dupes
        ", $params + $filterParams);

        // Money still owed against tenancies that have already ended.
        $ended = $this->row("
            SELECT COUNT(DISTINCT l.id) AS leases,
                   COALESCE(SUM(CASE WHEN ps.status IN ('overdue','partial')
                                     THEN ps.amount + ps.penalty END), 0) AS arrears,
                   COALESCE(SUM(CASE WHEN ps.status = 'pending'
                                     THEN ps.amount + ps.penalty END), 0) AS not_yet_due
            FROM leases l
            JOIN properties p ON l.property_id = p.id
            JOIN payment_schedules ps ON ps.lease_id = l.id
            WHERE l.status IN ('terminated','expired') AND ps.status <> 'paid'
              AND ({$scope}) {$filterSql}
        ", $params + $filterParams);

        $n = static fn($v): array => ['count' => (int) $v, 'amount' => 0.0];

        return [
            'active_past_end'       => $n($row['active_past_end'] ?? 0),
            'end_before_start'      => $n($row['end_before_start'] ?? 0),
            'moveout_before_movein' => $n($row['moveout_before_movein'] ?? 0),
            'zero_rent'             => $n($row['zero_rent'] ?? 0),
            'status_disagrees'      => $n($row['status_disagrees'] ?? 0),
            'duplicate_active'      => $n($dup),
            'ended_with_balance'    => [
                'count'       => (int) ($ended['leases'] ?? 0),
                'amount'      => (float) ($ended['arrears'] ?? 0) + (float) ($ended['not_yet_due'] ?? 0),
                'arrears'     => (float) ($ended['arrears'] ?? 0),
                'not_yet_due' => (float) ($ended['not_yet_due'] ?? 0),
            ],
        ];
    }

    // ─── Portfolio · inventory and composition ─────────────────────────
    //
    // Everything here is current-state. A property is occupied now, approved
    // now, assigned to an agent now; the schema records no history of any of
    // it, so none of these figures moves when the reporting window changes
    // and none of them has a previous-period equivalent. The only
    // window-bounded quantity on the whole report is revenue.
    //
    // That distinction is carried through to the UI rather than hidden: a
    // portfolio KPI that silently ignored the date picker would be read as a
    // figure for the period, and it is not.

    /**
     * The portfolio's commercial state, counted once across all inventory.
     *
     * inventory() already answers this for the rentable subset, and is not
     * touched. What it cannot answer is "how many properties are commercially
     * available" across the whole book, because its `vacant` is rentable-only
     * by design — a sale-only listing is never vacant, it is unsold.
     *
     * The four states are mutually exclusive and resolved in the order a
     * property can only be one of them: a completed sale ends the story, then
     * a live lease, then an unexpired hold, and what is left is available.
     * Each is proved by a record rather than by properties.status, which the
     * audit found is not written when a lease is signed.
     *
     * @return array<string,mixed>
     */
    public function portfolioState(): array
    {
        [$scope, $scopeParams]      = $this->scope('property', 'p');
        [$filterSql, $filterParams] = $this->propertyFilters('p');

        ['leased' => $liveLease, 'held' => $heldNow, 'sold' => $soldOff] = $this->commercialState('ps');

        $row = $this->row("
            SELECT COUNT(*) AS total,
                   COALESCE(SUM({$soldOff}), 0) AS sold,
                   COALESCE(SUM(NOT {$soldOff} AND {$liveLease}), 0) AS occupied,
                   COALESCE(SUM(NOT {$soldOff} AND NOT {$liveLease} AND {$heldNow}), 0) AS reserved,
                   COALESCE(SUM(NOT {$soldOff} AND NOT {$liveLease} AND NOT {$heldNow}), 0) AS available
            FROM properties p
            WHERE p.is_archived = 0
              AND p.approval_status = 'approved'
              AND ({$scope})
              {$filterSql}
        ", $scopeParams + $filterParams + [':w_today' => $this->window['today']]);

        return [
            'total'     => (int) ($row['total'] ?? 0),
            'available' => (int) ($row['available'] ?? 0),
            'occupied'  => (int) ($row['occupied'] ?? 0),
            'reserved'  => (int) ($row['reserved'] ?? 0),
            'sold'      => (int) ($row['sold'] ?? 0),
        ];
    }

    /**
     * The portfolio by category, with its commercial state alongside.
     *
     * `category` is an ENUM on the property row rather than a table, so the
     * five values that exist are the five the schema allows and there is no
     * metadata to join. Categories with nothing in them are absent rather
     * than listed at zero — an empty category is a schema fact, not a
     * portfolio one.
     *
     * @return array<int,array<string,mixed>>
     */
    public function portfolioComposition(): array
    {
        return $this->portfolioGroupedBy('p.category');
    }

    /**
     * The portfolio by listing intent — how it is meant to be marketed.
     *
     * `property_type` is rent, sale or both, and answers a different question
     * from the commercial state: intent versus outcome. A property listed for
     * sale that has not sold is still sale-intent inventory, and counting it
     * as a completed sale would be the mistake this separation exists to
     * prevent.
     *
     * @return array<int,array<string,mixed>>
     */
    public function portfolioListingIntent(): array
    {
        return $this->portfolioGroupedBy('p.property_type');
    }

    /**
     * The portfolio grouped by any column on `properties` this class names.
     *
     * $column is written here, never taken from the request — the two public
     * callers above pass a literal. Written once because category and intent
     * ask the same shape of question and two copies would drift.
     *
     * @return array<int,array<string,mixed>>
     */
    private function portfolioGroupedBy(string $column): array
    {
        [$scope, $scopeParams]      = $this->scope('property', 'p');
        [$filterSql, $filterParams] = $this->propertyFilters('p');

        ['leased' => $liveLease, 'sold' => $soldOff] = $this->commercialState('pg');

        $rows = $this->rows("
            SELECT {$column} AS grp,
                   COUNT(*) AS properties,
                   COALESCE(SUM(NOT {$soldOff} AND {$liveLease}), 0) AS occupied,
                   COALESCE(SUM({$soldOff}), 0) AS sold
            FROM properties p
            WHERE p.is_archived = 0
              AND p.approval_status = 'approved'
              AND ({$scope})
              {$filterSql}
            GROUP BY {$column}
            ORDER BY properties DESC, grp ASC
        ", $scopeParams + $filterParams + [':w_today' => $this->window['today']]);

        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'key'        => (string) $r['grp'],
                'label'      => uiLabel((string) $r['grp']),
                'properties' => (int) $r['properties'],
                'occupied'   => (int) $r['occupied'],
                'sold'       => (int) $r['sold'],
            ];
        }

        return $out;
    }

    /**
     * How consistent the location data actually is.
     *
     * There is one free-text `location` column and no city, district or
     * region field to group on. Before drawing anything by location it is
     * worth knowing whether location means anything here: seventeen
     * properties across fourteen distinct strings, several of which differ
     * only in punctuation, is a column of addresses rather than a dimension.
     *
     * This returns the measurements so the report can say so and show a
     * table, instead of drawing a bar chart of fourteen bars of one and
     * calling it geographic analysis. Normalising the strings into tidy
     * regions would be inventing groupings the data does not contain.
     *
     * @return array<string,mixed>
     */
    public function portfolioLocations(int $limit = 15): array
    {
        [$scope, $scopeParams]      = $this->scope('property', 'p');
        [$filterSql, $filterParams] = $this->propertyFilters('p');

        $limit = max(1, min(100, $limit));

        $summary = $this->row("
            SELECT COUNT(*) AS total,
                   COALESCE(SUM(p.location IS NULL OR TRIM(p.location) = ''), 0) AS blank,
                   COUNT(DISTINCT p.location) AS distinct_values
            FROM properties p
            WHERE p.is_archived = 0 AND ({$scope}) {$filterSql}
        ", $scopeParams + $filterParams);

        $rows = $this->rows("
            SELECT p.location, COUNT(*) AS properties
            FROM properties p
            WHERE p.is_archived = 0
              AND p.location IS NOT NULL AND TRIM(p.location) <> ''
              AND ({$scope})
              {$filterSql}
            GROUP BY p.location
            ORDER BY properties DESC, p.location ASC
            LIMIT {$limit}
        ", $scopeParams + $filterParams);

        $total    = (int) ($summary['total'] ?? 0);
        $distinct = (int) ($summary['distinct_values'] ?? 0);

        return [
            'total'    => $total,
            'blank'    => (int) ($summary['blank'] ?? 0),
            'distinct' => $distinct,
            'rows'     => $rows,
            // Near 1.0 means every property has its own string — free text
            // rather than a dimension worth charting. The threshold is this
            // report's own reading of the data and is stated as such.
            'spread'   => $total > 0 ? round($distinct / $total, 2) : null,
            'usable'   => $total > 0 && ($distinct / $total) <= 0.5,
        ];
    }

    /**
     * One row per property: what it is, what state it is in, what it earned.
     *
     * The scope note that matters, and it is a real asymmetry rather than an
     * oversight: the row set is chosen by *property* visibility, while the
     * revenue column is summed under *payment* visibility. An agent therefore
     * sees every property assigned to them, and against each one only the
     * money they are entitled to see. Where those two rules disagree — a
     * payment on their property taken by a colleague — the property appears
     * with a revenue figure lower than the company's. That is the existing
     * access model, applied consistently rather than silently merged.
     *
     * @return array<int,array<string,mixed>>
     */
    public function portfolioTable(int $limit = 25, int $offset = 0): array
    {
        [$scope, $scopeParams]      = $this->scope('property', 'p');
        [$filterSql, $filterParams] = $this->propertyFilters('p');
        [$payScope, $payParams]     = $this->scope('payment', 'py', 'pp');
        $types = $this->revenueTypeList();

        $limit  = max(1, min(100, $limit));
        $offset = max(0, min(100000, $offset));

        ['leased' => $liveLease, 'held' => $heldNow, 'sold' => $soldOff] = $this->commercialState('pt');

        // Revenue as a correlated subquery rather than a join, so one payment
        // row can never multiply a property row -- the table promises one row
        // per property and a join to a one-to-many would quietly break that.
        $revenue = "(SELECT COALESCE(SUM(py.amount), 0)
                       FROM payments py
                       LEFT JOIN properties pp ON py.property_id = pp.id
                      WHERE py.property_id = p.id
                        AND py.status = 'paid'
                        AND py.payment_date IS NOT NULL
                        AND py.payment_date <= :w_today
                        AND py.payment_date BETWEEN :w_from AND :w_to
                        AND py.payment_type IN ({$types})
                        AND ({$payScope}))";

        return $this->rows("
            SELECT p.id, p.property_code, p.title, p.category, p.property_type,
                   p.location, p.status AS recorded_status, p.approval_status,
                   p.agent_id, u.full_name AS agent_name,
                   {$soldOff}   AS is_sold,
                   {$liveLease} AS is_occupied,
                   {$heldNow}   AS is_reserved,
                   {$revenue}   AS revenue
            FROM properties p
            LEFT JOIN users u ON p.agent_id = u.id
            WHERE p.is_archived = 0
              AND ({$scope})
              {$filterSql}
            ORDER BY revenue DESC, p.property_code ASC
            LIMIT {$limit} OFFSET {$offset}
        ", $scopeParams + $filterParams + $payParams + $this->periodParams()
           + [':w_today' => $this->window['today']]);
    }

    /** How many properties the portfolio table is paging through. */
    public function portfolioTableCount(): int
    {
        [$scope, $scopeParams]      = $this->scope('property', 'p');
        [$filterSql, $filterParams] = $this->propertyFilters('p');

        return $this->count("
            SELECT COUNT(*) FROM properties p
            WHERE p.is_archived = 0 AND ({$scope}) {$filterSql}
        ", $scopeParams + $filterParams);
    }

    /**
     * Portfolio records that do not agree with themselves.
     *
     * reportPropertyStateIssues() already counts the three status
     * contradictions and is reused rather than restated. What it does not
     * cover is attribution and archival, so those are measured here.
     *
     * The archived check is worth having even though it reads zero: a
     * property filed away while a tenancy is still running is invisible to
     * every register in the system, and nothing prevents it.
     *
     * @return array<string,int>
     */
    public function portfolioIntegrityFlags(): array
    {
        [$scope, $scopeParams]      = $this->scope('property', 'p');
        [$filterSql, $filterParams] = $this->propertyFilters('p');

        $liveLease = "EXISTS (SELECT 1 FROM leases pf_l
                              WHERE pf_l.property_id = p.id
                                AND pf_l.status = 'active'
                                AND pf_l.end_date >= :w_today)";

        $row = $this->row("
            SELECT COALESCE(SUM(p.is_archived = 0 AND p.agent_id IS NULL), 0) AS no_agent,
                   COALESCE(SUM(p.is_archived = 0 AND p.owner_id IS NULL), 0) AS no_owner,
                   COALESCE(SUM(p.is_archived = 0 AND (p.location IS NULL OR TRIM(p.location) = '')), 0) AS no_location,
                   COALESCE(SUM(p.is_archived = 1 AND {$liveLease}), 0) AS archived_with_lease
            FROM properties p
            WHERE ({$scope}) {$filterSql}
        ", $scopeParams + $filterParams + [':w_today' => $this->window['today']]);

        return [
            'no_agent'            => (int) ($row['no_agent'] ?? 0),
            'no_owner'            => (int) ($row['no_owner'] ?? 0),
            'no_location'         => (int) ($row['no_location'] ?? 0),
            'archived_with_lease' => (int) ($row['archived_with_lease'] ?? 0),
        ];
    }

    // ─── Payments · transaction activity ───────────────────────────────
    //
    // This is a different report from Financial, and the difference is the
    // question. Financial asks how the business performed; these methods ask
    // what happened in the ledger. So the axis is payment_date throughout —
    // when money moved, not when it was due — and the unit of analysis is the
    // record rather than the money.
    //
    // Three quantities that are easy to conflate and are kept apart:
    //
    //   records          rows in the window, whatever their status
    //   received         status = paid, dated on or before today
    //   collected        the narrower revenue definition, which additionally
    //                    excludes deposits and refunds
    //
    // On the present data the last two happen to be equal, because no deposit
    // or refund has ever been recorded. They are still computed separately —
    // the first refund would silently break any report that assumed they were
    // the same figure.

    /**
     * The window's payment activity, in one pass.
     *
     * @return array<string,mixed>
     */
    public function paymentActivity(bool $previous = false): array
    {
        [$scope, $scopeParams]      = $this->scope('payment', 'py', 'p');
        [$filterSql, $filterParams] = $this->propertyFilters('p');
        [$methodSql, $methodParams] = $this->paymentMethodFilter('py');
        $types = $this->revenueTypeList();

        $row = $this->row("
            SELECT COUNT(*) AS records,
                   COALESCE(SUM(py.amount), 0) AS amount,
                   COALESCE(SUM(CASE WHEN py.status = 'paid' AND py.payment_date <= :w_today
                                     THEN py.amount END), 0) AS received,
                   COALESCE(SUM(py.status = 'paid' AND py.payment_date <= :w_today), 0) AS received_records,
                   COALESCE(SUM(CASE WHEN py.status = 'paid' AND py.payment_date <= :w_today
                                      AND py.payment_type IN ({$types})
                                     THEN py.amount END), 0) AS collected,
                   COALESCE(SUM(py.status = 'cancelled'), 0) AS cancelled_records,
                   COALESCE(SUM(CASE WHEN py.status = 'cancelled' THEN py.amount END), 0) AS cancelled_amount
            FROM payments py
            LEFT JOIN properties p ON py.property_id = p.id
            WHERE py.payment_date IS NOT NULL
              AND {$this->withinPeriod('py.payment_date')}
              AND ({$scope})
              {$filterSql}
              {$methodSql}
        ", $scopeParams + $filterParams + $methodParams + $this->periodParams($previous));

        $records = (int) ($row['records'] ?? 0);
        $amount  = (float) ($row['amount'] ?? 0);

        return [
            'records'           => $records,
            'amount'            => $amount,
            'received'          => (float) ($row['received'] ?? 0),
            'received_records'  => (int) ($row['received_records'] ?? 0),
            'collected'         => (float) ($row['collected'] ?? 0),
            'cancelled_records' => (int) ($row['cancelled_records'] ?? 0),
            'cancelled_amount'  => (float) ($row['cancelled_amount'] ?? 0),
            // Stated rather than left to the reader's arithmetic, because
            // "more payments" and "more money" are the two things this report
            // exists to tell apart.
            'average'           => $records > 0 ? $amount / $records : null,
        ];
    }

    /**
     * Payment amount and record count over time, on the payment_date axis.
     *
     * Two series from one pass, because they are read together: a month where
     * the amount rose and the count fell is a different story from one where
     * both rose, and asking twice invites the two to disagree.
     *
     * @return array{amount:array,count:array}
     */
    public function paymentActivitySeries(bool $previous = false): array
    {
        [$scope, $scopeParams]      = $this->scope('payment', 'py', 'p');
        [$filterSql, $filterParams] = $this->propertyFilters('p');
        [$methodSql, $methodParams] = $this->paymentMethodFilter('py');

        $bucket = $this->bucket('py.payment_date');

        $rows = $this->rows("
            SELECT {$bucket} AS bucket,
                   COALESCE(SUM(py.amount), 0) AS amount,
                   COUNT(*) AS records
            FROM payments py
            LEFT JOIN properties p ON py.property_id = p.id
            WHERE py.payment_date IS NOT NULL
              AND {$this->withinPeriod('py.payment_date')}
              AND ({$scope})
              {$filterSql}
              {$methodSql}
            GROUP BY bucket
            ORDER BY bucket
        ", $scopeParams + $filterParams + $methodParams + $this->periodParams($previous));

        $from = $previous ? $this->window['prev_from'] : null;
        $to   = $previous ? $this->window['prev_to'] : null;

        return [
            'amount' => $this->fillSeries($rows, 'bucket', 'amount', $from, $to),
            'count'  => $this->fillSeries($rows, 'bucket', 'records', $from, $to),
        ];
    }

    /**
     * The previous period's payment amount, folded onto this period's buckets.
     *
     * @return array<int,array{bucket:string,label:string,total:float}>
     */
    public function paymentActivityComparisonSeries(): array
    {
        [$scope, $scopeParams]      = $this->scope('payment', 'py', 'p');
        [$filterSql, $filterParams] = $this->propertyFilters('p');
        [$methodSql, $methodParams] = $this->paymentMethodFilter('py');

        $rows = $this->rows("
            SELECT DATE(py.payment_date) AS d, COALESCE(SUM(py.amount), 0) AS total
            FROM payments py
            LEFT JOIN properties p ON py.property_id = p.id
            WHERE py.payment_date IS NOT NULL
              AND {$this->withinPeriod('py.payment_date')}
              AND ({$scope})
              {$filterSql}
              {$methodSql}
            GROUP BY d
        ", $scopeParams + $filterParams + $methodParams + $this->periodParams(true));

        $daily = [];
        foreach ($rows as $r) {
            $daily[(string) $r['d']] = (float) $r['total'];
        }

        return $this->foldOntoCurrentBuckets($daily);
    }

    /**
     * Payment records by status.
     *
     * Every status the schema declares is returned, including the ones with
     * no rows. A status breakdown that silently omits "cancelled" reads as a
     * ledger with no cancellations rather than one where none happened in
     * this window, and on a report about transaction integrity that is a
     * meaningful difference. The view decides what to draw.
     *
     * @return array<int,array<string,mixed>>
     */
    public function paymentStatusBreakdown(): array
    {
        [$scope, $scopeParams]      = $this->scope('payment', 'py', 'p');
        [$filterSql, $filterParams] = $this->propertyFilters('p');
        [$methodSql, $methodParams] = $this->paymentMethodFilter('py');

        $rows = $this->rows("
            SELECT COALESCE(py.status, 'pending') AS status,
                   COUNT(*) AS records,
                   COALESCE(SUM(py.amount), 0) AS amount
            FROM payments py
            LEFT JOIN properties p ON py.property_id = p.id
            WHERE py.payment_date IS NOT NULL
              AND {$this->withinPeriod('py.payment_date')}
              AND ({$scope})
              {$filterSql}
              {$methodSql}
            GROUP BY COALESCE(py.status, 'pending')
        ", $scopeParams + $filterParams + $methodParams + $this->periodParams());

        $found = [];
        foreach ($rows as $r) {
            $found[(string) $r['status']] = $r;
        }

        $out = [];
        foreach (REPORT_PAYMENT_STATUSES as $status) {
            $out[] = [
                'status'  => $status,
                'label'   => uiLabel($status),
                'records' => (int) ($found[$status]['records'] ?? 0),
                'amount'  => (float) ($found[$status]['amount'] ?? 0),
            ];
        }

        return $out;
    }

    /**
     * Payment records by method.
     *
     * `payment_method` is nullable in the schema even though it carries a
     * default, so a null is possible and is bucketed as "Not recorded" rather
     * than dropped. Dropping it would quietly remove real money from a chart
     * whose whole purpose is to account for all of it.
     *
     * @return array<int,array<string,mixed>>
     */
    public function paymentMethodBreakdown(): array
    {
        [$scope, $scopeParams]      = $this->scope('payment', 'py', 'p');
        [$filterSql, $filterParams] = $this->propertyFilters('p');
        [$methodSql, $methodParams] = $this->paymentMethodFilter('py');

        $rows = $this->rows("
            SELECT py.payment_method AS method,
                   COUNT(*) AS records,
                   COALESCE(SUM(py.amount), 0) AS amount
            FROM payments py
            LEFT JOIN properties p ON py.property_id = p.id
            WHERE py.payment_date IS NOT NULL
              AND {$this->withinPeriod('py.payment_date')}
              AND ({$scope})
              {$filterSql}
              {$methodSql}
            GROUP BY py.payment_method
            -- Tied amounts broke ties by whatever order the optimiser felt
            -- like; the method name settles it so the chart legend and the
            -- table under it are the same on every refresh.
            ORDER BY amount DESC, py.payment_method ASC
        ", $scopeParams + $filterParams + $methodParams + $this->periodParams());

        $out = [];
        foreach ($rows as $r) {
            $method = $r['method'];
            $out[] = [
                'method'  => $method === null || $method === '' ? '' : (string) $method,
                'label'   => $method === null || $method === '' ? 'Not recorded' : uiLabel((string) $method),
                'records' => (int) $r['records'],
                'amount'  => (float) $r['amount'],
                'missing' => $method === null || $method === '',
            ];
        }

        return $out;
    }

    /**
     * How payments are classified: payment_type against reference_type.
     *
     * The two columns answer different questions — what kind of money this is,
     * and what contract it was taken against — and approved decision 3 settles
     * which one revenue believes. This matrix is where the disagreements
     * become visible instead of being quietly resolved.
     *
     * The mismatch rule is exactly the one reportPaymentMismatches() uses, so
     * the count here and the count in the data-quality panel are the same
     * number arrived at the same way. A deposit, refund, late fee or "other"
     * can legitimately hang off any contract and is never a conflict.
     *
     * @return array<int,array<string,mixed>>
     */
    public function paymentClassificationMatrix(): array
    {
        [$scope, $scopeParams]      = $this->scope('payment', 'py', 'p');
        [$filterSql, $filterParams] = $this->propertyFilters('p');
        [$methodSql, $methodParams] = $this->paymentMethodFilter('py');

        $rows = $this->rows("
            SELECT py.payment_type, py.reference_type,
                   COUNT(*) AS records,
                   COALESCE(SUM(py.amount), 0) AS amount
            FROM payments py
            LEFT JOIN properties p ON py.property_id = p.id
            WHERE py.payment_date IS NOT NULL
              AND {$this->withinPeriod('py.payment_date')}
              AND ({$scope})
              {$filterSql}
              {$methodSql}
            GROUP BY py.payment_type, py.reference_type
            ORDER BY records DESC, amount DESC, py.payment_type ASC, py.reference_type ASC
        ", $scopeParams + $filterParams + $methodParams + $this->periodParams());

        $out = [];
        foreach ($rows as $r) {
            $type = (string) $r['payment_type'];
            $ref  = (string) $r['reference_type'];

            $conflict = ($ref === 'lease' && $type === 'sale')
                || ($ref === 'sale' && $type === 'rent')
                || ($ref === 'reservation' && in_array($type, ['rent', 'sale'], true));

            $out[] = [
                'payment_type'   => $type,
                'reference_type' => $ref,
                'type_label'     => uiLabel($type),
                'ref_label'      => uiLabel($ref),
                'records'        => (int) $r['records'],
                'amount'         => (float) $r['amount'],
                'mismatch'       => $conflict,
            ];
        }

        return $out;
    }

    /**
     * Individual payment records, newest first.
     *
     * Paginated rather than unbounded: the report is a workspace, not a data
     * dump, and a ledger with a hundred thousand rows in it should not try to
     * render them. Count and rows come from the same predicate so the pager
     * cannot promise a page that is not there.
     *
     * @return array<int,array<string,mixed>>
     */
    public function paymentRecords(int $limit = 25, int $offset = 0): array
    {
        [$scope, $scopeParams]      = $this->scope('payment', 'py', 'p');
        [$filterSql, $filterParams] = $this->propertyFilters('p');
        [$methodSql, $methodParams] = $this->paymentMethodFilter('py');

        // Integers this class chose, clamped. Neither reaches SQL from the
        // request: the page number is cast and bounded by the caller.
        $limit  = max(1, min(100, $limit));
        $offset = max(0, min(100000, $offset));

        return $this->rows("
            SELECT py.id, py.payment_code, py.payment_date, py.amount,
                   py.payment_type, py.reference_type, py.reference_id,
                   py.payment_method, py.status, py.receipt_number,
                   py.property_id, p.title AS property_title, p.property_code,
                   c.full_name AS customer_name,
                   u.full_name AS received_by_name
            FROM payments py
            LEFT JOIN properties p ON py.property_id = p.id
            LEFT JOIN customers  c ON py.customer_id = c.id
            LEFT JOIN users      u ON py.received_by = u.id
            WHERE py.payment_date IS NOT NULL
              AND {$this->withinPeriod('py.payment_date')}
              AND ({$scope})
              {$filterSql}
              {$methodSql}
            ORDER BY py.payment_date DESC, py.id DESC
            LIMIT {$limit} OFFSET {$offset}
        ", $scopeParams + $filterParams + $methodParams + $this->periodParams());
    }

    /** How many records the table above is paging through. */
    public function paymentRecordCount(): int
    {
        [$scope, $scopeParams]      = $this->scope('payment', 'py', 'p');
        [$filterSql, $filterParams] = $this->propertyFilters('p');
        [$methodSql, $methodParams] = $this->paymentMethodFilter('py');

        return $this->count("
            SELECT COUNT(*)
            FROM payments py
            LEFT JOIN properties p ON py.property_id = p.id
            WHERE py.payment_date IS NOT NULL
              AND {$this->withinPeriod('py.payment_date')}
              AND ({$scope})
              {$filterSql}
              {$methodSql}
        ", $scopeParams + $filterParams + $methodParams + $this->periodParams());
    }

    /**
     * The future-dated records themselves.
     *
     * futureDatedExcluded() counts them and states the amount; this lists them
     * so somebody can go and look. Deliberately not window-bounded, for the
     * same reason that method is not: a payment dated three months out is an
     * operational item today regardless of which period is on screen.
     *
     * They are not overdue, not failed and not invalid. They are dated ahead,
     * which is a reporting-timing question and nothing worse.
     *
     * @return array<int,array<string,mixed>>
     */
    public function futureDatedPayments(int $limit = 25): array
    {
        [$scope, $scopeParams]      = $this->scope('payment', 'py', 'p');
        [$filterSql, $filterParams] = $this->propertyFilters('p');
        [$methodSql, $methodParams] = $this->paymentMethodFilter('py');
        $types = $this->revenueTypeList();

        $limit = max(1, min(100, $limit));

        return $this->rows("
            SELECT py.id, py.payment_code, py.payment_date, py.amount,
                   py.payment_type, py.reference_type, py.status,
                   p.title AS property_title, p.property_code,
                   c.full_name AS customer_name
            FROM payments py
            LEFT JOIN properties p ON py.property_id = p.id
            LEFT JOIN customers  c ON py.customer_id = c.id
            WHERE py.status = 'paid'
              AND py.payment_date IS NOT NULL
              AND py.payment_date > :w_today
              AND py.payment_type IN ({$types})
              AND ({$scope})
              {$filterSql}
              {$methodSql}
            ORDER BY py.payment_date ASC, py.id ASC
            LIMIT {$limit}
        ", $scopeParams + $filterParams + $methodParams + [':w_today' => $this->window['today']]);
    }

    /**
     * Payment records the ledger cannot fully describe.
     *
     * Only conditions the schema can actually express are counted. Every
     * column checked here is nullable in the live table, so none of these is
     * hypothetical — they are all zero today and none of them is guaranteed
     * to stay that way.
     *
     * Orphaned references are checked against the three tables reference_type
     * can name. That check is real rather than assumed: the audit ran it and
     * found none, and it stays here so the next import is measured too.
     *
     * @return array<string,int>
     */
    public function paymentIntegrityFlags(): array
    {
        [$scope, $scopeParams]      = $this->scope('payment', 'py', 'p');
        [$filterSql, $filterParams] = $this->propertyFilters('p');
        [$methodSql, $methodParams] = $this->paymentMethodFilter('py');

        $orphan = "(
            (py.reference_type = 'lease'       AND NOT EXISTS (SELECT 1 FROM leases pf_l       WHERE pf_l.id = py.reference_id))
            OR (py.reference_type = 'sale'        AND NOT EXISTS (SELECT 1 FROM sales pf_s        WHERE pf_s.id = py.reference_id))
            OR (py.reference_type = 'reservation' AND NOT EXISTS (SELECT 1 FROM reservations pf_r WHERE pf_r.id = py.reference_id))
        )";

        $row = $this->row("
            SELECT COALESCE(SUM(py.payment_method IS NULL OR py.payment_method = ''), 0) AS missing_method,
                   COALESCE(SUM(py.payment_date IS NULL), 0) AS missing_date,
                   COALESCE(SUM(py.property_id IS NULL), 0) AS missing_property,
                   COALESCE(SUM(py.received_by IS NULL), 0) AS missing_received_by,
                   COALESCE(SUM({$orphan}), 0) AS orphaned_reference
            FROM payments py
            LEFT JOIN properties p ON py.property_id = p.id
            WHERE ({$scope})
              {$filterSql}
              {$methodSql}
        ", $scopeParams + $filterParams + $methodParams);

        return [
            'missing_method'      => (int) ($row['missing_method'] ?? 0),
            'missing_date'        => (int) ($row['missing_date'] ?? 0),
            'missing_property'    => (int) ($row['missing_property'] ?? 0),
            'missing_received_by' => (int) ($row['missing_received_by'] ?? 0),
            'orphaned_reference'  => (int) ($row['orphaned_reference'] ?? 0),
        ];
    }

    // ─── Shared internals ──────────────────────────────────────────────

    /**
     * The revenue-type allowlist as a SQL list.
     *
     * Built from REPORT_REVENUE_TYPES, which is our own constant — no request
     * value reaches this, and quoting is by whitelist rather than by escaping.
     */
    private function revenueTypeList(): string
    {
        return "'" . implode("','", REPORT_REVENUE_TYPES) . "'";
    }

    /**
     * The three rules of approved decision 2, as a WHERE clause.
     *
     * Split out from revenueQuery() in Phase 10 so the drill-down behind a
     * revenue tile selects rows through the *same* predicate the tile was
     * summed through. A second copy of these rules, however carefully
     * transcribed, would be a second definition of collected revenue -- and
     * the first time one of them changed, the panel would stop listing the
     * payments its own headline had counted.
     *
     * @return array{0:string,1:array}
     */
    private function revenueWhere(?string $referenceType, bool $previous = false): array
    {
        [$scope, $scopeParams]      = $this->scope('payment', 'py', 'p');
        [$filterSql, $filterParams] = $this->propertyFilters('p');
        // Applied here as well, so a Payments report narrowed to card
        // payments shows a collected-revenue figure that is narrowed the same
        // way. On every other tab this is a no-op: the per-tab allowlist
        // strips the filter before it reaches the model.
        [$methodSql, $methodParams] = $this->paymentMethodFilter('py');

        $params    = $scopeParams + $filterParams + $methodParams + $this->periodParams($previous);
        $streamSql = '';
        if ($referenceType !== null) {
            $streamSql = " AND py.reference_type = :f_stream";
            $params[':f_stream'] = $referenceType;
        }

        $types = $this->revenueTypeList();

        $where = "py.status = 'paid'
              AND py.payment_date IS NOT NULL
              AND py.payment_date <= :w_today
              AND {$this->withinPeriod('py.payment_date')}
              AND py.payment_type IN ({$types})
              AND ({$scope})
              {$streamSql}
              {$filterSql}
              {$methodSql}";

        return [$where, $params];
    }

    /**
     * The one collected-revenue query, shared by every figure that needs it.
     *
     * Written once so the three rules of approved decision 2 cannot be
     * remembered in one place and forgotten in another.
     *
     * @return array{0:string,1:array}
     */
    private function revenueQuery(?string $referenceType, bool $previous = false): array
    {
        [$where, $params] = $this->revenueWhere($referenceType, $previous);

        return ["
            SELECT COALESCE(SUM(py.amount), 0)
            FROM payments py
            LEFT JOIN properties p ON py.property_id = p.id
            WHERE {$where}
        ", $params];
    }

    // ═══ Drill-down ════════════════════════════════════════════════════
    //
    // One question runs through this whole section: which rows produced that
    // figure? Not "which rows look like they should have" -- the actual ones.
    //
    // That is why every method below builds its WHERE clause out of the same
    // predicates the aggregates are built from, rather than restating them.
    // revenueWhere() is approved decision 2; commercialState() is decision 1
    // and 5; the schedule and ledger bases below are decision 4's two
    // ledgers, kept apart here exactly as they are kept apart there. A
    // drill-down is meant to *explain* a KPI, and a panel that reinterprets
    // the figure it was opened from explains nothing.
    //
    // `$mode` and `$key` arrive from the browser and are never trusted. Each
    // method matches its mode against a list written here and returns nothing
    // for anything else: an unrecognised mode is a programming error or an
    // attack, and the safe answer to both is no rows.
    //
    // Every method is bounded. A drill-down is a panel, not a data dump, and
    // the caller pages through it.

    /** Limits this class chose. A request supplies a page number, never these. */
    private function bounds(int $limit, int $offset): array
    {
        return [max(1, min(100, $limit)), max(0, min(100000, $offset))];
    }

    // ─── Payments ──────────────────────────────────────────────────────

    /**
     * The payments-report ledger base: every payment dated in the window,
     * inside the reader's scope and the report's filters.
     *
     * Identical to what paymentActivity(), paymentStatusBreakdown(),
     * paymentMethodBreakdown(), paymentClassificationMatrix() and
     * paymentRecords() all select over -- which is what lets a drill-down
     * from any of those five land on the rows that particular figure counted.
     *
     * @return array{0:string,1:array}
     */
    private function paymentLedgerWhere(): array
    {
        [$scope, $scopeParams]      = $this->scope('payment', 'py', 'p');
        [$filterSql, $filterParams] = $this->propertyFilters('p');
        [$methodSql, $methodParams] = $this->paymentMethodFilter('py');

        return [
            "py.payment_date IS NOT NULL
              AND {$this->withinPeriod('py.payment_date')}
              AND ({$scope})
              {$filterSql}
              {$methodSql}",
            $scopeParams + $filterParams + $methodParams + $this->periodParams(),
        ];
    }

    /**
     * The predicate behind one payments figure.
     *
     * `collected` and `stream` come from revenueWhere() rather than from the
     * ledger base, and the difference is the whole of approved decision 2:
     * collected revenue is a narrower set than "payments in this window", and
     * a drill-down that showed the wider one would be listing money the tile
     * had deliberately excluded.
     *
     * @return array{0:string,1:array}|null null when the mode is not one of ours
     */
    private function paymentDrillWhere(string $mode, string $key): ?array
    {
        // Revenue and its streams are decision 2's set, not the ledger's.
        if ($mode === 'collected') {
            return $this->revenueWhere(null);
        }
        if ($mode === 'stream') {
            return in_array($key, [REPORT_STREAM_RENTAL, REPORT_STREAM_SALE, 'reservation'], true)
                ? $this->revenueWhere($key)
                : null;
        }

        [$base, $params] = $this->paymentLedgerWhere();

        switch ($mode) {
            case 'all':
                return [$base, $params];

            // Every paid record dated today or earlier, whatever its type --
            // the "money received" tile, which is deliberately wider than
            // collected revenue.
            case 'received':
                return [$base . " AND py.status = 'paid' AND py.payment_date <= :w_today", $params];

            case 'cancelled':
                return [$base . " AND py.status = 'cancelled'", $params];

            case 'status':
                if (!in_array($key, REPORT_PAYMENT_STATUSES, true)) {
                    return null;
                }
                $params[':d_status'] = $key;
                return [$base . " AND COALESCE(py.status, 'pending') = :d_status", $params];

            case 'method':
                // '' is a real answer here: the breakdown reports records with
                // no method recorded as their own row rather than folding
                // them into 'other', so the drill-down has to be able to
                // select them.
                if ($key === '') {
                    return [$base . " AND (py.payment_method IS NULL OR py.payment_method = '')", $params];
                }
                if (!in_array($key, REPORT_PAYMENT_METHODS, true)) {
                    return null;
                }
                $params[':d_method'] = $key;
                return [$base . " AND py.payment_method = :d_method", $params];

            // "rent|lease" -- one cell of the classification matrix.
            case 'class':
                $parts = explode('|', $key);
                if (count($parts) !== 2) {
                    return null;
                }
                $params[':d_type'] = $parts[0];
                $params[':d_ref']  = $parts[1];
                return [$base . " AND py.payment_type = :d_type AND py.reference_type = :d_ref", $params];

            // The rows where payment_type and reference_type name different
            // kinds of contract. The same three pairs reportPaymentMismatches()
            // counts -- a deposit, a refund or a late fee can legitimately
            // hang off either kind and is not a conflict.
            //
            // Like 'future', this cannot be built on the ledger base. The
            // detector is not window-bounded and carries no property or
            // method filter: a payment filed against the wrong kind of
            // contract is wrong whichever period is on screen, and the panel
            // has to list the same rows the count counted. Building it on the
            // window made a tile reading 1 open a panel reading none.
            case 'mismatch':
                [$mScope, $mParams] = $this->scope('payment', 'py', 'p');

                return [
                    "py.status <> 'cancelled'
                      AND (
                        (py.reference_type = 'lease' AND py.payment_type = 'sale')
                        OR (py.reference_type = 'sale' AND py.payment_type = 'rent')
                        OR (py.reference_type = 'reservation' AND py.payment_type IN ('rent','sale'))
                      )
                      AND ({$mScope})",
                    $mParams,
                ];

            // Paid, but dated after today.
            //
            // The one arm that cannot be built on the ledger base, and the
            // reason is the whole point of the figure: these records are
            // dated *after* the window ends, so a predicate bounded by the
            // window excludes every one of them and the panel behind a
            // $500 tile came back empty. futureDatedExcluded() is not
            // window-bounded either, and carries no method filter, so this
            // transcribes it rather than the ledger.
            case 'future':
                [$fScope, $fParams]   = $this->scope('payment', 'py', 'p');
                [$fFilter, $fFilterP] = $this->propertyFilters('p');
                $fTypes = $this->revenueTypeList();

                return [
                    "py.status = 'paid'
                      AND py.payment_date IS NOT NULL
                      AND py.payment_date > :w_today
                      AND py.payment_type IN ({$fTypes})
                      AND ({$fScope})
                      {$fFilter}",
                    $fParams + $fFilterP + [':w_today' => $this->window['today']],
                ];

            // One bucket of the activity chart. The grain is the window's,
            // never the request's; only the bucket key is bound.
            case 'bucket':
                $params[':d_bucket'] = $key;
                return [$base . " AND {$this->bucket('py.payment_date')} = :d_bucket", $params];

            // One property's collected revenue -- the figure the top-earners
            // table ranks on, so this uses decision 2's set and not the
            // ledger's.
            case 'property':
                $id = (int) $key;
                if ($id <= 0) {
                    return null;
                }
                [$where, $revParams] = $this->revenueWhere(null);
                $revParams[':d_property'] = $id;
                return [$where . " AND py.property_id = :d_property", $revParams];

            // One revenue bucket, on decision 2's set rather than the
            // ledger's, so it reconciles with the revenue chart above it.
            case 'revenue_bucket':
                [$where, $revParams] = $this->revenueWhere(null);
                $revParams[':d_bucket'] = $key;
                return [$where . " AND {$this->bucket('py.payment_date')} = :d_bucket", $revParams];
        }

        return null;
    }

    /**
     * Payment records behind one figure.
     *
     * The column list is paymentRecords()' own, so the drill-down table and
     * the report's own record table show a row the same way.
     *
     * @return array<int,array<string,mixed>>
     */
    public function drillPayments(string $mode, string $key, int $limit = 25, int $offset = 0): array
    {
        $resolved = $this->paymentDrillWhere($mode, $key);
        if ($resolved === null) {
            return [];
        }

        [$where, $params]  = $resolved;
        [$limit, $offset]  = $this->bounds($limit, $offset);

        return $this->rows("
            SELECT py.id, py.payment_code, py.payment_date, py.amount,
                   py.payment_type, py.reference_type, py.reference_id,
                   py.payment_method, py.status, py.receipt_number,
                   py.property_id, p.title AS property_title, p.property_code,
                   c.full_name AS customer_name,
                   u.full_name AS received_by_name
            FROM payments py
            LEFT JOIN properties p ON py.property_id = p.id
            LEFT JOIN customers  c ON py.customer_id = c.id
            LEFT JOIN users      u ON py.received_by = u.id
            WHERE {$where}
            ORDER BY py.payment_date DESC, py.id DESC
            LIMIT {$limit} OFFSET {$offset}
        ", $params);
    }

    /**
     * How many records and how much money sit behind one figure.
     *
     * The amount is what makes a drill-down checkable: the panel prints it
     * beside the figure it was opened from, and the two either agree or the
     * reader has found something worth reporting.
     *
     * @return array{records:int,amount:float}
     */
    public function drillPaymentsTotal(string $mode, string $key): array
    {
        $resolved = $this->paymentDrillWhere($mode, $key);
        if ($resolved === null) {
            return ['records' => 0, 'amount' => 0.0];
        }

        [$where, $params] = $resolved;

        $row = $this->row("
            SELECT COUNT(*) AS records, COALESCE(SUM(py.amount), 0) AS amount
            FROM payments py
            LEFT JOIN properties p ON py.property_id = p.id
            WHERE {$where}
        ", $params);

        return ['records' => (int) ($row['records'] ?? 0), 'amount' => (float) ($row['amount'] ?? 0)];
    }

    // ─── Rent schedules ────────────────────────────────────────────────

    /**
     * The rent-ledger base: scheduled instalments on tenancies in scope.
     *
     * This is the *other* ledger. rentLedger() reports expected, settled,
     * outstanding and arrears from payment_schedules, and approved decision 4
     * forbids adding them to anything out of `payments`. Drill-downs keep the
     * two apart the same way: nothing in this method touches the payments
     * table, and nothing in paymentDrillWhere() touches schedules.
     *
     * @return array{0:string,1:array}
     */
    private function scheduleWhere(): array
    {
        [$leaseScope, $leaseParams] = $this->scope('lease', 'l', 'p');
        [$filterSql, $filterParams] = $this->propertyFilters('p');

        return [
            "({$leaseScope}) {$filterSql}",
            $leaseParams + $filterParams + $this->periodParams(),
        ];
    }

    /**
     * The predicate behind one rent-ledger figure.
     *
     * Each arm is lifted from the matching CASE in rentLedger(), including
     * which of them are window-bounded and which are running balances.
     * Expected and settled are bounded by the due date; outstanding, arrears
     * and not-yet-due are the state of the schedule today and are not.
     *
     * @return array{0:string,1:array}|null
     */
    private function scheduleDrillWhere(string $mode, string $key): ?array
    {
        [$base, $params] = $this->scheduleWhere();
        $inPeriod = $this->withinPeriod('ps.due_date');

        switch ($mode) {
            case 'expected':
                return [$base . " AND {$inPeriod}", $params];

            case 'settled':
                return [$base . " AND ps.status = 'paid' AND {$inPeriod}", $params];

            // Running balances. No period bound, exactly as rentLedger()
            // computes them -- the schedule records the state a row is in
            // now, not the state it was in in July.
            case 'outstanding':
                return [$base . " AND ps.status <> 'paid'", $params];

            case 'arrears':
                return [$base . " AND ps.status IN ('overdue','partial')", $params];

            case 'overdue':
                return [$base . " AND ps.status = 'overdue'", $params];

            case 'not_yet_due':
                return [$base . " AND ps.status = 'pending'", $params];

            case 'bucket':
                $params[':d_bucket'] = $key;
                return [$base . " AND {$this->bucket('ps.due_date')} = :d_bucket", $params];

            case 'settled_bucket':
                $params[':d_bucket'] = $key;
                return [
                    $base . " AND ps.status = 'paid' AND {$this->bucket('ps.due_date')} = :d_bucket",
                    $params,
                ];

            case 'property':
                $id = (int) $key;
                if ($id <= 0) {
                    return null;
                }
                $params[':d_property'] = $id;
                return [$base . " AND p.id = :d_property AND {$inPeriod}", $params];

            case 'lease':
                $id = (int) $key;
                if ($id <= 0) {
                    return null;
                }
                $params[':d_lease'] = $id;
                return [$base . " AND l.id = :d_lease", $params];
        }

        return null;
    }

    /** @return array<int,array<string,mixed>> */
    public function drillSchedules(string $mode, string $key, int $limit = 25, int $offset = 0): array
    {
        $resolved = $this->scheduleDrillWhere($mode, $key);
        if ($resolved === null) {
            return [];
        }

        [$where, $params] = $resolved;
        [$limit, $offset] = $this->bounds($limit, $offset);

        return $this->rows("
            SELECT ps.id, ps.due_date, ps.paid_date, ps.amount, ps.penalty, ps.status,
                   ps.amount + ps.penalty AS due_total,
                   DATEDIFF(:w_today, ps.due_date) AS days_late,
                   l.id AS lease_id, l.lease_code,
                   p.id AS property_id, p.title AS property_title, p.property_code,
                   c.full_name AS tenant_name
            FROM payment_schedules ps
            JOIN leases l     ON ps.lease_id = l.id
            JOIN properties p ON l.property_id = p.id
            LEFT JOIN customers c ON l.customer_id = c.id
            WHERE {$where}
            ORDER BY ps.due_date DESC, ps.id DESC
            LIMIT {$limit} OFFSET {$offset}
        ", $params + [':w_today' => $this->window['today']]);
    }

    /**
     * Records and money behind one rent-ledger figure.
     *
     * `settled` sums ps.amount and everything else sums amount + penalty,
     * which looks inconsistent and is not: rentLedger() does exactly this.
     * A penalty is owed but was never scheduled, so it belongs in what is
     * expected and outstanding and not in what was settled.
     *
     * @return array{records:int,amount:float}
     */
    public function drillSchedulesTotal(string $mode, string $key): array
    {
        $resolved = $this->scheduleDrillWhere($mode, $key);
        if ($resolved === null) {
            return ['records' => 0, 'amount' => 0.0];
        }

        [$where, $params] = $resolved;
        $sum = in_array($mode, ['settled', 'settled_bucket'], true)
            ? 'ps.amount'
            : 'ps.amount + ps.penalty';

        $row = $this->row("
            SELECT COUNT(*) AS records, COALESCE(SUM({$sum}), 0) AS amount
            FROM payment_schedules ps
            JOIN leases l     ON ps.lease_id = l.id
            JOIN properties p ON l.property_id = p.id
            WHERE {$where}
        ", $params);

        return ['records' => (int) ($row['records'] ?? 0), 'amount' => (float) ($row['amount'] ?? 0)];
    }

    // ─── Properties ────────────────────────────────────────────────────

    /**
     * The predicate behind one portfolio figure.
     *
     * Occupancy's arms select from rentableWhere() and the rest from the
     * approved-and-unarchived register, because those are the two different
     * denominators the report uses and conflating them is the mistake the
     * Phase 0 audit found. Nothing here reads properties.status as a
     * commercial state.
     *
     * @return array{0:string,1:array}|null
     */
    private function propertyDrillWhere(string $mode, string $key): ?array
    {
        [$scope, $scopeParams]      = $this->scope('property', 'p');
        [$filterSql, $filterParams] = $this->propertyFilters('p');

        $params   = $scopeParams + $filterParams + [':w_today' => $this->window['today']];
        $state    = $this->commercialState('dp');
        $rentable = $this->rentableWhere('dr');
        $approved = "p.is_archived = 0 AND p.approval_status = 'approved'";
        $tail     = " AND ({$scope}) {$filterSql}";

        switch ($mode) {
            // Approved decision 1's denominator and its two halves.
            case 'rentable':
                return [$rentable . $tail, $params];
            case 'occupied':
                return [$rentable . " AND {$state['leased']}" . $tail, $params];
            case 'vacant':
                return [$rentable . " AND NOT {$state['leased']}" . $tail, $params];

            // Commercial state across approved inventory -- portfolioState().
            case 'sold':
                return [$approved . " AND {$state['sold']}" . $tail, $params];
            case 'state_occupied':
                return [$approved . " AND NOT {$state['sold']} AND {$state['leased']}" . $tail, $params];
            case 'reserved':
                return [
                    $approved . " AND NOT {$state['sold']} AND NOT {$state['leased']}"
                    . " AND {$state['held']}" . $tail,
                    $params,
                ];
            case 'available':
                return [
                    $approved . " AND NOT {$state['sold']} AND NOT {$state['leased']}"
                    . " AND NOT {$state['held']}" . $tail,
                    $params,
                ];

            // Lifecycle -- inventory()'s own arms, on the whole register.
            case 'approved':
                return [$approved . $tail, $params];
            case 'pending':
                return ["p.is_archived = 0 AND p.approval_status = 'pending'" . $tail, $params];
            case 'rejected':
                return ["p.is_archived = 0 AND p.approval_status = 'rejected'" . $tail, $params];
            case 'withdrawn':
                return ["p.is_archived = 0 AND p.status = 'inactive'" . $tail, $params];
            case 'archived':
                return ["p.is_archived = 1" . $tail, $params];
            case 'all':
                return ["p.is_archived = 0" . $tail, $params];

            case 'category':
                if (!in_array($key, REPORT_CATEGORIES, true)) {
                    return null;
                }
                $params[':d_category'] = $key;
                return [$approved . " AND p.category = :d_category" . $tail, $params];

            case 'intent':
                if (!in_array($key, ['rent', 'sale', 'both'], true)) {
                    return null;
                }
                $params[':d_intent'] = $key;
                return [$approved . " AND p.property_type = :d_intent" . $tail, $params];

            case 'location':
                // Measured against the locations this reader's own portfolio
                // actually holds, which is the same allowlist the filter is
                // validated through.
                if (!in_array($key, reportLocationOptions(), true)) {
                    return null;
                }
                $params[':d_location'] = $key;
                return [$approved . " AND p.location = :d_location" . $tail, $params];

            case 'property':
                $id = (int) $key;
                if ($id <= 0) {
                    return null;
                }
                $params[':d_property'] = $id;
                return ["p.id = :d_property" . $tail, $params];
        }

        return null;
    }

    /**
     * Properties behind one figure, with the revenue each collected in the
     * window.
     *
     * Same columns portfolioTable() shows, and the revenue subquery is the
     * same correlated one -- a join to a one-to-many would multiply a
     * property row per payment and quietly break the count the panel is
     * being checked against.
     *
     * @return array<int,array<string,mixed>>
     */
    public function drillProperties(string $mode, string $key, int $limit = 25, int $offset = 0): array
    {
        $resolved = $this->propertyDrillWhere($mode, $key);
        if ($resolved === null) {
            return [];
        }

        [$where, $params]       = $resolved;
        [$limit, $offset]       = $this->bounds($limit, $offset);
        [$payScope, $payParams] = $this->scope('payment', 'py', 'pp');
        $state                  = $this->commercialState('dl');
        $types                  = $this->revenueTypeList();

        $revenue = "(SELECT COALESCE(SUM(py.amount), 0)
                       FROM payments py
                       LEFT JOIN properties pp ON py.property_id = pp.id
                      WHERE py.property_id = p.id
                        AND py.status = 'paid'
                        AND py.payment_date IS NOT NULL
                        AND py.payment_date <= :w_today
                        AND py.payment_date BETWEEN :w_from AND :w_to
                        AND py.payment_type IN ({$types})
                        AND ({$payScope}))";

        return $this->rows("
            SELECT p.id, p.property_code, p.title, p.category, p.property_type,
                   p.location, p.status AS recorded_status, p.approval_status,
                   p.agent_id, u.full_name AS agent_name,
                   {$state['sold']}   AS is_sold,
                   {$state['leased']} AS is_occupied,
                   {$state['held']}   AS is_reserved,
                   {$revenue}         AS revenue
            FROM properties p
            LEFT JOIN users u ON p.agent_id = u.id
            WHERE {$where}
            ORDER BY revenue DESC, p.property_code ASC
            LIMIT {$limit} OFFSET {$offset}
        ", $params + $payParams + $this->periodParams());
    }

    public function drillPropertiesCount(string $mode, string $key): int
    {
        $resolved = $this->propertyDrillWhere($mode, $key);
        if ($resolved === null) {
            return 0;
        }

        [$where, $params] = $resolved;

        return $this->count("SELECT COUNT(*) FROM properties p WHERE {$where}", $params);
    }

    // ─── Leases ────────────────────────────────────────────────────────

    /**
     * The predicate behind one tenancy figure.
     *
     * The expiry arms are leaseExpiryBuckets()' own boundaries. "Expired"
     * means still flagged active with an end date in the past, which is a
     * record that has not caught up rather than a tenancy that is running.
     *
     * @return array{0:string,1:array}|null
     */
    private function leaseDrillWhere(string $mode, string $key): ?array
    {
        [$scope, $scopeParams]      = $this->scope('lease', 'l', 'p');
        [$filterSql, $filterParams] = $this->propertyFilters('p');

        $params = $scopeParams + $filterParams + [':w_today' => $this->window['today']];
        $tail   = " AND ({$scope}) {$filterSql}";
        $live   = "l.status = 'active' AND l.end_date >= :w_today";

        switch ($mode) {
            case 'active':
                return [$live . $tail, $params];

            case 'attention':
                return [
                    "l.status = 'active' AND l.end_date <= DATE_ADD(:w_today, INTERVAL 60 DAY)" . $tail,
                    $params,
                ];

            // leaseExpiryBuckets()' five bands, transcribed. They are
            // *exclusive*: "within 30 days" starts where "within 7" stops, so
            // the five add up to the active book exactly once. Reading them
            // as cumulative — the obvious mistake, and the one this made
            // before the reconciliation caught it — puts the same lease in
            // three panels and makes none of them agree with its own tile.
            case 'expiring':
                $bands = [
                    'expired' => "l.end_date < :w_today",
                    'd7'      => "l.end_date >= :w_today
                                  AND l.end_date <= DATE_ADD(:w_today, INTERVAL 7 DAY)",
                    'd30'     => "l.end_date > DATE_ADD(:w_today, INTERVAL 7 DAY)
                                  AND l.end_date <= DATE_ADD(:w_today, INTERVAL 30 DAY)",
                    'd60'     => "l.end_date > DATE_ADD(:w_today, INTERVAL 30 DAY)
                                  AND l.end_date <= DATE_ADD(:w_today, INTERVAL 60 DAY)",
                    'beyond'  => "l.end_date > DATE_ADD(:w_today, INTERVAL 60 DAY)",
                ];
                if (!isset($bands[$key])) {
                    return null;
                }
                return ["l.status = 'active' AND {$bands[$key]}" . $tail, $params];

            // The "expiring soon" tile, which is the three bands inside sixty
            // days added together — and deliberately not the fourth: a lease
            // already past its end date has not "expired soon", it has gone.
            case 'expiring_soon':
                return [
                    "l.status = 'active' AND l.end_date >= :w_today"
                    . " AND l.end_date <= DATE_ADD(:w_today, INTERVAL 60 DAY)" . $tail,
                    $params,
                ];

            case 'property':
                $id = (int) $key;
                if ($id <= 0) {
                    return null;
                }
                $params[':d_property'] = $id;
                return [$live . " AND p.id = :d_property" . $tail, $params];

            case 'agent':
                $id = (int) $key;
                if ($id <= 0) {
                    return null;
                }
                $params[':d_agent'] = $id;
                return [$live . " AND p.agent_id = :d_agent" . $tail, $params];

            // Leases written in the window, by whoever created the record --
            // agentPerformance()'s own attribution for that column.
            case 'created_by':
                $id = (int) $key;
                if ($id <= 0) {
                    return null;
                }
                $params[':d_agent'] = $id;
                return [
                    "l.created_by = :d_agent AND {$this->withinPeriod('DATE(l.created_at)')}" . $tail,
                    $params + $this->periodParams(),
                ];
        }

        return null;
    }

    /** @return array<int,array<string,mixed>> */
    public function drillLeases(string $mode, string $key, int $limit = 25, int $offset = 0): array
    {
        $resolved = $this->leaseDrillWhere($mode, $key);
        if ($resolved === null) {
            return [];
        }

        [$where, $params] = $resolved;
        [$limit, $offset] = $this->bounds($limit, $offset);

        return $this->rows("
            SELECT l.id, l.lease_code, l.start_date, l.end_date, l.rent_amount, l.status,
                   DATEDIFF(l.end_date, :w_today) AS days_left,
                   p.id AS property_id, p.title AS property_title, p.property_code,
                   c.full_name AS tenant_name,
                   COALESCE(SUM(CASE WHEN ps.status <> 'paid'
                                     THEN ps.amount + ps.penalty END), 0) AS outstanding,
                   COALESCE(SUM(CASE WHEN ps.status IN ('overdue','partial')
                                     THEN ps.amount + ps.penalty END), 0) AS arrears
            FROM leases l
            JOIN properties p ON l.property_id = p.id
            LEFT JOIN customers c ON l.customer_id = c.id
            LEFT JOIN payment_schedules ps ON ps.lease_id = l.id
            WHERE {$where}
            GROUP BY l.id, l.lease_code, l.start_date, l.end_date, l.rent_amount, l.status,
                     p.id, p.title, p.property_code, c.full_name
            ORDER BY l.end_date ASC, l.id ASC
            LIMIT {$limit} OFFSET {$offset}
        ", $params);
    }

    /** @return array{records:int,amount:float} rent roll, not a balance */
    public function drillLeasesTotal(string $mode, string $key): array
    {
        $resolved = $this->leaseDrillWhere($mode, $key);
        if ($resolved === null) {
            return ['records' => 0, 'amount' => 0.0];
        }

        [$where, $params] = $resolved;

        $row = $this->row("
            SELECT COUNT(*) AS records, COALESCE(SUM(l.rent_amount), 0) AS amount
            FROM leases l
            JOIN properties p ON l.property_id = p.id
            WHERE {$where}
        ", $params);

        return ['records' => (int) ($row['records'] ?? 0), 'amount' => (float) ($row['amount'] ?? 0)];
    }

    // ─── Sales ─────────────────────────────────────────────────────────

    /**
     * The predicate behind one sales figure.
     *
     * `completed` carries the `sale_date <= today` that salesSummary() puts
     * on it, and that clause is load-bearing: a deal marked completed with a
     * date next month is counted by neither, and the summary reports those
     * separately as future_completed rather than folding them in.
     *
     * @return array{0:string,1:array}|null
     */
    private function saleDrillWhere(string $mode, string $key): ?array
    {
        [$scope, $scopeParams]      = $this->scope('sale', 's', 'p');
        [$filterSql, $filterParams] = $this->propertyFilters('p');

        $params = $scopeParams + $filterParams + $this->periodParams();
        $base   = "s.sale_date IS NOT NULL AND {$this->withinPeriod('s.sale_date')}"
                . " AND ({$scope}) {$filterSql}";

        switch ($mode) {
            case 'all':
                return [$base, $params];

            case 'completed':
                return [$base . " AND s.status = 'completed' AND s.sale_date <= :w_today", $params];

            case 'future_completed':
                return [$base . " AND s.status = 'completed' AND s.sale_date > :w_today", $params];

            case 'status':
                if (!in_array($key, ['pending', 'completed', 'cancelled'], true)) {
                    return null;
                }
                $params[':d_status'] = $key;
                return [$base . " AND s.status = :d_status", $params];

            case 'bucket':
                $params[':d_bucket'] = $key;
                return [$base . " AND {$this->bucket('s.sale_date')} = :d_bucket", $params];

            case 'completed_bucket':
                $params[':d_bucket'] = $key;
                return [
                    $base . " AND s.status = 'completed' AND s.sale_date <= :w_today"
                    . " AND {$this->bucket('s.sale_date')} = :d_bucket",
                    $params,
                ];

            case 'category':
                if (!in_array($key, REPORT_CATEGORIES, true)) {
                    return null;
                }
                $params[':d_category'] = $key;
                return [$base . " AND p.category = :d_category", $params];

            case 'property':
                $id = (int) $key;
                if ($id <= 0) {
                    return null;
                }
                $params[':d_property'] = $id;
                return [$base . " AND p.id = :d_property", $params];

            case 'agent':
                $id = (int) $key;
                if ($id <= 0) {
                    return null;
                }
                $params[':d_agent'] = $id;
                return [
                    $base . " AND s.agent_id = :d_agent AND s.status = 'completed'"
                    . " AND s.sale_date <= :w_today",
                    $params,
                ];
        }

        return null;
    }

    /** @return array<int,array<string,mixed>> */
    public function drillSales(string $mode, string $key, int $limit = 25, int $offset = 0): array
    {
        $resolved = $this->saleDrillWhere($mode, $key);
        if ($resolved === null) {
            return [];
        }

        [$where, $params] = $resolved;
        [$limit, $offset] = $this->bounds($limit, $offset);

        return $this->rows("
            SELECT s.id, s.sale_code, s.sale_date, s.sale_amount, s.commission_amount,
                   s.payment_type, s.status,
                   p.id AS property_id, p.title AS property_title, p.property_code, p.category,
                   c.full_name AS buyer_name,
                   u.full_name AS agent_name
            FROM sales s
            JOIN properties p ON s.property_id = p.id
            LEFT JOIN customers c ON s.customer_id = c.id
            LEFT JOIN users u ON s.agent_id = u.id
            WHERE {$where}
            ORDER BY s.sale_date DESC, s.id DESC
            LIMIT {$limit} OFFSET {$offset}
        ", $params);
    }

    /** @return array{records:int,amount:float} contract value, never cash */
    public function drillSalesTotal(string $mode, string $key): array
    {
        $resolved = $this->saleDrillWhere($mode, $key);
        if ($resolved === null) {
            return ['records' => 0, 'amount' => 0.0];
        }

        [$where, $params] = $resolved;

        $row = $this->row("
            SELECT COUNT(*) AS records, COALESCE(SUM(s.sale_amount), 0) AS amount
            FROM sales s
            JOIN properties p ON s.property_id = p.id
            WHERE {$where}
        ", $params);

        return ['records' => (int) ($row['records'] ?? 0), 'amount' => (float) ($row['amount'] ?? 0)];
    }

    // ─── Reservations ──────────────────────────────────────────────────

    /**
     * The predicate behind one reservation figure.
     *
     * Live means unexpired whatever the status column says, and lapsed means
     * the opposite -- still marked active, expiry date gone by. That is
     * reservationSummary()'s distinction and the reason the two are never
     * added. Current state, so none of these is window-bounded.
     *
     * @return array{0:string,1:array}|null
     */
    private function reservationDrillWhere(string $mode, string $key): ?array
    {
        [$scope, $scopeParams]      = $this->scope('reservation', 'r', 'p');
        [$filterSql, $filterParams] = $this->propertyFilters('p');

        $params = $scopeParams + $filterParams + [':w_today' => $this->window['today']];
        $tail   = " AND ({$scope}) {$filterSql}";
        $held   = "r.status IN ('active','confirmed')";

        switch ($mode) {
            case 'all':
                return ["1 = 1" . $tail, $params];
            case 'live':
                return [$held . " AND r.expiry_date >= :w_today" . $tail, $params];
            case 'lapsed':
                return [$held . " AND r.expiry_date < :w_today" . $tail, $params];
            case 'expired':
                return ["r.status = 'expired'" . $tail, $params];
            case 'cancelled':
                return ["r.status = 'cancelled'" . $tail, $params];
            case 'property':
                $id = (int) $key;
                if ($id <= 0) {
                    return null;
                }
                $params[':d_property'] = $id;
                return ["p.id = :d_property" . $tail, $params];
        }

        return null;
    }

    /** @return array<int,array<string,mixed>> */
    public function drillReservations(string $mode, string $key, int $limit = 25, int $offset = 0): array
    {
        $resolved = $this->reservationDrillWhere($mode, $key);
        if ($resolved === null) {
            return [];
        }

        [$where, $params] = $resolved;
        [$limit, $offset] = $this->bounds($limit, $offset);

        return $this->rows("
            SELECT r.id, r.reservation_code, r.reservation_date, r.expiry_date,
                   r.deposit_amount, r.status,
                   DATEDIFF(r.expiry_date, :w_today) AS days_left,
                   p.id AS property_id, p.title AS property_title, p.property_code,
                   c.full_name AS customer_name
            FROM reservations r
            JOIN properties p ON r.property_id = p.id
            LEFT JOIN customers c ON r.customer_id = c.id
            WHERE {$where}
            ORDER BY r.expiry_date ASC, r.id ASC
            LIMIT {$limit} OFFSET {$offset}
        ", $params);
    }

    /** @return array{records:int,amount:float} deposits held, never revenue */
    public function drillReservationsTotal(string $mode, string $key): array
    {
        $resolved = $this->reservationDrillWhere($mode, $key);
        if ($resolved === null) {
            return ['records' => 0, 'amount' => 0.0];
        }

        [$where, $params] = $resolved;

        $row = $this->row("
            SELECT COUNT(*) AS records, COALESCE(SUM(r.deposit_amount), 0) AS amount
            FROM reservations r
            JOIN properties p ON r.property_id = p.id
            WHERE {$where}
        ", $params);

        return ['records' => (int) ($row['records'] ?? 0), 'amount' => (float) ($row['amount'] ?? 0)];
    }

    // ─── Maintenance ───────────────────────────────────────────────────

    /**
     * The predicate behind one maintenance figure.
     *
     * The split that runs through the whole report runs through here too:
     * `raised` and `completed` are period figures on created_at and
     * completion_date, and everything describing the queue is current state
     * with no window on it at all.
     *
     * @return array{0:string,1:array}|null
     */
    private function maintenanceDrillWhere(string $mode, string $key): ?array
    {
        [$scope, $scopeParams]      = $this->scope('maintenance', 'm', 'p');
        [$filterSql, $filterParams] = $this->propertyFilters('p');

        $params = $scopeParams + $filterParams + $this->periodParams();
        $tail   = " AND ({$scope}) {$filterSql}";
        $open   = "'" . implode("','", self::MAINTENANCE_OPEN) . "'";
        $age    = "DATEDIFF(:w_today, DATE(m.created_at))";

        switch ($mode) {
            case 'all':
                return ["1 = 1" . $tail, $params];

            case 'raised':
                return [$this->withinPeriod('DATE(m.created_at)') . $tail, $params];

            case 'raised_urgent':
                return [
                    $this->withinPeriod('DATE(m.created_at)')
                    . " AND m.priority IN ('high','urgent')" . $tail,
                    $params,
                ];

            case 'completed':
                return [
                    "m.status = 'completed' AND m.completion_date IS NOT NULL"
                    . " AND m.completion_date <= :w_today"
                    . " AND {$this->withinPeriod('m.completion_date')}" . $tail,
                    $params,
                ];

            case 'open':
                return ["m.status IN ({$open})" . $tail, $params];
            case 'open_urgent':
                return ["m.status IN ({$open}) AND m.priority IN ('high','urgent')" . $tail, $params];
            case 'open_unassigned':
                return ["m.status IN ({$open}) AND m.assigned_to IS NULL" . $tail, $params];
            case 'in_progress':
                return ["m.status = 'in_progress'" . $tail, $params];
            case 'awaiting':
                return ["m.status IN ('new','under_review')" . $tail, $params];
            case 'completed_ever':
                return ["m.status = 'completed'" . $tail, $params];

            case 'status':
                if (!in_array($key, self::MAINTENANCE_STATUSES, true)) {
                    return null;
                }
                $params[':d_status'] = $key;
                return ["m.status = :d_status" . $tail, $params];

            case 'priority':
                if (!in_array($key, ['urgent', 'high', 'medium', 'low'], true)) {
                    return null;
                }
                $params[':d_priority'] = $key;
                return ["m.status IN ({$open}) AND m.priority = :d_priority" . $tail, $params];

            // maintenanceAgeing()'s own four bands, on the open queue.
            case 'age':
                $bands = [
                    'd3'  => "{$age} BETWEEN 0 AND 3",
                    'd7'  => "{$age} BETWEEN 4 AND 7",
                    'd14' => "{$age} BETWEEN 8 AND 14",
                    'd15' => "{$age} > 14",
                ];
                if (!isset($bands[$key])) {
                    return null;
                }
                return ["m.status IN ({$open}) AND {$bands[$key]}" . $tail, $params];

            case 'bucket':
                $params[':d_bucket'] = $key;
                return [$this->bucket('DATE(m.created_at)') . " = :d_bucket" . $tail, $params];

            case 'completed_bucket':
                $params[':d_bucket'] = $key;
                return [
                    "m.status = 'completed' AND m.completion_date IS NOT NULL"
                    . " AND {$this->bucket('m.completion_date')} = :d_bucket" . $tail,
                    $params,
                ];

            // Only requests that can actually carry a resolution time. The
            // report refuses to average over anything else and so does this.
            case 'resolved':
                return [
                    "m.status = 'completed' AND m.completion_date IS NOT NULL"
                    . " AND m.completion_date >= DATE(m.created_at)" . $tail,
                    $params,
                ];

            case 'property':
                $id = (int) $key;
                if ($id <= 0) {
                    return null;
                }
                $params[':d_property'] = $id;
                return ["p.id = :d_property" . $tail, $params];
        }

        return null;
    }

    /** @return array<int,array<string,mixed>> */
    public function drillMaintenance(string $mode, string $key, int $limit = 25, int $offset = 0): array
    {
        $resolved = $this->maintenanceDrillWhere($mode, $key);
        if ($resolved === null) {
            return [];
        }

        [$where, $params] = $resolved;
        [$limit, $offset] = $this->bounds($limit, $offset);

        return $this->rows("
            SELECT m.id, m.request_code, m.issue_type, m.priority, m.status,
                   DATE(m.created_at) AS raised_on, m.completion_date,
                   m.cost_estimate, m.actual_cost,
                   DATEDIFF(:w_today, DATE(m.created_at)) AS age_days,
                   CASE WHEN m.completion_date IS NOT NULL
                        THEN DATEDIFF(m.completion_date, DATE(m.created_at)) END AS resolution_days,
                   p.id AS property_id, p.title AS property_title, p.property_code,
                   staff.full_name AS assigned_name
            FROM maintenance_requests m
            JOIN properties p ON m.property_id = p.id
            LEFT JOIN users staff ON m.assigned_to = staff.id
            WHERE {$where}
            ORDER BY FIELD(m.priority,'urgent','high','medium','low'), m.created_at ASC, m.id ASC
            LIMIT {$limit} OFFSET {$offset}
        ", $params + [':w_today' => $this->window['today']]);
    }

    /** @return array{records:int,amount:float} recorded cost, where there is one */
    public function drillMaintenanceTotal(string $mode, string $key): array
    {
        $resolved = $this->maintenanceDrillWhere($mode, $key);
        if ($resolved === null) {
            return ['records' => 0, 'amount' => 0.0];
        }

        [$where, $params] = $resolved;

        $row = $this->row("
            SELECT COUNT(*) AS records, COALESCE(SUM(m.actual_cost), 0) AS amount
            FROM maintenance_requests m
            JOIN properties p ON m.property_id = p.id
            WHERE {$where}
        ", $params);

        return ['records' => (int) ($row['records'] ?? 0), 'amount' => (float) ($row['amount'] ?? 0)];
    }

    // ─── Performance ───────────────────────────────────────────────────

    /**
     * Whether this reader may break out figures for a given agent.
     *
     * reportAgentOptions() is the desk for an administrator and the reader
     * alone for an agent, so this is the same check the ?agent= filter is
     * validated through -- an agent asking for a colleague's records is
     * refused here exactly as they are refused there, rather than being
     * quietly served an empty panel that confirms the colleague exists.
     */
    private function agentInScope(int $agentId): bool
    {
        return $agentId > 0 && isset(reportAgentOptions()[$agentId]);
    }

    /**
     * The revenue predicate for one agent's column on the desk table.
     *
     * Three different attributions, and they are different on purpose:
     * rental and sales revenue follow the *property's* assigned agent, and
     * "received at desk" follows whoever took the money. agentPerformance()
     * says so in the table's own footnote; the drill-down inherits it rather
     * than picking one.
     *
     * @return array{0:string,1:array}|null
     */
    private function agentRevenueWhere(int $agentId, string $measure): ?array
    {
        [$scope, $scopeParams] = $this->scope('payment', 'py', 'p');
        $types  = $this->revenueTypeList();
        $params = $scopeParams + $this->periodParams() + [':d_agent' => $agentId];

        $paid = "py.status = 'paid'
              AND py.payment_date IS NOT NULL
              AND py.payment_date <= :w_today
              AND {$this->withinPeriod('py.payment_date')}
              AND py.payment_type IN ({$types})
              AND ({$scope})";

        switch ($measure) {
            case 'rental_revenue':
                return [$paid . " AND py.reference_type = 'lease' AND p.agent_id = :d_agent", $params];
            case 'sales_revenue':
                return [$paid . " AND py.reference_type = 'sale' AND p.agent_id = :d_agent", $params];
            case 'revenue_received':
                return [$paid . " AND py.received_by = :d_agent", $params];
        }

        return null;
    }

    /**
     * The payments behind one agent's revenue column.
     *
     * @return array<int,array<string,mixed>>
     */
    public function drillAgentPayments(int $agentId, string $measure, int $limit = 25, int $offset = 0): array
    {
        if (!$this->agentInScope($agentId)) {
            return [];
        }

        $resolved = $this->agentRevenueWhere($agentId, $measure);
        if ($resolved === null) {
            return [];
        }

        [$where, $params] = $resolved;
        [$limit, $offset] = $this->bounds($limit, $offset);

        // JOIN rather than LEFT JOIN for the two property-attributed columns:
        // a payment on no property has no assigned agent and belongs to
        // nobody's row, which is exactly what unattributedRevenue() counts.
        $join = $measure === 'revenue_received'
            ? 'LEFT JOIN properties p ON py.property_id = p.id'
            : 'JOIN properties p ON py.property_id = p.id';

        return $this->rows("
            SELECT py.id, py.payment_code, py.payment_date, py.amount,
                   py.payment_type, py.reference_type, py.reference_id,
                   py.payment_method, py.status, py.receipt_number,
                   py.property_id, p.title AS property_title, p.property_code,
                   c.full_name AS customer_name,
                   u.full_name AS received_by_name
            FROM payments py
            {$join}
            LEFT JOIN customers c ON py.customer_id = c.id
            LEFT JOIN users     u ON py.received_by = u.id
            WHERE {$where}
            ORDER BY py.payment_date DESC, py.id DESC
            LIMIT {$limit} OFFSET {$offset}
        ", $params);
    }

    /** @return array{records:int,amount:float} */
    public function drillAgentPaymentsTotal(int $agentId, string $measure): array
    {
        if (!$this->agentInScope($agentId)) {
            return ['records' => 0, 'amount' => 0.0];
        }

        $resolved = $this->agentRevenueWhere($agentId, $measure);
        if ($resolved === null) {
            return ['records' => 0, 'amount' => 0.0];
        }

        [$where, $params] = $resolved;
        $join = $measure === 'revenue_received'
            ? 'LEFT JOIN properties p ON py.property_id = p.id'
            : 'JOIN properties p ON py.property_id = p.id';

        $row = $this->row("
            SELECT COUNT(*) AS records, COALESCE(SUM(py.amount), 0) AS amount
            FROM payments py
            {$join}
            WHERE {$where}
        ", $params);

        return ['records' => (int) ($row['records'] ?? 0), 'amount' => (float) ($row['amount'] ?? 0)];
    }

    /**
     * The properties one agent manages -- the "Managed" column.
     *
     * Unarchived and assigned to them, which is agentPerformance()'s own
     * count and deliberately not the approved-inventory universe: the column
     * describes the desk, not the shop window.
     *
     * @return array<int,array<string,mixed>>
     */
    public function drillAgentProperties(int $agentId, int $limit = 25, int $offset = 0): array
    {
        if (!$this->agentInScope($agentId)) {
            return [];
        }

        [$scope, $scopeParams] = $this->scope('property', 'p');
        [$limit, $offset]      = $this->bounds($limit, $offset);
        $state                 = $this->commercialState('da');

        return $this->rows("
            SELECT p.id, p.property_code, p.title, p.category, p.property_type,
                   p.location, p.status AS recorded_status, p.approval_status,
                   p.agent_id, u.full_name AS agent_name,
                   {$state['sold']}   AS is_sold,
                   {$state['leased']} AS is_occupied,
                   {$state['held']}   AS is_reserved,
                   NULL AS revenue
            FROM properties p
            LEFT JOIN users u ON p.agent_id = u.id
            WHERE p.is_archived = 0 AND p.agent_id = :d_agent AND ({$scope})
            ORDER BY p.property_code ASC
            LIMIT {$limit} OFFSET {$offset}
        ", $scopeParams + [':d_agent' => $agentId, ':w_today' => $this->window['today']]);
    }

    public function drillAgentPropertiesCount(int $agentId): int
    {
        if (!$this->agentInScope($agentId)) {
            return 0;
        }

        [$scope, $scopeParams] = $this->scope('property', 'p');

        return $this->count("
            SELECT COUNT(*) FROM properties p
            WHERE p.is_archived = 0 AND p.agent_id = :d_agent AND ({$scope})
        ", $scopeParams + [':d_agent' => $agentId]);
    }

}
