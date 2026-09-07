import 'package:flutter_test/flutter_test.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';

import 'package:mobile/main.dart';

void main() {
  testWidgets('renders the DigitoFina login flow', (WidgetTester tester) async {
    FlutterSecureStorage.setMockInitialValues({});
    await tester.pumpWidget(const DigitoFinaApp());
    await tester.pumpAndSettle();

    expect(find.text('DigitoFina'), findsOneWidget);
    expect(find.text('Sign in'), findsOneWidget);
    expect(find.textContaining('Registration is managed'), findsOneWidget);
  });
}
