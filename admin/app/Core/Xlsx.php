<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Minimal XLSX (SpreadsheetML) writer built on ZipArchive + hand-written XML.
 * No PhpSpreadsheet, no Composer.
 *
 * Supports what the 8 report exports actually need:
 *   - a title block and a header row with styling
 *   - inline strings, numbers and dates
 *   - frozen header row, auto-filter, column widths
 *   - a bold totals row
 *
 * If ZipArchive is unavailable on the host, callers fall back to CSV via
 * Xlsx::csv(), which Excel opens natively.
 */
final class Xlsx
{
    public const MIME = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

    /** Style indexes registered in styles.xml, in order. */
    private const STYLE_DEFAULT  = 0;
    private const STYLE_TITLE    = 1;
    private const STYLE_SUBTITLE = 2;
    private const STYLE_HEADER   = 3;
    private const STYLE_TEXT     = 4;
    private const STYLE_NUMBER   = 5;
    private const STYLE_TOTAL    = 6;
    private const STYLE_TOTAL_NUM = 7;

    public static function available(): bool
    {
        return class_exists(\ZipArchive::class);
    }

    /**
     * Builds a single-sheet workbook.
     *
     * @param list<string>                 $headings
     * @param list<list<string|int|float|null>> $rows
     * @param list<string|int|float|null>|null  $totals Optional bold footer row.
     */
    public static function build(
        string $sheetName,
        array $headings,
        array $rows,
        string $title = '',
        string $subtitle = '',
        ?array $totals = null
    ): string {
        if (!self::available()) {
            throw new \RuntimeException('ZipArchive extension is not available');
        }

        $columnCount = max(1, count($headings));

        $sheetXml = self::sheetXml($headings, $rows, $title, $subtitle, $totals, $columnCount);

        $files = [
            '[Content_Types].xml'                    => self::contentTypesXml(),
            '_rels/.rels'                            => self::rootRelsXml(),
            'xl/workbook.xml'                        => self::workbookXml($sheetName),
            'xl/_rels/workbook.xml.rels'             => self::workbookRelsXml(),
            'xl/styles.xml'                          => self::stylesXml(),
            'xl/worksheets/sheet1.xml'               => $sheetXml,
            'docProps/core.xml'                      => self::corePropsXml($title !== '' ? $title : $sheetName),
            'docProps/app.xml'                       => self::appPropsXml(),
        ];

        return self::zip($files);
    }

    /**
     * CSV fallback with a UTF-8 BOM so Excel renders Indian names correctly.
     *
     * @param list<string>                      $headings
     * @param list<list<string|int|float|null>> $rows
     */
    public static function csv(array $headings, array $rows, ?array $totals = null): string
    {
        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            throw new \RuntimeException('Unable to open temporary stream');
        }

        fwrite($handle, "\xEF\xBB\xBF");
        fputcsv($handle, $headings, ',', '"', '');
        foreach ($rows as $row) {
            fputcsv($handle, array_map(static fn ($v): string => $v === null ? '' : (string) $v, $row), ',', '"', '');
        }
        if ($totals !== null) {
            fputcsv($handle, array_map(static fn ($v): string => $v === null ? '' : (string) $v, $totals), ',', '"', '');
        }

        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }

    // -----------------------------------------------------------------------
    // XML parts
    // -----------------------------------------------------------------------

    /**
     * @param list<string>                      $headings
     * @param list<list<string|int|float|null>> $rows
     * @param list<string|int|float|null>|null  $totals
     */
    private static function sheetXml(
        array $headings,
        array $rows,
        string $title,
        string $subtitle,
        ?array $totals,
        int $columnCount
    ): string {
        $lastCol = self::columnName($columnCount);
        $rowIndex = 1;
        $body = '';

        // Title block
        if ($title !== '') {
            $body .= '<row r="' . $rowIndex . '" ht="22" customHeight="1">'
                . self::cell('A' . $rowIndex, $title, self::STYLE_TITLE)
                . '</row>';
            $rowIndex++;
        }
        if ($subtitle !== '') {
            $body .= '<row r="' . $rowIndex . '">'
                . self::cell('A' . $rowIndex, $subtitle, self::STYLE_SUBTITLE)
                . '</row>';
            $rowIndex++;
        }
        if ($title !== '' || $subtitle !== '') {
            $rowIndex++; // spacer
        }

        $headerRowIndex = $rowIndex;
        $body .= '<row r="' . $rowIndex . '" ht="18" customHeight="1">';
        foreach ($headings as $i => $heading) {
            $body .= self::cell(self::columnName($i + 1) . $rowIndex, $heading, self::STYLE_HEADER);
        }
        $body .= '</row>';
        $rowIndex++;

        foreach ($rows as $row) {
            $body .= '<row r="' . $rowIndex . '">';
            $col = 1;
            foreach ($row as $value) {
                $style = is_int($value) || is_float($value) ? self::STYLE_NUMBER : self::STYLE_TEXT;
                $body .= self::cell(self::columnName($col) . $rowIndex, $value, $style);
                $col++;
            }
            $body .= '</row>';
            $rowIndex++;
        }

        if ($totals !== null) {
            $body .= '<row r="' . $rowIndex . '">';
            $col = 1;
            foreach ($totals as $value) {
                $style = is_int($value) || is_float($value) ? self::STYLE_TOTAL_NUM : self::STYLE_TOTAL;
                $body .= self::cell(self::columnName($col) . $rowIndex, $value, $style);
                $col++;
            }
            $body .= '</row>';
            $rowIndex++;
        }

        $lastRow = max(1, $rowIndex - 1);

        // Column widths sized from the heading text, clamped to something sane.
        $cols = '<cols>';
        foreach ($headings as $i => $heading) {
            $width = min(46, max(12, mb_strlen($heading) + 4));
            $cols .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . $width . '" customWidth="1"/>';
        }
        $cols .= '</cols>';

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<dimension ref="A1:' . $lastCol . $lastRow . '"/>'
            . '<sheetViews><sheetView workbookViewId="0" showGridLines="0">'
            . '<pane ySplit="' . $headerRowIndex . '" topLeftCell="A' . ($headerRowIndex + 1) . '" activePane="bottomLeft" state="frozen"/>'
            . '</sheetView></sheetViews>'
            . '<sheetFormatPr defaultRowHeight="15"/>'
            . $cols
            . '<sheetData>' . $body . '</sheetData>'
            . '<autoFilter ref="A' . $headerRowIndex . ':' . $lastCol . $lastRow . '"/>'
            . '<pageMargins left="0.4" right="0.4" top="0.5" bottom="0.5" header="0.3" footer="0.3"/>'
            . '</worksheet>';
    }

    private static function cell(string $reference, string|int|float|null $value, int $styleIndex): string
    {
        $style = $styleIndex > 0 ? ' s="' . $styleIndex . '"' : '';

        if ($value === null || $value === '') {
            return '<c r="' . $reference . '"' . $style . '/>';
        }

        if (is_int($value) || is_float($value)) {
            return '<c r="' . $reference . '"' . $style . '><v>' . self::numberToString($value) . '</v></c>';
        }

        // Inline strings avoid maintaining a sharedStrings table.
        return '<c r="' . $reference . '"' . $style . ' t="inlineStr"><is><t xml:space="preserve">'
            . self::escape($value) . '</t></is></c>';
    }

    private static function numberToString(int|float $value): string
    {
        if (is_int($value)) {
            return (string) $value;
        }
        if (!is_finite($value)) {
            return '0';
        }
        return rtrim(rtrim(number_format($value, 4, '.', ''), '0'), '.') ?: '0';
    }

    public static function columnName(int $index): string
    {
        $name = '';
        while ($index > 0) {
            $index--;
            $name = chr(65 + ($index % 26)) . $name;
            $index = intdiv($index, 26);
        }
        return $name === '' ? 'A' : $name;
    }

    private static function escape(string $value): string
    {
        // Strip control characters that are illegal in XML 1.0.
        $clean = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $value) ?? $value;
        return htmlspecialchars($clean, ENT_QUOTES | ENT_XML1 | ENT_SUBSTITUTE, 'UTF-8');
    }

    private static function contentTypesXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>'
            . '<Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>'
            . '</Types>';
    }

    private static function rootRelsXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>'
            . '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/>'
            . '</Relationships>';
    }

    private static function workbookXml(string $sheetName): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
            . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets><sheet name="' . self::escape(self::safeSheetName($sheetName)) . '" sheetId="1" r:id="rId1"/></sheets>'
            . '</workbook>';
    }

    private static function workbookRelsXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            . '</Relationships>';
    }

    /** Matches the admin panel palette so exports look like the product. */
    private static function stylesXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<numFmts count="1"><numFmt numFmtId="164" formatCode="#,##0.00"/></numFmts>'
            . '<fonts count="6">'
            . '<font><sz val="11"/><name val="Calibri"/></font>'                                        // 0 default
            . '<font><b/><sz val="15"/><color rgb="FF071D40"/><name val="Calibri"/></font>'             // 1 title
            . '<font><sz val="10"/><color rgb="FF4B5563"/><name val="Calibri"/></font>'                 // 2 subtitle
            . '<font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font>'             // 3 header
            . '<font><sz val="10.5"/><color rgb="FF1C2128"/><name val="Calibri"/></font>'               // 4 body
            . '<font><b/><sz val="10.5"/><color rgb="FF1C2128"/><name val="Calibri"/></font>'           // 5 total
            . '</fonts>'
            . '<fills count="4">'
            . '<fill><patternFill patternType="none"/></fill>'
            . '<fill><patternFill patternType="gray125"/></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FF0B2A5B"/><bgColor indexed="64"/></patternFill></fill>' // 2 header navy
            . '<fill><patternFill patternType="solid"><fgColor rgb="FFE8F0FD"/><bgColor indexed="64"/></patternFill></fill>' // 3 total tint
            . '</fills>'
            . '<borders count="2">'
            . '<border><left/><right/><top/><bottom/><diagonal/></border>'
            . '<border><left style="thin"><color rgb="FFE2E5EA"/></left><right style="thin"><color rgb="FFE2E5EA"/></right>'
            . '<top style="thin"><color rgb="FFE2E5EA"/></top><bottom style="thin"><color rgb="FFE2E5EA"/></bottom><diagonal/></border>'
            . '</borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="8">'
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'                                                                              // 0
            . '<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/>'                                                                // 1 title
            . '<xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1"/>'                                                                // 2 subtitle
            . '<xf numFmtId="0" fontId="3" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1">'
            . '<alignment horizontal="center" vertical="center" wrapText="1"/></xf>'                                                                        // 3 header
            . '<xf numFmtId="0" fontId="4" fillId="0" borderId="1" xfId="0" applyFont="1" applyBorder="1" applyAlignment="1">'
            . '<alignment vertical="center"/></xf>'                                                                                                         // 4 text
            . '<xf numFmtId="164" fontId="4" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyFont="1" applyBorder="1" applyAlignment="1">'
            . '<alignment horizontal="right" vertical="center"/></xf>'                                                                                      // 5 number
            . '<xf numFmtId="0" fontId="5" fillId="3" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1"/>'                                   // 6 total
            . '<xf numFmtId="164" fontId="5" fillId="3" borderId="1" xfId="0" applyNumberFormat="1" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1">'
            . '<alignment horizontal="right"/></xf>'                                                                                                        // 7 total num
            . '</cellXfs>'
            . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            . '</styleSheet>';
    }

    private static function corePropsXml(string $title): string
    {
        $now = gmdate('Y-m-d\TH:i:s\Z');
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" '
            . 'xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" '
            . 'xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
            . '<dc:title>' . self::escape($title) . '</dc:title>'
            . '<dc:creator>D2 Recovery Solutions & Services</dc:creator>'
            . '<cp:lastModifiedBy>D2 Recovery Solutions & Services</cp:lastModifiedBy>'
            . '<dcterms:created xsi:type="dcterms:W3CDTF">' . $now . '</dcterms:created>'
            . '<dcterms:modified xsi:type="dcterms:W3CDTF">' . $now . '</dcterms:modified>'
            . '</cp:coreProperties>';
    }

    private static function appPropsXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties">'
            . '<Application>D2 Recovery Solutions & Services</Application></Properties>';
    }

    /** Excel forbids : \ / ? * [ ] and a 31-character limit. */
    private static function safeSheetName(string $name): string
    {
        $clean = preg_replace('/[:\\\\\\/\\?\\*\\[\\]]/', ' ', $name) ?? $name;
        $clean = trim($clean);
        return mb_substr($clean === '' ? 'Sheet1' : $clean, 0, 31);
    }

    /**
     * @param array<string,string> $files path => contents
     */
    private static function zip(array $files): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'lrms_xlsx_');
        if ($tmp === false) {
            throw new \RuntimeException('Unable to create a temporary file for the workbook');
        }

        $zip = new \ZipArchive();
        if ($zip->open($tmp, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            @unlink($tmp);
            throw new \RuntimeException('Unable to create the workbook archive');
        }

        foreach ($files as $path => $contents) {
            $zip->addFromString($path, $contents);
        }
        $zip->close();

        $binary = (string) file_get_contents($tmp);
        @unlink($tmp);

        return $binary;
    }
}
