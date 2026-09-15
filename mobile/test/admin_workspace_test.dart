import 'dart:async';
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

  test('company scope follows requests and is suspended during impersonation', () async {
    FlutterSecureStorage.setMockInitialValues({'access_token': 'admin'});
    final scopes = <String?>[];
    final client = ApiClient(config: config, transport: MockClient((request) async {
      scopes.add(request.headers['X-Organization-Id']);
      return success({'id': 1});
    }));
    await client.selectOrganization(10);
    await client.get('loans');
    await client.impersonate({'id': 2});
    client.stopImpersonating();
    await client.me();
    await client.selectOrganization(null);
    expect(scopes, ['10', '10', null, '10', 'global']);
    await client.clearSession();
    expect(client.organizationId, isNull);
    expect(client.profile.value, isNull);
  });

  test('old unauthorized responses cannot clear a changed workspace session', () async {
    FlutterSecureStorage.setMockInitialValues({'access_token': 'admin'});
    final started = Completer<void>();
    final pending = Completer<http.Response>();
    final client = ApiClient(config: config, transport: MockClient((request) async {
      if (request.url.path.endsWith('/slow')) {
        started.complete();
        return pending.future;
      }
      return success({'id': 1});
    }));
    final old = client.get('slow');
    await started.future;
    await client.selectOrganization(20);
    final assertion = expectLater(old, throwsA(isA<ApiException>().having((e) => e.message, 'message', contains('workspace changed'))));
    pending.complete(http.Response('{}', 401));
    await assertion;
    expect(await client.hasSession(), isTrue);
    expect(client.organizationId, 20);
  });

  test('failed scope changes restore the previous company', () async {
    FlutterSecureStorage.setMockInitialValues({'access_token': 'admin'});
    final client = ApiClient(config: config, transport: MockClient((request) async {
      if (request.headers['X-Organization-Id'] == '99') {
        return http.Response(jsonEncode({'message': 'Forbidden'}), 403);
      }
      return success({'id': 1});
    }));
    await client.selectOrganization(10);
    await expectLater(client.selectOrganization(99), throwsA(isA<ApiException>()));
    expect(client.organizationId, 10);
    expect(await client.hasSession(), isTrue);
  });

  test('financial requests promote the stable body key to the idempotency header', () async {
    FlutterSecureStorage.setMockInitialValues({'access_token': 'admin'});
    final client = ApiClient(config: config, transport: MockClient((request) async {
      expect(request.headers['Idempotency-Key'], 'stable-operation-001');
      return success({'accepted': true});
    }));

    await client.request('POST', 'savings/accounts/1/deposit', body: {
      'amount': 1000,
      'idempotency_key': 'stable-operation-001',
    });
  });

  test('financial failures persist and retry pending operations with the same key', () async {
    FlutterSecureStorage.setMockInitialValues({'access_token': 'admin'});
    var failed = true;
    final keys = <String?>[];
    final client = ApiClient(config: config, transport: MockClient((request) async {
      keys.add(request.headers['Idempotency-Key']);
      if (failed) {
        return http.Response(jsonEncode({'message': 'Unavailable'}), 503);
      }
      return success({'accepted': true});
    }));

    await expectLater(
      client.requestFinancial(
        'POST',
        'cashier/transactions',
        operationId: 'cashier-tx-001',
        body: {'amount': 1000, 'idempotency_key': 'stable-operation-001'},
      ),
      throwsA(isA<ApiException>()),
    );
    expect((await client.pendingFinancialOperations()).single['id'], 'cashier-tx-001');

    failed = false;
    await client.retryPendingFinancial('cashier-tx-001');
    expect(keys, ['stable-operation-001', 'stable-operation-001']);
    expect(await client.pendingFinancialOperations(), isEmpty);
  });

  test('pending transactions reject changed amounts and workspace changes', () async {
    FlutterSecureStorage.setMockInitialValues({'access_token': 'admin'});
    var posts = 0;
    final client = ApiClient(config: config, transport: MockClient((request) async {
      if (request.method == 'GET') return success({'id': 1});
      posts++;
      return http.Response(jsonEncode({'message': 'Unavailable'}), 503);
    }));
    await expectLater(client.requestFinancial('POST', 'cashier/transactions',
      operationId: 'pending-1', body: {'amount': 1000}), throwsA(isA<ApiException>()));
    await expectLater(client.requestFinancial('POST', 'cashier/transactions',
      operationId: 'pending-1', body: {'amount': 2000}), throwsA(isA<ApiException>()));
    expect((await client.pendingFinancialOperations()).single['body']['amount'], 1000);
    await client.selectOrganization(20);
    await expectLater(client.retryPendingFinancial('pending-1'), throwsA(isA<ApiException>()));
    expect(posts, 1);
  });

  test('pending recovery survives restart but rejects a different login session', () async {
    FlutterSecureStorage.setMockInitialValues({'access_token': 'admin'});
    final failing = ApiClient(config: config, transport: MockClient((_) async =>
      http.Response(jsonEncode({'message': 'Unavailable'}), 503)));
    await expectLater(failing.requestFinancial('POST', 'cashier/transactions',
      operationId: 'restart-1', body: {'amount': 1000}), throwsA(isA<ApiException>()));
    var posts = 0;
    final restarted = ApiClient(config: config, transport: MockClient((request) async {
      posts++;
      expect(request.headers['Idempotency-Key'], 'restart-1');
      return success({'accepted': true});
    }));
    await restarted.retryPendingFinancial('restart-1');
    expect(posts, 1);
    expect(await restarted.pendingFinancialOperations(), isEmpty);

    await expectLater(failing.requestFinancial('POST', 'cashier/transactions',
      operationId: 'restart-2', body: {'amount': 1000}), throwsA(isA<ApiException>()));
    await restarted.clearSession();
    await const FlutterSecureStorage().write(key: 'access_token', value: 'other');
    await expectLater(restarted.retryPendingFinancial('restart-2'), throwsA(isA<ApiException>()));
    expect(posts, 1);
  });

  test('a denied profile after login does not leave a stored session', () async {
    FlutterSecureStorage.setMockInitialValues({});
    final client = ApiClient(config: config, transport: MockClient((request) async {
      return request.url.path.endsWith('/login')
          ? success({'access_token': 'new-token'})
          : http.Response(jsonEncode({'message': 'Organization unavailable'}), 403);
    }));
    await expectLater(client.login('user', 'password'), throwsA(isA<ApiException>()));
    expect(await client.hasSession(), isFalse);
  });

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
