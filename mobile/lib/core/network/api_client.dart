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
    final offlineClientsKey = _offlineClientsKey;
    final activeOfflineClientsKey = await _storage.read(
      key: 'offline_clients_active_key',
    );
    stopImpersonating();
    _organizationId = null;
    _hasExplicitScope = false;
    await _storage.delete(key: 'access_token');
    await _storage.delete(key: 'financial_session');
    await _storage.delete(key: offlineClientsKey);
    await _storage.delete(key: '${offlineClientsKey}_synced_at');
    await _storage.delete(key: '${offlineClientsKey}_drafts');
    if (activeOfflineClientsKey != null) {
      await _storage.delete(key: activeOfflineClientsKey);
      await _storage.delete(key: '${activeOfflineClientsKey}_synced_at');
      await _storage.delete(key: '${activeOfflineClientsKey}_drafts');
      await _storage.delete(key: 'offline_clients_active_key');
    }
  }

  Future<Map<String, dynamic>> get(String path, {Map<String, String>? query}) =>
      request('GET', path, query: query);

  String get _offlineClientsKey =>
      'offline_clients_${_organizationId ?? 'default'}_${impersonatedUser.value?['id'] ?? profile.value?['id'] ?? 'self'}';

  String get _offlineClientsSyncedAtKey => '${_offlineClientsKey}_synced_at';

  String get _offlineClientDraftsKey => '${_offlineClientsKey}_drafts';

  Future<Map<String, dynamic>> syncClientsForOffline() async {
    final response = await get(
      'sync/clients',
      query: const {'per_page': '200', 'sort': '-updated_at'},
    );
    final records = (response['data'] as List? ?? [])
        .whereType<Map>()
        .map((item) => Map<String, dynamic>.from(item))
        .toList();
    await _storage.write(key: _offlineClientsKey, value: jsonEncode(records));
    await _storage.write(
      key: 'offline_clients_active_key',
      value: _offlineClientsKey,
    );
    final syncedAt = DateTime.now().toUtc().toIso8601String();
    await _storage.write(key: _offlineClientsSyncedAtKey, value: syncedAt);
    return {'count': records.length, 'synced_at': syncedAt};
  }

  Future<Map<String, dynamic>> offlineClientsSnapshot() async {
    final encoded = await _storage.read(key: _offlineClientsKey);
    final syncedAt = await _storage.read(key: _offlineClientsSyncedAtKey);
    if (encoded == null || encoded.isEmpty) {
      return {'clients': <Map<String, dynamic>>[], 'synced_at': syncedAt};
    }
    try {
      final clients = (jsonDecode(encoded) as List)
          .whereType<Map>()
          .map((item) => Map<String, dynamic>.from(item))
          .toList();
      return {'clients': clients, 'synced_at': syncedAt};
    } catch (_) {
      await _storage.delete(key: _offlineClientsKey);
      return {'clients': <Map<String, dynamic>>[], 'synced_at': syncedAt};
    }
  }

  Future<List<Map<String, dynamic>>> searchOfflineClients(String search) async {
    final normalized = search.trim().toLowerCase();
    final snapshot = await offlineClientsSnapshot();
    return (snapshot['clients'] as List<Map<String, dynamic>>)
        .where((client) {
          final searchable = [
            client['client_number'],
            client['full_name'],
            client['first_name'],
            client['last_name'],
            client['phone'],
            client['national_id'],
          ].join(' ').toLowerCase();
          return searchable.contains(normalized);
        })
        .take(30)
        .toList();
  }

  Future<List<Map<String, dynamic>>> offlineClientDrafts() async {
    final encoded = await _storage.read(key: _offlineClientDraftsKey);
    if (encoded == null || encoded.isEmpty) return [];
    try {
      return (jsonDecode(encoded) as List)
          .whereType<Map>()
          .map((item) => Map<String, dynamic>.from(item))
          .toList();
    } catch (_) {
      await _storage.delete(key: _offlineClientDraftsKey);
      return [];
    }
  }

  Future<void> stageOfflineClient({
    required Map<String, dynamic> payload,
    int? serverId,
    String? serverUpdatedAt,
  }) async {
    final drafts = await offlineClientDrafts();
    final localId = serverId == null
        ? 'client-${DateTime.now().microsecondsSinceEpoch}-${Random.secure().nextInt(1 << 20)}'
        : 'server-client-$serverId';
    drafts.removeWhere((draft) => draft['local_temp_id'] == localId);
    drafts.add({
      'local_temp_id': localId,
      'action': serverId == null ? 'create' : 'update',
      'server_id': serverId,
      'server_updated_at': serverUpdatedAt,
      'client_updated_at': DateTime.now().toUtc().toIso8601String(),
      'payload': payload,
      'status': 'pending',
    });
    await _storage.write(
      key: _offlineClientDraftsKey,
      value: jsonEncode(drafts),
    );
  }

  Future<Map<String, dynamic>> syncOfflineClientDrafts() async {
    final drafts = await offlineClientDrafts();
    if (drafts.isEmpty) {
      return {
        'summary': {'total': 0, 'accepted': 0, 'conflicts': 0, 'failed': 0},
      };
    }
    final batchId = 'mobile-client-${DateTime.now().microsecondsSinceEpoch}';
    final response = await request(
      'POST',
      'sync/clients',
      body: {
        'client_batch_id': batchId,
        'device_name': 'DigitoFina Mobile',
        'records': drafts
            .map((draft) => Map<String, dynamic>.from(draft)..remove('status'))
            .toList(),
      },
    );
    final result = Map<String, dynamic>.from(response['data'] as Map);
    final results = (result['records'] as List? ?? [])
        .whereType<Map>()
        .map(Map<String, dynamic>.from)
        .toList();
    final unresolved = <Map<String, dynamic>>[];
    for (final draft in drafts) {
      Map<String, dynamic>? outcome;
      for (final resultRecord in results) {
        if (resultRecord['local_temp_id'] == draft['local_temp_id']) {
          outcome = resultRecord;
          break;
        }
      }
      if (outcome == null || outcome['status'] != 'accepted') {
        unresolved.add({
          ...draft,
          ...(outcome == null ? <String, dynamic>{} : {'sync_result': outcome}),
          'status': outcome?['status'] ?? 'pending',
        });
      }
    }
    await _storage.write(
      key: _offlineClientDraftsKey,
      value: jsonEncode(unresolved),
    );
    return result;
  }

  Future<Map<String, dynamic>> postMultipart(
    String path, {
    required Map<String, String> fields,
    required String fileField,
    required String fileName,
    required List<int> fileBytes,
  }) async {
    final revision = _contextRevision;
    final base = config.baseUrl.replaceAll(RegExp(r'/+$'), '');
    final token = await _storage.read(key: 'access_token');
    final target = impersonatedUser.value;
    final request = http.MultipartRequest('POST', Uri.parse('$base/$path'))
      ..headers.addAll({
        'Accept': 'application/json',
        if (target != null) 'X-Impersonate-User': target['id'].toString(),
        if (target == null && _hasExplicitScope)
          'X-Organization-Id': _organizationId?.toString() ?? 'global',
        if (token != null) 'Authorization': 'Bearer $token',
      })
      ..fields.addAll(fields)
      ..files.add(
        http.MultipartFile.fromBytes(fileField, fileBytes, filename: fileName),
      );
    if (revision != _contextRevision) {
      throw const ApiException('Your workspace changed. Reload to continue.');
    }
    late http.Response response;
    try {
      response = await _transport
          .send(request)
          .then(http.Response.fromStream)
          .timeout(const Duration(seconds: 30));
    } on TimeoutException {
      throw const ApiException('The upload timed out. Please retry.');
    } on http.ClientException {
      throw const ApiException(
        'Unable to reach DigitoFina. Check your connection and retry.',
      );
    }
    Map<String, dynamic> payload;
    try {
      payload = Map<String, dynamic>.from(jsonDecode(response.body) as Map);
    } catch (_) {
      throw ApiException(
        'The server returned an unexpected response.',
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
        message ?? 'Upload failed.',
        status: response.statusCode,
      );
    }
    return payload;
  }

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
      throw const ApiException(
        'The pending financial operation was not found.',
      );
    }
    final revision = _contextRevision;
    if (record['context'] != await _financialContext() ||
        revision != _contextRevision) {
      throw const ApiException(
        'Return to the original workspace to recover this transaction.',
      );
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
    final result = Map<String, dynamic>.from(
      (await get('auth/me'))['data'] as Map,
    );
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
