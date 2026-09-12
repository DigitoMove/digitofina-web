import 'package:flutter/material.dart';
import '../../core/network/api_client.dart';

class ImpersonationPicker extends StatefulWidget {
  const ImpersonationPicker({
    super.key,
    required this.client,
    required this.onSelected,
  });
  final ApiClient client;
  final ValueChanged<Map<String, dynamic>> onSelected;

  @override
  State<ImpersonationPicker> createState() => _ImpersonationPickerState();
}

class _ImpersonationPickerState extends State<ImpersonationPicker> {
  final search = TextEditingController();
  bool cashiersOnly = true;
  bool selecting = false;
  int page = 1;
  late Future<Map<String, dynamic>> results;

  @override
  void initState() {
    super.initState();
    results = fetch();
  }

  @override
  void dispose() {
    search.dispose();
    super.dispose();
  }

  Future<Map<String, dynamic>> fetch() async => Map<String, dynamic>.from(
    (await widget.client.get(
          'admin/impersonation/users',
          query: {
            'search': search.text.trim(),
            'page': '$page',
            if (cashiersOnly) 'role': 'teller-cashier',
          },
        ))['data']
        as Map,
  );

  void reload({int nextPage = 1}) {
    setState(() {
      page = nextPage;
      results = fetch();
    });
  }

  Future<void> select(Map<String, dynamic> user) async {
    setState(() => selecting = true);
    try {
      final profile = await widget.client.impersonate(user);
      if (mounted) widget.onSelected(profile);
    } catch (error) {
      if (mounted) {
        ScaffoldMessenger.of(
          context,
        ).showSnackBar(SnackBar(content: Text('$error')));
      }
    } finally {
      if (mounted) setState(() => selecting = false);
    }
  }

  @override
  Widget build(BuildContext context) => Scaffold(
    appBar: AppBar(title: const Text('Act as a user')),
    body: Column(
      children: [
        const Padding(
          padding: EdgeInsets.all(16),
          child: Text(
            'Use their workspace and permissions. Actions affect real records and are attributed to your Super Admin account.',
          ),
        ),
        Padding(
          padding: const EdgeInsets.symmetric(horizontal: 16),
          child: TextField(
            controller: search,
            enabled: !selecting,
            onSubmitted: (_) => reload(),
            decoration: InputDecoration(
              labelText: 'Name, email or organization',
              suffixIcon: IconButton(
                onPressed: selecting ? null : () => reload(),
                icon: const Icon(Icons.search),
              ),
            ),
          ),
        ),
        SwitchListTile(
          title: const Text('Cashiers only'),
          value: cashiersOnly,
          onChanged: selecting
              ? null
              : (value) {
                  cashiersOnly = value;
                  reload();
                },
        ),
        if (selecting) const LinearProgressIndicator(),
        Expanded(
          child: FutureBuilder<Map<String, dynamic>>(
            future: results,
            builder: (context, snapshot) {
              if (snapshot.connectionState == ConnectionState.waiting) {
                return const Center(child: CircularProgressIndicator());
              }
              if (snapshot.hasError) {
                return Center(
                  child: Column(
                    mainAxisSize: MainAxisSize.min,
                    children: [
                      Text('${snapshot.error}'),
                      TextButton(
                        onPressed: () => reload(),
                        child: const Text('Retry'),
                      ),
                    ],
                  ),
                );
              }
              final users = (snapshot.data?['users'] as List? ?? [])
                  .cast<Map>();
              if (users.isEmpty) {
                return const Center(
                  child: Text(
                    'No active users found. Try all users or another search.',
                  ),
                );
              }
              return ListView(
                children: [
                  ...users.map(
                    (user) => ListTile(
                      title: Text(user['name'].toString()),
                      subtitle: Text(
                        [
                          user['role_name'],
                          user['organization'],
                          user['branch'],
                          user['email'],
                        ].whereType<String>().join(' · '),
                      ),
                      trailing: const Icon(Icons.chevron_right),
                      enabled: !selecting,
                      onTap: () => select(Map<String, dynamic>.from(user)),
                    ),
                  ),
                  Row(
                    mainAxisAlignment: MainAxisAlignment.center,
                    children: [
                      TextButton(
                        onPressed: !selecting && page > 1
                            ? () => reload(nextPage: page - 1)
                            : null,
                        child: const Text('Previous'),
                      ),
                      Text('Page $page'),
                      TextButton(
                        onPressed:
                            !selecting &&
                                page < (snapshot.data!['last_page'] as int)
                            ? () => reload(nextPage: page + 1)
                            : null,
                        child: const Text('Next'),
                      ),
                    ],
                  ),
                ],
              );
            },
          ),
        ),
      ],
    ),
  );
}
