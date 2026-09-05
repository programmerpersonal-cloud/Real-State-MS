<?php
/**
 * XlsxWriter — a small, dependency-free .xlsx generator.
 *
 * An xlsx file is a zip of XML parts, and PHP ships with both halves of that
 * already: ZipArchive for the container and nothing more exotic than string
 * concatenation for the parts. This class writes the six parts a spreadsheet
 * application actually needs and none of the twenty it tolerates being
 * absent — no charts, no pivot caches, no calculation chain, no theme.
 *
 * Two things it does properly rather than approximately, because they are the
 * difference between a workbook and a CSV that ends in .xlsx:
 *
 *   Numbers stay numbers. Money is written as 1240.5 under a currency format,
 *   not as the string "$1,240.50". A column of the first can be summed; a
 *   column of the second is text that happens to look like money.
 *
 *   A blank is a blank. A cell with no value is omitted entirely rather than
 *   written as nought, so "unavailable" survives the trip into Excel as an
 *   empty cell instead of arriving as a zero somebody will later average.
 *
 * Sheets are streamed to temporary files as they are written, so a workbook
 * with twenty thousand payment rows in it never exists in memory all at once.
 */
class XlsxWriter
{
    /**
     * Style names, in the order their <xf> records appear in styles.xml.
     *
     * The index into this list *is* the `s` attribute on a cell, so the order
     * here and the order in cellXfs() must match. They are kept adjacent in
     * this file for exactly that reason.
     */
    private const STYLES = [
        'default', 'title', 'subtitle', 'label', 'head', 'text',
        'money', 'int', 'percent', 'date', 'decimal',
        'total', 'total_money', 'total_int', 'section',
    ];

    /** Which styles carry a number format, and therefore expect a number. */
    private const NUMERIC = [
        'money'       => true,
        'int'         => true,
        'percent'     => true,
        'decimal'     => true,
        'date'        => true,
        'total_money' => true,
        'total_int'   => true,
    ];

    private string $currency;

    /** @var array<int,array{name:string,file:string}> */
    private array $sheets = [];

    /** @var resource|null */
    private $handle = null;

    private int $row = 0;

    /** Temporary files to remove once the archive is built. */
    private array $temp = [];

    public function __construct(?string $currency = null)
    {
        $this->currency = $currency ?? currencySymbol();
    }

    // ─── Sheets ────────────────────────────────────────────────────────

    /**
     * Begin a sheet.
     *
     * `$widths` are in Excel's character units, one per column from A. They
     * are written before any row because <cols> must precede <sheetData>,
     * which is also why a caller has to know its column shape up front — and
     * it always does: an export's columns come from the document model.
     *
     * @param array<int,float> $widths
     */
    public function openSheet(string $name, array $widths = []): void
    {
        $this->closeSheet();

        $file = tempnam(sys_get_temp_dir(), 'xlsx');
        if ($file === false) {
            throw new RuntimeException('Could not open a temporary file for the workbook.');
        }

        $handle = fopen($file, 'wb');
        if ($handle === false) {
            throw new RuntimeException('Could not write to the workbook temporary file.');
        }

        $this->temp[]  = $file;
        $this->handle  = $handle;
        $this->row     = 0;
        $this->sheets[] = ['name' => self::sheetName($name, count($this->sheets) + 1), 'file' => $file];

        fwrite($handle, '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">');

        if ($widths) {
            fwrite($handle, '<cols>');
            foreach (array_values($widths) as $i => $w) {
                fwrite($handle, sprintf(
                    '<col min="%d" max="%d" width="%s" customWidth="1"/>',
                    $i + 1,
                    $i + 1,
                    number_format(max(6.0, min(80.0, (float) $w)), 2, '.', '')
                ));
            }
            fwrite($handle, '</cols>');
        }

        fwrite($handle, '<sheetData>');
    }

    /**
     * Write one row.
     *
     * A cell is either a scalar (written as text), null (written as nothing
     * at all), or ['v' => value, 's' => style]. A styled cell whose value is
     * null is still nothing: the style says how a figure would have been
     * formatted, not that there is one.
     *
     * @param array<int,mixed> $cells
     */
    public function writeRow(array $cells): void
    {
        if ($this->handle === null) {
            return;
        }

        $this->row++;
        $xml = '<row r="' . $this->row . '">';

        foreach (array_values($cells) as $i => $cell) {
            $style = 'default';
            $value = $cell;

            if (is_array($cell)) {
                $style = (string) ($cell['s'] ?? 'default');
                $value = $cell['v'] ?? null;
            }

            if ($value === null || $value === '') {
                continue;
            }

            $ref = self::columnLetter($i + 1) . $this->row;
            $s   = self::styleIndex($style);

            if (isset(self::NUMERIC[$style])) {
                $number = $style === 'date' ? self::dateSerial((string) $value) : (float) $value;
                if ($number === null) {
                    // A date the database could not give us. Written as the
                    // text it is rather than silently becoming 1900-01-00.
                    $xml .= '<c r="' . $ref . '" s="' . self::styleIndex('text') . '" t="inlineStr"><is><t>'
                          . self::escape((string) $value) . '</t></is></c>';
                    continue;
                }
                $xml .= '<c r="' . $ref . '" s="' . $s . '"><v>'
                      . rtrim(rtrim(number_format($number, 6, '.', ''), '0'), '.') . '</v></c>';
                continue;
            }

            $xml .= '<c r="' . $ref . '" s="' . $s . '" t="inlineStr"><is><t xml:space="preserve">'
                  . self::escape(exportSafeText((string) $value)) . '</t></is></c>';
        }

        fwrite($this->handle, $xml . '</row>');
    }

    /** A blank spacer row. */
    public function blank(int $count = 1): void
    {
        for ($i = 0; $i < $count; $i++) {
            $this->row++;
        }
    }

    public function closeSheet(): void
    {
        if ($this->handle === null) {
            return;
        }

        fwrite($this->handle, '</sheetData></worksheet>');
        fclose($this->handle);
        $this->handle = null;
    }

    public function sheetCount(): int
    {
        return count($this->sheets);
    }

    // ─── The archive ───────────────────────────────────────────────────

    /**
     * Build the workbook and return its bytes.
     *
     * Every temporary file is removed before returning, on the success path
     * and on the failure one — an export that throws must not leave a sheet
     * of somebody's payment records in the system temp directory.
     */
    public function output(): string
    {
        $this->closeSheet();

        if (!$this->sheets) {
            throw new RuntimeException('A workbook needs at least one sheet.');
        }

        $path = tempnam(sys_get_temp_dir(), 'xlsxz');
        if ($path === false) {
            throw new RuntimeException('Could not open a temporary file for the workbook archive.');
        }
        $this->temp[] = $path;

        try {
            $zip = new ZipArchive();
            if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('Could not create the workbook archive.');
            }

            $zip->addFromString('[Content_Types].xml', $this->contentTypes());
            $zip->addFromString('_rels/.rels', self::rootRels());
            $zip->addFromString('xl/workbook.xml', $this->workbook());
            $zip->addFromString('xl/_rels/workbook.xml.rels', $this->workbookRels());
            $zip->addFromString('xl/styles.xml', $this->styles());

            foreach ($this->sheets as $i => $sheet) {
                $zip->addFile($sheet['file'], 'xl/worksheets/sheet' . ($i + 1) . '.xml');
            }

            $zip->close();

            $bytes = file_get_contents($path);
            if ($bytes === false) {
                throw new RuntimeException('Could not read the finished workbook.');
            }

            return $bytes;
        } finally {
            $this->cleanup();
        }
    }

    private function cleanup(): void
    {
        foreach ($this->temp as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
        $this->temp = [];
    }

    // ─── Parts ─────────────────────────────────────────────────────────

    private function contentTypes(): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
             . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
             . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
             . '<Default Extension="xml" ContentType="application/xml"/>'
             . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
             . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>';

        foreach ($this->sheets as $i => $_) {
            $xml .= '<Override PartName="/xl/worksheets/sheet' . ($i + 1) . '.xml"'
                  . ' ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        }

        return $xml . '</Types>';
    }

    private static function rootRels(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
             . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
             . '<Relationship Id="rId1"'
             . ' Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument"'
             . ' Target="xl/workbook.xml"/></Relationships>';
    }

    private function workbook(): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
             . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
             . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>';

        foreach ($this->sheets as $i => $sheet) {
            $xml .= '<sheet name="' . self::escape($sheet['name']) . '"'
                  . ' sheetId="' . ($i + 1) . '" r:id="rId' . ($i + 1) . '"/>';
        }

        return $xml . '</sheets></workbook>';
    }

    private function workbookRels(): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
             . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';

        foreach ($this->sheets as $i => $_) {
            $xml .= '<Relationship Id="rId' . ($i + 1) . '"'
                  . ' Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet"'
                  . ' Target="worksheets/sheet' . ($i + 1) . '.xml"/>';
        }

        return $xml . '<Relationship Id="rId' . (count($this->sheets) + 1) . '"'
             . ' Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles"'
             . ' Target="styles.xml"/></Relationships>';
    }

    /**
     * The style table.
     *
     * The currency format is built from the configured symbol rather than
     * hard-coded, so a workbook cannot end up quoting a different currency
     * from the receipt for the same money. Percentages are stored as the
     * numbers the analytics produce — 92.4 rather than 0.924 — and formatted
     * with a literal sign, which keeps the exported figure identical to the
     * one on screen instead of a hundredth of it.
     */
    private function styles(): string
    {
        $symbol = self::escape(str_replace(['"', '\\'], ['', ''], $this->currency));

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<numFmts count="5">'
                . '<numFmt numFmtId="164" formatCode="&quot;' . $symbol . '&quot;#,##0.00"/>'
                . '<numFmt numFmtId="165" formatCode="#,##0"/>'
                . '<numFmt numFmtId="166" formatCode="0.0&quot;%&quot;"/>'
                . '<numFmt numFmtId="167" formatCode="yyyy\-mm\-dd"/>'
                . '<numFmt numFmtId="168" formatCode="#,##0.0"/>'
            . '</numFmts>'
            . '<fonts count="5">'
                . '<font><sz val="11"/><name val="Calibri"/><color rgb="FF10222C"/></font>'
                . '<font><b/><sz val="11"/><name val="Calibri"/><color rgb="FF10222C"/></font>'
                . '<font><b/><sz val="11"/><name val="Calibri"/><color rgb="FFFFFFFF"/></font>'
                . '<font><b/><sz val="16"/><name val="Calibri"/><color rgb="FF10222C"/></font>'
                . '<font><i/><sz val="10"/><name val="Calibri"/><color rgb="FF667680"/></font>'
            . '</fonts>'
            . '<fills count="3">'
                . '<fill><patternFill patternType="none"/></fill>'
                . '<fill><patternFill patternType="gray125"/></fill>'
                . '<fill><patternFill patternType="solid"><fgColor rgb="FF10222C"/>'
                    . '<bgColor indexed="64"/></patternFill></fill>'
            . '</fills>'
            . '<borders count="3">'
                . '<border><left/><right/><top/><bottom/><diagonal/></border>'
                . '<border><left/><right/><top/><bottom style="thin">'
                    . '<color rgb="FFD9DEE2"/></bottom><diagonal/></border>'
                . '<border><left/><right/><top style="thin">'
                    . '<color rgb="FF10222C"/></top><bottom/><diagonal/></border>'
            . '</borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . $this->cellXfs()
            . '</styleSheet>';
    }

    /** One <xf> per name in STYLES, in the same order. */
    private function cellXfs(): string
    {
        $xfs = [
            /* default     */ '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>',
            /* title       */ '<xf numFmtId="0" fontId="3" fillId="0" borderId="0" xfId="0" applyFont="1"/>',
            /* subtitle    */ '<xf numFmtId="0" fontId="4" fillId="0" borderId="0" xfId="0" applyFont="1"/>',
            /* label       */ '<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/>',
            /* head        */ '<xf numFmtId="0" fontId="2" fillId="2" borderId="1" xfId="0" applyFont="1"'
                            . ' applyFill="1" applyBorder="1" applyAlignment="1">'
                            . '<alignment vertical="center" wrapText="1"/></xf>',
            /* text        */ '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0" applyAlignment="1">'
                            . '<alignment vertical="top" wrapText="1"/></xf>',
            /* money       */ '<xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>',
            /* int         */ '<xf numFmtId="165" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>',
            /* percent     */ '<xf numFmtId="166" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>',
            /* date        */ '<xf numFmtId="167" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>',
            /* decimal     */ '<xf numFmtId="168" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>',
            /* total       */ '<xf numFmtId="0" fontId="1" fillId="0" borderId="2" xfId="0"'
                            . ' applyFont="1" applyBorder="1"/>',
            /* total_money */ '<xf numFmtId="164" fontId="1" fillId="0" borderId="2" xfId="0"'
                            . ' applyNumberFormat="1" applyFont="1" applyBorder="1"/>',
            /* total_int   */ '<xf numFmtId="165" fontId="1" fillId="0" borderId="2" xfId="0"'
                            . ' applyNumberFormat="1" applyFont="1" applyBorder="1"/>',
            /* section     */ '<xf numFmtId="0" fontId="1" fillId="0" borderId="1" xfId="0"'
                            . ' applyFont="1" applyBorder="1"/>',
        ];

        return '<cellXfs count="' . count($xfs) . '">' . implode('', $xfs) . '</cellXfs>';
    }

    // ─── Small helpers ─────────────────────────────────────────────────

    private static function styleIndex(string $name): int
    {
        $index = array_search($name, self::STYLES, true);

        return $index === false ? 0 : (int) $index;
    }

    /** 1 → A, 27 → AA. */
    public static function columnLetter(int $index): string
    {
        $letters = '';
        while ($index > 0) {
            $index--;
            $letters = chr(65 + $index % 26) . $letters;
            $index   = intdiv($index, 26);
        }

        return $letters === '' ? 'A' : $letters;
    }

    /**
     * A Y-m-d date as an Excel day serial, or null when it cannot be read.
     *
     * Anchored at UTC so the serial is the calendar day the database holds
     * rather than the day it happened to be in the server's timezone when
     * the export ran.
     */
    private static function dateSerial(string $date): ?float
    {
        $stamp = strtotime(substr($date, 0, 10) . ' 00:00:00 UTC');
        if ($stamp === false) {
            return null;
        }

        // 25569 is 1970-01-01 in Excel's 1900 date system.
        return $stamp / 86400 + 25569;
    }

    /**
     * A sheet name Excel will accept.
     *
     * 31 characters, and none of the six the format reserves. A name that
     * empties out falls back to its position, so a sheet can never be
     * nameless — Excel refuses to open a workbook that contains one.
     */
    private static function sheetName(string $name, int $position): string
    {
        $clean = trim(str_replace(['\\', '/', '?', '*', '[', ']', ':'], ' ', $name));
        $clean = preg_replace('/\s+/', ' ', $clean) ?? '';

        if ($clean === '') {
            return 'Sheet' . $position;
        }

        return substr($clean, 0, 31);
    }

    /**
     * XML text, with the control characters the format forbids removed.
     *
     * A stray 0x0C in a property description is enough for Excel to declare
     * the whole workbook corrupt and offer to repair it, which is a worse
     * outcome than losing one invisible character.
     */
    private static function escape(string $value): string
    {
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $value) ?? $value;

        return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}
