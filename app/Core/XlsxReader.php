<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Reads .xlsx / .xls(x-as-zip) / .csv lead files without PhpSpreadsheet.
 *
 * XLSX handling:
 *   - opens the package with ZipArchive
 *   - resolves the first sheet through xl/workbook.xml + rels (not a hardcoded
 *     sheet1.xml, which is wrong for files exported by some core banking systems)
 *   - loads xl/sharedStrings.xml for shared string cells
 *   - honours the cell reference (r="C7") so blank cells do not shift columns,
 *     which is the classic cause of silently mis-imported data
 *   - converts Excel serial dates to Y-m-d
 *
 * Legacy binary .xls (BIFF) is not supported; the UI tells the user to re-save
 * as .xlsx or .csv rather than importing garbage.
 */
final class XlsxReader
{
    /** Rows are capped to protect shared hosting memory limits. */
    private const MAX_ROWS = 60000;

    /**
     * How far down to look for the header row. Bank exports put a title, a
     * "branch / as on" line, a blank spacer and occasionally a legend above it.
     */
    private const HEADER_SEARCH_ROWS = 15;

    /**
     * @return array{headings:list<string>, rows:list<list<string>>, header_row:int, sheet:string}
     */
    public static function read(string $path): array
    {
        if (!is_file($path)) {
            throw new \RuntimeException('Uploaded file could not be read.');
        }

        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        if ($extension === 'csv' || $extension === 'txt') {
            return self::readCsv($path);
        }

        if (self::looksLikeZip($path)) {
            return self::readXlsx($path);
        }

        // Some systems export a CSV with an .xls extension.
        if ($extension === 'xls') {
            $sample = (string) file_get_contents($path, false, null, 0, 2048);
            if ($sample !== '' && !str_starts_with($sample, "\xD0\xCF\x11\xE0")) {
                return self::readCsv($path);
            }
            throw new \RuntimeException(
                'Legacy .xls files are not supported. Please re-save the file as .xlsx or .csv and upload again.'
            );
        }

        throw new \RuntimeException('Unsupported file type. Upload .xlsx or .csv.');
    }

    // -----------------------------------------------------------------------
    // CSV
    // -----------------------------------------------------------------------

    /**
     * @return array{headings:list<string>, rows:list<list<string>>, header_row:int, sheet:string}
     */
    private static function readCsv(string $path): array
    {
        $handle = fopen($path, 'r');
        if ($handle === false) {
            throw new \RuntimeException('Unable to open the uploaded file.');
        }

        $delimiter = self::detectDelimiter($path);

        $grid = [];
        $isFirstRecord = true;

        // $escape is passed explicitly: PHP 8.4 deprecates relying on the
        // default, and "" is the standards-correct behaviour for CSV.
        while (($record = fgetcsv($handle, 0, $delimiter, '"', '')) !== false) {
            if ($record === [null]) {
                continue; // blank line
            }

            $values = array_map(
                static fn ($v): string => trim((string) ($v ?? '')),
                $record
            );

            if ($isFirstRecord) {
                // Strip a UTF-8 BOM from the very first cell.
                if (isset($values[0])) {
                    $values[0] = preg_replace('/^\xEF\xBB\xBF/', '', $values[0]) ?? $values[0];
                }
                $isFirstRecord = false;
            }

            $grid[] = $values;

            if (count($grid) > self::MAX_ROWS) {
                break;
            }
        }

        fclose($handle);

        if ($grid === []) {
            return ['headings' => [], 'rows' => [], 'header_row' => 0, 'sheet' => ''];
        }

        // Normalise to a dense rectangle so ragged rows cannot shift columns.
        $width = 0;
        foreach ($grid as $row) {
            $width = max($width, count($row));
        }
        foreach ($grid as $index => $row) {
            $grid[$index] = array_pad($row, $width, '');
        }

        return self::sliceGrid($grid);
    }

    private static function detectDelimiter(string $path): string
    {
        $line = '';
        $handle = fopen($path, 'r');
        if ($handle !== false) {
            $line = (string) fgets($handle, 8192);
            fclose($handle);
        }

        $candidates = [',' => 0, ';' => 0, "\t" => 0, '|' => 0];
        foreach (array_keys($candidates) as $candidate) {
            $candidates[$candidate] = substr_count($line, $candidate);
        }

        arsort($candidates);
        $best = (string) array_key_first($candidates);
        return $candidates[$best] > 0 ? $best : ',';
    }

    // -----------------------------------------------------------------------
    // XLSX
    // -----------------------------------------------------------------------

    /**
     * @return array{headings:list<string>, rows:list<list<string>>, header_row:int, sheet:string}
     */
    private static function readXlsx(string $path): array
    {
        if (!class_exists(\ZipArchive::class)) {
            throw new \RuntimeException(
                'The ZipArchive PHP extension is required to read .xlsx files. Upload a .csv instead.'
            );
        }

        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) {
            throw new \RuntimeException('The uploaded workbook is corrupt or not a valid .xlsx file.');
        }

        try {
            $sharedStrings = self::readSharedStrings($zip);
            $dateStyles = self::readDateStyles($zip);
            $sheets = self::resolveSheetPaths($zip);

            if ($sheets === []) {
                throw new \RuntimeException('The workbook has no worksheet part.');
            }

            // Pick the sheet that actually holds the lead list.
            //
            // Always reading sheet 1 is wrong often enough to matter: bank
            // workbooks lead with a cover sheet, a "Summary" pivot or a legend,
            // and the accounts sit on sheet 2 or 3. That produced an import that
            // failed with "missing required column(s)" against a file that plainly
            // contained them, which is impossible to explain to the person holding
            // the file. Every sheet is scored on how much its best candidate row
            // looks like column headings, and the winner is used.
            $grid = [];
            $sheetName = '';
            $bestScore = -1;

            foreach ($sheets as $name => $partPath) {
                $xml = $zip->getFromName($partPath);
                if ($xml === false) {
                    continue;
                }

                $candidate = self::parseSheet($xml, $sharedStrings, $dateStyles);
                if ($candidate === []) {
                    continue;
                }

                $score = 0;
                $window = min(count($candidate), self::HEADER_SEARCH_ROWS);
                for ($i = 0; $i < $window; $i++) {
                    $score = max($score, ColumnDetector::headerScore($candidate[$i]));
                }
                // A sheet of headings with no rows under them is a legend, not data.
                if (count($candidate) < 2) {
                    $score = 0;
                }

                if ($score > $bestScore) {
                    $bestScore = $score;
                    $grid = $candidate;
                    $sheetName = (string) $name;
                }
            }

            // No sheet looked like a lead list; fall back to the first one that
            // has any content, so an unrecognised file still reaches the mapping
            // screen instead of being rejected here.
            if ($grid === []) {
                foreach ($sheets as $name => $partPath) {
                    $xml = $zip->getFromName($partPath);
                    if ($xml === false) {
                        continue;
                    }
                    $candidate = self::parseSheet($xml, $sharedStrings, $dateStyles);
                    if ($candidate !== []) {
                        $grid = $candidate;
                        $sheetName = (string) $name;
                        break;
                    }
                }
            }
        } finally {
            $zip->close();
        }

        return self::sliceGrid($grid, $sheetName);
    }

    /**
     * Splits a parsed grid into headings and data rows.
     *
     * Shared by the CSV and XLSX paths, which used to carry identical copies of
     * this and so could drift apart.
     *
     * @param list<list<string>> $grid
     *
     * @return array{headings:list<string>, rows:list<list<string>>, header_row:int, sheet:string}
     */
    private static function sliceGrid(array $grid, string $sheet = ''): array
    {
        $empty = ['headings' => [], 'rows' => [], 'header_row' => 0, 'sheet' => $sheet];

        if ($grid === []) {
            return $empty;
        }

        $headerRowIndex = self::detectHeaderRow($grid);
        if ($headerRowIndex === null) {
            return $empty;
        }

        $rows = [];
        foreach ($grid as $index => $row) {
            if ($index <= $headerRowIndex) {
                continue;
            }
            if (implode('', $row) === '') {
                continue;
            }
            $rows[] = $row;
            if (count($rows) >= self::MAX_ROWS) {
                break;
            }
        }

        return [
            'headings'   => $grid[$headerRowIndex],
            'rows'       => $rows,
            // Reported so callers can quote real spreadsheet line numbers. The
            // importer used to say "row 2" for the first data row of every file,
            // which is wrong by however many title rows were skipped - and those
            // numbers are what someone uses to find the bad row in Excel.
            'header_row' => $headerRowIndex,
            'sheet'      => $sheet,
        ];
    }

    /**
     * Locates the real header row.
     *
     * Core banking exports and hand-maintained branch files carry a merged title
     * row ("NPA STATEMENT AS ON 31.03.2024"), often a "Branch: BR001 | As on:
     * 31.03.2024" subtitle row, and sometimes a blank spacer above the actual
     * column names.
     *
     * The old rule - first row with two or more populated cells - handled the
     * title row and nothing else: a two-cell subtitle row satisfies it perfectly
     * and every column then maps to the wrong field. So instead each candidate row
     * is scored on how many of its cells are recognisable column headings, and the
     * best-scoring row wins. The structural rule remains as a fallback for files
     * whose headings we do not recognise at all.
     *
     * @param list<list<string>> $grid
     */
    private static function detectHeaderRow(array $grid): ?int
    {
        $window = min(count($grid), self::HEADER_SEARCH_ROWS);

        $bestRow = null;
        $bestScore = 0;
        for ($i = 0; $i < $window; $i++) {
            $score = ColumnDetector::headerScore($grid[$i]);
            if ($score > $bestScore) {
                $bestScore = $score;
                $bestRow = $i;
            }
        }

        if ($bestRow !== null) {
            return $bestRow;
        }

        // Nothing recognisable: fall back to the first row with two or more
        // populated cells, which at least skips a merged title row.
        for ($i = 0; $i < $window; $i++) {
            $filled = 0;
            foreach ($grid[$i] as $cell) {
                if (trim($cell) !== '') {
                    $filled++;
                }
            }
            if ($filled >= 2) {
                return $i;
            }
        }

        // Single-column file: the first non-empty row.
        foreach ($grid as $index => $row) {
            if (implode('', $row) !== '') {
                return $index;
            }
        }

        return null;
    }

    /**
     * Every worksheet in the workbook, as sheet name => part path, in tab order.
     *
     * Hidden sheets are skipped: they are archives and scratch pads, never the
     * list someone means to import.
     *
     * @return array<string,string>
     */
    private static function resolveSheetPaths(\ZipArchive $zip): array
    {
        $workbook = $zip->getFromName('xl/workbook.xml');
        $rels = $zip->getFromName('xl/_rels/workbook.xml.rels');
        $sheetPaths = [];

        if ($workbook !== false && $rels !== false) {
            $wbXml = @simplexml_load_string($workbook);
            $relXml = @simplexml_load_string($rels);

            if ($wbXml !== false && $relXml !== false) {
                $relMap = [];
                foreach ($relXml->children() as $relationship) {
                    $id = (string) ($relationship['Id'] ?? '');
                    $target = (string) ($relationship['Target'] ?? '');
                    if ($id !== '' && $target !== '') {
                        $relMap[$id] = $target;
                    }
                }

                $wbXml->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
                $sheets = $wbXml->xpath('//m:sheets/m:sheet');

                foreach (is_array($sheets) ? $sheets : [] as $position => $sheet) {
                    if (((string) ($sheet['state'] ?? '')) !== '') {
                        continue; // hidden or veryHidden
                    }

                    $rid = '';
                    foreach ($sheet->attributes('r', true) ?? [] as $name => $value) {
                        if ($name === 'id') {
                            $rid = (string) $value;
                        }
                    }
                    if ($rid === '' || !isset($relMap[$rid])) {
                        continue;
                    }

                    $target = ltrim($relMap[$rid], '/');
                    $candidate = str_starts_with($target, 'xl/') ? $target : 'xl/' . $target;
                    if ($zip->locateName($candidate) === false) {
                        continue;
                    }

                    $label = trim((string) ($sheet['name'] ?? ''));
                    $sheetPaths[$label !== '' ? $label : 'Sheet' . ($position + 1)] = $candidate;
                }
            }
        }

        if ($sheetPaths !== []) {
            return $sheetPaths;
        }

        // Fall back to scanning for worksheet parts.
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            if (preg_match('#^xl/worksheets/sheet[^/]+\.xml$#', $name) === 1) {
                $sheetPaths[basename($name, '.xml')] = $name;
            }
        }

        return $sheetPaths;
    }

    /** @return list<string> */
    private static function readSharedStrings(\ZipArchive $zip): array
    {
        $xml = $zip->getFromName('xl/sharedStrings.xml');
        if ($xml === false) {
            return [];
        }

        $parsed = @simplexml_load_string($xml);
        if ($parsed === false) {
            return [];
        }

        $strings = [];
        foreach ($parsed->children() as $si) {
            // A shared string can be a single <t> or a set of rich-text <r><t> runs.
            $text = '';
            if (isset($si->t)) {
                $text = (string) $si->t;
            } else {
                foreach ($si->r ?? [] as $run) {
                    $text .= (string) ($run->t ?? '');
                }
            }
            $strings[] = $text;
        }

        return $strings;
    }

    /**
     * @param list<string> $sharedStrings
     * @return list<list<string>> Dense grid, blanks preserved.
     */
    private static function parseSheet(string $xml, array $sharedStrings, array $dateStyles = []): array
    {
        $parsed = @simplexml_load_string($xml);
        if ($parsed === false) {
            throw new \RuntimeException('The worksheet XML could not be parsed.');
        }

        $rowsAssoc = [];
        $maxColumn = 0;

        foreach ($parsed->sheetData->row ?? [] as $row) {
            $rowNumber = (int) ($row['r'] ?? 0);
            if ($rowNumber === 0) {
                $rowNumber = count($rowsAssoc) + 1;
            }

            $cells = [];
            $fallbackColumn = 0;

            foreach ($row->c ?? [] as $cell) {
                $reference = (string) ($cell['r'] ?? '');
                $columnIndex = $reference !== ''
                    ? self::columnIndexFromReference($reference)
                    : ++$fallbackColumn;
                $fallbackColumn = $columnIndex;

                $cells[$columnIndex] = self::cellValue($cell, $sharedStrings, $dateStyles);
                $maxColumn = max($maxColumn, $columnIndex);
            }

            $rowsAssoc[$rowNumber] = $cells;
        }

        if ($rowsAssoc === []) {
            return [];
        }

        ksort($rowsAssoc);

        $grid = [];
        foreach ($rowsAssoc as $cells) {
            $dense = [];
            for ($c = 1; $c <= $maxColumn; $c++) {
                $dense[] = $cells[$c] ?? '';
            }
            $grid[] = $dense;
        }

        return $grid;
    }

    private static function cellValue(
        \SimpleXMLElement $cell,
        array $sharedStrings,
        array $dateStyles = [],
    ): string {
        $type = (string) ($cell['t'] ?? '');

        if ($type === 'inlineStr') {
            $text = '';
            if (isset($cell->is->t)) {
                $text = (string) $cell->is->t;
            } else {
                foreach ($cell->is->r ?? [] as $run) {
                    $text .= (string) ($run->t ?? '');
                }
            }
            return trim($text);
        }

        if ($type === 's') {
            $index = (int) ($cell->v ?? -1);
            return trim($sharedStrings[$index] ?? '');
        }

        if ($type === 'str') {
            return trim((string) ($cell->v ?? ''));
        }

        if ($type === 'b') {
            return ((string) ($cell->v ?? '0')) === '1' ? '1' : '0';
        }

        if (!isset($cell->v)) {
            return '';
        }

        $raw = trim((string) $cell->v);
        if ($raw === '') {
            return '';
        }

        // A date in a spreadsheet is a plain number wearing a date number format.
        // The ONLY way to tell 45,000 rupees from 19 March 2023 is to look at the
        // format the cell was given, which is why styles.xml is parsed. Guessing
        // from the value instead - "integers in the Excel epoch window are dates"
        // - silently turned every whole-rupee outstanding balance between 32,874
        // and 65,380 into a date, and parseAmount() then read that date's year as
        // the amount: a Rs 45,000 balance was imported as Rs 2,023.
        if (isset($cell['s']) && ($dateStyles[(int) $cell['s']] ?? false) && is_numeric($raw)) {
            $converted = self::excelSerialToDate((float) $raw);
            if ($converted !== null) {
                return $converted;
            }
        }

        return $raw;
    }

    /**
     * Which cell-format indexes mean "this number is a date".
     *
     * Returns a map of cellXfs index => true. A cell carries s="N" pointing at
     * the Nth <xf> in <cellXfs>; that xf's numFmtId is either one of Excel's
     * built-in formats or a custom one declared in <numFmts>.
     *
     * @return array<int,bool>
     */
    private static function readDateStyles(\ZipArchive $zip): array
    {
        $xmlSource = $zip->getFromName('xl/styles.xml');
        if ($xmlSource === false) {
            return [];
        }

        $xml = @simplexml_load_string($xmlSource);
        if ($xml === false) {
            return [];
        }

        // Excel's built-in date and time formats. 27-36, 50-58 and 71-81 are the
        // locale-specific date formats East Asian and Indian builds emit.
        $dateFormatIds = [
            14, 15, 16, 17, 18, 19, 20, 21, 22, 45, 46, 47,
            ...range(27, 36), ...range(50, 58), ...range(71, 81),
        ];
        $isDateFormat = array_fill_keys($dateFormatIds, true);

        foreach ($xml->numFmts->numFmt ?? [] as $numFmt) {
            $id = (int) ($numFmt['numFmtId'] ?? 0);
            $code = (string) ($numFmt['formatCode'] ?? '');
            if ($id > 0 && self::formatCodeIsDate($code)) {
                $isDateFormat[$id] = true;
            }
        }

        $styles = [];
        $index = 0;
        foreach ($xml->cellXfs->xf ?? [] as $xf) {
            $numFmtId = (int) ($xf['numFmtId'] ?? 0);
            if ($isDateFormat[$numFmtId] ?? false) {
                $styles[$index] = true;
            }
            $index++;
        }

        return $styles;
    }

    /**
     * Whether a custom number-format code renders a date or a time.
     *
     * Literal text, escaped characters and bracketed sections are removed first,
     * so a currency format like "Rs."#,##0.00 or [$-4009]#,##0 cannot be mistaken
     * for a date through the letters inside them.
     */
    private static function formatCodeIsDate(string $code): bool
    {
        $stripped = preg_replace('/"[^"]*"/', '', $code) ?? $code;   // "literal text"
        $stripped = preg_replace('/\\\\./', '', $stripped) ?? $stripped; // \escaped char
        $stripped = preg_replace('/\[[^\]]*\]/', '', $stripped) ?? $stripped; // [Red] [$-409] [h]

        // y=year, d=day, s=second are unambiguous. A bare "m" is minutes or
        // months, but it only ever appears in a date or time format.
        return preg_match('/[ymdhs]/i', $stripped) === 1;
    }

    private static function columnIndexFromReference(string $reference): int
    {
        if (preg_match('/^([A-Z]+)/i', $reference, $m) !== 1) {
            return 1;
        }
        $letters = strtoupper($m[1]);
        $index = 0;
        $length = strlen($letters);
        for ($i = 0; $i < $length; $i++) {
            $index = $index * 26 + (ord($letters[$i]) - 64);
        }
        return max(1, $index);
    }

    public static function excelSerialToDate(float $serial): ?string
    {
        if ($serial <= 0) {
            return null;
        }
        // Excel epoch is 1899-12-30 because of the fictional 1900 leap year.
        $timestamp = (int) round(($serial - 25569) * 86400);
        $date = gmdate('Y-m-d', $timestamp);
        return $date === false ? null : $date;
    }

    private static function looksLikeZip(string $path): bool
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return false;
        }
        $magic = (string) fread($handle, 4);
        fclose($handle);
        return str_starts_with($magic, "PK\x03\x04") || str_starts_with($magic, "PK\x05\x06");
    }
}
