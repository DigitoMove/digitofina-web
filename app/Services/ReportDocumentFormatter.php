<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\Organization;
use App\Models\ReportExport;
use App\Models\SavingsAccount;
use Carbon\Carbon;
use DateTimeInterface;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;
use ZipArchive;

class ReportDocumentFormatter
{
    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    public function renderReport(ReportExport $export, array $rows): string
    {
        $organization = $this->organization($export->organization_id);
        $branch = $this->branch($export->branch_id);
        $title = $this->reportTitle($export->report_type);
        $filters = $export->filters ?? [];

        return $this->render([
            'title' => $title,
            'heading' => $title.' As At: '.$this->asAtLabel($filters),
            'organization' => $organization,
            'branch' => $branch,
            'metadata' => [
                'Report Name' => $title,
                'Report Number' => 'EXPORT-'.$export->id,
                'Currency' => $this->currencyLabel($organization?->currency),
                'Branch' => $this->branchLabel($branch),
                'Period' => $this->periodLabel($filters),
            ],
            'columns' => $this->columns($rows),
            'rows' => $rows,
        ], $export->format);
    }

    /**
     * @param  array<int, array<string, mixed>>  $transactions
     */
    public function renderStatement(ReportExport $export, SavingsAccount $account, array $transactions): string
    {
        $account->loadMissing(['branch', 'client', 'organization']);

        $filters = $export->filters ?? [];
        $organization = $account->organization ?: $this->organization($export->organization_id);
        $branch = $account->branch ?: $this->branch($export->branch_id);
        $openingBalance = $transactions[0]['balance_before'] ?? $account->current_balance;

        $rows = [[
            'date' => null,
            'narration' => 'Opening Balance',
            'debit' => null,
            'credit' => null,
            'balance' => (float) $openingBalance,
        ]];

        foreach ($transactions as $transaction) {
            $rows[] = $this->statementRow($transaction);
        }

        return $this->render([
            'title' => 'Savings Account Transactions',
            'heading' => 'Savings Account Transactions As At: '.$this->asAtLabel($filters),
            'organization' => $organization,
            'branch' => $branch,
            'metadata' => [
                'Account Name' => trim((string) ($account->client?->full_name ?: 'Savings Account')),
                'Account Number' => (string) $account->account_number,
                'Currency' => $this->currencyLabel($organization?->currency),
                'Account Branch' => $this->branchLabel($branch),
                'Period' => $this->periodLabel($filters),
            ],
            'columns' => ['date', 'narration', 'debit', 'credit', 'balance'],
            'rows' => $rows,
        ], $export->format);
    }

    /**
     * @param  array{
     *     title: string,
     *     heading: string,
     *     organization: Organization|null,
     *     branch: Branch|null,
     *     metadata: array<string, string>,
     *     columns: array<int, string>,
     *     rows: array<int, array<string, mixed>>
     * }  $document
     */
    private function render(array $document, string $format): string
    {
        return match ($format) {
            'xlsx' => $this->renderXlsx($document),
            'pdf' => $this->renderPdf($document),
            'csv' => $this->renderCsv($document),
            default => $this->renderHtml($document),
        };
    }

    /**
     * @param  array<string, mixed>  $transaction
     * @return array{date: mixed, narration: string, debit: float|null, credit: float|null, balance: float}
     */
    private function statementRow(array $transaction): array
    {
        $type = (string) ($transaction['type'] ?? '');
        $amount = (float) ($transaction['amount'] ?? 0);
        $charges = (float) ($transaction['charges'] ?? 0);
        $isDebit = $this->isDebitSavingsTransaction($type);
        $narration = trim((string) ($transaction['narration'] ?? ''));

        if ($narration === '') {
            $narration = trim(implode(' ', array_filter([
                Str::headline(str_replace('_', ' ', $type)),
                $transaction['reference'] ?? null,
                isset($transaction['payment_method']) ? 'via '.Str::headline(str_replace('_', ' ', (string) $transaction['payment_method'])) : null,
            ])));
        }

        if ($charges > 0) {
            $narration = trim($narration.'; Charges '.$this->formatMoney($charges));
        }

        return [
            'date' => $transaction['transaction_date'] ?? $transaction['updated_at'] ?? null,
            'narration' => $narration,
            'debit' => $isDebit ? $amount : null,
            'credit' => $isDebit ? null : $amount,
            'balance' => (float) ($transaction['balance_after'] ?? 0),
        ];
    }

    private function isDebitSavingsTransaction(string $type): bool
    {
        return Str::contains($type, ['withdrawal', 'withdraw', 'transfer_out', 'charge', 'fee', 'repayment', 'deduction']);
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, string>
     */
    private function columns(array $rows): array
    {
        $columns = [];

        foreach ($rows as $row) {
            foreach (array_keys($row) as $column) {
                if (! in_array($column, $columns, true)) {
                    $columns[] = $column;
                }
            }
        }

        return $columns ?: ['message'];
    }

    /**
     * @param  array{
     *     title: string,
     *     heading: string,
     *     organization: Organization|null,
     *     metadata: array<string, string>,
     *     columns: array<int, string>,
     *     rows: array<int, array<string, mixed>>
     * }  $document
     */
    private function renderPdf(array $document): string
    {
        return app(ReportPdfRenderer::class)->render([
            'title' => $document['title'],
            'heading' => $document['heading'],
            'organization_name' => $document['organization']?->name ?? config('app.name'),
            'organization_description' => $this->organizationDescription($document['organization']),
            'logo_path' => $this->logoPath($document['organization']),
            'metadata' => $document['metadata'],
            'columns' => array_map(fn (string $column): array => [
                'key' => $column,
                'label' => $this->columnLabel($column),
                'numeric' => $this->isAmountColumn($column),
            ], $document['columns']),
            'rows' => array_map(fn (array $row): array => collect($document['columns'])
                ->mapWithKeys(fn (string $column): array => [$column => $this->displayValue($row[$column] ?? null, $column)])
                ->all(), $document['rows'] ?: [['message' => 'No records found for the selected period.']]),
        ]);
    }

    /**
     * @param  array{
     *     columns: array<int, string>,
     *     rows: array<int, array<string, mixed>>
     * }  $document
     */
    private function renderCsv(array $document): string
    {
        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, array_map(fn (string $column): string => $this->columnLabel($column), $document['columns']));

        foreach ($document['rows'] ?: [['message' => 'No records found for the selected period.']] as $row) {
            fputcsv($handle, array_map(
                fn (string $column): string => $this->displayValue($row[$column] ?? null, $column),
                $document['columns']
            ));
        }

        rewind($handle);

        return stream_get_contents($handle) ?: '';
    }

    /**
     * @param  array{
     *     title: string,
     *     heading: string,
     *     organization: Organization|null,
     *     metadata: array<string, string>,
     *     columns: array<int, string>,
     *     rows: array<int, array<string, mixed>>
     * }  $document
     */
    private function renderHtml(array $document): string
    {
        $logo = $this->logoDataUri($document['organization']);
        $organization = $document['organization'];
        $description = $this->organizationDescription($organization);

        $metadata = collect($document['metadata'])
            ->map(fn (string $value, string $label): string => '<div class="meta-label">'.$this->escape($label).':</div><div>'.$this->escape($value).'</div>')
            ->implode('');

        $headers = collect($document['columns'])
            ->map(fn (string $column): string => '<th>'.$this->escape($this->columnLabel($column)).'</th>')
            ->implode('');

        $body = collect($document['rows'] ?: [['message' => 'No records found for the selected period.']])
            ->map(function (array $row) use ($document): string {
                $cells = collect($document['columns'])
                    ->map(function (string $column) use ($row): string {
                        $value = $row[$column] ?? null;
                        $numeric = is_numeric($value) && $this->isAmountColumn($column);
                        $class = $numeric ? ' class="number"' : '';

                        return '<td'.$class.'>'.$this->escape($this->displayValue($value, $column)).'</td>';
                    })
                    ->implode('');

                return '<tr>'.$cells.'</tr>';
            })
            ->implode('');

        return '<!doctype html>
<html>
<head>
<meta charset="utf-8">
<title>'.$this->escape($document['title']).'</title>
<style>
    @page { size: landscape; margin: 14mm; }
    * { box-sizing: border-box; }
    body { color: #111827; font-family: Arial, Helvetica, sans-serif; font-size: 12px; margin: 22px; }
    .document-header { align-items: flex-start; display: grid; gap: 24px; grid-template-columns: 1fr 1fr; margin-bottom: 14px; }
    .brand img { display: block; max-height: 82px; max-width: 280px; object-fit: contain; }
    .brand-name { color: #111827; font-size: 16px; font-weight: 700; margin-top: 6px; text-transform: uppercase; }
    .brand-description { color: #475569; font-size: 11px; line-height: 1.4; margin-top: 4px; max-width: 360px; }
    .metadata { display: grid; font-family: "Courier New", monospace; font-size: 13px; gap: 6px 14px; grid-template-columns: 160px minmax(220px, 1fr); justify-content: end; }
    .meta-label { font-weight: 700; }
    h1 { font-size: 16px; margin: 18px 0 7px; text-align: center; }
    table { border-collapse: collapse; table-layout: fixed; width: 100%; }
    th, td { border: 1px solid #1f2937; padding: 7px 9px; text-align: left; vertical-align: top; word-break: break-word; }
    th { background: #e5e7eb; font-size: 12px; text-align: center; text-transform: uppercase; }
    td.number { text-align: right; white-space: nowrap; }
    tbody tr:nth-child(even) td { background: #f8fafc; }
</style>
</head>
<body>
    <header class="document-header">
        <section class="brand">'.
            ($logo ? '<img src="'.$this->escape($logo).'" alt="'.$this->escape($organization?->name ?? 'Organization logo').'">' : '<div class="brand-name">'.$this->escape($organization?->name ?? config('app.name')).'</div>').
            ($logo ? '<div class="brand-name">'.$this->escape($organization?->name ?? config('app.name')).'</div>' : '').
            ($description ? '<div class="brand-description">'.$this->escape($description).'</div>' : '').
        '</section>
        <section class="metadata">'.$metadata.'</section>
    </header>
    <h1>'.$this->escape($document['heading']).'</h1>
    <table>
        <thead><tr>'.$headers.'</tr></thead>
        <tbody>'.$body.'</tbody>
    </table>
</body>
</html>';
    }

    /**
     * @param  array{
     *     title: string,
     *     heading: string,
     *     organization: Organization|null,
     *     metadata: array<string, string>,
     *     columns: array<int, string>,
     *     rows: array<int, array<string, mixed>>
     * }  $document
     */
    private function renderXlsx(array $document): string
    {
        $path = $this->temporaryPath('df-export-', '.xlsx');
        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Unable to create Excel export archive.');
        }

        $image = $this->xlsxImage($document['organization']);
        $zip->addFromString('[Content_Types].xml', $this->contentTypesXml($image));
        $zip->addFromString('_rels/.rels', $this->rootRelationshipsXml());
        $zip->addFromString('docProps/app.xml', $this->appPropertiesXml());
        $zip->addFromString('docProps/core.xml', $this->corePropertiesXml($document['title']));
        $zip->addFromString('xl/workbook.xml', $this->workbookXml($document['title']));
        $zip->addFromString('xl/_rels/workbook.xml.rels', $this->workbookRelationshipsXml());
        $zip->addFromString('xl/styles.xml', $this->stylesXml());
        $zip->addFromString('xl/worksheets/sheet1.xml', $this->worksheetXml($document, $image !== null));

        if ($image !== null) {
            $zip->addFromString('xl/worksheets/_rels/sheet1.xml.rels', $this->worksheetRelationshipsXml());
            $zip->addFromString('xl/drawings/drawing1.xml', $this->drawingXml($image));
            $zip->addFromString('xl/drawings/_rels/drawing1.xml.rels', $this->drawingRelationshipsXml($image));
            $zip->addFile($image['path'], 'xl/media/logo.'.$image['extension']);
        }

        $zip->close();

        try {
            return file_get_contents($path) ?: '';
        } finally {
            @unlink($path);
        }
    }

    /**
     * @param  array{
     *     title: string,
     *     heading: string,
     *     organization: Organization|null,
     *     metadata: array<string, string>,
     *     columns: array<int, string>,
     *     rows: array<int, array<string, mixed>>
     * }  $document
     */
    private function worksheetXml(array $document, bool $hasImage): string
    {
        $columns = $document['columns'];
        $tableWidth = max(count($columns), 5);
        $titleRow = max(7, count($document['metadata']) + 2);
        $headerRow = $titleRow + 1;
        $sheetRows = [];

        for ($row = 1; $row < $titleRow; $row++) {
            $cells = [];

            if (! $hasImage && $row === 1) {
                $cells[] = $this->textCell('A'.$row, $document['organization']?->name ?? config('app.name'), 1);
            }

            if (! $hasImage && $row === 2) {
                $cells[] = $this->textCell('A'.$row, $this->organizationDescription($document['organization']), 6);
            }

            if ($hasImage && $row === 5) {
                $cells[] = $this->textCell('A'.$row, $document['organization']?->name ?? config('app.name'), 2);
            }

            if ($hasImage && $row === 6) {
                $cells[] = $this->textCell('A'.$row, $this->organizationDescription($document['organization']), 6);
            }

            $metadataIndex = $row - 1;
            $metadata = array_values(array_map(
                fn (string $label, string $value): array => [$label, $value],
                array_keys($document['metadata']),
                $document['metadata']
            ));

            if (isset($metadata[$metadataIndex])) {
                [$label, $value] = $metadata[$metadataIndex];
                $cells[] = $this->textCell('D'.$row, $label.':', 2);
                $cells[] = $this->textCell('E'.$row, $value, 0);
            }

            if ($cells !== []) {
                $sheetRows[] = '<row r="'.$row.'">'.implode('', $cells).'</row>';
            }
        }

        $sheetRows[] = '<row r="'.$titleRow.'" ht="22" customHeight="1">'.$this->textCell('A'.$titleRow, $document['heading'], 1).'</row>';

        $headerCells = [];
        foreach ($columns as $index => $column) {
            $headerCells[] = $this->textCell($this->columnName($index + 1).$headerRow, $this->columnLabel($column), 3);
        }
        $sheetRows[] = '<row r="'.$headerRow.'" ht="20" customHeight="1">'.implode('', $headerCells).'</row>';

        $dataRows = $document['rows'] ?: [['message' => 'No records found for the selected period.']];
        $rowNumber = $headerRow + 1;

        foreach ($dataRows as $row) {
            $cells = [];

            foreach ($columns as $index => $column) {
                $cellRef = $this->columnName($index + 1).$rowNumber;
                $value = $row[$column] ?? null;
                $cells[] = is_numeric($value) && $this->isAmountColumn($column)
                    ? $this->numberCell($cellRef, (float) $value, 4)
                    : $this->textCell($cellRef, $this->displayValue($value, $column), 5);
            }

            $sheetRows[] = '<row r="'.$rowNumber.'">'.implode('', $cells).'</row>';
            $rowNumber++;
        }

        $lastColumn = $this->columnName($tableWidth);
        $columnDefinitions = [];

        for ($index = 1; $index <= $tableWidth; $index++) {
            $width = match ($index) {
                1 => 16,
                2 => 38,
                default => 18,
            };
            $columnDefinitions[] = '<col min="'.$index.'" max="'.$index.'" width="'.$width.'" customWidth="1"/>';
        }

        $mergeCells = '<mergeCells count="1"><mergeCell ref="A'.$titleRow.':'.$lastColumn.$titleRow.'"/></mergeCells>';
        $drawing = $hasImage ? '<drawing r:id="rId1"/>' : '';
        $relationshipsNamespace = $hasImage ? ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"' : '';

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'.$relationshipsNamespace.'>
    <sheetViews><sheetView showGridLines="0" workbookViewId="0"/></sheetViews>
    <sheetFormatPr defaultRowHeight="15"/>
    <cols>'.implode('', $columnDefinitions).'</cols>
    <sheetData>'.implode('', $sheetRows).'</sheetData>
    '.$mergeCells.'
    <pageMargins left="0.3" right="0.3" top="0.5" bottom="0.5" header="0.2" footer="0.2"/>
    <pageSetup orientation="landscape" fitToWidth="1"/>
    '.$drawing.'
</worksheet>';
    }

    /**
     * @param  array{path: string, extension: string, content_type: string}|null  $image
     */
    private function contentTypesXml(?array $image): string
    {
        $imageDefault = $image ? '<Default Extension="'.$this->xml($image['extension']).'" ContentType="'.$this->xml($image['content_type']).'"/>' : '';
        $drawingOverride = $image ? '<Override PartName="/xl/drawings/drawing1.xml" ContentType="application/vnd.openxmlformats-officedocument.drawing+xml"/>' : '';

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
    <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
    <Default Extension="xml" ContentType="application/xml"/>
    '.$imageDefault.'
    <Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>
    <Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>
    <Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>
    <Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>
    <Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>
    '.$drawingOverride.'
</Types>';
    }

    private function rootRelationshipsXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
    <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>
    <Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>
    <Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/>
</Relationships>';
    }

    private function workbookXml(string $title): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
    <sheets><sheet name="'.$this->xml(Str::limit($title, 28, '')).'" sheetId="1" r:id="rId1"/></sheets>
</workbook>';
    }

    private function workbookRelationshipsXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
    <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>
    <Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>
</Relationships>';
    }

    private function appPropertiesXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties" xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes">
    <Application>DigitoFina</Application>
</Properties>';
    }

    private function corePropertiesXml(string $title): string
    {
        $created = now()->toIso8601String();

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:dcmitype="http://purl.org/dc/dcmitype/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">
    <dc:title>'.$this->xml($title).'</dc:title>
    <dc:creator>DigitoFina</dc:creator>
    <cp:lastModifiedBy>DigitoFina</cp:lastModifiedBy>
    <dcterms:created xsi:type="dcterms:W3CDTF">'.$this->xml($created).'</dcterms:created>
    <dcterms:modified xsi:type="dcterms:W3CDTF">'.$this->xml($created).'</dcterms:modified>
</cp:coreProperties>';
    }

    private function stylesXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
    <numFmts count="1"><numFmt numFmtId="164" formatCode="#,##0.00"/></numFmts>
    <fonts count="4">
        <font><sz val="11"/><color rgb="FF111827"/><name val="Calibri"/></font>
        <font><b/><sz val="11"/><color rgb="FF111827"/><name val="Calibri"/></font>
        <font><b/><sz val="14"/><color rgb="FF111827"/><name val="Calibri"/></font>
        <font><sz val="10"/><color rgb="FF475569"/><name val="Calibri"/></font>
    </fonts>
    <fills count="3">
        <fill><patternFill patternType="none"/></fill>
        <fill><patternFill patternType="gray125"/></fill>
        <fill><patternFill patternType="solid"><fgColor rgb="FFE5E7EB"/><bgColor indexed="64"/></patternFill></fill>
    </fills>
    <borders count="2">
        <border><left/><right/><top/><bottom/><diagonal/></border>
        <border><left style="thin"><color rgb="FF1F2937"/></left><right style="thin"><color rgb="FF1F2937"/></right><top style="thin"><color rgb="FF1F2937"/></top><bottom style="thin"><color rgb="FF1F2937"/></bottom><diagonal/></border>
    </borders>
    <cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>
    <cellXfs count="7">
        <xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>
        <xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1" applyAlignment="1"><alignment horizontal="center"/></xf>
        <xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/>
        <xf numFmtId="0" fontId="1" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" wrapText="1"/></xf>
        <xf numFmtId="164" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyBorder="1" applyAlignment="1"><alignment horizontal="right"/></xf>
        <xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1" applyAlignment="1"><alignment wrapText="1"/></xf>
        <xf numFmtId="0" fontId="3" fillId="0" borderId="0" xfId="0" applyFont="1" applyAlignment="1"><alignment wrapText="1"/></xf>
    </cellXfs>
    <cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>
</styleSheet>';
    }

    private function worksheetRelationshipsXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
    <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/drawing" Target="../drawings/drawing1.xml"/>
</Relationships>';
    }

    /**
     * @param  array{extension: string}  $image
     */
    private function drawingRelationshipsXml(array $image): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
    <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="../media/logo.'.$this->xml($image['extension']).'"/>
</Relationships>';
    }

    /**
     * @param  array{path: string}  $image
     */
    private function drawingXml(array $image): string
    {
        [$width, $height] = @getimagesize($image['path']) ?: [320, 120];
        $dimensions = $this->constrainedLogoDimensions((int) $width, (int) $height);

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<xdr:wsDr xmlns:xdr="http://schemas.openxmlformats.org/drawingml/2006/spreadsheetDrawing" xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main">
    <xdr:oneCellAnchor>
        <xdr:from><xdr:col>0</xdr:col><xdr:colOff>95250</xdr:colOff><xdr:row>0</xdr:row><xdr:rowOff>95250</xdr:rowOff></xdr:from>
        <xdr:ext cx="'.$dimensions['cx'].'" cy="'.$dimensions['cy'].'"/>
        <xdr:pic>
            <xdr:nvPicPr><xdr:cNvPr id="1" name="Organization Logo"/><xdr:cNvPicPr/></xdr:nvPicPr>
            <xdr:blipFill><a:blip xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" r:embed="rId1"/><a:stretch><a:fillRect/></a:stretch></xdr:blipFill>
            <xdr:spPr><a:prstGeom prst="rect"><a:avLst/></a:prstGeom></xdr:spPr>
        </xdr:pic>
        <xdr:clientData/>
    </xdr:oneCellAnchor>
</xdr:wsDr>';
    }

    /**
     * @return array{cx: int, cy: int}
     */
    private function constrainedLogoDimensions(int $width, int $height): array
    {
        $maxWidth = 170;
        $maxHeight = 70;
        $scale = min($maxWidth / max(1, $width), $maxHeight / max(1, $height), 1);
        $displayWidth = max(40, (int) round($width * $scale));
        $displayHeight = max(24, (int) round($height * $scale));

        return [
            'cx' => $displayWidth * 9525,
            'cy' => $displayHeight * 9525,
        ];
    }

    private function textCell(string $cell, mixed $value, int $style = 0): string
    {
        return '<c r="'.$cell.'" t="inlineStr" s="'.$style.'"><is><t>'.$this->xml((string) $value).'</t></is></c>';
    }

    private function numberCell(string $cell, float $value, int $style = 0): string
    {
        return '<c r="'.$cell.'" s="'.$style.'"><v>'.rtrim(rtrim(number_format($value, 4, '.', ''), '0'), '.').'</v></c>';
    }

    private function columnName(int $index): string
    {
        $name = '';

        while ($index > 0) {
            $index--;
            $name = chr(65 + ($index % 26)).$name;
            $index = intdiv($index, 26);
        }

        return $name;
    }

    private function reportTitle(string $reportType): string
    {
        return match ($reportType) {
            'clients', 'client_report' => 'Client Report',
            'savings', 'savings_report' => 'Savings Accounts Report',
            'savings_transactions' => 'Savings Transactions Report',
            'loans', 'loan_report' => 'Loan Book',
            'arrears', 'arrears_report' => 'Loan Arrears Report',
            'branch_performance' => 'Branch Performance Report',
            default => Str::headline(str_replace('_', ' ', $reportType)),
        };
    }

    private function branchLabel(?Branch $branch): string
    {
        if (! $branch) {
            return 'ALL BRANCHES';
        }

        return Str::upper(trim(implode(' - ', array_filter([$branch->code, $branch->name]))));
    }

    private function currencyLabel(?string $currency): string
    {
        $currency = Str::upper($currency ?: 'UGX');

        return match ($currency) {
            'UGX' => 'Uganda Shilling - UGX',
            'USD' => 'United States Dollar - USD',
            'KES' => 'Kenya Shilling - KES',
            'TZS' => 'Tanzania Shilling - TZS',
            default => $currency,
        };
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function periodLabel(array $filters): string
    {
        $from = $filters['date_from'] ?? $filters['from'] ?? $filters['start_date'] ?? null;
        $to = $filters['date_to'] ?? $filters['to'] ?? $filters['end_date'] ?? null;
        $single = $filters['business_date'] ?? $filters['date'] ?? null;

        if ($from || $to) {
            return $this->dateLabel($from ?: $to).' - '.$this->dateLabel($to ?: $from);
        }

        if ($single) {
            return $this->dateLabel($single).' - '.$this->dateLabel($single);
        }

        return today()->format('d-m-Y').' - '.today()->format('d-m-Y');
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function asAtLabel(array $filters): string
    {
        return $this->dateLabel(
            $filters['date_to']
                ?? $filters['to']
                ?? $filters['end_date']
                ?? $filters['business_date']
                ?? $filters['date']
                ?? today()
        );
    }

    private function dateLabel(mixed $value): string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format('d-m-Y');
        }

        if (blank($value)) {
            return today()->format('d-m-Y');
        }

        try {
            return Carbon::parse((string) $value)->format('d-m-Y');
        } catch (Throwable) {
            return (string) $value;
        }
    }

    private function displayValue(mixed $value, string $column): string
    {
        if ($value instanceof DateTimeInterface) {
            return $this->dateLabel($value);
        }

        if ($value === null || $value === '') {
            return '';
        }

        if (is_numeric($value) && $this->isAmountColumn($column)) {
            return $this->formatMoney((float) $value);
        }

        if (is_bool($value)) {
            return $value ? 'Yes' : 'No';
        }

        if (is_array($value)) {
            return json_encode($value) ?: '';
        }

        if ($this->isDateColumn($column)) {
            return $this->dateLabel($value);
        }

        return (string) $value;
    }

    private function columnLabel(string $column): string
    {
        return Str::headline(str_replace('_', ' ', $column));
    }

    private function isAmountColumn(string $column): bool
    {
        return Str::contains($column, [
            'amount',
            'balance',
            'debit',
            'credit',
            'fee',
            'fees',
            'charge',
            'charges',
            'penalty',
            'total',
            'paid',
            'principal',
            'interest',
        ]);
    }

    private function isDateColumn(string $column): bool
    {
        return Str::contains($column, ['date', '_at']);
    }

    private function formatMoney(float $amount): string
    {
        return number_format($amount, 2);
    }

    private function organization(?int $organizationId): ?Organization
    {
        return $organizationId ? Organization::query()->find($organizationId) : null;
    }

    private function branch(?int $branchId): ?Branch
    {
        return $branchId ? Branch::withoutGlobalScopes()->find($branchId) : null;
    }

    private function organizationDescription(?Organization $organization): string
    {
        if (! $organization) {
            return '';
        }

        return trim(implode(' | ', array_filter([
            $organization->registration_number ? 'Reg. '.$organization->registration_number : null,
            $organization->address,
            $organization->phone,
            $organization->email,
            $organization->website,
        ])));
    }

    private function logoDataUri(?Organization $organization): ?string
    {
        $path = $this->logoPath($organization);

        if (! $path || ! is_file($path)) {
            return null;
        }

        $mime = mime_content_type($path) ?: 'image/png';

        return 'data:'.$mime.';base64,'.base64_encode(file_get_contents($path) ?: '');
    }

    /**
     * @return array{path: string, extension: string, content_type: string}|null
     */
    private function xlsxImage(?Organization $organization): ?array
    {
        $path = $this->logoPath($organization);

        if (! $path || ! is_file($path)) {
            return null;
        }

        $extension = Str::lower(pathinfo($path, PATHINFO_EXTENSION));
        $extension = $extension === 'jpeg' ? 'jpg' : $extension;

        return match ($extension) {
            'png' => ['path' => $path, 'extension' => 'png', 'content_type' => 'image/png'],
            'jpg' => ['path' => $path, 'extension' => 'jpg', 'content_type' => 'image/jpeg'],
            'svg' => ['path' => $path, 'extension' => 'svg', 'content_type' => 'image/svg+xml'],
            default => null,
        };
    }

    private function logoPath(?Organization $organization): ?string
    {
        $logo = $organization?->logo ?: $organization?->logo_path;

        if (filled($logo)) {
            $path = (string) $logo;

            if (is_file($path)) {
                return $path;
            }

            if (Str::startsWith($path, ['http://', 'https://'])) {
                $path = parse_url($path, PHP_URL_PATH) ?: $path;
            }

            $normalized = ltrim(str_replace('\\', '/', $path), '/');
            $withoutStoragePrefix = Str::startsWith($normalized, 'storage/')
                ? Str::after($normalized, 'storage/')
                : $normalized;
            $withoutPublicPrefix = Str::startsWith($withoutStoragePrefix, 'public/')
                ? Str::after($withoutStoragePrefix, 'public/')
                : $withoutStoragePrefix;

            foreach (array_unique([
                public_path($normalized),
                public_path('storage/'.$withoutStoragePrefix),
                public_path('storage/'.$withoutPublicPrefix),
                Storage::disk('public')->path($withoutStoragePrefix),
                Storage::disk('public')->path($withoutPublicPrefix),
                storage_path('app/public/'.$withoutPublicPrefix),
            ]) as $candidate) {
                if (is_file($candidate)) {
                    return $candidate;
                }
            }
        }

        foreach ([public_path('brand/digitofina-logo.svg'), public_path('brand/digitofina-mark.svg')] as $fallback) {
            if (is_file($fallback)) {
                return $fallback;
            }
        }

        return null;
    }

    private function temporaryPath(string $prefix, string $extension): string
    {
        $path = tempnam(sys_get_temp_dir(), $prefix);

        if ($path === false) {
            throw new \RuntimeException('Unable to allocate a temporary export path.');
        }

        $target = $path.$extension;
        @rename($path, $target);

        return $target;
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function xml(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_COMPAT, 'UTF-8');
    }
}
