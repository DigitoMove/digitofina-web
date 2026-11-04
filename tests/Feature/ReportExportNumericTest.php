<?php

use App\Models\ReportExport;
use App\Services\ReportDocumentFormatter;

it('writes currency amounts as numeric Excel cells while preserving identifiers', function () {
    $export = new ReportExport(['report_type' => 'loan_report', 'format' => 'xlsx']);
    $content = app(ReportDocumentFormatter::class)->renderReport($export, [
        ['Account Number' => '001234', 'Principal' => 'UGX 1,234.50', 'Interest' => 'USD -20.25', 'Amount' => '3,000.00', 'Other Value' => 'KES 2,500.00'],
    ]);

    $path = tempnam(sys_get_temp_dir(), 'report-export-xlsx');
    file_put_contents($path, $content);
    $archive = new ZipArchive;

    try {
        expect($archive->open($path))->toBeTrue();
        $worksheet = simplexml_load_string($archive->getFromName('xl/worksheets/sheet1.xml'));
        $worksheet->registerXPathNamespace('x', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $numberCells = collect($worksheet->xpath('//x:c[x:v]'));
        $textCells = collect($worksheet->xpath('//x:c[@t="inlineStr"]'));

        expect($numberCells->map(fn (SimpleXMLElement $cell): string => (string) $cell->v)->all())
            ->toBe(['1234.5', '-20.25', '3000', '2500'])
            ->and($textCells->contains(fn (SimpleXMLElement $cell): bool => (string) $cell->is->t === '001234'))->toBeTrue();
    } finally {
        $archive->close();
        @unlink($path);
    }
});
