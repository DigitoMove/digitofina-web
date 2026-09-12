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
  testWidgets('loads a real cashier dashboard and drawer actions', (
    tester,
  ) async {
    FlutterSecureStorage.setMockInitialValues({
      'access_token': 'cashier-token',
    });
    final client = ApiClient(
      config: const ApiConfig(baseUrl: 'https://example.test/api/v1'),
      transport: MockClient((request) async {
        expect(request.headers['Authorization'], 'Bearer cashier-token');
        return http.Response(
          jsonEncode({
            'data': {
              'session': null,
              'drawers': [
                {
                  'id': 1,
                  'name': 'Main till',
                  'code': 'T01',
                  'currency': 'UGX',
                  'current_balance': 100000,
                  'status': 'closed',
                },
              ],
              'business_date': '2026-09-12',
              'transaction_types': [],
            },
          }),
          200,
        );
      }),
    );

    await tester.pumpWidget(
      MaterialApp(
        home: CashierWorkspace(
          client: client,
          user: const {'name': 'Cashier'},
          onSignedOut: () {},
        ),
      ),
    );
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 100));

    expect(find.text('Start your drawer session'), findsOneWidget);
    expect(find.text('T01 · Main till'), findsOneWidget);
    expect(find.text('Open'), findsOneWidget);
  });
}
