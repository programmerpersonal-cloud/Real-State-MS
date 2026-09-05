<?php
/**
 * PdfWriter — a small, dependency-free PDF 1.4 generator.
 *
 * Written rather than installed, and that is a considered choice. This
 * application has no Composer manifest and no vendor directory; adding one
 * for a report download would mean a dependency tree, a lockfile and an
 * update story for a feature that needs text, rules, filled rectangles and
 * page numbers. Everything below is those four things.
 *
 * What it deliberately does not do: embedded fonts, images, transparency,
 * links, outlines, tagging. It uses the two base-14 faces every conforming
 * reader already has (Helvetica and Helvetica-Bold) in WinAnsi encoding, so
 * the file has no font programme in it and stays in the tens of kilobytes.
 *
 * Text measurement is real. The Adobe metrics for both faces are tabulated
 * below, which is what lets a column truncate at exactly the width it has,
 * a number right-align to its cell edge, and a paragraph wrap on a word
 * boundary. Estimating character widths instead is what makes home-grown PDFs
 * look home-grown.
 *
 * Coordinates in the public API are top-down and in points: y = 0 is the top
 * edge of the page, y grows downward, and 1pt = 1/72 inch. PDF's own origin
 * is bottom-left; the conversion happens in one place, at the drawing
 * primitives, so nothing above them has to think about it.
 */
class PdfWriter
{
    // ─── Page geometry (A4 portrait, in points) ────────────────────────

    public const PAGE_W = 595.28;
    public const PAGE_H = 841.89;

    private const MARGIN_X    = 42.0;
    private const MARGIN_TOP  = 44.0;
    private const MARGIN_FOOT = 46.0;

    // ─── Palette, taken from the product's own tokens ──────────────────
    //
    // A PDF cannot read a stylesheet, so these are transcribed from
    // assets/css/design-system.css rather than invented. An exported report
    // that used different blues from the screen it came off would read as a
    // different document about the same numbers.

    public const INK      = [0.063, 0.133, 0.173];  // --ink       #10222c
    public const PRIMARY  = [0.039, 0.388, 0.659];  // --primary   #0a63a8
    public const SUCCESS  = [0.082, 0.502, 0.239];  // --success   #15803d
    public const WARNING  = [0.706, 0.325, 0.035];  // --warning   #b45309
    public const DANGER   = [0.784, 0.118, 0.118];  // --danger    #c81e1e
    public const INFO     = [0.000, 0.373, 0.620];  // --info      #005f9e
    public const PURPLE   = [0.427, 0.157, 0.851];  // --purple    #6d28d9
    public const MUTED    = [0.400, 0.447, 0.478];
    public const RULE     = [0.851, 0.871, 0.886];
    public const BAND     = [0.961, 0.969, 0.976];
    public const WHITE    = [1.0, 1.0, 1.0];

    /** Font keys, as they appear in each page's resource dictionary. */
    public const REGULAR = 'F1';
    public const BOLD    = 'F2';

    /**
     * Adobe's Helvetica widths for WinAnsi 32–126, in 1/1000 em.
     *
     * Everything above 126 falls back to 556, which is the width of most
     * accented Latin letters in this face. The error is invisible at the one
     * place it can occur — a proper noun containing a diacritic — and the
     * alternative is a 224-entry table for a rounding difference.
     */
    private const W_REGULAR = [
        278, 278, 355, 556, 556, 889, 667, 191, 333, 333, 389, 584, 278, 333, 278, 278,
        556, 556, 556, 556, 556, 556, 556, 556, 556, 556, 278, 278, 584, 584, 584, 556,
        1015, 667, 667, 722, 722, 667, 611, 778, 722, 278, 500, 667, 556, 833, 722, 778,
        667, 778, 722, 667, 611, 722, 667, 944, 667, 667, 611, 278, 278, 278, 469, 556,
        333, 556, 556, 500, 556, 556, 278, 556, 556, 222, 222, 500, 222, 833, 556, 556,
        556, 556, 333, 500, 278, 556, 500, 722, 500, 500, 500, 334, 260, 334, 584,
    ];

    /** The same table for Helvetica-Bold. */
    private const W_BOLD = [
        278, 333, 474, 556, 556, 889, 722, 238, 333, 333, 389, 584, 278, 333, 278, 278,
        556, 556, 556, 556, 556, 556, 556, 556, 556, 556, 333, 333, 584, 584, 584, 611,
        975, 722, 722, 722, 722, 667, 611, 778, 722, 278, 556, 722, 611, 833, 722, 778,
        667, 778, 722, 667, 611, 722, 667, 944, 667, 667, 611, 333, 278, 333, 584, 556,
        333, 556, 611, 556, 611, 556, 333, 611, 611, 278, 278, 556, 278, 889, 611, 611,
        611, 611, 389, 556, 333, 611, 556, 778, 556, 556, 500, 389, 280, 389, 584,
    ];

    /** Content streams, one string of operators per page. */
    private array $pages = [];

    /** The stream being written to. */
    private int $current = -1;

    /** The writing cursor, measured down from the top of the page. */
    private float $y = self::MARGIN_TOP;

    private string $footerLeft;
    private string $runningTitle;

    public function __construct(string $footerLeft = '', string $runningTitle = '')
    {
        $this->footerLeft   = $footerLeft;
        $this->runningTitle = $runningTitle;
        $this->addPage(false);
    }

    // ─── Page and cursor ───────────────────────────────────────────────

    /** The x of the left text edge. */
    public function left(): float
    {
        return self::MARGIN_X;
    }

    /** The x of the right text edge. */
    public function right(): float
    {
        return self::PAGE_W - self::MARGIN_X;
    }

    /** The usable width between the margins. */
    public function width(): float
    {
        return self::PAGE_W - 2 * self::MARGIN_X;
    }

    /** The lowest y a drawing may reach before the footer band. */
    public function bottom(): float
    {
        return self::PAGE_H - self::MARGIN_FOOT;
    }

    public function y(): float
    {
        return $this->y;
    }

    public function moveTo(float $y): void
    {
        $this->y = $y;
    }

    public function space(float $points): void
    {
        $this->y += $points;
    }

    public function pageCount(): int
    {
        return count($this->pages);
    }

    /**
     * Start a page.
     *
     * `$runningHead` draws the small continuation line that tells a reader
     * holding page four which report they are holding. The first page has a
     * masthead instead and passes false.
     */
    public function addPage(bool $runningHead = true): void
    {
        $this->pages[] = '';
        $this->current = count($this->pages) - 1;
        $this->y       = self::MARGIN_TOP;

        if ($runningHead && $this->runningTitle !== '') {
            $this->text($this->left(), $this->y + 7, $this->runningTitle, 8, self::BOLD, self::MUTED);
            $this->y += 12;
            $this->rule();
            $this->y += 12;
        }
    }

    /**
     * Break to a new page if `$needed` points will not fit on this one.
     *
     * @return bool whether a break happened
     */
    public function ensure(float $needed): bool
    {
        if ($this->y + $needed <= $this->bottom()) {
            return false;
        }
        $this->addPage();

        return true;
    }

    // ─── Drawing primitives ────────────────────────────────────────────

    private function op(string $ops): void
    {
        $this->pages[$this->current] .= $ops;
    }

    /** PDF's y, from this class's top-down y. */
    private static function py(float $y): float
    {
        return self::PAGE_H - $y;
    }

    private static function n(float $v): string
    {
        return rtrim(rtrim(number_format($v, 3, '.', ''), '0'), '.') ?: '0';
    }

    private static function colour(array $rgb, bool $stroke = false): string
    {
        return self::n($rgb[0]) . ' ' . self::n($rgb[1]) . ' ' . self::n($rgb[2])
             . ($stroke ? ' RG ' : ' rg ');
    }

    /**
     * One line of text, with its baseline at `$y`.
     *
     * Returns the width it occupied, so a caller drawing a label and a value
     * on the same line does not have to measure twice.
     */
    public function text(
        float $x,
        float $y,
        string $value,
        float $size = 9.0,
        string $font = self::REGULAR,
        array $colour = self::INK
    ): float {
        if ($value === '') {
            return 0.0;
        }

        $this->op(
            'BT ' . self::colour($colour) . '/' . $font . ' ' . self::n($size) . ' Tf '
            . self::n($x) . ' ' . self::n(self::py($y)) . ' Td '
            . '(' . self::escape($value) . ') Tj ET' . "\n"
        );

        return self::widthOf($value, $size, $font);
    }

    /** Text whose right edge sits at `$x` — for a column of figures. */
    public function textRight(
        float $x,
        float $y,
        string $value,
        float $size = 9.0,
        string $font = self::REGULAR,
        array $colour = self::INK
    ): void {
        $this->text($x - self::widthOf($value, $size, $font), $y, $value, $size, $font, $colour);
    }

    /** Text centred on `$x`. */
    public function textCentre(
        float $x,
        float $y,
        string $value,
        float $size = 9.0,
        string $font = self::REGULAR,
        array $colour = self::INK
    ): void {
        $this->text($x - self::widthOf($value, $size, $font) / 2, $y, $value, $size, $font, $colour);
    }

    /** A filled and/or stroked rectangle, `$y` being its top edge. */
    public function rect(
        float $x,
        float $y,
        float $w,
        float $h,
        ?array $fill = null,
        ?array $stroke = null,
        float $lineWidth = 0.5
    ): void {
        if ($fill === null && $stroke === null) {
            return;
        }

        $ops = '';
        if ($fill !== null) {
            $ops .= self::colour($fill);
        }
        if ($stroke !== null) {
            $ops .= self::colour($stroke, true) . self::n($lineWidth) . ' w ';
        }

        $ops .= self::n($x) . ' ' . self::n(self::py($y) - $h) . ' '
              . self::n($w) . ' ' . self::n($h) . ' re ';

        $ops .= match (true) {
            $fill !== null && $stroke !== null => 'B',
            $fill !== null                     => 'f',
            default                            => 'S',
        };

        $this->op($ops . "\n");
    }

    public function line(float $x1, float $y1, float $x2, float $y2, array $colour = self::RULE, float $w = 0.5): void
    {
        $this->op(
            self::colour($colour, true) . self::n($w) . ' w '
            . self::n($x1) . ' ' . self::n(self::py($y1)) . ' m '
            . self::n($x2) . ' ' . self::n(self::py($y2)) . ' l S' . "\n"
        );
    }

    /** A hairline across the text column at the cursor. */
    public function rule(array $colour = self::RULE, float $w = 0.5): void
    {
        $this->line($this->left(), $this->y, $this->right(), $this->y, $colour, $w);
    }

    // ─── Measurement, wrapping and encoding ────────────────────────────

    /** The width of a string, in points, at a given size and face. */
    public static function widthOf(string $value, float $size, string $font = self::REGULAR): float
    {
        $table = $font === self::BOLD ? self::W_BOLD : self::W_REGULAR;
        $bytes = self::winAnsi($value);
        $units = 0;

        $len = strlen($bytes);
        for ($i = 0; $i < $len; $i++) {
            $code   = ord($bytes[$i]);
            $units += ($code >= 32 && $code <= 126) ? $table[$code - 32] : 556;
        }

        return $units * $size / 1000;
    }

    /** A string cut to fit `$max` points, with an ellipsis when it was cut. */
    public static function fit(string $value, float $max, float $size, string $font = self::REGULAR): string
    {
        if ($max <= 0 || self::widthOf($value, $size, $font) <= $max) {
            return $value;
        }

        $ellipsis = '...';
        $room     = $max - self::widthOf($ellipsis, $size, $font);
        if ($room <= 0) {
            return '';
        }

        $out = '';
        foreach (preg_split('//u', $value, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $char) {
            if (self::widthOf($out . $char, $size, $font) > $room) {
                break;
            }
            $out .= $char;
        }

        return rtrim($out) . $ellipsis;
    }

    /**
     * Break a paragraph into lines that fit `$max` points.
     *
     * Breaks on spaces. A single word longer than the column is cut with
     * fit() rather than allowed to run into the margin.
     *
     * @return string[]
     */
    public static function wrap(string $value, float $max, float $size, string $font = self::REGULAR): array
    {
        $words = preg_split('/\s+/u', trim($value), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $lines = [];
        $line  = '';

        foreach ($words as $word) {
            $candidate = $line === '' ? $word : $line . ' ' . $word;
            if (self::widthOf($candidate, $size, $font) <= $max) {
                $line = $candidate;
                continue;
            }
            if ($line !== '') {
                $lines[] = $line;
            }
            $line = self::widthOf($word, $size, $font) > $max
                ? self::fit($word, $max, $size, $font)
                : $word;
        }

        if ($line !== '') {
            $lines[] = $line;
        }

        return $lines ?: [''];
    }

    /**
     * A wrapped paragraph, drawn from the cursor down.
     *
     * Page-breaks between lines rather than mid-line, so a sentence is never
     * split across a fold.
     */
    public function paragraph(
        string $value,
        float $size = 9.0,
        string $font = self::REGULAR,
        array $colour = self::INK,
        ?float $width = null,
        float $leading = 1.35
    ): void {
        $width = $width ?? $this->width();
        $step  = $size * $leading;

        foreach (self::wrap($value, $width, $size, $font) as $line) {
            $this->ensure($step);
            $this->y += $step;
            $this->text($this->left(), $this->y - $size * 0.25, $line, $size, $font, $colour);
        }
    }

    /**
     * UTF-8 in, WinAnsi (cp1252) out.
     *
     * The reporting module writes real typography — em dashes in empty
     * states, en dashes between dates, curly apostrophes in insight text, a
     * true minus sign in reportCompact(). cp1252 has code points for most of
     * those and iconv finds them.
     *
     * The map below is only for the characters cp1252 genuinely lacks. They
     * are replaced while the string is still valid UTF-8 — replacing them
     * with raw cp1252 bytes first would leave a mixed-encoding string that
     * iconv would then reject — because //TRANSLIT's substitutions vary
     * between libc builds and a "?" in the middle of a figure is not an
     * acceptable outcome.
     */
    private static function winAnsi(string $value): string
    {
        static $map = [
            "\xE2\x88\x92" => '-',   // − true minus
            "\xE2\x86\x91" => '+',   // ↑
            "\xE2\x86\x93" => '-',   // ↓
            "\xE2\x9C\x93" => 'Y',   // ✓
            "\xC2\xA0"     => ' ',   // non-breaking space
        ];

        $value = strtr($value, $map);

        // Pure ASCII, which is the overwhelmingly common case: nothing for
        // iconv to do, and no risk of a //TRANSLIT build rewriting something
        // that was already correct.
        if (!preg_match('/[\x80-\xFF]/', $value)) {
            return $value;
        }

        $converted = @iconv('UTF-8', 'CP1252//TRANSLIT//IGNORE', $value);

        return $converted === false ? preg_replace('/[^\x20-\x7E]/', '', $value) ?? '' : $converted;
    }

    /** A string literal for a content stream. */
    private static function escape(string $value): string
    {
        return strtr(self::winAnsi($value), ['\\' => '\\\\', '(' => '\\(', ')' => '\\)', "\r" => '', "\n" => '']);
    }

    // ─── The file ──────────────────────────────────────────────────────

    /**
     * Serialise every page into a PDF document.
     *
     * The footer is stamped here rather than when each page was written,
     * because "Page 3 of 9" cannot be known until there is no page ten. Every
     * page's stream is finished, then the footer is appended to it, then the
     * whole set is numbered and cross-referenced.
     */
    public function output(): string
    {
        $total = max(1, count($this->pages));

        foreach ($this->pages as $index => $_) {
            $this->current = $index;
            $this->footer($index + 1, $total);
        }

        $objects = [];

        // 1 catalog · 2 page tree · 3-4 fonts · then two objects per page.
        $pageIds = [];
        for ($i = 0; $i < $total; $i++) {
            $pageIds[] = 5 + $i * 2;
        }

        $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objects[2] = '<< /Type /Pages /Count ' . $total . ' /Kids ['
                    . implode(' ', array_map(static fn(int $id): string => $id . ' 0 R', $pageIds))
                    . '] >>';
        $objects[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
        $objects[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';

        foreach ($this->pages as $index => $stream) {
            $pageId    = $pageIds[$index];
            $contentId = $pageId + 1;

            $objects[$pageId] = '<< /Type /Page /Parent 2 0 R'
                . ' /MediaBox [0 0 ' . self::n(self::PAGE_W) . ' ' . self::n(self::PAGE_H) . ']'
                . ' /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >>'
                . ' /Contents ' . $contentId . ' 0 R >>';

            // Flate is universally supported and takes a report's content
            // stream down by roughly four fifths — these are long runs of
            // repeated operators.
            $compressed = gzcompress($stream, 6);
            $objects[$contentId] = $compressed === false
                ? '<< /Length ' . strlen($stream) . " >>\nstream\n" . $stream . "\nendstream"
                : '<< /Length ' . strlen($compressed) . " /Filter /FlateDecode >>\nstream\n"
                  . $compressed . "\nendstream";
        }

        ksort($objects);

        $out     = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];

        foreach ($objects as $id => $body) {
            $offsets[$id] = strlen($out);
            $out .= $id . " 0 obj\n" . $body . "\nendobj\n";
        }

        $xref  = strlen($out);
        $count = count($objects) + 1;

        $out .= "xref\n0 " . $count . "\n0000000000 65535 f \n";
        for ($id = 1; $id < $count; $id++) {
            $out .= sprintf("%010d 00000 n \n", $offsets[$id] ?? 0);
        }

        $out .= "trailer\n<< /Size " . $count . " /Root 1 0 R"
              . " /Info << /Producer (" . self::escape(APP_NAME . ' Reports') . ")"
              . " /CreationDate (D:" . date('YmdHis') . ") >> >>\n"
              . "startxref\n" . $xref . "\n%%EOF";

        return $out;
    }

    /** The rule, the provenance line and the page number on one page. */
    private function footer(int $page, int $total): void
    {
        $y = self::PAGE_H - 30.0;

        $this->line($this->left(), $y - 10, $this->right(), $y - 10, self::RULE);

        if ($this->footerLeft !== '') {
            $this->text(
                $this->left(),
                $y,
                self::fit($this->footerLeft, $this->width() - 90, 7.5),
                7.5,
                self::REGULAR,
                self::MUTED
            );
        }

        $this->textRight($this->right(), $y, 'Page ' . $page . ' of ' . $total, 7.5, self::BOLD, self::MUTED);
    }
}
