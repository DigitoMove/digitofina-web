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
  bool openingDrawer = false;
  bool transactionLocked = false;
  final Map<int, String> openingKeys = {};

  @override
  void initState() {
    super.initState();
    dashboard = fetchDashboard();
  }

  Future<Map<String, dynamic>> fetchDashboard() async =>
      Map<String, dynamic>.from(
        (await widget.client.get('cashier/dashboard'))['data'] as Map,
      );

  void reload() {
    if (transactionLocked || openingDrawer) return;
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
    if (openingDrawer) return;
    setState(() => openingDrawer = true);
    try {
      await widget.client.request(
        'POST',
        'cashier/open',
        body: {
          'drawer_id': drawer['id'],
          'idempotency_key': openingKeys.putIfAbsent(
            drawer['id'] as int,
            requestId,
          ),
        },
      );
      if (mounted) {
        openingDrawer = false;
        openingKeys.remove(drawer['id']);
        showMessage('Drawer opened. You can now serve clients.');
        reload();
      }
    } on ApiException catch (error) {
      if (mounted) {
        if (error.status == 401) widget.onSignedOut();
        showMessage(error.message, error: true);
      }
    } finally {
      if (mounted) setState(() => openingDrawer = false);
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
          IconButton(
            onPressed: reload,
            tooltip: 'Refresh',
            icon: const Icon(Icons.refresh),
          ),
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
              OutlinedButton.icon(
                onPressed: () => Navigator.of(context).push(
                  MaterialPageRoute<void>(
                    builder: (_) => CashierSessionsPage(
                      client: widget.client,
                      onExpired: widget.onSignedOut,
                    ),
                  ),
                ),
                icon: const Icon(Icons.history),
                label: const Text('Past drawer sessions'),
              ),
              if (session == null) ...[
                _SectionTitle(
                  title: 'Start your drawer session',
                  subtitle:
                      'Open only the drawer assigned to you. Cashiers cannot close drawers.',
                ),
                if (openingDrawer) const LinearProgressIndicator(),
                for (final drawer in drawers)
                  _DrawerCard(
                    drawer: drawer,
                    onOpen: openingDrawer ? null : () => openDrawer(drawer),
                  ),
                if (drawers.isEmpty)
                  const _EmptyCard(
                    message:
                        'No assigned drawer is available. Ask your drawer administrator for access.',
                  ),
              ] else ...[
                _SessionSummary(session: session),
                const SizedBox(height: 20),
                _TransactionComposer(
                  client: widget.client,
                  session: session,
                  businessDate: data['business_date'] as String,
                  transactionTypes: (data['transaction_types'] as List? ?? [])
                      .cast<Map>(),
                  onLockChanged: (value) =>
                      setState(() => transactionLocked = value),
                  onPosted: () {
                    transactionLocked = false;
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
      gradient: const LinearGradient(
        colors: [Color(0xff4c1d95), Color(0xff9333ea), Color(0xff0891b2)],
      ),
      borderRadius: BorderRadius.circular(22),
    ),
    child: Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(
          'Welcome, ${user['name'] ?? 'Cashier'}',
          style: const TextStyle(
            color: Colors.white,
            fontSize: 22,
            fontWeight: FontWeight.w700,
          ),
        ),
        const SizedBox(height: 6),
        Text(
          session == null
              ? 'Your secure drawer is waiting.'
              : '${(session?['drawer'] as Map?)?['name']} · ${session?['branch']}',
          style: const TextStyle(color: Colors.white70),
        ),
        const SizedBox(height: 14),
        Row(
          children: [
            Icon(
              session == null ? Icons.lock_outline : Icons.lock_open,
              color: Colors.white,
              size: 18,
            ),
            const SizedBox(width: 8),
            Expanded(
              child: Text(
                session == null
                    ? 'Session not started'
                    : 'Session active · transactions stay in this session',
                style: const TextStyle(
                  color: Colors.white,
                  fontWeight: FontWeight.w600,
                ),
              ),
            ),
          ],
        ),
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
        _SectionTitle(
          title: '${drawer['code']} · ${drawer['name']}',
          subtitle: 'Everything below belongs to this open drawer session.',
        ),
        Wrap(
          spacing: 10,
          runSpacing: 10,
          children: [
            _MoneyCard(
              label: 'Drawer balance',
              value: drawer['current_balance'],
              color: const Color(0xff7e22ce),
              icon: Icons.account_balance_wallet,
            ),
            _MoneyCard(
              label: 'Cash in',
              value: session['cash_in'],
              color: const Color(0xff059669),
              icon: Icons.south_west,
            ),
            _MoneyCard(
              label: 'Cash out',
              value: session['cash_out'],
              color: const Color(0xffd97706),
              icon: Icons.north_east,
            ),
          ],
        ),
      ],
    );
  }
}

class _MoneyCard extends StatelessWidget {
  const _MoneyCard({
    required this.label,
    required this.value,
    required this.color,
    required this.icon,
  });
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
        decoration: BoxDecoration(
          border: Border(left: BorderSide(color: color, width: 5)),
        ),
        padding: const EdgeInsets.all(14),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Icon(icon, color: color),
            const SizedBox(height: 8),
            Text(label, style: Theme.of(context).textTheme.labelMedium),
            const SizedBox(height: 4),
            Text(
              '${formatMoney(value)} UGX',
              style: Theme.of(
                context,
              ).textTheme.titleMedium?.copyWith(fontWeight: FontWeight.w700),
            ),
          ],
        ),
      ),
    ),
  );
}

class _DrawerCard extends StatelessWidget {
  const _DrawerCard({required this.drawer, required this.onOpen});
  final Map drawer;
  final VoidCallback? onOpen;

  @override
  Widget build(BuildContext context) => Card(
    child: ListTile(
      leading: const CircleAvatar(
        backgroundColor: Color(0xffede9fe),
        child: Icon(Icons.point_of_sale, color: Color(0xff6d28d9)),
      ),
      title: Text(
        '${drawer['code']} · ${drawer['name']}',
        maxLines: 1,
        overflow: TextOverflow.ellipsis,
      ),
      subtitle: Text(
        '${drawer['currency'] ?? 'UGX'} · Balance ${formatMoney(drawer['current_balance'])}',
        maxLines: 1,
        overflow: TextOverflow.ellipsis,
      ),
      trailing: FilledButton(
        onPressed: drawer['status'] == 'open' ? null : onOpen,
        child: const Text('Open'),
      ),
    ),
  );
}

class _TransactionComposer extends StatefulWidget {
  const _TransactionComposer({
    required this.client,
    required this.session,
    required this.transactionTypes,
    required this.businessDate,
    required this.onPosted,
    required this.onExpired,
    required this.onLockChanged,
  });
  final ApiClient client;
  final Map session;
  final List<Map> transactionTypes;
  final String businessDate;
  final ValueChanged<bool> onLockChanged;
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
  String operation = 'deposit';
  String chargeMode = 'add_to_amount';
  String ledgerEntry = 'credit';
  Map? selected;
  Map? selectedType;
  List<Map> matches = [];
  bool searching = false;
  bool searched = false;
  bool busy = false;
  int searchVersion = 0;
  String? searchError;
  Map<String, dynamic>? pending;

  bool get ledger => operation == 'ledger';
  String get currency =>
      (widget.session['drawer'] as Map)['currency']?.toString() ?? 'UGX';
  int get sessionId => widget.session['id'] as int;
  List<Map> get availableTypes => widget.transactionTypes.where((type) {
    if (operation == 'repayment') return type['code'] == 'loan_repayment';
    if (type['code'] == 'loan_repayment') return false;
    final withdrawal = ['debit', 'withdrawal'].contains(type['direction']);
    return operation == 'withdrawal' ? withdrawal : !withdrawal;
  }).toList();

  @override
  void initState() {
    super.initState();
    if (availableTypes.isEmpty &&
        widget.transactionTypes.any(
          (type) => type['code'] == 'loan_repayment',
        )) {
      operation = 'repayment';
    }
    chooseDefaultType();
  }

  void chooseDefaultType() =>
      selectedType = availableTypes.isEmpty ? null : availableTypes.first;

  void changeOperation(String value) {
    setState(() {
      operation = value;
      selected = null;
      matches = [];
      searched = false;
      searching = false;
      searchError = null;
      searchVersion++;
      search.clear();
      amount.clear();
      charges.text = '0';
      chooseDefaultType();
    });
  }

  Future<void> lookup() async {
    final version = ++searchVersion;
    if (search.text.trim().length < 2) {
      setState(() {
        matches = [];
        searchError = 'Enter at least 2 characters to search.';
      });
      return;
    }
    setState(() {
      searching = true;
      matches = [];
      selected = null;
      searchError = null;
      searched = false;
    });
    try {
      final result = await widget.client.get(
        'cashier/lookup',
        query: {
          'drawer_session_id': '$sessionId',
          'search': search.text.trim(),
          'scope': ledger ? 'general_ledger' : 'client_account',
          if (!ledger)
            'category': operation == 'repayment' ? 'loan' : 'savings',
        },
      );
      if (mounted && version == searchVersion) {
        setState(() {
          matches = (result['data'] as List? ?? []).cast<Map>();
          searched = true;
        });
      }
    } on ApiException catch (error) {
      if (mounted && version == searchVersion) {
        setState(() => searchError = error.message);
        if (error.status == 401) widget.onExpired();
      }
    } finally {
      if (mounted && version == searchVersion)
        setState(() => searching = false);
    }
  }

  void handleError(ApiException error) {
    if (error.status == 401) {
      widget.onExpired();
      return;
    }
    ScaffoldMessenger.of(
      context,
    ).showSnackBar(SnackBar(content: Text(error.message)));
  }

  Map<String, dynamic> payload() => {
    'drawer_session_id': sessionId,
    'account_scope': ledger ? 'general_ledger' : 'client_account',
    if (!ledger) ...{
      'account_number': selected!['account_number'],
      'transaction_type_id': selectedType!['id'],
    },
    if (ledger) ...{
      'chart_account_id': selected!['id'],
      'gl_entry_type': selected!['drawer_direction'] == 'cash_out'
          ? 'debit'
          : selected!['drawer_direction'] == 'cash_in'
          ? 'credit'
          : ledgerEntry,
      'charge_mode': chargeMode,
    },
    'amount': double.parse(amount.text.trim()),
    'charges': double.parse(charges.text.trim()),
    'transaction_date': widget.businessDate,
    'description': description.text.trim(),
    'idempotency_key': requestId(),
  };

  Future<void> review() async {
    final value = double.tryParse(amount.text.trim());
    final fee = double.tryParse(charges.text.trim());
    if (selected == null ||
        (!ledger && selectedType == null) ||
        value == null ||
        !value.isFinite ||
        value < 100 ||
        fee == null ||
        !fee.isFinite ||
        fee < 0) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(
          content: Text(
            'Choose an account and enter an amount of at least 100, with valid charges.',
          ),
        ),
      );
      return;
    }
    FocusScope.of(context).unfocus();
    setState(() => busy = true);
    widget.onLockChanged(true);
    final draft = payload();
    try {
      final response = await widget.client.request(
        'POST',
        'cashier/preview',
        body: draft,
      );
      if (!mounted) return;
      final preview = response['data'] as Map;
      final confirmed = await showDialog<bool>(
        context: context,
        builder: (context) => AlertDialog(
          title: const Text('Review transaction'),
          content: SingleChildScrollView(
            child: Column(
              mainAxisSize: MainAxisSize.min,
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  ledger
                      ? '${selected!['code']} · ${selected!['name']}'
                      : '${selected!['client_name'] ?? selected!['name']}\n${selected!['account_number']}',
                ),
                Text(
                  ledger
                      ? 'Ledger cash ${draft['gl_entry_type'] == 'credit' ? 'in' : 'out'}'
                      : selectedType!['name'].toString(),
                ),
                Text('Business date: ${widget.businessDate}'),
                const Divider(),
                Text('Amount: ${formatMoney(preview['amount'])} $currency'),
                Text('Charges: ${formatMoney(preview['charges'])} $currency'),
                const Divider(),
                for (final effect in (preview['effects'] as List? ?? []))
                  Padding(
                    padding: const EdgeInsets.only(bottom: 8),
                    child: Text(
                      '${effect['label']}: ${formatMoney(effect['before'])} → ${formatMoney(effect['after'])} $currency',
                    ),
                  ),
                for (final note in (preview['notes'] as List? ?? []))
                  Text('• $note'),
              ],
            ),
          ),
          actions: [
            TextButton(
              onPressed: () => Navigator.pop(context, false),
              child: const Text('Back'),
            ),
            FilledButton(
              onPressed: () => Navigator.pop(context, true),
              child: const Text('Confirm transaction'),
            ),
          ],
        ),
      );
      if (confirmed == true && mounted) {
        pending = draft;
        await post();
      }
    } on ApiException catch (error) {
      if (mounted) handleError(error);
    } finally {
      if (mounted) {
        setState(() => busy = false);
        widget.onLockChanged(pending != null);
      }
    }
  }

  Future<void> post() async {
    setState(() => busy = true);
    try {
      final response = await widget.client.request(
        'POST',
        'cashier/transactions',
        body: pending!,
      );
      pending = null;
      if (mounted) {
        await showCashierReceipt(context, response['data'] as Map, currency);
        if (mounted) widget.onPosted();
      }
    } on ApiException catch (error) {
      if ([400, 403, 404, 422].contains(error.status)) pending = null;
      if (mounted) handleError(error);
    } finally {
      if (mounted) {
        setState(() => busy = false);
        widget.onLockChanged(pending != null);
      }
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
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          const _SectionTitle(
            title: 'Serve a client',
            subtitle:
                'Choose a service, find the account, then review and confirm.',
          ),
          AbsorbPointer(
            absorbing: busy || pending != null,
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Wrap(
                  spacing: 8,
                  runSpacing: 4,
                  children: [
                    for (final item in const [
                      ('deposit', 'Deposit', Icons.south_west),
                      ('withdrawal', 'Withdrawal', Icons.north_east),
                      ('repayment', 'Loan repayment', Icons.payments_outlined),
                      ('ledger', 'Ledger', Icons.menu_book),
                    ])
                      ChoiceChip(
                        label: Text(item.$2),
                        avatar: Icon(item.$3, size: 18),
                        selected: operation == item.$1,
                        onSelected: (_) => changeOperation(item.$1),
                      ),
                  ],
                ),
                const SizedBox(height: 16),
                Text(
                  '1. Find ${ledger
                      ? 'a ledger'
                      : operation == 'repayment'
                      ? 'a loan account'
                      : 'a savings account'}',
                  style: Theme.of(context).textTheme.titleMedium,
                ),
                const SizedBox(height: 8),
                TextField(
                  controller: search,
                  textInputAction: TextInputAction.search,
                  onChanged: (_) => setState(() {
                    searchVersion++;
                    selected = null;
                    matches = [];
                    searched = false;
                    searching = false;
                    searchError = null;
                  }),
                  onSubmitted: (_) => lookup(),
                  decoration: InputDecoration(
                    labelText: ledger
                        ? 'Ledger name or code'
                        : 'Client name, phone or account number',
                    prefixIcon: const Icon(Icons.search),
                    suffixIcon: IconButton(
                      tooltip: 'Search accounts',
                      onPressed: searching ? null : lookup,
                      icon: const Icon(Icons.arrow_forward),
                    ),
                  ),
                ),
                if (searching) const LinearProgressIndicator(),
                if (searchError != null)
                  Padding(
                    padding: const EdgeInsets.symmetric(vertical: 8),
                    child: Text(
                      searchError!,
                      style: TextStyle(
                        color: Theme.of(context).colorScheme.error,
                      ),
                    ),
                  ),
                if (searched && matches.isEmpty)
                  const Padding(
                    padding: EdgeInsets.symmetric(vertical: 12),
                    child: Text(
                      'No eligible accounts found. Try a phone number or account number.',
                    ),
                  ),
                if (matches.length == 30)
                  const Text(
                    'Showing the first 30 matches. Refine your search to find more.',
                  ),
                for (final match in matches)
                  ListTile(
                    contentPadding: EdgeInsets.zero,
                    leading: Icon(
                      ledger ? Icons.menu_book : Icons.person_outline,
                    ),
                    title: Text('${match['client_name'] ?? match['name']}'),
                    subtitle: Text(
                      ledger
                          ? '${match['code']} · ${match['type']}'
                          : '${match['account_number']} · ${match['client_number'] ?? ''}\n${match['client_phone'] ?? ''} · ${operation == 'repayment' ? 'Account balance' : 'Balance'} ${formatMoney(match['balance'])} ${match['currency'] ?? currency}',
                    ),
                    trailing: const Icon(Icons.chevron_right),
                    onTap: () => setState(() {
                      selected = match;
                      matches = [];
                      searched = false;
                    }),
                  ),
                if (selected != null)
                  Card(
                    color: Theme.of(context).colorScheme.primaryContainer,
                    child: ListTile(
                      title: Text(
                        '${selected!['client_name'] ?? selected!['name']}',
                      ),
                      subtitle: Text(
                        '${selected![ledger ? 'code' : 'account_number']} · ${ledger ? 'Ledger' : '${formatMoney(selected!['balance'])} ${selected!['currency'] ?? currency}'}',
                      ),
                      trailing: IconButton(
                        tooltip: 'Change account',
                        icon: const Icon(Icons.close),
                        onPressed: () => setState(() => selected = null),
                      ),
                    ),
                  ),
                const SizedBox(height: 16),
                Text(
                  '2. Transaction details',
                  style: Theme.of(context).textTheme.titleMedium,
                ),
                const SizedBox(height: 10),
                if (!ledger && availableTypes.isEmpty)
                  const Text(
                    'No transaction type is available for this service. Ask your administrator to check your permissions.',
                  ),
                if (!ledger && availableTypes.isNotEmpty)
                  DropdownButtonFormField<Map>(
                    key: ValueKey(operation),
                    initialValue: selectedType,
                    isExpanded: true,
                    decoration: const InputDecoration(
                      labelText: 'Transaction type',
                    ),
                    items: [
                      for (final type in availableTypes)
                        DropdownMenuItem(
                          value: type,
                          child: Text(
                            type['name'].toString(),
                            overflow: TextOverflow.ellipsis,
                          ),
                        ),
                    ],
                    onChanged: (value) => setState(() => selectedType = value),
                  ),
                if (ledger)
                  DropdownButtonFormField<String>(
                    key: ValueKey(selected?['id']),
                    initialValue: selected?['drawer_direction'] == 'cash_out'
                        ? 'debit'
                        : selected?['drawer_direction'] == 'cash_in'
                        ? 'credit'
                        : ledgerEntry,
                    decoration: const InputDecoration(
                      labelText: 'Drawer cash direction',
                    ),
                    items: const [
                      DropdownMenuItem(value: 'credit', child: Text('Cash in')),
                      DropdownMenuItem(value: 'debit', child: Text('Cash out')),
                    ],
                    onChanged:
                        [
                          'cash_in',
                          'cash_out',
                        ].contains(selected?['drawer_direction'])
                        ? null
                        : (value) => setState(() => ledgerEntry = value!),
                  ),
                const SizedBox(height: 12),
                TextField(
                  controller: amount,
                  keyboardType: const TextInputType.numberWithOptions(
                    decimal: true,
                  ),
                  decoration: InputDecoration(labelText: 'Amount ($currency)'),
                ),
                const SizedBox(height: 12),
                TextField(
                  controller: charges,
                  keyboardType: const TextInputType.numberWithOptions(
                    decimal: true,
                  ),
                  decoration: InputDecoration(
                    labelText: 'Charges ($currency)',
                    helperText: ledger
                        ? null
                        : 'Charges affect drawer cash; the account amount stays as entered.',
                  ),
                ),
                if (ledger) ...[
                  const SizedBox(height: 12),
                  DropdownButtonFormField<String>(
                    initialValue: chargeMode,
                    isExpanded: true,
                    decoration: const InputDecoration(
                      labelText: 'Charge handling',
                    ),
                    items: const [
                      DropdownMenuItem(
                        value: 'add_to_amount',
                        child: Text('Add charges to amount'),
                      ),
                      DropdownMenuItem(
                        value: 'deduct_from_amount',
                        child: Text('Deduct charges from amount'),
                      ),
                    ],
                    onChanged: (value) => setState(() => chargeMode = value!),
                  ),
                ],
                const SizedBox(height: 12),
                TextField(
                  controller: description,
                  maxLength: 1000,
                  decoration: const InputDecoration(
                    labelText: 'Description (optional)',
                  ),
                ),
              ],
            ),
          ),
          if (pending != null && !busy)
            const Padding(
              padding: EdgeInsets.only(bottom: 12),
              child: Text(
                'Confirmation was not received. Retry this same transaction to retrieve its result without posting twice. Keep this screen open until confirmed.',
              ),
            ),
          SizedBox(
            width: double.infinity,
            child: FilledButton.icon(
              onPressed: busy
                  ? null
                  : pending != null
                  ? post
                  : review,
              icon: busy
                  ? const SizedBox(
                      width: 18,
                      height: 18,
                      child: CircularProgressIndicator(strokeWidth: 2),
                    )
                  : const Icon(Icons.fact_check),
              label: Text(
                busy
                    ? 'Please wait…'
                    : pending != null
                    ? 'Retry same transaction'
                    : 'Review transaction',
              ),
            ),
          ),
        ],
      ),
    ),
  );
}

Future<void> showCashierReceipt(
  BuildContext context,
  Map receipt,
  String currency,
) => showDialog<void>(
  context: context,
  builder: (context) => AlertDialog(
    title: const Text('Transaction receipt'),
    content: SingleChildScrollView(
      child: Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          SelectableText(
            '${receipt['reference']}',
            style: Theme.of(context).textTheme.titleLarge,
          ),
          Text('${receipt['type'] ?? 'Transaction'} · ${receipt['status']}'),
          const Divider(),
          if (receipt['account'] != null)
            Text(
              '${receipt['account']['account_name'] ?? ''}\n${receipt['account']['account_number']}',
            ),
          Text('Amount: ${formatMoney(receipt['amount'])} $currency'),
          Text('Charges: ${formatMoney(receipt['charges'])} $currency'),
          Text(
            'Balance after: ${formatMoney(receipt['balance_after'])} $currency',
          ),
          Text('Cashier: ${receipt['cashier'] ?? ''}'),
          Text('Posted: ${receipt['posted_at'] ?? ''}'),
          if (receipt['description'] != null)
            Text(receipt['description'].toString()),
        ],
      ),
    ),
    actions: [
      FilledButton(
        onPressed: () => Navigator.pop(context),
        child: const Text('Done'),
      ),
    ],
  ),
);

class CashierHistoryPage extends StatefulWidget {
  const CashierHistoryPage({
    super.key,
    required this.client,
    required this.sessionId,
    required this.onExpired,
  });
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
    actions: [
      IconButton(
        onPressed: () => setState(() => history = fetch()),
        icon: const Icon(Icons.refresh),
      ),
    ],
    child: FutureBuilder<Map<String, dynamic>>(
      future: history,
      builder: (context, snapshot) {
        if (snapshot.connectionState == ConnectionState.waiting)
          return const Center(child: CircularProgressIndicator());
        if (snapshot.hasError)
          return _CashierError(
            error: snapshot.error!,
            retry: () => setState(() => history = fetch()),
          );
        final data = snapshot.data!;
        final transactions = (data['transactions'] as List? ?? []).cast<Map>();
        final movements = (data['movements'] as List? ?? []).cast<Map>();
        return ListView(
          padding: const EdgeInsets.all(16),
          children: [
            _SessionSummary(session: data['session'] as Map),
            const SizedBox(height: 20),
            _SectionTitle(
              title: 'Transactions (${transactions.length})',
              subtitle:
                  'All posts linked to this drawer session, regardless of business date.',
            ),
            if (transactions.isEmpty)
              const _EmptyCard(
                message: 'No transactions have been posted in this session.',
              ),
            for (final transaction in transactions)
              Card(
                child: ListTile(
                  leading: CircleAvatar(
                    backgroundColor: transaction['status'] == 'posted'
                        ? const Color(0xffd1fae5)
                        : const Color(0xfffef3c7),
                    child: const Icon(Icons.receipt_long),
                  ),
                  title: Text(
                    '${transaction['type'] ?? 'Transaction'} · ${formatMoney(transaction['amount'])} UGX',
                  ),
                  subtitle: Text(
                    '${transaction['reference']} · ${transaction['posted_at'] ?? ''}\n${transaction['account']?['account_number'] ?? 'Ledger'}',
                  ),
                  isThreeLine: true,
                  onTap: () => showCashierReceipt(
                    context,
                    transaction,
                    (data['session']['drawer']['currency'] ?? 'UGX').toString(),
                  ),
                ),
              ),
            _HistoryPager(
              page: transactionPage,
              pagination: data['transaction_pagination'] as Map?,
              onPrevious: transactionPage > 1
                  ? () => reload(transactions: transactionPage - 1)
                  : null,
              onNext: () => reload(transactions: transactionPage + 1),
            ),
            const SizedBox(height: 20),
            _SectionTitle(
              title: 'Drawer movements (${movements.length})',
              subtitle:
                  'Cash movement totals are calculated from this session only.',
            ),
            for (final movement in movements)
              Card(
                child: ListTile(
                  leading: Icon(
                    movement['type'] == 'cash_in'
                        ? Icons.south_west
                        : Icons.north_east,
                    color: movement['type'] == 'cash_in'
                        ? Colors.green
                        : Colors.orange,
                  ),
                  title: Text(
                    '${movement['type'] == 'cash_in' ? 'Cash in' : 'Cash out'} · ${formatMoney(movement['amount'])} UGX',
                  ),
                  subtitle: Text(
                    '${movement['reference'] ?? ''} · Balance ${formatMoney(movement['balance_after'])} UGX',
                  ),
                ),
              ),
            _HistoryPager(
              page: movementPage,
              pagination: data['movement_pagination'] as Map?,
              onPrevious: movementPage > 1
                  ? () => reload(movements: movementPage - 1)
                  : null,
              onNext: () => reload(movements: movementPage + 1),
            ),
          ],
        );
      },
    ),
  );
}

class _HistoryPager extends StatelessWidget {
  const _HistoryPager({
    required this.page,
    required this.pagination,
    required this.onPrevious,
    required this.onNext,
  });
  final int page;
  final Map? pagination;
  final VoidCallback? onPrevious;
  final VoidCallback onNext;

  @override
  Widget build(BuildContext context) {
    final lastPage =
        int.tryParse(pagination?['last_page']?.toString() ?? '') ?? page;
    return Row(
      children: [
        IconButton(
          onPressed: onPrevious,
          tooltip: 'Previous page',
          icon: const Icon(Icons.chevron_left),
        ),
        Expanded(
          child: Text(
            'Page $page of $lastPage',
            textAlign: TextAlign.center,
            style: Theme.of(context).textTheme.bodySmall,
          ),
        ),
        IconButton(
          onPressed: page < lastPage ? onNext : null,
          tooltip: 'Next page',
          icon: const Icon(Icons.chevron_right),
        ),
      ],
    );
  }
}

class _SectionTitle extends StatelessWidget {
  const _SectionTitle({required this.title, required this.subtitle});
  final String title;
  final String subtitle;
  @override
  Widget build(BuildContext context) => Padding(
    padding: const EdgeInsets.only(bottom: 10),
    child: Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(
          title,
          style: Theme.of(
            context,
          ).textTheme.titleLarge?.copyWith(fontWeight: FontWeight.w700),
        ),
        const SizedBox(height: 4),
        Text(subtitle, style: Theme.of(context).textTheme.bodySmall),
      ],
    ),
  );
}

class _EmptyCard extends StatelessWidget {
  const _EmptyCard({required this.message});
  final String message;
  @override
  Widget build(BuildContext context) => Card(
    child: Padding(
      padding: const EdgeInsets.all(18),
      child: Row(
        children: [
          const Icon(Icons.info_outline, color: Color(0xff0891b2)),
          const SizedBox(width: 10),
          Expanded(child: Text(message)),
        ],
      ),
    ),
  );
}

class _CashierError extends StatelessWidget {
  const _CashierError({required this.error, required this.retry});
  final Object error;
  final VoidCallback retry;
  @override
  Widget build(BuildContext context) => Center(
    child: Padding(
      padding: const EdgeInsets.all(24),
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          const Icon(Icons.cloud_off, size: 48),
          const SizedBox(height: 12),
          Text(error.toString(), textAlign: TextAlign.center),
          const SizedBox(height: 16),
          FilledButton(onPressed: retry, child: const Text('Retry')),
        ],
      ),
    ),
  );
}

String requestId() {
  final random = Random.secure();
  final suffix = List.generate(
    12,
    (_) => random.nextInt(16).toRadixString(16),
  ).join();
  return '00000000-0000-4000-8000-$suffix';
}

String formatMoney(dynamic value) {
  final number = double.tryParse(value?.toString() ?? '') ?? 0;
  return number.toStringAsFixed(number.truncateToDouble() == number ? 0 : 2);
}

class CashierSessionsPage extends StatefulWidget {
  const CashierSessionsPage({
    super.key,
    required this.client,
    required this.onExpired,
  });
  final ApiClient client;
  final VoidCallback onExpired;
  @override
  State<CashierSessionsPage> createState() => _CashierSessionsPageState();
}

class _CashierSessionsPageState extends State<CashierSessionsPage> {
  int page = 1;
  late Future<Map<String, dynamic>> sessions;
  @override
  void initState() {
    super.initState();
    sessions = fetch();
  }

  Future<Map<String, dynamic>> fetch() =>
      widget.client.get('cashier/sessions', query: {'page': '$page'});
  void reload(int value) => setState(() {
    page = value;
    sessions = fetch();
  });
  @override
  Widget build(BuildContext context) => _CashierShell(
    title: 'Drawer sessions',
    child: FutureBuilder<Map<String, dynamic>>(
      future: sessions,
      builder: (context, snapshot) {
        if (snapshot.connectionState == ConnectionState.waiting)
          return const Center(child: CircularProgressIndicator());
        if (snapshot.hasError)
          return _CashierError(
            error: snapshot.error!,
            retry: () => reload(page),
          );
        final rows = (snapshot.data!['data'] as List).cast<Map>();
        return ListView(
          padding: const EdgeInsets.all(16),
          children: [
            if (rows.isEmpty)
              const _EmptyCard(message: 'No drawer sessions yet.'),
            for (final session in rows)
              Card(
                child: ListTile(
                  leading: const Icon(Icons.point_of_sale),
                  title: Text(
                    '${session['drawer']['name']} · ${session['status']}',
                  ),
                  subtitle: Text(
                    'Opened ${session['opened_at'] ?? ''}\n${session['branch'] ?? ''}',
                  ),
                  trailing: const Icon(Icons.chevron_right),
                  onTap: () => Navigator.of(context).push(
                    MaterialPageRoute<void>(
                      builder: (_) => CashierHistoryPage(
                        client: widget.client,
                        sessionId: session['id'] as int,
                        onExpired: widget.onExpired,
                      ),
                    ),
                  ),
                ),
              ),
            _HistoryPager(
              page: page,
              pagination: snapshot.data!['meta']?['pagination'] as Map?,
              onPrevious: page > 1 ? () => reload(page - 1) : null,
              onNext: () => reload(page + 1),
            ),
          ],
        );
      },
    ),
  );
}
