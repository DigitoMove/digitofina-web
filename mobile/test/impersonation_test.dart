import 'dart:convert';
import 'package:flutter/material.dart';
import 'package:mobile/features/admin/impersonation_picker.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:mobile/core/config/api_config.dart';
import 'package:mobile/core/network/api_client.dart';

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  testWidgets('picker starts with cashiers and opens the selected profile', (
    tester,
  ) async {
    FlutterSecureStorage.setMockInitialValues({'access_token': 'admin-token'});
    Map<String, dynamic>? selected;
    final client = ApiClient(
      config: const ApiConfig(baseUrl: 'https://example.test/api/v1'),
      transport: MockClient((request) async {
        if (request.url.path.endsWith('/users')) {
          expect(request.url.queryParameters['role'], 'teller-cashier');
          return http.Response(
            jsonEncode({
              'data': {
                'users': [
                  {
                    'id': 2,
                    'name': 'Jane Cashier',
                    'role_name': 'Cashier',
                    'organization': 'Branch Bank',
                  },
                ],
                'last_page': 1,
              },
            }),
            200,
          );
        }
        return http.Response(
          jsonEncode({
            'data': {'id': 2, 'role': 'teller-cashier'},
          }),
          200,
        );
      }),
    );
    await tester.pumpWidget(
      MaterialApp(
        home: ImpersonationPicker(
          client: client,
          onSelected: (user) => selected = user,
        ),
      ),
    );
    await tester.pumpAndSettle();
    expect(find.text('Jane Cashier'), findsOneWidget);
    await tester.tap(find.text('Jane Cashier'));
    await tester.pumpAndSettle();
    expect(selected?['role'], 'teller-cashier');
  });

  test(
    'impersonation uses the admin token and returning clears the target',
    () async {
      FlutterSecureStorage.setMockInitialValues({
        'access_token': 'admin-token',
      });
      final targets = <String?>[];
      final client = ApiClient(
        config: const ApiConfig(baseUrl: 'https://example.test/api/v1'),
        transport: MockClient((request) async {
          expect(request.headers['Authorization'], 'Bearer admin-token');
          final target = request.headers['X-Impersonate-User'];
          targets.add(target);
          return http.Response(
            jsonEncode({
              'data': {'id': target == null ? 1 : 2},
            }),
            200,
          );
        }),
      );
      expect((await client.impersonate({'id': 2, 'name': 'Cashier'}))['id'], 2);
      await client.get('cashier/dashboard');
      client.stopImpersonating();
      expect((await client.me())['id'], 1);
      expect(targets, ['2', '2', null]);
    },
  );

  test(
    'a rejected impersonation returns to the administrator context',
    () async {
      FlutterSecureStorage.setMockInitialValues({
        'access_token': 'admin-token',
      });
      final client = ApiClient(
        config: const ApiConfig(baseUrl: 'https://example.test/api/v1'),
        transport: MockClient(
          (request) async =>
              http.Response(jsonEncode({'message': 'Inactive user'}), 422),
        ),
      );
      await expectLater(
        client.impersonate({'id': 2}),
        throwsA(isA<ApiException>()),
      );
      expect(client.impersonatedUser.value, isNull);
      expect(await client.hasSession(), isTrue);
    },
  );
}
