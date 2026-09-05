<?php
/**
 * ReportDocument — one report, described in a way three formats can render.
 *
 * This is the whole of Phase 9's data layer, and its most important property
 * is what it does not contain: a query. Every value below is read out of the
 * array ReportController already built for the screen — the same array the
 * view is handed, produced by the same CoreAnalytics call, under the same
 * window, the same validated filters and the same record scope. There is no
 * second set of SQL for exports and no way for one to drift in, because there
 * is nowhere here for it to live.
 *
 * The one place an export goes back to the database is a paged record table,
 * and it goes back through a closure over the *same* method the screen used,
 * at a later offset. Page one is not re-fetched; the controller already has
 * it. See reportExportWalk() in includes/export.php.
 *
 * The structure it produces:
 *
 *   title, blurb        which report this is
 *   meta                period, comparison, scope, provenance
 *   filters             what is narrowing it, named
 *   kpis                the tiles at the top of the screen, in order
 *   sections            the analytics tables behind the charts and panels
 *   insights            the derived findings, verbatim from the controller
 *   quality             the data-quality panel, verbatim
 *   records             the report's primary tabular dataset
 *
 * A column carries a `type`, and the type is what makes the same value print
 * as "$1,240.00" in a PDF, land as a number under a currency format in Excel
 * and appear as 1240.00 in a CSV. Null is never coerced: it means the figure
 * is unavailable, and each format has its own honest way of saying so.
 */
class ReportDocument
{
    /**
     * Build the document for a report.
     *
     * @param string              $tab       a key from ReportController::TABS
     * @param array<string,mixed> $p         the controller's payload — the view's own vars
     * @return array<string,mixed>
     */
    public static function build(string $tab, array $p): array
    {
        $meta      = ReportController::TABS[$tab] ?? ReportController::TABS['overview'];
        $window    = $p['window'];
        $filters   = $p['filters'] ?? [];
        $compare   = !empty($p['compare']);
        $analytics = $p['analytics'];

        $doc = [
            'tab'      => $tab,
            'title'    => (string) $meta['label'],
            'blurb'    => (string) $meta['blurb'],
            'company'  => companyName(),
            'meta'     => self::meta($window, $filters, $compare),
            'filters'  => self::filterList($filters),
            'kpis'     => [],
            'sections' => [],
            'insights' => self::insights($p['insights'] ?? []),
            'quality'  => self::quality($p),
            'records'  => null,
        ];

        // array_merge, not `+`: the union operator keeps the left-hand value
        // for a duplicate key, which would silently discard every kpis,
        // sections and records entry the report just built and leave the
        // defaults above in their place.
        $doc = array_merge($doc, match ($tab) {
            'overview'    => self::overview($p, $compare),
            'financial'   => self::financial($p, $compare),
            'properties'  => self::properties($p, $analytics),
            'rentals'     => self::rentals($p, $analytics),
            'sales'       => self::sales($p, $analytics, $compare),
            'payments'    => self::payments($p, $analytics, $compare),
            'maintenance' => self::maintenance($p, $analytics, $compare),
            'performance' => self::performance($p, $window),
            default       => [],
        });

        // A series of nothing but zeroes is as empty as no series at all, and
        // the screen already says so: _chart_card.php refuses to draw a flat
        // line along the axis, because doing it implies a measurement was
        // taken and came back nought. Every section that would have been a
        // chart card on screen makes the same refusal here — otherwise a
        // month with no maintenance in it exports two pages of "0  0" and
        // calls it a report.
        foreach ($doc['sections'] as $i => $section) {
            if (isset($section['chart']) && !self::anyValue($section['rows'])) {
                $doc['sections'][$i]['rows'] = [];
            }
        }

        return $doc;
    }

    /**
     * A drill-down, as a document the Phase 9 writers can render.
     *
     * Phase 9 built one export engine and this phase does not build a second.
     * A drill-down is a report with no analytics sections and one record
     * table, which is a shape the existing document model already describes —
     * so it is described that way, handed to the same PDF, workbook and CSV
     * writers, and comes out carrying the same masthead, the same period
     * statement and the same filter list as the report it was opened from.
     *
     * The single KPI is the reconciliation figure: the total this record set
     * adds up to. It is the whole reason to export a drill-down rather than
     * the report — somebody is checking a number, and the file should carry
     * the number being checked.
     *
     * @param array<string,mixed> $context ReportController::context()'s output
     * @param array<string,mixed> $spec    the catalogue entry
     * @param array<string,mixed> $result  ReportDrilldown::fetch()'s output
     * @return array<string,mixed>
     */
    public static function buildDrill(
        string $tab,
        array $context,
        array $spec,
        string $key,
        string $keyLabel,
        array $result,
        CoreAnalytics $analytics
    ): array {
        $meta    = ReportController::TABS[$tab] ?? ReportController::TABS['overview'];
        $window  = $context['window'];
        $filters = $context['filters'];
        $money   = $result['unit'] === 'money';
        $heading = $result['label'] . ($keyLabel !== '' ? ' — ' . $keyLabel : '');

        return [
            'tab'     => $tab,
            'title'   => $heading,
            'blurb'   => $result['explain'],
            'company' => companyName(),
            'meta'    => self::meta($window, $filters, !empty($context['compare'])),
            'filters' => self::filterList($filters),
            'kpis'    => [self::kpi(
                $heading,
                $money ? formatCurrency((float) $result['amount']) : number_format((int) $result['total']),
                sprintf(
                    '%s %s behind this figure on the %s report',
                    number_format((int) $result['total']),
                    (int) $result['total'] === 1 ? 'record' : 'records',
                    $meta['label']
                )
            )],
            'sections' => [],
            'insights' => [],
            'quality'  => [],
            'records'  => [
                'title'   => $heading,
                'note'    => $result['explain'],
                'columns' => self::drillColumns((string) $result['source']),
                'rows'    => $result['rows'],
                'total'   => (int) $result['total'],
                // The same walker the report's own record tables use, over
                // the same method the panel paged through.
                'fetch'   => static fn(int $limit, int $offset): array => self::drillRows(
                    $analytics,
                    (string) $result['source'],
                    (string) $spec['mode'],
                    $key,
                    $limit,
                    $offset
                ),
                'empty'   => 'No records match this metric.',
            ],
        ];
    }

    /**
     * A further page of drill-down records.
     *
     * ReportDrilldown::fetch() answers one page; an export walks the rest,
     * and it walks them through the same model methods rather than a second
     * accessor of its own.
     *
     * @return array<int,array<string,mixed>>
     */
    private static function drillRows(
        CoreAnalytics $analytics,
        string $source,
        string $mode,
        string $key,
        int $limit,
        int $offset
    ): array {
        $agentId = (int) $key;

        return match ($source) {
            'payments'         => $analytics->drillPayments($mode, $key, $limit, $offset),
            'schedules'        => $analytics->drillSchedules($mode, $key, $limit, $offset),
            'leases'           => $analytics->drillLeases($mode, $key, $limit, $offset),
            'sales'            => $analytics->drillSales($mode, $key, $limit, $offset),
            'reservations'     => $analytics->drillReservations($key, '', $limit, $offset),
            'maintenance'      => $analytics->drillMaintenance($mode, $key, $limit, $offset),
            'properties'       => $analytics->drillProperties(
                in_array($mode, ['state', 'lifecycle'], true) ? $key : $mode,
                $key,
                $limit,
                $offset
            ),
            'agent_properties' => $analytics->drillAgentProperties($agentId, $limit, $offset),
            'agent_payments'   => $analytics->drillAgentPayments($agentId, $mode, $limit, $offset),
            default            => [],
        };
    }

    /**
     * The columns for one record family.
     *
     * The same fields _drill_table.php shows on screen, so an exported
     * drill-down and the panel it came from describe a row the same way.
     *
     * @return array<int,array<string,mixed>>
     */
    private static function drillColumns(string $source): array
    {
        switch ($source) {
            case 'payments':
            case 'agent_payments':
                return [
                    self::col('payment_code', 'Reference', 'text', 2.6),
                    self::col('payment_date', 'Date', 'date', 1.8),
                    self::col('property_code', 'Code', 'text', 2.0, false),
                    self::col('property_title', 'Property', 'text', 3.2),
                    self::col('customer_name', 'Payer', 'text', 2.6),
                    self::col('payment_type', 'Type', 'label', 1.6),
                    self::col('reference_type', 'Against', 'label', 1.6, false),
                    self::col('payment_method', 'Method', 'label', 1.8),
                    self::col('status', 'Status', 'label', 1.6),
                    self::col('received_by_name', 'Received by', 'text', 2.4, false),
                    self::col('amount', 'Amount', 'money', 2.0),
                ];

            case 'schedules':
                return [
                    self::col('lease_code', 'Lease', 'text', 2.4),
                    self::col('property_code', 'Code', 'text', 2.0, false),
                    self::col('property_title', 'Property', 'text', 3.2),
                    self::col('tenant_name', 'Tenant', 'text', 2.6),
                    self::col('due_date', 'Due', 'date', 1.8),
                    self::col('paid_date', 'Paid', 'date', 1.8),
                    self::col('status', 'Status', 'label', 1.6),
                    self::col('amount', 'Instalment', 'money', 1.8, false),
                    self::col('penalty', 'Penalty', 'money', 1.6),
                    self::col('due_total', 'Amount due', 'money', 2.0),
                ];

            case 'properties':
            case 'agent_properties':
                return [
                    self::col('property_code', 'Code', 'text', 2.4),
                    self::col('title', 'Property', 'text', 3.8),
                    self::col('category', 'Category', 'label', 2.0),
                    self::col('location', 'Location', 'text', 2.4),
                    self::col('recorded_status', 'Recorded', 'label', 1.8, false),
                    self::col('property_type', 'Intent', 'label', 1.6, false),
                    self::col('agent_name', 'Agent', 'text', 2.6),
                    self::col('revenue', 'Revenue', 'money', 2.0),
                ];

            case 'leases':
                return [
                    self::col('lease_code', 'Lease', 'text', 2.4),
                    self::col('property_code', 'Code', 'text', 2.0, false),
                    self::col('property_title', 'Property', 'text', 3.2),
                    self::col('tenant_name', 'Tenant', 'text', 2.6),
                    self::col('start_date', 'Started', 'date', 1.8),
                    self::col('end_date', 'Ends', 'date', 1.8),
                    self::col('days_left', 'Days left', 'int', 1.4, false),
                    self::col('rent_amount', 'Rent', 'money', 1.8),
                    self::col('outstanding', 'Outstanding', 'money', 1.8),
                    self::col('arrears', 'Arrears', 'money', 1.8),
                ];

            case 'sales':
                return [
                    self::col('sale_code', 'Sale', 'text', 2.2),
                    self::col('sale_date', 'Sale date', 'date', 1.8),
                    self::col('property_code', 'Code', 'text', 2.0, false),
                    self::col('property_title', 'Property', 'text', 3.2),
                    self::col('buyer_name', 'Buyer', 'text', 2.6),
                    self::col('status', 'Status', 'label', 1.6),
                    self::col('agent_name', 'Agent', 'text', 2.4),
                    self::col('commission_amount', 'Commission', 'money', 1.8, false),
                    self::col('sale_amount', 'Value', 'money', 2.0),
                ];

            case 'reservations':
                return [
                    self::col('reservation_code', 'Reference', 'text', 2.4),
                    self::col('property_code', 'Code', 'text', 2.0, false),
                    self::col('property_title', 'Property', 'text', 3.2),
                    self::col('customer_name', 'Customer', 'text', 2.6),
                    self::col('reservation_date', 'Reserved', 'date', 1.8),
                    self::col('expiry_date', 'Expires', 'date', 1.8),
                    self::col('status', 'Status', 'label', 1.6),
                    self::col('deposit_amount', 'Deposit', 'money', 2.0),
                ];

            case 'maintenance':
                return [
                    self::col('request_code', 'Request', 'text', 2.4),
                    self::col('property_code', 'Code', 'text', 2.0, false),
                    self::col('property_title', 'Property', 'text', 3.2),
                    self::col('issue_type', 'Type', 'text', 2.4),
                    self::col('priority', 'Priority', 'label', 1.6),
                    self::col('status', 'Status', 'label', 1.8),
                    self::col('raised_on', 'Raised', 'date', 1.8),
                    self::col('completion_date', 'Completed', 'date', 1.8),
                    self::col('resolution_days', 'Days to resolve', 'int', 1.6, false),
                    self::col('actual_cost', 'Cost', 'money', 1.8),
                ];
        }

        return [];
    }

    // ─── Shared furniture ──────────────────────────────────────────────

    /**
     * Period, comparison, scope and provenance — the masthead's content.
     *
     * Worded exactly as views/admin/reports/_report_header.php words it, so
     * an exported report and the screen it came off make the same statements
     * about what they cover. The comparison entry exists only when comparison
     * is on, for the same reason it does on screen: nothing is drawn that is
     * not true.
     *
     * @return array<int,array{term:string,value:string,sub:?string}>
     */
    private static function meta(array $window, array $filters, bool $compare): array
    {
        $applied = reportFilterCount($filters);
        $user    = getCurrentUser();

        $out = [[
            'term'  => 'Period',
            'value' => $window['label'] . ($window['is_partial'] ? ' (so far)' : ''),
            'sub'   => formatDate($window['from']) . ' – ' . formatDate($window['to_capped']),
        ]];

        if ($compare) {
            $out[] = [
                'term'  => 'Compared with',
                'value' => 'Previous period',
                'sub'   => formatDate($window['prev_from']) . ' – ' . formatDate($window['prev_to'])
                         . ' · ' . (int) $window['days'] . ' days each',
            ];
        }

        $out[] = [
            'term'  => 'Scope',
            'value' => $applied > 0
                ? $applied . ($applied === 1 ? ' filter applied' : ' filters applied')
                : 'Whole portfolio',
            'sub'   => $applied > 0
                ? 'Narrowed — every figure below reflects it'
                : 'Everything you have access to',
        ];

        $out[] = [
            'term'  => 'Prepared for',
            'value' => (string) ($user['full_name'] ?? 'Signed-in user'),
            'sub'   => 'Access scope: ' . uiLabel((string) ($user['role'] ?? '')),
        ];

        $out[] = [
            'term'  => 'Generated',
            'value' => formatDateTime(date('Y-m-d H:i:s')),
            'sub'   => null,
        ];

        return $out;
    }

    /**
     * The active filters, named the way the toolbar's chips name them.
     *
     * @return array<int,array{label:string,value:string}>
     */
    private static function filterList(array $filters): array
    {
        $agents = reportAgentOptions();
        $out    = [];

        foreach (reportFilterSpec() as $name => $spec) {
            $value = $filters[$name] ?? null;
            if ($value === null) {
                continue;
            }

            $out[] = [
                'label' => $spec['label'],
                'value' => match ($name) {
                    'agent'    => $agents[(int) $value] ?? ('Agent #' . (int) $value),
                    'category' => categoryLabel((string) $value),
                    'property' => 'Property #' . (int) $value,
                    'owner'    => 'Owner #' . (int) $value,
                    default    => uiLabel((string) $value),
                },
            ];
        }

        return $out;
    }

    /**
     * The derived findings, flattened.
     *
     * The controller's insight rules already decided what is true; nothing is
     * re-evaluated and nothing is added. An empty list is a legitimate
     * outcome and the exports say so rather than filling the space.
     *
     * @return array<int,array{label:string,text:string,metric:?string,tone:string}>
     */
    private static function insights(array $insights): array
    {
        $out = [];

        foreach ($insights as $i) {
            $out[] = [
                'label'  => (string) ($i['label'] ?? ''),
                // The rules write for HTML and use entities for typography.
                'text'   => html_entity_decode((string) ($i['text'] ?? ''), ENT_QUOTES, 'UTF-8'),
                'metric' => isset($i['metric']) && $i['metric'] !== null ? (string) $i['metric'] : null,
                'tone'   => (string) ($i['tone'] ?? 'info'),
            ];
        }

        return $out;
    }

    /**
     * The data-quality panel: where the database contradicts itself.
     *
     * Built from the same two detectors the screen's panel is built from, and
     * carrying the same wording. Nothing is corrected here — the counts are
     * the finding.
     *
     * The amount stays a number rather than becoming "$600.00" here. A
     * workbook column of formatted strings cannot be summed, and each format
     * knows how to print money for itself.
     *
     * @return array<int,array{label:string,count:int,text:string,amount:?float}>
     */
    private static function quality(array $p): array
    {
        $dq  = $p['dataQuality'] ?? null;
        $out = [];

        if ($dq && $dq['payments']['count'] > 0) {
            $n = (int) $dq['payments']['count'];
            $out[] = [
                'label' => 'Payment classification',
                'count' => $n,
                'text'  => $n === 1
                    ? 'One payment is filed against a contract of a different kind from its own type. '
                      . 'Revenue counts it by the contract it names.'
                    : sprintf(
                        '%d payments are filed against contracts of a different kind from their own type. '
                        . 'Revenue counts them by the contract they name.',
                        $n
                    ),
                'amount' => (float) $dq['payments']['amount'],
            ];
        }

        foreach ($dq['states'] ?? [] as $state) {
            if ((int) $state['count'] === 0) {
                continue;
            }
            $out[] = [
                'label'  => (string) $state['label'],
                'count'  => (int) $state['count'],
                'text'   => (string) $state['detail'],
                'amount' => null,
            ];
        }

        // Window-bounded, so it is not part of reportDataQuality() — but it
        // is on the same panel on screen and belongs on the same one here.
        $future = $p['futureDated'] ?? null;
        if ($future && (int) $future['count'] > 0) {
            $out[] = [
                'label' => 'Dated ahead of today',
                'count' => (int) $future['count'],
                'text'  => 'Paid records dated after today. Held out of collected revenue until '
                         . 'their date arrives, and reported here so a reader reconciling against '
                         . 'the payments register does not find an unexplained gap.',
                'amount' => (float) $future['amount'],
            ];
        }

        $unattributed = $p['unattributed'] ?? null;
        if ($unattributed && (int) $unattributed['count'] > 0) {
            $out[] = [
                'label' => 'Revenue with no agent',
                'count' => (int) $unattributed['count'],
                'text'  => 'Collected revenue taken on properties with nobody assigned. Counted in the '
                         . 'company total and absent from every agent row, which is the whole of the '
                         . 'gap between the two.',
                'amount' => (float) $unattributed['amount'],
            ];
        }

        return $out;
    }

    // ─── Small builders ────────────────────────────────────────────────

    /**
     * One column definition.
     *
     * `$w` is a relative weight: the PDF divides the text column by the sum
     * of the weights, and the workbook turns it into a character width. One
     * number rather than two so a column can never be wide in one format and
     * cramped in the other.
     */
    private static function col(
        string $key,
        string $label,
        string $type = 'text',
        float $w = 2.0,
        bool $inPdf = true
    ): array {
        return ['key' => $key, 'label' => $label, 'type' => $type, 'w' => $w, 'pdf' => $inPdf];
    }

    /** One KPI tile, in the order and wording the screen uses. */
    private static function kpi(string $label, string $value, string $context = '', ?array $delta = null): array
    {
        return [
            'label'   => $label,
            'value'   => $value,
            'context' => $context,
            'delta'   => $delta === null ? null : (string) $delta['label'],
        ];
    }

    /**
     * A time series as a table plus the numbers a bar chart is drawn from.
     *
     * The screen draws these as Chart.js line and bar cards, each of which
     * already carries its own data table underneath for anyone who cannot see
     * the canvas. The export carries the same table, and the PDF draws the
     * same shape from the same array — every bucket keyed on `total`, which
     * is what fillSeries() names its value.
     *
     * A previous-period series is aligned by *position*, exactly as the
     * Overview's chart aligns it: bucket three is "days 15 to 21 of the
     * period" in both windows, because revenueComparisonSeries() folded the
     * previous window onto this one's offsets. Aligning by calendar label
     * instead would set 1 August against 1 July and call the difference a
     * trend.
     */
    private static function series(
        string $title,
        string $note,
        array $buckets,
        string $label,
        string $type,
        string $grain,
        array $previous = [],
        string $previousLabel = 'Previous period'
    ): array {
        $columns = [self::col('label', ucfirst($grain), 'text', 2.0), self::col('value', $label, $type, 2.0)];
        $rows    = [];
        $chart   = ['labels' => [], 'values' => []];

        foreach (array_values($buckets) as $i => $b) {
            $row = [
                'label' => (string) ($b['label'] ?? ''),
                'value' => (float) ($b['total'] ?? 0),
            ];

            if ($previous) {
                $row['previous'] = isset($previous[$i]) ? (float) ($previous[$i]['total'] ?? 0) : null;
            }

            $rows[] = $row;
            $chart['labels'][] = $row['label'];
            $chart['values'][] = $row['value'];
        }

        if ($previous) {
            $columns[] = self::col('previous', $previousLabel, $type, 2.0);
        }

        $chart['unit'] = $type === 'money' ? 'currency' : 'number';

        return [
            'title'   => $title,
            'note'    => $note,
            'columns' => $columns,
            'rows'    => $rows,
            'chart'   => $chart,
            'empty'   => 'Nothing was recorded in this period.',
        ];
    }

    /**
     * Whether a set of series rows holds a single non-zero figure.
     *
     * The label column is skipped, and so is null — a bucket with no rate is
     * not a bucket with a rate of nought, and neither of them is data.
     */
    private static function anyValue(array $rows): bool
    {
        foreach ($rows as $row) {
            foreach ($row as $key => $value) {
                if ($key !== 'label' && is_numeric($value) && (float) $value != 0.0) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Two aligned series — expected against settled, raised against
     * completed — as one table with the ratio between them.
     *
     * The rate column is null wherever the denominator is nought, which is
     * the same refusal the collection-performance chart makes on screen: a
     * bucket with nothing scheduled has no rate, and printing 0% there would
     * report a collections failure that never happened.
     */
    private static function pairedSeries(
        string $title,
        string $note,
        array $left,
        array $right,
        string $leftLabel,
        string $rightLabel,
        string $type,
        string $grain,
        bool $withRate = false,
        string $chart = 'left'
    ): array {
        $columns = [
            self::col('label', ucfirst($grain), 'text', 2.0),
            self::col('left', $leftLabel, $type, 2.0),
            self::col('right', $rightLabel, $type, 2.0),
        ];
        if ($withRate) {
            $columns[] = self::col('rate', 'Rate', 'percent', 1.4);
        }

        $rows = [];
        $plot = ['labels' => [], 'values' => []];

        foreach (array_values($left) as $i => $b) {
            $l = (float) ($b['total'] ?? 0);
            $r = isset($right[$i]) ? (float) ($right[$i]['total'] ?? 0) : 0.0;

            $row = ['label' => (string) ($b['label'] ?? ''), 'left' => $l, 'right' => $r];
            if ($withRate) {
                $row['rate'] = reportShare($r, $l);
            }

            $rows[] = $row;
            $plot['labels'][] = $row['label'];
            $plot['values'][] = $chart === 'right' ? $r : $l;
        }

        $plot['unit'] = $type === 'money' ? 'currency' : 'number';

        return [
            'title'   => $title,
            'note'    => $note,
            'columns' => $columns,
            'rows'    => $rows,
            'chart'   => $plot,
            'empty'   => 'Nothing was recorded in this period.',
        ];
    }

    // ─── Overview ──────────────────────────────────────────────────────

    private static function overview(array $p, bool $compare): array
    {
        $occupancy = $p['occupancy'];
        $ledger    = $p['ledger'];
        $inventory = $p['inventory'];
        $streams   = $p['streams'];
        $window    = $p['window'];

        $kpis = [
            self::kpi(
                'Collected revenue',
                formatCurrency((float) $p['revenue']),
                'Rent, sales and fees actually received',
                $p['previousRevenue'] !== null
                    ? reportDelta((float) $p['revenue'], (float) $p['previousRevenue'])
                    : null
            ),
            self::kpi(
                'Occupancy',
                reportPercent($occupancy['rate']),
                $occupancy['rentable'] > 0
                    ? sprintf(
                        '%d of %d rentable %s occupied',
                        $occupancy['occupied'],
                        $occupancy['rentable'],
                        $occupancy['rentable'] === 1 ? 'property' : 'properties'
                    )
                    : 'No rentable property in scope'
            ),
            self::kpi(
                'Occupied properties',
                number_format((int) $occupancy['occupied']),
                sprintf(
                    'of %d rentable · %d vacant · %d listings in total',
                    $occupancy['rentable'],
                    $occupancy['vacant'],
                    $inventory['lifecycle']['active_listings']
                )
            ),
            self::kpi(
                'Outstanding arrears',
                formatCurrency((float) $ledger['arrears']),
                (int) $ledger['overdue_count'] > 0
                    ? sprintf(
                        '%d overdue %s · as at today, not for the period',
                        (int) $ledger['overdue_count'],
                        (int) $ledger['overdue_count'] === 1 ? 'instalment' : 'instalments'
                    )
                    : 'Nothing overdue'
            ),
        ];

        $sections = [
            self::series(
                'Revenue performance',
                'Collected revenue by ' . $window['grain']
                . ($compare ? ', against the previous period of equal length' : ''),
                $p['series'],
                'Collected',
                'money',
                (string) $window['grain'],
                $compare ? $p['previousSeries'] : []
            ),
            self::streamSection($streams),
            [
                'title'   => 'Portfolio distribution',
                'note'    => 'Commercial state, derived from leases, reservations and completed sales. '
                           . 'Vacant counts rentable properties with no live lease; sale-only listings '
                           . 'are not rentable and are not counted as vacant.',
                'columns' => [self::col('label', 'State', 'text', 3.0), self::col('value', 'Properties', 'int', 1.5)],
                'rows'    => [
                    ['label' => 'Occupied', 'value' => (int) $inventory['commercial']['occupied']],
                    ['label' => 'Vacant',   'value' => (int) $inventory['commercial']['vacant']],
                    ['label' => 'Reserved', 'value' => (int) $inventory['commercial']['reserved']],
                    ['label' => 'Sold',     'value' => (int) $inventory['commercial']['sold']],
                ],
                'empty'   => 'There are no properties in scope for the current filters.',
            ],
        ];

        return [
            'kpis'     => $kpis,
            'sections' => $sections,
            'records'  => [
                'title'   => 'Top earning properties',
                'note'    => 'The five properties that collected the most in this period — the same '
                           . 'ranking the report shows on screen, and not a full property list.',
                'columns' => [
                    self::col('property_code', 'Code', 'text', 2.4),
                    self::col('title', 'Property', 'text', 4.0),
                    self::col('category', 'Category', 'label', 2.0),
                    self::col('location', 'Location', 'text', 2.4),
                    self::col('state', 'State', 'text', 1.6),
                    self::col('agent_name', 'Agent', 'text', 2.6),
                    self::col('payments', 'Payments', 'int', 1.5),
                    self::col('collected', 'Collected', 'money', 2.2),
                ],
                'rows'    => array_map(
                    static fn(array $r): array => [
                        'property_code' => (string) ($r['property_code'] ?? ''),
                        'title'         => (string) ($r['title'] ?? ''),
                        'category'      => categoryLabel((string) ($r['category'] ?? '')),
                        'location'      => ($r['location'] ?? '') !== '' ? (string) $r['location'] : null,
                        'state'         => self::propertyState($r),
                        'agent_name'    => ($r['agent_name'] ?? null) !== null ? (string) $r['agent_name'] : null,
                        'payments'      => (int) ($r['payments'] ?? 0),
                        'collected'     => (float) ($r['collected'] ?? 0),
                    ],
                    $p['topProperties']
                ),
                'total'   => null,
                'fetch'   => null,
                'empty'   => 'No property collected anything in this period.',
            ],
        ];
    }

    /** Revenue split by the contract the money was taken against. */
    private static function streamSection(array $streams): array
    {
        $named = ['rental' => 'Rentals', 'sale' => 'Sales', 'reservation' => 'Reservations'];
        $rows  = [];

        foreach ($named as $key => $label) {
            $rows[] = [
                'label'  => $label,
                'amount' => (float) $streams[$key],
                'share'  => reportShare((float) $streams[$key], (float) $streams['total']),
            ];
        }

        return [
            'title'   => 'Revenue breakdown',
            'note'    => 'By the contract the money was taken against. Reservation deposits are kept '
                       . 'as their own line and never folded into sales.',
            'columns' => [
                self::col('label', 'Source', 'text', 3.0),
                self::col('amount', 'Collected', 'money', 2.0),
                self::col('share', 'Share', 'percent', 1.5),
            ],
            'rows'    => $rows,
            'total'   => ['label' => 'Total', 'amount' => (float) $streams['total'], 'share' => null],
            'empty'   => 'No revenue was collected in this period, so there is nothing to break down.',
        ];
    }

    /** A property's commercial state, derived exactly as _top_properties.php derives it. */
    private static function propertyState(array $row): string
    {
        return match (true) {
            !empty($row['is_sold'])     => 'Sold',
            !empty($row['is_occupied']) => 'Occupied',
            !empty($row['is_reserved']) => 'Reserved',
            default                     => 'Available',
        };
    }

    // ─── Financial ─────────────────────────────────────────────────────

    private static function financial(array $p, bool $compare): array
    {
        $ledger   = $p['ledger'];
        $streams  = $p['streams'];
        $window   = $p['window'];
        $prevL    = $p['previousLedger'];
        $prevS    = $p['previousStreams'];
        $expected = (float) $ledger['expected'];
        $settled  = (float) $ledger['settled_on_ledger'];
        $notYet   = (float) ($ledger['not_yet_due'] ?? 0);

        $kpis = [
            self::kpi(
                'Collected revenue',
                formatCurrency((float) $p['revenue']),
                'Money actually received, dated by the day it arrived',
                $prevS !== null ? reportDelta((float) $p['revenue'], (float) $prevS['total']) : null
            ),
            self::kpi(
                'Expected rent',
                formatCurrency($expected),
                'Scheduled rent falling due in this period — tenancies only',
                $prevL !== null ? reportDelta($expected, (float) $prevL['expected']) : null
            ),
            self::kpi(
                'Collection rate',
                reportPercent($ledger['collection_rate']),
                $expected > 0
                    ? formatCurrency($settled) . ' settled of ' . formatCurrency($expected) . ' scheduled'
                    : 'No rent was scheduled in this period',
                ($prevL !== null && $prevL['collection_rate'] !== null && $ledger['collection_rate'] !== null)
                    ? reportDelta((float) $ledger['collection_rate'], (float) $prevL['collection_rate'])
                    : null
            ),
            self::kpi(
                'Outstanding balance',
                formatCurrency((float) $ledger['outstanding']),
                $notYet > 0
                    ? formatCurrency($notYet) . ' of this is not yet due'
                    : 'All of it has already fallen due'
            ),
            self::kpi(
                'Rent arrears',
                formatCurrency((float) $ledger['arrears']),
                (int) $ledger['overdue_count'] > 0
                    ? sprintf(
                        '%d overdue %s · the late part of the balance',
                        (int) $ledger['overdue_count'],
                        (int) $ledger['overdue_count'] === 1 ? 'instalment' : 'instalments'
                    )
                    : 'Nothing overdue'
            ),
        ];

        $sections = [];

        if ($compare && $prevL !== null && $prevS !== null) {
            $sections[] = self::comparison([
                ['Collected revenue', (float) $streams['total'], (float) $prevS['total'], 'money'],
                ['Expected rent', $expected, (float) $prevL['expected'], 'money'],
                ['Settled on the ledger', $settled, (float) $prevL['settled_on_ledger'], 'money'],
                ['Collection rate', $ledger['collection_rate'], $prevL['collection_rate'], 'percent'],
                ['Outstanding balance', $ledger['outstanding'], $prevL['outstanding'], 'money'],
                ['Rent arrears', $ledger['arrears'], $prevL['arrears'], 'money'],
            ], 'Outstanding and arrears are running balances. The schedule records the state a row is '
             . 'in now, not the state it was in during the previous window, so there is no previous '
             . 'figure to set against them and none is invented.');
        }

        $sections[] = self::pairedSeries(
            'Expected against settled rent',
            'Both series sit on the due-date axis, by ' . $window['grain'] . ', which is what makes '
            . 'the collection rate a like-for-like measure. Cash received is a different question on '
            . 'a different axis, and is the collected-revenue figure above. A bucket where nothing '
            . 'fell due has no rate rather than a rate of nought.',
            $p['ledgerSeries']['expected'],
            $p['ledgerSeries']['settled'],
            'Expected',
            'Settled',
            'money',
            (string) $window['grain'],
            true
        );
        $sections[count($sections) - 1]['empty'] = 'No rent was scheduled to fall due in this period.';

        $sections[] = self::streamSection($streams);

        $sections[] = [
            'title'   => 'Outstanding, split by whether it is late',
            'note'    => 'Rent that falls due next month is owed and not late. Rent that fell due and '
                       . 'is unpaid is both. The two are never added into one figure.',
            'columns' => [self::col('label', 'Balance', 'text', 3.0), self::col('amount', 'Amount', 'money', 2.0)],
            'rows'    => [
                ['label' => 'In arrears (already due)', 'amount' => (float) $ledger['arrears']],
                ['label' => 'Not yet due',              'amount' => $notYet],
            ],
            'total'   => ['label' => 'Outstanding balance', 'amount' => (float) $ledger['outstanding']],
            'empty'   => 'Nothing is outstanding.',
        ];

        $deposits = $p['deposits'] ?? ['deposits' => 0.0, 'refunds' => 0.0];
        $sections[] = [
            'title'   => 'Money held, not earned',
            'note'    => 'A deposit is a liability held against a tenancy and a refund is money going '
                       . 'out. Neither is earnings, and neither is counted in collected revenue.',
            'columns' => [self::col('label', 'Kind', 'text', 3.0), self::col('amount', 'Amount', 'money', 2.0)],
            'rows'    => [
                ['label' => 'Deposits taken', 'amount' => (float) $deposits['deposits']],
                ['label' => 'Refunds paid',   'amount' => (float) $deposits['refunds']],
            ],
            'empty'   => 'No deposit or refund has been recorded.',
        ];

        return [
            'kpis'     => $kpis,
            'sections' => $sections,
            'records'  => [
                'title'   => 'Rent ledger by property',
                'note'    => 'The twenty properties with the most rent scheduled in this period — the '
                           . 'same ranking the report shows on screen.',
                'columns' => [
                    self::col('property_code', 'Code', 'text', 2.4),
                    self::col('title', 'Property', 'text', 4.0),
                    self::col('category', 'Category', 'label', 2.0),
                    self::col('expected', 'Expected', 'money', 2.0),
                    self::col('settled', 'Settled', 'money', 2.0),
                    self::col('rate', 'Rate', 'percent', 1.4),
                    self::col('outstanding', 'Outstanding', 'money', 2.0),
                    self::col('arrears', 'Arrears', 'money', 2.0),
                ],
                'rows'    => array_map(
                    static fn(array $r): array => [
                        'property_code' => (string) ($r['property_code'] ?? ''),
                        'title'         => (string) ($r['title'] ?? ''),
                        'category'      => categoryLabel((string) ($r['category'] ?? '')),
                        'expected'      => (float) $r['expected'],
                        'settled'       => (float) $r['settled'],
                        'rate'          => reportShare((float) $r['settled'], (float) $r['expected']),
                        'outstanding'   => (float) $r['outstanding'],
                        'arrears'       => (float) $r['arrears'],
                    ],
                    $p['byProperty']
                ),
                'total'   => null,
                'fetch'   => null,
                'empty'   => 'No rent was scheduled against any property in this period.',
            ],
        ];
    }

    /**
     * A this-against-previous table.
     *
     * A row whose previous value is null prints "Not available" and no
     * movement. That is the case the reporting module cares most about: a
     * running balance has no previous-period equivalent, and filling one in
     * from today's figure would be the worst possible answer.
     *
     * @param array<int,array{0:string,1:mixed,2:mixed,3:string}> $rows
     */
    private static function comparison(array $rows, string $note): array
    {
        $out = [];

        foreach ($rows as [$label, $current, $previous]) {
            // A null on either side means the figure has no previous-period
            // equivalent — a running balance, or an average over no records.
            // It prints "Not available" and no movement, never a nought.
            $delta = ($current !== null && $previous !== null)
                ? reportDelta((float) $current, (float) $previous)
                : null;

            $out[] = [
                'label'    => $label,
                'current'  => $current,
                'previous' => $previous,
                'change'   => $delta === null ? 'Not available' : $delta['label'],
            ];
        }

        return [
            'title'   => 'This period against the previous',
            'note'    => $note,
            'columns' => [
                self::col('label', 'Figure', 'text', 3.4),
                self::col('current', 'This period', 'auto', 2.0),
                self::col('previous', 'Previous period', 'auto', 2.0),
                self::col('change', 'Movement', 'text', 2.6),
            ],
            'types'   => array_column($rows, 3),
            'rows'    => $out,
            'empty'   => 'Comparison is not available for this report.',
        ];
    }

    // ─── Properties ────────────────────────────────────────────────────

    private static function properties(array $p, CoreAnalytics $analytics): array
    {
        $state     = $p['state'];
        $inventory = $p['inventory'];
        $occupancy = $p['occupancy'];
        $locations = $p['locations'];

        // Label, value and context are the screen's, word for word. A tile
        // whose supporting sentence is rewritten for the export is a tile the
        // reader has to reconcile by hand against the page they printed it
        // from, which is the opposite of what an export is for.
        $total   = (int) $state['total'];
        $pending = (int) $inventory['lifecycle']['pending_approval'];

        $kpis = [
            self::kpi(
                'Total portfolio',
                number_format((int) $inventory['lifecycle']['active_listings']),
                sprintf(
                    '%d approved %s %d archived %s as at today',
                    (int) $inventory['lifecycle']['active_listings'],
                    "·",
                    (int) $inventory['lifecycle']['archived'],
                    "·"
                )
            ),
            self::kpi(
                'Occupancy',
                reportPercent($occupancy['rate']),
                (int) $occupancy['rentable'] > 0
                    ? sprintf(
                        '%d of %d rentable %s occupied',
                        (int) $occupancy['occupied'],
                        (int) $occupancy['rentable'],
                        (int) $occupancy['rentable'] === 1 ? 'property' : 'properties'
                    )
                    : 'No rentable property in scope'
            ),
            self::kpi(
                'Available',
                number_format((int) $state['available']),
                $total > 0
                    ? reportPercent(reportShare((float) $state['available'], (float) $total)) . ' of approved inventory'
                    : 'No approved inventory in scope'
            ),
            self::kpi(
                'Under reservation',
                number_format((int) $state['reserved']),
                (int) $state['reserved'] > 0
                    ? 'Held on a reservation that has not expired'
                    : 'No unexpired reservations'
            ),
            self::kpi(
                'Sold',
                number_format((int) $state['sold']),
                'Properties with a completed sale on record'
            ),
            self::kpi(
                'Awaiting approval',
                number_format($pending),
                $pending > 0
                    ? 'Not live inventory until an administrator signs it off'
                    : 'Nothing waiting on approval'
            ),
        ];

        $sections = [
            [
                'title'   => 'Commercial state',
                'note'    => 'Proved by a record — a live lease, an unexpired hold, a completed sale — '
                           . 'rather than by the status column, which the audit found is not maintained.',
                'columns' => [self::col('label', 'State', 'text', 3.0), self::col('value', 'Properties', 'int', 1.5)],
                'rows'    => [
                    ['label' => 'Available', 'value' => (int) $state['available']],
                    ['label' => 'Occupied',  'value' => (int) $state['occupied']],
                    ['label' => 'Reserved',  'value' => (int) $state['reserved']],
                    ['label' => 'Sold',      'value' => (int) $state['sold']],
                ],
                'empty'   => 'There are no properties in scope for the current filters.',
            ],
            [
                'title'   => 'Lifecycle',
                'note'    => 'Where each listing stands in the approval and archive workflow. '
                           . 'Current state — the schema records no history of it, so there is no '
                           . 'previous period to set it against.',
                'columns' => [self::col('label', 'Stage', 'text', 3.0), self::col('value', 'Properties', 'int', 1.5)],
                'rows'    => [
                    ['label' => 'Approved',          'value' => (int) $inventory['lifecycle']['active_listings']],
                    ['label' => 'Awaiting approval', 'value' => (int) $inventory['lifecycle']['pending_approval']],
                    ['label' => 'Rejected',          'value' => (int) $inventory['lifecycle']['rejected']],
                    ['label' => 'Withdrawn',         'value' => (int) $inventory['lifecycle']['withdrawn']],
                    ['label' => 'Archived',          'value' => (int) $inventory['lifecycle']['archived']],
                ],
                'empty'   => 'There are no properties in scope.',
            ],
            self::composition('Composition by category', 'Approved listings, grouped by what they are.', $p['composition']),
            self::composition('Rent against sale inventory', 'What each listing is on the market for.', $p['listingIntent']),
        ];

        $sections[] = [
            'title'   => 'Location',
            'note'    => empty($locations['usable'])
                ? 'Location is free text on the property record and is too varied here to read as a '
                  . 'geography — ' . (int) $locations['distinct'] . ' distinct values across '
                  . (int) $locations['total'] . ' properties. Listed as recorded, and not treated as a dimension.'
                : 'As recorded on the property. ' . (int) $locations['blank'] . ' with no location set.',
            'columns' => [
                self::col('location', 'Location as recorded', 'text', 4.0),
                self::col('properties', 'Properties', 'int', 1.5),
                self::col('share', 'Share', 'percent', 1.5),
            ],
            'rows'    => array_map(
                static fn(array $r): array => [
                    'location'   => (string) $r['location'],
                    'properties' => (int) $r['properties'],
                    'share'      => reportShare((float) $r['properties'], (float) $locations['total']),
                ],
                $locations['rows']
            ),
            'empty'   => 'No property in scope has a location recorded.',
        ];

        return [
            'kpis'     => $kpis,
            'sections' => $sections,
            'records'  => [
                'title'   => 'Property register',
                'note'    => 'Every unarchived property in your scope, with the revenue it collected '
                           . 'in this period. "State" is derived from records; "Recorded" is what the '
                           . 'property row itself claims, and the two disagreeing is what the '
                           . 'data-quality section counts.',
                'columns' => [
                    self::col('property_code', 'Code', 'text', 2.4),
                    self::col('title', 'Property', 'text', 4.0),
                    self::col('category', 'Category', 'label', 2.0),
                    self::col('location', 'Location', 'text', 2.4),
                    self::col('state', 'State', 'text', 1.6),
                    self::col('recorded_status', 'Recorded', 'label', 1.6, false),
                    self::col('property_type', 'Intent', 'label', 1.4, false),
                    self::col('agent_name', 'Agent', 'text', 2.6),
                    self::col('revenue', 'Revenue', 'money', 2.0),
                ],
                'rows'    => array_map([self::class, 'portfolioRow'], $p['portfolio']),
                'total'   => (int) $p['portfolioTotal'],
                'fetch'   => static fn(int $limit, int $offset): array => array_map(
                    [self::class, 'portfolioRow'],
                    $analytics->portfolioTable($limit, $offset)
                ),
                'empty'   => 'No property is in scope for the current filters.',
            ],
        ];
    }

    /** @return array<string,mixed> */
    private static function portfolioRow(array $r): array
    {
        return [
            'property_code'   => (string) ($r['property_code'] ?? ''),
            'title'           => (string) ($r['title'] ?? ''),
            'category'        => categoryLabel((string) ($r['category'] ?? '')),
            'location'        => ($r['location'] ?? '') !== '' ? (string) $r['location'] : null,
            'state'           => self::propertyState($r),
            'recorded_status' => uiLabel((string) ($r['recorded_status'] ?? '')),
            // The register's own wording, not uiLabel()'s: the screen says
            // "For rent" and an export saying "Rent" beside the same row
            // reads as a different column.
            'property_type'   => [
                'rent' => 'For rent',
                'sale' => 'For sale',
                'both' => 'Rent or sale',
            ][(string) ($r['property_type'] ?? '')] ?? uiLabel((string) ($r['property_type'] ?? '')),
            'agent_name'      => ($r['agent_name'] ?? null) !== null ? (string) $r['agent_name'] : null,
            'revenue'         => (float) ($r['revenue'] ?? 0),
        ];
    }

    /** A portfolioGroupedBy() result as a table. */
    private static function composition(string $title, string $note, array $groups): array
    {
        $total = 0;
        foreach ($groups as $g) {
            $total += (int) $g['properties'];
        }

        return [
            'title'   => $title,
            'note'    => $note,
            'columns' => [
                self::col('label', 'Group', 'text', 3.0),
                self::col('properties', 'Properties', 'int', 1.5),
                self::col('occupied', 'Occupied', 'int', 1.5),
                self::col('sold', 'Sold', 'int', 1.5),
                self::col('share', 'Share', 'percent', 1.5),
            ],
            'rows'    => array_map(
                static fn(array $g): array => [
                    'label'      => (string) $g['label'],
                    'properties' => (int) $g['properties'],
                    'occupied'   => (int) $g['occupied'],
                    'sold'       => (int) $g['sold'],
                    'share'      => reportShare((float) $g['properties'], (float) $total),
                ],
                $groups
            ),
            'empty'   => 'There is nothing in scope to group.',
        ];
    }

    // ─── Rentals ───────────────────────────────────────────────────────

    private static function rentals(array $p, CoreAnalytics $analytics): array
    {
        $summary   = $p['summary'];
        $occupancy = $p['occupancy'];
        $ledger    = $p['ledger'];
        $expiry    = $p['expiry'];
        $window    = $p['window'];

        $bucket = static function (array $buckets, string $key): int {
            foreach ($buckets as $b) {
                if ($b['key'] === $key) {
                    return (int) $b['count'];
                }
            }
            return 0;
        };
        $soon = $bucket($expiry, 'd7') + $bucket($expiry, 'd30') + $bucket($expiry, 'd60');
        $gone = $bucket($expiry, 'expired');

        $kpis = [
            self::kpi(
                'Active leases',
                number_format((int) $summary['active']),
                $summary['average_rent'] !== null
                    ? formatCurrency((float) $summary['rent_roll']) . ' rent roll · avg '
                      . formatCurrency((float) $summary['average_rent'])
                    : 'No tenancy running'
            ),
            self::kpi(
                'Occupancy',
                reportPercent($occupancy['rate']),
                (int) $occupancy['rentable'] > 0
                    ? sprintf(
                        '%d let of %d rentable · %d vacant',
                        (int) $occupancy['occupied'],
                        (int) $occupancy['rentable'],
                        (int) $occupancy['vacant']
                    )
                    : 'No rentable property in scope'
            ),
            self::kpi(
                'Expected rent',
                formatCurrency((float) $ledger['expected']),
                'Scheduled to fall due in ' . $window['label']
            ),
            self::kpi(
                'Collection rate',
                reportPercent($ledger['collection_rate']),
                (float) $ledger['expected'] > 0
                    ? formatCurrency((float) $ledger['settled_on_ledger']) . ' settled of '
                      . formatCurrency((float) $ledger['expected'])
                    : 'No rent scheduled in this period'
            ),
            self::kpi(
                'Outstanding rent',
                formatCurrency((float) $ledger['outstanding']),
                (float) ($ledger['not_yet_due'] ?? 0) > 0
                    ? formatCurrency((float) $ledger['not_yet_due']) . ' of it not yet due · '
                      . formatCurrency((float) $ledger['arrears']) . ' in arrears'
                    : 'All of it has fallen due'
            ),
            self::kpi(
                'Expiring soon',
                number_format($soon),
                $gone > 0
                    ? sprintf('within 60 days · %d already expired', $gone)
                    : ($soon > 0 ? 'Tenancies ending within 60 days' : 'Nothing ending within 60 days')
            ),
        ];

        $sections = [
            self::pairedSeries(
                'Expected against settled rent',
                'Both series sit on the due-date axis, by ' . $window['grain'] . '. A bucket where '
                . 'nothing fell due has no rate rather than a rate of nought.',
                $p['ledgerSeries']['expected'],
                $p['ledgerSeries']['settled'],
                'Expected',
                'Settled',
                'money',
                (string) $window['grain'],
                true
            ),
            [
                'title'   => 'Occupancy',
                'note'    => 'Rentable stock only. Sale-only listings and sold units are not rentable '
                           . 'and are not counted as vacant.',
                'columns' => [self::col('label', 'State', 'text', 3.0), self::col('value', 'Properties', 'int', 1.5)],
                'rows'    => [
                    ['label' => 'Occupied', 'value' => (int) $occupancy['occupied']],
                    ['label' => 'Vacant',   'value' => (int) $occupancy['vacant']],
                ],
                'empty'   => 'No rentable property is in scope.',
            ],
            [
                'title'   => 'When tenancies end',
                'note'    => 'Active leases by how long they have left. An expired lease still marked '
                           . 'active is counted on its own line — it has already gone, which is a '
                           . 'different and more urgent problem than one ending soon.',
                'columns' => [self::col('label', 'Ending', 'text', 3.0), self::col('count', 'Leases', 'int', 1.5)],
                'rows'    => array_map(
                    static fn(array $b): array => ['label' => (string) $b['label'], 'count' => (int) $b['count']],
                    $expiry
                ),
                'chart'   => [
                    'labels' => array_column($expiry, 'label'),
                    'values' => array_map(static fn(array $b): float => (float) $b['count'], $expiry),
                    'unit'   => 'number',
                ],
                'empty'   => 'There are no active tenancies in scope.',
            ],
            [
                'title'   => 'Outstanding rent, split by whether it is late',
                'note'    => 'Owed and late are not the same money.',
                'columns' => [self::col('label', 'Balance', 'text', 3.0), self::col('amount', 'Amount', 'money', 2.0)],
                'rows'    => [
                    ['label' => 'In arrears (already due)', 'amount' => (float) $ledger['arrears']],
                    ['label' => 'Not yet due',              'amount' => (float) ($ledger['not_yet_due'] ?? 0)],
                ],
                'total'   => ['label' => 'Outstanding', 'amount' => (float) $ledger['outstanding']],
                'empty'   => 'Nothing is outstanding.',
            ],
        ];

        return [
            'kpis'     => $kpis,
            'sections' => $sections,
            'records'  => [
                'title'   => 'Active tenancies',
                'note'    => 'Live leases in your scope, soonest to end first. Expected and settled are '
                           . 'this period; outstanding and arrears are running balances as at today.',
                'columns' => [
                    self::col('lease_code', 'Lease', 'text', 2.4),
                    self::col('property_code', 'Code', 'text', 1.6, false),
                    self::col('property_title', 'Property', 'text', 3.4),
                    self::col('tenant_name', 'Tenant', 'text', 2.6),
                    self::col('start_date', 'Started', 'date', 1.8),
                    self::col('end_date', 'Ends', 'date', 1.8),
                    self::col('days_left', 'Days left', 'int', 1.4, false),
                    self::col('rent_amount', 'Rent', 'money', 1.8),
                    self::col('expected', 'Expected', 'money', 1.8),
                    self::col('settled', 'Settled', 'money', 1.8, false),
                    self::col('rate', 'Rate', 'percent', 1.3, false),
                    self::col('outstanding', 'Outstanding', 'money', 1.8),
                    self::col('arrears', 'Arrears', 'money', 1.8),
                ],
                'rows'    => array_map([self::class, 'leaseRow'], $p['leases']),
                'total'   => (int) $p['leaseTotal'],
                'fetch'   => static fn(int $limit, int $offset): array => array_map(
                    [self::class, 'leaseRow'],
                    $analytics->leaseTable('active', $limit, $offset)
                ),
                'empty'   => 'There are no active tenancies in scope.',
            ],
        ];
    }

    /** @return array<string,mixed> */
    private static function leaseRow(array $r): array
    {
        return [
            'lease_code'     => (string) ($r['lease_code'] ?? ''),
            'property_code'  => (string) ($r['property_code'] ?? ''),
            'property_title' => (string) ($r['property_title'] ?? ''),
            'tenant_name'    => ($r['tenant_name'] ?? null) !== null ? (string) $r['tenant_name'] : null,
            'start_date'     => $r['start_date'] ?: null,
            'end_date'       => $r['end_date'] ?: null,
            'days_left'      => (int) ($r['days_left'] ?? 0),
            'rent_amount'    => (float) ($r['rent_amount'] ?? 0),
            'expected'       => (float) ($r['expected'] ?? 0),
            'settled'        => (float) ($r['settled'] ?? 0),
            'rate'           => reportShare((float) ($r['settled'] ?? 0), (float) ($r['expected'] ?? 0)),
            'outstanding'    => (float) ($r['outstanding'] ?? 0),
            'arrears'        => (float) ($r['arrears'] ?? 0),
        ];
    }

    // ─── Sales ─────────────────────────────────────────────────────────

    private static function sales(array $p, CoreAnalytics $analytics, bool $compare): array
    {
        $summary  = $p['summary'];
        $pipeline = $p['pipeline'];
        $resv     = $p['reservations'];
        $previous = $p['previous'];
        $window   = $p['window'];

        $kpis = [
            self::kpi(
                'Deals recorded',
                number_format((int) $summary['total']),
                formatCurrency((float) $summary['total_value']) . ' across every status',
                $previous !== null
                    ? reportDelta((float) $summary['total'], (float) $previous['total'])
                    : null
            ),
            self::kpi(
                'Completed value',
                formatCurrency((float) $summary['completed_value']),
                'Contract value of completed sales — not cash received',
                $previous !== null
                    ? reportDelta((float) $summary['completed_value'], (float) $previous['completed_value'])
                    : null
            ),
            self::kpi(
                'Completed sales',
                number_format((int) $summary['completed']),
                $summary['average'] !== null
                    ? 'Averaging ' . formatCurrency((float) $summary['average']) . ' each'
                    : 'No sale has completed in this period',
                $previous !== null
                    ? reportDelta((float) $summary['completed'], (float) $previous['completed'])
                    : null
            ),
            self::kpi(
                'Pending',
                number_format((int) $summary['pending']),
                (int) $summary['pending'] > 0
                    ? formatCurrency((float) $summary['pending_value']) . ' of value awaiting completion'
                    : 'Nothing awaiting completion'
            ),
            self::kpi(
                'Cancelled',
                number_format((int) $summary['cancelled']),
                (int) $summary['cancelled'] > 0
                    ? formatCurrency((float) $summary['cancelled_value']) . ' of value fell through'
                    : 'Nothing cancelled in this period'
            ),
            self::kpi(
                'Live reservations',
                number_format((int) $resv['live']),
                (int) $resv['lapsed'] > 0
                    ? sprintf(
                        '%d lapsed but still marked active · %s held',
                        (int) $resv['lapsed'],
                        formatCurrency((float) $resv['lapsed_deposits'])
                    )
                    : ((int) $resv['live'] > 0
                        ? formatCurrency((float) $resv['live_deposits']) . ' held on deposit'
                        : 'No property is under an unexpired hold')
            ),
        ];

        $salesSeries = $p['salesSeries'];

        $sections = [
            [
                'title'   => 'Pipeline by status',
                'note'    => 'A pending sale is an intention and a completed one is a transaction. '
                           . 'They are never added together.',
                'columns' => [
                    self::col('label', 'Status', 'text', 3.0),
                    self::col('deals', 'Deals', 'int', 1.5),
                    self::col('value', 'Value', 'money', 2.0),
                ],
                'rows'    => array_map(
                    static fn(array $s): array => [
                        'label' => (string) $s['label'],
                        'deals' => (int) $s['deals'],
                        'value' => (float) $s['value'],
                    ],
                    $pipeline
                ),
                'empty'   => 'No sale was recorded in this period.',
            ],
            [
                'title'   => 'Sales value over time',
                'note'    => 'By ' . $window['grain'] . ', on the sale date. "Recorded" is every deal '
                           . 'dated in the bucket; "completed" is the subset that closed.',
                'columns' => [
                    self::col('label', ucfirst((string) $window['grain']), 'text', 2.0),
                    self::col('recorded', 'Recorded', 'money', 2.0),
                    self::col('completed', 'Completed', 'money', 2.0),
                ],
                'rows'    => array_map(
                    static fn(array $r, array $c): array => [
                        'label'     => (string) $r['label'],
                        'recorded'  => (float) $r['total'],
                        'completed' => (float) $c['total'],
                    ],
                    $salesSeries['recorded'],
                    $salesSeries['completed']
                ),
                'chart'   => [
                    'labels' => array_column($salesSeries['completed'], 'label'),
                    'values' => array_map(
                        static fn(array $b): float => (float) $b['total'],
                        $salesSeries['completed']
                    ),
                    'unit'   => 'currency',
                ],
                'empty'   => 'No sale was recorded in this period.',
            ],
            [
                'title'   => 'Deal value by category',
                'note'    => 'What kind of stock the book is made of.',
                'columns' => [
                    self::col('label', 'Category', 'text', 3.0),
                    self::col('deals', 'Deals', 'int', 1.4),
                    self::col('value', 'Value', 'money', 2.0),
                    self::col('completed', 'Completed', 'int', 1.4),
                    self::col('completed_value', 'Completed value', 'money', 2.0),
                ],
                'rows'    => array_map(
                    static fn(array $c): array => [
                        'label'           => categoryLabel((string) $c['category']),
                        'deals'           => (int) $c['deals'],
                        'value'           => (float) $c['value'],
                        'completed'       => (int) $c['completed'],
                        'completed_value' => (float) $c['completed_value'],
                    ],
                    $p['byCategory']
                ),
                'empty'   => 'No sale was recorded in this period.',
            ],
            [
                'title'   => 'Reservations',
                'note'    => 'Current state, not a period figure — a hold either stands today or it '
                           . 'does not, and the table keeps no history of when it stood. A lapsed hold '
                           . 'is past its expiry date and still marked active.',
                'columns' => [
                    self::col('label', 'State', 'text', 3.0),
                    self::col('count', 'Reservations', 'int', 1.6),
                    self::col('deposits', 'Deposits held', 'money', 2.0),
                ],
                'rows'    => [
                    ['label' => 'Live',      'count' => (int) $resv['live'],           'deposits' => (float) $resv['live_deposits']],
                    ['label' => 'Lapsed',    'count' => (int) $resv['lapsed'],         'deposits' => (float) $resv['lapsed_deposits']],
                    ['label' => 'Expired',   'count' => (int) $resv['marked_expired'], 'deposits' => null],
                    ['label' => 'Cancelled', 'count' => (int) $resv['cancelled'],      'deposits' => null],
                ],
                'empty'   => 'No reservation has been recorded.',
            ],
        ];

        if ($compare && $previous !== null) {
            array_unshift($sections, self::comparison([
                ['Deals recorded', (float) $summary['total'], (float) $previous['total'], 'int'],
                ['Completed sales', (float) $summary['completed'], (float) $previous['completed'], 'int'],
                ['Completed value', (float) $summary['completed_value'], (float) $previous['completed_value'], 'money'],
                ['Contract value, all statuses', (float) $summary['total_value'], (float) $previous['total_value'], 'money'],
                ['Commission recorded', (float) $summary['commission'], (float) $previous['commission'], 'money'],
            ], 'Reservations carry no comparison: they are current state and the table records no '
             . 'history of when a hold stood.'));
        }

        $queue = $p['resvQueue'] ?? [];
        if ($queue) {
            $sections[] = [
                'title'   => 'Reservation queue',
                'note'    => 'Holds that still stand or have lapsed, soonest to expire first.',
                'columns' => [
                    self::col('reservation_code', 'Reference', 'text', 1.8),
                    self::col('property_code', 'Code', 'text', 2.4),
                    self::col('property_title', 'Property', 'text', 3.4),
                    self::col('customer_name', 'Customer', 'text', 2.6),
                    self::col('reservation_date', 'Reserved', 'date', 1.8),
                    self::col('expiry_date', 'Expires', 'date', 1.8),
                    self::col('status', 'Status', 'label', 1.6),
                    self::col('deposit_amount', 'Deposit', 'money', 2.0),
                ],
                'rows'    => array_map(
                    static fn(array $r): array => [
                        'reservation_code' => (string) ($r['reservation_code'] ?? ''),
                        'property_code'    => (string) ($r['property_code'] ?? ''),
                        'property_title'   => (string) ($r['property_title'] ?? ''),
                        'customer_name'    => ($r['customer_name'] ?? null) !== null ? (string) $r['customer_name'] : null,
                        'reservation_date' => $r['reservation_date'] ?: null,
                        'expiry_date'      => $r['expiry_date'] ?: null,
                        'status'           => uiLabel((string) ($r['status'] ?? '')),
                        'deposit_amount'   => (float) ($r['deposit_amount'] ?? 0),
                    ],
                    $queue
                ),
                'empty'   => 'No reservation is outstanding.',
            ];
        }

        return [
            'kpis'     => $kpis,
            'sections' => $sections,
            'records'  => [
                'title'   => 'Sales register',
                'note'    => 'Every deal dated in this period, newest first. "Value" is contract value; '
                           . '"collected" is money actually received against the deal, and the two are '
                           . 'different questions.',
                'columns' => [
                    self::col('sale_code', 'Sale', 'text', 1.8, false),
                    self::col('sale_date', 'Sale date', 'date', 1.8),
                    self::col('property_code', 'Code', 'text', 1.6, false),
                    self::col('property_title', 'Property', 'text', 3.4),
                    self::col('buyer_name', 'Buyer', 'text', 2.6),
                    self::col('category', 'Category', 'label', 1.8),
                    self::col('status', 'Status', 'label', 1.6),
                    self::col('agent_name', 'Agent', 'text', 2.4),
                    self::col('sale_amount', 'Value', 'money', 2.0),
                    self::col('commission_amount', 'Commission', 'money', 2.0, false),
                    self::col('collected', 'Collected', 'money', 2.0),
                ],
                'rows'    => array_map([self::class, 'saleRow'], $p['register']),
                'total'   => (int) $p['registerTotal'],
                'fetch'   => static fn(int $limit, int $offset): array => array_map(
                    [self::class, 'saleRow'],
                    $analytics->salesRegister($limit, $offset)
                ),
                'empty'   => 'No sale was recorded in this period.',
            ],
        ];
    }

    /** @return array<string,mixed> */
    private static function saleRow(array $r): array
    {
        return [
            'sale_code'         => (string) ($r['sale_code'] ?? ''),
            'sale_date'         => $r['sale_date'] ?: null,
            'property_code'     => (string) ($r['property_code'] ?? ''),
            'property_title'    => (string) ($r['property_title'] ?? ''),
            'buyer_name'        => ($r['buyer_name'] ?? null) !== null ? (string) $r['buyer_name'] : null,
            'category'          => categoryLabel((string) ($r['category'] ?? '')),
            'status'            => uiLabel((string) ($r['status'] ?? '')),
            'agent_name'        => ($r['agent_name'] ?? null) !== null ? (string) $r['agent_name'] : null,
            'sale_amount'       => (float) ($r['sale_amount'] ?? 0),
            'commission_amount' => (float) ($r['commission_amount'] ?? 0),
            'collected'         => (float) ($r['collected'] ?? 0),
        ];
    }

    // ─── Payments ──────────────────────────────────────────────────────

    private static function payments(array $p, CoreAnalytics $analytics, bool $compare): array
    {
        $activity = $p['activity'];
        $previous = $p['previousActivity'];
        $future   = $p['futureDated'];
        $window   = $p['window'];

        $kpis = [
            self::kpi(
                'Payment records',
                number_format((int) $activity['records']),
                'Transactions dated in this period — a count, not an amount',
                $previous !== null
                    ? reportDelta((float) $activity['records'], (float) $previous['records'])
                    : null
            ),
            self::kpi(
                'Money received',
                formatCurrency((float) $activity['received']),
                sprintf(
                    '%d paid %s dated today or earlier — all types',
                    (int) $activity['received_records'],
                    (int) $activity['received_records'] === 1 ? 'record' : 'records'
                ),
                $previous !== null
                    ? reportDelta((float) $activity['received'], (float) $previous['received'])
                    : null
            ),
            self::kpi(
                'Collected revenue',
                formatCurrency((float) $activity['collected']),
                abs((float) $activity['received'] - (float) $activity['collected']) >= 0.005
                    ? formatCurrency((float) $activity['received'] - (float) $activity['collected'])
                      . ' of the received total is deposits or refunds'
                    : 'Same as received — no deposits or refunds in this period'
            ),
            self::kpi(
                'Dated ahead',
                formatCurrency((float) $future['amount']),
                (int) $future['count'] > 0
                    ? sprintf(
                        '%d %s dated after today, held out of revenue',
                        (int) $future['count'],
                        (int) $future['count'] === 1 ? 'record' : 'records'
                    )
                    : 'No future-dated payments'
            ),
            self::kpi(
                'Needs review',
                number_format((int) $p['reviewFlags']),
                (int) $p['reviewFlags'] > 0
                    ? 'Classification, timing and completeness flags combined'
                    : 'Nothing flagged on these records'
            ),
        ];

        $activitySeries = $p['activitySeries'];

        $sections = [];

        if ($compare && $previous !== null) {
            $sections[] = self::comparison([
                ['Payment records', (float) $activity['records'], (float) $previous['records'], 'int'],
                ['Amount recorded', (float) $activity['amount'], (float) $previous['amount'], 'money'],
                ['Money received', (float) $activity['received'], (float) $previous['received'], 'money'],
                ['Collected revenue', (float) $activity['collected'], (float) $previous['collected'], 'money'],
                ['Average payment', $activity['average'], $previous['average'], 'money'],
                ['Cancelled records', (float) $activity['cancelled_records'], (float) $previous['cancelled_records'], 'int'],
            ], 'An average over zero payments is not a small average — it is no average at all, and '
             . 'is reported as unavailable rather than as nought.');
        }

        // Volume and value are two aligned series over the same buckets. The
        // report exists partly to say they move independently, so they are
        // reported side by side rather than one of them alone.
        $sections[] = self::pairedSeries(
            'Payment activity',
            'By ' . $window['grain'] . ', on the payment date — when money moved. More payments and '
            . 'more money are different events, and reading either alone misleads.',
            $activitySeries['count'],
            $activitySeries['amount'],
            'Records',
            'Amount',
            'auto',
            (string) $window['grain'],
            false,
            'right'
        );
        $sections[count($sections) - 1]['columns'][1]['type'] = 'int';
        $sections[count($sections) - 1]['columns'][2]['type'] = 'money';
        $sections[count($sections) - 1]['chart']['unit']      = 'currency';
        $sections[count($sections) - 1]['empty'] = 'No payment was recorded in this period.';

        $sections[] = [
            'title'   => 'By status',
            'note'    => 'Every status side by side. The report analyses status rather than filtering '
                       . 'to one, because narrowing to "pending" would leave a tile called collected '
                       . 'revenue showing money that was never collected.',
            'columns' => [
                self::col('label', 'Status', 'text', 3.0),
                self::col('records', 'Records', 'int', 1.6),
                self::col('amount', 'Amount', 'money', 2.0),
            ],
            'rows'    => array_map(
                static fn(array $s): array => [
                    'label'   => (string) $s['label'],
                    'records' => (int) $s['records'],
                    'amount'  => (float) $s['amount'],
                ],
                $p['statusBreakdown']
            ),
            'empty'   => 'No payment was recorded in this period.',
        ];

        $sections[] = [
            'title'   => 'By method',
            'note'    => 'How the money arrived. Records with no method recorded are shown as such '
                       . 'rather than folded into "other".',
            'columns' => [
                self::col('label', 'Method', 'text', 3.0),
                self::col('records', 'Records', 'int', 1.6),
                self::col('amount', 'Amount', 'money', 2.0),
            ],
            'rows'    => array_map(
                static fn(array $m): array => [
                    'label'   => (string) $m['label'],
                    'records' => (int) $m['records'],
                    'amount'  => (float) $m['amount'],
                ],
                $p['methodBreakdown']
            ),
            'empty'   => 'No payment was recorded in this period.',
        ];

        $sections[] = [
            'title'   => 'Classification matrix',
            'note'    => 'Payment type against the contract the payment was taken on. A row marked as '
                       . 'a conflict is one where the two disagree; revenue counts it by the contract '
                       . 'it names, and the row is worth reclassifying.',
            'columns' => [
                self::col('type_label', 'Payment type', 'text', 2.4),
                self::col('ref_label', 'Taken against', 'text', 2.4),
                self::col('records', 'Records', 'int', 1.5),
                self::col('amount', 'Amount', 'money', 2.0),
                self::col('reading', 'Reading', 'text', 2.4),
            ],
            'rows'    => array_map(
                static fn(array $c): array => [
                    'type_label' => (string) $c['type_label'],
                    'ref_label'  => (string) $c['ref_label'],
                    'records'    => (int) $c['records'],
                    'amount'     => (float) $c['amount'],
                    'reading'    => !empty($c['mismatch']) ? 'Conflict — counted by contract' : 'Agrees',
                ],
                $p['classification']
            ),
            'empty'   => 'No payment was recorded in this period.',
        ];

        if ((int) $future['count'] > 0 && !empty($p['futureRecords'])) {
            $sections[] = [
                'title'   => 'Dated ahead of today',
                'note'    => 'Paid records dated after today. Held out of collected revenue until '
                           . 'their date arrives, and listed so the gap against the payments register '
                           . 'has an explanation attached to it.',
                'columns' => [
                    self::col('payment_date', 'Dated', 'date', 1.8),
                    self::col('payment_code', 'Reference', 'text', 2.6),
                    self::col('property_title', 'Property', 'text', 3.4),
                    self::col('customer_name', 'Payer', 'text', 2.6),
                    self::col('payment_type', 'Type', 'label', 1.8),
                    self::col('status', 'Status', 'label', 1.6),
                    self::col('amount', 'Amount', 'money', 2.0),
                ],
                'rows'    => array_map(
                    static fn(array $r): array => [
                        'payment_date'   => $r['payment_date'] ?: null,
                        'payment_code'   => (string) ($r['payment_code'] ?? ''),
                        'property_title' => ($r['property_title'] ?? null) !== null ? (string) $r['property_title'] : null,
                        'customer_name'  => ($r['customer_name'] ?? null) !== null ? (string) $r['customer_name'] : null,
                        'payment_type'   => uiLabel((string) ($r['payment_type'] ?? '')),
                        'status'         => uiLabel((string) ($r['status'] ?? '')),
                        'amount'         => (float) ($r['amount'] ?? 0),
                    ],
                    $p['futureRecords']
                ),
                'empty'   => 'Nothing is dated ahead.',
            ];
        }

        return [
            'kpis'     => $kpis,
            'sections' => $sections,
            'records'  => [
                'title'   => 'Payment records',
                'note'    => 'Every payment dated in this period, newest first, under your access '
                           . 'scope and the report\'s current filters.',
                'columns' => [
                    self::col('payment_date', 'Date', 'date', 1.8),
                    self::col('payment_code', 'Reference', 'text', 2.6),
                    self::col('receipt_number', 'Receipt', 'text', 1.8, false),
                    self::col('property_code', 'Code', 'text', 1.6, false),
                    self::col('property_title', 'Property', 'text', 3.2),
                    self::col('customer_name', 'Payer', 'text', 2.6),
                    self::col('payment_type', 'Type', 'label', 1.6),
                    self::col('reference_type', 'Against', 'label', 1.6, false),
                    self::col('payment_method', 'Method', 'label', 1.8),
                    self::col('status', 'Status', 'label', 1.6),
                    self::col('received_by_name', 'Received by', 'text', 2.4, false),
                    self::col('amount', 'Amount', 'money', 2.0),
                ],
                'rows'    => array_map([self::class, 'paymentRow'], $p['records']),
                'total'   => (int) $p['recordTotal'],
                'fetch'   => static fn(int $limit, int $offset): array => array_map(
                    [self::class, 'paymentRow'],
                    $analytics->paymentRecords($limit, $offset)
                ),
                'empty'   => 'No payment was recorded in this period.',
            ],
        ];
    }

    /** @return array<string,mixed> */
    private static function paymentRow(array $r): array
    {
        $method = (string) ($r['payment_method'] ?? '');

        return [
            'payment_date'     => $r['payment_date'] ?: null,
            'payment_code'     => (string) ($r['payment_code'] ?? ''),
            'receipt_number'   => ($r['receipt_number'] ?? '') !== '' ? (string) $r['receipt_number'] : null,
            'property_code'    => ($r['property_code'] ?? '') !== '' ? (string) $r['property_code'] : null,
            'property_title'   => ($r['property_title'] ?? null) !== null ? (string) $r['property_title'] : null,
            'customer_name'    => ($r['customer_name'] ?? null) !== null ? (string) $r['customer_name'] : null,
            'payment_type'     => uiLabel((string) ($r['payment_type'] ?? '')),
            'reference_type'   => uiLabel((string) ($r['reference_type'] ?? '')),
            'payment_method'   => $method === '' ? 'Not recorded' : uiLabel($method),
            'status'           => uiLabel((string) ($r['status'] ?? '')),
            'received_by_name' => ($r['received_by_name'] ?? null) !== null ? (string) $r['received_by_name'] : null,
            'amount'           => (float) ($r['amount'] ?? 0),
        ];
    }

    // ─── Maintenance ───────────────────────────────────────────────────

    private static function maintenance(array $p, CoreAnalytics $analytics, bool $compare): array
    {
        $summary    = $p['summary'];
        $previous   = $p['previous'];
        $resolution = $p['resolution'];
        $ageing     = $p['ageing'];
        $costs      = $p['costs'];
        $window     = $p['window'];

        $kpis = [
            self::kpi(
                'Requests raised',
                number_format((int) $summary['raised']),
                'Logged in ' . $window['label'] . ' — a period figure',
                $previous !== null ? reportDelta((float) $summary['raised'], (float) $previous['raised']) : null
            ),
            self::kpi(
                'Open now',
                number_format((int) $summary['open']),
                (int) $summary['open'] > 0
                    ? sprintf(
                        '%d awaiting triage · %d assigned · %d in progress',
                        (int) $summary['awaiting'],
                        (int) $summary['assigned'],
                        (int) $summary['in_progress']
                    )
                    : 'Nothing outstanding'
            ),
            self::kpi(
                'In progress',
                number_format((int) $summary['in_progress']),
                (int) $summary['in_progress'] > 0
                    ? 'Work has started on these'
                    : 'No request has been started'
            ),
            self::kpi(
                'Completed',
                number_format((int) $summary['completed']),
                sprintf('in %s · %d completed ever', $window['label'], (int) $summary['completed_ever']),
                $previous !== null ? reportDelta((float) $summary['completed'], (float) $previous['completed']) : null
            ),
            self::kpi(
                'High priority open',
                number_format((int) $summary['open_urgent']),
                (int) $summary['open_urgent'] > 0
                    ? 'Marked high or urgent and not yet closed'
                    : 'No high or urgent work outstanding'
            ),
            self::kpi(
                'Average resolution',
                !empty($resolution['available'])
                    ? number_format((float) $resolution['average'], 1) . ' days'
                    : 'Not available',
                !empty($resolution['available'])
                    ? sprintf(
                        'across %d completed · %d to %d days',
                        (int) $resolution['resolved'],
                        (int) $resolution['fastest'],
                        (int) $resolution['slowest']
                    )
                    : 'No completed request carries a completion date'
            ),
        ];

        $maintSeries = $p['maintSeries'];

        $sections = [];

        if ($compare && $previous !== null) {
            $sections[] = self::comparison([
                ['Requests raised', (float) $summary['raised'], (float) $previous['raised'], 'int'],
                ['High or urgent raised', (float) $summary['raised_urgent'], (float) $previous['raised_urgent'], 'int'],
                ['Requests completed', (float) $summary['completed'], (float) $previous['completed'], 'int'],
                ['Cost on completed work', (float) $summary['completed_cost'], (float) $previous['completed_cost'], 'money'],
            ], 'The open backlog carries no comparison. maintenance_requests holds one status per row '
             . 'and no history of it, so what was open during the previous window is not a question '
             . 'this database can answer.');
        }

        $sections[] = [
            'title'   => 'Intake against completion',
            'note'    => 'By ' . $window['grain'] . '. A queue with intake and no output is the '
                       . 'finding, and it is easy to miss when every individual number looks small.',
            'columns' => [
                self::col('label', ucfirst((string) $window['grain']), 'text', 2.0),
                self::col('raised', 'Raised', 'int', 1.6),
                self::col('completed', 'Completed', 'int', 1.6),
            ],
            'rows'    => array_map(
                static fn(array $r, array $c): array => [
                    'label'     => (string) $r['label'],
                    'raised'    => (int) $r['total'],
                    'completed' => (int) $c['total'],
                ],
                $maintSeries['raised'],
                $maintSeries['completed']
            ),
            'chart'   => [
                'labels' => array_column($maintSeries['raised'], 'label'),
                'values' => array_map(static fn(array $b): float => (float) $b['total'], $maintSeries['raised']),
            ],
            'empty'   => 'No request was raised in this period.',
        ];

        $sections[] = [
            'title'   => 'By status',
            'note'    => 'Current state across every request in scope.',
            'columns' => [
                self::col('label', 'Status', 'text', 3.0),
                self::col('requests', 'Requests', 'int', 1.6),
                self::col('open', 'Still open', 'text', 1.6),
            ],
            'rows'    => array_map(
                static fn(array $s): array => [
                    'label'    => (string) $s['label'],
                    'requests' => (int) $s['requests'],
                    'open'     => !empty($s['is_open']) ? 'Yes' : 'No',
                ],
                $p['statusMix']
            ),
            'empty'   => 'There are no maintenance requests in scope.',
        ];

        $sections[] = [
            'title'   => 'Open work by priority',
            'note'    => 'Priority as recorded on the request.',
            'columns' => [self::col('label', 'Priority', 'text', 3.0), self::col('requests', 'Open requests', 'int', 1.8)],
            'rows'    => array_map(
                static fn(array $r): array => ['label' => (string) $r['label'], 'requests' => (int) $r['requests']],
                $p['priorityMix']
            ),
            'chart'   => [
                'labels' => array_column($p['priorityMix'], 'label'),
                'values' => array_map(static fn(array $r): float => (float) $r['requests'], $p['priorityMix']),
                'unit'   => 'number',
            ],
            'empty'   => 'Nothing is open.',
        ];

        $sections[] = [
            'title'   => 'How long open work has been waiting',
            'note'    => sprintf(
                'Oldest open request: %d days. Average across the queue: %s days. Not an SLA breach — '
                . 'this system defines no target response time — but an age worth stating.',
                (int) $ageing['oldest'],
                number_format((float) $ageing['average'], 1)
            ),
            'columns' => [self::col('label', 'Waiting', 'text', 3.0), self::col('requests', 'Requests', 'int', 1.6)],
            'rows'    => array_map(
                static fn(array $b): array => [
                    'label'    => (string) $b['label'],
                    'requests' => (int) $b['requests'],
                ],
                $ageing['buckets']
            ),
            'empty'   => 'Nothing is open.',
        ];

        $sections[] = [
            'title'   => 'Cost',
            'note'    => 'As recorded on the request. Only requests that carry a figure contribute to '
                       . 'these totals — a request with no cost entered is absent from them rather '
                       . 'than counted as nought.',
            'columns' => [
                self::col('label', 'Measure', 'text', 3.4),
                self::col('requests', 'Requests', 'int', 1.6),
                self::col('amount', 'Amount', 'money', 2.0),
            ],
            'rows'    => [
                [
                    'label'    => 'Estimated cost',
                    'requests' => (int) $costs['with_estimate'],
                    'amount'   => (float) $costs['estimate'],
                ],
                [
                    'label'    => 'Actual cost recorded',
                    'requests' => null,
                    'amount'   => (float) $costs['actual'],
                ],
            ],
            'empty'   => 'No cost has been recorded.',
        ];

        $byProperty = $p['byProperty'] ?? [];
        if ($byProperty) {
            $sections[] = [
                'title'   => 'Where the work is',
                'note'    => 'The properties generating the most requests.',
                'columns' => [
                    self::col('property_code', 'Code', 'text', 2.4),
                    self::col('title', 'Property', 'text', 3.8),
                    self::col('requests', 'Requests', 'int', 1.5),
                    self::col('open_requests', 'Open', 'int', 1.3),
                    self::col('urgent_requests', 'High', 'int', 1.3),
                    self::col('cost', 'Cost', 'money', 2.0),
                ],
                'rows'    => array_map(
                    static fn(array $r): array => [
                        'property_code'   => (string) ($r['property_code'] ?? ''),
                        'title'           => (string) ($r['title'] ?? ''),
                        'requests'        => (int) $r['requests'],
                        'open_requests'   => (int) $r['open_requests'],
                        'urgent_requests' => (int) $r['urgent_requests'],
                        'cost'            => (float) $r['cost'],
                    ],
                    $byProperty
                ),
                'empty'   => 'No property has a maintenance request against it.',
            ];
        }

        return [
            'kpis'     => $kpis,
            'sections' => $sections,
            'records'  => [
                'title'   => 'Open work queue',
                'note'    => 'Requests nobody has closed — new, under review, assigned or in progress '
                           . '— urgent first, then oldest. Current state, so it does not move with the '
                           . 'reporting period.',
                'columns' => [
                    self::col('request_code', 'Request', 'text', 2.4),
                    self::col('property_code', 'Code', 'text', 1.6, false),
                    self::col('property_title', 'Property', 'text', 3.4),
                    self::col('issue_type', 'Type', 'text', 2.4),
                    self::col('priority', 'Priority', 'label', 1.6),
                    self::col('status', 'Status', 'label', 1.8),
                    self::col('raised_on', 'Raised', 'date', 1.8),
                    self::col('age_days', 'Days waiting', 'int', 1.5),
                    self::col('assigned_name', 'Assigned', 'text', 2.4),
                    self::col('actual_cost', 'Cost', 'money', 1.8),
                ],
                'rows'    => array_map([self::class, 'maintenanceRow'], $p['queue']),
                'total'   => (int) $p['queueTotal'],
                'fetch'   => static fn(int $limit, int $offset): array => array_map(
                    [self::class, 'maintenanceRow'],
                    $analytics->maintenanceTable('open', $limit, $offset)
                ),
                'empty'   => 'Nothing is open.',
            ],
        ];
    }

    /** @return array<string,mixed> */
    private static function maintenanceRow(array $r): array
    {
        return [
            'request_code'   => (string) ($r['request_code'] ?? ''),
            'property_code'  => (string) ($r['property_code'] ?? ''),
            'property_title' => (string) ($r['property_title'] ?? ''),
            'issue_type'     => ($r['issue_type'] ?? '') !== '' ? (string) $r['issue_type'] : null,
            'priority'       => uiLabel((string) ($r['priority'] ?? '')),
            'status'         => uiLabel((string) ($r['status'] ?? '')),
            'raised_on'      => $r['raised_on'] ?: null,
            'age_days'       => (int) ($r['age_days'] ?? 0),
            'assigned_name'  => ($r['assigned_name'] ?? null) !== null ? (string) $r['assigned_name'] : null,
            // Nought is not the same as "no cost recorded", and this column
            // must not claim the first when the record says the second.
            'actual_cost'    => $r['actual_cost'] === null ? null : (float) $r['actual_cost'],
        ];
    }

    // ─── Performance ───────────────────────────────────────────────────

    private static function performance(array $p, array $window): array
    {
        $rows = $p['agentPerf'] ?? [];

        return [
            'kpis'     => [],
            'sections' => [[
                'title'   => 'How attribution works',
                'note'    => 'Eight measures, each from its own source. Nothing here is weighted into '
                           . 'a score, because nobody has decided what the weights would be.',
                'columns' => [
                    self::col('measure', 'Measure', 'text', 2.6),
                    self::col('source', 'Counted from', 'prose', 6.0),
                ],
                'rows'    => [
                    [
                        'measure' => 'Listings and active book',
                        'source'  => 'The property record\'s assigned agent, and live leases on those '
                                   . 'properties. Both describe the desk as it stands today and do not '
                                   . 'move with the reporting period.',
                    ],
                    [
                        'measure' => 'Leases written',
                        'source'  => 'Who created the lease record, within the period. It measures '
                                   . 'paperwork, not ownership — an agent can write a lease on a '
                                   . 'colleague\'s listing.',
                    ],
                    [
                        'measure' => 'Sales closed',
                        'source'  => 'The agent named on the sale, where the sale has completed and its '
                                   . 'date falls in the period. A deal with no agent recorded is counted '
                                   . 'by the company and by nobody here.',
                    ],
                    [
                        'measure' => 'Revenue and commission',
                        'source'  => 'Revenue is paid payments dated on or before today, matched either '
                                   . 'to the agent\'s own listings or to the person who received the '
                                   . 'money. Commission is the pending balance on the commission '
                                   . 'ledger, a running total and not a figure for this period.',
                    ],
                ],
                'empty'   => '',
            ]],
            'records'  => [
                'title'   => 'The desk, agent by agent',
                'note'    => 'Book as it stands now; activity and revenue within ' . $window['label'] . '. '
                           . '"Rent collected" is money taken on that agent\'s own listings; "received '
                           . 'at desk" is money they personally took in, including rent on a '
                           . 'colleague\'s property. The two answer different questions and will not '
                           . 'agree. No column is weighted into a score and there is no ranking.',
                'columns' => [
                    self::col('full_name', 'Agent', 'text', 3.8),
                    self::col('properties_managed', 'Managed (now)', 'int', 1.8),
                    self::col('active_leases', 'Active leases (now)', 'int', 1.8),
                    self::col('leases_created', 'Leases written', 'int', 1.8),
                    self::col('sales_completed', 'Sales closed', 'int', 1.6),
                    self::col('sales_value', 'Contracted value', 'money', 2.0, false),
                    self::col('rental_revenue', 'Rent collected', 'money', 2.0),
                    self::col('sales_revenue', 'Sales collected', 'money', 2.0, false),
                    self::col('revenue_received', 'Received at desk', 'money', 2.0),
                    self::col('commission_pending', 'Commission due (to date)', 'money', 2.0),
                ],
                'rows'    => array_map(
                    static fn(array $a): array => [
                        'full_name'          => (string) $a['full_name'],
                        'properties_managed' => (int) $a['properties_managed'],
                        'active_leases'      => (int) $a['active_leases'],
                        'leases_created'     => (int) $a['leases_created'],
                        'sales_completed'    => (int) $a['sales_completed'],
                        'sales_value'        => (float) $a['sales_value'],
                        'rental_revenue'     => (float) $a['rental_revenue'],
                        'sales_revenue'      => (float) $a['sales_revenue'],
                        'revenue_received'   => (float) $a['revenue_received'],
                        'commission_pending' => (float) $a['commission_pending'],
                    ],
                    $rows
                ),
                'total'   => null,
                'fetch'   => null,
                'empty'   => 'No account holds the Agent role, so there is nothing to measure. '
                           . 'Nothing is estimated in the meantime.',
            ],
        ];
    }
}
