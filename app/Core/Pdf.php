<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Minimal PDF 1.4 generator: no FPDF, no Composer.
 *
 * Scope is deliberately narrow - what the report exports and the visit report
 * printout need:
 *   - A4 portrait or landscape
 *   - Helvetica core fonts (no embedding, so files stay small)
 *   - a branded header band, page numbers, repeated table headers
 *   - automatic column fitting, text truncation and page breaks
 *   - key/value detail blocks
 *
 * WinAnsi (cp1252) is the text encoding. Characters outside it (for example
 * Devanagari names) are transliterated or replaced rather than emitting broken
 * glyphs, and the rupee sign is written as "Rs." for the same reason.
 */
final class Pdf
{
    public const MIME = 'application/pdf';

    private const A4_WIDTH  = 595.28;
    private const A4_HEIGHT = 841.89;

    private bool $landscape;
    private float $pageWidth;
    private float $pageHeight;
    private float $marginX = 32.0;
    private float $marginTop = 34.0;
    private float $marginBottom = 44.0;

    private float $y;
    private int $pageNumber = 0;

    /** @var list<string> Content streams, one per page. */
    private array $pages = [];
    private string $buffer = '';

    private string $title;
    private string $subtitle;
    private string $footerNote;

    /** @var list<array{label:string,width:float,align:string}> */
    private array $columns = [];

    public function __construct(
        string $title,
        string $subtitle = '',
        bool $landscape = false,
        string $footerNote = ''
    ) {
        $this->title = self::text($title);
        $this->subtitle = self::text($subtitle);
        $this->footerNote = self::text($footerNote);
        $this->landscape = $landscape;

        $this->pageWidth  = $landscape ? self::A4_HEIGHT : self::A4_WIDTH;
        $this->pageHeight = $landscape ? self::A4_WIDTH : self::A4_HEIGHT;

        $this->y = 0.0;
        $this->addPage();
    }

    // -----------------------------------------------------------------------
    // Public drawing API
    // -----------------------------------------------------------------------

    /**
     * Defines the table columns. Widths are relative weights, scaled to fit
     * the printable width.
     *
     * @param list<array{label:string,width?:float,align?:string}> $columns
     */
    public function setColumns(array $columns): void
    {
        $totalWeight = 0.0;
        foreach ($columns as $column) {
            $totalWeight += (float) ($column['width'] ?? 1.0);
        }
        if ($totalWeight <= 0) {
            $totalWeight = max(1, count($columns));
        }

        $available = $this->contentWidth();
        $this->columns = [];

        foreach ($columns as $column) {
            $weight = (float) ($column['width'] ?? 1.0);
            $this->columns[] = [
                'label' => self::text((string) $column['label']),
                'width' => $available * ($weight / $totalWeight),
                'align' => (string) ($column['align'] ?? 'left'),
            ];
        }
    }

    public function tableHeader(): void
    {
        if ($this->columns === []) {
            return;
        }

        $this->ensureSpace(24.0);

        $height = 19.0;
        $top = $this->y - $height;

        // Header band in the primary blue.
        $this->rect($this->marginX, $top, $this->contentWidth(), $height, '#1957c2', true);

        $x = $this->marginX;
        foreach ($this->columns as $column) {
            $this->textAt(
                self::fit($column['label'], $column['width'] - 8.0, 8.2, true),
                $x + 4.0,
                $top + 5.6,
                8.2,
                true,
                '#ffffff',
                $column['width'] - 8.0,
                $column['align']
            );
            $x += $column['width'];
        }

        $this->y = $top;
    }

    /**
     * @param list<string|int|float|null> $cells
     */
    public function row(array $cells, bool $bold = false, ?string $fillHex = null): void
    {
        if ($this->columns === []) {
            return;
        }

        $fontSize = 8.0;
        $height = 16.0;

        // Repeat the header when the row would overflow the page.
        if ($this->y - $height < $this->marginBottom) {
            $this->addPage();
            $this->tableHeader();
        }

        $top = $this->y - $height;

        if ($fillHex !== null) {
            $this->rect($this->marginX, $top, $this->contentWidth(), $height, $fillHex, true);
        }

        // Row separator
        $this->line($this->marginX, $top, $this->marginX + $this->contentWidth(), $top, '#e2e5ea', 0.4);

        $x = $this->marginX;
        foreach ($this->columns as $index => $column) {
            $value = $cells[$index] ?? '';
            $rendered = self::text(is_float($value) || is_int($value) ? self::number($value) : (string) $value);
            $this->textAt(
                self::fit($rendered, $column['width'] - 8.0, $fontSize, $bold),
                $x + 4.0,
                $top + 4.6,
                $fontSize,
                $bold,
                '#1c2128',
                $column['width'] - 8.0,
                $column['align']
            );
            $x += $column['width'];
        }

        $this->y = $top;
    }

    /** Bold, tinted totals row. */
    public function totalRow(array $cells): void
    {
        $this->row($cells, true, '#e8f0fd');
    }

    public function heading(string $text, float $size = 11.0): void
    {
        $this->ensureSpace(28.0);
        $this->y -= 18.0;
        $this->textAt(self::text($text), $this->marginX, $this->y, $size, true, '#123f8f');
        $this->y -= 4.0;
    }

    public function paragraph(string $text, float $size = 9.0, string $colorHex = '#4b5563'): void
    {
        $lines = self::wrap(self::text($text), $this->contentWidth(), $size, false);
        foreach ($lines as $line) {
            $this->ensureSpace(14.0);
            $this->y -= 12.0;
            $this->textAt($line, $this->marginX, $this->y, $size, false, $colorHex);
        }
        $this->y -= 3.0;
    }

    /**
     * Two-column key/value block used by the visit report printout.
     *
     * @param array<string,string|int|float|null> $pairs
     */
    public function keyValueBlock(array $pairs, int $columnsPerRow = 2): void
    {
        $entries = [];
        foreach ($pairs as $label => $value) {
            $entries[] = [self::text((string) $label), self::text($value === null || $value === '' ? '-' : (string) $value)];
        }

        $cellWidth = $this->contentWidth() / max(1, $columnsPerRow);
        $chunks = array_chunk($entries, $columnsPerRow);

        foreach ($chunks as $chunk) {
            $this->ensureSpace(26.0);
            $rowHeight = 22.0;
            $top = $this->y - $rowHeight;

            $x = $this->marginX;
            foreach ($chunk as [$label, $value]) {
                $this->textAt(
                    self::fit($label, $cellWidth - 10.0, 7.2, false),
                    $x + 2.0,
                    $top + 12.0,
                    7.2,
                    false,
                    '#4b5563'
                );
                $this->textAt(
                    self::fit($value, $cellWidth - 10.0, 9.0, true),
                    $x + 2.0,
                    $top + 1.5,
                    9.0,
                    true,
                    '#1c2128'
                );
                $x += $cellWidth;
            }

            $this->line($this->marginX, $top, $this->marginX + $this->contentWidth(), $top, '#eef1f5', 0.4);
            $this->y = $top;
        }

        $this->y -= 4.0;
    }

    public function spacer(float $height = 10.0): void
    {
        $this->y -= $height;
    }

    /** Renders the final document. */
    public function output(): string
    {
        $this->flushPage();
        return $this->assemble();
    }

    // -----------------------------------------------------------------------
    // Page management
    // -----------------------------------------------------------------------

    private function addPage(): void
    {
        if ($this->pageNumber > 0) {
            $this->flushPage();
        }

        $this->pageNumber++;
        $this->buffer = '';
        $this->y = $this->pageHeight - $this->marginTop;

        $this->drawHeaderBand();
        $this->drawFooter();
    }

    private function ensureSpace(float $needed): void
    {
        if ($this->y - $needed < $this->marginBottom) {
            $this->addPage();
        }
    }

    private function drawHeaderBand(): void
    {
        $bank = self::text((string) Settings::get('bank_name', ''));
        $appName = self::text((string) Settings::get('app_name', 'LRMS'));

        // Thin brand rule across the top.
        $this->rect($this->marginX, $this->pageHeight - 26.0, $this->contentWidth(), 2.5, '#1957c2', true);

        $this->textAt($this->title, $this->marginX, $this->pageHeight - 46.0, 13.0, true, '#123f8f');

        $rightLabel = $bank !== '' ? $bank : $appName;
        $this->textAt(
            $rightLabel,
            $this->marginX,
            $this->pageHeight - 46.0,
            9.0,
            true,
            '#4b5563',
            $this->contentWidth(),
            'right'
        );

        $y = $this->pageHeight - 59.0;
        if ($this->subtitle !== '') {
            $this->textAt($this->subtitle, $this->marginX, $y, 8.4, false, '#4b5563');
            $y -= 11.0;
        }

        $this->textAt(
            'Generated ' . date('d M Y, h:i A'),
            $this->marginX,
            $this->pageHeight - 59.0,
            8.0,
            false,
            '#6b7280',
            $this->contentWidth(),
            'right'
        );

        $this->y = $y - 8.0;
    }

    private function drawFooter(): void
    {
        $footerY = 24.0;

        $this->line($this->marginX, $footerY + 13.0, $this->marginX + $this->contentWidth(), $footerY + 13.0, '#e2e5ea', 0.5);

        $left = $this->footerNote !== ''
            ? $this->footerNote
            : 'LRMS - Loan Recovery Management System';

        $this->textAt($left, $this->marginX, $footerY, 7.6, false, '#6b7280');
        $this->textAt(
            'Page ' . $this->pageNumber,
            $this->marginX,
            $footerY,
            7.6,
            false,
            '#6b7280',
            $this->contentWidth(),
            'right'
        );
    }

    private function flushPage(): void
    {
        if ($this->buffer !== '') {
            $this->pages[] = $this->buffer;
            $this->buffer = '';
        } elseif ($this->pageNumber > count($this->pages)) {
            $this->pages[] = '';
        }
    }

    private function contentWidth(): float
    {
        return $this->pageWidth - (2 * $this->marginX);
    }

    // -----------------------------------------------------------------------
    // Primitives
    // -----------------------------------------------------------------------

    private function textAt(
        string $text,
        float $x,
        float $y,
        float $size,
        bool $bold = false,
        string $colorHex = '#1c2128',
        ?float $boxWidth = null,
        string $align = 'left'
    ): void {
        if ($text === '') {
            return;
        }

        if ($boxWidth !== null && $align !== 'left') {
            $textWidth = self::stringWidth($text, $size, $bold);
            if ($align === 'right') {
                $x += $boxWidth - $textWidth;
            } elseif ($align === 'center') {
                $x += ($boxWidth - $textWidth) / 2;
            }
        }

        [$r, $g, $b] = self::hexToRgb($colorHex);
        $font = $bold ? '/F2' : '/F1';

        $this->buffer .= sprintf(
            "BT %s %.2F Tf %.3F %.3F %.3F rg %.2F %.2F Td (%s) Tj ET\n",
            $font,
            $size,
            $r,
            $g,
            $b,
            $x,
            $y,
            self::escape($text)
        );
    }

    private function rect(float $x, float $y, float $width, float $height, string $colorHex, bool $filled): void
    {
        [$r, $g, $b] = self::hexToRgb($colorHex);
        $this->buffer .= sprintf(
            "%.3F %.3F %.3F %s %.2F %.2F %.2F %.2F re %s\n",
            $r,
            $g,
            $b,
            $filled ? 'rg' : 'RG',
            $x,
            $y,
            $width,
            $height,
            $filled ? 'f' : 'S'
        );
    }

    private function line(float $x1, float $y1, float $x2, float $y2, string $colorHex, float $width): void
    {
        [$r, $g, $b] = self::hexToRgb($colorHex);
        $this->buffer .= sprintf(
            "%.3F %.3F %.3F RG %.2F w %.2F %.2F m %.2F %.2F l S\n",
            $r,
            $g,
            $b,
            $width,
            $x1,
            $y1,
            $x2,
            $y2
        );
    }

    // -----------------------------------------------------------------------
    // Document assembly
    // -----------------------------------------------------------------------

    private function assemble(): string
    {
        $objects = [];

        $pageCount = count($this->pages);
        $firstPageObject = 4; // 1 catalog, 2 pages, 3 font F1, then F2, then pages

        // Object numbering:
        //   1 Catalog
        //   2 Pages
        //   3 Font Helvetica
        //   4 Font Helvetica-Bold
        //   5..            Page objects
        //   5+pageCount..  Content streams
        $pageObjectStart = 5;
        $contentObjectStart = $pageObjectStart + $pageCount;

        $kids = [];
        for ($i = 0; $i < $pageCount; $i++) {
            $kids[] = ($pageObjectStart + $i) . ' 0 R';
        }

        $objects[1] = "<< /Type /Catalog /Pages 2 0 R >>";
        $objects[2] = sprintf(
            "<< /Type /Pages /Kids [%s] /Count %d >>",
            implode(' ', $kids),
            $pageCount
        );
        $objects[3] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>";
        $objects[4] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>";

        for ($i = 0; $i < $pageCount; $i++) {
            $objects[$pageObjectStart + $i] = sprintf(
                "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %.2F %.2F] "
                . "/Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents %d 0 R >>",
                $this->pageWidth,
                $this->pageHeight,
                $contentObjectStart + $i
            );
        }

        for ($i = 0; $i < $pageCount; $i++) {
            $stream = $this->pages[$i];
            $objects[$contentObjectStart + $i] = sprintf(
                "<< /Length %d >>\nstream\n%s\nendstream",
                strlen($stream),
                $stream
            );
        }

        ksort($objects);

        $pdf = "%PDF-1.4\n";
        $offsets = [];

        foreach ($objects as $number => $body) {
            $offsets[$number] = strlen($pdf);
            $pdf .= $number . " 0 obj\n" . $body . "\nendobj\n";
        }

        $xrefOffset = strlen($pdf);
        $maxObject = max(array_keys($objects));

        $pdf .= "xref\n0 " . ($maxObject + 1) . "\n";
        $pdf .= "0000000000 65535 f \n";
        for ($i = 1; $i <= $maxObject; $i++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$i] ?? 0);
        }

        $pdf .= "trailer\n<< /Size " . ($maxObject + 1) . " /Root 1 0 R >>\n";
        $pdf .= "startxref\n" . $xrefOffset . "\n%%EOF";

        return $pdf;
    }

    // -----------------------------------------------------------------------
    // Text metrics and encoding
    // -----------------------------------------------------------------------

    /**
     * Helvetica advance widths (per 1000 units) for the printable ASCII range.
     * Enough to measure text accurately for fitting and alignment.
     *
     * @var array<int,int>|null
     */
    private static ?array $widths = null;

    private static function widthTable(): array
    {
        if (self::$widths !== null) {
            return self::$widths;
        }

        // Widths for chars 32..126 in Helvetica.
        $w = [
            278, 278, 355, 556, 556, 889, 667, 191, 333, 333, 389, 584, 278, 333, 278, 278,
            556, 556, 556, 556, 556, 556, 556, 556, 556, 556, 278, 278, 584, 584, 584, 556,
            1015, 667, 667, 722, 722, 667, 611, 778, 722, 278, 500, 667, 556, 833, 722, 778,
            667, 778, 722, 667, 611, 722, 667, 944, 667, 667, 611, 278, 278, 278, 469, 556,
            333, 556, 556, 500, 556, 556, 278, 556, 556, 222, 222, 500, 222, 833, 556, 556,
            556, 556, 333, 500, 278, 556, 500, 722, 500, 500, 500, 334, 260, 334, 584,
        ];

        $table = [];
        foreach ($w as $index => $width) {
            $table[32 + $index] = $width;
        }
        return self::$widths = $table;
    }

    /** Width of a WinAnsi string at the given point size. */
    public static function stringWidth(string $text, float $size, bool $bold = false): float
    {
        $table = self::widthTable();
        $total = 0;
        $length = strlen($text);

        for ($i = 0; $i < $length; $i++) {
            $code = ord($text[$i]);
            $total += $table[$code] ?? 556;
        }

        // Helvetica-Bold is marginally wider; approximate rather than ship a
        // second full metrics table, which is plenty for layout purposes.
        $factor = $bold ? 1.045 : 1.0;

        return ($total / 1000.0) * $size * $factor;
    }

    /** Truncates with an ellipsis so a long value can never overflow its cell. */
    public static function fit(string $text, float $maxWidth, float $size, bool $bold): string
    {
        if ($maxWidth <= 0 || $text === '') {
            return '';
        }
        if (self::stringWidth($text, $size, $bold) <= $maxWidth) {
            return $text;
        }

        $ellipsis = '...';
        $budget = $maxWidth - self::stringWidth($ellipsis, $size, $bold);
        if ($budget <= 0) {
            return '';
        }

        $result = '';
        $length = strlen($text);
        for ($i = 0; $i < $length; $i++) {
            $candidate = $result . $text[$i];
            if (self::stringWidth($candidate, $size, $bold) > $budget) {
                break;
            }
            $result = $candidate;
        }

        return rtrim($result) . $ellipsis;
    }

    /** @return list<string> */
    public static function wrap(string $text, float $maxWidth, float $size, bool $bold): array
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $lines = [];

        foreach (explode("\n", $text) as $paragraph) {
            $words = preg_split('/\s+/', trim($paragraph)) ?: [];
            $current = '';

            foreach ($words as $word) {
                $candidate = $current === '' ? $word : $current . ' ' . $word;
                if (self::stringWidth($candidate, $size, $bold) <= $maxWidth) {
                    $current = $candidate;
                    continue;
                }
                if ($current !== '') {
                    $lines[] = $current;
                }
                // A single word longer than the line gets hard-split.
                while (self::stringWidth($word, $size, $bold) > $maxWidth && $word !== '') {
                    $chunk = '';
                    while ($word !== '' && self::stringWidth($chunk . $word[0], $size, $bold) <= $maxWidth) {
                        $chunk .= $word[0];
                        $word = substr($word, 1);
                    }
                    if ($chunk === '') {
                        break;
                    }
                    $lines[] = $chunk;
                }
                $current = $word;
            }

            $lines[] = $current;
        }

        return array_values(array_filter($lines, static fn (string $l): bool => $l !== '' || count($lines) === 1));
    }

    /**
     * Converts UTF-8 to WinAnsi, replacing what cannot be represented.
     */
    public static function text(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        $string = (string) $value;
        if ($string === '') {
            return '';
        }

        // Common symbols that would otherwise become '?'.
        $string = str_replace(
            ['₹', '—', '–', '“', '”', '‘', '’', '…', '•', '→', '≥', '≤', '₨'],
            ['Rs.', '-', '-', '"', '"', "'", "'", '...', '-', '->', '>=', '<=', 'Rs.'],
            $string
        );

        $converted = @iconv('UTF-8', 'CP1252//TRANSLIT', $string);
        if ($converted === false) {
            $converted = @iconv('UTF-8', 'ASCII//TRANSLIT', $string);
        }
        if ($converted === false) {
            $converted = preg_replace('/[^\x20-\x7E]/', '?', $string) ?? '';
        }

        // Drop control characters and stray transliteration artefacts.
        return preg_replace('/[\x00-\x1F\x7F]/', '', $converted) ?? '';
    }

    private static function escape(string $text): string
    {
        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text);
    }

    private static function number(int|float $value): string
    {
        if (is_int($value)) {
            return number_format($value, 0, '.', ',');
        }
        return number_format($value, 2, '.', ',');
    }

    /** @return array{0:float,1:float,2:float} */
    private static function hexToRgb(string $hex): array
    {
        $hex = ltrim($hex, '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        if (strlen($hex) !== 6) {
            return [0.0, 0.0, 0.0];
        }
        return [
            hexdec(substr($hex, 0, 2)) / 255,
            hexdec(substr($hex, 2, 2)) / 255,
            hexdec(substr($hex, 4, 2)) / 255,
        ];
    }
}
