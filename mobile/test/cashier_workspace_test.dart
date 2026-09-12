import 'dart:convert';
import 'package:flutter/material.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:mobile/core/config/api_config.dart';
import 'package:mobile/core/network/api_client.dart';
import 'package:mobile/features/cashier/cashier_workspace.dart';

void main() {
  final session = {
    'id': 7, 'status': 'open', 'branch': 'Main branch', 'cash_in': 0, 'cash_out': 0,
    'drawer': {'id': 1, 'code': 'T01', 'name': 'Main till', 'currency': 'UGX', 'current_balance': 100000},
  };
  final types = [
    {'id': 1, 'code': 'deposit', 'name': 'Cash deposit', 'direction': 'credit'},
    {'id': 2, 'code': 'withdrawal', 'name': 'Cash withdrawal', 'direction': 'debit'},
    {'id': 3, 'code': 'loan_repayment', 'name': 'Loan repayment', 'direction': 'credit'},
  ];
  http.Response response(dynamic data, {int status = 200}) => http.Response(jsonEncode({'data': data}), status);

  for (final operation in ['Deposit', 'Withdrawal', 'Loan repayment', 'Ledger']) {
    testWidgets('$operation searches, reviews and posts the exact draft with a receipt', (tester) async {
      tester.view.physicalSize = const Size(430, 1000);
      tester.view.devicePixelRatio = 1;
      addTearDown(tester.view.resetPhysicalSize);
      addTearDown(tester.view.resetDevicePixelRatio);
      FlutterSecureStorage.setMockInitialValues({'access_token': 'cashier'});
      Map<String, dynamic>? draft;
      var posts = 0;
      final client = ApiClient(config: const ApiConfig(baseUrl: 'https://example.test/api/v1'), transport: MockClient((request) async {
        if (request.url.path.endsWith('/dashboard')) return response({'session': session, 'drawers': [], 'can_post_ledger': true, 'business_date': '2026-09-12', 'transaction_types': types});
        if (request.url.path.endsWith('/lookup')) {
          expect(request.url.queryParameters['category'], operation == 'Ledger' ? null : operation == 'Loan repayment' ? 'loan' : 'savings');
          return response([{'id': 4, 'name': 'Jane Client', 'client_name': 'Jane Client', 'client_phone': '+256700000001', 'account_number': 'ACC001', 'balance': 10000, 'currency': 'UGX', 'code': 'GL001', 'type': 'income', 'drawer_direction': 'both'}]);
        }
        if (request.url.path.endsWith('/preview')) {
          draft = Map<String, dynamic>.from(jsonDecode(request.body));
          expect(draft!['drawer_session_id'], 7);
          expect(draft!['transaction_date'], '2026-09-12');
          if (operation == 'Ledger') {
            expect(draft!['gl_entry_type'], 'credit');
          } else {
            expect(draft!['transaction_type_id'], operation == 'Deposit' ? 1 : operation == 'Withdrawal' ? 2 : 3);
          }
          return response({'amount': 1000, 'charges': 0, 'effects': [{'label': 'Drawer', 'before': 100000, 'after': 101000}], 'notes': []});
        }
        if (request.url.path.endsWith('/transactions')) {
          posts++;
          expect(jsonDecode(request.body), draft);
          if (operation == 'Deposit' && posts == 1) return http.Response(jsonEncode({'message': 'Connection interrupted'}), 503);
          return response({'id': 10, 'reference': 'TX-001', 'status': 'posted', 'amount': 1000, 'charges': 0, 'balance_after': 11000, 'cashier': 'Cashier'}, status: 201);
        }
        throw StateError(request.url.toString());
      }));
      await tester.pumpWidget(MaterialApp(home: CashierWorkspace(client: client, user: const {'name': 'Cashier'}, onSignedOut: () {})));
      await tester.pumpAndSettle();
      final choice = find.widgetWithText(ChoiceChip, operation);
      await tester.ensureVisible(choice);
      await tester.tap(choice);
      await tester.pumpAndSettle();
      final search = find.byType(TextField).first;
      await tester.ensureVisible(search);
      await tester.enterText(search, 'Jane');
      await tester.testTextInput.receiveAction(TextInputAction.search);
      await tester.pumpAndSettle();
      final match = find.text('Jane Client');
      await tester.ensureVisible(match);
      await tester.tap(match);
      await tester.pumpAndSettle();
      final amount = find.widgetWithText(TextField, 'Amount (UGX)');
      await tester.ensureVisible(amount);
      await tester.enterText(amount, '1000');
      final review = find.widgetWithText(FilledButton, 'Review transaction');
      await tester.ensureVisible(review);
      await tester.tap(review);
      await tester.pumpAndSettle();
      expect(find.text('Drawer: 100000 → 101000 UGX'), findsOneWidget);
      await tester.tap(find.text('Confirm transaction'));
      await tester.pumpAndSettle();
      if (operation == 'Deposit') {
        expect(find.text('Retry same transaction'), findsOneWidget);
        await tester.tap(find.text('Retry same transaction'));
        await tester.pumpAndSettle();
        expect(posts, 2);
      }
      expect(find.text('Transaction receipt'), findsOneWidget);
      expect(find.text('TX-001'), findsOneWidget);
      await tester.tap(find.text('Done'));
      await tester.pumpAndSettle();
      expect(tester.takeException(), isNull);
    });
  }
}
