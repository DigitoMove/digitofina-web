import 'dart:convert';
import 'package:flutter/material.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:mobile/core/config/api_config.dart';
import 'package:mobile/core/network/api_client.dart';
import 'package:mobile/features/admin/admin_workspace.dart';
import 'package:mobile/main.dart';

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();
  const config = ApiConfig(baseUrl: 'https://digitofina.digitomove.com/api/v1');
  Map<String, dynamic> summary = {
    'scope': null,
    'metrics': [
      {
        'label': 'Organizations',
        'value': 2,
        'section': 'organizations',
        'money': false,
      },
    ],
    'navigation': [
      {
        'key': 'organizations',
        'label': 'Organizations',
        'group': 'Administration',
      },
    ],
    'recent_organizations': [],
  };
  http.Response success(dynamic data, {Map<String, dynamic>? meta}) =>
      http.Response(
        jsonEncode({'success': true, 'data': data, 'meta': meta ?? {}}),
        200,
      );

  test(
    'login uses the exact API URL and fetches permissions from the profile',
    () async {
      FlutterSecureStorage.setMockInitialValues({});
      final paths = <String>[];
      final client = ApiClient(
        config: config,
        transport: MockClient((req) async {
          paths.add(req.url.toString());
          if (req.url.path.endsWith('/login')) {
            expect(jsonDecode(req.body)['login'], 'admin@example.test');
            return success({
              'access_token': 'test-token',
              'user': {'role': 'super-admin'},
            });
          }
          expect(req.headers['Authorization'], 'Bearer test-token');
          return success({
            'name': 'Admin',
            'role': 'super-admin',
            'permissions': ['manage-users'],
          });
        }),
      );
      final profile = await client.login('admin@example.test', 'test-password');
      expect(paths, [
        'https://digitofina.digitomove.com/api/v1/auth/login',
        'https://digitofina.digitomove.com/api/v1/auth/me',
      ]);
      expect(profile['permissions'], ['manage-users']);
    },
  );

  test(
    'expired sessions are cleared and non-JSON responses are explained',
    () async {
      FlutterSecureStorage.setMockInitialValues({'access_token': 'expired'});
      final client = ApiClient(
        config: config,
        transport: MockClient(
          (_) async =>
              http.Response(jsonEncode({'message': 'Unauthenticated.'}), 401),
        ),
      );
      await expectLater(
        client.me(),
        throwsA(isA<ApiException>().having((e) => e.status, 'status', 401)),
      );
      expect(await client.hasSession(), false);
      final htmlClient = ApiClient(
        config: config,
        transport: MockClient(
          (_) async => http.Response('<html>Unavailable</html>', 503),
        ),
      );
      await expectLater(
        htmlClient.me(),
        throwsA(
          isA<ApiException>().having(
            (e) => e.message,
            'message',
            contains('unexpected response'),
          ),
        ),
      );
    },
  );

  test(
    'logout removes local credentials even if the server is unreachable',
    () async {
      FlutterSecureStorage.setMockInitialValues({'access_token': 'test-token'});
      final client = ApiClient(
        config: config,
        transport: MockClient(
          (_) async => throw http.ClientException('offline'),
        ),
      );
      await expectLater(client.logout(), throwsA(isA<ApiException>()));
      expect(await client.hasSession(), false);
    },
  );

  testWidgets(
    'restores the super admin session and opens an organization detail',
    (tester) async {
      FlutterSecureStorage.setMockInitialValues({'access_token': 'test-token'});
      final client = ApiClient(
        config: config,
        transport: MockClient((req) async {
          if (req.url.path.endsWith('/auth/me')) {
            return success({'name': 'Admin', 'role': 'super-admin'});
          }
          if (req.url.path.endsWith('/admin/dashboard')) {
            return success(summary);
          }
          if (req.url.path.endsWith('/organizations/1')) {
            return success({
              'id': 1,
              'title': 'Example SACCO',
              'fields': {'name': 'Example SACCO', 'currency': 'UGX'},
            });
          }
          return success(
            [
              {'id': 1, 'title': 'Example SACCO', 'status': 'active'},
            ],
            meta: {
              'pagination': {'total': 1, 'last_page': 1},
            },
          );
        }),
      );
      await tester.pumpWidget(MaterialApp(home: SessionGate(client: client)));
      await tester.pumpAndSettle();
      expect(find.text('Super Admin Dashboard'), findsOneWidget);
      expect(find.text('All organizations'), findsOneWidget);
      await tester.tap(find.text('Organizations').first);
      await tester.pumpAndSettle();
      expect(find.text('Example SACCO'), findsOneWidget);
      await tester.tap(find.text('Example SACCO'));
      await tester.pumpAndSettle();
      expect(find.text('Currency'), findsOneWidget);
      expect(find.text('UGX'), findsOneWidget);
      expect(tester.takeException(), isNull);
    },
  );

  testWidgets('org admin has a scoped dashboard without a tenant switcher', (
    tester,
  ) async {
    FlutterSecureStorage.setMockInitialValues({'access_token': 'test-token'});
    final client = ApiClient(
      config: config,
      transport: MockClient(
        (_) async => success({
          ...summary,
          'scope': {'id': 7, 'name': 'Own SACCO', 'currency': 'UGX'},
        }),
      ),
    );
    await tester.pumpWidget(
      MaterialApp(
        home: AdminWorkspace(
          client: client,
          user: const {'name': 'Manager', 'role': 'organization-admin'},
          onSignedOut: () {},
        ),
      ),
    );
    await tester.pumpAndSettle();
    expect(find.text('Organization Dashboard'), findsOneWidget);
    expect(find.text('Own SACCO'), findsOneWidget);
    expect(find.text('Choose organization'), findsNothing);
    expect(tester.takeException(), isNull);
  });
}
