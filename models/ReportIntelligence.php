<?php
/**
 * ReportIntelligence — what happened, why it matters, what needs attention.
 *
 * The reporting module can now answer the first question well: eight reports,
 * real definitions, drill-downs to the rows behind every figure. What it
 * cannot do is read itself. A manager opening the Overview sees twenty
 * accurate numbers and has to know which three of them are the story today.
 * This decides that, and it decides it the same way every time.
 *
 * Deterministic, and that word is load-bearing. Every finding below is a rule
 * with a stated condition over a value the report already computed: a
 * threshold is crossed or it is not, a count is above nought or it is not.
 * There is no model here, no scoring, no ranking learned from anything, and
 * no sentence that could come out different on two runs over the same data.
 * An empty Decision Center is a legitimate outcome and the honest one when
 * nothing is wrong.
 *
 * Three things it deliberately does not do:
 *
 *   It does not query. Every value arrives in the payload the Overview
 *   already built, which is what keeps §11's promise: the intelligence layer
 *   adds no queries per insight, because it adds none at all.
 *
 *   It does not re-derive. Collected revenue, occupancy, arrears and the rest
 *   are read, never recomputed. A rule that re-implemented one of them would
 *   be a second definition, and the first month they disagreed the Decision
 *   Center would be arguing with the tiles above it.
 *
 *   It does not claim causation. Revenue "rose by $800" is in the data.
 *   Revenue "rose because occupancy improved" is not, and no rule here says
 *   anything of that shape.
 *
 * Every actionable finding carries a Phase 10 drill-down URL, so the answer
 * to "which records?" is one click away and is the same record set the tile
 * would have opened.
 */
class ReportIntelligence
{
    // ─── Severity ──────────────────────────────────────────────────────
    //
    // Five words, used consistently, and never carried by colour alone: the
    // panel prints the severity as text beside every finding.

    public const POSITIVE  = 'positive';
    public const NEUTRAL   = 'neutral';
    public const ATTENTION = 'attention';
    public const WARNING   = 'warning';
    public const CRITICAL  = 'critical';

    /** Ordering, worst first. Used to rank the action list. */
    private const WEIGHT = [
        self::CRITICAL  => 0,
        self::WARNING   => 1,
        self::ATTENTION => 2,
        self::NEUTRAL   => 3,
        self::POSITIVE  => 4,
    ];

    // ─── Thresholds ────────────────────────────────────────────────────
    //
    // Every line a rule is measured against is named here rather than
    // written into the rule, so the whole set can be read in one place and
    // argued with. None of these is a company target: this system has never
    // been given one. They are the lines this report draws in order to have
    // something to say, and the panel says so where it uses them.

    /**
     * Collection rate. 90 and 70 are the Financial report's own attention
     * lines, established in Phase 4A and reused here rather than reinvented —
     * two components disagreeing about what counts as a poor collection rate
     * would be worse than either line being wrong.
     */
    private const COLLECTION_GOOD = 90.0;
    private const COLLECTION_POOR = 70.0;

    /**
     * Occupancy. 70 is the portfolio report's own line, from Phase 5A.
     */
    private const OCCUPANCY_GOOD = 70.0;

    /**
     * Arrears as a share of the rent scheduled in the period. A quarter of
     * the period's rent being overdue is the point at which this report stops
     * calling it attention and starts calling it a warning. Presentation
     * only: it changes which heading a finding sits under and nothing about
     * the figure itself.
     */
    private const ARREARS_HEAVY = 25.0;

    /**
     * Tenancies ending inside a week are a warning rather than attention.
     * Seven days is leaseExpiryBuckets()' own first band, not a new number.
     */
    private const EXPIRY_URGENT_BAND = 'd7';

    /** How many findings the action list carries before it becomes a list. */
    private const ACTION_LIMIT = 6;

    /**
     * Read the Overview's figures and say what they mean.
     *
     * @param array<string,mixed> $p       the Overview payload
     * @param array<string,mixed> $window  the resolved reporting window
     * @param array<string,mixed> $filters the validated filters
     * @return array{
     *     performance:array, attention:array, risk:array, action:array,
     *     reconciliation:array, counts:array, clean:bool, comparing:bool
     * }
     */
    public static function assess(array $p, array $window, array $filters, bool $compare): array
    {
        $drill = static fn(string $tab, string $metric, string $key = ''): string
            => reportDrillUrl($window, $filters, $tab, $metric, $key, $compare ? ['compare' => '1'] : []);

        $out = [
            'performance'    => self::performance($p, $window, $compare, $drill),
            'attention'      => self::attention($p, $drill),
            'risk'           => self::risk($p, $drill),
            'reconciliation' => self::reconciliation($p, $drill),
            'comparing'      => $compare,
        ];

        // The action list is not a fifth set of rules. It is the attention
        // and risk findings that carry somewhere to go, worst first — so the
        // question "what should I look at next" is answered by the findings
        // already made rather than by a new opinion about them.
        $actionable = array_filter(
            array_merge($out['attention'], $out['risk']),
            static fn(array $f): bool => $f['drill'] !== null
        );

        usort($actionable, static function (array $a, array $b): int {
            $bySeverity = self::WEIGHT[$a['severity']] <=> self::WEIGHT[$b['severity']];

            return $bySeverity !== 0 ? $bySeverity : ($a['rank'] <=> $b['rank']);
        });

        $out['action'] = array_slice(array_values($actionable), 0, self::ACTION_LIMIT);
        $out['counts'] = [
            'performance' => count($out['performance']),
            'attention'   => count($out['attention']),
            'risk'        => count($out['risk']),
        ];
        $out['clean'] = $out['attention'] === [] && $out['risk'] === [];

        return $out;
    }

    /**
     * One finding.
     *
     * `value` is the figure a reader checks; `records` is how many rows are
     * behind it, where the report knows. `text` explains and never asserts a
     * cause the database cannot prove.
     */
    private static function finding(
        string $key,
        string $severity,
        string $title,
        string $value,
        string $text,
        ?string $drill = null,
        ?int $records = null,
        int $rank = 50,
        ?string $detail = null
    ): array {
        return [
            'key'      => $key,
            'severity' => $severity,
            'title'    => $title,
            'value'    => $value,
            'detail'   => $detail,
            'text'     => $text,
            'records'  => $records,
            'drill'    => $drill,
            'rank'     => $rank,
        ];
    }

    // ─── Performance ───────────────────────────────────────────────────

    /**
     * What is going well, stated as measurements.
     *
     * @return array<int,array<string,mixed>>
     */
    private static function performance(array $p, array $window, bool $compare, callable $drill): array
    {
        $out       = [];
        $streams   = $p['streams'];
        $ledger    = $p['ledger'];
        $occupancy = $p['occupancy'];
        $signals   = $p['signals'] ?? [];
        $revenue   = (float) $streams['total'];

        // ── Revenue, with the comparison where there is one ─────────────
        if ($compare && $p['previousRevenue'] !== null) {
            $delta = reportDelta($revenue, (float) $p['previousRevenue']);

            $out[] = self::finding(
                'revenue',
                $delta['direction'] === 'down' ? self::ATTENTION : self::POSITIVE,
                'Collected revenue',
                formatCurrency($revenue),
                match (true) {
                    $delta['direction'] === 'flat'
                        => 'Collected revenue is unchanged against the previous period of equal length.',
                    // Nothing to rise from. "Rose by $4,200" against a
                    // previous period of nought is arithmetically true and
                    // reads as a trend, which is the one thing it is not.
                    $delta['percentage'] === null
                        => sprintf(
                            '%s collected in this period, with nothing recorded in the previous '
                            . 'equivalent period.',
                            formatCurrency($revenue)
                        ),
                    default
                        => sprintf(
                            'Collected revenue %s by %s against the previous period of equal length.',
                            $delta['direction'] === 'up' ? 'rose' : 'fell',
                            formatCurrency(abs((float) $delta['difference']))
                        ),
                },
                $drill('overview', 'revenue'),
                $signals['revenueRecords']['records'] ?? null,
                10,
                $delta['percentage'] === null
                    ? 'New this period — nothing recorded previously'
                    : $delta['label']
            );
        } else {
            $out[] = self::finding(
                'revenue',
                $revenue > 0 ? self::POSITIVE : self::NEUTRAL,
                'Collected revenue',
                formatCurrency($revenue),
                $revenue > 0
                    ? 'Rent, sales and fees actually received inside this period.'
                    : 'Nothing was collected inside this period.',
                $drill('overview', 'revenue'),
                $signals['revenueRecords']['records'] ?? null,
                10,
                // §6: an absent comparison is stated, never fabricated.
                'Comparison unavailable — turn on Compare to measure this against the previous period'
            );
        }

        // ── Collection rate, where rent was actually scheduled ──────────
        $rate = $ledger['collection_rate'];
        if ($rate !== null && (float) $ledger['expected'] > 0 && $rate >= self::COLLECTION_GOOD) {
            $out[] = self::finding(
                'collection',
                self::POSITIVE,
                'Collection rate',
                reportPercent($rate),
                sprintf(
                    '%s of the rent scheduled in this period has been settled, above this '
                    . 'report\'s %s attention line.',
                    formatCurrency((float) $ledger['settled_on_ledger']),
                    reportPercent(self::COLLECTION_GOOD, 0)
                ),
                $drill('overview', 'revenue'),
                null,
                20
            );
        }

        // ── Occupancy ───────────────────────────────────────────────────
        if ((int) $occupancy['rentable'] > 0) {
            $good = $occupancy['rate'] !== null && $occupancy['rate'] >= self::OCCUPANCY_GOOD;

            $out[] = self::finding(
                'occupancy',
                $good ? self::POSITIVE : self::NEUTRAL,
                'Occupancy',
                reportPercent($occupancy['rate']),
                sprintf(
                    '%d of %d rentable %s occupied under a live lease.',
                    (int) $occupancy['occupied'],
                    (int) $occupancy['rentable'],
                    (int) $occupancy['rentable'] === 1 ? 'property is' : 'properties are'
                ),
                $drill('overview', 'occupied'),
                (int) $occupancy['occupied'],
                30
            );
        }

        // ── Completed sales ─────────────────────────────────────────────
        $sales = $signals['sales'] ?? null;
        if ($sales !== null && (int) $sales['completed'] > 0) {
            $out[] = self::finding(
                'sales',
                self::POSITIVE,
                'Completed sales',
                number_format((int) $sales['completed']),
                sprintf(
                    '%d %s completed in this period, worth %s in contract value.',
                    (int) $sales['completed'],
                    (int) $sales['completed'] === 1 ? 'sale' : 'sales',
                    formatCurrency((float) $sales['completed_value'])
                ),
                $drill('sales', 'completed'),
                (int) $sales['completed'],
                40
            );
        }

        // ── Maintenance throughput ──────────────────────────────────────
        $maint = $signals['maintenance'] ?? null;
        if ($maint !== null && (int) $maint['completed'] > 0) {
            $out[] = self::finding(
                'maintenance_done',
                self::POSITIVE,
                'Maintenance completed',
                number_format((int) $maint['completed']),
                sprintf(
                    '%d %s completed in this period, against %d raised.',
                    (int) $maint['completed'],
                    (int) $maint['completed'] === 1 ? 'request' : 'requests',
                    (int) $maint['raised']
                ),
                $drill('maintenance', 'completed'),
                (int) $maint['completed'],
                50
            );
        }

        return $out;
    }

    // ─── Attention ─────────────────────────────────────────────────────

    /**
     * What a manager should deal with. Operational, not broken.
     *
     * @return array<int,array<string,mixed>>
     */
    private static function attention(array $p, callable $drill): array
    {
        $out     = [];
        $ledger  = $p['ledger'];
        $signals = $p['signals'] ?? [];

        // ── Arrears ─────────────────────────────────────────────────────
        $arrears = (float) $ledger['arrears'];
        if ($arrears > 0) {
            $share = reportShare($arrears, (float) $ledger['expected']);
            $heavy = $share !== null && $share >= self::ARREARS_HEAVY;

            $out[] = self::finding(
                'arrears',
                $heavy ? self::WARNING : self::ATTENTION,
                'Rent arrears',
                formatCurrency($arrears),
                sprintf(
                    '%s is overdue across %d scheduled %s. A running balance as at today, '
                    . 'not a figure for the period.',
                    formatCurrency($arrears),
                    (int) $ledger['overdue_count'],
                    (int) $ledger['overdue_count'] === 1 ? 'instalment' : 'instalments'
                ),
                $drill('overview', 'arrears'),
                (int) $ledger['overdue_count'],
                10,
                $share === null
                    ? 'No rent was scheduled in this period to measure it against'
                    : reportPercent($share) . ' of the rent scheduled in this period'
            );
        }

        // ── Collection rate below the line ──────────────────────────────
        $rate = $ledger['collection_rate'];
        if ($rate !== null && (float) $ledger['expected'] > 0 && $rate < self::COLLECTION_GOOD) {
            $out[] = self::finding(
                'collection',
                $rate < self::COLLECTION_POOR ? self::WARNING : self::ATTENTION,
                'Collection rate',
                reportPercent($rate),
                sprintf(
                    'Only %s of the %s scheduled in this period has been settled. Measured '
                    . 'against this report\'s own %s attention line, not a company target.',
                    formatCurrency((float) $ledger['settled_on_ledger']),
                    formatCurrency((float) $ledger['expected']),
                    reportPercent(self::COLLECTION_GOOD, 0)
                ),
                $drill('financial', 'settled'),
                null,
                20
            );
        }

        // ── Tenancies ending ────────────────────────────────────────────
        $expiry = $signals['expiry'] ?? null;
        if ($expiry !== null) {
            $band = static function (array $buckets, string $key): int {
                foreach ($buckets as $b) {
                    if ($b['key'] === $key) {
                        return (int) $b['count'];
                    }
                }
                return 0;
            };

            $soon   = $band($expiry, 'd7') + $band($expiry, 'd30') + $band($expiry, 'd60');
            $urgent = $band($expiry, self::EXPIRY_URGENT_BAND);

            if ($soon > 0) {
                $out[] = self::finding(
                    'expiring',
                    $urgent > 0 ? self::WARNING : self::ATTENTION,
                    'Tenancies ending',
                    number_format($soon),
                    sprintf(
                        '%d %s within 60 days and %s renewal or re-letting.',
                        $soon,
                        $soon === 1 ? 'tenancy ends' : 'tenancies end',
                        $soon === 1 ? 'needs' : 'need'
                    ),
                    $drill('rentals', 'expiring_soon'),
                    $soon,
                    30,
                    $urgent > 0
                        ? sprintf('%d of them inside seven days', $urgent)
                        : null
                );
            }
        }

        // ── Open maintenance ────────────────────────────────────────────
        $maint = $signals['maintenance'] ?? null;
        if ($maint !== null && (int) $maint['open'] > 0) {
            $urgent = (int) $maint['open_urgent'];

            $out[] = self::finding(
                'maintenance_open',
                $urgent > 0 ? self::WARNING : self::ATTENTION,
                'Open maintenance',
                number_format((int) $maint['open']),
                sprintf(
                    '%d %s nobody has closed. Current state — the table holds one status per '
                    . 'row and no history of it, so this cannot be asked of a past period.',
                    (int) $maint['open'],
                    (int) $maint['open'] === 1 ? 'request' : 'requests'
                ),
                // The open queue, always -- never the urgent subset. The
                // figure this finding reports is the open count, and a link
                // that opened a different and smaller set would be a panel
                // disagreeing with the line that offered it. Urgency raises
                // the severity and is stated beside the count; it does not
                // change which records the link means.
                $drill('maintenance', 'open'),
                (int) $maint['open'],
                40,
                $urgent > 0 ? sprintf('%d of them marked high or urgent', $urgent) : null
            );
        }

        // ── A pipeline with nothing closing in it ───────────────────────
        $sales = $signals['sales'] ?? null;
        if ($sales !== null && (int) $sales['pending'] > 0 && (int) $sales['completed'] === 0) {
            $out[] = self::finding(
                'sales_pipeline',
                self::ATTENTION,
                'Sales pipeline',
                formatCurrency((float) $sales['pending_value']),
                sprintf(
                    '%d %s worth %s %s pending, and nothing completed in this period.',
                    (int) $sales['pending'],
                    (int) $sales['pending'] === 1 ? 'sale' : 'sales',
                    formatCurrency((float) $sales['pending_value']),
                    (int) $sales['pending'] === 1 ? 'is' : 'are'
                ),
                $drill('sales', 'status', 'pending'),
                (int) $sales['pending'],
                50
            );
        }

        // ── Revenue that belongs to nobody ──────────────────────────────
        $unattributed = $p['unattributed'] ?? null;
        if ($unattributed && (int) $unattributed['count'] > 0) {
            $out[] = self::finding(
                'unattributed',
                self::ATTENTION,
                'Unattributed revenue',
                formatCurrency((float) $unattributed['amount']),
                'Revenue taken on properties with nobody assigned. It is counted in the '
                . 'company total and absent from every agent row, which is the whole of the '
                . 'gap between the two.',
                $drill('overview', 'revenue'),
                (int) $unattributed['count'],
                60,
                $unattributed['share'] !== null
                    ? reportPercent((float) $unattributed['share']) . ' of collected revenue'
                    : null
            );
        }

        return $out;
    }

    // ─── Risk ──────────────────────────────────────────────────────────

    /**
     * Where the records contradict each other.
     *
     * These are not operational backlogs — they are places the database
     * disagrees with itself, which is a different kind of problem and belongs
     * under a different heading. None of them is corrected here; the figures
     * above are still arithmetically right, and this is what they were
     * computed over.
     *
     * @return array<int,array<string,mixed>>
     */
    private static function risk(array $p, callable $drill): array
    {
        $out     = [];
        $quality = $p['dataQuality'] ?? null;
        $signals = $p['signals'] ?? [];
        $ledger  = $p['ledger'];

        // ── A tenancy that has run out and is still flagged active ──────
        $expiry = $signals['expiry'] ?? null;
        if ($expiry !== null) {
            $expired = 0;
            foreach ($expiry as $b) {
                if ($b['key'] === 'expired') {
                    $expired = (int) $b['count'];
                }
            }

            if ($expired > 0) {
                $out[] = self::finding(
                    'expired_leases',
                    self::CRITICAL,
                    'Expired tenancies still active',
                    number_format($expired),
                    sprintf(
                        '%d %s marked active but past its end date. The tenancy has run out '
                        . 'and the record has not caught up.',
                        $expired,
                        $expired === 1 ? 'lease is' : 'leases are'
                    ),
                    $drill('rentals', 'expiring', 'expired'),
                    $expired,
                    5
                );
            }
        }

        // ── A payment filed against the wrong kind of contract ──────────
        if ($quality && (int) $quality['payments']['count'] > 0) {
            $n = (int) $quality['payments']['count'];

            $out[] = self::finding(
                'classification',
                self::WARNING,
                'Payment classification',
                formatCurrency((float) $quality['payments']['amount']),
                sprintf(
                    '%d payment %s filed against a contract of a different kind from %s own '
                    . 'type. Revenue counts %s by the contract %s.',
                    $n,
                    $n === 1 ? 'is' : 'records are',
                    $n === 1 ? 'its' : 'their',
                    $n === 1 ? 'it' : 'them',
                    $n === 1 ? 'it names' : 'they name'
                ),
                $drill('payments', 'mismatch'),
                $n,
                10
            );
        }

        // ── The register disagreeing with its own records ───────────────
        //
        // Summarised into one finding rather than four, because §9 asks the
        // Decision Center to summarise data quality and not to reprint the
        // panel that already lists every check.
        if ($quality && (int) $quality['state_total'] > 0) {
            $worst = null;
            foreach ($quality['states'] as $key => $state) {
                if ((int) $state['count'] > 0 && ($worst === null || $state['count'] > $worst['count'])) {
                    $worst = $state + ['key' => $key];
                }
            }

            $total = (int) $quality['state_total'];

            $out[] = self::finding(
                'property_state',
                self::WARNING,
                'Property state mismatch',
                number_format($total),
                sprintf(
                    '%d %s whose recorded status %s own records — most commonly: %s. '
                    . 'Occupancy and commercial state are derived from leases, reservations and '
                    . 'sales, so the figures above are unaffected.',
                    $total,
                    $total === 1 ? 'property' : 'properties',
                    $total === 1 ? 'contradicts its' : 'contradict their',
                    $worst === null ? 'not stated' : lcfirst((string) $worst['label'])
                ),
                // The state the register is wrong about most often, opened
                // through the portfolio drill-down.
                $worst !== null && $worst['key'] === 'let_not_rented'
                    ? $drill('properties', 'state', 'state_occupied')
                    : $drill('properties', 'total'),
                $total,
                20
            );
        }

        // ── The two ledgers disagreeing ─────────────────────────────────
        //
        // Approved decision 4 keeps payment_schedules and payments apart.
        // Zero is the only comfortable value for the distance between them:
        // anything else means rent was taken without its schedule row being
        // closed, or the reverse.
        $gap = (float) ($ledger['ledger_gap'] ?? 0);
        if (abs($gap) >= 0.005) {
            $out[] = self::finding(
                'ledger_gap',
                self::WARNING,
                'Ledger gap',
                formatCurrency(abs($gap)),
                sprintf(
                    'Rent received and rent marked settled differ by %s over this period, both '
                    . 'measured on the day the money moved. It means a payment was taken '
                    . 'without its schedule row being closed, or the reverse.',
                    formatCurrency(abs($gap))
                ),
                $drill('financial', 'settled'),
                null,
                30
            );
        }

        // ── Money dated into the future ─────────────────────────────────
        $future = $signals['futureDated'] ?? null;
        if ($future !== null && (int) $future['count'] > 0) {
            $out[] = self::finding(
                'future_dated',
                self::ATTENTION,
                'Payments dated ahead',
                formatCurrency((float) $future['amount']),
                sprintf(
                    '%d paid %s dated after today. Held out of collected revenue until the '
                    . 'date arrives, and reported so the gap against the payments register has '
                    . 'an explanation attached to it.',
                    (int) $future['count'],
                    (int) $future['count'] === 1 ? 'record is' : 'records are'
                ),
                $drill('payments', 'future'),
                (int) $future['count'],
                40
            );
        }

        // ── Open work with no owner ─────────────────────────────────────
        $maint = $signals['maintenance'] ?? null;
        if ($maint !== null && (int) $maint['open_unassigned'] > 0) {
            $out[] = self::finding(
                'unassigned',
                self::ATTENTION,
                'Maintenance with no owner',
                number_format((int) $maint['open_unassigned']),
                sprintf(
                    '%d open %s nobody assigned to it, so no one is accountable for closing it.',
                    (int) $maint['open_unassigned'],
                    (int) $maint['open_unassigned'] === 1 ? 'request has' : 'requests have'
                ),
                $drill('maintenance', 'unassigned'),
                (int) $maint['open_unassigned'],
                50
            );
        }

        return $out;
    }

    // ─── Cross-report reconciliation ───────────────────────────────────

    /**
     * The same figure, seen from two reports.
     *
     * The point is not to merge them. Several of these pairs are
     * *deliberately* different quantities — expected rent is not collected
     * revenue, settled is not collected, arrears is not outstanding — and the
     * module has spent five phases keeping them apart. What this does is
     * state each figure beside the record set that produces it, so a reader
     * moving between tabs knows which of two similar numbers they are looking
     * at and can open the rows behind either.
     *
     * @return array<int,array<string,mixed>>
     */
    private static function reconciliation(array $p, callable $drill): array
    {
        $signals   = $p['signals'] ?? [];
        $ledger    = $p['ledger'];
        $occupancy = $p['occupancy'];
        $sales     = $signals['sales'] ?? null;
        $maint     = $signals['maintenance'] ?? null;

        $rows = [];

        $revenueRecords = $signals['revenueRecords'] ?? null;
        $rows[] = [
            'label'   => 'Collected revenue',
            'value'   => formatCurrency((float) $p['streams']['total']),
            'against' => 'Payments register',
            'note'    => $revenueRecords === null
                ? 'Paid payments inside this period, deposits and refunds excluded.'
                : sprintf(
                    '%d contributing %s in the payment register.',
                    (int) $revenueRecords['records'],
                    (int) $revenueRecords['records'] === 1 ? 'payment' : 'payments'
                ),
            'drill'   => $drill('payments', 'collected'),
        ];

        $expectedRecords = $signals['expectedRecords'] ?? null;
        $rows[] = [
            'label'   => 'Expected rent',
            'value'   => formatCurrency((float) $ledger['expected']),
            'against' => 'Rent schedule',
            'note'    => sprintf(
                '%s scheduled instalments. Expected is what fell due; it is not collected '
                . 'revenue and the two are never added.',
                $expectedRecords === null ? 'The period\'s' : number_format((int) $expectedRecords['records'])
            ),
            'drill'   => $drill('financial', 'expected'),
        ];

        $rows[] = [
            'label'   => 'Occupancy',
            'value'   => reportPercent($occupancy['rate']),
            'against' => 'Property register',
            'note'    => sprintf(
                '%d of %d rentable properties, derived from leases rather than from the '
                . 'property status column.',
                (int) $occupancy['occupied'],
                (int) $occupancy['rentable']
            ),
            'drill'   => $drill('properties', 'occupied'),
        ];

        if ($sales !== null) {
            $rows[] = [
                'label'   => 'Completed sales',
                'value'   => formatCurrency((float) $sales['completed_value']),
                'against' => 'Property register',
                'note'    => sprintf(
                    '%d completed %s. Contract value, not cash received — a listing marked '
                    . 'for sale is not a completed one.',
                    (int) $sales['completed'],
                    (int) $sales['completed'] === 1 ? 'deal' : 'deals'
                ),
                'drill'   => $drill('sales', 'completed'),
            ];
        }

        if ($maint !== null) {
            $rows[] = [
                'label'   => 'Open maintenance',
                'value'   => number_format((int) $maint['open']),
                'against' => 'Property register',
                'note'    => sprintf(
                    '%d raised in this period. The open figure beside it is current state and '
                    . 'does not move with the reporting window.',
                    (int) $maint['raised']
                ),
                'drill'   => $drill('maintenance', 'open'),
            ];
        }

        return $rows;
    }
}
