import 'package:flutter/material.dart';

import '../../core/network/api_client.dart';
import '../admin/admin_workspace.dart';

class RecoveryWorkspace extends StatefulWidget {
  const RecoveryWorkspace({super.key, required this.client, required this.user, required this.onSignedOut});
  final ApiClient client;
  final Map<String, dynamic> user;
  final VoidCallback onSignedOut;

  @override
  State<RecoveryWorkspace> createState() => _RecoveryWorkspaceState();
}

class _RecoveryWorkspaceState extends State<RecoveryWorkspace> {
  int tab = 0;
  late Future<Map<String, dynamic>> arrears;
  final search = TextEditingController();
  int page = 1;
  bool signingOut = false;

  @override
  void initState() {
    super.initState();
    arrears = fetchArrears();
  }

  Future<Map<String, dynamic>> fetchArrears() => widget.client.get('loans/arrears/list', query: {'page': '$page', 'per_page': '30'});

  void reload({int? nextPage}) => setState(() {
    page = nextPage ?? page;
    arrears = fetchArrears();
  });

  Future<void> logout() async {
    if (signingOut) return;
    setState(() => signingOut = true);
    try { await widget.client.logout(); } catch (_) {}
    if (mounted) widget.onSignedOut();
  }

  @override
  void dispose() { search.dispose(); super.dispose(); }

  @override
  Widget build(BuildContext context) => Scaffold(
    appBar: AppBar(
      title: const BrandTitle(title: 'Loan recovery'),
      actions: [
        IconButton(onPressed: () => reload(), tooltip: 'Refresh', icon: const Icon(Icons.refresh)),
        IconButton(onPressed: signingOut ? null : logout, tooltip: 'Sign out', icon: const Icon(Icons.logout)),
      ],
    ),
    body: Column(children: [
      Padding(padding: const EdgeInsets.all(16), child: SegmentedButton<int>(
        segments: const [ButtonSegment(value: 0, label: Text('Arrears'), icon: Icon(Icons.warning_amber)), ButtonSegment(value: 1, label: Text('Clients'), icon: Icon(Icons.people_outline))],
        selected: {tab}, onSelectionChanged: (value) => setState(() => tab = value.first),
      )),
      Expanded(child: tab == 0 ? _ArrearsList(future: arrears, page: page, reload: reload, onExpired: widget.onSignedOut) : _RecoveryClients(client: widget.client, onExpired: widget.onSignedOut)),
    ]),
  );
}

class _ArrearsList extends StatelessWidget {
  const _ArrearsList({required this.future, required this.page, required this.reload, required this.onExpired});
  final Future<Map<String, dynamic>> future;
  final int page;
  final void Function({int? nextPage}) reload;
  final VoidCallback onExpired;

  @override
  Widget build(BuildContext context) => FutureBuilder<Map<String, dynamic>>(
    future: future,
    builder: (context, snapshot) {
      if (snapshot.connectionState == ConnectionState.waiting) return const Center(child: CircularProgressIndicator());
      if (snapshot.hasError) return ApiErrorPanel(error: snapshot.error!, retry: reload, onExpired: onExpired);
      final rows = (snapshot.data?['data'] as List? ?? []).cast<Map>();
      final pagination = (snapshot.data?['meta'] as Map?)?['pagination'] as Map? ?? {};
      return RefreshIndicator(
        onRefresh: () async { reload(); },
        child: ListView(padding: const EdgeInsets.all(16), children: [
          Text('Outstanding instalments', style: Theme.of(context).textTheme.headlineSmall),
          const SizedBox(height: 4),
          const Text('Contact clients, record follow-ups in your normal process, and direct payments to the cashier. This role can view arrears but cannot post or waive transactions.'),
          const SizedBox(height: 16),
          if (rows.isEmpty) const _RoleEmpty(message: 'No arrears are currently due.'),
          for (final row in rows) _ArrearCard(row: row),
          _RolePager(page: page, lastPage: int.tryParse(pagination['last_page']?.toString() ?? '') ?? page, previous: page > 1 ? () => reload(nextPage: page - 1) : null, next: page < (int.tryParse(pagination['last_page']?.toString() ?? '') ?? page) ? () => reload(nextPage: page + 1) : null),
        ]),
      );
    },
  );
}

class _ArrearCard extends StatelessWidget {
  const _ArrearCard({required this.row});
  final Map row;
  @override
  Widget build(BuildContext context) {
    final loan = row['loan'] as Map?;
    final client = loan?['client'] as Map?;
    return Card(child: ListTile(
      leading: CircleAvatar(backgroundColor: Colors.red.shade50, foregroundColor: Colors.red.shade800, child: const Icon(Icons.warning_amber)),
      title: Text(client?['name']?.toString() ?? loan?['loan_number']?.toString() ?? 'Loan account'),
      subtitle: Text('${client?['phone'] ?? client?['client_number'] ?? ''}\n${loan?['loan_number'] ?? ''} · Due ${row['due_date'] ?? ''} · ${row['days_overdue'] ?? 0} days overdue'),
      isThreeLine: true,
      trailing: Text('${formatRoleMoney(row['outstanding_amount'])} UGX', style: const TextStyle(fontWeight: FontWeight.w700, color: Colors.red)),
    ));
  }
}

class _RecoveryClients extends StatefulWidget {
  const _RecoveryClients({required this.client, required this.onExpired});
  final ApiClient client;
  final VoidCallback onExpired;
  @override
  State<_RecoveryClients> createState() => _RecoveryClientsState();
}

class _RecoveryClientsState extends State<_RecoveryClients> {
  final search = TextEditingController();
  int page = 1;
  late Future<Map<String, dynamic>> clients;
  @override
  void initState() { super.initState(); clients = fetch(); }
  Future<Map<String, dynamic>> fetch() => widget.client.get('clients', query: {'page': '$page', 'search': search.text.trim()});
  void reload({int nextPage = 1}) => setState(() { page = nextPage; clients = fetch(); });
  @override
  void dispose() { search.dispose(); super.dispose(); }
  @override
  Widget build(BuildContext context) => Column(children: [
    Padding(padding: const EdgeInsets.fromLTRB(16, 0, 16, 12), child: TextField(controller: search, onSubmitted: (_) => reload(), decoration: InputDecoration(labelText: 'Search client name, phone or number', prefixIcon: const Icon(Icons.search), suffixIcon: IconButton(onPressed: () => reload(), icon: const Icon(Icons.arrow_forward))))),
    Expanded(child: FutureBuilder<Map<String, dynamic>>(future: clients, builder: (context, snapshot) {
      if (snapshot.connectionState == ConnectionState.waiting) return const Center(child: CircularProgressIndicator());
      if (snapshot.hasError) return ApiErrorPanel(error: snapshot.error!, retry: () => reload(nextPage: page), onExpired: widget.onExpired);
      final rows = (snapshot.data?['data'] as List? ?? []).cast<Map>();
      final pagination = (snapshot.data?['meta'] as Map?)?['pagination'] as Map? ?? {};
      return ListView(padding: const EdgeInsets.symmetric(horizontal: 16), children: [
        if (rows.isEmpty) const _RoleEmpty(message: 'No clients found.'),
        for (final row in rows) Card(child: ListTile(leading: const Icon(Icons.person_outline), title: Text(row['name']?.toString() ?? row['client_number']?.toString() ?? 'Client'), subtitle: Text('${row['client_number'] ?? ''} · ${row['phone'] ?? ''} · ${row['status'] ?? ''}'), onTap: () => Navigator.of(context).push(MaterialPageRoute<void>(builder: (_) => AdminDetail(client: widget.client, section: 'clients', id: row['id'] as int, onExpired: widget.onExpired)))),
        _RolePager(page: page, lastPage: int.tryParse(pagination['last_page']?.toString() ?? '') ?? page, previous: page > 1 ? () => reload(nextPage: page - 1) : null, next: page < (int.tryParse(pagination['last_page']?.toString() ?? '') ?? page) ? () => reload(nextPage: page + 1) : null),
      ]);
    })),
  ]);
}

class OrganizationAdminTools extends StatefulWidget {
  const OrganizationAdminTools({super.key, required this.client, required this.user, required this.onSignedOut});
  final ApiClient client;
  final Map<String, dynamic> user;
  final VoidCallback onSignedOut;
  @override
  State<OrganizationAdminTools> createState() => _OrganizationAdminToolsState();
}

class _OrganizationAdminToolsState extends State<OrganizationAdminTools> {
  int tab = 0;
  late Future<Map<String, dynamic>> loans;
  late Future<Map<String, dynamic>> approvals;
  late Future<Map<String, dynamic>> reports;
  @override
  void initState() { super.initState(); refresh(); }
  void refresh() {
    loans = widget.client.get('loans', query: {'status_group': 'applications', 'per_page': '30'});
    approvals = widget.client.get('financial-controls/approvals', query: {'status': 'pending', 'per_page': '30'});
    reports = widget.client.get('reports/dashboard');
    if (mounted) setState(() {});
  }
  Future<void> approve(Map loan) async {
    final amount = TextEditingController(text: loan['requested_amount']?.toString() ?? loan['approved_amount']?.toString() ?? '');
    final comments = TextEditingController();
    final result = await showDialog<bool>(context: context, builder: (context) => AlertDialog(title: Text('Approve ${loan['loan_number']}'), content: Column(mainAxisSize: MainAxisSize.min, children: [TextField(controller: amount, keyboardType: TextInputType.number, decoration: const InputDecoration(labelText: 'Approved amount')), TextField(controller: comments, decoration: const InputDecoration(labelText: 'Comments'))]), actions: [TextButton(onPressed: () => Navigator.pop(context, false), child: const Text('Cancel')), FilledButton(onPressed: () => Navigator.pop(context, true), child: const Text('Approve'))]));
    if (result != true) { amount.dispose(); comments.dispose(); return; }
    try {
      await widget.client.request('POST', 'loans/${loan['id']}/approve', body: {'approved_amount': double.tryParse(amount.text), 'comments': comments.text.trim()});
      if (mounted) { ScaffoldMessenger.of(context).showSnackBar(const SnackBar(content: Text('Loan approval submitted.'))); refresh(); }
    } on ApiException catch (error) { if (mounted) ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(error.message))); }
    amount.dispose(); comments.dispose();
  }
  Future<void> decideApproval(Map item, bool approveRequest) async {
    try {
      await widget.client.request('POST', 'financial-controls/approvals/${item['id']}/${approveRequest ? 'approve' : 'reject'}', body: {'comments': approveRequest ? 'Approved from mobile.' : 'Rejected from mobile.'});
      if (mounted) { ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(approveRequest ? 'Approval completed.' : 'Approval rejected.'))); refresh(); }
    } on ApiException catch (error) { if (mounted) ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(error.message))); }
  }
  @override
  Widget build(BuildContext context) => Scaffold(appBar: AppBar(title: const BrandTitle(title: 'Admin controls'), actions: [IconButton(onPressed: refresh, icon: const Icon(Icons.refresh))]), body: Column(children: [Padding(padding: const EdgeInsets.all(16), child: SegmentedButton<int>(segments: const [ButtonSegment(value: 0, label: Text('Loans'), icon: Icon(Icons.payments_outlined)), ButtonSegment(value: 1, label: Text('Approvals'), icon: Icon(Icons.verified_outlined)), ButtonSegment(value: 2, label: Text('Reports'), icon: Icon(Icons.analytics_outlined))], selected: {tab}, onSelectionChanged: (value) => setState(() => tab = value.first))), Expanded(child: tab == 0 ? _LoanApplications(future: loans, approve: approve, onExpired: widget.onSignedOut) : tab == 1 ? _PendingApprovals(future: approvals, decide: decideApproval, onExpired: widget.onSignedOut) : _ReportSummary(future: reports, onExpired: widget.onSignedOut))]));
}

class _LoanApplications extends StatelessWidget { const _LoanApplications({required this.future, required this.approve, required this.onExpired}); final Future<Map<String, dynamic>> future; final Future<void> Function(Map) approve; final VoidCallback onExpired; @override Widget build(BuildContext context) => FutureBuilder<Map<String, dynamic>>(future: future, builder: (context, snapshot) { if (snapshot.connectionState == ConnectionState.waiting) return const Center(child: CircularProgressIndicator()); if (snapshot.hasError) return ApiErrorPanel(error: snapshot.error!, retry: () {}, onExpired: onExpired); final rows = (snapshot.data?['data'] as List? ?? []).cast<Map>(); return ListView(padding: const EdgeInsets.all(16), children: [Text('Loan applications', style: Theme.of(context).textTheme.headlineSmall), const SizedBox(height: 8), if (rows.isEmpty) const _RoleEmpty(message: 'No loan applications require action.'), for (final loan in rows) Card(child: ListTile(title: Text(loan['loan_number']?.toString() ?? 'Loan'), subtitle: Text('${loan['status'] ?? ''} · Requested ${formatRoleMoney(loan['requested_amount'])} UGX'), trailing: ['submitted', 'under_review', 'pending_guarantor_approval', 'pending_collateral_verification'].contains(loan['status']) ? FilledButton(onPressed: () => approve(loan), child: const Text('Approve')) : null))]); }); }
class _PendingApprovals extends StatelessWidget { const _PendingApprovals({required this.future, required this.decide, required this.onExpired}); final Future<Map<String, dynamic>> future; final Future<void> Function(Map, bool) decide; final VoidCallback onExpired; @override Widget build(BuildContext context) => FutureBuilder<Map<String, dynamic>>(future: future, builder: (context, snapshot) { if (snapshot.connectionState == ConnectionState.waiting) return const Center(child: CircularProgressIndicator()); if (snapshot.hasError) return ApiErrorPanel(error: snapshot.error!, retry: () {}, onExpired: onExpired); final rows = (snapshot.data?['data'] as List? ?? []).cast<Map>(); return ListView(padding: const EdgeInsets.all(16), children: [Text('Maker-checker queue', style: Theme.of(context).textTheme.headlineSmall), if (rows.isEmpty) const _RoleEmpty(message: 'No pending approvals.'), for (final item in rows) Card(child: ListTile(title: Text(item['action']?.toString() ?? 'Approval'), subtitle: Text('${item['reason'] ?? ''} · ${formatRoleMoney(item['amount'])} UGX'), isThreeLine: true, trailing: PopupMenuButton<String>(onSelected: (value) => decide(item, value == 'approve'), itemBuilder: (_) => const [PopupMenuItem(value: 'approve', child: Text('Approve')), PopupMenuItem(value: 'reject', child: Text('Reject'))]))) ]); }); }
class _ReportSummary extends StatelessWidget { const _ReportSummary({required this.future, required this.onExpired}); final Future<Map<String, dynamic>> future; final VoidCallback onExpired; @override Widget build(BuildContext context) => FutureBuilder<Map<String, dynamic>>(future: future, builder: (context, snapshot) { if (snapshot.connectionState == ConnectionState.waiting) return const Center(child: CircularProgressIndicator()); if (snapshot.hasError) return ApiErrorPanel(error: snapshot.error!, retry: () {}, onExpired: onExpired); final data = snapshot.data?['data'] as Map? ?? {}; return ListView(padding: const EdgeInsets.all(16), children: [Text('Live reports', style: Theme.of(context).textTheme.headlineSmall), const SizedBox(height: 12), for (final entry in data.entries.where((entry) => entry.value is num)) Card(child: ListTile(title: Text(entry.key.toString().replaceAll('_', ' ')), trailing: Text(formatRoleMoney(entry.value))))]); }); }
class _RolePager extends StatelessWidget { const _RolePager({required this.page, required this.lastPage, required this.previous, required this.next}); final int page; final int lastPage; final VoidCallback? previous; final VoidCallback? next; @override Widget build(BuildContext context) => Row(mainAxisAlignment: MainAxisAlignment.center, children: [IconButton(onPressed: previous, icon: const Icon(Icons.chevron_left)), Text('Page $page of $lastPage'), IconButton(onPressed: next, icon: const Icon(Icons.chevron_right))]); }
class _RoleEmpty extends StatelessWidget { const _RoleEmpty({required this.message}); final String message; @override Widget build(BuildContext context) => Card(child: Padding(padding: const EdgeInsets.all(24), child: Center(child: Text(message, textAlign: TextAlign.center)))); }
String formatRoleMoney(dynamic value) => (double.tryParse(value?.toString() ?? '') ?? 0).toStringAsFixed(0);
