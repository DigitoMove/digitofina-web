import 'package:pdf/pdf.dart';
import 'package:pdf/widgets.dart' as pw;
import 'package:printing/printing.dart';

Future<void> shareReceiptPdf(Map receipt) async {
  final document = pw.Document();
  document.addPage(
    pw.Page(
      pageFormat: PdfPageFormat.a5,
      build: (context) => pw.Column(
        crossAxisAlignment: pw.CrossAxisAlignment.start,
        children: [
          pw.Text(
            'DigitoFina receipt',
            style: pw.TextStyle(fontSize: 20, fontWeight: pw.FontWeight.bold),
          ),
          pw.SizedBox(height: 16),
          ..._lines({
            'Reference': receipt['reference'],
            'Amount': _money(receipt['amount']),
            if (receipt['charges'] != null)
              'Charges': _money(receipt['charges']),
            'Type': receipt['type'] ?? receipt['payment_method'],
            'Account': receipt['account'] is Map
                ? receipt['account']['number']
                : null,
            'Client': receipt['account'] is Map
                ? receipt['account']['client']
                : null,
            'Posted at': receipt['posted_at'] ?? receipt['paid_at'],
          }),
          pw.Spacer(),
          pw.Text('System-generated receipt. Keep it for your records.'),
        ],
      ),
    ),
  );
  await Printing.sharePdf(
    bytes: await document.save(),
    filename: 'digitofina-receipt-${receipt['reference'] ?? 'transaction'}.pdf',
  );
}

Future<void> shareStatementPdf({
  required String clientName,
  required List<Map> transactions,
}) async {
  final document = pw.Document();
  document.addPage(
    pw.MultiPage(
      build: (context) => [
        pw.Text(
          'DigitoFina client statement',
          style: pw.TextStyle(fontSize: 20, fontWeight: pw.FontWeight.bold),
        ),
        pw.SizedBox(height: 6),
        pw.Text(clientName),
        pw.SizedBox(height: 16),
        pw.TableHelper.fromTextArray(
          headers: const ['Date', 'Reference', 'Type', 'Amount'],
          data: transactions
              .map(
                (entry) => [
                  entry['transaction_date']?.toString() ?? '',
                  entry['reference']?.toString() ?? '',
                  entry['type']?.toString() ?? '',
                  _money(entry['amount']),
                ],
              )
              .toList(),
        ),
      ],
    ),
  );
  await Printing.sharePdf(
    bytes: await document.save(),
    filename:
        'digitofina-statement-${DateTime.now().toIso8601String().split('T').first}.pdf',
  );
}

List<pw.Widget> _lines(Map<String, dynamic> values) => values.entries
    .where((entry) => entry.value != null && entry.value.toString().isNotEmpty)
    .map(
      (entry) => pw.Padding(
        padding: const pw.EdgeInsets.only(bottom: 6),
        child: pw.Row(
          children: [
            pw.SizedBox(width: 78, child: pw.Text(entry.key)),
            pw.Expanded(child: pw.Text(entry.value.toString())),
          ],
        ),
      ),
    )
    .toList();

String _money(dynamic value) {
  final amount = value is num
      ? value.toDouble()
      : double.tryParse(value?.toString() ?? '') ?? 0;
  return 'UGX ${amount.toStringAsFixed(2)}';
}
