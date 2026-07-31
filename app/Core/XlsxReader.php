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
     * @return array{headings:list<string>, rows:list<list<string>>}
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
     * @return array{headings:list<string>, rows:list<list<string>>}
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
            return ['headings' => [], 'rows' => []];
        }

        // Normalise to a dense rectangle so ragged rows cannot shift columns.
        $width = 0;
        foreach ($grid as $row) {
            $width = max($width, count($row));
        }
        foreach ($grid as $index => $row) {
            $grid[$index] = array_pad($row, $width, '');
        }

        $headerRowIndex = self::detectHeaderRow($grid);
        if ($headerRowIndex === null) {
            return ['headings' => [], 'rows' => []];
        }

        $headings = $grid[$headerRowIndex];

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

        return ['headings' => $headings, 'rows' => $rows];
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
     * @return array{headings:list<string>, rows:list<list<string>>}
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
            $sheetPath = self::resolveFirstSheetPath($zip);

            $xml = $zip->getFromName($sheetPath);
            if ($xml === false) {
                throw new \RuntimeException('The workbook has no readable worksheet.');
            }

            $grid = self::parseSheet($xml, $sharedStrings);
        } finally {
            $zip->close();
        }

        if ($grid === []) {
            return ['headings' => [], 'rows' => []];
        }

        $headerRowIndex = self::detectHeaderRow($grid);
        if ($headerRowIndex === null) {
            return ['headings' => [], 'rows' => []];
        }
        $headings = $grid[$headerRowIndex];

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

        return ['headings' => $headings, 'rows' => $rows];
    }

    /**
     * Locates the real header row.
     *
     * Core banking exports and hand-maintained branch files routinely carry a
     * merged title row ("NPA STATEMENT AS ON 31.03.2024") and sometimes a blank
     * spacer above the actual column names. Taking the first non-empty row as
     * the header silently shifts every column in those files, so instead we pick
     * the first row in the leading window that has at least two populated cells
     * - a title row has exactly one.
     *
     * @param list<list<string>> $grid
     */
    private static function detectHeaderRow(array $grid): ?int
    {
        $window = min(count($grid), 15);

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

        // Single-column file: fall back to the first non-empty row.
        foreach ($grid as $index => $row) {
            if (implode('', $row) !== '') {
                return $index;
            }
        }

        return null;
    }

    /**
     * Resolves the first sheet's part name via workbook rels.
     */
    private static function resolveFirstSheetPath(\ZipArchive $zip): string
    {
        $workbook = $zip->getFromName('xl/workbook.xml');
        $rels = $zip->getFromName('xl/_rels/workbook.xml.rels');

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

                if (is_array($sheets) && $sheets !== []) {
                    $first = $sheets[0];
                    $rid = '';
                    foreach ($first->attributes('r', true) ?? [] as $name => $value) {
                        if ($name === 'id') {
                            $rid = (string) $value;
                        }
                    }
                    if ($rid !== '' && isset($relMap[$rid])) {
                        $target = ltrim($relMap[$rid], '/');
                        $candidate = str_starts_with($target, 'xl/') ? $target : 'xl/' . $target;
                        if ($zip->locateName($candidate) !== false) {
                            return $candidate;
                        }
                    }
                }
            }
        }

        // Fall back to scanning for any worksheet part.
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            if (preg_match('#^xl/worksheets/sheet[^/]+\.xml$#', $name) === 1) {
                return $name;
            }
        }

        throw new \RuntimeException('The workbook has no worksheet part.');
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
    private static function parseSheet(string $xml, array $sharedStrings): array
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

                $cells[$columnIndex] = self::cellValue($cell, $sharedStrings);
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

    private static function cellValue(\SimpleXMLElement $cell, array $sharedStrings): string
    {
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

        // Date-formatted numerics: styles with a date numFmt get s="..." but we
        // do not parse styles.xml, so use a heuristic on plausible serial ranges
        // only when the value is an integer in the Excel epoch window.
        if (self::looksLikeExcelDateSerial($raw)) {
            $converted = self::excelSerialToDate((float) $raw);
            if ($converted !== null) {
                return $converted;
            }
        }

        return $raw;
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

    /**
     * Excel serial dates for 1990-01-01 .. 2079-12-31 fall in 32874..65380.
     * Restricting to that band avoids mangling ordinary amounts and account
     * numbers, which are the columns that matter most in this importer.
     */
    private static function looksLikeExcelDateSerial(string $raw): bool
    {
        if (!is_numeric($raw)) {
            return false;
        }
        if (str_contains($raw, '.')) {
            return false; // amounts have decimals; dates here do not
        }
        $value = (int) $raw;
        return $value >= 32874 && $value <= 65380;
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
