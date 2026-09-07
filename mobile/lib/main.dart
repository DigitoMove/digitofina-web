import 'package:flutter/material.dart';
import 'package:flutter_svg/flutter_svg.dart';

import 'core/config/api_config.dart';
import 'core/network/api_client.dart';
import 'features/admin/admin_workspace.dart';

void main() => runApp(const DigitoFinaApp());

class DigitoFinaApp extends StatelessWidget {
  const DigitoFinaApp({super.key});

  @override
  Widget build(BuildContext context) => MaterialApp(
    title: 'DigitoFina',
    debugShowCheckedModeBanner: false,
    theme: ThemeData(
      colorScheme: ColorScheme.fromSeed(seedColor: const Color(0xff7e22ce)),
      scaffoldBackgroundColor: const Color(0xfffaf7fd),
      useMaterial3: true,
      inputDecorationTheme: const InputDecorationTheme(
        filled: true,
        fillColor: Colors.white,
        border: OutlineInputBorder(),
      ),
    ),
    home: SessionGate(client: ApiClient(config: ApiConfig.fromEnvironment())),
  );
}

Widget workspace(
  BuildContext context,
  Map<String, dynamic> user,
  ApiClient client,
) {
  if (['super-admin', 'organization-admin'].contains(user['role'])) {
    return AdminWorkspace(
      client: client,
      user: user,
      onSignedOut: () {
        Navigator.of(context).pushAndRemoveUntil(
          MaterialPageRoute<void>(builder: (_) => LoginPage(client: client)),
          (_) => false,
        );
      },
    );
  }
  return RoleDashboard(user: user, client: client);
}

class SessionGate extends StatefulWidget {
  const SessionGate({super.key, required this.client});
  final ApiClient client;
  @override
  State<SessionGate> createState() => _SessionGateState();
}

class _SessionGateState extends State<SessionGate> {
  late Future<Map<String, dynamic>?> session;
  @override
  void initState() {
    super.initState();
    session = restore();
  }

  Future<Map<String, dynamic>?> restore() async {
    if (!await widget.client.hasSession()) return null;
    try {
      return await widget.client.me();
    } on ApiException catch (error) {
      if (error.status == 401) return null;
      rethrow;
    }
  }

  @override
  Widget build(BuildContext context) => FutureBuilder<Map<String, dynamic>?>(
    future: session,
    builder: (context, snapshot) {
      if (snapshot.connectionState == ConnectionState.waiting) {
        return Scaffold(
          body: Center(
            child: Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                SvgPicture.asset(
                  'assets/brand/digitofina-mark.svg',
                  width: 96,
                  height: 96,
                ),
                const SizedBox(height: 24),
                const Text('Opening your workspace'),
                const SizedBox(height: 24),
                const CircularProgressIndicator(),
              ],
            ),
          ),
        );
      }
      if (snapshot.hasError) {
        return Scaffold(
          body: ApiErrorPanel(
            error: snapshot.error!,
            retry: () => setState(() => session = restore()),
            onExpired: () => setState(() => session = Future.value(null)),
          ),
        );
      }
      return snapshot.data == null
          ? LoginPage(client: widget.client)
          : workspace(context, snapshot.data!, widget.client);
    },
  );
}

class LoginPage extends StatefulWidget {
  const LoginPage({required this.client, super.key});

  final ApiClient client;

  @override
  State<LoginPage> createState() => _LoginPageState();
}

class _LoginPageState extends State<LoginPage> {
  final formKey = GlobalKey<FormState>();
  final loginController = TextEditingController();
  final passwordController = TextEditingController();
  bool busy = false;
  String? error;

  Future<void> signIn() async {
    if (!(formKey.currentState?.validate() ?? false)) return;
    setState(() {
      busy = true;
      error = null;
    });
    try {
      final user = await widget.client.login(
        loginController.text.trim(),
        passwordController.text,
      );
      if (mounted) {
        Navigator.of(context).pushReplacement(
          MaterialPageRoute<void>(
            builder: (context) => workspace(context, user, widget.client),
          ),
        );
      }
    } on ApiException catch (exception) {
      if (mounted) setState(() => error = exception.message);
    } catch (exception) {
      if (mounted) {
        setState(() => error = 'Unable to sign in. Please try again.');
      }
    } finally {
      if (mounted) setState(() => busy = false);
    }
  }

  @override
  void dispose() {
    loginController.dispose();
    passwordController.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) => Scaffold(
    body: SafeArea(
      child: Center(
        child: SingleChildScrollView(
          padding: const EdgeInsets.all(24),
          child: ConstrainedBox(
            constraints: const BoxConstraints(maxWidth: 440),
            child: Form(
              key: formKey,
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  SvgPicture.asset(
                    'assets/brand/digitofina-mark.svg',
                    height: 112,
                    width: 112,
                  ),
                  const SizedBox(height: 16),
                  Text(
                    'DigitoFina',
                    style: Theme.of(context).textTheme.headlineMedium,
                    textAlign: TextAlign.center,
                  ),
                  const SizedBox(height: 8),
                  const Text(
                    'Secure financial management',
                    textAlign: TextAlign.center,
                  ),
                  const SizedBox(height: 32),
                  TextFormField(
                    controller: loginController,
                    decoration: const InputDecoration(
                      labelText: 'Email or phone',
                      border: OutlineInputBorder(),
                    ),
                    validator: (value) => value == null || value.isEmpty
                        ? 'Enter your email or phone'
                        : null,
                  ),
                  const SizedBox(height: 16),
                  TextFormField(
                    controller: passwordController,
                    obscureText: true,
                    decoration: const InputDecoration(
                      labelText: 'Password',
                      border: OutlineInputBorder(),
                    ),
                    validator: (value) => value == null || value.isEmpty
                        ? 'Enter your password'
                        : null,
                  ),
                  if (error != null)
                    Padding(
                      padding: const EdgeInsets.only(top: 16),
                      child: Text(
                        error!,
                        style: TextStyle(
                          color: Theme.of(context).colorScheme.error,
                        ),
                      ),
                    ),
                  const SizedBox(height: 24),
                  FilledButton(
                    onPressed: busy ? null : signIn,
                    child: Text(busy ? 'Signing in…' : 'Sign in'),
                  ),
                  const SizedBox(height: 12),
                  const Text(
                    'Registration is managed by your organization administrator.',
                    textAlign: TextAlign.center,
                  ),
                ],
              ),
            ),
          ),
        ),
      ),
    ),
  );
}

class RoleDashboard extends StatelessWidget {
  const RoleDashboard({required this.user, required this.client, super.key});

  final Map<String, dynamic> user;
  final ApiClient client;

  @override
  Widget build(BuildContext context) {
    final role = user['role']?.toString() ?? 'user';
    final isClient = role == 'member';
    final title = switch (role) {
      'super-admin' => 'Super Admin',
      'organization-admin' => 'Organization Admin',
      'member' => 'My finances',
      _ => 'Staff workspace',
    };
    final modules = isClient
        ? ['My accounts', 'My loans', 'Statements', 'Support']
        : [
            'Clients',
            'Savings and accounts',
            'Loans and repayments',
            'Approvals',
            'Reports',
          ];
    return Scaffold(
      appBar: AppBar(
        title: Row(
          children: [
            SvgPicture.asset('assets/brand/digitofina-mark.svg', height: 30),
            const SizedBox(width: 8),
            Text(title),
          ],
        ),
        actions: [
          IconButton(
            onPressed: () async {
              await client.logout();
              if (context.mounted) {
                Navigator.of(context).pushAndRemoveUntil(
                  MaterialPageRoute<void>(
                    builder: (_) => LoginPage(client: client),
                  ),
                  (_) => false,
                );
              }
            },
            icon: const Icon(Icons.logout),
          ),
        ],
      ),
      body: FutureBuilder<Map<String, dynamic>>(
        future: isClient ? null : client.dashboard(),
        builder: (context, snapshot) => ListView(
          padding: const EdgeInsets.all(20),
          children: [
            Text(
              'Welcome, ${user['name'] ?? 'User'}',
              style: Theme.of(context).textTheme.headlineSmall,
            ),
            const SizedBox(height: 8),
            Text(
              role.replaceAll('-', ' ').toUpperCase(),
              style: Theme.of(context).textTheme.labelLarge,
            ),
            const SizedBox(height: 24),
            if (snapshot.connectionState == ConnectionState.waiting)
              const Center(child: CircularProgressIndicator()),
            if (snapshot.hasError)
              Text(
                'Live summary unavailable. Your permitted modules remain available below.',
                style: TextStyle(color: Theme.of(context).colorScheme.error),
              ),
            if (snapshot.hasData)
              Wrap(
                spacing: 12,
                runSpacing: 12,
                children: [
                  for (final entry in snapshot.data!.entries)
                    if (entry.value is num)
                      _MetricCard(
                        label: entry.key.replaceAll('_', ' '),
                        value: entry.value.toString(),
                      ),
                ],
              ),
            const SizedBox(height: 20),
            for (final module in modules)
              Card(
                child: ListTile(
                  leading: const Icon(Icons.arrow_forward_ios, size: 18),
                  title: Text(module),
                  subtitle: const Text(
                    'Module access follows your Laravel permissions',
                  ),
                ),
              ),
          ],
        ),
      ),
    );
  }
}

class _MetricCard extends StatelessWidget {
  const _MetricCard({required this.label, required this.value});

  final String label;
  final String value;

  @override
  Widget build(BuildContext context) => SizedBox(
    width: 155,
    child: Card(
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(label, style: Theme.of(context).textTheme.labelMedium),
            const SizedBox(height: 8),
            Text(value, style: Theme.of(context).textTheme.titleLarge),
          ],
        ),
      ),
    ),
  );
}
