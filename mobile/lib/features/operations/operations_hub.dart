import 'dart:math';

import 'package:flutter/material.dart';
import 'package:image_picker/image_picker.dart';

import '../../core/network/api_client.dart';
import 'receipt_export.dart';

class OperationsHub extends StatefulWidget {
  const OperationsHub({
    super.key,
    required this.client,
    required this.user,
    this.initialTab = 0,
    this.initialLoanId,
  });
  final ApiClient client;
  final Map<String, dynamic> user;
  final int initialTab;
  final int? initialLoanId;

  @override
  State<OperationsHub> createState() => _OperationsHubState();
}

class _OperationsHubState extends State<OperationsHub> {
  late int tab;

  @override
  void initState() {
    super.initState();
    tab = widget.initialTab.clamp(0, 4);
    if (widget.initialLoanId != null) {
      WidgetsBinding.instance.addPostFrameCallback((_) {
        if (!mounted) return;
        Navigator.of(context).push(
          MaterialPageRoute<void>(
            builder: (_) => LoanDetailPage(
              client: widget.client,
              user: widget.user,
              loanId: widget.initialLoanId!,
            ),
          ),
        );
      });
    }
  }

  @override
  Widget build(BuildContext context) => Scaffold(
    appBar: AppBar(title: const Text('Operations')),
    body: IndexedStack(
      index: tab,
      children: [
        ClientSearchPage(client: widget.client, user: widget.user),
        LoanServicingPage(client: widget.client, user: widget.user),
        SavingsLoanTransferPage(client: widget.client, user: widget.user),
        NonCashPage(client: widget.client),
        OperationsAlertsPage(client: widget.client, user: widget.user),
      ],
    ),
    bottomNavigationBar: NavigationBar(
      selectedIndex: tab,
      onDestinationSelected: (value) => setState(() => tab = value),
      destinations: const [
        NavigationDestination(
          icon: Icon(Icons.people_outline),
          label: 'Clients',
        ),
        NavigationDestination(
          icon: Icon(Icons.payments_outlined),
          label: 'Loans',
        ),
        NavigationDestination(icon: Icon(Icons.swap_horiz), label: 'Transfer'),
        NavigationDestination(
          icon: Icon(Icons.account_balance),
          label: 'Non-cash',
        ),
        NavigationDestination(
          icon: Icon(Icons.notifications_outlined),
          label: 'Alerts',
        ),
      ],
    ),
  );
}

class ClientSearchPage extends StatefulWidget {
  const ClientSearchPage({super.key, required this.client, required this.user});
  final ApiClient client;
  final Map<String, dynamic> user;

  @override
  State<ClientSearchPage> createState() => _ClientSearchPageState();
}

class _ClientSearchPageState extends State<ClientSearchPage> {
  final search = TextEditingController();
  Future<Map<String, dynamic>>? results;
  Future<List<Map<String, dynamic>>>? offlineResults;

  Future<void> lookup() async {
    if (search.text.trim().length < 2) return;
    setState(() {
      offlineResults = null;
      results = widget.client.get(
        'clients',
        query: {'search': search.text.trim(), 'per_page': '30'},
      );
    });
  }

  void lookupOffline() {
    if (search.text.trim().length < 2) return;
    setState(() {
      results = null;
      offlineResults = widget.client.searchOfflineClients(search.text);
    });
  }

  Future<void> registerOfflineClient() async {
    final firstName = TextEditingController();
    final lastName = TextEditingController();
    final phone = TextEditingController();
    final confirmed = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        title: const Text('Register client offline'),
        content: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            TextField(
              controller: firstName,
              decoration: const InputDecoration(labelText: 'First name'),
            ),
            TextField(
              controller: lastName,
              decoration: const InputDecoration(labelText: 'Last name'),
            ),
            TextField(
              controller: phone,
              keyboardType: TextInputType.phone,
              decoration: const InputDecoration(labelText: 'Phone number'),
            ),
          ],
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(context, false),
            child: const Text('Cancel'),
          ),
          FilledButton(
            onPressed: () => Navigator.pop(context, true),
            child: const Text('Save offline'),
          ),
        ],
      ),
    );
    if (confirmed != true) {
      firstName.dispose();
      lastName.dispose();
      phone.dispose();
      return;
    }
    final branchId = int.tryParse(widget.user['branch_id']?.toString() ?? '');
    if (branchId == null ||
        firstName.text.trim().isEmpty ||
        lastName.text.trim().isEmpty ||
        phone.text.trim().isEmpty) {
      if (mounted) {
        _message(
          context,
          'Your branch, first name, last name, and phone are required.',
          error: true,
        );
      }
    } else {
      await widget.client.stageOfflineClient(
        payload: {
          'branch_id': branchId,
          'client_type': 'individual',
          'first_name': firstName.text.trim(),
          'last_name': lastName.text.trim(),
          'phone': phone.text.trim(),
          'kyc_status': 'pending',
          'status': 'pending',
        },
      );
      if (mounted) {
        _message(
          context,
          'Client registration saved offline. Sync it from Alerts when connected.',
        );
      }
    }
    firstName.dispose();
    lastName.dispose();
    phone.dispose();
  }

  @override
  void dispose() {
    search.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) => ListView(
    padding: const EdgeInsets.all(16),
    children: [
      Text('Find a client', style: Theme.of(context).textTheme.headlineSmall),
      const Text('Search by client number, name, phone, or national ID.'),
      const SizedBox(height: 16),
      TextField(
        controller: search,
        textInputAction: TextInputAction.search,
        onSubmitted: (_) => lookup(),
        decoration: InputDecoration(
          labelText: 'Search clients',
          prefixIcon: const Icon(Icons.search),
          suffixIcon: IconButton(
            onPressed: lookup,
            icon: const Icon(Icons.arrow_forward),
          ),
        ),
      ),
      Align(
        alignment: Alignment.centerRight,
        child: Wrap(
          children: [
            TextButton.icon(
              onPressed: lookupOffline,
              icon: const Icon(Icons.offline_bolt_outlined),
              label: const Text('Search offline'),
            ),
            TextButton.icon(
              onPressed: registerOfflineClient,
              icon: const Icon(Icons.person_add_alt_1_outlined),
              label: const Text('Register offline'),
            ),
          ],
        ),
      ),
      const SizedBox(height: 12),
      if (results == null)
        const _Empty(
          message:
              'Search to view client accounts, loans, and financial position.',
        ),
      if (results != null)
        FutureBuilder<Map<String, dynamic>>(
          future: results,
          builder: (context, snapshot) {
            if (snapshot.connectionState == ConnectionState.waiting) {
              return const Center(
                child: Padding(
                  padding: EdgeInsets.all(24),
                  child: CircularProgressIndicator(),
                ),
              );
            }
            if (snapshot.hasError) {
              return _Retry(error: snapshot.error!, retry: lookup);
            }
            final rows = (snapshot.data?['data'] as List? ?? []).cast<Map>();
            if (rows.isEmpty) {
              return const _Empty(message: 'No matching clients found.');
            }
            return Column(
              children: [
                for (final row in rows)
                  Card(
                    child: ListTile(
                      leading: const CircleAvatar(
                        child: Icon(Icons.person_outline),
                      ),
                      title: Text(row['full_name']?.toString() ?? 'Client'),
                      subtitle: Text(
                        '${row['client_number'] ?? ''} · ${row['phone'] ?? ''}\n${row['kyc_status'] ?? ''} · ${row['status'] ?? ''}',
                      ),
                      isThreeLine: true,
                      trailing: const Icon(Icons.chevron_right),
                      onTap: () => Navigator.of(context).push(
                        MaterialPageRoute<void>(
                          builder: (_) => ClientOperationsDetail(
                            client: widget.client,
                            user: widget.user,
                            clientId: row['id'] as int,
                          ),
                        ),
                      ),
                    ),
                  ),
              ],
            );
          },
        ),
      if (offlineResults != null)
        FutureBuilder<List<Map<String, dynamic>>>(
          future: offlineResults,
          builder: (context, snapshot) {
            if (snapshot.connectionState == ConnectionState.waiting) {
              return const Center(child: CircularProgressIndicator());
            }
            final rows = snapshot.data ?? [];
            if (rows.isEmpty) {
              return const _Empty(
                message: 'No matching client is stored on this device yet.',
              );
            }
            return Column(
              children: [
                const _OfflineNotice(
                  'Showing the last synced client directory. Connect to view live account and loan balances.',
                ),
                for (final row in rows)
                  Card(
                    child: ListTile(
                      leading: const CircleAvatar(
                        child: Icon(Icons.person_outline),
                      ),
                      title: Text(row['full_name']?.toString() ?? 'Client'),
                      subtitle: Text(
                        '${row['client_number'] ?? ''} · ${row['phone'] ?? ''}',
                      ),
                    ),
                  ),
              ],
            );
          },
        ),
    ],
  );
}

class ClientOperationsDetail extends StatefulWidget {
  const ClientOperationsDetail({
    super.key,
    required this.client,
    required this.user,
    required this.clientId,
  });
  final ApiClient client;
  final Map<String, dynamic> user;
  final int clientId;

  @override
  State<ClientOperationsDetail> createState() => _ClientOperationsDetailState();
}

class _ClientOperationsDetailState extends State<ClientOperationsDetail> {
  late Future<List<Map<String, dynamic>>> data;

  @override
  void initState() {
    super.initState();
    data = load();
  }

  Future<List<Map<String, dynamic>>> load() async {
    final response = await Future.wait([
      widget.client.get('clients/${widget.clientId}', query: {'detailed': '1'}),
      widget.client.get('clients/${widget.clientId}/financial-overview'),
      widget.client.get('clients/${widget.clientId}/accounts'),
      widget.client.get('clients/${widget.clientId}/loans'),
    ]);
    return response.map(Map<String, dynamic>.from).toList();
  }

  Future<void> stageProfileUpdate(Map profile) async {
    final firstName = TextEditingController(
      text: profile['first_name']?.toString(),
    );
    final lastName = TextEditingController(
      text: profile['last_name']?.toString(),
    );
    final phone = TextEditingController(text: profile['phone']?.toString());
    final confirmed = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        title: const Text('Stage profile update offline'),
        content: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            TextField(
              controller: firstName,
              decoration: const InputDecoration(labelText: 'First name'),
            ),
            TextField(
              controller: lastName,
              decoration: const InputDecoration(labelText: 'Last name'),
            ),
            TextField(
              controller: phone,
              decoration: const InputDecoration(labelText: 'Phone number'),
            ),
          ],
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(context, false),
            child: const Text('Cancel'),
          ),
          FilledButton(
            onPressed: () => Navigator.pop(context, true),
            child: const Text('Stage update'),
          ),
        ],
      ),
    );
    if (confirmed == true) {
      final branchId = int.tryParse(
        (profile['branch'] as Map?)?['id']?.toString() ?? '',
      );
      if (branchId == null) {
        if (mounted) {
          _message(
            context,
            'A branch is required to stage this change.',
            error: true,
          );
        }
      } else {
        await widget.client.stageOfflineClient(
          serverId: widget.clientId,
          serverUpdatedAt: profile['updated_at']?.toString(),
          payload: {
            'branch_id': branchId,
            'client_type': profile['client_type'] ?? 'individual',
            'first_name': firstName.text.trim(),
            'last_name': lastName.text.trim(),
            'phone': phone.text.trim(),
            'kyc_status': profile['kyc_status'] ?? 'pending',
            'status': profile['status'] ?? 'pending',
          },
        );
        if (mounted) {
          _message(
            context,
            'Profile update staged offline. Sync it from Alerts when connected.',
          );
        }
      }
    }
    firstName.dispose();
    lastName.dispose();
    phone.dispose();
  }

  @override
  Widget build(BuildContext context) => Scaffold(
    appBar: AppBar(title: const Text('Client profile')),
    body: FutureBuilder<List<Map<String, dynamic>>>(
      future: data,
      builder: (context, snapshot) {
        if (snapshot.connectionState == ConnectionState.waiting) {
          return const Center(child: CircularProgressIndicator());
        }
        if (snapshot.hasError) {
          return _Retry(
            error: snapshot.error!,
            retry: () => setState(() => data = load()),
          );
        }
        final profile = snapshot.data![0]['data'] as Map? ?? {};
        final overview = snapshot.data![1]['data'] as Map? ?? {};
        final accounts = (snapshot.data![2]['data'] as List? ?? []).cast<Map>();
        final loans = (snapshot.data![3]['data'] as List? ?? []).cast<Map>();
        return ListView(
          padding: const EdgeInsets.all(16),
          children: [
            Text(
              profile['full_name']?.toString() ?? 'Client',
              style: Theme.of(context).textTheme.headlineSmall,
            ),
            Text(
              '${profile['client_number'] ?? ''} · ${profile['phone'] ?? ''}',
            ),
            const SizedBox(height: 12),
            _Metrics(
              values: {
                'Savings': overview['total_savings'],
                'Outstanding loans': overview['outstanding_loan_balance'],
                'Available': overview['available_balance'],
              },
            ),
            Wrap(
              spacing: 8,
              runSpacing: 8,
              children: [
                OutlinedButton.icon(
                  onPressed: () => Navigator.of(context).push(
                    MaterialPageRoute<void>(
                      builder: (_) => ClientRecordsPage(
                        client: widget.client,
                        clientId: widget.clientId,
                        clientName:
                            profile['full_name']?.toString() ?? 'Client',
                      ),
                    ),
                  ),
                  icon: const Icon(Icons.receipt_long_outlined),
                  label: const Text('Statements & documents'),
                ),
                OutlinedButton.icon(
                  onPressed: () => stageProfileUpdate(profile),
                  icon: const Icon(Icons.edit_note_outlined),
                  label: const Text('Stage offline update'),
                ),
              ],
            ),
            const _Heading('Savings accounts'),
            if (accounts.isEmpty) const _Empty(message: 'No savings accounts.'),
            for (final account in accounts)
              Card(
                child: ListTile(
                  title: Text(
                    account['account_number']?.toString() ?? 'Savings account',
                  ),
                  subtitle: Text(
                    'Balance: ${_money(account['current_balance'])} · ${account['status'] ?? ''}',
                  ),
                ),
              ),
            const _Heading('Loans'),
            if (loans.isEmpty) const _Empty(message: 'No loans found.'),
            for (final loan in loans)
              Card(
                child: ListTile(
                  title: Text(loan['loan_number']?.toString() ?? 'Loan'),
                  subtitle: Text(
                    '${loan['status'] ?? ''} · Outstanding ${_loanBalance(loan)}',
                  ),
                  trailing: const Icon(Icons.chevron_right),
                  onTap: () => Navigator.of(context).push(
                    MaterialPageRoute<void>(
                      builder: (_) => LoanDetailPage(
                        client: widget.client,
                        user: widget.user,
                        loanId: loan['id'] as int,
                      ),
                    ),
                  ),
                ),
              ),
          ],
        );
      },
    ),
  );
}

class ClientRecordsPage extends StatefulWidget {
  const ClientRecordsPage({
    super.key,
    required this.client,
    required this.clientId,
    required this.clientName,
  });
  final ApiClient client;
  final int clientId;
  final String clientName;

  @override
  State<ClientRecordsPage> createState() => _ClientRecordsPageState();
}

class _ClientRecordsPageState extends State<ClientRecordsPage> {
  late Future<List<Map<String, dynamic>>> records;

  @override
  void initState() {
    super.initState();
    records = load();
  }

  Future<List<Map<String, dynamic>>> load() async => (await Future.wait([
    widget.client.get('clients/${widget.clientId}/statements'),
    widget.client.get('clients/${widget.clientId}/documents'),
  ])).map(Map<String, dynamic>.from).toList();

  Future<void> captureDocument() async {
    final image = await ImagePicker().pickImage(
      source: ImageSource.camera,
      imageQuality: 80,
      maxWidth: 1800,
    );
    if (image == null) return;
    try {
      await widget.client.postMultipart(
        'clients/${widget.clientId}/documents',
        fields: {
          'type': 'national_id_front',
          'title': 'Mobile KYC capture',
          'captured_at': DateTime.now().toIso8601String(),
          'local_uuid': _operationId(),
        },
        fileField: 'document',
        fileName: image.name,
        fileBytes: await image.readAsBytes(),
      );
      if (mounted) {
        _message(context, 'KYC document uploaded for verification.');
        setState(() => records = load());
      }
    } on ApiException catch (error) {
      if (mounted) _message(context, error.message, error: true);
    }
  }

  @override
  Widget build(BuildContext context) => Scaffold(
    appBar: AppBar(
      title: Text('${widget.clientName} records'),
      actions: [
        IconButton(
          tooltip: 'Capture KYC document',
          onPressed: captureDocument,
          icon: const Icon(Icons.camera_alt_outlined),
        ),
      ],
    ),
    body: FutureBuilder<List<Map<String, dynamic>>>(
      future: records,
      builder: (context, snapshot) {
        if (snapshot.connectionState == ConnectionState.waiting) {
          return const Center(child: CircularProgressIndicator());
        }
        if (snapshot.hasError) {
          return _Retry(
            error: snapshot.error!,
            retry: () => setState(() => records = load()),
          );
        }
        final statements = (snapshot.data![0]['data'] as List? ?? [])
            .cast<Map>();
        final documents = (snapshot.data![1]['data'] as List? ?? [])
            .cast<Map>();
        return RefreshIndicator(
          onRefresh: () async => setState(() => records = load()),
          child: ListView(
            physics: const AlwaysScrollableScrollPhysics(),
            padding: const EdgeInsets.all(16),
            children: [
              const Text(
                'A read-only audit trail of the client’s savings activity and KYC documents.',
              ),
              Align(
                alignment: Alignment.centerLeft,
                child: TextButton.icon(
                  onPressed: statements.isEmpty
                      ? null
                      : () => shareStatementPdf(
                          clientName: widget.clientName,
                          transactions: statements,
                        ),
                  icon: const Icon(Icons.picture_as_pdf_outlined),
                  label: const Text('Share activity PDF'),
                ),
              ),
              const _Heading('Recent account activity'),
              if (statements.isEmpty)
                const _Empty(message: 'No savings activity found.'),
              for (final entry in statements)
                Card(
                  child: ListTile(
                    leading: const Icon(Icons.receipt_long_outlined),
                    title: Text(
                      entry['reference']?.toString() ?? 'Transaction',
                    ),
                    subtitle: Text(
                      '${entry['type'] ?? ''} · ${entry['transaction_date'] ?? ''}\n${entry['narration'] ?? entry['payment_method'] ?? ''}',
                    ),
                    isThreeLine: true,
                    trailing: Text(_money(entry['amount'])),
                  ),
                ),
              const _Heading('KYC documents'),
              if (documents.isEmpty)
                const _Empty(message: 'No KYC documents are available.'),
              for (final document in documents)
                Card(
                  child: ListTile(
                    leading: const Icon(Icons.description_outlined),
                    title: Text(document['title']?.toString() ?? 'Document'),
                    subtitle: Text(
                      '${document['type'] ?? ''} · ${document['verification_status'] ?? 'pending'}',
                    ),
                    trailing: Text(
                      document['created_at']?.toString().split('T').first ?? '',
                    ),
                  ),
                ),
            ],
          ),
        );
      },
    ),
  );
}

class LoanServicingPage extends StatefulWidget {
  const LoanServicingPage({
    super.key,
    required this.client,
    required this.user,
  });
  final ApiClient client;
  final Map<String, dynamic> user;
  @override
  State<LoanServicingPage> createState() => _LoanServicingPageState();
}

class _LoanServicingPageState extends State<LoanServicingPage> {
  int tab = 0;
  late Future<Map<String, dynamic>> rows;
  @override
  void initState() {
    super.initState();
    rows = load();
  }

  Future<Map<String, dynamic>> load() => widget.client.get(
    tab == 0 ? 'mobile-operations/due-repayments' : 'loans/arrears/list',
    query: {'per_page': '30'},
  );
  void reload() => setState(() => rows = load());
  @override
  Widget build(BuildContext context) => ListView(
    padding: const EdgeInsets.all(16),
    children: [
      SegmentedButton<int>(
        segments: const [
          ButtonSegment(value: 0, label: Text('Due')),
          ButtonSegment(value: 1, label: Text('Arrears')),
        ],
        selected: {tab},
        onSelectionChanged: (value) {
          setState(() {
            tab = value.first;
            rows = load();
          });
        },
      ),
      const SizedBox(height: 12),
      FutureBuilder<Map<String, dynamic>>(
        future: rows,
        builder: (context, snapshot) {
          if (snapshot.connectionState == ConnectionState.waiting) {
            return const Center(child: CircularProgressIndicator());
          }
          if (snapshot.hasError) {
            return _Retry(error: snapshot.error!, retry: reload);
          }
          final items = (snapshot.data?['data'] as List? ?? []).cast<Map>();
          if (items.isEmpty) {
            return const _Empty(message: 'No repayment items found.');
          }
          return Column(
            children: [
              for (final row in items)
                _ScheduleCard(
                  row: row,
                  client: widget.client,
                  user: widget.user,
                ),
            ],
          );
        },
      ),
    ],
  );
}

class _ScheduleCard extends StatelessWidget {
  const _ScheduleCard({
    required this.row,
    required this.client,
    required this.user,
  });
  final Map row;
  final ApiClient client;
  final Map<String, dynamic> user;
  @override
  Widget build(BuildContext context) {
    final loan = row['loan'] as Map? ?? {};
    return Card(
      child: ListTile(
        leading: const Icon(Icons.event_note),
        title: Text(
          '${loan['loan_number'] ?? 'Loan'} · ${loan['client']?['name'] ?? ''}',
        ),
        subtitle: Text(
          'Due ${row['due_date'] ?? ''} · ${row['days_overdue'] ?? 0} days overdue\nOutstanding ${_money(row['outstanding_amount'])}',
        ),
        isThreeLine: true,
        trailing: const Icon(Icons.chevron_right),
        onTap: loan['id'] == null
            ? null
            : () => Navigator.of(context).push(
                MaterialPageRoute<void>(
                  builder: (_) => LoanDetailPage(
                    client: client,
                    user: user,
                    loanId: loan['id'] as int,
                  ),
                ),
              ),
      ),
    );
  }
}

class LoanDetailPage extends StatefulWidget {
  const LoanDetailPage({
    super.key,
    required this.client,
    required this.user,
    required this.loanId,
  });
  final ApiClient client;
  final Map<String, dynamic> user;
  final int loanId;
  @override
  State<LoanDetailPage> createState() => _LoanDetailPageState();
}

class _LoanDetailPageState extends State<LoanDetailPage> {
  late Future<List<Map<String, dynamic>>> data;
  @override
  void initState() {
    super.initState();
    data = load();
  }

  Future<List<Map<String, dynamic>>> load() async => (await Future.wait([
    widget.client.get('loans/${widget.loanId}', query: {'detailed': '1'}),
    widget.client.get('loans/${widget.loanId}/schedule'),
  ])).map(Map<String, dynamic>.from).toList();

  Future<void> recordRepayment() async {
    final amount = TextEditingController();
    final reference = TextEditingController();
    var paymentMethod = 'cash';
    final confirmed = await showDialog<bool>(
      context: context,
      builder: (context) => StatefulBuilder(
        builder: (context, setDialogState) => AlertDialog(
          title: const Text('Record loan repayment'),
          content: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              TextField(
                controller: amount,
                keyboardType: TextInputType.number,
                decoration: const InputDecoration(labelText: 'Amount (UGX)'),
              ),
              DropdownButtonFormField<String>(
                initialValue: paymentMethod,
                decoration: const InputDecoration(labelText: 'Payment method'),
                items: const [
                  DropdownMenuItem(value: 'cash', child: Text('Cash')),
                  DropdownMenuItem(
                    value: 'mobile_money',
                    child: Text('Mobile money'),
                  ),
                  DropdownMenuItem(value: 'bank', child: Text('Bank')),
                  DropdownMenuItem(value: 'cheque', child: Text('Cheque')),
                ],
                onChanged: (value) => setDialogState(
                  () => paymentMethod = value ?? paymentMethod,
                ),
              ),
              TextField(
                controller: reference,
                decoration: const InputDecoration(
                  labelText: 'Reference (optional)',
                ),
              ),
            ],
          ),
          actions: [
            TextButton(
              onPressed: () => Navigator.pop(context, false),
              child: const Text('Cancel'),
            ),
            FilledButton(
              onPressed: () => Navigator.pop(context, true),
              child: const Text('Post repayment'),
            ),
          ],
        ),
      ),
    );
    if (confirmed != true) {
      amount.dispose();
      reference.dispose();
      return;
    }
    try {
      final response = await widget.client.requestFinancial(
        'POST',
        'loans/${widget.loanId}/repayments',
        operationId: _operationId(),
        body: {
          'amount': double.tryParse(amount.text),
          'payment_method': paymentMethod,
          'reference': reference.text.trim().isEmpty
              ? null
              : reference.text.trim(),
          'paid_at': DateTime.now().toIso8601String(),
        },
      );
      if (mounted) {
        _showReceipt(context, response['data'] as Map? ?? {});
        setState(() => data = load());
      }
    } on ApiException catch (error) {
      if (mounted) _message(context, error.message, error: true);
    } finally {
      amount.dispose();
      reference.dispose();
    }
  }

  Future<void> writeOff() async {
    final amount = TextEditingController();
    final confirmed = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        title: const Text('Partial arrears write-off'),
        content: TextField(
          controller: amount,
          keyboardType: TextInputType.number,
          decoration: const InputDecoration(labelText: 'Amount'),
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(context, false),
            child: const Text('Cancel'),
          ),
          FilledButton(
            onPressed: () => Navigator.pop(context, true),
            child: const Text('Write off'),
          ),
        ],
      ),
    );
    if (confirmed != true) return;
    try {
      await widget.client.requestFinancial(
        'POST',
        'mobile-operations/loans/${widget.loanId}/arrears/write-off',
        operationId: _operationId(),
        body: {'amount': double.tryParse(amount.text)},
      );
      if (mounted) {
        _message(context, 'Arrears write-off posted.');
        setState(() => data = load());
      }
    } on ApiException catch (error) {
      if (mounted) _message(context, error.message, error: true);
    } finally {
      amount.dispose();
    }
  }

  @override
  Widget build(BuildContext context) => Scaffold(
    appBar: AppBar(title: const Text('Loan detail')),
    body: FutureBuilder<List<Map<String, dynamic>>>(
      future: data,
      builder: (context, snapshot) {
        if (snapshot.connectionState == ConnectionState.waiting) {
          return const Center(child: CircularProgressIndicator());
        }
        if (snapshot.hasError) {
          return _Retry(
            error: snapshot.error!,
            retry: () => setState(() => data = load()),
          );
        }
        final loan = snapshot.data![0]['data'] as Map? ?? {};
        final schedule = (snapshot.data![1]['data'] as List? ?? []).cast<Map>();
        final canWriteOff = [
          'super-admin',
          'organization-admin',
        ].contains(widget.user['role']);
        final canPostRepayment =
            widget.user['role'] != 'loan-recovery-officer' &&
            widget.user['role'] != 'member';
        return ListView(
          padding: const EdgeInsets.all(16),
          children: [
            Text(
              loan['loan_number']?.toString() ?? 'Loan',
              style: Theme.of(context).textTheme.headlineSmall,
            ),
            Text(
              '${loan['status'] ?? ''} · ${loan['client']?['full_name'] ?? ''}',
            ),
            _Metrics(
              values: {
                'Principal': loan['principal_balance'],
                'Interest': loan['interest_balance'],
                'Fees': loan['fees_balance'],
                'Penalties': loan['penalty_balance'],
              },
            ),
            if (canPostRepayment)
              Padding(
                padding: const EdgeInsets.only(top: 12),
                child: FilledButton.icon(
                  onPressed: recordRepayment,
                  icon: const Icon(Icons.payments_outlined),
                  label: const Text('Record repayment'),
                ),
              ),
            if (canWriteOff &&
                ((loan['penalty_balance'] as num? ?? 0) > 0 ||
                    schedule.any(
                      (row) => (row['days_overdue'] as num? ?? 0) > 0,
                    )))
              Padding(
                padding: const EdgeInsets.only(top: 12),
                child: OutlinedButton.icon(
                  onPressed: writeOff,
                  icon: const Icon(Icons.remove_circle_outline),
                  label: const Text('Write off arrears amount'),
                ),
              ),
            const _Heading('Repayment schedule'),
            for (final row in schedule)
              Card(
                child: ListTile(
                  title: Text(
                    'Installment ${row['installment_number']} · ${row['due_date']}',
                  ),
                  subtitle: Text(
                    '${row['status']} · Outstanding ${_money(row['outstanding_amount'])}',
                  ),
                  trailing: Text('${row['days_overdue'] ?? 0}d'),
                ),
              ),
          ],
        );
      },
    ),
  );
}

class SavingsLoanTransferPage extends StatefulWidget {
  const SavingsLoanTransferPage({
    super.key,
    required this.client,
    required this.user,
  });
  final ApiClient client;
  final Map<String, dynamic> user;
  @override
  State<SavingsLoanTransferPage> createState() =>
      _SavingsLoanTransferPageState();
}

class _SavingsLoanTransferPageState extends State<SavingsLoanTransferPage> {
  final search = TextEditingController();
  final amount = TextEditingController();
  Future<Map<String, dynamic>>? sources;
  Future<Map<String, dynamic>>? schedules;
  Map? account;
  Map? loan;
  List<Map> loans = [];
  String frequency = 'monthly';
  @override
  void initState() {
    super.initState();
    schedules = widget.client.get('mobile-operations/scheduled-deductions');
  }

  Future<void> lookup() async {
    if (search.text.trim().length < 2) return;
    setState(
      () => sources = widget.client.get(
        'mobile-operations/savings-loan-sources',
        query: {'search': search.text.trim()},
      ),
    );
  }

  Future<void> choose(Map value) async {
    setState(() {
      account = value;
      loan = null;
      loans = [];
    });
    final response = await widget.client.get(
      'mobile-operations/savings-loan-sources/${value['id']}/loans',
    );
    if (mounted) {
      setState(
        () => loans = ((response['data'] as Map?)?['loans'] as List? ?? [])
            .cast<Map>(),
      );
    }
  }

  Future<void> repay() async {
    if (account == null ||
        loan == null ||
        double.tryParse(amount.text) == null) {
      return;
    }
    try {
      await widget.client.requestFinancial(
        'POST',
        'mobile-operations/loans/${loan!['id']}/repay-from-account-savings',
        operationId: _operationId(),
        body: {
          'account_id': account!['id'],
          'amount': double.parse(amount.text),
        },
      );
      if (mounted) {
        _message(context, 'Savings repayment posted.');
        setState(() {
          amount.clear();
        });
      }
    } on ApiException catch (error) {
      if (mounted) {
        _message(context, error.message, error: true);
      }
    }
  }

  Future<void> schedule() async {
    if (account == null ||
        loan == null ||
        double.tryParse(amount.text) == null) {
      return;
    }
    try {
      await widget.client.requestFinancial(
        'POST',
        'mobile-operations/scheduled-deductions',
        operationId: _operationId(),
        body: {
          'account_id': account!['id'],
          'loan_id': loan!['id'],
          'amount': double.parse(amount.text),
          'frequency': frequency,
          'next_run_at': DateTime.now()
              .add(const Duration(days: 1))
              .toIso8601String(),
        },
      );
      if (mounted) {
        _message(context, 'Schedule created for administrator activation.');
        setState(
          () => schedules = widget.client.get(
            'mobile-operations/scheduled-deductions',
          ),
        );
      }
    } on ApiException catch (error) {
      if (mounted) _message(context, error.message, error: true);
    }
  }

  Future<void> activateSchedule(Map schedule) async {
    try {
      await widget.client.request(
        'POST',
        'mobile-operations/scheduled-deductions/${schedule['id']}/activate',
      );
      if (mounted) {
        _message(context, 'Scheduled deduction activated.');
        setState(
          () => schedules = widget.client.get(
            'mobile-operations/scheduled-deductions',
          ),
        );
      }
    } on ApiException catch (error) {
      if (mounted) {
        _message(context, error.message, error: true);
      }
    }
  }

  Future<void> changeScheduleStatus(Map schedule, String status) async {
    try {
      await widget.client.request(
        'POST',
        'mobile-operations/scheduled-deductions/${schedule['id']}/disable',
        body: {'status': status},
      );
      if (mounted) {
        _message(
          context,
          status == 'cancelled'
              ? 'Scheduled deduction cancelled.'
              : 'Scheduled deduction disabled.',
        );
        setState(
          () => schedules = widget.client.get(
            'mobile-operations/scheduled-deductions',
          ),
        );
      }
    } on ApiException catch (error) {
      if (mounted) _message(context, error.message, error: true);
    }
  }

  Future<void> showScheduleRuns(Map schedule) async {
    try {
      final response = await widget.client.get(
        'mobile-operations/scheduled-deductions/${schedule['id']}/runs',
      );
      if (!mounted) return;
      final runs = (response['data'] as List? ?? []).cast<Map>();
      await showModalBottomSheet<void>(
        context: context,
        showDragHandle: true,
        builder: (context) => SafeArea(
          child: ListView(
            padding: const EdgeInsets.all(16),
            children: [
              Text(
                'Run history · ${schedule['loan_number']}',
                style: Theme.of(context).textTheme.titleLarge,
              ),
              if (runs.isEmpty)
                const _Empty(message: 'This schedule has not run yet.'),
              for (final run in runs)
                ListTile(
                  leading: Icon(
                    run['status'] == 'succeeded'
                        ? Icons.check_circle_outline
                        : Icons.error_outline,
                  ),
                  title: Text('${run['status']} · ${_money(run['amount'])}'),
                  subtitle: Text(
                    '${run['processed_at'] ?? run['due_at'] ?? ''}\n${run['failure_reason'] ?? ''}',
                  ),
                  isThreeLine: run['failure_reason'] != null,
                ),
            ],
          ),
        ),
      );
    } on ApiException catch (error) {
      if (mounted) _message(context, error.message, error: true);
    }
  }

  @override
  void dispose() {
    search.dispose();
    amount.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) => ListView(
    padding: const EdgeInsets.all(16),
    children: [
      Text('Savings to loan', style: Theme.of(context).textTheme.headlineSmall),
      const Text(
        'Find a client savings account, then pay an unpaid loan installment without drawer cash.',
      ),
      const SizedBox(height: 12),
      TextField(
        controller: search,
        onSubmitted: (_) => lookup(),
        decoration: InputDecoration(
          labelText: 'Client, phone, or savings account',
          suffixIcon: IconButton(
            onPressed: lookup,
            icon: const Icon(Icons.search),
          ),
        ),
      ),
      if (sources != null)
        FutureBuilder<Map<String, dynamic>>(
          future: sources,
          builder: (context, snapshot) {
            if (!snapshot.hasData) return const LinearProgressIndicator();
            final rows = (snapshot.data?['data'] as List? ?? []).cast<Map>();
            return Column(
              children: [
                for (final row in rows)
                  ListTile(
                    title: Text(
                      '${row['client']?['name'] ?? ''} · ${row['account_number']}',
                    ),
                    subtitle: Text('Available ${_money(row['balance'])}'),
                    trailing: const Icon(Icons.chevron_right),
                    onTap: () => choose(row),
                  ),
              ],
            );
          },
        ),
      if (account != null)
        Card(
          color: Theme.of(context).colorScheme.primaryContainer,
          child: ListTile(
            title: Text(account!['account_number'].toString()),
            subtitle: Text(
              '${account!['client']?['name']} · Available ${_money(account!['balance'])}',
            ),
          ),
        ),
      if (account != null)
        DropdownButtonFormField<Map>(
          key: ValueKey(loan?['id']),
          initialValue: loan,
          decoration: const InputDecoration(labelText: 'Payable loan'),
          items: [
            for (final item in loans)
              DropdownMenuItem(
                value: item,
                child: Text(
                  '${item['loan_number']} · ${_money(item['unpaid_amount'])}',
                ),
              ),
          ],
          onChanged: (value) => setState(() => loan = value),
        ),
      if (loan != null) ...[
        const SizedBox(height: 12),
        TextField(
          controller: amount,
          keyboardType: TextInputType.number,
          decoration: const InputDecoration(labelText: 'Amount (UGX)'),
        ),
        DropdownButtonFormField<String>(
          key: ValueKey(frequency),
          initialValue: frequency,
          decoration: const InputDecoration(labelText: 'Schedule frequency'),
          items: const [
            DropdownMenuItem(value: 'daily', child: Text('Daily')),
            DropdownMenuItem(value: 'weekly', child: Text('Weekly')),
            DropdownMenuItem(value: 'biweekly', child: Text('Every two weeks')),
            DropdownMenuItem(value: 'monthly', child: Text('Monthly')),
          ],
          onChanged: (value) => setState(() => frequency = value!),
        ),
        const SizedBox(height: 8),
        Wrap(
          spacing: 8,
          children: [
            FilledButton(onPressed: repay, child: const Text('Post repayment')),
            OutlinedButton(
              onPressed: schedule,
              child: const Text('Create schedule'),
            ),
          ],
        ),
      ],
      const _Heading('Scheduled deductions'),
      FutureBuilder<Map<String, dynamic>>(
        future: schedules,
        builder: (context, snapshot) {
          if (!snapshot.hasData) return const LinearProgressIndicator();
          final rows = (snapshot.data?['data'] as List? ?? []).cast<Map>();
          return Column(
            children: [
              for (final row in rows)
                Card(
                  child: ListTile(
                    title: Text(
                      '${row['account_number']} → ${row['loan_number']}',
                    ),
                    subtitle: Text(
                      '${_money(row['amount'])} · ${row['frequency']} · ${row['status']}\n${row['last_message'] ?? 'Not run yet'}',
                    ),
                    isThreeLine: true,
                    trailing: PopupMenuButton<String>(
                      onSelected: (value) {
                        if (value == 'runs') {
                          showScheduleRuns(row);
                        } else if (value == 'activate') {
                          activateSchedule(row);
                        } else {
                          changeScheduleStatus(row, value);
                        }
                      },
                      itemBuilder: (context) => [
                        const PopupMenuItem(
                          value: 'runs',
                          child: Text('View run history'),
                        ),
                        if ([
                              'super-admin',
                              'organization-admin',
                            ].contains(widget.user['role']) &&
                            ['draft', 'disabled'].contains(row['status']))
                          const PopupMenuItem(
                            value: 'activate',
                            child: Text('Activate'),
                          ),
                        if ([
                              'super-admin',
                              'organization-admin',
                            ].contains(widget.user['role']) &&
                            row['status'] == 'active')
                          const PopupMenuItem(
                            value: 'disabled',
                            child: Text('Disable'),
                          ),
                        if ([
                              'super-admin',
                              'organization-admin',
                            ].contains(widget.user['role']) &&
                            row['status'] != 'cancelled')
                          const PopupMenuItem(
                            value: 'cancelled',
                            child: Text('Cancel schedule'),
                          ),
                      ],
                    ),
                  ),
                ),
            ],
          );
        },
      ),
    ],
  );
}

class NonCashPage extends StatefulWidget {
  const NonCashPage({super.key, required this.client});
  final ApiClient client;
  @override
  State<NonCashPage> createState() => _NonCashPageState();
}

class _NonCashPageState extends State<NonCashPage> {
  final search = TextEditingController();
  final amount = TextEditingController();
  final description = TextEditingController();
  late Future<Map<String, dynamic>> options;
  late Future<Map<String, dynamic>> history;
  Future<Map<String, dynamic>>? accounts;
  Map? account;
  Map? ledger;
  Map? type;
  @override
  void initState() {
    super.initState();
    options = widget.client.get('mobile-operations/non-cash/options');
    history = widget.client.get('mobile-operations/non-cash/transactions');
  }

  Future<void> lookup() async {
    if (search.text.trim().length < 2) return;
    setState(
      () => accounts = widget.client.get(
        'mobile-operations/non-cash/accounts',
        query: {'search': search.text.trim()},
      ),
    );
  }

  Future<void> post() async {
    if (account == null ||
        ledger == null ||
        type == null ||
        double.tryParse(amount.text) == null ||
        description.text.trim().isEmpty) {
      return;
    }
    try {
      final response = await widget.client.requestFinancial(
        'POST',
        'mobile-operations/non-cash/transactions',
        operationId: _operationId(),
        body: {
          'account_id': account!['id'],
          'chart_account_id': ledger!['id'],
          'transaction_type_id': type!['id'],
          'amount': double.parse(amount.text),
          'description': description.text.trim(),
          'transaction_date': DateTime.now().toIso8601String(),
        },
      );
      if (mounted) {
        final receipt = response['data'] as Map? ?? {};
        _showReceipt(context, receipt);
        setState(() {
          history = widget.client.get(
            'mobile-operations/non-cash/transactions',
          );
          amount.clear();
          description.clear();
        });
      }
    } on ApiException catch (error) {
      if (mounted) _message(context, error.message, error: true);
    }
  }

  Future<void> reviewAndPost() async {
    if (account == null ||
        ledger == null ||
        type == null ||
        double.tryParse(amount.text) == null ||
        description.text.trim().isEmpty) {
      _message(
        context,
        'Choose an account, transaction type, ledger, amount, and description.',
        error: true,
      );
      return;
    }
    final confirmed = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        title: const Text('Confirm non-cash transaction'),
        content: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text('Account: ${account!['account_number']}'),
            Text('Client: ${account!['client']?['name'] ?? ''}'),
            Text('Type: ${type!['name']}'),
            Text('Offset: ${ledger!['code']} · ${ledger!['name']}'),
            Text('Amount: ${_money(double.tryParse(amount.text))}'),
            Text('Description: ${description.text.trim()}'),
          ],
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(context, false),
            child: const Text('Back'),
          ),
          FilledButton(
            onPressed: () => Navigator.pop(context, true),
            child: const Text('Post'),
          ),
        ],
      ),
    );
    if (confirmed == true) await post();
  }

  Future<void> requestReversal(Map transaction) async {
    final reason = TextEditingController();
    final confirmed = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        title: Text('Request reversal ${transaction['reference'] ?? ''}'),
        content: TextField(
          controller: reason,
          minLines: 2,
          maxLines: 4,
          decoration: const InputDecoration(labelText: 'Reason for reversal'),
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(context, false),
            child: const Text('Cancel'),
          ),
          FilledButton(
            onPressed: () => Navigator.pop(context, true),
            child: const Text('Submit request'),
          ),
        ],
      ),
    );
    if (confirmed != true) {
      reason.dispose();
      return;
    }
    try {
      await widget.client.request(
        'POST',
        'mobile-operations/non-cash/transactions/${transaction['id']}/reversal-request',
        body: {'reason': reason.text.trim()},
      );
      if (mounted) {
        _message(context, 'Reversal request submitted for approval.');
      }
    } on ApiException catch (error) {
      if (mounted) {
        _message(context, error.message, error: true);
      }
    } finally {
      reason.dispose();
    }
  }

  @override
  void dispose() {
    search.dispose();
    amount.dispose();
    description.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) => ListView(
    padding: const EdgeInsets.all(16),
    children: [
      Text(
        'Non-cash transaction',
        style: Theme.of(context).textTheme.headlineSmall,
      ),
      const Text(
        'Post a bank, mobile-money, merchant-code, or clearing-account transaction and receive a reference.',
      ),
      const SizedBox(height: 12),
      TextField(
        controller: search,
        onSubmitted: (_) => lookup(),
        decoration: InputDecoration(
          labelText: 'Client or account number',
          suffixIcon: IconButton(
            onPressed: lookup,
            icon: const Icon(Icons.search),
          ),
        ),
      ),
      if (accounts != null)
        FutureBuilder<Map<String, dynamic>>(
          future: accounts,
          builder: (context, snapshot) {
            if (!snapshot.hasData) return const LinearProgressIndicator();
            return Column(
              children: [
                for (final row
                    in (snapshot.data?['data'] as List? ?? []).cast<Map>())
                  ListTile(
                    title: Text(
                      '${row['account_number']} · ${row['client']?['name'] ?? ''}',
                    ),
                    subtitle: Text('Balance ${_money(row['balance'])}'),
                    onTap: () => setState(() => account = row),
                  ),
              ],
            );
          },
        ),
      if (account != null)
        ListTile(
          title: Text('Source: ${account!['account_number']}'),
          trailing: IconButton(
            onPressed: () => setState(() => account = null),
            icon: const Icon(Icons.close),
          ),
        ),
      FutureBuilder<Map<String, dynamic>>(
        future: options,
        builder: (context, snapshot) {
          if (!snapshot.hasData) return const LinearProgressIndicator();
          final data = snapshot.data!['data'] as Map? ?? {};
          final types = (data['transaction_types'] as List? ?? []).cast<Map>();
          final ledgers = (data['ledgers'] as List? ?? []).cast<Map>();
          return Column(
            children: [
              DropdownButtonFormField<Map>(
                key: ValueKey(type?['id']),
                initialValue: type,
                decoration: const InputDecoration(
                  labelText: 'Transaction type',
                ),
                items: [
                  for (final row in types)
                    DropdownMenuItem(
                      value: row,
                      child: Text(row['name'].toString()),
                    ),
                ],
                onChanged: (value) => setState(() => type = value),
              ),
              DropdownButtonFormField<Map>(
                key: ValueKey(ledger?['id']),
                initialValue: ledger,
                decoration: const InputDecoration(labelText: 'Offset ledger'),
                items: [
                  for (final row in ledgers)
                    DropdownMenuItem(
                      value: row,
                      child: Text('${row['code']} · ${row['name']}'),
                    ),
                ],
                onChanged: (value) => setState(() => ledger = value),
              ),
            ],
          );
        },
      ),
      TextField(
        controller: amount,
        keyboardType: TextInputType.number,
        decoration: const InputDecoration(labelText: 'Amount (UGX)'),
      ),
      TextField(
        controller: description,
        decoration: const InputDecoration(labelText: 'Description'),
      ),
      const SizedBox(height: 8),
      FilledButton(
        onPressed: reviewAndPost,
        child: const Text('Review and post non-cash transaction'),
      ),
      const _Heading('Recent non-cash receipts'),
      FutureBuilder<Map<String, dynamic>>(
        future: history,
        builder: (context, snapshot) {
          if (!snapshot.hasData) return const LinearProgressIndicator();
          return Column(
            children: [
              for (final row
                  in (snapshot.data?['data'] as List? ?? []).cast<Map>())
                Card(
                  child: ListTile(
                    title: Text(row['reference']?.toString() ?? 'Receipt'),
                    subtitle: Text(
                      '${row['account']?['client'] ?? ''} · ${row['type'] ?? ''}\n${_money(row['amount'])} · ${row['posted_at'] ?? ''}',
                    ),
                    isThreeLine: true,
                    trailing: PopupMenuButton<String>(
                      onSelected: (value) {
                        if (value == 'reversal') requestReversal(row);
                      },
                      itemBuilder: (context) => const [
                        PopupMenuItem(
                          value: 'reversal',
                          child: Text('Request reversal'),
                        ),
                      ],
                    ),
                  ),
                ),
            ],
          );
        },
      ),
    ],
  );
}

class EndOfDayPage extends StatefulWidget {
  const EndOfDayPage({super.key, required this.client});
  final ApiClient client;

  @override
  State<EndOfDayPage> createState() => _EndOfDayPageState();
}

class _EndOfDayPageState extends State<EndOfDayPage> {
  late Future<Map<String, dynamic>> status;

  @override
  void initState() {
    super.initState();
    status = load();
  }

  Future<Map<String, dynamic>> load() =>
      widget.client.get('mobile-operations/end-of-day');

  Future<void> closeDay() async {
    final cash = TextEditingController();
    final notes = TextEditingController();
    final confirmed = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        title: const Text('Close business day'),
        content: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            const Text(
              'This completes reconciliation, daily processing, and close-of-day controls for your branch.',
            ),
            TextField(
              controller: cash,
              keyboardType: TextInputType.number,
              decoration: const InputDecoration(
                labelText: 'Counted cash (UGX)',
              ),
            ),
            TextField(
              controller: notes,
              minLines: 2,
              maxLines: 4,
              decoration: const InputDecoration(labelText: 'Notes (optional)'),
            ),
          ],
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(context, false),
            child: const Text('Cancel'),
          ),
          FilledButton(
            onPressed: () => Navigator.pop(context, true),
            child: const Text('Close day'),
          ),
        ],
      ),
    );
    if (confirmed != true) {
      cash.dispose();
      notes.dispose();
      return;
    }
    try {
      await widget.client.requestFinancial(
        'POST',
        'mobile-operations/end-of-day/close',
        operationId: _operationId(),
        body: {
          'business_date': DateTime.now().toIso8601String(),
          'counted_cash': double.tryParse(cash.text),
          'notes': notes.text.trim().isEmpty ? null : notes.text.trim(),
        },
      );
      if (mounted) {
        _message(context, 'Business day closed and processed.');
        setState(() => status = load());
      }
    } on ApiException catch (error) {
      if (mounted) _message(context, error.message, error: true);
    } finally {
      cash.dispose();
      notes.dispose();
    }
  }

  @override
  Widget build(BuildContext context) => Scaffold(
    appBar: AppBar(title: const Text('End of day')),
    body: FutureBuilder<Map<String, dynamic>>(
      future: status,
      builder: (context, snapshot) {
        if (snapshot.connectionState == ConnectionState.waiting) {
          return const Center(child: CircularProgressIndicator());
        }
        if (snapshot.hasError) {
          return _Retry(
            error: snapshot.error!,
            retry: () => setState(() => status = load()),
          );
        }
        final data = snapshot.data!['data'] as Map? ?? {};
        final summary = data['summary'] as Map? ?? {};
        return ListView(
          padding: const EdgeInsets.all(16),
          children: [
            Text(
              data['branch']?['name']?.toString() ?? 'Branch',
              style: Theme.of(context).textTheme.headlineSmall,
            ),
            Text(
              '${data['business_date'] ?? ''} · ${data['financial_day_status'] ?? ''}',
            ),
            _Metrics(
              values: {
                'Expected cash': summary['expected_cash'],
                'Counted cash': summary['counted_cash'],
                'Variance': summary['cash_variance'],
                'Loan repayments': summary['loan_repayments'],
              },
            ),
            Text(
              'Open teller sessions: ${summary['open_teller_sessions'] ?? 0}',
            ),
            const SizedBox(height: 16),
            FilledButton.icon(
              onPressed: data['financial_day_status'] == 'locked'
                  ? null
                  : closeDay,
              icon: const Icon(Icons.lock_clock_outlined),
              label: const Text('Reconcile and close business day'),
            ),
          ],
        );
      },
    ),
  );
}

class OperationsAlertsPage extends StatefulWidget {
  const OperationsAlertsPage({
    super.key,
    required this.client,
    required this.user,
  });
  final ApiClient client;
  final Map<String, dynamic> user;
  @override
  State<OperationsAlertsPage> createState() => _OperationsAlertsPageState();
}

class _OperationsAlertsPageState extends State<OperationsAlertsPage> {
  late Future<List<dynamic>> data;
  @override
  void initState() {
    super.initState();
    data = load();
  }

  Future<List<dynamic>> load() => Future.wait([
    widget.client.get('notifications'),
    widget.client.get('sync/manifest'),
    widget.client.pendingFinancialOperations(),
    widget.client.offlineClientsSnapshot(),
    widget.client.offlineClientDrafts(),
  ]);

  Future<void> syncClientDirectory() async {
    try {
      final result = await widget.client.syncClientsForOffline();
      if (mounted) {
        _message(
          context,
          '${result['count']} clients are ready for offline search.',
        );
        setState(() => data = load());
      }
    } on ApiException catch (error) {
      if (mounted) _message(context, error.message, error: true);
    }
  }

  Future<void> syncClientDrafts() async {
    try {
      final result = await widget.client.syncOfflineClientDrafts();
      final summary = result['summary'] as Map? ?? {};
      if (mounted) {
        _message(
          context,
          '${summary['accepted'] ?? 0} client changes accepted; ${summary['conflicts'] ?? 0} need review.',
        );
        setState(() => data = load());
      }
    } on ApiException catch (error) {
      if (mounted) _message(context, error.message, error: true);
    }
  }

  @override
  Widget build(BuildContext context) => ListView(
    padding: const EdgeInsets.all(16),
    children: [
      Text(
        'Alerts & offline recovery',
        style: Theme.of(context).textTheme.headlineSmall,
      ),
      FutureBuilder<List<dynamic>>(
        future: data,
        builder: (context, snapshot) {
          if (!snapshot.hasData) {
            return const Center(child: CircularProgressIndicator());
          }
          if (snapshot.hasError) {
            return _Retry(
              error: snapshot.error!,
              retry: () => setState(() => data = load()),
            );
          }
          final notifications = (snapshot.data![0]['data'] as List? ?? [])
              .cast<Map>();
          final manifest = snapshot.data![1]['data'] as Map? ?? {};
          final pending = (snapshot.data![2] as List)
              .cast<Map<String, dynamic>>();
          final offline = snapshot.data![3] as Map<String, dynamic>;
          final drafts = (snapshot.data![4] as List)
              .cast<Map<String, dynamic>>();
          return Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(
                'Server time: ${manifest['server_time'] ?? 'available when connected'}',
              ),
              if ([
                'super-admin',
                'organization-admin',
                'accountant',
              ].contains(widget.user['role']))
                Align(
                  alignment: Alignment.centerLeft,
                  child: TextButton.icon(
                    onPressed: () => Navigator.of(context).push(
                      MaterialPageRoute<void>(
                        builder: (_) => EndOfDayPage(client: widget.client),
                      ),
                    ),
                    icon: const Icon(Icons.event_available_outlined),
                    label: const Text('End-of-day controls'),
                  ),
                ),
              const _Heading('Offline client directory'),
              Text(
                '${(offline['clients'] as List).length} clients stored on this device${offline['synced_at'] == null ? '' : ' · updated ${offline['synced_at']}'}',
              ),
              const SizedBox(height: 8),
              OutlinedButton.icon(
                onPressed: syncClientDirectory,
                icon: const Icon(Icons.sync),
                label: const Text('Sync client directory for offline search'),
              ),
              if (drafts.isNotEmpty) ...[
                Text(
                  '${drafts.length} offline client registration/update changes pending.',
                ),
                OutlinedButton.icon(
                  onPressed: syncClientDrafts,
                  icon: const Icon(Icons.cloud_upload_outlined),
                  label: const Text('Sync client changes'),
                ),
                for (final draft in drafts.where(
                  (draft) => draft['status'] == 'conflict',
                ))
                  _OfflineNotice(
                    'Conflict for ${draft['payload']?['first_name'] ?? 'client'}: server data changed. Keep this staged change for review before syncing again.',
                  ),
              ],
              const _Heading('Pending financial operations'),
              if (pending.isEmpty)
                const _Empty(message: 'No pending financial operations.'),
              for (final item in pending)
                Card(
                  child: ListTile(
                    title: Text(item['path'].toString()),
                    subtitle: Text(item['created_at'].toString()),
                    trailing: FilledButton(
                      onPressed: () async {
                        try {
                          await widget.client.retryPendingFinancial(
                            item['id'].toString(),
                          );
                          if (mounted) {
                            setState(() => data = load());
                          }
                        } on ApiException catch (error) {
                          if (!context.mounted) {
                            return;
                          }
                          _message(context, error.message, error: true);
                        }
                      },
                      child: const Text('Retry'),
                    ),
                  ),
                ),
              const _Heading('Notifications'),
              if (notifications.isEmpty)
                const _Empty(message: 'No notifications.'),
              for (final item in notifications)
                Card(
                  child: ListTile(
                    title: Text(
                      (item['data'] as Map?)?['title']?.toString() ??
                          item['type'].toString(),
                    ),
                    subtitle: Text(
                      (item['data'] as Map?)?['message']?.toString() ?? '',
                    ),
                    trailing: item['read_at'] == null
                        ? const Icon(Icons.circle, size: 12)
                        : null,
                    onTap: item['read_at'] != null
                        ? null
                        : () async {
                            await widget.client.request(
                              'POST',
                              'notifications/${item['id']}/read',
                            );
                            if (mounted) setState(() => data = load());
                          },
                  ),
                ),
            ],
          );
        },
      ),
    ],
  );
}

class _Heading extends StatelessWidget {
  const _Heading(this.text);
  final String text;
  @override
  Widget build(BuildContext context) => Padding(
    padding: const EdgeInsets.only(top: 24, bottom: 8),
    child: Text(text, style: Theme.of(context).textTheme.titleLarge),
  );
}

class _OfflineNotice extends StatelessWidget {
  const _OfflineNotice(this.message);
  final String message;

  @override
  Widget build(BuildContext context) => Container(
    margin: const EdgeInsets.only(bottom: 8),
    padding: const EdgeInsets.all(12),
    decoration: BoxDecoration(
      color: Theme.of(context).colorScheme.secondaryContainer,
      borderRadius: BorderRadius.circular(12),
    ),
    child: Row(
      children: [
        const Icon(Icons.offline_bolt_outlined),
        const SizedBox(width: 8),
        Expanded(child: Text(message)),
      ],
    ),
  );
}

class _Metrics extends StatelessWidget {
  const _Metrics({required this.values});
  final Map<String, dynamic> values;
  @override
  Widget build(BuildContext context) => Padding(
    padding: const EdgeInsets.symmetric(vertical: 12),
    child: Wrap(
      spacing: 8,
      runSpacing: 8,
      children: [
        for (final value in values.entries)
          Chip(label: Text('${value.key}: ${_money(value.value)}')),
      ],
    ),
  );
}

class _Empty extends StatelessWidget {
  const _Empty({required this.message});
  final String message;
  @override
  Widget build(BuildContext context) => Padding(
    padding: const EdgeInsets.all(20),
    child: Text(message, textAlign: TextAlign.center),
  );
}

class _Retry extends StatelessWidget {
  const _Retry({required this.error, required this.retry});
  final Object error;
  final VoidCallback retry;
  @override
  Widget build(BuildContext context) => Center(
    child: Column(
      mainAxisSize: MainAxisSize.min,
      children: [
        Text('$error'),
        TextButton(onPressed: retry, child: const Text('Retry')),
      ],
    ),
  );
}

String _money(dynamic value) {
  final amount = value is num
      ? value.toDouble()
      : double.tryParse(value?.toString() ?? '') ?? 0;

  return 'UGX ${amount.toStringAsFixed(2)}';
}

String _loanBalance(Map loan) => _money(
  ((loan['principal_balance'] as num?) ?? 0) +
      ((loan['interest_balance'] as num?) ?? 0) +
      ((loan['fees_balance'] as num?) ?? 0) +
      ((loan['penalty_balance'] as num?) ?? 0),
);
String _operationId() =>
    'mobile-${DateTime.now().microsecondsSinceEpoch}-${Random.secure().nextInt(1 << 32)}';
void _message(BuildContext context, String text, {bool error = false}) =>
    ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(
        content: Text(text),
        backgroundColor: error ? Theme.of(context).colorScheme.error : null,
      ),
    );

void _showReceipt(BuildContext context, Map receipt) {
  showModalBottomSheet<void>(
    context: context,
    showDragHandle: true,
    builder: (context) => SafeArea(
      child: Padding(
        padding: const EdgeInsets.all(20),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(
              'Transaction receipt',
              style: Theme.of(context).textTheme.titleLarge,
            ),
            const SizedBox(height: 12),
            _receiptLine('Reference', receipt['reference']),
            _receiptLine('Amount', _money(receipt['amount'])),
            if (receipt['charges'] != null)
              _receiptLine('Charges', _money(receipt['charges'])),
            _receiptLine('Type', receipt['type'] ?? receipt['payment_method']),
            _receiptLine(
              'Account',
              receipt['account'] is Map ? receipt['account']['number'] : null,
            ),
            _receiptLine(
              'Client',
              receipt['account'] is Map ? receipt['account']['client'] : null,
            ),
            _receiptLine(
              'Posted at',
              receipt['posted_at'] ?? receipt['paid_at'],
            ),
            const SizedBox(height: 12),
            Row(
              mainAxisAlignment: MainAxisAlignment.end,
              children: [
                TextButton.icon(
                  onPressed: () => shareReceiptPdf(receipt),
                  icon: const Icon(Icons.picture_as_pdf_outlined),
                  label: const Text('Share PDF'),
                ),
                const SizedBox(width: 8),
                FilledButton(
                  onPressed: () => Navigator.pop(context),
                  child: const Text('Done'),
                ),
              ],
            ),
          ],
        ),
      ),
    ),
  );
}

Widget _receiptLine(String label, dynamic value) => Padding(
  padding: const EdgeInsets.symmetric(vertical: 3),
  child: Row(
    crossAxisAlignment: CrossAxisAlignment.start,
    children: [
      SizedBox(width: 86, child: Text(label)),
      Expanded(child: SelectableText(value?.toString() ?? '—')),
    ],
  ),
);
