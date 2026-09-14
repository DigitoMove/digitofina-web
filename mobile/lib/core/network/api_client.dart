import 'dart:async';
import 'dart:convert';
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
  }

  Future<Map<String, dynamic>> get(String path, {Map<String, String>? query}) =>
      request('GET', path, query: query);

  Future<Map<String, dynamic>> request(
    String method,
    String path, {
    Map<String, String>? query,
    Map<String, dynamic>? body,
    String? idempotencyKey,
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
