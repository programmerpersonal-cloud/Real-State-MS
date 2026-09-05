<?php
/**
 * Report exports — the vocabulary the three formats share.
 *
 * Phase 9 adds one thing to the reporting module: the ability to take the
 * report you are looking at away with you. It adds no analytics, no queries
 * and no definitions. Every figure in a PDF, a workbook or a CSV is a value
 * that ReportController already put in scope for the screen, which is what
 * makes "screen KPI = export KPI" a structural fact rather than a promise
 * somebody has to keep re-checking.
 *
 * This file holds the parts that are the same whichever format is asked for:
 * which formats exist, what a file is called, how a value that a spreadsheet
 * would execute is defused, and how a paged dataset is walked without
 * pulling the whole ledger into memory.
 *
 * Deliberately NOT loaded by includes/init.php. Nothing outside an export
 * request needs it, and the same reasoning keeps backup_engine.php out of the
 * bootstrap: a page that only reads should not pay for the code that writes.
 */

/**
 * The formats, and the label each is offered under.
 *
 * This is the allowlist. `?format=` is measured against its keys and nothing
 * else ever reaches a filename, a MIME type or a writer.
 */
const REPORT_EXPORT_FORMATS = [
    'pdf'  => 'PDF',
    'xlsx' => 'Excel',
    'csv'  => 'CSV',
];

/**
 * Rows fetched per round trip when an export walks a paged dataset.
 *
 * 100 because that is the ceiling every table method in CoreAnalytics clamps
 * its own LIMIT to. Asking for more would silently get 100 anyway and the
 * walker would then compute the wrong next offset.
 */
const REPORT_EXPORT_CHUNK = 100;

/**
 * The most rows any one export will walk.
 *
 * Not a business rule — a memory ceiling. A CSV of twenty thousand payments
 * is already past the point where a spreadsheet is the right tool, and an
 * export that quietly tried for a million would take the site down rather
 * than answer the question. When the cap bites, the file says so in its own
 * header block instead of just ending.
 */
const REPORT_EXPORT_MAX_ROWS = 20000;

/**
 * Rows a PDF prints from a record table before it stops and says how many
 * it left.
 *
 * A management PDF is read; a CSV is processed. Three hundred pages of
 * payment lines is not a report, so the PDF carries the head of the table and
 * names the format that carries the rest.
 */
const REPORT_EXPORT_PDF_ROWS = 60;

/**
 * The requested format, or '' when it is not one we have.
 *
 * uiPick() rather than in_array() so the comparison is the same one every
 * other allowlist in this application uses.
 */
function reportExportFormat(mixed $raw): string
{
    return uiPick(is_string($raw) ? $raw : '', array_keys(REPORT_EXPORT_FORMATS));
}

/**
 * A filename component reduced to characters no filesystem or
 * Content-Disposition header can misread.
 *
 * Everything that is not a letter or a digit becomes an underscore, runs
 * collapse, and the result is cut to a sane length. Nothing that reaches this
 * is trusted: the company name is admin-supplied and the tab name, though it
 * comes from an allowlist, is still passed through so that adding a tab
 * called "Q1/Q2" later cannot produce a path separator in a header.
 */
function exportSlug(string $value, int $max = 40): string
{
    $slug = preg_replace('/[^A-Za-z0-9]+/', '_', $value) ?? '';
    $slug = trim($slug, '_');

    return $slug === '' ? '' : substr($slug, 0, $max);
}

/**
 * The download's name: company, module, report, date, extension.
 *
 * Deterministic on purpose. Two people exporting the same report on the same
 * day get the same filename, which is what makes a folder of these sortable
 * and what stops "report (3).pdf" from being the only thing distinguishing
 * one month's numbers from another's.
 */
function reportExportFilename(string $tab, string $format, string $slice = ''): string
{
    $company = exportSlug(companyName(), 24) ?: 'SAXANE';
    $report  = exportSlug(ReportController::TABS[$tab]['label'] ?? $tab, 24) ?: 'Report';
    $ext     = isset(REPORT_EXPORT_FORMATS[$format]) ? $format : 'txt';

    // A drill-down names the figure it is showing. Without it, exporting the
    // arrears panel and then the outstanding panel from the same report on
    // the same day produces two files with one name, and the second silently
    // becomes "(1)" in a downloads folder where nobody can tell them apart.
    $part = $slice === '' ? '' : '_' . (exportSlug($slice, 32) ?: 'Detail');

    return sprintf('%s_Reports_%s%s_%s.%s', $company, $report, $part, date('Y-m-d'), $ext);
}

/** The MIME type a format is served as. */
function reportExportMime(string $format): string
{
    return match ($format) {
        'pdf'  => 'application/pdf',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'csv'  => 'text/csv; charset=UTF-8',
        default => 'application/octet-stream',
    };
}

/**
 * Defuse a value a spreadsheet would run instead of read.
 *
 * Property titles, tenant names and issue descriptions in these files were
 * typed by people. Excel and Sheets treat a leading =, +, - or @ as the start
 * of a formula, so a property called `=HYPERLINK(...)` becomes a live cell in
 * whoever's spreadsheet this lands in. A leading tab keeps the text visible
 * and makes the cell inert; quoting alone does not.
 *
 * The same rule is applied to CSV and to XLSX. The injection is a property of
 * the reader, not of the container.
 */
function exportSafeText(string $text): string
{
    return $text !== '' && str_contains("=+-@\t\r", $text[0]) ? "\t" . $text : $text;
}

/**
 * Hand the browser a file and stop.
 *
 * The buffer teardown and session_write_close() are the two lines
 * DashboardController::stream() opens with, and for the same reasons: views
 * render inside ob_start(), and a download should not hold the session lock
 * while the user's other tabs wait behind it.
 *
 * Called immediately before the bytes are written, never earlier — a header
 * sent and then an exception thrown produces a corrupt download with a
 * plausible name, which is worse than an error page.
 */
function reportExportHeaders(string $filename, string $format, ?int $length = null): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    // The filename has already been through exportSlug(), so it holds only
    // letters, digits, underscores, one dot and a hyphenated date. It cannot
    // carry a quote or a newline into the header.
    header('Content-Type: ' . reportExportMime($format));
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, max-age=0, must-revalidate');
    header('Pragma: no-cache');

    if ($length !== null) {
        header('Content-Length: ' . $length);
    }
}

/**
 * Walk a record set the report has already started fetching.
 *
 * The first page is not re-queried. ReportController has already asked
 * CoreAnalytics for it — it is the page the screen would have drawn — so the
 * walker emits those rows and only then goes back for the rest, one clamped
 * chunk at a time. A report whose table is a fixed top-N (the Overview's five
 * earners, the Financial report's twenty properties) has its whole dataset in
 * hand already and costs no extra query at all.
 *
 * `$fetch` is a closure over the same CoreAnalytics instance and the same
 * method the screen used, so the scope, the filters and the ordering are not
 * merely equivalent to the screen's — they are the screen's.
 *
 * @param array<int,array<string,mixed>> $first  rows already fetched
 * @param callable(int,int):array|null   $fetch  fn(limit, offset): rows
 * @param callable(array<string,mixed>):void $emit
 * @return array{rows:int,truncated:bool}
 */
function reportExportWalk(array $first, ?callable $fetch, ?int $total, callable $emit): array
{
    $seen = 0;

    foreach ($first as $row) {
        if ($seen >= REPORT_EXPORT_MAX_ROWS) {
            return ['rows' => $seen, 'truncated' => true];
        }
        $emit($row);
        $seen++;
    }

    // Nothing more to get: either the caller had no pager, or the first page
    // was the whole set.
    if ($fetch === null || $total === null || $seen >= $total) {
        return ['rows' => $seen, 'truncated' => false];
    }

    while ($seen < $total) {
        if ($seen >= REPORT_EXPORT_MAX_ROWS) {
            return ['rows' => $seen, 'truncated' => true];
        }

        $chunk = $fetch(REPORT_EXPORT_CHUNK, $seen);
        if (!$chunk) {
            // The set shrank under us, or the offset ran past the clamp in
            // the model. Either way there is nothing further to write, and
            // stopping is the only honest response.
            break;
        }

        foreach ($chunk as $row) {
            if ($seen >= REPORT_EXPORT_MAX_ROWS) {
                return ['rows' => $seen, 'truncated' => true];
            }
            $emit($row);
            $seen++;
        }
    }

    return ['rows' => $seen, 'truncated' => false];
}

/**
 * A cell's value, rendered for a human-readable format (PDF).
 *
 * The empty cases are the point of this function. Approved reporting
 * semantics say an unavailable figure is never a zero, so null renders as an
 * em dash — the same mark the screen prints — and a figure the analytics
 * declared unavailable arrives here already as the string "Not available"
 * and passes straight through.
 */
function exportDisplay(mixed $value, string $type): string
{
    if ($value === null || $value === '') {
        return '—';
    }

    // Dates arrive from the database as 'Y-m-d' strings, so they have to be
    // recognised by their column type before the is_string() shortcut below
    // hands them straight through. Without this the PDF printed 2026-08-16
    // where the screen printed Aug 16, 2026 — the same day, stated two
    // different ways in two documents claiming to be the same report.
    if ($type === 'date') {
        return formatDate((string) $value);
    }

    if (is_string($value)) {
        return $value;
    }

    return match ($type) {
        'money'   => formatCurrency((float) $value),
        'percent' => reportPercent((float) $value),
        'int'     => number_format((int) $value),
        'decimal' => number_format((float) $value, 1),
        default   => (string) $value,
    };
}

/**
 * A cell's value, rendered for a machine-readable format (CSV).
 *
 * Money loses its symbol and its thousands separators, percentages lose their
 * sign, dates are ISO. What a spreadsheet gets is a number it can add up
 * rather than a string it has to be taught to parse — and null stays empty,
 * because a blank cell and a zero are different claims and only one of them
 * is true.
 */
function exportRaw(mixed $value, string $type): string
{
    if ($value === null) {
        return '';
    }

    // A date passes through as the ISO string the database holds. That is
    // already the machine-readable form, and it is the one form every
    // spreadsheet and every importer reads the same way.
    if (is_string($value)) {
        return exportSafeText($value);
    }

    return match ($type) {
        'money'   => number_format((float) $value, 2, '.', ''),
        'percent',
        'decimal' => (string) round((float) $value, 2),
        'int'     => (string) (int) $value,
        default   => (string) $value,
    };
}
