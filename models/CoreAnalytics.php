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

        $liveLease = "EXISTS (SELECT 1 FROM leases oc_l
                              WHERE oc_l.property_id = p.id
                                AND oc_l.status = 'active'
                                AND oc_l.end_date >= :w_today)";
        $anyActive = "EXISTS (SELECT 1 FROM leases oc_a
                              WHERE oc_a.property_id = p.id AND oc_a.status = 'active')";
        $soldOff   = "EXISTS (SELECT 1 FROM sales oc_s
                              WHERE oc_s.property_id = p.id AND oc_s.status = 'completed')";

        $row = $this->row("
            SELECT COUNT(*) AS rentable,
                   COALESCE(SUM({$liveLease}), 0) AS occupied,
                   COALESCE(SUM({$anyActive}), 0) AS active_any
            FROM properties p
            WHERE p.is_archived = 0
              AND p.approval_status = 'approved'
              AND p.property_type IN ('rent', 'both')
              AND p.status <> 'inactive'
              AND NOT {$soldOff}
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

        $liveLease = "EXISTS (SELECT 1 FROM leases iv_l
                              WHERE iv_l.property_id = p.id
                                AND iv_l.status = 'active'
                                AND iv_l.end_date >= :w_today)";
        $heldNow   = "EXISTS (SELECT 1 FROM reservations iv_r
                              WHERE iv_r.property_id = p.id
                                AND iv_r.status IN ('active','confirmed')
                                AND iv_r.expiry_date >= :w_today)";
        $soldOff   = "EXISTS (SELECT 1 FROM sales iv_s
                              WHERE iv_s.property_id = p.id AND iv_s.status = 'completed')";

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
            ORDER BY c DESC
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

        $liveLease = "EXISTS (SELECT 1 FROM leases tp_l
                              WHERE tp_l.property_id = p.id
                                AND tp_l.status = 'active'
                                AND tp_l.end_date >= :w_today)";
        $heldNow   = "EXISTS (SELECT 1 FROM reservations tp_r
                              WHERE tp_r.property_id = p.id
                                AND tp_r.status IN ('active','confirmed')
                                AND tp_r.expiry_date >= :w_today)";
        $soldOff   = "EXISTS (SELECT 1 FROM sales tp_s
                              WHERE tp_s.property_id = p.id AND tp_s.status = 'completed')";

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

        $liveLease = "EXISTS (SELECT 1 FROM leases ps_l
                              WHERE ps_l.property_id = p.id
                                AND ps_l.status = 'active'
                                AND ps_l.end_date >= :w_today)";
        $heldNow   = "EXISTS (SELECT 1 FROM reservations ps_r
                              WHERE ps_r.property_id = p.id
                                AND ps_r.status IN ('active','confirmed')
                                AND ps_r.expiry_date >= :w_today)";
        $soldOff   = "EXISTS (SELECT 1 FROM sales ps_s
                              WHERE ps_s.property_id = p.id AND ps_s.status = 'completed')";

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

        $liveLease = "EXISTS (SELECT 1 FROM leases pg_l
                              WHERE pg_l.property_id = p.id
                                AND pg_l.status = 'active'
                                AND pg_l.end_date >= :w_today)";
        $soldOff   = "EXISTS (SELECT 1 FROM sales pg_s
                              WHERE pg_s.property_id = p.id AND pg_s.status = 'completed')";

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

        $liveLease = "EXISTS (SELECT 1 FROM leases pt_l
                              WHERE pt_l.property_id = p.id
                                AND pt_l.status = 'active'
                                AND pt_l.end_date >= :w_today)";
        $heldNow   = "EXISTS (SELECT 1 FROM reservations pt_r
                              WHERE pt_r.property_id = p.id
                                AND pt_r.status IN ('active','confirmed')
                                AND pt_r.expiry_date >= :w_today)";
        $soldOff   = "EXISTS (SELECT 1 FROM sales pt_s
                              WHERE pt_s.property_id = p.id AND pt_s.status = 'completed')";

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
            ORDER BY amount DESC
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
            ORDER BY records DESC, amount DESC
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
     * The one collected-revenue query, shared by every figure that needs it.
     *
     * Written once so the three rules of approved decision 2 cannot be
     * remembered in one place and forgotten in another.
     *
     * @return array{0:string,1:array}
     */
    private function revenueQuery(?string $referenceType, bool $previous = false): array
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

        $sql = "
            SELECT COALESCE(SUM(py.amount), 0)
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
              {$methodSql}
        ";

        return [$sql, $params];
    }
}
