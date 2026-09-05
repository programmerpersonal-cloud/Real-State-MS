<?php
/**
 * ReportDrilldown — which records produced that figure.
 *
 * A report's job is to compress thousands of rows into a number somebody can
 * act on. The cost of that compression is trust: a manager looking at
 * "$5,900 in arrears" has no way to check it, and a figure that cannot be
 * checked is a figure that gets argued with. This is the way back down.
 *
 * The whole file is a catalogue. Each entry names a metric, says in one
 * sentence what defines the set behind it, and points at the CoreAnalytics
 * method that selects that set — and pointing is all it does. There is no SQL
 * here, no threshold, no date arithmetic and no second opinion about what
 * revenue means. A drill-down explains a KPI; it never reinterprets one.
 *
 * The catalogue is also the allowlist. `?metric=` is matched against it and
 * nothing else reaches the model, so a hand-typed metric name is a 404 rather
 * than an improvised query. The `key` beside it — a stream, a status, a
 * chart bucket, a property id — is validated a second time inside
 * CoreAnalytics against the same lists the report's own filters are validated
 * against, because a value that has been checked once in a controller has
 * been checked in the wrong place.
 *
 * Nothing here decides who may see a row either. Every method it calls
 * carries the reader's own record scope, so an agent opening the company
 * arrears panel gets the arrears on their own tenancies and no others — not
 * because this file filtered them, but because it never had them.
 */
class ReportDrilldown
{
    /**
     * How many records a panel shows at once.
     *
     * Small on purpose. A drill-down answers "which ones?", and a reader who
     * genuinely needs all four hundred wants the export, not eighteen pages
     * of scrolling.
     */
    public const PER_PAGE = 25;

    /**
     * The catalogue: every drillable figure in the workspace.
     *
     * `source` names the record family and `mode` the arm of it, both of
     * which are matched inside CoreAnalytics against its own lists. `keyed`
     * marks the metrics that need a second value — which stream, which
     * status, which bucket — and `keys` names the allowlist it is measured
     * against where the answer is a fixed set rather than a validated id.
     *
     * `explain` is the sentence printed under the panel heading. It is not
     * decoration: it is the definition the figure was computed from, in the
     * one place a reader is actually asking the question.
     *
     * @return array<string,array<string,array<string,mixed>>>
     */
    public static function catalog(): array
    {
        static $catalog = null;
        if ($catalog !== null) {
            return $catalog;
        }

        // ── The record sets, written once and shared between tabs ───────
        //
        // "Collected revenue" is the same set on Overview, Financial and
        // Payments, and the reconciliation suite asserts the three tiles
        // agree. Their drill-downs are the same entry for the same reason.

        $revenue = [
            'label'   => 'Collected revenue',
            'explain' => 'Paid payments dated on or before today, excluding deposits and refunds, '
                       . 'inside this period. Money held or refunded is not earnings and is not here.',
            'source'  => 'payments',
            'mode'    => 'collected',
            'unit'    => 'money',
        ];

        $stream = [
            'label'   => 'Revenue by stream',
            'explain' => 'Collected revenue taken against one kind of contract. The contract the '
                       . 'payment names decides this, not the label chosen on the form.',
            'source'  => 'payments',
            'mode'    => 'stream',
            'unit'    => 'money',
            'keyed'   => true,
            'keys'    => ['lease' => 'Rentals', 'sale' => 'Sales', 'reservation' => 'Reservations'],
        ];

        $revenueBucket = [
            'label'   => 'Collected revenue',
            'explain' => 'The payments collected inside this bucket of the period.',
            'source'  => 'payments',
            'mode'    => 'revenue_bucket',
            'unit'    => 'money',
            'keyed'   => true,
        ];

        $occupied = [
            'label'   => 'Occupied properties',
            'explain' => 'Rentable properties carrying a live lease — active and not past its end '
                       . 'date. Derived from leases, never from the property status column.',
            'source'  => 'properties',
            'mode'    => 'occupied',
            'unit'    => 'count',
        ];

        $vacant = [
            'label'   => 'Vacant properties',
            'explain' => 'Rentable properties with no live lease. Sale-only listings and sold units '
                       . 'are not rentable and are not counted as vacant.',
            'source'  => 'properties',
            'mode'    => 'vacant',
            'unit'    => 'count',
        ];

        $rentable = [
            'label'   => 'Rentable properties',
            'explain' => 'Approved, unarchived, lettable by type, not withdrawn and not already '
                       . 'sold. This is the denominator the occupancy rate is taken over.',
            'source'  => 'properties',
            'mode'    => 'rentable',
            'unit'    => 'count',
        ];

        $expected = [
            'label'   => 'Expected rent',
            'explain' => 'Rent scheduled to fall due inside this period, on tenancies you can see. '
                       . 'Instalments, not payments — this is the schedule, not the ledger.',
            'source'  => 'schedules',
            'mode'    => 'expected',
            'unit'    => 'money',
        ];

        $settled = [
            'label'   => 'Settled rent',
            'explain' => 'Scheduled instalments falling due in this period that have been marked '
                       . 'paid. Measured on the due date, which is what makes the collection rate '
                       . 'a like-for-like figure.',
            'source'  => 'schedules',
            'mode'    => 'settled',
            'unit'    => 'money',
        ];

        $outstanding = [
            'label'   => 'Outstanding balance',
            'explain' => 'Every unsettled instalment, whether or not it has fallen due yet. A '
                       . 'running balance as at today, not a figure for the period.',
            'source'  => 'schedules',
            'mode'    => 'outstanding',
            'unit'    => 'money',
        ];

        $arrears = [
            'label'   => 'Rent arrears',
            'explain' => 'Instalments already due and unpaid — overdue or part-paid. The late part '
                       . 'of the outstanding balance, and a running total rather than a period one.',
            'source'  => 'schedules',
            'mode'    => 'arrears',
            'unit'    => 'money',
        ];

        $notYetDue = [
            'label'   => 'Not yet due',
            'explain' => 'Instalments that are owed but not late. Counted in the outstanding '
                       . 'balance and never in arrears.',
            'source'  => 'schedules',
            'mode'    => 'not_yet_due',
            'unit'    => 'money',
        ];

        // ── The catalogue itself ────────────────────────────────────────

        $catalog = [];

        $catalog['overview'] = [
            'revenue'   => $revenue,
            'stream'    => $stream,
            'revenue_bucket' => $revenueBucket,
            'occupancy' => $occupied,
            'occupied'  => $occupied,
            'vacant'    => $vacant,
            'rentable'  => $rentable,
            'arrears'   => $arrears,
            'state'     => [
                'label'   => 'Properties by commercial state',
                'explain' => 'State proved by a record — a live lease, an unexpired hold, a '
                           . 'completed sale — rather than by the status column.',
                'source'  => 'properties',
                'mode'    => 'state',
                'unit'    => 'count',
                'keyed'   => true,
                'keys'    => [
                    'state_occupied' => 'Occupied',
                    'vacant'         => 'Vacant',
                    'reserved'       => 'Reserved',
                    'sold'           => 'Sold',
                ],
            ],
            'property_revenue' => [
                'label'   => 'Revenue collected on this property',
                'explain' => 'The payments behind this property\'s place in the ranking, under the '
                           . 'same collected-revenue definition.',
                'source'  => 'payments',
                'mode'    => 'property',
                'unit'    => 'money',
                'keyed'   => true,
            ],
        ];

        $catalog['financial'] = [
            'revenue'        => $revenue,
            'stream'         => $stream,
            'expected'       => $expected,
            'settled'        => $settled,
            'outstanding'    => $outstanding,
            'arrears'        => $arrears,
            'not_yet_due'    => $notYetDue,
            'expected_bucket' => [
                'label'   => 'Rent due in this bucket',
                'explain' => 'Instalments falling due inside this bucket of the period.',
                'source'  => 'schedules',
                'mode'    => 'bucket',
                'unit'    => 'money',
                'keyed'   => true,
            ],
            'settled_bucket' => [
                'label'   => 'Rent settled in this bucket',
                'explain' => 'Instalments falling due inside this bucket that have been paid.',
                'source'  => 'schedules',
                'mode'    => 'settled_bucket',
                'unit'    => 'money',
                'keyed'   => true,
            ],
            'property_schedule' => [
                'label'   => 'Rent scheduled on this property',
                'explain' => 'The instalments behind this property\'s row, falling due inside the '
                           . 'period.',
                'source'  => 'schedules',
                'mode'    => 'property',
                'unit'    => 'money',
                'keyed'   => true,
            ],
        ];

        $catalog['properties'] = [
            'total'     => [
                'label'   => 'Approved listings',
                'explain' => 'Unarchived properties an administrator has approved. Listings still '
                           . 'waiting on approval are not live inventory and are counted separately.',
                'source'  => 'properties',
                'mode'    => 'approved',
                'unit'    => 'count',
            ],
            'occupancy' => $occupied,
            'occupied'  => $occupied,
            'vacant'    => $vacant,
            'rentable'  => $rentable,
            'state'     => $catalog['overview']['state'],
            'lifecycle' => [
                'label'   => 'Properties by lifecycle stage',
                'explain' => 'Where a listing stands in the approval and archive workflow. '
                           . 'Administrative rather than commercial.',
                'source'  => 'properties',
                'mode'    => 'lifecycle',
                'unit'    => 'count',
                'keyed'   => true,
                'keys'    => [
                    'approved'  => 'Approved',
                    'pending'   => 'Awaiting approval',
                    'rejected'  => 'Rejected',
                    'withdrawn' => 'Withdrawn',
                    'archived'  => 'Archived',
                ],
            ],
            'category'  => [
                'label'   => 'Properties in this category',
                'explain' => 'Approved listings of one kind.',
                'source'  => 'properties',
                'mode'    => 'category',
                'unit'    => 'count',
                'keyed'   => true,
            ],
            'intent'    => [
                'label'   => 'Properties by listing intent',
                'explain' => 'What the listing is on the market for. Intent, not commercial state.',
                'source'  => 'properties',
                'mode'    => 'intent',
                'unit'    => 'count',
                'keyed'   => true,
                'keys'    => ['rent' => 'For rent', 'sale' => 'For sale', 'both' => 'Rent or sale'],
            ],
            'location'  => [
                'label'   => 'Properties in this location',
                'explain' => 'Location as recorded on the property, which is free text and not a '
                           . 'maintained dimension.',
                'source'  => 'properties',
                'mode'    => 'location',
                'unit'    => 'count',
                'keyed'   => true,
            ],
            'property'  => [
                'label'   => 'Property',
                'explain' => 'The register\'s own record for this property.',
                'source'  => 'properties',
                'mode'    => 'property',
                'unit'    => 'count',
                'keyed'   => true,
            ],
        ];

        $catalog['rentals'] = [
            'active'      => [
                'label'   => 'Active tenancies',
                'explain' => 'Leases marked active whose end date has not passed. Current state, so '
                           . 'this does not move with the reporting period.',
                'source'  => 'leases',
                'mode'    => 'active',
                'unit'    => 'count',
            ],
            'occupancy'   => $occupied,
            'occupied'    => $occupied,
            'vacant'      => $vacant,
            'expected'    => $expected,
            'settled'     => $settled,
            'outstanding' => $outstanding,
            'arrears'     => $arrears,
            'not_yet_due' => $notYetDue,
            'expiring'    => [
                'label'   => 'Tenancies by when they end',
                'explain' => 'Active leases grouped by how long they have left. A lease past its '
                           . 'end date and still marked active is counted on its own line — it has '
                           . 'already gone, which is a different problem from ending soon.',
                'source'  => 'leases',
                'mode'    => 'expiring',
                'unit'    => 'count',
                'keyed'   => true,
                'keys'    => [
                    'expired' => 'Already expired',
                    'd7'      => 'Within 7 days',
                    'd30'     => 'Within 30 days',
                    'd60'     => 'Within 60 days',
                    'beyond'  => 'More than 60 days',
                ],
            ],
            'expiring_soon' => [
                'label'   => 'Tenancies ending within 60 days',
                'explain' => 'Active leases whose end date falls inside the next sixty days. Leases '
                           . 'already past their end date are not here.',
                'source'  => 'leases',
                'mode'    => 'expiring_soon',
                'unit'    => 'count',
            ],
            'expected_bucket' => $catalog['financial']['expected_bucket'],
            'settled_bucket'  => $catalog['financial']['settled_bucket'],
        ];

        $catalog['sales'] = [
            'deals'     => [
                'label'   => 'Deals recorded',
                'explain' => 'Every sale dated inside this period, whatever its status. A pending '
                           . 'sale is an intention and a completed one is a transaction; both are '
                           . 'counted here and never added together anywhere else.',
                'source'  => 'sales',
                'mode'    => 'all',
                'unit'    => 'money',
            ],
            'completed' => [
                'label'   => 'Completed sales',
                'explain' => 'Sales that have completed, dated on or before today. A deal marked '
                           . 'completed with a date in the future is not counted and is reported '
                           . 'separately.',
                'source'  => 'sales',
                'mode'    => 'completed',
                'unit'    => 'money',
            ],
            'status'    => [
                'label'   => 'Sales by status',
                'explain' => 'Deals dated in this period, in one state.',
                'source'  => 'sales',
                'mode'    => 'status',
                'unit'    => 'money',
                'keyed'   => true,
                'keys'    => ['pending' => 'Pending', 'completed' => 'Completed', 'cancelled' => 'Cancelled'],
            ],
            'bucket'    => [
                'label'   => 'Sales recorded in this bucket',
                'explain' => 'Deals dated inside this bucket of the period, every status.',
                'source'  => 'sales',
                'mode'    => 'bucket',
                'unit'    => 'money',
                'keyed'   => true,
            ],
            'completed_bucket' => [
                'label'   => 'Sales completed in this bucket',
                'explain' => 'Deals that completed inside this bucket of the period.',
                'source'  => 'sales',
                'mode'    => 'completed_bucket',
                'unit'    => 'money',
                'keyed'   => true,
            ],
            'category'  => [
                'label'   => 'Sales in this category',
                'explain' => 'Deals dated in this period on properties of one kind.',
                'source'  => 'sales',
                'mode'    => 'category',
                'unit'    => 'money',
                'keyed'   => true,
            ],
            'reservations' => [
                'label'   => 'Reservations',
                'explain' => 'Holds on property. Current state, not a period figure — a hold either '
                           . 'stands today or it does not, and a lapsed one is past its expiry date '
                           . 'and still marked active. Deposits are held, never earned.',
                'source'  => 'reservations',
                'mode'    => 'state',
                'unit'    => 'count',
                'keyed'   => true,
                'keys'    => [
                    'live'      => 'Live',
                    'lapsed'    => 'Lapsed',
                    'expired'   => 'Expired',
                    'cancelled' => 'Cancelled',
                ],
            ],
        ];

        $catalog['payments'] = [
            'records'   => [
                'label'   => 'Payment records',
                'explain' => 'Every payment dated inside this period, whatever its status. A count '
                           . 'of transactions, not an amount.',
                'source'  => 'payments',
                'mode'    => 'all',
                'unit'    => 'count',
            ],
            'received'  => [
                'label'   => 'Money received',
                'explain' => 'Paid records dated today or earlier, of every type — deposits and '
                           . 'refunds included. Wider than collected revenue on purpose.',
                'source'  => 'payments',
                'mode'    => 'received',
                'unit'    => 'money',
            ],
            'collected' => $revenue,
            'cancelled' => [
                'label'   => 'Cancelled payments',
                'explain' => 'Records marked cancelled. Never counted as revenue.',
                'source'  => 'payments',
                'mode'    => 'cancelled',
                'unit'    => 'money',
            ],
            'future'    => [
                'label'   => 'Dated ahead of today',
                'explain' => 'Paid records dated after today. Held out of collected revenue until '
                           . 'their date arrives, and listed so the gap against the payments '
                           . 'register has an explanation attached to it.',
                'source'  => 'payments',
                'mode'    => 'future',
                'unit'    => 'money',
            ],
            'status'    => [
                'label'   => 'Payments by status',
                'explain' => 'Records dated in this period carrying one status.',
                'source'  => 'payments',
                'mode'    => 'status',
                'unit'    => 'count',
                'keyed'   => true,
            ],
            'method'    => [
                'label'   => 'Payments by method',
                'explain' => 'How the money arrived. Records with no method recorded are their own '
                           . 'group rather than folded into "other".',
                'source'  => 'payments',
                'mode'    => 'method',
                'unit'    => 'money',
                'keyed'   => true,
            ],
            'class'     => [
                'label'   => 'Payments in this classification',
                'explain' => 'One cell of the matrix: payment type against the contract the payment '
                           . 'was taken on.',
                'source'  => 'payments',
                'mode'    => 'class',
                'unit'    => 'count',
                'keyed'   => true,
            ],
            'mismatch'  => [
                'label'   => 'Classification conflicts',
                'explain' => 'Records whose type names a different kind of contract from the one '
                           . 'they are filed against. Revenue counts them by the contract they '
                           . 'name; they are worth reclassifying.',
                'source'  => 'payments',
                'mode'    => 'mismatch',
                'unit'    => 'count',
            ],
            'bucket'    => [
                'label'   => 'Payments in this bucket',
                'explain' => 'Records dated inside this bucket of the period, every status.',
                'source'  => 'payments',
                'mode'    => 'bucket',
                'unit'    => 'money',
                'keyed'   => true,
            ],
        ];

        $catalog['maintenance'] = [
            'raised'      => [
                'label'   => 'Requests raised',
                'explain' => 'Requests logged inside this period. A period figure, on the date the '
                           . 'request was created.',
                'source'  => 'maintenance',
                'mode'    => 'raised',
                'unit'    => 'count',
            ],
            'open'        => [
                'label'   => 'Open requests',
                'explain' => 'Requests nobody has closed — new, under review, assigned or in '
                           . 'progress. Current state: the table holds one status per row and no '
                           . 'history of it, so this cannot be asked of a past period.',
                'source'  => 'maintenance',
                'mode'    => 'open',
                'unit'    => 'count',
            ],
            'in_progress' => [
                'label'   => 'In progress',
                'explain' => 'Requests somebody has started work on.',
                'source'  => 'maintenance',
                'mode'    => 'in_progress',
                'unit'    => 'count',
            ],
            'completed'   => [
                'label'   => 'Completed in this period',
                'explain' => 'Requests completed inside this period, dated on the completion date.',
                'source'  => 'maintenance',
                'mode'    => 'completed',
                'unit'    => 'count',
            ],
            'open_urgent' => [
                'label'   => 'High priority open',
                'explain' => 'Open requests marked high or urgent.',
                'source'  => 'maintenance',
                'mode'    => 'open_urgent',
                'unit'    => 'count',
            ],
            'unassigned'  => [
                'label'   => 'Open with nobody assigned',
                'explain' => 'Open requests with no owner on the record.',
                'source'  => 'maintenance',
                'mode'    => 'open_unassigned',
                'unit'    => 'count',
            ],
            'resolved'    => [
                'label'   => 'Requests with a resolution time',
                'explain' => 'Completed requests that carry a completion date, which are the only '
                           . 'ones a resolution time can be measured on. Requests completed without '
                           . 'one are absent rather than counted as instant.',
                'source'  => 'maintenance',
                'mode'    => 'resolved',
                'unit'    => 'count',
            ],
            'priority'    => [
                'label'   => 'Open work by priority',
                'explain' => 'Open requests at one priority, as recorded on the request.',
                'source'  => 'maintenance',
                'mode'    => 'priority',
                'unit'    => 'count',
                'keyed'   => true,
                'keys'    => ['urgent' => 'Urgent', 'high' => 'High', 'medium' => 'Medium', 'low' => 'Low'],
            ],
            'status'      => [
                'label'   => 'Requests by status',
                'explain' => 'Every request in scope carrying one status. Current state.',
                'source'  => 'maintenance',
                'mode'    => 'status',
                'unit'    => 'count',
                'keyed'   => true,
            ],
            'age'         => [
                'label'   => 'Open work by how long it has waited',
                'explain' => 'Open requests in one age band, measured from the day they were '
                           . 'raised. Not an SLA breach — this system defines no target response '
                           . 'time — but an age worth stating.',
                'source'  => 'maintenance',
                'mode'    => 'age',
                'unit'    => 'count',
                'keyed'   => true,
                'keys'    => ['d3' => '0–3 days', 'd7' => '4–7 days', 'd14' => '8–14 days', 'd15' => '15+ days'],
            ],
            'bucket'      => [
                'label'   => 'Requests raised in this bucket',
                'explain' => 'Requests logged inside this bucket of the period.',
                'source'  => 'maintenance',
                'mode'    => 'bucket',
                'unit'    => 'count',
                'keyed'   => true,
            ],
            'completed_bucket' => [
                'label'   => 'Requests completed in this bucket',
                'explain' => 'Requests completed inside this bucket of the period.',
                'source'  => 'maintenance',
                'mode'    => 'completed_bucket',
                'unit'    => 'count',
                'keyed'   => true,
            ],
            'property'    => [
                'label'   => 'Maintenance on this property',
                'explain' => 'Every request against this property, whatever its state.',
                'source'  => 'maintenance',
                'mode'    => 'property',
                'unit'    => 'count',
                'keyed'   => true,
            ],
        ];

        // Performance. Every entry is keyed by an agent id, and that id is
        // checked against reportAgentOptions() inside the model — an agent
        // asking for a colleague is refused there rather than served an empty
        // panel that would confirm the colleague exists.
        $catalog['performance'] = [
            'agent_listings' => [
                'label'   => 'Properties managed',
                'explain' => 'Unarchived properties whose record names this agent. The desk as it '
                           . 'stands today, not a figure for the period.',
                'source'  => 'agent_properties',
                'mode'    => 'listings',
                'unit'    => 'count',
                'keyed'   => true,
            ],
            'agent_leases' => [
                'label'   => 'Active tenancies on their listings',
                'explain' => 'Live leases on properties assigned to this agent. Current state.',
                'source'  => 'leases',
                'mode'    => 'agent',
                'unit'    => 'count',
                'keyed'   => true,
                'agent'   => true,
            ],
            'agent_written' => [
                'label'   => 'Leases written in this period',
                'explain' => 'Leases this agent created inside the period. It measures paperwork, '
                           . 'not ownership — an agent can write a lease on a colleague\'s listing.',
                'source'  => 'leases',
                'mode'    => 'created_by',
                'unit'    => 'count',
                'keyed'   => true,
                'agent'   => true,
            ],
            'agent_sales' => [
                'label'   => 'Sales closed in this period',
                'explain' => 'Completed sales naming this agent, dated on or before today. A deal '
                           . 'with no agent recorded belongs to the company and to nobody here.',
                'source'  => 'sales',
                'mode'    => 'agent',
                'unit'    => 'count',
                'keyed'   => true,
                'agent'   => true,
            ],
            'agent_rental_revenue' => [
                'label'   => 'Rent collected on their listings',
                'explain' => 'Collected revenue taken against tenancies on properties assigned to '
                           . 'this agent.',
                'source'  => 'agent_payments',
                'mode'    => 'rental_revenue',
                'unit'    => 'money',
                'keyed'   => true,
            ],
            'agent_sales_revenue' => [
                'label'   => 'Sales money collected on their listings',
                'explain' => 'Collected revenue taken against sales on properties assigned to this '
                           . 'agent.',
                'source'  => 'agent_payments',
                'mode'    => 'sales_revenue',
                'unit'    => 'money',
                'keyed'   => true,
            ],
            'agent_received' => [
                'label'   => 'Received at desk',
                'explain' => 'Collected revenue this agent personally took in, including rent on a '
                           . 'colleague\'s property. A different question from the two above, and '
                           . 'the three will not agree.',
                'source'  => 'agent_payments',
                'mode'    => 'revenue_received',
                'unit'    => 'money',
                'keyed'   => true,
            ],
        ];

        return $catalog;
    }

    /**
     * The spec for one metric, or null when there is no such thing.
     *
     * This is the allowlist check. Both halves matter: a metric that exists
     * on Payments is not thereby available on Overview, because the tabs
     * honour different filters and a metric reached from the wrong one would
     * be computed under a filter set its own report never offered.
     *
     * @return array<string,mixed>|null
     */
    public static function resolve(string $tab, string $metric): ?array
    {
        $spec = self::catalog()[$tab][$metric] ?? null;
        if ($spec === null) {
            return null;
        }

        return $spec + ['keyed' => false, 'keys' => null, 'fixed' => null, 'agent' => false];
    }

    /**
     * Fetch the records behind a metric.
     *
     * `$key` has already survived the catalogue's own list where the spec
     * declares one; it is checked again inside CoreAnalytics against the
     * report's validated allowlists, and an id is checked against the
     * reader's scope. Two checks in two places on purpose — the second is the
     * one that is still there when somebody adds a third caller.
     *
     * @return array{
     *     rows:array, total:int, amount:float, source:string,
     *     label:string, explain:string, unit:string, page:int, pages:int
     * }
     */
    public static function fetch(
        array $spec,
        string $key,
        CoreAnalytics $analytics,
        int $page = 1
    ): array {
        $mode = (string) $spec['mode'];
        $key  = $spec['fixed'] !== null ? (string) $spec['fixed'] : $key;

        // Metrics whose key is an agent id resolve it once, here, so the two
        // families that take one do not each have to remember to cast it.
        $agentId = (int) $key;

        $totals = match ($spec['source']) {
            'payments'       => $analytics->drillPaymentsTotal($mode, $key),
            'schedules'      => $analytics->drillSchedulesTotal($mode, $key),
            'leases'         => $analytics->drillLeasesTotal($mode, $key),
            'sales'          => $analytics->drillSalesTotal($mode, $key),
            'reservations'   => $analytics->drillReservationsTotal(self::reservationMode($key), ''),
            'maintenance'    => $analytics->drillMaintenanceTotal($mode, $key),
            'properties'     => [
                'records' => $analytics->drillPropertiesCount(self::propertyMode($mode, $key), $key),
                'amount'  => 0.0,
            ],
            'agent_properties' => [
                'records' => $analytics->drillAgentPropertiesCount($agentId),
                'amount'  => 0.0,
            ],
            'agent_payments' => $analytics->drillAgentPaymentsTotal($agentId, $mode),
            default          => ['records' => 0, 'amount' => 0.0],
        };

        $total  = (int) $totals['records'];
        $pages  = max(1, (int) ceil($total / self::PER_PAGE));
        $page   = max(1, min($pages, $page));
        $offset = ($page - 1) * self::PER_PAGE;

        $rows = match ($spec['source']) {
            'payments'     => $analytics->drillPayments($mode, $key, self::PER_PAGE, $offset),
            'schedules'    => $analytics->drillSchedules($mode, $key, self::PER_PAGE, $offset),
            'leases'       => $analytics->drillLeases($mode, $key, self::PER_PAGE, $offset),
            'sales'        => $analytics->drillSales($mode, $key, self::PER_PAGE, $offset),
            'reservations' => $analytics->drillReservations(self::reservationMode($key), '', self::PER_PAGE, $offset),
            'maintenance'  => $analytics->drillMaintenance($mode, $key, self::PER_PAGE, $offset),
            'properties'   => $analytics->drillProperties(self::propertyMode($mode, $key), $key, self::PER_PAGE, $offset),
            'agent_properties' => $analytics->drillAgentProperties($agentId, self::PER_PAGE, $offset),
            'agent_payments'   => $analytics->drillAgentPayments($agentId, $mode, self::PER_PAGE, $offset),
            default        => [],
        };

        return [
            'rows'    => $rows,
            'total'   => $total,
            'amount'  => (float) $totals['amount'],
            'source'  => (string) $spec['source'],
            'label'   => (string) $spec['label'],
            'explain' => (string) $spec['explain'],
            'unit'    => (string) $spec['unit'],
            'page'    => $page,
            'pages'   => $pages,
        ];
    }

    /**
     * The property mode, when the metric groups rather than names one.
     *
     * `state` and `lifecycle` are one metric each on the tile strip and one
     * predicate each in the model, so the key selects which. It is measured
     * against the spec's own list before it arrives, and against the model's
     * again after.
     */
    private static function propertyMode(string $mode, string $key): string
    {
        if ($mode === 'state' || $mode === 'lifecycle') {
            return $key;
        }

        return $mode;
    }

    /** Reservations name their state in the key rather than the mode. */
    private static function reservationMode(string $key): string
    {
        return $key;
    }

    /**
     * The name of the thing selected, for the panel's heading.
     *
     * A bucket is labelled the way the axis labelled it, so a reader who
     * clicked "15 Aug" sees "15 Aug" rather than 2026-08-15. Anything the
     * spec does not name falls back to the report's own label helpers.
     */
    public static function keyLabel(array $spec, string $key, array $window): string
    {
        if ($key === '') {
            return '';
        }

        if (is_array($spec['keys']) && isset($spec['keys'][$key])) {
            return (string) $spec['keys'][$key];
        }

        // A key the model would refuse is not named in the heading.
        //
        // The model already returns no rows for one -- the allowlists inside
        // CoreAnalytics see to that, and the panel prints its empty state.
        // What it used to do as well was echo the value into the title, so a
        // hand-typed ?key=<script>... produced "Payments by status —
        // &lt;script&gt;...". Escaped, harmless, and it read as a broken
        // page. Measured against the same lists the report's own filters are
        // measured against, so this adds no second opinion about what a valid
        // status is.
        $named = static function (string $value, array $allowed): string {
            return in_array($value, $allowed, true) ? uiLabel($value) : '';
        };

        return match ((string) $spec['mode']) {
            'bucket', 'settled_bucket', 'revenue_bucket', 'completed_bucket'
                        => reportGrainLabel((string) $window['grain'], $key),
            'category'  => in_array($key, REPORT_CATEGORIES, true) ? categoryLabel($key) : '',
            'class'     => implode(' against ', array_map('uiLabel', explode('|', $key))),
            'status'    => $named($key, array_merge(REPORT_PAYMENT_STATUSES, ['pending', 'completed', 'cancelled'])),
            'method'    => $key === '' ? 'Not recorded' : $named($key, REPORT_PAYMENT_METHODS),
            'priority'  => $named($key, ['urgent', 'high', 'medium', 'low']),
            'intent'    => $named($key, ['rent', 'sale', 'both']),
            'property', 'agent', 'created_by', 'listings',
            'rental_revenue', 'sales_revenue', 'revenue_received'
                        => '',
            default     => $key,
        };
    }
}
