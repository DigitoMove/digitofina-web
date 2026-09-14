<?php

namespace App\Services;

use Illuminate\Support\Str;

class ReportPdfRenderer
{
    private const PAGE_WIDTH = 841.89;

    private const PAGE_HEIGHT = 595.28;

    private const MARGIN = 28.0;

    /** @var array<int, string|null> */
    private array $objects = [];

    private int $regularFontId;

    private int $boldFontId;

    private int $monoFontId;

    private int $monoBoldFontId;

    /** @var array{id: int, width: int, height: int}|null */
    private ?array $logo = null;

    /**
     * @param  array{
     *     title: string,
     *     heading: string,
     *     organization_name: string,
     *     organization_description: string,
     *     logo_path: string|null,
     *     metadata: array<string, string>,
     *     columns: array<int, array{key: string, label: string, numeric: bool}>,
     *     rows: array<int, array<string, string>>
     * }  $document
     */
    public function render(array $document): string
    {
        $this->objects = [];

        $catalogId = $this->reserveObject();
        $pagesId = $this->reserveObject();
        $this->regularFontId = $this->addObject('<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>');
        $this->boldFontId = $this->addObject('<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>');
        $this->monoFontId = $this->addObject('<< /Type /Font /Subtype /Type1 /BaseFont /Courier /Encoding /WinAnsiEncoding >>');
        $this->monoBoldFontId = $this->addObject('<< /Type /Font /Subtype /Type1 /BaseFont /Courier-Bold /Encoding /WinAnsiEncoding >>');
        $this->logo = $this->logoObject($document['logo_path']);

        $pageIds = $this->buildPages($document, $pagesId);
        $this->setObject($pagesId, '<< /Type /Pages /Kids ['.implode(' ', array_map(fn (int $id): string => $id.' 0 R', $pageIds)).'] /Count '.count($pageIds).' >>');
        $this->setObject($catalogId, '<< /Type /Catalog /Pages '.$pagesId.' 0 R >>');

        return $this->assemble($catalogId);
    }

    /**
     * @param  array{
     *     title: string,
     *     heading: string,
     *     organization_name: string,
     *     organization_description: string,
     *     metadata: array<string, string>,
     *     columns: array<int, array{key: string, label: string, numeric: bool}>,
     *     rows: array<int, array<string, string>>
     * }  $document
     * @return array<int, int>
     */
    private function buildPages(array $document, int $pagesId): array
    {
        $pages = [];
        $columns = $this->columnLayout($document['columns']);
        $rows = $document['rows'] ?: [['message' => 'No records found for the selected period.']];
        $pageNumber = 1;
        $content = '';
        $cursorY = $this->drawHeader($content, $document, $pageNumber);
        $this->drawTableHeader($content, $columns, $cursorY);

        foreach ($this->splitLongRows($rows, $columns) as $row) {
            $rowHeight = $this->rowHeight($row, $columns);

            if ($cursorY + $rowHeight > self::PAGE_HEIGHT - 85) {
                $pages[] = $content;
                $pageNumber++;
                $content = '';
                $cursorY = $this->drawHeader($content, $document, $pageNumber);
                $this->drawTableHeader($content, $columns, $cursorY);
            }

            $this->drawTableRow($content, $row, $columns, $cursorY, $rowHeight);
            $cursorY += $rowHeight;
        }

        $pages[] = $content;
        $pageIds = [];
        foreach ($pages as $index => $page) {
            $this->drawFooter($page, $index + 1, count($pages), $document);
            $pageIds[] = $this->addPage($pagesId, $page);
        }

        return $pageIds;
    }

    /**
     * @param  array{
     *     title: string,
     *     heading: string,
     *     organization_name: string,
     *     organization_description: string,
     *     metadata: array<string, string>
     * }  $document
     */
    private function drawHeader(string &$content, array $document, int $pageNumber): float
    {
        $top = 30.0;
        $brandX = self::MARGIN + 8;

        if ($this->logo !== null) {
            $scale = min(150 / max(1, $this->logo['width']), 72 / max(1, $this->logo['height']));
            $logoWidth = $this->logo['width'] * $scale;
            $logoHeight = $this->logo['height'] * $scale;
            $this->image($content, 'Logo', $brandX, $top + 3, $logoWidth, $logoHeight);
        }
        $nameY = $this->logo !== null ? $top + $logoHeight + 18 : $top + 20;
        foreach ($this->wrap($document['organization_name'], 340, 14) as $nameLine) {
            $this->text($content, $nameLine, $brandX, $nameY, 14, 'bold');
            $nameY += 17;
        }

        if ($document['organization_description'] !== '') {
            $descriptionLines = $this->wrap($document['organization_description'], 300, 8);
            foreach (array_slice($descriptionLines, 0, 3) as $lineIndex => $line) {
                $this->text($content, $line, $brandX, $nameY + 8 + ($lineIndex * 10), 8);
            }
        }

        $metaX = 420.0;
        $metaY = $top + 3;
        foreach ($document['metadata'] as $label => $value) {
            $labelLines = $this->wrap($label.':', 135, 9);
            $valueLines = $this->wrap($value, self::PAGE_WIDTH - self::MARGIN - $metaX - 148, 9);
            foreach ($labelLines as $index => $line) {
                $this->text($content, $line, $metaX, $metaY + $index * 11, 9, 'bold');
            }
            foreach ($valueLines as $index => $line) {
                $this->text($content, $line, $metaX + 148, $metaY + $index * 11, 9);
            }
            $metaY += max(count($labelLines), count($valueLines)) * 11 + 6;
        }

        $headingY = max(143, $metaY + 12, $nameY + 50);
        $this->text($content, $document['heading'].($pageNumber > 1 ? ' (continued)' : ''), self::PAGE_WIDTH / 2, $headingY, 13, 'bold', 'center');

        return $headingY + 21;
    }

    /**
     * @param  array<int, array{key: string, label: string, numeric: bool}>  $columns
     * @return array<int, array{key: string, label: string, numeric: bool, x: float, width: float}>
     */
    private function columnLayout(array $columns): array
    {
        $rawWidths = [];

        foreach ($columns as $column) {
            $label = Str::lower($column['label']);
            $rawWidths[] = match (true) {
                Str::contains($label, ['narration', 'description']) => 240.0,
                $label === 'client' => 190.0,
                Str::contains($label, ['number', 'reference']) => 145.0,
                Str::contains($label, ['date', 'updated']) => 90.0,
                Str::contains($label, ['status']) => 76.0,
                $column['numeric'] => 86.0,
                default => 78.0,
            };
        }

        $available = self::PAGE_WIDTH - (self::MARGIN * 2);
        $scale = $available / max(1.0, array_sum($rawWidths));
        $x = self::MARGIN;

        return array_map(function (array $column, float $width) use (&$x, $scale): array {
            $scaledWidth = $width * $scale;
            $layout = [
                'key' => $column['key'],
                'label' => $column['label'],
                'numeric' => $column['numeric'],
                'x' => $x,
                'width' => $scaledWidth,
            ];
            $x += $scaledWidth;

            return $layout;
        }, $columns, $rawWidths);
    }

    /**
     * @param  array<int, array{label: string, x: float, width: float}>  $columns
     */
    private function drawTableHeader(string &$content, array $columns, float &$cursorY): void
    {
        $height = 30.0;

        foreach ($columns as $column) {
            $this->filledRectangle($content, $column['x'], $cursorY, $column['width'], $height, [0.90, 0.91, 0.93]);
            $this->rectangle($content, $column['x'], $cursorY, $column['width'], $height);
            $lines = array_slice($this->wrap(Str::upper($column['label']), $column['width'] - 8, 7.0), 0, 2);
            $lineY = $cursorY + ($height - (count($lines) * 8.0)) / 2 + 7.0;

            foreach ($lines as $line) {
                $this->text($content, $line, $column['x'] + ($column['width'] / 2), $lineY, 7.0, 'bold', 'center');
                $lineY += 8.0;
            }
        }

        $cursorY += $height;
    }

    /**
     * @param  array<string, string>  $row
     * @param  array<int, array{key: string, numeric: bool, width: float}>  $columns
     */
    private function rowHeight(array $row, array $columns): float
    {
        $lines = 1;

        foreach ($columns as $column) {
            $lines = max($lines, count($this->wrap($row[$column['key']] ?? '', $column['width'] - 12, 8)));
        }

        return max(22.0, 10.0 + ($lines * 10.0));
    }

    /**
     * @param  array<string, string>  $row
     * @param  array<int, array{key: string, numeric: bool, x: float, width: float}>  $columns
     */
    private function drawTableRow(string &$content, array $row, array $columns, float $y, float $height): void
    {
        foreach ($columns as $column) {
            $this->rectangle($content, $column['x'], $y, $column['width'], $height, [0.12, 0.16, 0.22]);
            $lines = $this->wrap($row[$column['key']] ?? '', $column['width'] - 12, 8);
            $textY = $y + 12;

            foreach ($lines as $line) {
                $x = $column['numeric'] ? $column['x'] + $column['width'] - 6 : $column['x'] + 6;
                $this->text($content, $line, $x, $textY, 8, 'regular', $column['numeric'] ? 'right' : 'left');
                $textY += 10;
            }
        }
    }

    /** @param array<string, mixed> $document */
    private function drawFooter(string &$content, int $pageNumber, int $totalPages, array $document): void
    {
        foreach (array_slice($this->wrap($document['note'] ?? '', self::PAGE_WIDTH - self::MARGIN * 2, 8), 0, 2) as $index => $line) {
            $this->text($content, $line, self::MARGIN, self::PAGE_HEIGHT - 66 + $index * 10, 8);
        }
        foreach (array_slice($this->wrap($document['organization_description'], self::PAGE_WIDTH - self::MARGIN * 2, 7), 0, 2) as $index => $line) {
            $this->text($content, $line, self::MARGIN, self::PAGE_HEIGHT - 42 + $index * 9, 7);
        }
        $this->text($content, 'Generated '.now()->format('d M Y H:i:s T'), self::MARGIN, self::PAGE_HEIGHT - 20, 8);
        $this->text($content, 'Page '.$pageNumber.' of '.$totalPages, self::PAGE_WIDTH - self::MARGIN, self::PAGE_HEIGHT - 20, 8, 'regular', 'right');
    }

    /**
     * @param  array<int, float>  $color
     */
    private function rectangle(string &$content, float $x, float $topY, float $width, float $height, array $color = [0.12, 0.16, 0.22]): void
    {
        $y = self::PAGE_HEIGHT - $topY - $height;
        $content .= sprintf("%.3F %.3F %.3F RG 0.5 w %.2F %.2F %.2F %.2F re S\n", $color[0], $color[1], $color[2], $x, $y, $width, $height);
    }

    /**
     * @param  array<int, float>  $color
     */
    private function filledRectangle(string &$content, float $x, float $topY, float $width, float $height, array $color): void
    {
        $y = self::PAGE_HEIGHT - $topY - $height;
        $content .= sprintf("%.3F %.3F %.3F rg %.2F %.2F %.2F %.2F re f\n", $color[0], $color[1], $color[2], $x, $y, $width, $height);
    }

    private function text(string &$content, string $text, float $x, float $topY, float $size, string $font = 'regular', string $align = 'left'): void
    {
        $fontName = match ($font) {
            'bold' => 'F2',
            'mono' => 'F3',
            'mono-bold' => 'F4',
            default => 'F1',
        };
        $width = $this->textWidth($text, $size, Str::contains($font, 'mono'));
        $adjustedX = match ($align) {
            'center' => $x - ($width / 2),
            'right' => $x - $width,
            default => $x,
        };
        $y = self::PAGE_HEIGHT - $topY;

        $content .= sprintf("0 0 0 rg BT /%s %.2F Tf 1 0 0 1 %.2F %.2F Tm (%s) Tj ET\n", $fontName, $size, $adjustedX, $y, $this->pdfText($text));
    }

    private function image(string &$content, string $name, float $x, float $topY, float $width, float $height): void
    {
        $y = self::PAGE_HEIGHT - $topY - $height;
        $content .= sprintf("q %.2F 0 0 %.2F %.2F %.2F cm /%s Do Q\n", $width, $height, $x, $y, $name);
    }

    /**
     * @return array<int, string>
     */
    private function wrap(string $text, float $maxWidth, float $size): array
    {
        if (str_contains($text, "\n")) {
            return array_merge(...array_map(fn (string $line): array => $this->wrap($line, $maxWidth, $size), explode("\n", $text)));
        }
        $text = trim(preg_replace('/\s+/', ' ', $text) ?? '');

        if ($text === '') {
            return [''];
        }

        $lines = [];
        $line = '';

        foreach (explode(' ', $text) as $word) {
            $candidate = trim($line.' '.$word);

            if ($line !== '' && $this->textWidth($candidate, $size) > $maxWidth) {
                $lines[] = $line;
                $line = $word;
            } else {
                $line = $candidate;
            }

            while ($this->textWidth($line, $size) > $maxWidth && strlen($line) > 8) {
                $chunkLength = max(4, (int) floor(strlen($line) * ($maxWidth / $this->textWidth($line, $size))));
                $lines[] = substr($line, 0, $chunkLength);
                $line = substr($line, $chunkLength);
            }
        }

        if ($line !== '') {
            $lines[] = $line;
        }

        return $lines ?: [''];
    }

    /**
     * @param  array<int, array<string, string>>  $rows
     * @param  array<int, array<string, mixed>>  $columns
     * @return array<int, array<string, string>>
     */
    private function splitLongRows(array $rows, array $columns): array
    {
        $result = [];
        foreach ($rows as $row) {
            $wrapped = [];
            $chunks = 1;
            foreach ($columns as $column) {
                $wrapped[$column['key']] = array_chunk($this->wrap($row[$column['key']] ?? '', $column['width'] - 12, 8), 10);
                $chunks = max($chunks, count($wrapped[$column['key']]));
            }
            for ($index = 0; $index < $chunks; $index++) {
                $result[] = array_map(fn (array $parts): string => implode("\n", $parts[$index] ?? []), $wrapped);
            }
        }

        return $result;
    }

    private function textWidth(string $text, float $size, bool $mono = false): float
    {
        return strlen($this->plainText($text)) * $size * ($mono ? 0.60 : 0.50);
    }

    private function addPage(int $pagesId, string $content): int
    {
        $contentId = $this->addStream($content);
        $resources = '<< /Font << /F1 '.$this->regularFontId.' 0 R /F2 '.$this->boldFontId.' 0 R /F3 '.$this->monoFontId.' 0 R /F4 '.$this->monoBoldFontId.' 0 R >>';

        if ($this->logo !== null) {
            $resources .= ' /XObject << /Logo '.$this->logo['id'].' 0 R >>';
        }

        $resources .= ' >>';

        return $this->addObject('<< /Type /Page /Parent '.$pagesId.' 0 R /MediaBox [0 0 '.self::PAGE_WIDTH.' '.self::PAGE_HEIGHT.'] /Resources '.$resources.' /Contents '.$contentId.' 0 R >>');
    }

    /**
     * @return array{id: int, width: int, height: int}|null
     */
    private function logoObject(?string $path): ?array
    {
        if (! $path || ! is_file($path) || ! extension_loaded('gd')) {
            return null;
        }

        $contents = file_get_contents($path);

        if ($contents === false) {
            return null;
        }

        $source = @imagecreatefromstring($contents);

        if (! $source) {
            return null;
        }

        $width = imagesx($source);
        $height = imagesy($source);
        $canvas = imagecreatetruecolor($width, $height);
        $white = imagecolorallocate($canvas, 255, 255, 255);
        imagefill($canvas, 0, 0, $white);
        imagecopy($canvas, $source, 0, 0, 0, 0, $width, $height);

        ob_start();
        imagejpeg($canvas, null, 90);
        $jpeg = ob_get_clean();
        imagedestroy($canvas);
        imagedestroy($source);

        if (! is_string($jpeg) || $jpeg === '') {
            return null;
        }

        $id = $this->addStream($jpeg, ' /Type /XObject /Subtype /Image /Width '.$width.' /Height '.$height.' /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode');

        return ['id' => $id, 'width' => $width, 'height' => $height];
    }

    private function reserveObject(): int
    {
        $id = count($this->objects) + 1;
        $this->objects[$id] = null;

        return $id;
    }

    private function setObject(int $id, string $body): void
    {
        $this->objects[$id] = $body;
    }

    private function addObject(string $body): int
    {
        $id = $this->reserveObject();
        $this->setObject($id, $body);

        return $id;
    }

    private function addStream(string $stream, string $extraDictionary = ''): int
    {
        return $this->addObject('<< /Length '.strlen($stream).$extraDictionary." >>\nstream\n".$stream."\nendstream");
    }

    private function assemble(int $catalogId): string
    {
        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [0];

        foreach ($this->objects as $id => $body) {
            $offsets[$id] = strlen($pdf);
            $pdf .= $id." 0 obj\n".$body."\nendobj\n";
        }

        $xrefOffset = strlen($pdf);
        $pdf .= "xref\n0 ".(count($this->objects) + 1)."\n";
        $pdf .= "0000000000 65535 f \n";

        for ($id = 1; $id <= count($this->objects); $id++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$id]);
        }

        $pdf .= "trailer\n<< /Size ".(count($this->objects) + 1).' /Root '.$catalogId." 0 R >>\nstartxref\n".$xrefOffset."\n%%EOF";

        return $pdf;
    }

    private function pdfText(string $text): string
    {
        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $this->plainText($text));
    }

    private function plainText(string $text): string
    {
        $converted = @iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $text);

        return is_string($converted) ? $converted : preg_replace('/[^\x20-\x7E]/', '', $text) ?? '';
    }
}
