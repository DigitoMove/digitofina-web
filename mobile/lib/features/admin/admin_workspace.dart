import 'package:flutter/material.dart';
import 'package:flutter_svg/flutter_svg.dart';
import '../../core/network/api_client.dart';
import '../operations/operations_hub.dart';
import '../roles/role_workspaces.dart';

class AdminWorkspace extends StatefulWidget {
  const AdminWorkspace({
    super.key,
    required this.client,
    required this.user,
    required this.onSignedOut,
    this.onImpersonate,
  });
  final ApiClient client;
  final Map<String, dynamic> user;
  final VoidCallback onSignedOut;
  final VoidCallback? onImpersonate;
  @override
  State<AdminWorkspace> createState() => _AdminWorkspaceState();
}

class _AdminWorkspaceState extends State<AdminWorkspace> {
  int? organizationId;
  late Future<Map<String, dynamic>> summary;
  bool signingOut = false;
  bool get isSuper => widget.user['role'] == 'super-admin';
  Map<String, String> get query => {
    if (organizationId != null) 'organization_id': organizationId.toString(),
  };

  @override
  void initState() {
    super.initState();
    organizationId = widget.client.organizationId;
    summary = fetch();
  }

  Future<Map<String, dynamic>> fetch() async => Map<String, dynamic>.from(
    (await widget.client.get('admin/dashboard', query: query))['data'] as Map,
  );
  Future<void> refresh() async {
    await widget.client.me();
    final next = fetch();
    setState(() => summary = next);
    await next;
  }

  void open(Map item) {
    Navigator.of(context).push(
      MaterialPageRoute<void>(
        builder: (_) => AdminDirectory(
          client: widget.client,
          section: item['key'].toString(),
          label: item['label'].toString(),
          organizationId: organizationId,
          onExpired: widget.onSignedOut,
        ),
      ),
    );
  }

  Future<void> selectOrganization() async {
    final selected = await Navigator.of(context).push<int>(
      MaterialPageRoute(
        builder: (_) => AdminDirectory(
          client: widget.client,
          section: 'organizations',
          label: 'Choose organization',
          selectOrganization: true,
          onExpired: widget.onSignedOut,
        ),
      ),
    );
    if (!mounted || selected == null) return;
    try {
      await widget.client.selectOrganization(selected);
    } on ApiException catch (error) {
      if (mounted) {
        ScaffoldMessenger.of(
          context,
        ).showSnackBar(SnackBar(content: Text(error.message)));
      }
      return;
    }
    if (!mounted) return;
    setState(() {
      organizationId = selected;
      summary = fetch();
    });
  }

  Future<void> logout() async {
    if (signingOut) return;
    setState(() => signingOut = true);
    try {
      await widget.client.logout();
    } catch (_) {
      // Local credentials are removed by the client even when revocation fails.
    }
    if (mounted) widget.onSignedOut();
  }

  @override
  Widget build(BuildContext context) => FutureBuilder<Map<String, dynamic>>(
    future: summary,
    builder: (context, snapshot) {
      final data = snapshot.data;
      final navigation = (data?['navigation'] as List? ?? []).cast<Map>();
      final scopeName =
          (data?['scope'] as Map?)?['name']?.toString() ??
          (isSuper
              ? 'All organizations'
              : (widget.user['organization'] as Map?)?['name']?.toString() ??
                    'Your organization');
      return Scaffold(
        appBar: AppBar(
          title: const BrandTitle(title: 'DigitoFina'),
          actions: [
            IconButton(
              tooltip: 'Operations',
              onPressed: () => Navigator.of(context).push(
                MaterialPageRoute<void>(
                  builder: (_) =>
                      OperationsHub(client: widget.client, user: widget.user),
                ),
              ),
              icon: const Icon(Icons.point_of_sale_outlined),
            ),
            if (widget.onImpersonate != null)
              IconButton(
                tooltip: 'Act as a user',
                onPressed: widget.onImpersonate,
                icon: const Icon(Icons.switch_account),
              ),
            if (!isSuper)
              IconButton(
                tooltip: 'Loan approvals and reports',
                onPressed: () => Navigator.of(context).push(
                  MaterialPageRoute<void>(
                    builder: (_) => OrganizationAdminTools(
                      client: widget.client,
                      user: widget.user,
                      onSignedOut: widget.onSignedOut,
                    ),
                  ),
                ),
                icon: const Icon(Icons.analytics_outlined),
              ),
            IconButton(
              tooltip: 'Refresh',
              onPressed: () {
                refresh().catchError((_) {});
              },
              icon: const Icon(Icons.refresh),
            ),
            IconButton(
              tooltip: 'Sign out',
              onPressed: signingOut ? null : logout,
              icon: const Icon(Icons.logout),
            ),
          ],
        ),
        drawer: Drawer(
          child: SafeArea(
            child: ListView(
              children: [
                Padding(
                  padding: const EdgeInsets.all(20),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      const BrandTitle(title: 'Workspace'),
                      const SizedBox(height: 16),
                      Text(
                        widget.user['name']?.toString() ?? 'Administrator',
                        style: Theme.of(context).textTheme.titleLarge,
                      ),
                      Text(scopeName),
                    ],
                  ),
                ),
                ListTile(
                  leading: const Icon(Icons.dashboard_outlined),
                  title: const Text('Dashboard'),
                  selected: true,
                  onTap: () => Navigator.pop(context),
                ),
                for (final group
                    in navigation.map((e) => e['group']).toSet()) ...[
                  Padding(
                    padding: const EdgeInsets.fromLTRB(20, 20, 20, 8),
                    child: Text(
                      group.toString(),
                      style: Theme.of(context).textTheme.labelLarge,
                    ),
                  ),
                  for (final item in navigation.where(
                    (e) => e['group'] == group,
                  ))
                    ListTile(
                      leading: Icon(sectionIcon(item['key'].toString())),
                      title: Text(item['label'].toString()),
                      onTap: () {
                        Navigator.pop(context);
                        open(item);
                      },
                    ),
                ],
              ],
            ),
          ),
        ),
        body: snapshot.connectionState == ConnectionState.waiting
            ? const Center(child: CircularProgressIndicator())
            : snapshot.hasError
            ? ApiErrorPanel(
                error: snapshot.error!,
                retry: () {
                  refresh().catchError((_) {});
                },
                onExpired: widget.onSignedOut,
              )
            : RefreshIndicator(
                onRefresh: () async {
                  try {
                    await refresh();
                  } catch (_) {}
                },
                child: ListView(
                  physics: const AlwaysScrollableScrollPhysics(),
                  padding: const EdgeInsets.all(20),
                  children: [
                    Container(
                      padding: const EdgeInsets.all(24),
                      decoration: BoxDecoration(
                        gradient: const LinearGradient(
                          colors: [Color(0xff3b0764), Color(0xff7e22ce)],
                        ),
                        borderRadius: BorderRadius.circular(24),
                      ),
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text(
                            isSuper
                                ? 'Super Admin Dashboard'
                                : 'Organization Dashboard',
                            style: const TextStyle(
                              color: Colors.white,
                              fontSize: 24,
                              fontWeight: FontWeight.w700,
                            ),
                          ),
                          const SizedBox(height: 8),
                          Text(
                            'Welcome, ${widget.user['name']?.toString() ?? 'Administrator'}',
                            style: const TextStyle(color: Colors.white70),
                          ),
                          const SizedBox(height: 20),
                          Text(
                            scopeName,
                            style: const TextStyle(
                              color: Colors.white,
                              fontWeight: FontWeight.w600,
                            ),
                          ),
                          if (isSuper)
                            Wrap(
                              spacing: 8,
                              children: [
                                TextButton(
                                  onPressed: selectOrganization,
                                  child: const Text(
                                    'Choose organization',
                                    style: TextStyle(color: Colors.white),
                                  ),
                                ),
                                if (organizationId != null)
                                  TextButton(
                                    onPressed: () async {
                                      try {
                                        await widget.client.selectOrganization(
                                          null,
                                        );
                                      } on ApiException catch (error) {
                                        if (context.mounted) {
                                          ScaffoldMessenger.of(
                                            context,
                                          ).showSnackBar(
                                            SnackBar(
                                              content: Text(error.message),
                                            ),
                                          );
                                        }
                                        return;
                                      }
                                      if (!mounted) return;
                                      setState(() {
                                        organizationId = null;
                                        summary = fetch();
                                      });
                                    },
                                    child: const Text(
                                      'Global view',
                                      style: TextStyle(color: Colors.white),
                                    ),
                                  ),
                              ],
                            ),
                        ],
                      ),
                    ),
                    const SizedBox(height: 24),
                    Text(
                      'Overview',
                      style: Theme.of(context).textTheme.titleLarge,
                    ),
                    const SizedBox(height: 12),
                    LayoutBuilder(
                      builder: (context, constraints) => Wrap(
                        spacing: 12,
                        runSpacing: 12,
                        children: [
                          for (final metric in data?['metrics'] as List? ?? [])
                            SizedBox(
                              width: constraints.maxWidth > 600
                                  ? (constraints.maxWidth - 24) / 3
                                  : (constraints.maxWidth - 12) / 2,
                              child: Card(
                                margin: EdgeInsets.zero,
                                clipBehavior: Clip.antiAlias,
                                child: InkWell(
                                  borderRadius: BorderRadius.circular(12),
                                  onTap: () => open(
                                    navigation.firstWhere(
                                      (e) => e['key'] == metric['section'],
                                    ),
                                  ),
                                  child: Container(
                                    decoration: BoxDecoration(
                                      border: Border(
                                        top: BorderSide(
                                          color: metricColor(
                                            metric['section'].toString(),
                                          ),
                                          width: 5,
                                        ),
                                      ),
                                    ),
                                    padding: const EdgeInsets.all(16),
                                    child: Column(
                                      crossAxisAlignment:
                                          CrossAxisAlignment.start,
                                      children: [
                                        CircleAvatar(
                                          backgroundColor: metricColor(
                                            metric['section'].toString(),
                                          ).withValues(alpha: .12),
                                          foregroundColor: metricColor(
                                            metric['section'].toString(),
                                          ),
                                          child: Icon(
                                            sectionIcon(
                                              metric['section'].toString(),
                                            ),
                                          ),
                                        ),
                                        const SizedBox(height: 12),
                                        Text(metric['label'].toString()),
                                        const SizedBox(height: 6),
                                        Text(
                                          formatValue(
                                            metric['value'],
                                            money: metric['money'] == true,
                                          ),
                                          style: Theme.of(
                                            context,
                                          ).textTheme.titleLarge,
                                        ),
                                        if (metric['money'] == true)
                                          Text(
                                            (data?['scope']
                                                        as Map?)?['currency']
                                                    ?.toString() ??
                                                'UGX',
                                          ),
                                      ],
                                    ),
                                  ),
                                ),
                              ),
                            ),
                        ],
                      ),
                    ),
                    const SizedBox(height: 28),
                    Text(
                      'Workspace',
                      style: Theme.of(context).textTheme.titleLarge,
                    ),
                    for (final item in navigation)
                      Card(
                        child: ListTile(
                          leading: Icon(sectionIcon(item['key'].toString())),
                          title: Text(item['label'].toString()),
                          subtitle: Text(item['group'].toString()),
                          trailing: const Icon(Icons.chevron_right),
                          onTap: () => open(item),
                        ),
                      ),
                    const SizedBox(height: 24),
                    Text(
                      'Recent organizations',
                      style: Theme.of(context).textTheme.titleLarge,
                    ),
                    for (final record
                        in data?['recent_organizations'] as List? ?? [])
                      RecordTile(
                        record: record as Map,
                        onTap: () => Navigator.of(context).push(
                          MaterialPageRoute<void>(
                            builder: (_) => AdminDetail(
                              client: widget.client,
                              section: 'organizations',
                              id: record['id'] as int,
                              organizationId: organizationId,
                              onExpired: widget.onSignedOut,
                            ),
                          ),
                        ),
                      ),
                  ],
                ),
              ),
      );
    },
  );
}

class AdminDirectory extends StatefulWidget {
  const AdminDirectory({
    super.key,
    required this.client,
    required this.section,
    required this.label,
    required this.onExpired,
    this.organizationId,
    this.selectOrganization = false,
  });
  final ApiClient client;
  final String section;
  final String label;
  final int? organizationId;
  final bool selectOrganization;
  final VoidCallback onExpired;
  @override
  State<AdminDirectory> createState() => _AdminDirectoryState();
}

class _AdminDirectoryState extends State<AdminDirectory> {
  final search = TextEditingController();
  int page = 1;
  late Future<Map<String, dynamic>> records;
  @override
  void initState() {
    super.initState();
    records = fetch();
  }

  Future<Map<String, dynamic>> fetch() => widget.client.get(
    'admin/records/${widget.section}',
    query: {
      'page': page.toString(),
      'search': search.text.trim(),
      if (widget.organizationId != null)
        'organization_id': widget.organizationId.toString(),
    },
  );
  void reload({int? atPage}) {
    setState(() {
      page = atPage ?? page;
      records = fetch();
    });
  }

  @override
  void dispose() {
    search.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) => Scaffold(
    appBar: AppBar(title: BrandTitle(title: widget.label)),
    body: Column(
      children: [
        Padding(
          padding: const EdgeInsets.all(16),
          child: TextField(
            controller: search,
            textInputAction: TextInputAction.search,
            onSubmitted: (_) => reload(atPage: 1),
            decoration: InputDecoration(
              hintText: 'Search ${widget.label.toLowerCase()}',
              prefixIcon: const Icon(Icons.search),
              suffixIcon: IconButton(
                tooltip: 'Search',
                onPressed: () => reload(atPage: 1),
                icon: const Icon(Icons.arrow_forward),
              ),
            ),
          ),
        ),
        Expanded(
          child: FutureBuilder<Map<String, dynamic>>(
            future: records,
            builder: (context, snapshot) {
              if (snapshot.connectionState == ConnectionState.waiting) {
                return const Center(child: CircularProgressIndicator());
              }
              if (snapshot.hasError) {
                return ApiErrorPanel(
                  error: snapshot.error!,
                  retry: reload,
                  onExpired: widget.onExpired,
                );
              }
              final rows = snapshot.data?['data'] as List? ?? [];
              final pagination =
                  (snapshot.data?['meta'] as Map?)?['pagination'] as Map? ?? {};
              return Column(
                children: [
                  Expanded(
                    child: RefreshIndicator(
                      onRefresh: () async {
                        reload();
                        try {
                          await records;
                        } catch (_) {}
                      },
                      child: ListView(
                        physics: const AlwaysScrollableScrollPhysics(),
                        padding: const EdgeInsets.symmetric(horizontal: 16),
                        children: [
                          if (rows.isEmpty)
                            const Padding(
                              padding: EdgeInsets.all(40),
                              child: Text(
                                'No matching records.',
                                textAlign: TextAlign.center,
                              ),
                            ),
                          for (final row in rows)
                            RecordTile(
                              record: row as Map,
                              onTap: () {
                                if (widget.selectOrganization) {
                                  Navigator.pop(context, row['id'] as int);
                                  return;
                                }
                                Navigator.push(
                                  context,
                                  MaterialPageRoute<void>(
                                    builder: (_) => AdminDetail(
                                      client: widget.client,
                                      section: widget.section,
                                      id: row['id'] as int,
                                      organizationId: widget.organizationId,
                                      onExpired: widget.onExpired,
                                    ),
                                  ),
                                );
                              },
                            ),
                        ],
                      ),
                    ),
                  ),
                  SafeArea(
                    top: false,
                    child: Padding(
                      padding: const EdgeInsets.all(12),
                      child: Row(
                        children: [
                          IconButton(
                            tooltip: 'Previous page',
                            onPressed: page > 1
                                ? () => reload(atPage: page - 1)
                                : null,
                            icon: const Icon(Icons.chevron_left),
                          ),
                          Expanded(
                            child: Text(
                              'Page $page · ${pagination['total'] ?? rows.length} records',
                              textAlign: TextAlign.center,
                            ),
                          ),
                          IconButton(
                            tooltip: 'Next page',
                            onPressed:
                                page < (pagination['last_page'] as int? ?? 1)
                                ? () => reload(atPage: page + 1)
                                : null,
                            icon: const Icon(Icons.chevron_right),
                          ),
                        ],
                      ),
                    ),
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

class AdminDetail extends StatefulWidget {
  const AdminDetail({
    super.key,
    required this.client,
    required this.section,
    required this.id,
    required this.onExpired,
    this.organizationId,
  });
  final ApiClient client;
  final String section;
  final int id;
  final int? organizationId;
  final VoidCallback onExpired;
  @override
  State<AdminDetail> createState() => _AdminDetailState();
}

class _AdminDetailState extends State<AdminDetail> {
  late Future<Map<String, dynamic>> record;
  @override
  void initState() {
    super.initState();
    record = fetch();
  }

  Future<Map<String, dynamic>> fetch() => widget.client.get(
    'admin/records/${widget.section}/${widget.id}',
    query: {
      if (widget.organizationId != null)
        'organization_id': widget.organizationId.toString(),
    },
  );
  @override
  Widget build(BuildContext context) => Scaffold(
    appBar: AppBar(title: const BrandTitle(title: 'Details')),
    body: FutureBuilder<Map<String, dynamic>>(
      future: record,
      builder: (context, snapshot) {
        if (snapshot.connectionState == ConnectionState.waiting) {
          return const Center(child: CircularProgressIndicator());
        }
        if (snapshot.hasError) {
          return ApiErrorPanel(
            error: snapshot.error!,
            retry: () => setState(() => record = fetch()),
            onExpired: widget.onExpired,
          );
        }
        final data = snapshot.data!['data'] as Map;
        return ListView(
          padding: const EdgeInsets.all(20),
          children: [
            Text(
              data['title'].toString(),
              style: Theme.of(context).textTheme.headlineSmall,
            ),
            const SizedBox(height: 20),
            for (final related in [
              'organization',
              'branch',
              'role',
              'package',
              'user',
            ])
              if (data[related] is Map)
                ListTile(
                  title: Text(heading(related)),
                  subtitle: Text(data[related]['name'].toString()),
                ),
            for (final entry in (data['fields'] as Map).entries)
              ListTile(
                title: Text(heading(entry.key.toString())),
                subtitle: SelectableText(entry.value.toString()),
              ),
            if (data['permissions'] is List) ...[
              Text(
                'Permissions',
                style: Theme.of(context).textTheme.titleLarge,
              ),
              for (final permission in data['permissions'] as List)
                ListTile(
                  leading: const Icon(Icons.check_circle_outline),
                  title: Text(permission.toString()),
                ),
            ],
          ],
        );
      },
    ),
  );
}

class BrandTitle extends StatelessWidget {
  const BrandTitle({super.key, required this.title});
  final String title;
  @override
  Widget build(BuildContext context) => Row(
    children: [
      SvgPicture.asset(
        'assets/brand/digitofina-mark.svg',
        width: 32,
        height: 32,
      ),
      const SizedBox(width: 10),
      Expanded(
        child: Text(title, maxLines: 1, overflow: TextOverflow.ellipsis),
      ),
    ],
  );
}

class ApiErrorPanel extends StatelessWidget {
  const ApiErrorPanel({
    super.key,
    required this.error,
    required this.retry,
    required this.onExpired,
  });
  final Object error;
  final VoidCallback retry;
  final VoidCallback onExpired;
  @override
  Widget build(BuildContext context) {
    final expired =
        error is ApiException && (error as ApiException).status == 401;
    final missing =
        error is ApiException && (error as ApiException).status == 404;
    return Center(
      child: Padding(
        padding: const EdgeInsets.all(24),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            const Icon(Icons.cloud_off_outlined, size: 40),
            const SizedBox(height: 16),
            Text(
              expired
                  ? 'Your session has expired. Please sign in again.'
                  : missing
                  ? 'This record or service is unavailable.'
                  : error.toString(),
              textAlign: TextAlign.center,
            ),
            const SizedBox(height: 16),
            FilledButton(
              onPressed: expired ? onExpired : retry,
              child: Text(expired ? 'Sign in' : 'Retry'),
            ),
          ],
        ),
      ),
    );
  }
}

class RecordTile extends StatelessWidget {
  const RecordTile({super.key, required this.record, required this.onTap});
  final Map record;
  final VoidCallback onTap;
  @override
  Widget build(BuildContext context) => Card(
    child: ListTile(
      title: Text(record['title']?.toString() ?? 'Record'),
      subtitle: Text(
        [
          if (record['organization'] is Map) record['organization']['name'],
          record['status'],
        ].where((e) => e != null).join(' · '),
      ),
      trailing: const Icon(Icons.chevron_right),
      onTap: onTap,
    ),
  );
}

IconData sectionIcon(String key) => switch (key) {
  'organizations' => Icons.business_outlined,
  'users' => Icons.people_outline,
  'branches' => Icons.account_tree_outlined,
  'roles' => Icons.admin_panel_settings_outlined,
  'modules' => Icons.widgets_outlined,
  'packages' => Icons.inventory_2_outlined,
  'quotes' => Icons.receipt_long_outlined,
  'activity' => Icons.monitor_heart_outlined,
  'clients' => Icons.person_outline,
  'loans' => Icons.payments_outlined,
  'accounts' => Icons.account_balance_wallet_outlined,
  'client-groups' => Icons.groups_outlined,
  'loan-products' => Icons.description_outlined,
  'drawers' => Icons.point_of_sale_outlined,
  'transactions' => Icons.search,
  'savings-products' => Icons.savings_outlined,
  'ledger-accounts' => Icons.menu_book_outlined,
  'investments' => Icons.trending_up,
  _ => Icons.dashboard_outlined,
};

Color metricColor(String key) => switch (key) {
  'organizations' => const Color(0xff7e22ce),
  'users' => const Color(0xff0284c7),
  'branches' => const Color(0xff475569),
  'clients' => const Color(0xff0891b2),
  'loans' => const Color(0xffd97706),
  'accounts' => const Color(0xff059669),
  'investments' => const Color(0xff4f46e5),
  _ => const Color(0xff7e22ce),
};
String heading(String value) => value
    .split('_')
    .map(
      (word) => word.isEmpty ? word : word[0].toUpperCase() + word.substring(1),
    )
    .join(' ');
String formatValue(dynamic value, {bool money = false}) {
  final number = num.tryParse(value.toString()) ?? 0;
  final parts = number.toStringAsFixed(money ? 2 : 0).split('.');
  final whole = parts[0].replaceAllMapped(
    RegExp(r'(\d)(?=(\d{3})+(?!\d))'),
    (m) => '${m[1]!},',
  );
  return money ? '$whole.${parts[1]}' : whole;
}
