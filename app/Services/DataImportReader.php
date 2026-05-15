<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use RuntimeException;
use ZipArchive;

class DataImportReader
{
    /**
     * @return array<int, array<string, string|null>>
     */
    public function read(UploadedFile|string $file): array
    {
        $path = $file instanceof UploadedFile ? $file->getRealPath() : $file;
        $extension = strtolower($file instanceof UploadedFile ? $file->getClientOriginalExtension() : pathinfo($file, PATHINFO_EXTENSION));

        return match ($extension) {
            'csv', 'txt' => $this->readCsv((string) $path),
            'xlsx' => $this->readXlsx((string) $path),
            default => throw new RuntimeException('Unsupported import file type. Use CSV or XLSX.'),
        };
    }

    /**
     * @return array<int, array<string, string|null>>
     */
    private function readCsv(string $path): array
    {
        $handle = fopen($path, 'r');

        if (! $handle) {
            throw new RuntimeException('The import file could not be opened.');
        }

        $headers = [];
        $rows = [];
        $line = 0;

        while (($values = fgetcsv($handle)) !== false) {
            $line++;

            if ($line === 1) {
                $headers = $this->normalizeHeaders($values);

                continue;
            }

            $row = $this->combineRow($headers, $values);

            if (collect($row)->filter(fn ($value): bool => filled($value))->isNotEmpty()) {
                $rows[] = $row;
            }
        }

        fclose($handle);

        return $rows;
    }

    /**
     * @return array<int, array<string, string|null>>
     */
    private function readXlsx(string $path): array
    {
        if (! class_exists(ZipArchive::class)) {
            throw new RuntimeException('XLSX import requires the PHP Zip extension. Save the sheet as CSV and upload again.');
        }

        $zip = new ZipArchive;

        if ($zip->open($path) !== true) {
            throw new RuntimeException('The XLSX file could not be opened.');
        }

        $sharedStrings = $this->sharedStrings($zip);
        $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();

        if (! $sheetXml) {
            throw new RuntimeException('The workbook does not contain a first worksheet.');
        }

        $xml = simplexml_load_string($sheetXml);
        $headers = [];
        $rows = [];

        foreach ($xml->sheetData->row as $rowNode) {
            $values = [];

            foreach ($rowNode->c as $cell) {
                $reference = (string) $cell['r'];
                $values[$this->columnIndex($reference)] = $this->cellValue($cell, $sharedStrings);
            }

            ksort($values);
            $denseValues = [];
            $max = empty($values) ? -1 : max(array_keys($values));

            for ($index = 0; $index <= $max; $index++) {
                $denseValues[] = $values[$index] ?? null;
            }

            if ($headers === []) {
                $headers = $this->normalizeHeaders($denseValues);

                continue;
            }

            $combined = $this->combineRow($headers, $denseValues);

            if (collect($combined)->filter(fn ($value): bool => filled($value))->isNotEmpty()) {
                $rows[] = $combined;
            }
        }

        return $rows;
    }

    /**
     * @param  array<int, mixed>  $headers
     * @return array<int, string>
     */
    private function normalizeHeaders(array $headers): array
    {
        return collect($headers)
            ->map(fn ($header): string => (string) Str::of((string) $header)->trim()->lower()->replaceMatches('/[^a-z0-9]+/', '_')->trim('_'))
            ->values()
            ->all();
    }

    /**
     * @param  array<int, string>  $headers
     * @param  array<int, mixed>  $values
     * @return array<string, string|null>
     */
    private function combineRow(array $headers, array $values): array
    {
        $row = [];

        foreach ($headers as $index => $header) {
            if ($header === '') {
                continue;
            }

            $value = $values[$index] ?? null;
            $row[$header] = is_string($value) ? trim($value) : ($value === null ? null : (string) $value);
        }

        return $row;
    }

    /**
     * @return array<int, string>
     */
    private function sharedStrings(ZipArchive $zip): array
    {
        $xml = $zip->getFromName('xl/sharedStrings.xml');

        if (! $xml) {
            return [];
        }

        $strings = simplexml_load_string($xml);

        return collect($strings->si ?? [])
            ->map(function ($item): string {
                if (isset($item->t)) {
                    return (string) $item->t;
                }

                return collect($item->r ?? [])->map(fn ($run): string => (string) $run->t)->implode('');
            })
            ->values()
            ->all();
    }

    /**
     * @param  array<int, string>  $sharedStrings
     */
    private function cellValue(object $cell, array $sharedStrings): ?string
    {
        $type = (string) $cell['t'];

        if ($type === 's') {
            return $sharedStrings[(int) $cell->v] ?? null;
        }

        if ($type === 'inlineStr') {
            return isset($cell->is->t) ? (string) $cell->is->t : null;
        }

        return isset($cell->v) ? (string) $cell->v : null;
    }

    private function columnIndex(string $reference): int
    {
        $letters = preg_replace('/[^A-Z]/', '', strtoupper($reference)) ?: 'A';
        $index = 0;

        foreach (str_split($letters) as $letter) {
            $index = ($index * 26) + (ord($letter) - 64);
        }

        return $index - 1;
    }
}
