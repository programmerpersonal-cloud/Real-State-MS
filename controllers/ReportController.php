<?php
/**
 * Report Controller — the analytics workspace.
 *
 * Phase 1 settled what the numbers mean and moved every definition into
 * models/CoreAnalytics.php. Phase 2 turns the single page those definitions
 * fed into a workspace: one report per tab, each a real route with its own
 * authorization, all of them sharing one period, one filter set and one
 * comparison toggle.
 *
 * The controller holds no SQL and decides no business meaning. It resolves
 * the reader's window and filters, asks CoreAnalytics for the figures the
 * *active tab* needs and nothing else, and hands them to the shell. A tab
 * nobody is looking at costs nothing.
 *
 * On authorization: every public tab method opens with authorize(), and none
 * of them delegates to a method that does it on their behalf. The Phase 0
 * audit found four routes inheriting their check from index(); it was correct
 * then and would have been one forgetful afternoon away from not being.
 */
require_once BASE_PATH . '/models/CoreAnalytics.php';

class ReportController
{
    /**
     * The eight reports, in the order they are read.
     *
     * `label` names the tab, `icon` marks it, `blurb` says what the report is
     * for — used by the tab's own heading and, for the reports still being
     * built, by the panel that stands in for them.
     *
     * `filters` is the set each report can actually honour, and it is
     * enforced rather than decorative: a filter absent from a report's list
     * is not offered in its toolbar and is stripped from its URL. The
     * alternative — leaving every control on every tab — meant the Overview
     * showed a payment-method select that changed nothing, because collected
     * revenue is *defined* as paid payments and cannot be narrowed to pending
     * ones without contradicting itself. A control that does nothing is worse
     * than a missing one: it teaches the reader that the numbers do not
     * respond to the filters, which is exactly the wrong lesson.
     */
    public const TABS = [
        'overview' => [
            'label' => 'Overview',
            'icon'  => 'bi-grid-1x2',
            'blurb' => 'The executive read — revenue, occupancy, arrears and what needs attention today.',
            'filters' => ['property', 'category', 'location', 'agent', 'owner'],
        ],
        'financial' => [
            // The tab strip draws a hairline before this entry. Overview is
            // the executive summary and the seven that follow are the detail
            // reports it points into -- one honest division rather than four
            // invented clusters, and the required tab order is untouched.
            'starts_group' => true,
            'label' => 'Financial',
            'icon'  => 'bi-cash-stack',
            'blurb' => 'Rent collection, receivables and the money position across the reporting period.',
            // No payment-status or payment-method filter here, deliberately.
            // Every figure on this report is *defined* by payment status --
            // collected revenue is paid payments, expected is scheduled rent,
            // the collection rate is one over the other. A status filter would
            // let a reader turn "Collected revenue" into "Pending revenue"
            // while the tile kept its name, and a method filter would divide a
            // cash-only numerator by an all-methods denominator and call the
            // result a collection rate. Those questions belong to the Payments
            // report, which describes the ledger rather than the money.
            'filters' => ['property', 'category', 'location', 'agent', 'owner'],
        ],
        'properties' => [
            'label' => 'Properties',
            'icon'  => 'bi-buildings',
            'blurb' => 'Portfolio composition, commercial state and inventory quality across every listing.',
            'filters' => ['property', 'category', 'location', 'agent', 'owner'],
        ],
        'rentals' => [
            'label' => 'Rentals',
            'icon'  => 'bi-house-check',
            'blurb' => 'Occupancy, lease health, expiring tenancies and the rent they are owed.',
            'filters' => ['property', 'category', 'location', 'agent', 'owner'],
        ],
        'sales' => [
            'label' => 'Sales',
            'icon'  => 'bi-tag',
            'blurb' => 'Sales performance, completed transactions, pipeline value and reservations.',
            'filters' => ['property', 'category', 'location', 'agent', 'owner'],
        ],
        'payments' => [
            'label' => 'Payments',
            'icon'  => 'bi-receipt',
            'blurb' => 'Transaction activity, payment classification, methods and ledger integrity.',
            // Method is exposed, status is not, and the line between them is
            // whether the filter narrows the question or renames the answer.
            // Narrowing to card payments leaves "collected revenue" correctly
            // named -- it is the revenue that arrived by card. Narrowing to
            // status = pending would leave a tile called "collected revenue"
            // showing money that was never collected, and would stop the
            // figure reconciling with Financial and Overview. Payment status
            // and payment type are analysed by this report instead: the
            // status breakdown and the classification matrix show every value
            // side by side, which answers more than filtering to one ever
            // could.
            'filters' => ['property', 'category', 'location', 'agent', 'owner', 'payment_method'],
        ],
        'maintenance' => [
            'label' => 'Maintenance',
            'icon'  => 'bi-tools',
            'blurb' => 'Operational workload, priority, ageing and unresolved property maintenance.',
            'filters' => ['property', 'category', 'location', 'agent', 'owner'],
        ],
        'performance' => [
            'label' => 'Performance',
            'icon'  => 'bi-person-badge',
            'blurb' => 'Agent workload, transactions, revenue and attribution — measured, never scored.',
            'filters' => ['property', 'category', 'location', 'agent', 'owner'],
        ],
    ];

    /**
     * Reports that carry real analytics today.
     *
     * The rest render a panel describing what they will hold. That is a
     * deliberate choice over hiding them: a tab that exists and says what is
     * coming is navigation, and a tab that appears later without warning is a
     * surprise. Nothing in either case shows a number that is not real.
     */
    private const BUILT = ['overview', 'financial', 'properties', 'rentals', 'sales', 'payments', 'maintenance', 'performance'];

    /**
     * The request's tab, resolved to a controller method.
     *
     * The router calls this rather than mapping ?tab= itself, so the
     * allowlist lives beside the tabs it allows. An unknown tab is not an
     * error — it is the overview, because a stale bookmark should open the
     * workspace rather than a 404.
     */
    public static function routeFor(mixed $tab, string $action = ''): string
    {
        // Links written before the workspace existed. Each names the report
        // that absorbed it, so an old bookmark lands on the figures it was
        // saved for rather than on a page that no longer exists.
        $legacy = [
            'occupancy'  => 'rentals',
            'revenue'    => 'financial',
            'commission' => 'performance',
            'arrears'    => 'payments',
        ];

        $key = uiPick($tab, array_keys(self::TABS));
        if ($key === '' && $action !== '') {
            $key = $legacy[$action] ?? '';
        }

        return $key !== '' ? $key : 'overview';
    }

    /**
     * The filters a given report honours.
     *
     * @return string[]
     */
    public static function filtersFor(string $tab): array
    {
        return self::TABS[$tab]['filters'] ?? [];
    }

    // ─── The eight reports ─────────────────────────────────────────────
    //
    // One method each, and each one opens the same way on purpose. The
    // repetition is the safety: there is no shared entry point that could be
    // reached without the check.

    public function overview(): void    { authorize('reports.view'); $this->render('overview'); }
    public function financial(): void   { authorize('reports.view'); $this->render('financial'); }
    public function properties(): void  { authorize('reports.view'); $this->render('properties'); }
    public function rentals(): void     { authorize('reports.view'); $this->render('rentals'); }
    public function sales(): void       { authorize('reports.view'); $this->render('sales'); }
    public function payments(): void    { authorize('reports.view'); $this->render('payments'); }
    public function maintenance(): void { authorize('reports.view'); $this->render('maintenance'); }
    public function performance(): void { authorize('reports.view'); $this->render('performance'); }

    /** The workspace's front door, and where a stale link lands. */
    public function index(): void { $this->overview(); }

    // Retained so links written before the workspace keep working. Each is
    // its own authorized entry point rather than a passthrough to index().
    public function occupancy(): void  { authorize('reports.view'); $this->render('rentals'); }
    public function revenue(): void    { authorize('reports.view'); $this->render('financial'); }
    public function commission(): void { authorize('reports.view'); $this->render('performance'); }
    public function arrears(): void    { authorize('reports.view'); $this->render('payments'); }

    // ─── The shell ─────────────────────────────────────────────────────

    /**
     * Resolve the period, the filters and the active report, then render.
     *
     * Only the active tab's figures are fetched. That is the whole reason
     * the tab is a route: eight reports on one page would mean eight reports'
     * worth of queries on every visit, most of them for something nobody
     * scrolled to.
     */
    private function render(string $tab): void
    {
        $window  = reportWindow($_GET);

        // Validated first, then narrowed to what this particular report can
        // honour. Both steps matter: the first stops a bad value reaching
        // SQL, the second stops a good value being carried around by a report
        // that would silently ignore it.
        $filters   = reportFilters($_GET);
        $honoured  = self::filtersFor($tab);
        foreach (array_keys(reportFilterSpec()) as $name) {
            if (!in_array($name, $honoured, true)) {
                $filters[$name] = null;
            }
        }

        // Comparison is off unless asked for. It is a display mode rather
        // than a filter — it changes what a KPI shows, not which rows it
        // counts — so it rides in the URL beside the filters and survives a
        // tab change with them.
        $compare = ($_GET['compare'] ?? '') === '1';

        $analytics = new CoreAnalytics($window, $filters);

        $vars = [
            'reportTab'    => $tab,
            'window'       => $window,
            'filters'      => $filters,
            'compare'      => $compare,
            'analytics'    => $analytics,
            'isBuilt'      => in_array($tab, self::BUILT, true),
            'pageTitle'    => 'Reports & Analytics',
            'pageSubtitle' => 'Real-time portfolio intelligence and operational analytics.',
            'breadcrumbs'  => [['label' => 'Reports'], ['label' => self::TABS[$tab]['label']]],
        ];

        $vars += match ($tab) {
            'overview'    => $this->overviewData($analytics, $compare),
            'financial'   => $this->financialData($analytics, $compare),
            'properties'  => $this->propertiesData($analytics, $compare),
            'rentals'     => $this->rentalsData($analytics),
            'sales'       => $this->salesData($analytics, $compare),
            'maintenance' => $this->maintenanceData($analytics, $compare),
            'payments'    => $this->paymentsData($analytics, $compare),
            'performance' => $this->performanceData($analytics),
            default       => [],
        };

        renderPage(VIEWS_PATH . '/admin/reports/index.php', $vars);
    }

    /**
     * The executive overview.
     *
     * Every figure here comes from a Phase 1 method that was reconciled
     * against the module owning the data. The ranked-properties table in the
     * fourth row has no Phase 1 source and is therefore not drawn — its slot
     * says so rather than showing a plausible-looking placeholder.
     *
     * @return array<string,mixed>
     */
    private function overviewData(CoreAnalytics $analytics, bool $compare): array
    {
        $occupancy    = $analytics->occupancy();
        $ledger       = $analytics->rentLedger();
        $inventory    = $analytics->inventory();
        $streams      = $analytics->revenueByStream();
        $unattributed = $analytics->unattributedRevenue();

        // The comparison costs two extra passes, so it is only asked for when
        // the reader has actually turned it on.
        //
        // Note what is *not* here: a previous occupancy. The numerator can be
        // rebuilt from lease dates, but the denominator cannot — nothing
        // records when a property was archived, approved or changed type, so
        // rentable inventory as it stood in July is not recoverable. Setting a
        // reconstructed numerator over today's denominator would produce a
        // percentage that looks like a measurement and is not one, so the
        // occupancy tile carries no comparison at all.
        $previousRevenue = $compare ? $analytics->collectedRevenue(true) : null;
        $previousSeries  = $compare ? $analytics->revenueComparisonSeries() : [];

        return [
            'occupancy'       => $occupancy,
            'ledger'          => $ledger,
            'inventory'       => $inventory,
            'streams'         => $streams,
            'revenue'         => $streams['total'],
            'previousRevenue' => $previousRevenue,
            'series'          => $analytics->revenueSeries(),
            'previousSeries'  => $previousSeries,
            'topProperties'   => $analytics->topPropertiesByRevenue(5),
            'dataQuality'     => reportDataQuality(),
            'unattributed'    => $unattributed,
            'insights'        => $this->insights(
                $analytics, $occupancy, $ledger, $streams, $previousRevenue, $unattributed
            ),
        ];
    }

    /**
     * The financial report.
     *
     * Two ledgers, kept apart and labelled by the axis each is measured on:
     *
     *   payment_schedules   what rent fell due, and how much of it settled,
     *                       both dated by the due date
     *   payments            what money actually arrived, dated by the day it
     *                       arrived
     *
     * The collection rate lives entirely inside the first, because a rate
     * whose numerator and denominator sit on different axes measures the
     * calendar as much as the collecting. The second is reported beside it as
     * cash received, and the distance between them is the ledger gap the
     * data-quality panel already tracks.
     *
     * Comparison asks for the previous window's expected, settled and
     * collected -- all period figures, all reconstructible. It does not ask
     * for a previous outstanding or arrears: those are running balances and
     * the schedule holds only today's state, so rentLedger() returns null for
     * them and the comparison panel prints "not available".
     *
     * @return array<string,mixed>
     */
    private function financialData(CoreAnalytics $analytics, bool $compare): array
    {
        $ledger  = $analytics->rentLedger();
        $streams = $analytics->revenueByStream();

        $previousLedger  = $compare ? $analytics->rentLedger(true) : null;
        $previousStreams = $compare ? $analytics->revenueByStream(true) : null;

        return [
            'ledger'          => $ledger,
            'streams'         => $streams,
            'revenue'         => $streams['total'],
            'ledgerSeries'    => $analytics->rentLedgerSeries(),
            'previousLedger'  => $previousLedger,
            'previousStreams' => $previousStreams,
            'byProperty'      => $analytics->rentLedgerByProperty(20),
            'deposits'        => $analytics->depositsAndRefunds(),
            'futureDated'     => $analytics->futureDatedExcluded(),
            'unattributed'    => $analytics->unattributedRevenue(),
            'dataQuality'     => reportDataQuality(),
            'insights'        => $this->financialInsights($analytics, $ledger, $streams, $previousStreams),
        ];
    }

    /**
     * Financial insights, derived rather than written.
     *
     * Same contract as the Overview's: a rule fires or it does not, and every
     * sentence restates a figure already on the page. The collection-rate
     * threshold below is an attention line for this report and nothing more --
     * the company has set no target, and calling 90% one here would be
     * inventing a business decision inside a template.
     *
     * @return array<int,array<string,mixed>>
     */
    private function financialInsights(
        CoreAnalytics $analytics,
        array $ledger,
        array $streams,
        ?array $previousStreams
    ): array {
        $out    = [];
        $window = $analytics->window();
        $filter = $analytics->filters();

        if ((int) $ledger['overdue_count'] > 0) {
            $out[] = [
                'rank'   => 10,
                'tone'   => 'danger',
                'icon'   => 'bi-exclamation-triangle',
                'label'  => 'Arrears',
                'text'   => sprintf(
                    '%s is overdue across %d scheduled %s.',
                    formatCurrency((float) $ledger['arrears']),
                    (int) $ledger['overdue_count'],
                    (int) $ledger['overdue_count'] === 1 ? 'instalment' : 'instalments'
                ),
                'metric' => formatCurrency((float) $ledger['arrears']),
                'url'    => reportUrl($window, $filter, ['tab' => 'payments']),
            ];
        }

        if ($ledger['collection_rate'] !== null && $ledger['expected'] > 0 && $ledger['collection_rate'] < 90.0) {
            $out[] = [
                'rank'   => 20,
                'tone'   => $ledger['collection_rate'] < 70.0 ? 'danger' : 'warning',
                'icon'   => 'bi-percent',
                'label'  => 'Collection rate',
                'text'   => sprintf(
                    'Only %s of the rent scheduled for this period has been settled. '
                    . 'Measured against an attention threshold of 90%%, which is this '
                    . 'report&rsquo;s own line and not a company target.',
                    reportPercent($ledger['collection_rate'])
                ),
                'metric' => formatCurrency((float) $ledger['settled_on_ledger'])
                          . ' of ' . formatCurrency((float) $ledger['expected']),
            ];
        }

        // The distinction this report exists to draw. Only stated when the two
        // figures genuinely differ -- where everything outstanding is already
        // overdue, saying so twice adds nothing.
        $notYetDue = (float) ($ledger['not_yet_due'] ?? 0);
        if ($notYetDue > 0 && (float) $ledger['outstanding'] > (float) $ledger['arrears']) {
            $out[] = [
                'rank'   => 30,
                'tone'   => 'info',
                'icon'   => 'bi-hourglass-split',
                'label'  => 'Outstanding',
                'text'   => sprintf(
                    '%s of the %s outstanding is not yet due, so it is owed but not late.',
                    formatCurrency($notYetDue),
                    formatCurrency((float) $ledger['outstanding'])
                ),
                'metric' => reportPercent(reportShare($notYetDue, (float) $ledger['outstanding'])) . ' not yet due',
            ];
        }

        if ($previousStreams !== null) {
            $delta = reportDelta((float) $streams['total'], (float) $previousStreams['total']);
            if ($delta['direction'] !== 'flat') {
                $out[] = [
                    'rank'   => 40,
                    'tone'   => $delta['direction'] === 'up' ? 'success' : 'warning',
                    'icon'   => $delta['direction'] === 'up' ? 'bi-graph-up-arrow' : 'bi-graph-down-arrow',
                    'label'  => 'Revenue trend',
                    'text'   => $delta['percentage'] === null
                        ? sprintf(
                            'Collected revenue of %s, against nothing recorded in the previous equivalent period.',
                            formatCurrency((float) $streams['total'])
                        )
                        : sprintf(
                            'Collected revenue %s by %s against the previous equivalent period.',
                            $delta['direction'] === 'up' ? 'rose' : 'fell',
                            formatCurrency(abs((float) $delta['difference']))
                        ),
                    'metric' => $delta['percentage'] === null
                        ? null
                        : reportPercent(abs((float) $delta['percentage'])),
                ];
            }
        }

        // Which stream carried the period -- only where there is another to
        // carry it against.
        $named  = ['rental' => 'Rental income', 'sale' => 'Sales income', 'reservation' => 'Reservation income'];
        $active = array_filter(
            array_intersect_key($streams, $named),
            static fn($v): bool => (float) $v > 0
        );
        if (count($active) > 1) {
            arsort($active);
            $top = (string) array_key_first($active);
            $out[] = [
                'rank'   => 50,
                'tone'   => 'primary',
                'icon'   => 'bi-pie-chart',
                'label'  => 'Revenue mix',
                'text'   => sprintf(
                    '%s accounts for %s of collected revenue in this period.',
                    $named[$top],
                    reportPercent(reportShare((float) $active[$top], (float) $streams['total']))
                ),
                'metric' => formatCurrency((float) $active[$top]),
            ];
        }

        usort($out, static fn(array $a, array $b): int => ($a['rank'] ?? 50) <=> ($b['rank'] ?? 50));

        return array_slice($out, 0, 5);
    }

    /**
     * The payments report.
     *
     * A transaction report, not a financial one. Financial asks how the
     * business performed; this asks what happened in the ledger and what
     * operations should go and look at. Everything here sits on the
     * payment_date axis -- when money moved -- and counts records as readily
     * as it counts money, because "more payments" and "more money" are
     * different events and a report that only measures the second cannot tell
     * you which one happened.
     *
     * Collected revenue appears here too, unchanged, from the approved
     * definition. It is the bridge between this report and the other two: the
     * same window, scope and filters must produce the same figure on all
     * three, and the reconciliation suite asserts exactly that.
     *
     * @return array<string,mixed>
     */
    private function paymentsData(CoreAnalytics $analytics, bool $compare): array
    {
        $activity = $analytics->paymentActivity();
        $flags    = $analytics->paymentIntegrityFlags();
        $future   = $analytics->futureDatedExcluded();
        $mismatch = reportPaymentMismatches();

        // The pager is the module's own: a page number cast and bounded here,
        // never a value reaching SQL from the request.
        $perPage = 25;
        $total   = $analytics->paymentRecordCount();
        $pages   = max(1, (int) ceil($total / $perPage));
        $page    = max(1, min($pages, (int) ($_GET['p'] ?? 1)));

        $previousActivity = $compare ? $analytics->paymentActivity(true) : null;
        $previousSeries   = $compare ? $analytics->paymentActivityComparisonSeries() : [];

        // Everything a reader would have to act on, counted once. The
        // classification count comes from the shared detector rather than a
        // second rule of its own, so this tile and the data-quality panel can
        // never disagree.
        $reviewFlags = $mismatch['count']
            + $future['count']
            + array_sum($flags);

        return [
            'activity'         => $activity,
            'activitySeries'   => $analytics->paymentActivitySeries(),
            'previousActivity' => $previousActivity,
            'previousSeries'   => $previousSeries,
            'statusBreakdown'  => $analytics->paymentStatusBreakdown(),
            'methodBreakdown'  => $analytics->paymentMethodBreakdown(),
            'classification'   => $analytics->paymentClassificationMatrix(),
            'records'          => $analytics->paymentRecords($perPage, ($page - 1) * $perPage),
            'recordTotal'      => $total,
            'recordPage'       => $page,
            'recordPages'      => $pages,
            'recordPerPage'    => $perPage,
            'futureDated'      => $future,
            'futureRecords'    => $future['count'] > 0 ? $analytics->futureDatedPayments(25) : [],
            'integrityFlags'   => $flags,
            'mismatch'         => $mismatch,
            'reviewFlags'      => $reviewFlags,
            'unattributed'     => $analytics->unattributedRevenue(),
            'ledger'           => null,
            'dataQuality'      => reportDataQuality(),
            'insights'         => $this->paymentInsights(
                $activity, $previousActivity, $future, $mismatch
            ),
        ];
    }

    /**
     * Payment insights, derived rather than written.
     *
     * These explain *activity* -- volume, average size, the gap between more
     * transactions and more money. They deliberately do not restate the
     * data-quality panel: that box explains whether the ledger is internally
     * consistent, these explain what the ledger did. The one overlap, the
     * classification count, is phrased as an operational queue rather than as
     * an integrity finding.
     *
     * @return array<int,array<string,mixed>>
     */
    private function paymentInsights(
        array $activity,
        ?array $previous,
        array $future,
        array $mismatch
    ): array {
        $out = [];

        if ($previous !== null) {
            $records = reportDelta((float) $activity['records'], (float) $previous['records']);
            $money   = reportDelta((float) $activity['amount'], (float) $previous['amount']);

            if ($records['direction'] !== 'flat' || $money['direction'] !== 'flat') {
                // The point of the report in one sentence: volume and value
                // move independently, and reading either alone misleads.
                $out[] = [
                    'rank'   => 10,
                    'tone'   => 'primary',
                    'icon'   => 'bi-activity',
                    'label'  => 'Activity',
                    'text'   => sprintf(
                        'Payment records %s from %d to %d while the amount recorded %s from %s to %s.',
                        self::movedWord($records['direction']),
                        (int) $previous['records'],
                        (int) $activity['records'],
                        self::movedWord($money['direction']),
                        formatCurrency((float) $previous['amount']),
                        formatCurrency((float) $activity['amount'])
                    ),
                    'metric' => $records['percentage'] === null
                        ? null
                        : reportPercent(abs((float) $records['percentage'])) . ' records',
                ];
            }

            // Only where both periods actually have transactions -- an
            // average over zero payments is not a small average, it is no
            // average at all.
            if ($activity['average'] !== null && $previous['average'] !== null) {
                $avg = reportDelta((float) $activity['average'], (float) $previous['average']);
                if ($avg['direction'] !== 'flat') {
                    $out[] = [
                        'rank'   => 20,
                        'tone'   => $avg['direction'] === 'up' ? 'success' : 'info',
                        'icon'   => 'bi-rulers',
                        'label'  => 'Average payment',
                        'text'   => sprintf(
                            'The average payment %s from %s to %s.',
                            self::movedWord($avg['direction']),
                            formatCurrency((float) $previous['average']),
                            formatCurrency((float) $activity['average'])
                        ),
                        'metric' => formatCurrency((float) $activity['average']),
                    ];
                }
            }
        } elseif ($activity['average'] !== null) {
            $out[] = [
                'rank'   => 20,
                'tone'   => 'info',
                'icon'   => 'bi-rulers',
                'label'  => 'Average payment',
                'text'   => sprintf(
                    '%d %s recorded, averaging %s each.',
                    (int) $activity['records'],
                    (int) $activity['records'] === 1 ? 'payment' : 'payments',
                    formatCurrency((float) $activity['average'])
                ),
                'metric' => formatCurrency((float) $activity['average']),
            ];
        }

        if ($future['count'] > 0) {
            $out[] = [
                'rank'   => 30,
                'tone'   => 'warning',
                'icon'   => 'bi-calendar-plus',
                'label'  => 'Dated ahead',
                'text'   => sprintf(
                    '%s across %d %s is dated after today and is not counted as collected revenue yet.',
                    formatCurrency((float) $future['amount']),
                    (int) $future['count'],
                    (int) $future['count'] === 1 ? 'record' : 'records'
                ),
                'metric' => formatCurrency((float) $future['amount']),
            ];
        }

        if ($mismatch['count'] > 0) {
            $out[] = [
                'rank'   => 40,
                'tone'   => 'info',
                'icon'   => 'bi-tags',
                'label'  => 'Classification queue',
                'text'   => sprintf(
                    '%d payment %s %s a type that disagrees with the contract it was taken against, and %s worth reclassifying.',
                    (int) $mismatch['count'],
                    (int) $mismatch['count'] === 1 ? 'record' : 'records',
                    (int) $mismatch['count'] === 1 ? 'carries' : 'carry',
                    (int) $mismatch['count'] === 1 ? 'is' : 'are'
                ),
                'metric' => formatCurrency((float) $mismatch['amount']),
            ];
        }

        if ((int) $activity['cancelled_records'] > 0) {
            $out[] = [
                'rank'   => 50,
                'tone'   => 'warning',
                'icon'   => 'bi-x-circle',
                'label'  => 'Cancelled',
                'text'   => sprintf(
                    '%d cancelled %s in this period, worth %s. Cancelled records never count as revenue.',
                    (int) $activity['cancelled_records'],
                    (int) $activity['cancelled_records'] === 1 ? 'payment' : 'payments',
                    formatCurrency((float) $activity['cancelled_amount'])
                ),
                'metric' => formatCurrency((float) $activity['cancelled_amount']),
            ];
        }

        usort($out, static fn(array $a, array $b): int => ($a['rank'] ?? 50) <=> ($b['rank'] ?? 50));

        return array_slice($out, 0, 5);
    }

    /** "rose" / "fell" / "held steady", so a sentence reads as a sentence. */
    private static function movedWord(string $direction): string
    {
        return ['up' => 'rose', 'down' => 'fell', 'flat' => 'held steady'][$direction] ?? 'changed';
    }

    /**
     * The portfolio report.
     *
     * Almost everything here is current-state, and that is the fact the whole
     * report is built around. The schema records no history of inventory:
     * nothing says when a property was added, archived, approved, or changed
     * type. So "how many properties did we have in July" is not a question
     * this database can answer, and the report says so rather than answering
     * it with today's number wearing a previous-period label.
     *
     * The one window-bounded quantity is revenue, which is why comparison is
     * offered at all -- and why it is offered for exactly one row of the
     * comparison panel.
     *
     * @return array<string,mixed>
     */
    private function propertiesData(CoreAnalytics $analytics, bool $compare): array
    {
        // $compare is accepted for a uniform dispatcher signature and then
        // deliberately unused: there is nothing on this report that can be
        // compared with a previous period. Portfolio state has no history to
        // compare against, and revenue here is per-property detail rather
        // than a headline figure. Turning the toggle on changes the toolbar
        // and nothing else, which is the honest outcome.
        unset($compare);

        // Each of these is fetched exactly once and then passed on. The
        // insight rules used to ask the model again for the inventory and the
        // composition they were being shown alongside, which cost four extra
        // round trips per render for figures already in hand.
        $state       = $analytics->portfolioState();
        $inventory   = $analytics->inventory();
        $occupancy   = $inventory['occupancy'];
        $locations   = $analytics->portfolioLocations(15);
        $composition = $analytics->portfolioComposition();

        $perPage = 25;
        $total   = $analytics->portfolioTableCount();
        $pages   = max(1, (int) ceil($total / $perPage));
        $page    = max(1, min($pages, (int) ($_GET['p'] ?? 1)));

        return [
            'state'            => $state,
            'inventory'        => $inventory,
            'occupancy'        => $occupancy,
            'composition'      => $composition,
            'listingIntent'    => $analytics->portfolioListingIntent(),
            'locations'        => $locations,
            'portfolio'        => $analytics->portfolioTable($perPage, ($page - 1) * $perPage),
            'portfolioTotal'   => $total,
            'portfolioPage'    => $page,
            'portfolioPages'   => $pages,
            // No company revenue total and no comparison figure. This report
            // shows revenue per property in the table and nowhere else, and
            // fetching a portfolio-wide total the view never prints cost four
            // aggregates a render for nothing.
            'integrityFlags'   => $analytics->portfolioIntegrityFlags(),
            'unattributed'     => $analytics->unattributedRevenue(),
            'ledger'           => null,
            'dataQuality'      => reportDataQuality(),
            'insights'         => $this->portfolioInsights(
                $analytics, $state, $occupancy, $locations, $composition, $inventory
            ),
        ];
    }

    /**
     * Portfolio insights, derived rather than written.
     *
     * All current-state, all restating a figure already on the page. The
     * location rule is the interesting one: it fires only when the data is
     * concentrated enough for concentration to mean something, and its
     * absence is itself informative -- fourteen distinct location strings
     * across seventeen properties is a column of addresses, not a geography.
     *
     * @return array<int,array<string,mixed>>
     */
    private function portfolioInsights(
        CoreAnalytics $analytics,
        array $state,
        array $occupancy,
        array $locations,
        array $composition,
        array $inventory
    ): array {
        $out    = [];
        $window = $analytics->window();
        $filter = $analytics->filters();
        $total  = (int) $state['total'];

        if ($occupancy['rentable'] > 0) {
            $out[] = [
                'rank'   => 10,
                'tone'   => $occupancy['rate'] !== null && $occupancy['rate'] >= 70 ? 'success' : 'info',
                'icon'   => 'bi-house-check',
                'label'  => 'Occupancy',
                'text'   => sprintf(
                    '%d of %d rentable %s currently occupied under a live lease.',
                    (int) $occupancy['occupied'],
                    (int) $occupancy['rentable'],
                    (int) $occupancy['rentable'] === 1 ? 'property is' : 'properties are'
                ),
                'metric' => reportPercent($occupancy['rate']),
                'url'    => reportUrl($window, $filter, ['tab' => 'rentals']),
            ];
        }

        if ($total > 0 && (int) $state['available'] > 0) {
            $out[] = [
                'rank'   => 20,
                'tone'   => 'primary',
                'icon'   => 'bi-door-open',
                'label'  => 'Available',
                'text'   => sprintf(
                    '%d of %d approved %s commercially available — no lease, hold or completed sale against %s.',
                    (int) $state['available'],
                    $total,
                    (int) $state['available'] === 1 ? 'property is' : 'properties are',
                    (int) $state['available'] === 1 ? 'it' : 'them'
                ),
                'metric' => reportPercent(reportShare((float) $state['available'], (float) $total)),
            ];
        }

        if ((int) $state['reserved'] > 0) {
            $out[] = [
                'rank'   => 30,
                'tone'   => 'warning',
                'icon'   => 'bi-bookmark-check',
                'label'  => 'Under reservation',
                'text'   => sprintf(
                    '%d %s held under a reservation that has not expired.',
                    (int) $state['reserved'],
                    (int) $state['reserved'] === 1 ? 'property is' : 'properties are'
                ),
                'metric' => number_format((int) $state['reserved']),
            ];
        }

        // Composition, but only where there is a mix to describe.
        if (count($composition) > 1 && $total > 0) {
            $top = $composition[0];
            $out[] = [
                'rank'   => 40,
                'tone'   => 'info',
                'icon'   => 'bi-pie-chart',
                'label'  => 'Composition',
                'text'   => sprintf(
                    '%s are the largest category, at %d of %d approved %s.',
                    // categoryLabel(), not the generic uiLabel() the model
                    // returns: the composition chart labels these the same
                    // way, and an insight naming "Apartment" beside a bar
                    // labelled "Apartments" reads as two different figures.
                    categoryLabel($top['key']),
                    (int) $top['properties'],
                    $total,
                    $total === 1 ? 'property' : 'properties'
                ),
                'metric' => reportPercent(reportShare((float) $top['properties'], (float) $total)),
            ];
        }

        // Concentration only where location is a dimension rather than free
        // text. When it is not, the absence of this line is the finding, and
        // the location panel says so in words.
        if (!empty($locations['usable']) && !empty($locations['rows'])) {
            $topLocation = $locations['rows'][0];
            $out[] = [
                'rank'   => 50,
                'tone'   => 'info',
                'icon'   => 'bi-geo-alt',
                'label'  => 'Concentration',
                'text'   => sprintf(
                    '%s holds the largest share of the portfolio.',
                    (string) $topLocation['location']
                ),
                'metric' => reportPercent(reportShare(
                    (float) $topLocation['properties'],
                    (float) $locations['total']
                )),
            ];
        }

        $pending = (int) ($inventory['lifecycle']['pending_approval'] ?? 0);
        if ($pending > 0) {
            $out[] = [
                'rank'   => 15,
                'tone'   => 'warning',
                'icon'   => 'bi-hourglass-split',
                'label'  => 'Awaiting approval',
                'text'   => sprintf(
                    '%d %s waiting on an administrator and not yet live inventory.',
                    $pending,
                    $pending === 1 ? 'listing is' : 'listings are'
                ),
                'metric' => number_format($pending),
            ];
        }

        usort($out, static fn(array $a, array $b): int => ($a['rank'] ?? 50) <=> ($b['rank'] ?? 50));

        return array_slice($out, 0, 5);
    }

    /**
     * The rentals report.
     *
     * Two clocks run here and the report keeps them apart. The tenancy
     * figures — how many are active, what the rent roll is, when they end —
     * are current-state and do not move with the reporting window. The rent
     * ledger figures do: expected and settled are bounded by the window on
     * the due-date axis, exactly as the approved collection rate requires.
     *
     * Nothing is re-derived. Occupancy comes from occupancy(), the ledger
     * from rentLedger() and rentLedgerSeries(); this method adds the tenancy
     * dimension around them.
     *
     * @return array<string,mixed>
     */
    private function rentalsData(CoreAnalytics $analytics): array
    {
        $summary   = $analytics->leaseSummary();
        $occupancy = $analytics->occupancy();
        $ledger    = $analytics->rentLedger();
        $expiry    = $analytics->leaseExpiryBuckets();
        $flags     = $analytics->leaseIntegrityFlags();

        $perPage = 25;
        $total   = $analytics->leaseTableCount('active');
        $pages   = max(1, (int) ceil($total / $perPage));
        $page    = max(1, min($pages, (int) ($_GET['p'] ?? 1)));

        return [
            'summary'       => $summary,
            'occupancy'     => $occupancy,
            'ledger'        => $ledger,
            'ledgerSeries'  => $analytics->rentLedgerSeries(),
            'expiry'        => $expiry,
            'leases'        => $analytics->leaseTable('active', $perPage, ($page - 1) * $perPage),
            'leaseTotal'    => $total,
            'leasePage'     => $page,
            'leasePages'    => $pages,
            'attention'     => $analytics->leaseTable('attention', 25, 0),
            'leaseFlags'    => $flags,
            'unattributed'  => $analytics->unattributedRevenue(),
            'dataQuality'   => reportDataQuality(),
            'insights'      => $this->rentalInsights($analytics, $summary, $occupancy, $ledger, $expiry, $flags),
        ];
    }

    /**
     * Rental insights, derived rather than written.
     *
     * @return array<int,array<string,mixed>>
     */
    private function rentalInsights(
        CoreAnalytics $analytics,
        array $summary,
        array $occupancy,
        array $ledger,
        array $expiry,
        array $flags
    ): array {
        $out    = [];
        $window = $analytics->window();
        $filter = $analytics->filters();
        $by     = static function (array $expiry, string $key): int {
            foreach ($expiry as $b) { if ($b['key'] === $key) { return (int) $b['count']; } }
            return 0;
        };

        // An expired tenancy still flagged active is the most urgent thing a
        // rentals report can say, so it leads when it happens.
        $expired = $by($expiry, 'expired');
        if ($expired > 0) {
            $out[] = [
                'rank' => 5, 'tone' => 'danger', 'icon' => 'bi-calendar-x',
                'label' => 'Expired', 'metric' => number_format($expired),
                'text' => sprintf(
                    '%d %s still marked active but past its end date — the tenancy has run out and the record has not caught up.',
                    $expired, $expired === 1 ? 'lease is' : 'leases are'
                ),
            ];
        }

        $soon = $by($expiry, 'd7') + $by($expiry, 'd30') + $by($expiry, 'd60');
        if ($soon > 0) {
            $out[] = [
                'rank' => 10, 'tone' => 'warning', 'icon' => 'bi-hourglass-split',
                'label' => 'Ending soon', 'metric' => number_format($soon),
                'text' => sprintf(
                    '%d %s within 60 days and %s renewal or re-letting.',
                    $soon, $soon === 1 ? 'tenancy ends' : 'tenancies end',
                    $soon === 1 ? 'needs' : 'need'
                ),
            ];
        }

        if ((int) $ledger['overdue_count'] > 0) {
            $out[] = [
                'rank' => 20, 'tone' => 'danger', 'icon' => 'bi-exclamation-triangle',
                'label' => 'Arrears',
                'metric' => formatCurrency((float) $ledger['arrears']),
                'text' => sprintf(
                    '%s is overdue across %d scheduled %s.',
                    formatCurrency((float) $ledger['arrears']),
                    (int) $ledger['overdue_count'],
                    (int) $ledger['overdue_count'] === 1 ? 'instalment' : 'instalments'
                ),
                'url' => reportUrl($window, $filter, ['tab' => 'payments']),
            ];
        }

        if ($ledger['collection_rate'] !== null && $ledger['expected'] > 0 && $ledger['collection_rate'] < 90.0) {
            $out[] = [
                'rank' => 30,
                'tone' => $ledger['collection_rate'] < 70.0 ? 'danger' : 'warning',
                'icon' => 'bi-percent', 'label' => 'Collection',
                'metric' => reportPercent($ledger['collection_rate']),
                'text' => sprintf(
                    'Only %s of the rent scheduled in this period has been settled. Measured against a 90%% attention threshold, not a company target.',
                    reportPercent($ledger['collection_rate'])
                ),
            ];
        }

        if ((int) $occupancy['rentable'] > 0 && (int) $occupancy['vacant'] > 0) {
            $out[] = [
                'rank' => 40, 'tone' => 'info', 'icon' => 'bi-house-dash',
                'label' => 'Vacancy',
                'metric' => reportPercent($occupancy['rate']) . ' let',
                'text' => sprintf(
                    '%d of %d rentable %s standing empty.',
                    (int) $occupancy['vacant'], (int) $occupancy['rentable'],
                    (int) $occupancy['vacant'] === 1 ? 'property is' : 'properties are'
                ),
            ];
        }

        // Money owed against tenancies that have already ended. Not a
        // performance figure — a collectability one, and easily missed
        // because it sits inside the company-wide arrears total.
        $ended = $flags['ended_with_balance'] ?? null;
        if ($ended && $ended['count'] > 0 && $ended['amount'] > 0) {
            $out[] = [
                'rank' => 25, 'tone' => 'warning', 'icon' => 'bi-journal-x',
                'label' => 'Ended tenancies',
                'metric' => formatCurrency((float) $ended['amount']),
                'text' => sprintf(
                    '%s is still unsettled on %d ended %s, of which %s is already overdue.',
                    formatCurrency((float) $ended['amount']),
                    (int) $ended['count'],
                    (int) $ended['count'] === 1 ? 'tenancy' : 'tenancies',
                    formatCurrency((float) $ended['arrears'])
                ),
            ];
        }

        usort($out, static fn(array $a, array $b): int => ($a['rank'] ?? 50) <=> ($b['rank'] ?? 50));

        return array_slice($out, 0, 5);
    }

    /**
     * The sales report.
     *
     * Four things are kept apart here that everyday language runs together:
     * a property listed for sale, a pending sale, a completed sale, and a
     * reservation. Only the third is a transaction that happened; the first
     * is inventory intent, the second is an intention, and the fourth is a
     * hold with a deposit against it.
     *
     * Comparison applies to the period figures — deals recorded, completed
     * count and completed value, all on the sale_date axis. Reservations are
     * current-state and carry no comparison: a hold either stands today or it
     * does not, and the table keeps no history of when it stood.
     *
     * @return array<string,mixed>
     */
    private function salesData(CoreAnalytics $analytics, bool $compare): array
    {
        $summary  = $analytics->salesSummary();
        $pipeline = $analytics->salesPipeline();
        $resv     = $analytics->reservationSummary();
        $flags    = $analytics->salesIntegrityFlags();

        $perPage = 25;
        $total   = $analytics->salesRegisterCount();
        $pages   = max(1, (int) ceil($total / $perPage));
        $page    = max(1, min($pages, (int) ($_GET['p'] ?? 1)));

        return [
            'summary'       => $summary,
            'previous'      => $compare ? $analytics->salesSummary(true) : null,
            'pipeline'      => $pipeline,
            'byCategory'    => $analytics->salesByCategory(),
            'salesSeries'   => $analytics->salesSeries(),
            'register'      => $analytics->salesRegister($perPage, ($page - 1) * $perPage),
            'registerTotal' => $total,
            'registerPage'  => $page,
            'registerPages' => $pages,
            'reservations'  => $resv,
            'resvQueue'     => $analytics->reservationQueue(25),
            'salesFlags'    => $flags,
            'unattributed'  => $analytics->unattributedRevenue(),
            'ledger'        => null,
            'dataQuality'   => reportDataQuality(),
            'insights'      => $this->salesInsights($analytics, $summary, $pipeline, $resv, $flags),
        ];
    }

    /**
     * Sales insights, derived rather than written.
     *
     * @return array<int,array<string,mixed>>
     */
    private function salesInsights(
        CoreAnalytics $analytics,
        array $summary,
        array $pipeline,
        array $resv,
        array $flags
    ): array {
        $out    = [];
        $window = $analytics->window();
        $filter = $analytics->filters();

        // A pipeline with nothing closing in it is the finding, and it is
        // easily missed because every other number on the page looks healthy.
        if ((int) $summary['pending'] > 0 && (int) $summary['completed'] === 0) {
            $out[] = [
                'rank' => 5, 'tone' => 'warning', 'icon' => 'bi-hourglass-split',
                'label' => 'Pipeline',
                'metric' => formatCurrency((float) $summary['pending_value']),
                'text' => sprintf(
                    '%d %s worth %s %s pending, and nothing completed in this period.',
                    (int) $summary['pending'],
                    (int) $summary['pending'] === 1 ? 'sale' : 'sales',
                    formatCurrency((float) $summary['pending_value']),
                    (int) $summary['pending'] === 1 ? 'is' : 'are'
                ),
            ];
        } elseif ((int) $summary['completed'] > 0) {
            $out[] = [
                'rank' => 10, 'tone' => 'success', 'icon' => 'bi-check-circle',
                'label' => 'Completed',
                'metric' => formatCurrency((float) $summary['completed_value']),
                'text' => sprintf(
                    '%d %s completed in this period, worth %s in contract value.',
                    (int) $summary['completed'],
                    (int) $summary['completed'] === 1 ? 'sale' : 'sales',
                    formatCurrency((float) $summary['completed_value'])
                ),
            ];
        }

        // Lapsed holds: property still marked reserved, deposit still held,
        // and nobody has decided what happens next.
        $lapsed = $flags['lapsed_reservations'] ?? null;
        if ($lapsed && (int) $lapsed['count'] > 0) {
            $out[] = [
                'rank' => 15, 'tone' => 'danger', 'icon' => 'bi-bookmark-x',
                'label' => 'Lapsed holds',
                'metric' => formatCurrency((float) $lapsed['amount']),
                'text' => sprintf(
                    '%d %s past its expiry date but still marked active, holding %s in deposits.',
                    (int) $lapsed['count'],
                    (int) $lapsed['count'] === 1 ? 'reservation is' : 'reservations are',
                    formatCurrency((float) $lapsed['amount'])
                ),
            ];
        }

        if ((int) $resv['live'] > 0) {
            $out[] = [
                'rank' => 20, 'tone' => 'info', 'icon' => 'bi-bookmark-check',
                'label' => 'Live holds',
                'metric' => number_format((int) $resv['live']),
                'text' => sprintf(
                    '%d %s currently held under an unexpired reservation.',
                    (int) $resv['live'],
                    (int) $resv['live'] === 1 ? 'property is' : 'properties are'
                ),
            ];
        }

        // Which kind of stock the book is made of, where there is a mix.
        $cats = $analytics->salesByCategory();
        if (count($cats) > 1 && (float) $summary['total_value'] > 0) {
            $top = $cats[0];
            $out[] = [
                'rank' => 30, 'tone' => 'primary', 'icon' => 'bi-pie-chart',
                'label' => 'Deal mix',
                'metric' => reportPercent(reportShare((float) $top['value'], (float) $summary['total_value'])),
                'text' => sprintf(
                    '%s account for the largest share of deal value in this period.',
                    categoryLabel((string) $top['category'])
                ),
            ];
        }

        // A property whose record claims a sale nobody completed.
        $soldNoSale = $flags['sold_no_sale'] ?? null;
        if ($soldNoSale && (int) $soldNoSale['count'] > 0) {
            $out[] = [
                'rank' => 25, 'tone' => 'warning', 'icon' => 'bi-house-exclamation',
                'label' => 'Recorded sold',
                'metric' => number_format((int) $soldNoSale['count']),
                'text' => sprintf(
                    '%d %s marked sold on the register with no completed sale behind it, so %s not counted here.',
                    (int) $soldNoSale['count'],
                    (int) $soldNoSale['count'] === 1 ? 'property is' : 'properties are',
                    (int) $soldNoSale['count'] === 1 ? 'it is' : 'they are'
                ),
                'url' => reportUrl($window, $filter, ['tab' => 'properties']),
            ];
        }

        usort($out, static fn(array $a, array $b): int => ($a['rank'] ?? 50) <=> ($b['rank'] ?? 50));

        return array_slice($out, 0, 5);
    }

    /**
     * The maintenance report.
     *
     * The split between current state and period is sharper here than
     * anywhere else in the module, because a backlog is the most tempting
     * thing to compare and the least possible. Nothing records what was open
     * in July: `maintenance_requests` holds one status per row and no history
     * of it. So the workload, its age and its priority mix describe today and
     * carry no comparison, while requests raised and requests completed are
     * period figures and do.
     *
     * Resolution time is offered only when the data can support it. The
     * measurement is created_at to completion_date; where no completed
     * request carries a completion date, maintenanceResolution() reports
     * itself unavailable and the view prints that rather than an average of
     * nothing.
     *
     * @return array<string,mixed>
     */
    private function maintenanceData(CoreAnalytics $analytics, bool $compare): array
    {
        $summary    = $analytics->maintenanceSummary();
        $ageing     = $analytics->maintenanceAgeing();
        $resolution = $analytics->maintenanceResolution();
        $priority   = $analytics->maintenancePriorityBreakdown();
        $flags      = $analytics->maintenanceIntegrityFlags();

        $perPage = 25;
        $total   = $analytics->maintenanceTableCount('open');
        $pages   = max(1, (int) ceil($total / $perPage));
        $page    = max(1, min($pages, (int) ($_GET['p'] ?? 1)));

        return [
            'summary'      => $summary,
            'previous'     => $compare ? $analytics->maintenanceSummary(true) : null,
            'statusMix'    => $analytics->maintenanceStatusBreakdown(),
            'priorityMix'  => $priority,
            'ageing'       => $ageing,
            'maintSeries'  => $analytics->maintenanceSeries(),
            'resolution'   => $resolution,
            'costs'        => $analytics->maintenanceCosts(),
            'queue'        => $analytics->maintenanceTable('open', $perPage, ($page - 1) * $perPage),
            'queueTotal'   => $total,
            'queuePage'    => $page,
            'queuePages'   => $pages,
            'done'         => $analytics->maintenanceTable('completed', 25, 0),
            'doneTotal'    => $analytics->maintenanceTableCount('completed'),
            'byProperty'   => $analytics->maintenanceByProperty(10),
            'issueTypes'   => $analytics->maintenanceIssueTypes(15),
            'maintFlags'   => $flags,
            'unattributed' => $analytics->unattributedRevenue(),
            'ledger'       => null,
            'dataQuality'  => reportDataQuality(),
            'insights'     => $this->maintenanceInsights($summary, $ageing, $resolution, $priority, $flags),
        ];
    }

    /**
     * Maintenance insights, derived rather than written.
     *
     * @return array<int,array<string,mixed>>
     */
    private function maintenanceInsights(
        array $summary,
        array $ageing,
        array $resolution,
        array $priority,
        array $flags
    ): array {
        $out = [];

        // Urgent work still open leads whenever it exists.
        if ((int) $summary['open_urgent'] > 0) {
            $out[] = [
                'rank' => 5, 'tone' => 'danger', 'icon' => 'bi-exclamation-octagon',
                'label' => 'Urgent open',
                'metric' => number_format((int) $summary['open_urgent']),
                'text' => sprintf(
                    '%d high or urgent %s still open.',
                    (int) $summary['open_urgent'],
                    (int) $summary['open_urgent'] === 1 ? 'request is' : 'requests are'
                ),
            ];
        }

        // Age. Not an SLA breach — this system defines no target response
        // time — but an age worth stating.
        $old = 0;
        foreach ($ageing['buckets'] as $b) {
            if ($b['key'] === 'd15') { $old = (int) $b['requests']; }
        }
        if ($old > 0) {
            $out[] = [
                'rank' => 10, 'tone' => 'warning', 'icon' => 'bi-clock-history',
                'label' => 'Waiting',
                'metric' => $ageing['oldest'] . ' days oldest',
                'text' => sprintf(
                    '%d open %s been waiting more than a fortnight, averaging %s days across the queue.',
                    $old,
                    $old === 1 ? 'request has' : 'requests have',
                    number_format((float) $ageing['average'], 1)
                ),
            ];
        }

        if ((int) $summary['open_unassigned'] > 0) {
            $out[] = [
                'rank' => 15, 'tone' => 'warning', 'icon' => 'bi-person-dash',
                'label' => 'Unassigned',
                'metric' => number_format((int) $summary['open_unassigned']),
                'text' => sprintf(
                    '%d open %s nobody assigned to it.',
                    (int) $summary['open_unassigned'],
                    (int) $summary['open_unassigned'] === 1 ? 'request has' : 'requests have'
                ),
            ];
        }

        // A queue with intake and no output is the finding, and it is easy to
        // miss when every individual number looks small.
        if ((int) $summary['raised'] > 0 && (int) $summary['completed'] === 0) {
            $out[] = [
                'rank' => 20, 'tone' => 'warning', 'icon' => 'bi-arrow-down-up',
                'label' => 'Throughput',
                'metric' => number_format((int) $summary['raised']) . ' in, 0 out',
                'text' => sprintf(
                    '%d %s raised in this period and none completed, so the queue only grew.',
                    (int) $summary['raised'],
                    (int) $summary['raised'] === 1 ? 'request was' : 'requests were'
                ),
            ];
        } elseif ((int) $summary['completed'] > 0) {
            $out[] = [
                'rank' => 20, 'tone' => 'success', 'icon' => 'bi-check2-circle',
                'label' => 'Throughput',
                'metric' => number_format((int) $summary['completed']) . ' completed',
                'text' => sprintf(
                    '%d %s raised and %d completed in this period.',
                    (int) $summary['raised'],
                    (int) $summary['raised'] === 1 ? 'request was' : 'requests were',
                    (int) $summary['completed']
                ),
            ];
        }

        if (!empty($resolution['available'])) {
            $out[] = [
                'rank' => 25, 'tone' => 'info', 'icon' => 'bi-stopwatch',
                'label' => 'Resolution',
                'metric' => number_format((float) $resolution['average'], 1) . ' days',
                'text' => sprintf(
                    'Across %d completed %s, resolution has taken %s days on average, between %d and %d.',
                    (int) $resolution['resolved'],
                    (int) $resolution['resolved'] === 1 ? 'request' : 'requests',
                    number_format((float) $resolution['average'], 1),
                    (int) $resolution['fastest'],
                    (int) $resolution['slowest']
                ),
            ];
        }

        usort($out, static fn(array $a, array $b): int => ($a['rank'] ?? 50) <=> ($b['rank'] ?? 50));

        return array_slice($out, 0, 5);
    }

    /** @return array<string,mixed> */
    private function performanceData(CoreAnalytics $analytics): array
    {
        return [
            'agentPerf'    => $analytics->agentPerformance(),
            'unattributed' => $analytics->unattributedRevenue(),
        ];
    }

    /**
     * Key insights, derived rather than written.
     *
     * Each one is a statement about a figure already on the page, produced by
     * a rule that either fires or does not. There is no model behind this and
     * no list of encouraging sentences to pick from: an insight appears only
     * when the condition that makes it true is met, which is why an empty
     * insights panel is a legitimate outcome and not a failure.
     *
     * @return array<int,array<string,mixed>>
     */
    private function insights(
        CoreAnalytics $analytics,
        array $occupancy,
        array $ledger,
        array $streams,
        ?float $previousRevenue,
        array $unattributed = []
    ): array {
        $out    = [];
        $window = $analytics->window();
        $filter = $analytics->filters();

        if ($previousRevenue !== null) {
            $delta = reportDelta($streams['total'], $previousRevenue);
            if ($delta['direction'] !== 'flat' && $delta['percentage'] !== null) {
                $out[] = [
                    'rank'   => 30,
                'tone'   => $delta['direction'] === 'up' ? 'success' : 'warning',
                    'icon'   => $delta['direction'] === 'up' ? 'bi-graph-up-arrow' : 'bi-graph-down-arrow',
                    'label'  => 'Revenue',
                    'text'   => sprintf(
                        'Collected revenue %s %s%% against the previous period.',
                        $delta['direction'] === 'up' ? 'rose' : 'fell',
                        number_format(abs($delta['percentage']), 1)
                    ),
                    'metric' => formatCurrency($streams['total']),
                ];
            }
        }

        if ($occupancy['rentable'] > 0 && $occupancy['vacant'] > 0) {
            $out[] = [
                'rank'   => 40,
                'tone'   => 'info',
                'icon'   => 'bi-house-dash',
                'label'  => 'Occupancy',
                'text'   => sprintf(
                    '%d of %d rentable %s vacant.',
                    $occupancy['vacant'],
                    $occupancy['rentable'],
                    $occupancy['vacant'] === 1 ? 'property is' : 'properties are'
                ),
                'metric' => reportPercent($occupancy['rate']) . ' let',
                'url'    => reportUrl($window, $filter, ['tab' => 'rentals']),
            ];
        }

        if ($ledger['overdue_count'] > 0) {
            $out[] = [
                'rank'   => 10,
                'tone'   => 'danger',
                'icon'   => 'bi-exclamation-triangle',
                'label'  => 'Arrears',
                'text'   => sprintf(
                    '%d rent %s overdue and unpaid.',
                    $ledger['overdue_count'],
                    $ledger['overdue_count'] === 1 ? 'instalment is' : 'instalments are'
                ),
                'metric' => formatCurrency($ledger['arrears']),
                'url'    => reportUrl($window, $filter, ['tab' => 'payments']),
            ];
        }

        if ($ledger['collection_rate'] !== null && $ledger['expected'] > 0) {
            $rate = $ledger['collection_rate'];
            $out[] = [
                'rank'   => 20,
                'tone'   => $rate >= 90 ? 'success' : ($rate >= 70 ? 'warning' : 'danger'),
                'icon'   => 'bi-percent',
                'label'  => 'Collections',
                'text'   => sprintf('%s of the rent due in this period has been settled.', reportPercent($rate)),
                'metric' => formatCurrency($ledger['settled_on_ledger']) . ' of ' . formatCurrency($ledger['expected']),
            ];
        }

        // Which stream carried the period. Only stated when there is another
        // to compare against — "rentals are 100% of revenue" at a company
        // with no sales is not an insight, it is a tautology.
        $named  = ['rental' => 'Rentals', 'sale' => 'Sales', 'reservation' => 'Reservations'];
        $active = array_filter(
            array_intersect_key($streams, $named),
            static fn($v): bool => (float) $v > 0
        );
        if (count($active) > 1) {
            arsort($active);
            $top = (string) array_key_first($active);
            $out[] = [
                'rank'   => 50,
                'tone'   => 'primary',
                'icon'   => 'bi-pie-chart',
                'label'  => 'Revenue mix',
                'text'   => sprintf('%s produced the largest share of collected revenue this period.', $named[$top]),
                'metric' => reportPercent(reportShare((float) $active[$top], (float) $streams['total'])),
            ];
        }

        if (!empty($unattributed) && $unattributed['count'] > 0) {
            $out[] = [
                'rank'   => 60,
                'tone'   => 'info',
                'icon'   => 'bi-person-slash',
                'label'  => 'Attribution',
                'text'   => sprintf(
                    '%s of collected revenue cannot be attributed to an agent, because the property it was taken on has nobody assigned.',
                    formatCurrency($unattributed['amount'])
                ),
                'metric' => $unattributed['share'] !== null
                    ? reportPercent($unattributed['share']) . ' of revenue'
                    : null,
                'url'    => reportUrl($window, $filter, ['tab' => 'performance']),
            ];
        }

        // Ordered by what a manager would act on first — money owed before
        // money earned, both before composition — and then cut to five. A
        // panel of nine findings is a list, and a list is something people
        // stop reading.
        usort($out, static fn(array $a, array $b): int => ($a['rank'] ?? 50) <=> ($b['rank'] ?? 50));

        return array_slice($out, 0, 5);
    }
}
