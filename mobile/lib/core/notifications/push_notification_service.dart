import 'dart:convert';
import 'dart:io';
import 'dart:math';

import 'package:firebase_core/firebase_core.dart';
import 'package:firebase_messaging/firebase_messaging.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';

import '../network/api_client.dart';

class PushNotificationService {
  PushNotificationService({FlutterSecureStorage? storage})
    : _storage = storage ?? const FlutterSecureStorage();

  final FlutterSecureStorage _storage;
  bool _configured = false;

  Future<void> configure(
    ApiClient client, {
    required void Function(Map<String, String> data) onOpened,
  }) async {
    if (_configured || client.profile.value == null) return;
    try {
      await Firebase.initializeApp();
      final messaging = FirebaseMessaging.instance;
      final permission = await messaging.requestPermission(
        alert: true,
        badge: true,
        sound: true,
      );
      if (permission.authorizationStatus == AuthorizationStatus.denied) return;
      final token = await messaging.getToken();
      if (token == null || token.isEmpty) return;
      await _register(client, token);
      FirebaseMessaging.onMessageOpenedApp.listen(
        (message) => onOpened(_data(message)),
      );
      final launchMessage = await messaging.getInitialMessage();
      if (launchMessage != null) onOpened(_data(launchMessage));
      _configured = true;
    } catch (_) {
      // Firebase credentials are environment-specific; notifications remain optional until configured.
    }
  }

  Future<void> _register(ApiClient client, String token) async {
    var deviceId = await _storage.read(key: 'push_device_id');
    if (deviceId == null) {
      final random = Random.secure();
      deviceId = base64Url.encode(
        List.generate(24, (_) => random.nextInt(256)),
      );
      await _storage.write(key: 'push_device_id', value: deviceId);
    }
    await client.request(
      'POST',
      'notifications/devices',
      body: {
        'device_id': deviceId,
        'platform': Platform.isIOS ? 'ios' : 'android',
        'provider': 'fcm',
        'push_token': token,
        'device_name': Platform.localHostname,
        'app_version': '1.0.4+4',
        'timezone': DateTime.now().timeZoneName,
        'capabilities': ['push_notifications', 'loan_deep_links'],
      },
    );
  }

  Map<String, String> _data(RemoteMessage message) =>
      message.data.map((key, value) => MapEntry(key, value.toString()));
}
