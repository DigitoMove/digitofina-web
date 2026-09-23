import 'dart:async';
import 'dart:convert';
import 'dart:math';
import 'package:flutter/foundation.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:http/http.dart' as http;
import '../config/api_config.dart';

class ApiClient {
  ApiClient({
    required this.config,
    FlutterSecureStorage? storage,
    http.Client? transport,
  }) : _storage = storage ?? const FlutterSecureStorage(),
       _transport = transport ?? http.Client();
  final impersonatedUser = ValueNotifier<Map<String, dynamic>?>(null);
  final profile = ValueNotifier<Map<String, dynamic>?>(null);
  int _contextRevision = 0;
  int? _organizationId;
  bool _hasExplicitScope = false;
  int? get organizationId => _organizationId;

  Future<Map<String, dynamic>> selectOrganization(int? id) async {
    final previous = _organizationId;
    final previousExplicitScope = _hasExplicitScope;
    _organizationId = id;
    _hasExplicitScope = true;
    final revision = ++_contextRevision;
    profile.value = null;
    try {
      return await me();
    } catch (_) {
      if (_contextRevision == revision) {
        _organizationId = previous;
        _hasExplicitScope = previousExplicitScope;
        _contextRevision++;
      }
      rethrow;
    }
  }

  Future<Map<String, dynamic>> impersonate(Map<String, dynamic> user) async {
    final revision = ++_contextRevision;
    profile.value = null;
    impersonatedUser.value = user;
    try {
      return await me();
    } catch (_) {
      if (_contextRevision == revision) {
        stopImpersonating();
      }
      rethrow;
    }
  }

  void stopImpersonating() {
    _contextRevision++;
    profile.value = null;
    impersonatedUser.value = null;
  }

  final ApiConfig config;
  final FlutterSecureStorage _storage;
  final http.Client _transport;

  Future<bool> hasSession() async =>
      await _storage.read(key: 'access_token') != null;
  Future<void> clearSession() async {
    stopImpersonating();
    _organizationId = null;
    _hasExplicitScope = false;
    await _storage.delete(key: 'access_token');
    await _storage.delete(key: 'financial_session');
  }

  Future<Map<String, dynamic>> get(String path, {Map<String, String>? query}) =>
      request('GET', path, query: query);

  Future<List<Map<String, dynamic>>> pendingFinancialOperations() async {
    final encoded = await _storage.read(key: 'pending_financial_operations');
    if (encoded == null || encoded.isEmpty) return [];
    try {
      return (jsonDecode(encoded) as List)
          .map((item) => Map<String, dynamic>.from(item as Map))
          .toList();
    } catch (_) {
      await _storage.delete(key: 'pending_financial_operations');
      return [];
    }
  }

  Future<Map<String, dynamic>> requestFinancial(
    String method,
    String path, {
    required String operationId,
    Map<String, String>? query,
    required Map<String, dynamic> body,
  }) async {
    final revision = _contextRevision;
    final context = await _financialContext();
    final operations = await pendingFinancialOperations();
    final key = body['idempotency_key']?.toString() ?? operationId;
    if (operationId.isEmpty || key.isEmpty) {
      throw const ApiException('A financial operation requires a unique key.');
    }
    for (final existing in operations) {
      if (existing['id'] == operationId || existing['idempotency_key'] == key) {
        if (existing['context'] != context ||
            existing['method'] != method ||
            existing['path'] != path ||
            existing['idempotency_key'] != key ||
            _canonicalJson(existing['query']) != _canonicalJson(query ?? {}) ||
            _canonicalJson(existing['body']) != _canonicalJson(body)) {
          throw const ApiException(
            'This pending transaction belongs to a different workspace or has changed. Recover the original transaction before starting another.',
          );
        }
      }
    }
    final record = <String, dynamic>{
      'id': operationId,
      'method': method,
      'path': path,
      'query': query ?? <String, String>{},
      'body': body,
      'idempotency_key': key,
      'context': context,
      'created_at': DateTime.now().toUtc().toIso8601String(),
    };
    operations.removeWhere((item) => item['id'] == operationId);
    operations.add(record);
    await _storage.write(
      key: 'pending_financial_operations',
      value: jsonEncode(operations),
    );
    if (revision != _contextRevision) {
      throw const ApiException('Your workspace changed. Reload to continue.');
    }
    try {
      final response = await request(
        method,
        path,
        query: query,
        body: body,
        idempotencyKey: key,
      );
      await _removePendingFinancialOperation(operationId);
      return response;
    } catch (_) {
      rethrow;
    }
  }

  String _canonicalJson(dynamic value) {
    if (value is Map) {
      final keys = value.keys.map((key) => key.toString()).toList()..sort();
      return '{${keys.map((key) => '${jsonEncode(key)}:${_canonicalJson(value[key])}').join(',')}}';
    }
    if (value is List) {
      return '[${value.map(_canonicalJson).join(',')}]';
    }
    return jsonEncode(value);
  }

  Future<String> _financialContext() async {
    if (!await hasSession()) {
      throw const ApiException('Sign in before recovering a transaction.');
    }
    var session = await _storage.read(key: 'financial_session');
    if (session == null) {
      final random = Random.secure();
      session = base64UrlEncode(List.generate(32, (_) => random.nextInt(256)));
      await _storage.write(key: 'financial_session', value: session);
    }
    return jsonEncode([
      config.baseUrl,
      session,
      _hasExplicitScope,
      _organizationId,
      impersonatedUser.value?['id'],
    ]);
  }

  Future<Map<String, dynamic>> retryPendingFinancial(String operationId) async {
    final operations = await pendingFinancialOperations();
    Map<String, dynamic>? record;
    for (final item in operations) {
      if (item['id'] == operationId) {
        record = item;
        break;
      }
    }
    if (record == null) {
      throw const ApiException('The pending financial operation was not found.');
    }
    final revision = _contextRevision;
    if (record['context'] != await _financialContext() || revision != _contextRevision) {
      throw const ApiException('Return to the original workspace to recover this transaction.');
    }
    try {
      final verified = await request(
        record['method'].toString(),
        record['path'].toString(),
        query: Map<String, String>.from(record['query'] as Map? ?? {}),
        body: Map<String, dynamic>.from(record['body'] as Map),
        idempotencyKey: record['idempotency_key'].toString(),
        verifyFinancialOnly: true,
      );
      await _removePendingFinancialOperation(operationId);
      return verified;
    } on ApiException catch (error) {
      if (error.status != 404 || error.message != 'not_found') rethrow;
    }
    if (revision != _contextRevision) {
      throw const ApiException('Your workspace changed. Reload to continue.');
    }
    return requestFinancial(
      record['method'].toString(),
      record['path'].toString(),
      operationId: operationId,
      query: Map<String, String>.from(record['query'] as Map? ?? {}),
      body: Map<String, dynamic>.from(record['body'] as Map),
    );
  }

  Future<void> _removePendingFinancialOperation(String operationId) async {
    final operations = await pendingFinancialOperations();
    operations.removeWhere((item) => item['id'] == operationId);
    if (operations.isEmpty) {
      await _storage.delete(key: 'pending_financial_operations');
    } else {
      await _storage.write(
        key: 'pending_financial_operations',
        value: jsonEncode(operations),
      );
    }
  }

  Future<Map<String, dynamic>> request(
    String method,
    String path, {
    Map<String, String>? query,
    Map<String, dynamic>? body,
    String? idempotencyKey,
    bool verifyFinancialOnly = false,
  }) async {
    final revision = _contextRevision;
    final base = config.baseUrl.replaceAll(RegExp(r'/+$'), '');
    final uri = Uri.parse('$base/$path').replace(queryParameters: query);
    final target = impersonatedUser.value;
    final token = await _storage.read(key: 'access_token');
    final operationKey = idempotencyKey ?? body?['idempotency_key']?.toString();
    if (revision != _contextRevision) {
      throw const ApiException('Your workspace changed. Reload to continue.');
    }
    final req = http.Request(method, uri)
      ..headers.addAll({
        'Accept': 'application/json',
        'Content-Type': 'application/json',
        if (verifyFinancialOnly) 'Idempotency-Verify-Only': 'true',
        if (target != null) 'X-Impersonate-User': target['id'].toString(),
        if (target == null && _hasExplicitScope)
          'X-Organization-Id': _organizationId?.toString() ?? 'global',
        if (token != null) 'Authorization': 'Bearer $token',
        if (operationKey != null && operationKey.isNotEmpty)
          'Idempotency-Key': operationKey,
      });
    if (body != null) req.body = jsonEncode(body);
    late http.Response response;
    try {
      response = await _transport
          .send(req)
          .then(http.Response.fromStream)
          .timeout(const Duration(seconds: 25));
    } on TimeoutException {
      throw const ApiException(
        'The server took too long to respond. Please retry.',
      );
    } on http.ClientException {
      throw const ApiException(
        'Unable to reach DigitoFina. Check your connection and retry.',
      );
    }
    if (revision != _contextRevision) {
      throw const ApiException('Your workspace changed. Reload to continue.');
    }
    if (response.statusCode == 401) await clearSession();
    Map<String, dynamic> payload;
    try {
      payload = Map<String, dynamic>.from(jsonDecode(response.body) as Map);
    } catch (_) {
      throw ApiException(
        'The server returned an unexpected response. Please try again later.',
        status: response.statusCode,
      );
    }
    if (response.statusCode >= 400 || payload['success'] == false) {
      final errors = payload['errors'];
      final first = errors is Map && errors.isNotEmpty
          ? errors.values.first
          : null;
      final message = first is List && first.isNotEmpty
          ? first.first.toString()
          : payload['message']?.toString();
      throw ApiException(
        message ?? 'Request failed.',
        status: response.statusCode,
      );
    }
    return payload;
  }

  Future<Map<String, dynamic>> login(String login, String password) async {
    await clearSession();
    final payload = await request(
      'POST',
      'auth/login',
      body: {
        'login': login,
        'password': password,
        'device_name': 'DigitoFina Mobile',
      },
    );
    await _storage.write(
      key: 'access_token',
      value: payload['data']['access_token'] as String,
    );
    try {
      return await me();
    } catch (_) {
      await clearSession();
      rethrow;
    }
  }

  Future<Map<String, dynamic>> me() async {
    final result = Map<String, dynamic>.from((await get('auth/me'))['data'] as Map);
    profile.value = result;
    return result;
  }

  Future<void> logout() async {
    stopImpersonating();
    try {
      if (await hasSession()) await request('POST', 'auth/logout');
    } finally {
      await clearSession();
    }
  }

  Future<Map<String, dynamic>> dashboard() async => Map<String, dynamic>.from(
    (await get('reports/dashboard'))['data'] as Map,
  );
}

class ApiException implements Exception {
  const ApiException(this.message, {this.status});
  final String message;
  final int? status;
  @override
  String toString() => message;
}
