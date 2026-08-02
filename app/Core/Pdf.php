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

    /**
     * Embedded images, keyed by the name used in the content stream (Im1, Im2...).
     *
     * @var array<string,array{data:string,width:int,height:int,filter:string,colorspace:string}>
     */
    private array $images = [];

    /** Absolute path (plus mtime) to image name, so the same file embeds once. */
    private array $imageKeys = [];

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
        $this->rect($this->marginX, $top, $this->contentWidth(), $height, '#0b2a5b', true);

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
        $this->textAt(self::text($text), $this->marginX, $this->y, $size, true, '#071d40');
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

    // -----------------------------------------------------------------------
    // Images
    // -----------------------------------------------------------------------

    /**
     * Lays out images side by side, each with an optional label and caption.
     *
     * Used for the two things a printed visit report has to show rather than
     * describe: the agent's own photograph at the door, and each field photograph
     * with the coordinates it was taken at. A report that merely says "Photos: 3" is
     * not evidence of anything.
     *
     * An image that cannot be read is skipped and its cell shows why, rather than
     * aborting the whole report. A missing file is a housekeeping problem; a print
     * button that returns a 500 is a different and worse one.
     *
     * @param list<array{path:string,label?:string,caption?:string}> $items
     */
    public function imageStrip(array $items, float $maxHeight = 96.0, float $gap = 12.0): void
    {
        $items = array_values(array_filter($items, static fn (array $i): bool => ($i['path'] ?? '') !== ''));
        if ($items === []) {
            return;
        }

        $count = count($items);
        $cellWidth = ($this->contentWidth() - ($gap * ($count - 1))) / $count;

        // Measure first so the whole strip moves to the next page together. A
        // caption orphaned under a blank space is worse than a page break.
        $labelSpace = 0.0;
        $captionLines = 0;
        foreach ($items as $item) {
            if (($item['label'] ?? '') !== '') {
                $labelSpace = 12.0;
            }
            if (($item['caption'] ?? '') !== '') {
                $captionLines = max($captionLines, count(self::wrap(self::text($item['caption']), $cellWidth, 7.2, false)));
            }
        }
        $captionSpace = $captionLines * 8.6;

        $this->ensureSpace($labelSpace + $maxHeight + $captionSpace + 10.0);

        $top = $this->y;
        $x = $this->marginX;

        foreach ($items as $item) {
            $cellTop = $top;

            if (($item['label'] ?? '') !== '') {
                $this->textAt(self::text($item['label']), $x, $cellTop - 8.0, 7.6, true, '#4b5563');
                $cellTop -= $labelSpace;
            }

            $image = $this->registerImage((string) $item['path']);

            if ($image === null) {
                $this->rect($x, $cellTop - $maxHeight, $cellWidth, $maxHeight, '#e2e5ea', false);
                $this->textAt(
                    'image unavailable',
                    $x,
                    $cellTop - ($maxHeight / 2),
                    7.4,
                    false,
                    '#9aa1ab',
                    $cellWidth,
                    'center'
                );
            } else {
                // Fit inside the cell without distorting. A stretched photograph is
                // evidence of a place that does not look like that.
                $scale = min($cellWidth / $image['width'], $maxHeight / $image['height'], 1.0);
                $drawWidth = $image['width'] * $scale;
                $drawHeight = $image['height'] * $scale;

                // Scaling up a small image is worse than leaving it small, so scale is
                // capped at 1 and the result is centred in its cell.
                $offsetX = $x + (($cellWidth - $drawWidth) / 2);
                $bottom = $cellTop - $drawHeight;

                $this->rect($x, $cellTop - $maxHeight, $cellWidth, $maxHeight, '#eef0f3', false);
                $this->drawImage($image['name'], $offsetX, $bottom, $drawWidth, $drawHeight);
            }

            if (($item['caption'] ?? '') !== '') {
                $lineY = $cellTop - $maxHeight - 9.0;
                foreach (self::wrap(self::text($item['caption']), $cellWidth, 7.2, false) as $line) {
                    $this->textAt($line, $x, $lineY, 7.2, false, '#6b7280');
                    $lineY -= 8.6;
                }
            }

            $x += $cellWidth + $gap;
        }

        $this->y = $top - $labelSpace - $maxHeight - $captionSpace - 10.0;
    }

    /**
     * Empty ruled boxes to be signed by hand on the printed copy.
     *
     * This replaced a captured signature image. The bank's decision, and a sound one:
     * a finger-drawn scrawl on a 5-inch screen is not a signature anybody would accept
     * across a counter, and it was being collected anyway - so the printout now carries
     * the space and the paper carries the signature.
     *
     * Deliberately drawn even when nothing else on the page needs space, because the
     * whole point is that the box is empty. A form that omits the box when there is
     * "nothing to show" is a form nobody can sign.
     *
     * @param list<array{label?:string,caption?:string}> $items
     * @param int $columns Width the cells to this many columns rather than to the number
     *                     of items. A lone signature stretched across the full page reads
     *                     as a different kind of field from the two half-width boxes above
     *                     it; on a form, boxes that mean the same thing should be the same
     *                     size. 0 means "as many columns as there are items".
     */
    public function signatureBlock(array $items, float $height = 60.0, float $gap = 16.0, int $columns = 0): void
    {
        $items = array_values($items);
        if ($items === []) {
            return;
        }

        $count = max($columns, count($items));
        $cellWidth = ($this->contentWidth() - ($gap * ($count - 1))) / $count;

        $labelSpace = 0.0;
        $captionLines = 0;
        foreach ($items as $item) {
            if (($item['label'] ?? '') !== '') {
                $labelSpace = 12.0;
            }
            if (($item['caption'] ?? '') !== '') {
                $captionLines = max($captionLines, count(self::wrap(self::text($item['caption']), $cellWidth, 7.2, false)));
            }
        }
        $captionSpace = $captionLines * 9.6;

        // Measured and kept together, like imageStrip: a signature line at the top of
        // the next page, with its label left behind on this one, is worse than a break.
        $this->ensureSpace($labelSpace + $height + $captionSpace + 10.0);

        $top = $this->y;
        $x = $this->marginX;

        foreach ($items as $item) {
            $cellTop = $top;

            if (($item['label'] ?? '') !== '') {
                $this->textAt(self::text($item['label']), $x, $cellTop - 8.0, 7.6, true, '#4b5563');
                $cellTop -= $labelSpace;
            }

            // The box is the space to sign in; the darker rule inside its foot is the
            // line to sign ON. Without the rule people sign across the border and the
            // scan clips it.
            $this->rect($x, $cellTop - $height, $cellWidth, $height, '#c8cdd4', false);
            $this->line(
                $x + 8.0,
                $cellTop - $height + 15.0,
                $x + $cellWidth - 8.0,
                $cellTop - $height + 15.0,
                '#8a919b',
                0.6
            );

            if (($item['caption'] ?? '') !== '') {
                $lineY = $cellTop - $height - 10.0;
                foreach (self::wrap(self::text($item['caption']), $cellWidth, 7.2, false) as $line) {
                    $this->textAt($line, $x, $lineY, 7.2, false, '#6b7280');
                    $lineY -= 9.6;
                }
            }

            $x += $cellWidth + $gap;
        }

        $this->y = $top - $labelSpace - $height - $captionSpace - 10.0;
    }

    /** True when at least one of these paths can actually be embedded. */
    public function canEmbed(string $path): bool
    {
        return $this->registerImage($path) !== null;
    }

    /**
     * Decodes an image once and returns the name to reference it by.
     *
     * @return array{name:string,width:int,height:int}|null
     */
    private function registerImage(string $path): ?array
    {
        $key = $path . '|' . (string) @filemtime($path);

        if (isset($this->imageKeys[$key])) {
            $name = $this->imageKeys[$key];

            return [
                'name'   => $name,
                'width'  => $this->images[$name]['width'],
                'height' => $this->images[$name]['height'],
            ];
        }

        $decoded = self::decodeImage($path);
        if ($decoded === null) {
            return null;
        }

        $name = 'Im' . (count($this->images) + 1);
        $this->images[$name] = $decoded;
        $this->imageKeys[$key] = $name;

        return ['name' => $name, 'width' => $decoded['width'], 'height' => $decoded['height']];
    }

    /**
     * Emits the operators that place a registered image.
     *
     * The cm matrix is the image's size and position in one step; PDF draws every
     * image into a 1x1 unit square, so the width and height ARE the scale.
     */
    private function drawImage(string $name, float $x, float $y, float $width, float $height): void
    {
        $this->buffer .= sprintf(
            "q %.2F 0 0 %.2F %.2F %.2F cm /%s Do Q\n",
            $width,
            $height,
            $x,
            $y,
            $name
        );
    }

    /**
     * Reads an image file into something a PDF can carry.
     *
     * Two routes, chosen by what the source is:
     *
     *   A baseline JPEG in RGB or greyscale is passed through untouched with
     *   /DCTDecode. PDF speaks JPEG natively, so this costs no quality and no time -
     *   which matters because field photographs are JPEG and there can be several.
     *
     *   Everything else is re-encoded through GD: PNG, WebP, CMYK JPEG
     *   and progressive JPEG. Progressive is the subtle one - it is still DCT, but
     *   /DCTDecode does not support it and viewers render a grey box, so it has to be
     *   detected rather than assumed safe.
     *
     * @return array{data:string,width:int,height:int,filter:string,colorspace:string}|null
     */
    private static function decodeImage(string $path): ?array
    {
        if ($path === '' || !is_file($path) || !is_readable($path)) {
            return null;
        }

        $info = @getimagesize($path);
        if ($info === false) {
            return null;
        }

        $width = (int) $info[0];
        $height = (int) $info[1];
        if ($width < 1 || $height < 1) {
            return null;
        }

        $type = (int) ($info[2] ?? 0);
        $channels = (int) ($info['channels'] ?? 3);

        if ($type === IMAGETYPE_JPEG && ($channels === 3 || $channels === 1)) {
            $raw = @file_get_contents($path);

            if ($raw !== false && $raw !== '' && !self::isProgressiveJpeg($raw)) {
                return [
                    'data'       => $raw,
                    'width'      => $width,
                    'height'     => $height,
                    'filter'     => 'DCTDecode',
                    'colorspace' => $channels === 1 ? 'DeviceGray' : 'DeviceRGB',
                ];
            }
        }

        // Line art keeps its edges through Flate; photographs are better off as JPEG.
        $lineArt = in_array($type, [IMAGETYPE_PNG, IMAGETYPE_GIF, IMAGETYPE_BMP], true);

        return self::rasterise($path, $width, $height, $lineArt);
    }

    /**
     * Re-encodes anything GD can open.
     *
     * Transparency is flattened onto white, which is not cosmetic: a transparent PNG -
     * a logo, a line drawing, a stamp - is dark ink on nothing, and "nothing" in an RGB
     * buffer is black, so unflattened it prints as a solid black rectangle.
     *
     * @return array{data:string,width:int,height:int,filter:string,colorspace:string}|null
     */
    private static function rasterise(string $path, int $width, int $height, bool $lineArt): ?array
    {
        if (!function_exists('imagecreatefromstring')) {
            return null;
        }

        $blob = @file_get_contents($path);
        if ($blob === false || $blob === '') {
            return null;
        }

        $source = @imagecreatefromstring($blob);
        if ($source === false) {
            return null;
        }

        // A 12 megapixel photograph is 36 MB of raw samples before compression, and a
        // report can hold several. Downscaling to print resolution first keeps a print
        // request from exhausting memory - at A4 these are thumbnails on the page.
        $maxDimension = $lineArt ? 900 : 1200;
        $targetWidth = $width;
        $targetHeight = $height;

        if ($width > $maxDimension || $height > $maxDimension) {
            $scale = $maxDimension / max($width, $height);
            $targetWidth = max(1, (int) round($width * $scale));
            $targetHeight = max(1, (int) round($height * $scale));
        }

        $canvas = @imagecreatetruecolor($targetWidth, $targetHeight);
        if ($canvas === false) {
            imagedestroy($source);

            return null;
        }

        $white = (int) imagecolorallocate($canvas, 255, 255, 255);
        imagefilledrectangle($canvas, 0, 0, $targetWidth, $targetHeight, $white);
        imagecopyresampled($canvas, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);
        imagedestroy($source);

        if (!$lineArt) {
            // Straight back out as baseline JPEG, which /DCTDecode carries natively -
            // far cheaper than walking two million pixels in PHP.
            ob_start();
            $ok = imagejpeg($canvas, null, 86);
            $jpeg = (string) ob_get_clean();
            imagedestroy($canvas);

            if (!$ok || $jpeg === '') {
                return null;
            }

            return [
                'data'       => $jpeg,
                'width'      => $targetWidth,
                'height'     => $targetHeight,
                'filter'     => 'DCTDecode',
                'colorspace' => 'DeviceRGB',
            ];
        }

        $bytes = '';
        for ($y = 0; $y < $targetHeight; $y++) {
            $row = '';
            for ($x = 0; $x < $targetWidth; $x++) {
                $rgb = (int) imagecolorat($canvas, $x, $y);
                $row .= chr(($rgb >> 16) & 0xFF) . chr(($rgb >> 8) & 0xFF) . chr($rgb & 0xFF);
            }
            $bytes .= $row;
        }
        imagedestroy($canvas);

        $compressed = @gzcompress($bytes, 6);
        if ($compressed === false) {
            return null;
        }

        return [
            'data'       => $compressed,
            'width'      => $targetWidth,
            'height'     => $targetHeight,
            'filter'     => 'FlateDecode',
            'colorspace' => 'DeviceRGB',
        ];
    }

    /**
     * Whether a JPEG uses progressive encoding.
     *
     * /DCTDecode handles baseline (SOF0) and extended sequential (SOF1) only.
     * A progressive JPEG (SOF2) embeds without complaint and then renders as a grey
     * rectangle in most viewers, which is the kind of bug that reaches a printed
     * report before anyone notices.
     */
    private static function isProgressiveJpeg(string $raw): bool
    {
        $length = strlen($raw);
        $offset = 2; // skip SOI

        while ($offset + 3 < $length) {
            if ($raw[$offset] !== "\xFF") {
                $offset++;

                continue;
            }

            $marker = ord($raw[$offset + 1]);

            // Standalone markers carry no length.
            if ($marker === 0xD8 || $marker === 0x01 || ($marker >= 0xD0 && $marker <= 0xD9)) {
                $offset += 2;

                continue;
            }

            // SOF2 progressive, and the arithmetic-coded variants PDF also cannot read.
            if (in_array($marker, [0xC2, 0xC6, 0xCA, 0xCE], true)) {
                return true;
            }

            $segmentLength = (ord($raw[$offset + 2]) << 8) | ord($raw[$offset + 3]);
            if ($segmentLength < 2) {
                return false;
            }

            $offset += 2 + $segmentLength;
        }

        return false;
    }

    public function spacer(float $height = 10.0): void
    {
        $this->y -= $height;
    }

    /**
     * The current vertical cursor, in points from the foot of the page.
     *
     * Read-only, and exposed for the tests: how far a block advances the cursor is the
     * difference between a signature box the next section prints on top of and one it
     * does not, and that cannot be asserted from the PDF bytes.
     */
    public function cursorY(): float
    {
        return $this->y;
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
        $appName = self::text((string) Settings::get('app_name', 'D2 Recovery'));

        // Thin brand rule across the top.
        $this->rect($this->marginX, $this->pageHeight - 26.0, $this->contentWidth(), 2.5, '#0b2a5b', true);

        $this->textAt($this->title, $this->marginX, $this->pageHeight - 46.0, 13.0, true, '#071d40');

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
            : 'D2 Recovery - Loan Recovery Management System';

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
        $imageNames = array_keys($this->images);
        $imageCount = count($imageNames);

        // Object numbering:
        //   1 Catalog
        //   2 Pages
        //   3 Font Helvetica
        //   4 Font Helvetica-Bold
        //   5..                       Image XObjects
        //   5+imageCount..            Page objects
        //   5+imageCount+pageCount..  Content streams
        $imageObjectStart = 5;
        $pageObjectStart = $imageObjectStart + $imageCount;
        $contentObjectStart = $pageObjectStart + $pageCount;

        // Every image is listed in every page's resources rather than tracked per
        // page. It costs a few bytes of dictionary and removes the failure mode where
        // an image drawn on page three is only declared on page one - which produces a
        // file that opens fine and shows nothing where the photograph should be.
        $xobjectEntries = [];
        foreach ($imageNames as $index => $name) {
            $xobjectEntries[] = sprintf('/%s %d 0 R', $name, $imageObjectStart + $index);
        }
        $xobjectDict = $xobjectEntries === []
            ? ''
            : ' /XObject << ' . implode(' ', $xobjectEntries) . ' >>';

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

        foreach ($imageNames as $index => $name) {
            $image = $this->images[$name];

            $objects[$imageObjectStart + $index] = sprintf(
                "<< /Type /XObject /Subtype /Image /Width %d /Height %d /ColorSpace /%s "
                . "/BitsPerComponent 8 /Filter /%s /Length %d >>\nstream\n%s\nendstream",
                $image['width'],
                $image['height'],
                $image['colorspace'],
                $image['filter'],
                strlen($image['data']),
                $image['data']
            );
        }

        for ($i = 0; $i < $pageCount; $i++) {
            $objects[$pageObjectStart + $i] = sprintf(
                "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %.2F %.2F] "
                . "/Resources << /Font << /F1 3 0 R /F2 4 0 R >>%s >> /Contents %d 0 R >>",
                $this->pageWidth,
                $this->pageHeight,
                $xobjectDict,
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

        // Drop control characters and stray transliteration artefacts - but NOT the
        // newline, which is the one control character that carries meaning here.
        //
        // It used to be stripped along with the rest, and that quietly broke every
        // multi-line caption on the printed report: wrap() splits on "\n", but this ran
        // first, so "Suresh Yadav\nBC0007\n26.912400, 75.787300" reached it as one
        // unbroken run and printed as "Suresh YadavBC000726.912400, 75.787300". Every
        // test passed, because each phrase was individually present in the bytes.
        // Callers that draw a single line are protected in escape() instead.
        return preg_replace('/[\x00-\x09\x0B-\x1F\x7F]/', '', $converted) ?? '';
    }

    private static function escape(string $text): string
    {
        // A newline is legal inside a PDF literal string and means a newline CHARACTER,
        // not a line break on the page - the text operator draws one line wherever the
        // cursor is. So anything reaching a single-line draw with a newline still in it
        // becomes a space. wrap() has already split the ones that were meant to break.
        $text = str_replace("\n", ' ', $text);

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
