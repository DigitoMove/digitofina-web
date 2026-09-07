import 'dart:math';

import 'package:flutter/material.dart';
import 'package:flutter_svg/flutter_svg.dart';

import '../../core/network/api_client.dart';

class CashierWorkspace extends StatefulWidget {
  const CashierWorkspace({
    super.key,
    required this.client,
    required this.user,
    required this.onSignedOut,
  });

  final ApiClient client;
  final Map<String, dynamic> user;
  final VoidCallback onSignedOut;

  @override
  State<CashierWorkspace> createState() => _CashierWorkspaceState();
}

class _CashierWorkspaceState extends State<CashierWorkspace> {
  late Future<Map<String, dynamic>> dashboard;
  bool signingOut = false;

  @override
  void initState() {
    super.initState();
    dashboard = fetchDashboard();
  }

  Future<Map<String, dynamic>> fetchDashboard() async => Map<String, dynamic>.from(
    (await widget.client.get('cashier/dashboard'))['data'] as Map,
  );

  void reload() {
    setState(() => dashboard = fetchDashboard());
  }

  Future<void> logout() async {
    if (signingOut) return;
    setState(() => signingOut = true);
    try {
      await widget.client.logout();
    } catch (_) {}
    if (mounted) widget.onSignedOut();
  }

  Future<void> openDrawer(Map drawer) async {
    try {
      await widget.client.request(
        'POST',
        'cashier/open',
        body: {
          'drawer_id': drawer['id'],
          'idempotency_key': requestId(),
        },
      );
      if (mounted) {
        showMessage('Drawer opened. You can now serve clients.');
        reload();
      }
    } on ApiException catch (error) {
      if (mounted) showMessage(error.message, error: true);
    }
  }

  void showMessage(String message, {bool error = false}) {
    ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(
        content: Text(message),
        backgroundColor: error ? Theme.of(context).colorScheme.error : null,
      ),
    );
  }

  @override
  Widget build(BuildContext context) => FutureBuilder<Map<String, dynamic>>(
    future: dashboard,
    builder: (context, snapshot) {
      if (snapshot.connectionState == ConnectionState.waiting) {
        return const _CashierShell(
          title: 'Cashier workspace',
          child: Center(child: CircularProgressIndicator()),
        );
      }
      if (snapshot.hasError) {
        return _CashierShell(
          title: 'Cashier workspace',
          actions: [
            IconButton(onPressed: reload, icon: const Icon(Icons.refresh)),
          ],
          child: _CashierError(error: snapshot.error!, retry: reload),
        );
      }
      final data = snapshot.data!;
      final session = data['session'] as Map?;
      final drawers = (data['drawers'] as List? ?? []).cast<Map>();
      return _CashierShell(
        title: 'Cashier workspace',
        actions: [
          IconButton(onPressed: reload, tooltip: 'Refresh', icon: const Icon(Icons.refresh)),
          IconButton(
            onPressed: signingOut ? null : logout,
            tooltip: 'Sign out',
            icon: const Icon(Icons.logout),
          ),
        ],
        child: RefreshIndicator(
          onRefresh: () async {
            reload();
            try {
              await dashboard;
            } catch (_) {}
          },
          child: ListView(
            physics: const AlwaysScrollableScrollPhysics(),
            padding: const EdgeInsets.all(16),
            children: [
              _WelcomeBanner(user: widget.user, session: session),
              const SizedBox(height: 16),
              if (session == null) ...[
                _SectionTitle(
                  title: 'Start your drawer session',
                  subtitle: 'Open only the drawer assigned to you. Cashiers cannot close drawers.',
                ),
                for (final drawer in drawers)
                  _DrawerCard(drawer: drawer, onOpen: () => openDrawer(drawer)),
                if (drawers.isEmpty)
                  const _EmptyCard(message: 'No assigned drawer is available. Ask your drawer administrator for access.'),
              ] else ...[
                _SessionSummary(session: session),
                const SizedBox(height: 20),
                _TransactionComposer(
                  client: widget.client,
                  session: session,
                  transactionTypes: (data['transaction_types'] as List? ?? []).cast<Map>(),
                  onPosted: () {
                    reload();
                    showMessage('Transaction posted successfully.');
                  },
                  onExpired: widget.onSignedOut,
                ),
                const SizedBox(height: 20),
                FilledButton.tonalIcon(
                  onPressed: () => Navigator.of(context).push(
                    MaterialPageRoute<void>(
                      builder: (_) => CashierHistoryPage(
                        client: widget.client,
                        sessionId: session['id'] as int,
                        onExpired: widget.onSignedOut,
                      ),
                    ),
                  ),
                  icon: const Icon(Icons.receipt_long),
                  label: const Text('View complete session history'),
                ),
              ],
            ],
          ),
        ),
      );
    },
  );
}

class _CashierShell extends StatelessWidget {
  const _CashierShell({required this.title, required this.child, this.actions});
  final String title;
  final Widget child;
  final List<Widget>? actions;

  @override
  Widget build(BuildContext context) => Scaffold(
    appBar: AppBar(
      title: Row(
        children: [
          SvgPicture.asset('assets/brand/digitofina-mark.svg', height: 30),
          const SizedBox(width: 8),
          Flexible(child: Text(title, overflow: TextOverflow.ellipsis)),
        ],
      ),
      actions: actions,
    ),
    body: child,
  );
}

class _WelcomeBanner extends StatelessWidget {
  const _WelcomeBanner({required this.user, required this.session});
  final Map<String, dynamic> user;
  final Map? session;

  @override
  Widget build(BuildContext context) => Container(
    padding: const EdgeInsets.all(20),
    decoration: BoxDecoration(
      gradient: const LinearGradient(colors: [Color(0xff4c1d95), Color(0xff9333ea), Color(0xff0891b2)]),
      borderRadius: BorderRadius.circular(22),
    ),
    child: Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text('Welcome, ${user['name'] ?? 'Cashier'}', style: const TextStyle(color: Colors.white, fontSize: 22, fontWeight: FontWeight.w700)),
        const SizedBox(height: 6),
        Text(session == null ? 'Your secure drawer is waiting.' : '${(session?['drawer'] as Map?)?['name']} · ${session?['branch']}', style: const TextStyle(color: Colors.white70)),
        const SizedBox(height: 14),
        Row(children: [
          Icon(session == null ? Icons.lock_outline : Icons.lock_open, color: Colors.white, size: 18),
          const SizedBox(width: 8),
          Text(session == null ? 'Session not started' : 'Session active · transactions stay in this session', style: const TextStyle(color: Colors.white, fontWeight: FontWeight.w600)),
        ]),
      ],
    ),
  );
}

class _SessionSummary extends StatelessWidget {
  const _SessionSummary({required this.session});
  final Map session;

  @override
  Widget build(BuildContext context) {
    final drawer = session['drawer'] as Map;
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        _SectionTitle(title: '${drawer['code']} · ${drawer['name']}', subtitle: 'Everything below belongs to this open drawer session.'),
        Wrap(
          spacing: 10,
          runSpacing: 10,
          children: [
            _MoneyCard(label: 'Drawer balance', value: drawer['current_balance'], color: const Color(0xff7e22ce), icon: Icons.account_balance_wallet),
            _MoneyCard(label: 'Cash in', value: session['cash_in'], color: const Color(0xff059669), icon: Icons.south_west),
            _MoneyCard(label: 'Cash out', value: session['cash_out'], color: const Color(0xffd97706), icon: Icons.north_east),
          ],
        ),
      ],
    );
  }
}

class _MoneyCard extends StatelessWidget {
  const _MoneyCard({required this.label, required this.value, required this.color, required this.icon});
  final String label;
  final dynamic value;
  final Color color;
  final IconData icon;

  @override
  Widget build(BuildContext context) => SizedBox(
    width: 150,
    child: Card(
      margin: EdgeInsets.zero,
      clipBehavior: Clip.antiAlias,
      child: Container(
        decoration: BoxDecoration(border: Border(left: BorderSide(color: color, width: 5))),
        padding: const EdgeInsets.all(14),
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Icon(icon, color: color),
          const SizedBox(height: 8),
          Text(label, style: Theme.of(context).textTheme.labelMedium),
          const SizedBox(height: 4),
          Text('${formatMoney(value)} UGX', style: Theme.of(context).textTheme.titleMedium?.copyWith(fontWeight: FontWeight.w700)),
        ]),
      ),
    ),
  );
}

class _DrawerCard extends StatelessWidget {
  const _DrawerCard({required this.drawer, required this.onOpen});
  final Map drawer;
  final VoidCallback onOpen;

  @override
  Widget build(BuildContext context) => Card(
    child: ListTile(
      leading: const CircleAvatar(backgroundColor: Color(0xffede9fe), child: Icon(Icons.point_of_sale, color: Color(0xff6d28d9))),
      title: Text('${drawer['code']} · ${drawer['name']}', maxLines: 1, overflow: TextOverflow.ellipsis),
      subtitle: Text('${drawer['currency'] ?? 'UGX'} · Balance ${formatMoney(drawer['current_balance'])}', maxLines: 1, overflow: TextOverflow.ellipsis),
      trailing: FilledButton(onPressed: drawer['status'] == 'open' ? null : onOpen, child: const Text('Open')),
    ),
  );
}

class _TransactionComposer extends StatefulWidget {
  const _TransactionComposer({required this.client, required this.session, required this.transactionTypes, required this.onPosted, required this.onExpired});
  final ApiClient client;
  final Map session;
  final List<Map> transactionTypes;
  final VoidCallback onPosted;
  final VoidCallback onExpired;
  @override
  State<_TransactionComposer> createState() => _TransactionComposerState();
}

class _TransactionComposerState extends State<_TransactionComposer> {
  final search = TextEditingController();
  final amount = TextEditingController();
  final charges = TextEditingController(text: '0');
  final description = TextEditingController();
  String scope = 'client_account';
  String? chargeMode = 'add_to_amount';
  Map? selected;
  Map? selectedType;
  List<Map> matches = [];
  bool searching = false;
  bool busy = false;

  int get sessionId => widget.session['id'] as int;

  Future<void> lookup() async {
    if (search.text.trim().length < 2) return;
    setState(() => searching = true);
    try {
      final result = await widget.client.get('cashier/lookup', query: {
        'drawer_session_id': sessionId.toString(),
        'search': search.text.trim(),
        'scope': scope,
      });
      if (mounted) setState(() => matches = (result['data'] as List? ?? []).cast<Map>());
    } on ApiException catch (error) {
      if (mounted) handleError(error);
    } finally {
      if (mounted) setState(() => searching = false);
    }
  }

  void handleError(ApiException error) {
    if (error.status == 401) {
      widget.onExpired();
      return;
    }
    ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(error.message), backgroundColor: Theme.of(context).colorScheme.error));
  }

  Map<String, dynamic> payload(String key) => {
    'drawer_session_id': sessionId,
    'account_scope': scope,
    if (scope == 'client_account') ...{
      'account_number': selected?['account_number'],
      'transaction_type_id': selectedType?['id'],
    },
    if (scope == 'general_ledger') 'chart_account_id': selected?['id'],
    'amount': double.tryParse(amount.text.trim()),
    'charges': double.tryParse(charges.text.trim()) ?? 0,
    'charge_mode': chargeMode,
    'transaction_date': DateTime.now().toIso8601String().substring(0, 10),
    'description': description.text.trim(),
    'idempotency_key': requestId(),
    'request': key,
  };

  Future<void> review() async {
    if (selected == null || amount.text.trim().isEmpty || (scope == 'client_account' && selectedType == null)) {
      ScaffoldMessenger.of(context).showSnackBar(const SnackBar(content: Text('Choose an account, transaction type, and amount first.')));
      return;
    }
    setState(() => busy = true);
    try {
      final preview = await widget.client.request('POST', 'cashier/preview', body: payload('preview'));
      if (mounted) await showPreview(preview['data'] as Map);
    } on ApiException catch (error) {
      if (mounted) handleError(error);
    } finally {
      if (mounted) setState(() => busy = false);
    }
  }

  Future<void> showPreview(Map preview) async {
    final confirmed = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        title: const Text('Review before posting'),
        content: SizedBox(
          width: 420,
          child: SingleChildScrollView(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Text('Amount: ${formatMoney(preview['amount'])} UGX'),
            Text('Charges: ${formatMoney(preview['charges'])} UGX'),
            const Divider(),
            for (final effect in (preview['effects'] as List? ?? []))
              Padding(padding: const EdgeInsets.only(bottom: 8), child: Text('${effect['label']}: ${formatMoney(effect['before'])} → ${formatMoney(effect['after'])} UGX')),
            const SizedBox(height: 8),
            for (final note in (preview['notes'] as List? ?? [])) Text('• $note', style: Theme.of(context).textTheme.bodySmall),
          ])),
        ),
        actions: [
          TextButton(onPressed: () => Navigator.pop(context, false), child: const Text('Back')),
          FilledButton(onPressed: () => Navigator.pop(context, true), child: const Text('Post transaction')),
        ],
      ),
    );
    if (confirmed == true) await post();
  }

  Future<void> post() async {
    setState(() => busy = true);
    try {
      await widget.client.request('POST', 'cashier/transactions', body: payload('post'));
      if (mounted) {
        selected = null;
        matches = [];
        amount.clear();
        charges.text = '0';
        description.clear();
        widget.onPosted();
      }
    } on ApiException catch (error) {
      if (mounted) handleError(error);
    } finally {
      if (mounted) setState(() => busy = false);
    }
  }

  @override
  void dispose() {
    search.dispose();
    amount.dispose();
    charges.dispose();
    description.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) => Card(
    child: Padding(
      padding: const EdgeInsets.all(16),
      child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        const _SectionTitle(title: 'Post a transaction', subtitle: 'Search, review the effect, then confirm. Every post is tied to this session.'),
        SegmentedButton<String>(
          segments: const [ButtonSegment(value: 'client_account', label: Text('Account'), icon: Icon(Icons.person)), ButtonSegment(value: 'general_ledger', label: Text('Ledger'), icon: Icon(Icons.menu_book))],
          selected: {scope},
          onSelectionChanged: (value) => setState(() { scope = value.first; selected = null; matches = []; }),
        ),
        const SizedBox(height: 14),
        TextField(controller: search, onSubmitted: (_) => lookup(), decoration: InputDecoration(labelText: scope == 'client_account' ? 'Account, client, or phone' : 'Ledger name or code', prefixIcon: const Icon(Icons.search), suffixIcon: IconButton(onPressed: searching ? null : lookup, icon: const Icon(Icons.arrow_forward)))),
        if (searching) const LinearProgressIndicator(),
        for (final match in matches)
          ListTile(
            dense: true,
            leading: Icon(scope == 'client_account' ? Icons.account_circle : Icons.account_tree, color: Theme.of(context).colorScheme.primary),
            title: Text(scope == 'client_account' ? '${match['account_number']} · ${match['name'] ?? 'Client'}' : '${match['code']} · ${match['name']}'),
            subtitle: Text(scope == 'client_account' ? '${match['category'] ?? 'account'} · Balance ${formatMoney(match['balance'])} UGX' : '${match['type']} · ${match['drawer_direction'] ?? 'both'}'),
            selected: selected?['id'] == match['id'],
            onTap: () => setState(() { selected = match; matches = []; }),
          ),
        if (selected != null)
          Container(margin: const EdgeInsets.only(top: 6, bottom: 12), padding: const EdgeInsets.all(10), color: const Color(0xfff3e8ff), child: Text(scope == 'client_account' ? 'Selected ${selected!['account_number']}' : 'Selected ${selected!['code']}')),
        if (scope == 'client_account')
          DropdownButtonFormField<Map>(initialValue: selectedType, decoration: const InputDecoration(labelText: 'Transaction type'), items: [for (final type in widget.transactionTypes) DropdownMenuItem(value: type, child: Text(type['name'].toString()))], onChanged: (value) => setState(() => selectedType = value)),
        const SizedBox(height: 12),
        Row(children: [Expanded(child: TextField(controller: amount, keyboardType: TextInputType.number, decoration: const InputDecoration(labelText: 'Amount (UGX)'))), const SizedBox(width: 10), Expanded(child: TextField(controller: charges, keyboardType: TextInputType.number, decoration: const InputDecoration(labelText: 'Charges')))]),
        const SizedBox(height: 12),
        DropdownButtonFormField<String>(initialValue: chargeMode, decoration: const InputDecoration(labelText: 'Charge handling'), items: const [DropdownMenuItem(value: 'add_to_amount', child: Text('Add charges to amount')), DropdownMenuItem(value: 'deduct_from_amount', child: Text('Deduct charges from amount'))], onChanged: (value) => setState(() => chargeMode = value)),
        const SizedBox(height: 12),
        TextField(controller: description, maxLength: 1000, decoration: const InputDecoration(labelText: 'Description (optional)')),
        const SizedBox(height: 4),
        SizedBox(width: double.infinity, child: FilledButton.icon(onPressed: busy ? null : review, icon: busy ? const SizedBox(width: 18, height: 18, child: CircularProgressIndicator(strokeWidth: 2)) : const Icon(Icons.fact_check), label: Text(busy ? 'Checking…' : 'Review transaction'))),
      ]),
    ),
  );
}

class CashierHistoryPage extends StatefulWidget {
  const CashierHistoryPage({super.key, required this.client, required this.sessionId, required this.onExpired});
  final ApiClient client;
  final int sessionId;
  final VoidCallback onExpired;
  @override
  State<CashierHistoryPage> createState() => _CashierHistoryPageState();
}

class _CashierHistoryPageState extends State<CashierHistoryPage> {
  late Future<Map<String, dynamic>> history;
  int transactionPage = 1;
  int movementPage = 1;
  @override
  void initState() {
    super.initState();
    history = fetch();
  }

  Future<Map<String, dynamic>> fetch() async {
    final results = await Future.wait([
      widget.client.get('cashier/sessions/${widget.sessionId}'),
      widget.client.get(
        'cashier/sessions/${widget.sessionId}/transactions',
        query: {'page': transactionPage.toString()},
      ),
      widget.client.get(
        'cashier/sessions/${widget.sessionId}/movements',
        query: {'page': movementPage.toString()},
      ),
    ]);
    return {
      'session': results[0]['data'],
      'transactions': results[1]['data'],
      'transaction_pagination': (results[1]['meta'] as Map?)?['pagination'],
      'movements': results[2]['data'],
      'movement_pagination': (results[2]['meta'] as Map?)?['pagination'],
    };
  }

  void reload({int? transactions, int? movements}) {
    setState(() {
      transactionPage = transactions ?? transactionPage;
      movementPage = movements ?? movementPage;
      history = fetch();
    });
  }

  @override
  Widget build(BuildContext context) => _CashierShell(
    title: 'Complete session history',
    actions: [IconButton(onPressed: () => setState(() => history = fetch()), icon: const Icon(Icons.refresh))],
    child: FutureBuilder<Map<String, dynamic>>(
      future: history,
      builder: (context, snapshot) {
        if (snapshot.connectionState == ConnectionState.waiting) return const Center(child: CircularProgressIndicator());
        if (snapshot.hasError) return _CashierError(error: snapshot.error!, retry: () => setState(() => history = fetch()));
        final data = snapshot.data!;
        final transactions = (data['transactions'] as List? ?? []).cast<Map>();
        final movements = (data['movements'] as List? ?? []).cast<Map>();
        return ListView(padding: const EdgeInsets.all(16), children: [
          _SessionSummary(session: data['session'] as Map),
          const SizedBox(height: 20),
          _SectionTitle(title: 'Transactions (${transactions.length})', subtitle: 'All posts linked to this drawer session, regardless of business date.'),
          if (transactions.isEmpty) const _EmptyCard(message: 'No transactions have been posted in this session.'),
          for (final transaction in transactions) Card(child: ListTile(leading: CircleAvatar(backgroundColor: transaction['status'] == 'posted' ? const Color(0xffd1fae5) : const Color(0xfffef3c7), child: const Icon(Icons.receipt_long)), title: Text('${transaction['type'] ?? 'Transaction'} · ${formatMoney(transaction['amount'])} UGX'), subtitle: Text('${transaction['reference']} · ${transaction['posted_at'] ?? ''}\n${transaction['account']?['account_number'] ?? 'Ledger'}'), isThreeLine: true)),
          _HistoryPager(
            page: transactionPage,
            pagination: data['transaction_pagination'] as Map?,
            onPrevious: transactionPage > 1 ? () => reload(transactions: transactionPage - 1) : null,
            onNext: () => reload(transactions: transactionPage + 1),
          ),
          const SizedBox(height: 20),
          _SectionTitle(title: 'Drawer movements (${movements.length})', subtitle: 'Cash movement totals are calculated from this session only.'),
          for (final movement in movements) Card(child: ListTile(leading: Icon(movement['type'] == 'cash_in' ? Icons.south_west : Icons.north_east, color: movement['type'] == 'cash_in' ? Colors.green : Colors.orange), title: Text('${movement['type'] == 'cash_in' ? 'Cash in' : 'Cash out'} · ${formatMoney(movement['amount'])} UGX'), subtitle: Text('${movement['reference'] ?? ''} · Balance ${formatMoney(movement['balance_after'])} UGX'))),
          _HistoryPager(
            page: movementPage,
            pagination: data['movement_pagination'] as Map?,
            onPrevious: movementPage > 1 ? () => reload(movements: movementPage - 1) : null,
            onNext: () => reload(movements: movementPage + 1),
          ),
        ]);
      },
    ),
  );
}

class _HistoryPager extends StatelessWidget {
  const _HistoryPager({required this.page, required this.pagination, required this.onPrevious, required this.onNext});
  final int page;
  final Map? pagination;
  final VoidCallback? onPrevious;
  final VoidCallback onNext;

  @override
  Widget build(BuildContext context) {
    final lastPage = int.tryParse(pagination?['last_page']?.toString() ?? '') ?? page;
    return Row(children: [
      IconButton(onPressed: onPrevious, tooltip: 'Previous page', icon: const Icon(Icons.chevron_left)),
      Expanded(child: Text('Page $page of $lastPage', textAlign: TextAlign.center, style: Theme.of(context).textTheme.bodySmall)),
      IconButton(onPressed: page < lastPage ? onNext : null, tooltip: 'Next page', icon: const Icon(Icons.chevron_right)),
    ]);
  }
}

class _SectionTitle extends StatelessWidget {
  const _SectionTitle({required this.title, required this.subtitle});
  final String title;
  final String subtitle;
  @override
  Widget build(BuildContext context) => Padding(padding: const EdgeInsets.only(bottom: 10), child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [Text(title, style: Theme.of(context).textTheme.titleLarge?.copyWith(fontWeight: FontWeight.w700)), const SizedBox(height: 4), Text(subtitle, style: Theme.of(context).textTheme.bodySmall)]));
}

class _EmptyCard extends StatelessWidget {
  const _EmptyCard({required this.message});
  final String message;
  @override
  Widget build(BuildContext context) => Card(child: Padding(padding: const EdgeInsets.all(18), child: Row(children: [const Icon(Icons.info_outline, color: Color(0xff0891b2)), const SizedBox(width: 10), Expanded(child: Text(message))])));
}

class _CashierError extends StatelessWidget {
  const _CashierError({required this.error, required this.retry});
  final Object error;
  final VoidCallback retry;
  @override
  Widget build(BuildContext context) => Center(child: Padding(padding: const EdgeInsets.all(24), child: Column(mainAxisSize: MainAxisSize.min, children: [const Icon(Icons.cloud_off, size: 48), const SizedBox(height: 12), Text(error.toString(), textAlign: TextAlign.center), const SizedBox(height: 16), FilledButton(onPressed: retry, child: const Text('Retry'))])));
}

String requestId() {
  final random = Random();
  final suffix = List.generate(12, (_) => random.nextInt(16).toRadixString(16)).join();
  return '00000000-0000-4000-8000-$suffix';
}

String formatMoney(dynamic value) {
  final number = double.tryParse(value?.toString() ?? '') ?? 0;
  return number.toStringAsFixed(number.truncateToDouble() == number ? 0 : 2);
}
