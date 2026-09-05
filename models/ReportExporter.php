<?php
/**
 * ReportExporter — one document model, three renderings.
 *
 * Nothing here decides what a figure means. ReportDocument already turned the
 * controller's on-screen payload into a neutral structure; this file only
 * chooses how that structure looks as a printed page, as a workbook and as a
 * comma-separated file. If an export ever disagrees with the screen, the bug
 * is upstream of this file by construction.
 *
 * The three renderings differ in what they are *for*, and the differences are
 * deliberate rather than accidental:
 *
 *   PDF    something a manager reads. Formatted figures, a fixed column set
 *          that fits a portrait page, and record tables cut to a readable
 *          length with the count of what was left stated on the page.
 *
 *   XLSX   something an analyst works with. Real numbers under real number
 *          formats, four sheets, and every column the report has.
 *
 *   CSV    something a system consumes. The report's primary record set,
 *          machine-readable, streamed rather than assembled.
 *
 * What none of them does is turn an unavailable figure into a nought. A null
 * prints as an em dash, lands in Excel as an empty cell, and leaves a CSV
 * field empty — three different ways of saying the same true thing.
 */
require_once __DIR__ . '/PdfWriter.php';
require_once __DIR__ . '/XlsxWriter.php';

class ReportExporter
{
    // ══════════════════════════════════════════════════════════════════
    //  PDF
    // ══════════════════════════════════════════════════════════════════

    /** The finished PDF, as bytes. */
    public static function pdf(array $doc): string
    {
        $footer = $doc['company'] . ' · ' . $doc['title'] . ' report · '
                . self::periodLine($doc) . ' · Generated ' . formatDateTime(date('Y-m-d H:i:s'));

        $pdf = new PdfWriter($footer, $doc['company'] . ' · ' . $doc['title'] . ' report');

        self::pdfMasthead($pdf, $doc);
        self::pdfKpis($pdf, $doc['kpis']);

        foreach ($doc['sections'] as $section) {
            self::pdfSection($pdf, $section);
        }

        self::pdfInsights($pdf, $doc['insights']);
        self::pdfQuality($pdf, $doc['quality']);

        if (!empty($doc['records'])) {
            self::pdfRecords($pdf, $doc['records']);
        }

        return $pdf->output();
    }

    /** Company, report, period, comparison, scope, provenance. */
    private static function pdfMasthead(PdfWriter $pdf, array $doc): void
    {
        $bandHeight = 76.0;

        $pdf->rect(0, 0, PdfWriter::PAGE_W, $bandHeight, PdfWriter::INK);
        $pdf->rect(0, $bandHeight - 3, PdfWriter::PAGE_W, 3, PdfWriter::PRIMARY);

        $pdf->text($pdf->left(), 30, strtoupper($doc['company']), 9, PdfWriter::BOLD, PdfWriter::WHITE);
        $pdf->text($pdf->left(), 56, $doc['title'] . ' report', 21, PdfWriter::BOLD, PdfWriter::WHITE);
        $pdf->textRight($pdf->right(), 30, 'Analytics export', 8, PdfWriter::REGULAR, [0.62, 0.70, 0.75]);

        $pdf->moveTo($bandHeight + 16);
        $pdf->paragraph($doc['blurb'], 8.5, PdfWriter::REGULAR, PdfWriter::MUTED);

        $pdf->space(10);
        $pdf->rule();
        $pdf->space(6);

        // The meta grid. Three to a row, each a term, a value and — where
        // there is one — the sentence that qualifies it.
        $columns = 3;
        $gap     = 14.0;
        $colW    = ($pdf->width() - $gap * ($columns - 1)) / $columns;
        $entries = array_values($doc['meta']);

        foreach (array_chunk($entries, $columns) as $rowEntries) {
            $height = 0.0;
            foreach ($rowEntries as $entry) {
                $lines  = $entry['sub'] === null ? 0 : count(PdfWriter::wrap($entry['sub'], $colW, 7.5));
                $height = max($height, 26 + $lines * 9.5);
            }

            $pdf->ensure($height + 6);
            $top = $pdf->y();

            foreach ($rowEntries as $i => $entry) {
                $x = $pdf->left() + $i * ($colW + $gap);
                $pdf->text($x, $top + 8, strtoupper($entry['term']), 6.5, PdfWriter::BOLD, PdfWriter::MUTED);
                $pdf->text(
                    $x,
                    $top + 20,
                    PdfWriter::fit($entry['value'], $colW, 9.5, PdfWriter::BOLD),
                    9.5,
                    PdfWriter::BOLD
                );

                if ($entry['sub'] !== null) {
                    $y = $top + 30;
                    foreach (PdfWriter::wrap($entry['sub'], $colW, 7.5) as $line) {
                        $pdf->text($x, $y, $line, 7.5, PdfWriter::REGULAR, PdfWriter::MUTED);
                        $y += 9.5;
                    }
                }
            }

            $pdf->moveTo($top + $height);
        }

        if (!empty($doc['filters'])) {
            $named = [];
            foreach ($doc['filters'] as $filter) {
                $named[] = $filter['label'] . ': ' . $filter['value'];
            }
            $pdf->space(2);
            $pdf->paragraph('Filtered by — ' . implode('  ·  ', $named), 7.5, PdfWriter::BOLD, PdfWriter::PRIMARY);
        }

        $pdf->space(10);
        $pdf->rule(PdfWriter::INK, 0.9);
        $pdf->space(4);
    }

    /** The headline tiles, three to a row, in the screen's order. */
    private static function pdfKpis(PdfWriter $pdf, array $kpis): void
    {
        if (!$kpis) {
            return;
        }

        self::pdfHeading($pdf, 'Summary', '');

        $columns = 3;
        $gap     = 10.0;
        $colW    = ($pdf->width() - $gap * ($columns - 1)) / $columns;
        $height  = 70.0;

        foreach (array_chunk($kpis, $columns) as $row) {
            $pdf->ensure($height + 8);
            $top = $pdf->y();

            foreach ($row as $i => $kpi) {
                $x = $pdf->left() + $i * ($colW + $gap);

                $pdf->rect($x, $top, $colW, $height, PdfWriter::BAND, PdfWriter::RULE);
                $pdf->rect($x, $top, 2.5, $height, PdfWriter::PRIMARY);

                $inner = $x + 10;
                $textW = $colW - 20;

                $pdf->text(
                    $inner,
                    $top + 14,
                    PdfWriter::fit(strtoupper($kpi['label']), $textW, 6.5, PdfWriter::BOLD),
                    6.5,
                    PdfWriter::BOLD,
                    PdfWriter::MUTED
                );
                $pdf->text(
                    $inner,
                    $top + 32,
                    PdfWriter::fit($kpi['value'], $textW, 15, PdfWriter::BOLD),
                    15,
                    PdfWriter::BOLD
                );

                $y = $top + 44;
                foreach (array_slice(PdfWriter::wrap($kpi['context'], $textW, 7), 0, 2) as $line) {
                    $pdf->text($inner, $y, $line, 7, PdfWriter::REGULAR, PdfWriter::MUTED);
                    $y += 8.5;
                }

                // The comparison gets its own line at the foot of the tile
                // rather than sharing the label's. Beside the label it had
                // about eighty points to say "New this period - nothing
                // recorded previously" in, which is not enough, and the two
                // ran into each other on the widest labels.
                if ($kpi['delta'] !== null) {
                    $pdf->text(
                        $inner,
                        $top + 65,
                        PdfWriter::fit($kpi['delta'], $textW, 6.5, PdfWriter::BOLD),
                        6.5,
                        PdfWriter::BOLD,
                        PdfWriter::PRIMARY
                    );
                }
            }

            $pdf->moveTo($top + $height + 8);
        }
    }

    /** One analytics section: heading, note, optional chart, table. */
    private static function pdfSection(PdfWriter $pdf, array $section): void
    {
        self::pdfHeading($pdf, $section['title'], (string) ($section['note'] ?? ''));

        if (empty($section['rows'])) {
            $pdf->paragraph(
                (string) ($section['empty'] ?? 'Nothing to show.'),
                8.5,
                PdfWriter::REGULAR,
                PdfWriter::MUTED
            );
            $pdf->space(6);

            return;
        }

        if (!empty($section['chart'])) {
            self::pdfChart($pdf, $section['chart']);
        }

        self::pdfTable($pdf, $section['columns'], $section['rows'], $section['total'] ?? null, $section['types'] ?? null);
    }

    private static function pdfHeading(PdfWriter $pdf, string $title, string $note): void
    {
        $pdf->ensure(56);
        $pdf->space(14);
        $pdf->text($pdf->left(), $pdf->y(), $title, 12.5, PdfWriter::BOLD);
        $pdf->space(4);

        if ($note !== '') {
            $pdf->paragraph($note, 7.5, PdfWriter::REGULAR, PdfWriter::MUTED);
        }

        $pdf->space(6);
    }

    /**
     * A bar chart, drawn from the same array the screen's canvas is drawn
     * from.
     *
     * Native vector rather than a picture of a Chart.js canvas: the browser's
     * rendering would have to be round-tripped through the request to get
     * here, and a chart that only appears when JavaScript happened to have
     * finished is not something a scheduled or scripted export can rely on.
     * The shape is the shape; the table underneath carries the figures.
     */
    private static function pdfChart(PdfWriter $pdf, array $chart): void
    {
        $values = array_map('floatval', $chart['values'] ?? []);
        $labels = $chart['labels'] ?? [];
        $max    = $values ? max($values) : 0.0;

        // A chart of nothing is not a chart. The table below still prints.
        if (count($values) < 2 || $max <= 0) {
            return;
        }

        $height = 104.0;
        $pdf->ensure($height + 14);

        $top    = $pdf->y();
        $plotH  = $height - 22;
        $base   = $top + $plotH;
        $plotW  = $pdf->width();
        $slot   = $plotW / count($values);
        $barW   = min(28.0, $slot * 0.6);

        // Two guide lines, at the maximum and at half of it. More than that
        // is a grid, and a grid on a chart this small is texture.
        //
        // The tick reads as money only where the series *is* money. A count
        // of maintenance requests labelled "$1" is worse than an unlabelled
        // axis, because it is confidently wrong about what it is measuring.
        $money = ($chart['unit'] ?? 'number') === 'currency';

        foreach ([1.0, 0.5] as $fraction) {
            $y     = $base - $plotH * $fraction;
            $value = $max * $fraction;

            $pdf->line($pdf->left(), $y, $pdf->right(), $y, [0.92, 0.94, 0.95]);
            $pdf->textRight(
                $pdf->right(),
                $y - 2,
                $money ? reportCompact($value) : number_format($value, fmod($value, 1.0) == 0.0 ? 0 : 1),
                6,
                PdfWriter::REGULAR,
                PdfWriter::MUTED
            );
        }

        $pdf->line($pdf->left(), $base, $pdf->right(), $base, PdfWriter::RULE, 0.7);

        // Labels thin out rather than overlap: whichever stride keeps them
        // roughly 34pt apart, which is about the width of "12 Aug".
        $stride = max(1, (int) ceil(count($values) / max(1, floor($plotW / 34))));

        foreach ($values as $i => $value) {
            $barH = $value <= 0 ? 0.0 : max(1.0, $plotH * ($value / $max));
            $x    = $pdf->left() + $i * $slot + ($slot - $barW) / 2;

            if ($barH > 0) {
                $pdf->rect($x, $base - $barH, $barW, $barH, PdfWriter::PRIMARY);
            }

            if ($i % $stride === 0 && isset($labels[$i])) {
                $pdf->textCentre(
                    $x + $barW / 2,
                    $base + 10,
                    PdfWriter::fit((string) $labels[$i], $slot * $stride - 4, 6),
                    6,
                    PdfWriter::REGULAR,
                    PdfWriter::MUTED
                );
            }
        }

        $pdf->moveTo($top + $height);
    }

    /**
     * A table, with a repeating header and a page break that never lands
     * inside a row.
     *
     * `$types` is the per-row type override the comparison table needs: its
     * "this period" and "previous period" columns hold money on one line and
     * a percentage on the next, so the type belongs to the row rather than to
     * the column.
     */
    private static function pdfTable(
        PdfWriter $pdf,
        array $columns,
        array $rows,
        ?array $total = null,
        ?array $types = null,
        int $limit = 0
    ): int {
        $columns = array_values(array_filter($columns, static fn(array $c): bool => $c['pdf'] ?? true));
        if (!$columns) {
            return 0;
        }

        $weights = array_sum(array_column($columns, 'w'));
        $avail   = $pdf->width();
        $widths  = [];
        foreach ($columns as $column) {
            $widths[] = $avail * ((float) $column['w'] / $weights);
        }

        $size = count($columns) > 8 ? 6.5 : 7.5;
        $lead = $size + 1.5;

        // Headings wrap to two lines rather than truncating. "Commission due
        // (to date)" over eight columns has no room on one, and a column
        // called "Commission..." is a column whose meaning was thrown away to
        // save four points of height.
        $headLines = [];
        $headRows  = 1;
        foreach ($columns as $i => $column) {
            $headLines[$i] = array_slice(
                PdfWriter::wrap($column['label'], $widths[$i] - 8, $size, PdfWriter::BOLD),
                0,
                2
            );
            $headRows = max($headRows, count($headLines[$i]));
        }

        $headH   = 8.0 + $headRows * $lead;
        $rowH    = 15.0;
        $printed = 0;

        $header = static function () use ($pdf, $columns, $widths, $size, $lead, $headH, $headLines): void {
            $top = $pdf->y();
            $pdf->rect($pdf->left(), $top, $pdf->width(), $headH, PdfWriter::INK);

            $x = $pdf->left();
            foreach ($columns as $i => $column) {
                $y = $top + 4.0 + $lead;
                foreach ($headLines[$i] as $line) {
                    if (self::isNumeric($column['type'])) {
                        $pdf->textRight($x + $widths[$i] - 4, $y, $line, $size, PdfWriter::BOLD, PdfWriter::WHITE);
                    } else {
                        $pdf->text($x + 4, $y, $line, $size, PdfWriter::BOLD, PdfWriter::WHITE);
                    }
                    $y += $lead;
                }
                $x += $widths[$i];
            }

            $pdf->moveTo($top + $headH);
        };

        $pdf->ensure($headH + $rowH * 3);
        $header();

        foreach (array_values($rows) as $index => $row) {
            if ($limit > 0 && $printed >= $limit) {
                break;
            }

            // A 'prose' column carries a sentence rather than a value, and a
            // sentence cut at an ellipsis has lost the thing it was there to
            // say. Those wrap; everything else stays on one line, because a
            // table of figures reads as a table only while its rows are the
            // same height.
            $cells  = [];
            $height = $rowH;

            foreach ($columns as $i => $column) {
                $type = $column['type'] === 'auto' && $types !== null
                    ? (string) ($types[$index] ?? 'text')
                    : $column['type'];

                $value = exportDisplay($row[$column['key']] ?? null, $type);

                $lines = $type === 'prose'
                    ? array_slice(PdfWriter::wrap($value, $widths[$i] - 8, $size), 0, 4)
                    : [PdfWriter::fit($value, $widths[$i] - 8, $size)];

                $cells[$i] = ['lines' => $lines, 'numeric' => self::isNumeric($type)];
                $height    = max($height, 6.0 + count($lines) * $lead);
            }

            if ($pdf->ensure($height + 2)) {
                $header();
            }

            $top = $pdf->y();

            if ($index % 2 === 1) {
                $pdf->rect($pdf->left(), $top, $pdf->width(), $height, PdfWriter::BAND);
            }

            $x = $pdf->left();
            foreach ($columns as $i => $column) {
                $y = $top + 3.0 + $lead;
                foreach ($cells[$i]['lines'] as $line) {
                    if ($cells[$i]['numeric']) {
                        $pdf->textRight($x + $widths[$i] - 4, $y, $line, $size);
                    } else {
                        $pdf->text($x + 4, $y, $line, $size);
                    }
                    $y += $lead;
                }
                $x += $widths[$i];
            }

            $pdf->line($pdf->left(), $top + $height, $pdf->right(), $top + $height, [0.92, 0.94, 0.95]);
            $pdf->moveTo($top + $height);
            $printed++;
        }

        if ($total !== null) {
            if ($pdf->ensure($rowH + 4)) {
                $header();
            }

            $top = $pdf->y();
            $pdf->line($pdf->left(), $top, $pdf->right(), $top, PdfWriter::INK, 0.8);

            $x = $pdf->left();
            foreach ($columns as $i => $column) {
                $value = $total[$column['key']] ?? null;
                if ($value === null && $i > 0) {
                    $x += $widths[$i];
                    continue;
                }

                $text = $i === 0
                    ? (string) ($total[$column['key']] ?? 'Total')
                    : exportDisplay($value, $column['type']);
                $text = PdfWriter::fit($text, $widths[$i] - 8, $size, PdfWriter::BOLD);

                if (self::isNumeric($column['type'])) {
                    $pdf->textRight($x + $widths[$i] - 4, $top + 11, $text, $size, PdfWriter::BOLD);
                } else {
                    $pdf->text($x + 4, $top + 11, $text, $size, PdfWriter::BOLD);
                }
                $x += $widths[$i];
            }

            $pdf->moveTo($top + $rowH);
        }

        $pdf->space(4);

        return $printed;
    }

    /** The derived findings. */
    private static function pdfInsights(PdfWriter $pdf, array $insights): void
    {
        if (!$insights) {
            return;
        }

        self::pdfHeading(
            $pdf,
            'Insights',
            'Each one restates a figure already in this report. A rule fires or it does not; '
            . 'nothing here is written, ranked or generated.'
        );

        foreach ($insights as $insight) {
            $lines  = PdfWriter::wrap($insight['text'], $pdf->width() - 130, 8);
            $height = max(26.0, 14 + count($lines) * 10.5);

            $pdf->ensure($height + 4);
            $top = $pdf->y();

            $pdf->rect($pdf->left(), $top, 2.0, $height - 4, self::tone($insight['tone']));
            $pdf->text($pdf->left() + 10, $top + 10, strtoupper($insight['label']), 6.5, PdfWriter::BOLD, self::tone($insight['tone']));

            if ($insight['metric'] !== null) {
                $pdf->textRight($pdf->right(), $top + 10, $insight['metric'], 8.5, PdfWriter::BOLD);
            }

            $y = $top + 10;
            foreach ($lines as $line) {
                $pdf->text($pdf->left() + 100, $y, $line, 8);
                $y += 10.5;
            }

            $pdf->moveTo($top + $height);
        }
    }

    /** Where the database contradicts itself. */
    private static function pdfQuality(PdfWriter $pdf, array $quality): void
    {
        if (!$quality) {
            return;
        }

        self::pdfHeading(
            $pdf,
            'Data quality',
            'Records that disagree with each other. None of these is a reporting error and none is '
            . 'corrected here — the figures above are still arithmetically right, and this is what '
            . 'they were computed over.'
        );

        self::pdfTable(
            $pdf,
            [
                ['key' => 'label',  'label' => 'Finding', 'type' => 'text',  'w' => 2.8, 'pdf' => true],
                ['key' => 'count',  'label' => 'Records', 'type' => 'int',   'w' => 1.0, 'pdf' => true],
                ['key' => 'amount', 'label' => 'Amount',  'type' => 'money', 'w' => 1.4, 'pdf' => true],
                ['key' => 'text',   'label' => 'What it means', 'type' => 'prose', 'w' => 6.0, 'pdf' => true],
            ],
            $quality
        );
    }

    /** The report's primary record table, cut to a readable length. */
    private static function pdfRecords(PdfWriter $pdf, array $records): void
    {
        $rows      = $records['rows'];
        $total     = $records['total'];
        $available = $total ?? count($rows);

        $note = (string) $records['note'];
        if ($available > REPORT_EXPORT_PDF_ROWS) {
            $note .= ' This PDF prints the first ' . REPORT_EXPORT_PDF_ROWS . ' of '
                   . number_format($available) . '; the Excel and CSV exports carry every row.';
        }
        if (self::hasHiddenColumns($records['columns'])) {
            $note .= ' Some columns are held back so the table fits a portrait page — '
                   . 'the Excel and CSV exports carry all of them.';
        }

        self::pdfHeading($pdf, $records['title'], $note);

        if (!$rows) {
            $pdf->paragraph((string) $records['empty'], 8.5, PdfWriter::REGULAR, PdfWriter::MUTED);

            return;
        }

        // Only ever the first page of rows: sixty lines is already more than
        // a printed report wants, and the walker exists for the formats that
        // can actually use twenty thousand.
        self::pdfTable($pdf, $records['columns'], $rows, null, null, REPORT_EXPORT_PDF_ROWS);
    }

    private static function hasHiddenColumns(array $columns): bool
    {
        foreach ($columns as $column) {
            if (($column['pdf'] ?? true) === false) {
                return true;
            }
        }

        return false;
    }

    private static function tone(string $tone): array
    {
        return match ($tone) {
            'danger'  => PdfWriter::DANGER,
            'warning' => PdfWriter::WARNING,
            'success' => PdfWriter::SUCCESS,
            'purple'  => PdfWriter::PURPLE,
            'primary' => PdfWriter::PRIMARY,
            default   => PdfWriter::INFO,
        };
    }

    private static function isNumeric(string $type): bool
    {
        return in_array($type, ['money', 'int', 'percent', 'decimal'], true);
    }

    // ══════════════════════════════════════════════════════════════════
    //  Excel
    // ══════════════════════════════════════════════════════════════════

    /**
     * The workbook, as bytes.
     *
     * Four sheets, and a sheet is only created when it would have something
     * on it. A workbook whose third tab is an empty grid with a heading is
     * worse than a three-tab workbook: it invites the reader to wonder what
     * they did wrong.
     */
    public static function xlsx(array $doc): string
    {
        $book = new XlsxWriter();

        self::xlsxSummary($book, $doc);

        if (!empty($doc['sections'])) {
            self::xlsxAnalytics($book, $doc);
        }
        if (!empty($doc['records']) && !empty($doc['records']['rows'])) {
            self::xlsxRecords($book, $doc);
        }
        if (!empty($doc['quality'])) {
            self::xlsxQuality($book, $doc);
        }

        return $book->output();
    }

    private static function xlsxSummary(XlsxWriter $book, array $doc): void
    {
        $book->openSheet('Summary', [34, 22, 22, 22, 22]);

        $book->writeRow([['v' => $doc['company'], 's' => 'title']]);
        $book->writeRow([['v' => $doc['title'] . ' report', 's' => 'label']]);
        $book->writeRow([['v' => $doc['blurb'], 's' => 'subtitle']]);
        $book->blank();

        foreach ($doc['meta'] as $entry) {
            $book->writeRow([
                ['v' => $entry['term'], 's' => 'label'],
                $entry['value'],
                $entry['sub'],
            ]);
        }

        if (!empty($doc['filters'])) {
            $book->blank();
            $book->writeRow([['v' => 'Filters applied', 's' => 'section']]);
            foreach ($doc['filters'] as $filter) {
                $book->writeRow([['v' => $filter['label'], 's' => 'label'], $filter['value']]);
            }
        }

        if (!empty($doc['kpis'])) {
            $book->blank();
            $book->writeRow([['v' => 'Key figures', 's' => 'section']]);
            $book->writeRow([
                ['v' => 'Figure', 's' => 'head'],
                ['v' => 'Value', 's' => 'head'],
                ['v' => 'What it means', 's' => 'head'],
                ['v' => 'Against the previous period', 's' => 'head'],
            ]);

            foreach ($doc['kpis'] as $kpi) {
                // The value is written as the text the screen shows. These
                // are headline figures a person reads, and several of them
                // ("Not available", "12.4 days") are not numbers at all — the
                // Analytics sheet is where the arithmetic lives.
                $book->writeRow([
                    ['v' => $kpi['label'], 's' => 'label'],
                    $kpi['value'],
                    ['v' => $kpi['context'], 's' => 'text'],
                    $kpi['delta'],
                ]);
            }
        }

        if (!empty($doc['insights'])) {
            $book->blank();
            $book->writeRow([['v' => 'Insights', 's' => 'section']]);
            $book->writeRow([
                ['v' => 'Finding', 's' => 'head'],
                ['v' => 'Measure', 's' => 'head'],
                ['v' => 'Detail', 's' => 'head'],
            ]);

            foreach ($doc['insights'] as $insight) {
                $book->writeRow([
                    ['v' => $insight['label'], 's' => 'label'],
                    $insight['metric'],
                    ['v' => $insight['text'], 's' => 'text'],
                ]);
            }
        }

        $book->closeSheet();
    }

    private static function xlsxAnalytics(XlsxWriter $book, array $doc): void
    {
        // The widest section decides the sheet's column widths, because a
        // sheet has one set of them and every table on it shares them.
        $widths = [];
        foreach ($doc['sections'] as $section) {
            foreach (array_values($section['columns']) as $i => $column) {
                $widths[$i] = max($widths[$i] ?? 12.0, self::xlsxWidth($column));
            }
        }

        $book->openSheet('Analytics', $widths);

        $book->writeRow([['v' => $doc['title'] . ' — analytics', 's' => 'title']]);
        $book->writeRow([['v' => self::periodLine($doc), 's' => 'subtitle']]);
        $book->blank();

        foreach ($doc['sections'] as $section) {
            $book->writeRow([['v' => $section['title'], 's' => 'section']]);

            if (!empty($section['note'])) {
                $book->writeRow([['v' => $section['note'], 's' => 'subtitle']]);
            }

            if (empty($section['rows'])) {
                $book->writeRow([['v' => (string) ($section['empty'] ?? 'Nothing to show.'), 's' => 'subtitle']]);
                $book->blank();
                continue;
            }

            $book->writeRow(array_map(
                static fn(array $c): array => ['v' => $c['label'], 's' => 'head'],
                array_values($section['columns'])
            ));

            foreach (array_values($section['rows']) as $index => $row) {
                $cells = [];
                foreach ($section['columns'] as $column) {
                    $type = $column['type'] === 'auto' && isset($section['types'])
                        ? (string) ($section['types'][$index] ?? 'text')
                        : $column['type'];
                    $cells[] = self::xlsxCell($row[$column['key']] ?? null, $type);
                }
                $book->writeRow($cells);
            }

            if (!empty($section['total'])) {
                $cells = [];
                foreach ($section['columns'] as $i => $column) {
                    $value = $section['total'][$column['key']] ?? null;
                    $cells[] = $i === 0
                        ? ['v' => $value ?? 'Total', 's' => 'total']
                        : self::xlsxCell($value, $column['type'], true);
                }
                $book->writeRow($cells);
            }

            $book->blank();
        }

        $book->closeSheet();
    }

    /**
     * The record sheet, written straight through.
     *
     * reportExportWalk() emits one row at a time and each is written to the
     * sheet's temporary file as it arrives, so the workbook never holds more
     * than one chunk of the ledger in memory however long the period is.
     */
    private static function xlsxRecords(XlsxWriter $book, array $doc): void
    {
        $records = $doc['records'];
        $widths  = array_map([self::class, 'xlsxWidth'], array_values($records['columns']));

        $book->openSheet('Records', $widths);

        $book->writeRow([['v' => $records['title'], 's' => 'title']]);
        $book->writeRow([['v' => self::periodLine($doc), 's' => 'subtitle']]);
        $book->writeRow([['v' => $records['note'], 's' => 'subtitle']]);
        $book->blank();

        $book->writeRow(array_map(
            static fn(array $c): array => ['v' => $c['label'], 's' => 'head'],
            array_values($records['columns'])
        ));

        $columns = $records['columns'];
        $walk    = reportExportWalk(
            $records['rows'],
            $records['fetch'],
            $records['total'],
            static function (array $row) use ($book, $columns): void {
                $cells = [];
                foreach ($columns as $column) {
                    $cells[] = self::xlsxCell($row[$column['key']] ?? null, $column['type']);
                }
                $book->writeRow($cells);
            }
        );

        $book->blank();
        $book->writeRow([[
            'v' => $walk['truncated']
                ? number_format($walk['rows']) . ' rows exported — the ceiling for a single export. '
                  . 'Narrow the period or add a filter to bring the whole set into one file.'
                : number_format($walk['rows']) . ' ' . ($walk['rows'] === 1 ? 'row' : 'rows') . ' exported.',
            's' => 'subtitle',
        ]]);

        $book->closeSheet();
    }

    private static function xlsxQuality(XlsxWriter $book, array $doc): void
    {
        $book->openSheet('Data quality', [34, 12, 16, 80]);

        $book->writeRow([['v' => 'Data quality', 's' => 'title']]);
        $book->writeRow([[
            'v' => 'Records that disagree with each other. None of these is a reporting error and '
                 . 'none is corrected here — the figures in this workbook are arithmetically right, '
                 . 'and this is what they were computed over.',
            's' => 'subtitle',
        ]]);
        $book->blank();

        $book->writeRow([
            ['v' => 'Finding', 's' => 'head'],
            ['v' => 'Records', 's' => 'head'],
            ['v' => 'Amount', 's' => 'head'],
            ['v' => 'What it means', 's' => 'head'],
        ]);

        foreach ($doc['quality'] as $issue) {
            $book->writeRow([
                ['v' => $issue['label'], 's' => 'label'],
                ['v' => $issue['count'], 's' => 'int'],
                ['v' => $issue['amount'], 's' => 'money'],
                ['v' => $issue['text'], 's' => 'text'],
            ]);
        }

        $book->closeSheet();
    }

    /** A value and the style its type asks for. */
    private static function xlsxCell(mixed $value, string $type, bool $total = false): array
    {
        if ($value === null) {
            return ['v' => null, 's' => 'default'];
        }

        // Dates are 'Y-m-d' strings and must be caught before the is_string()
        // branch below, or they land in the workbook as text: right-hand
        // column, no date format, and useless for sorting or a date filter.
        // XlsxWriter turns them into day serials and falls back to text if
        // one cannot be read.
        if ($type === 'date') {
            return ['v' => $value, 's' => 'date'];
        }

        // A figure the analytics declared unavailable arrives as text and
        // stays text — writing "Not available" under a currency format would
        // produce a cell Excel reads as broken.
        if (is_string($value)) {
            $textish = in_array($type, ['text', 'label', 'prose'], true);

            return ['v' => $value, 's' => $total ? 'total' : ($textish ? 'text' : 'default')];
        }

        $style = match ($type) {
            'money'   => $total ? 'total_money' : 'money',
            'int'     => $total ? 'total_int' : 'int',
            'percent' => 'percent',
            'decimal' => 'decimal',
            'date'    => 'date',
            default   => $total ? 'total' : 'default',
        };

        return ['v' => $value, 's' => $style];
    }

    /** A column's width in Excel's character units, from its layout weight. */
    private static function xlsxWidth(array $column): float
    {
        return max(10.0, min(52.0, (float) $column['w'] * 6.5));
    }

    // ══════════════════════════════════════════════════════════════════
    //  CSV
    // ══════════════════════════════════════════════════════════════════

    /**
     * Stream the report's primary record set.
     *
     * Written straight to the output buffer rather than assembled: a period
     * with fifteen thousand payments in it is a perfectly reasonable thing to
     * ask for, and it should not cost fifteen thousand rows of memory to
     * answer.
     *
     * The preamble is what makes the file self-describing. A CSV that arrives
     * in somebody's inbox with no statement of its period, its filters or the
     * access scope it was taken under is a column of numbers nobody can check.
     */
    public static function csv(array $doc): void
    {
        $out = fopen('php://output', 'w');
        if ($out === false) {
            return;
        }

        // Excel reads a CSV as the machine's legacy codepage unless the file
        // says otherwise, which turns every currency symbol and em dash in
        // here into mojibake. The BOM is what makes it read UTF-8.
        fwrite($out, "\xEF\xBB\xBF");

        $put = static function (array $row) use ($out): void {
            fputcsv($out, array_map(static fn($v): string => exportSafeText((string) $v), $row));
        };

        $put([$doc['company']]);
        $put([$doc['title'] . ' report']);

        foreach ($doc['meta'] as $entry) {
            $put([$entry['term'], $entry['value'], (string) ($entry['sub'] ?? '')]);
        }

        foreach ($doc['filters'] as $filter) {
            $put(['Filter', $filter['label'], $filter['value']]);
        }

        $records = $doc['records'] ?? null;

        if (!$records) {
            $put([]);
            $put(['This report has no record table to export.']);
            fclose($out);

            return;
        }

        $put([]);
        $put([$records['title']]);
        $put([$records['note']]);
        $put([]);

        if (!$records['rows']) {
            $put([(string) $records['empty']]);
            fclose($out);

            return;
        }

        $columns = array_values($records['columns']);
        $put(array_column($columns, 'label'));

        $walk = reportExportWalk(
            $records['rows'],
            $records['fetch'],
            $records['total'],
            static function (array $row) use ($put, $columns): void {
                $cells = [];
                foreach ($columns as $column) {
                    $cells[] = exportRaw($row[$column['key']] ?? null, $column['type']);
                }
                $put($cells);
            }
        );

        $put([]);
        $put([$walk['truncated']
            ? number_format($walk['rows']) . ' rows exported — the ceiling for a single export. '
              . 'Narrow the period or add a filter to bring the whole set into one file.'
            : number_format($walk['rows']) . ' ' . ($walk['rows'] === 1 ? 'row' : 'rows') . ' exported.',
        ]);

        fclose($out);
    }

    // ══════════════════════════════════════════════════════════════════

    /** "Last 30 days (05 Aug 2026 – 03 Sep 2026)", for a subtitle or footer. */
    private static function periodLine(array $doc): string
    {
        $period = $doc['meta'][0] ?? null;

        return $period === null
            ? ''
            : $period['value'] . ($period['sub'] !== null ? ' (' . $period['sub'] . ')' : '');
    }
}
