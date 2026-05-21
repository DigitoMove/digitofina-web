<?php

namespace App\Services;

use App\Models\Account;
use App\Models\AccountType;
use App\Models\Branch;
use App\Models\ChartAccount;
use App\Models\Client;
use App\Models\ImportBatch;
use App\Models\ImportRow;
use App\Models\Loan;
use App\Models\LoanProduct;
use App\Models\LoanSchedule;
use App\Models\Organization;
use App\Models\SavingsAccount;
use App\Models\SavingsProduct;
use App\Models\SavingsTransaction;
use App\Models\User;
use App\Support\ApiSecurity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

class DataImportService
{
    public function __construct(
        private readonly DataImportReader $reader,
        private readonly AuditLogger $audit,
        private readonly AccountNumberGenerator $numbers,
        private readonly LoanScheduleService $loanSchedules,
    ) {}

    /**
     * @return array<string, array{label: string, description: string, columns: array<int, string>}>
     */
    public function types(): array
    {
        return [
            'clients' => [
                'label' => 'Clients',
                'description' => 'Register clients, generate their basic savings account, and optionally create a draft loan with a preview schedule.',
                'columns' => ['client_number', 'first_name', 'middle_name', 'last_name', 'phone', 'branch_code', 'client_type', 'email', 'national_id', 'status', 'kyc_status', 'address', 'business_name', 'registered_at', 'loan_amount', 'loan_code', 'installments'],
            ],
            'savings_accounts' => [
                'label' => 'Savings accounts',
                'description' => 'Open existing member savings accounts against configured savings products.',
                'columns' => ['account_number', 'client_number', 'product_code', 'branch_code', 'opening_date', 'current_balance', 'available_balance', 'status'],
            ],
            'opening_balances' => [
                'label' => 'Opening balances',
                'description' => 'Post migrated savings opening balances with before/after balances and rollback metadata.',
                'columns' => ['account_number', 'balance', 'transaction_date', 'narration'],
            ],
            'existing_loans' => [
                'label' => 'Existing loans',
                'description' => 'Migrate active, arrears, or fully paid loans from a legacy register.',
                'columns' => ['loan_number', 'client_number', 'product_code', 'branch_code', 'requested_amount', 'approved_amount', 'principal_balance', 'interest_balance', 'fees_balance', 'penalty_balance', 'interest_rate', 'term_periods', 'repayment_frequency', 'status', 'application_date', 'approved_at', 'disbursed_at', 'purpose'],
            ],
            'repayment_schedules' => [
                'label' => 'Repayment schedules',
                'description' => 'Attach repayment schedules to imported or existing loans.',
                'columns' => ['loan_number', 'installment_number', 'due_date', 'principal_due', 'interest_due', 'fees_due', 'penalty_due', 'total_due', 'principal_paid', 'interest_paid', 'fees_paid', 'penalty_paid', 'total_paid', 'outstanding_amount', 'status'],
            ],
            'chart_accounts' => [
                'label' => 'Chart of accounts',
                'description' => 'Import GL accounts with optional parent account codes.',
                'columns' => ['code', 'name', 'type', 'normal_balance', 'parent_code', 'branch_code', 'is_cash_account', 'is_active', 'is_system'],
            ],
        ];
    }

    public function createBatch(User $user, UploadedFile $file, string $type, ?int $branchId = null, ?int $organizationId = null): ImportBatch
    {
        $this->guardType($type);

        $organization = $this->resolveOrganization($user, $organizationId);
        $branch = $this->resolveBranch($user, $branchId, $organization);
        $organizationId = $branch?->organization_id ?? $organization?->id ?? $user->organization_id;
        $path = $file->store('imports');
        $rows = $this->reader->read($file);

        if ($rows === []) {
            throw new InvalidArgumentException('The import file does not contain any data rows.');
        }

        return DB::transaction(function () use ($user, $file, $type, $organizationId, $branch, $path, $rows): ImportBatch {
            $batch = ImportBatch::create([
                'organization_id' => $organizationId,
                'branch_id' => $branch?->id,
                'created_by' => $user->id,
                'type' => $type,
                'original_filename' => $file->getClientOriginalName(),
                'stored_path' => $path,
                'status' => 'preview',
            ]);

            foreach ($rows as $index => $row) {
                $result = $this->validate($type, $row, $user, $branch, $organizationId);

                ImportRow::create([
                    'import_batch_id' => $batch->id,
                    'row_number' => $index + 2,
                    'raw_data' => $row,
                    'normalized_data' => $result['data'],
                    'validation_errors' => $result['errors'] ?: null,
                    'status' => $result['errors'] === [] ? 'valid' : 'invalid',
                ]);
            }

            $this->refreshCounts($batch);
            $this->audit->record('imports.preview_created', $batch, ['type' => $type, 'rows' => count($rows)], [], $user);

            return $batch->fresh(['rows']);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateRow(ImportRow $row, array $data, User $user): ImportRow
    {
        $batch = $row->batch;

        if (! $batch || ! $batch->isEditable()) {
            throw new InvalidArgumentException('Only preview or failed imports can be corrected.');
        }

        $result = $this->validate($batch->type, $data, $user, $batch->branch, $batch->organization_id);

        $row->update([
            'normalized_data' => $result['data'],
            'validation_errors' => $result['errors'] ?: null,
            'status' => $result['errors'] === [] ? 'valid' : 'invalid',
            'message' => null,
        ]);

        $this->refreshCounts($batch);
        $this->audit->record('imports.row_corrected', $row, $row->normalized_data ?? [], [], $user);

        return $row->fresh();
    }

    public function commit(ImportBatch $batch, User $user): ImportBatch
    {
        $batch->loadMissing(['branch', 'rows']);

        if (! $batch->isEditable()) {
            throw new InvalidArgumentException('This import batch can no longer be committed.');
        }

        if ($batch->rows()->where('status', 'invalid')->exists()) {
            throw new InvalidArgumentException('Correct validation errors before committing this import.');
        }

        DB::transaction(function () use ($batch, $user): void {
            $batch->rows()->whereIn('status', ['valid', 'failed'])->orderBy('row_number')->each(function (ImportRow $row) use ($batch, $user): void {
                try {
                    $model = $this->importRow($batch, $row, $user);

                    $row->update([
                        'record_type' => $model?->getMorphClass(),
                        'record_id' => $model?->getKey(),
                        'status' => 'imported',
                        'message' => 'Imported successfully.',
                    ]);
                } catch (Throwable $exception) {
                    $row->update([
                        'status' => 'failed',
                        'validation_errors' => ['row' => [$exception->getMessage()]],
                        'message' => $exception->getMessage(),
                    ]);
                }
            });

            $this->refreshCounts($batch);
            $batch->update([
                'status' => $batch->rows()->where('status', 'failed')->exists() ? 'failed' : 'imported',
                'committed_by' => $user->id,
                'committed_at' => now(),
            ]);
            $this->audit->record('imports.committed', $batch, $batch->only(['type', 'imported_rows', 'failed_rows']), [], $user);
        });

        return $batch->fresh(['rows']);
    }

    public function rollback(ImportBatch $batch, User $user): ImportBatch
    {
        if ($batch->status !== 'imported') {
            throw new InvalidArgumentException('Only fully imported batches can be rolled back safely.');
        }

        DB::transaction(function () use ($batch, $user): void {
            $rolledBack = 0;

            $batch->rows()->where('status', 'imported')->orderByDesc('row_number')->each(function (ImportRow $row) use (&$rolledBack): void {
                if ($this->rollbackRow($row)) {
                    $rolledBack++;
                    $row->update(['status' => 'rolled_back', 'message' => 'Rolled back safely.']);
                }
            });

            $batch->update([
                'status' => 'rolled_back',
                'rolled_back_by' => $user->id,
                'rolled_back_at' => now(),
                'rolled_back_rows' => $rolledBack,
            ]);
            $this->audit->record('imports.rolled_back', $batch, ['rolled_back_rows' => $rolledBack], [], $user);
        });

        return $batch->fresh(['rows']);
    }

    public function templateCsv(string $type): string
    {
        $this->guardType($type);

        return implode(',', $this->types()[$type]['columns']).PHP_EOL;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{data: array<string, mixed>, errors: array<string, array<int, string>>}
     */
    private function validate(string $type, array $row, User $user, ?Branch $branch, ?int $organizationId = null): array
    {
        $data = $this->normalize($row);
        $errors = [];

        $validator = match ($type) {
            'clients' => $this->validateClient($data, $user, $branch, $organizationId),
            'savings_accounts' => $this->validateSavingsAccount($data, $user, $branch, $organizationId),
            'opening_balances' => $this->validateOpeningBalance($data, $user),
            'existing_loans' => $this->validateExistingLoan($data, $user, $branch, $organizationId),
            'repayment_schedules' => $this->validateRepaymentSchedule($data, $user),
            'chart_accounts' => $this->validateChartAccount($data, $user, $branch, $organizationId),
            default => ['Unknown import type.'],
        };

        foreach ($validator as $field => $messages) {
            $errors[$field] = is_array($messages) ? $messages : [$messages];
        }

        return ['data' => $data, 'errors' => $errors];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function normalize(array $row): array
    {
        return collect($row)
            ->mapWithKeys(fn ($value, $key): array => [(string) Str::of((string) $key)->lower()->replaceMatches('/[^a-z0-9]+/', '_')->trim('_') => is_string($value) ? trim($value) : $value])
            ->filter(fn ($value, $key): bool => $key !== '')
            ->all();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, array<int, string>>
     */
    private function validateClient(array &$data, User $user, ?Branch $branch, ?int $organizationId): array
    {
        $errors = $this->required($data, ['client_number', 'first_name', 'last_name', 'phone', 'loan_amount']);
        $this->resolveBranchCode($data, $user, $branch, $organizationId, $errors);
        $data['client_type'] = $data['client_type'] ?? 'individual';
        $data['status'] = $data['status'] ?? 'active';
        $data['kyc_status'] = $data['kyc_status'] ?? 'pending';
        $data['loan_amount'] = $this->money($data['loan_amount'] ?? 0);
        $data['loan_code'] = strtoupper((string) ($data['loan_code'] ?? 'WEEKLY20'));
        $data['installments'] = (int) ($data['installments'] ?? $data['installments_column'] ?? 4);

        if (! in_array($data['client_type'], ['individual', 'group', 'business', 'institution'], true)) {
            $errors['client_type'][] = 'Client type must be individual, group, business, or institution.';
        }

        if (! in_array($data['status'], ['pending', 'active', 'dormant', 'suspended', 'blacklisted', 'closed'], true)) {
            $errors['status'][] = 'Status is not supported.';
        }

        if (Client::where('client_number', $data['client_number'] ?? null)->exists()) {
            $errors['client_number'][] = 'Client number already exists.';
        }

        if (filled($data['national_id'] ?? null) && Client::where('national_id', $data['national_id'])->exists()) {
            $errors['national_id'][] = 'National ID already exists.';
        }

        if ($data['installments'] < 1) {
            $errors['installments'][] = 'Installments must be at least 1.';
        }

        if ($data['loan_amount'] <= 0) {
            $errors['loan_amount'][] = 'Loan amount must be greater than zero for imported clients.';
        } else {
            $product = LoanProduct::query()
                ->where('code', $data['loan_code'])
                ->when($organizationId, fn ($query) => $query->where(function ($query) use ($organizationId): void {
                    $query->where('organization_id', $organizationId)->orWhereNull('organization_id');
                }))
                ->first();

            if (! $product) {
                $errors['loan_code'][] = "Loan product {$data['loan_code']} was not found.";
            } else {
                if ($data['loan_amount'] < (float) $product->min_amount || $data['loan_amount'] > (float) $product->max_amount) {
                    $errors['loan_amount'][] = 'Loan amount is outside the selected product amount limits.';
                }

                if ($data['installments'] < (int) $product->min_term || $data['installments'] > (int) $product->max_term) {
                    $errors['installments'][] = 'Installments are outside the selected product term limits.';
                }
            }
        }

        $this->dateField($data, 'registered_at', $errors);

        return $errors;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, array<int, string>>
     */
    private function validateSavingsAccount(array &$data, User $user, ?Branch $branch, ?int $organizationId): array
    {
        $errors = $this->required($data, ['account_number', 'client_number', 'product_code']);
        $this->resolveBranchCode($data, $user, $branch, $organizationId, $errors);
        $data['status'] = $data['status'] ?? 'active';
        $data['current_balance'] = $this->money($data['current_balance'] ?? 0);
        $data['available_balance'] = $this->money($data['available_balance'] ?? $data['current_balance']);
        $this->dateField($data, 'opening_date', $errors, today()->toDateString());

        $client = Client::where('client_number', $data['client_number'] ?? null)->first();
        $product = SavingsProduct::where('code', $data['product_code'] ?? null)->first();

        if (! $client) {
            $errors['client_number'][] = 'Client number was not found.';
        }

        if (! $product) {
            $errors['product_code'][] = 'Savings product code was not found.';
        }

        if ($client && ($data['branch_id'] ?? null) && (int) $client->branch_id !== (int) $data['branch_id']) {
            $errors['client_number'][] = 'Client does not belong to the selected branch.';
        }

        if (SavingsAccount::where('account_number', $data['account_number'] ?? null)->exists()) {
            $errors['account_number'][] = 'Savings account number already exists.';
        }

        if (! in_array($data['status'], ['pending', 'active', 'frozen', 'dormant', 'closed'], true)) {
            $errors['status'][] = 'Status is not supported.';
        }

        return $errors;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, array<int, string>>
     */
    private function validateOpeningBalance(array &$data, User $user): array
    {
        $errors = $this->required($data, ['account_number', 'balance']);
        $account = SavingsAccount::where('account_number', $data['account_number'] ?? null)->first();
        $data['balance'] = $this->money($data['balance'] ?? 0);
        $this->dateField($data, 'transaction_date', $errors, today()->toDateString());
        $data['narration'] = $data['narration'] ?? 'Opening balance import';

        if (! $account) {
            $errors['account_number'][] = 'Savings account number was not found.';
        } elseif (! $user->canAccessBranch($account->branch_id, $account->organization_id)) {
            $errors['account_number'][] = 'Savings account is outside your branch.';
        }

        if ($data['balance'] < 0) {
            $errors['balance'][] = 'Opening balance must be zero or greater.';
        }

        return $errors;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, array<int, string>>
     */
    private function validateExistingLoan(array &$data, User $user, ?Branch $branch, ?int $organizationId): array
    {
        $errors = $this->required($data, ['loan_number', 'client_number', 'product_code', 'requested_amount', 'principal_balance', 'interest_rate', 'term_periods', 'repayment_frequency']);
        $this->resolveBranchCode($data, $user, $branch, $organizationId, $errors);
        $client = Client::where('client_number', $data['client_number'] ?? null)->first();
        $product = LoanProduct::where('code', $data['product_code'] ?? null)->first();

        foreach (['requested_amount', 'approved_amount', 'principal_balance', 'interest_balance', 'fees_balance', 'penalty_balance', 'interest_rate'] as $field) {
            $data[$field] = $this->money($data[$field] ?? 0);
        }

        $data['approved_amount'] = $data['approved_amount'] ?: $data['requested_amount'];
        $data['status'] = $data['status'] ?? 'active';
        $data['term_periods'] = (int) ($data['term_periods'] ?? 0);
        $data['repayment_frequency'] = $data['repayment_frequency'] ?? 'monthly';
        $this->dateField($data, 'application_date', $errors, today()->toDateString());
        $this->dateField($data, 'approved_at', $errors);
        $this->dateField($data, 'disbursed_at', $errors);

        if (! $client) {
            $errors['client_number'][] = 'Client number was not found.';
        }

        if (! $product) {
            $errors['product_code'][] = 'Loan product code was not found.';
        }

        if ($client && ($data['branch_id'] ?? null) && (int) $client->branch_id !== (int) $data['branch_id']) {
            $errors['client_number'][] = 'Client does not belong to the selected branch.';
        }

        if (Loan::where('loan_number', $data['loan_number'] ?? null)->exists()) {
            $errors['loan_number'][] = 'Loan number already exists.';
        }

        if ($data['term_periods'] < 1) {
            $errors['term_periods'][] = 'Term periods must be at least 1.';
        }

        return $errors;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, array<int, string>>
     */
    private function validateRepaymentSchedule(array &$data, User $user): array
    {
        $errors = $this->required($data, ['loan_number', 'installment_number', 'due_date', 'total_due']);
        $loan = Loan::where('loan_number', $data['loan_number'] ?? null)->first();
        $data['installment_number'] = (int) ($data['installment_number'] ?? 0);
        $data['status'] = $data['status'] ?? 'pending';

        foreach (['principal_due', 'interest_due', 'fees_due', 'penalty_due', 'total_due', 'principal_paid', 'interest_paid', 'fees_paid', 'penalty_paid', 'total_paid', 'outstanding_amount'] as $field) {
            $data[$field] = $this->money($data[$field] ?? 0);
        }

        $data['outstanding_amount'] = $data['outstanding_amount'] ?: max(0, $data['total_due'] - $data['total_paid']);
        $this->dateField($data, 'due_date', $errors);

        if (! $loan) {
            $errors['loan_number'][] = 'Loan number was not found.';
        } elseif (! $user->canAccessBranch($loan->branch_id, $loan->organization_id)) {
            $errors['loan_number'][] = 'Loan is outside your branch.';
        } elseif (LoanSchedule::where('loan_id', $loan->id)->where('installment_number', $data['installment_number'])->exists()) {
            $errors['installment_number'][] = 'Installment already exists for this loan.';
        }

        if ($data['installment_number'] < 1) {
            $errors['installment_number'][] = 'Installment number must be at least 1.';
        }

        return $errors;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, array<int, string>>
     */
    private function validateChartAccount(array &$data, User $user, ?Branch $branch, ?int $organizationId): array
    {
        $errors = $this->required($data, ['code', 'name', 'type', 'normal_balance']);
        $this->resolveOptionalBranchCode($data, $user, $branch, $organizationId, $errors);
        $data['type'] = $data['type'] ?? null;
        $data['normal_balance'] = $data['normal_balance'] ?? null;
        $data['is_cash_account'] = $this->bool($data['is_cash_account'] ?? false);
        $data['is_active'] = $this->bool($data['is_active'] ?? true);
        $data['is_system'] = $this->bool($data['is_system'] ?? false);

        if (! in_array($data['type'], ['asset', 'liability', 'equity', 'income', 'expense'], true)) {
            $errors['type'][] = 'Type must be asset, liability, equity, income, or expense.';
        }

        if (! in_array($data['normal_balance'], ['debit', 'credit'], true)) {
            $errors['normal_balance'][] = 'Normal balance must be debit or credit.';
        }

        if (filled($data['parent_code'] ?? null) && ! ChartAccount::where('code', $data['parent_code'])->when($organizationId, fn ($query) => $query->where('organization_id', $organizationId))->exists()) {
            $errors['parent_code'][] = 'Parent account code was not found.';
        }

        if (ChartAccount::where('code', $data['code'] ?? null)->exists()) {
            $errors['code'][] = 'Chart account code already exists.';
        }

        return $errors;
    }

    private function importRow(ImportBatch $batch, ImportRow $row, User $user): ?Model
    {
        $data = $row->normalized_data ?? [];

        return match ($batch->type) {
            'clients' => $this->importClientWithDefaultAccounts($data, $user),
            'savings_accounts' => SavingsAccount::create($this->savingsAccountPayload($data, $user)),
            'opening_balances' => $this->postOpeningBalance($row, $data, $user),
            'existing_loans' => Loan::create($this->loanPayload($data, $user)),
            'repayment_schedules' => LoanSchedule::create($this->schedulePayload($data)),
            'chart_accounts' => ChartAccount::create($this->chartAccountPayload($data, $user)),
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function clientPayload(array $data, User $user): array
    {
        $branch = Branch::query()->find($data['branch_id']);

        return [
            'organization_id' => $branch?->organization_id ?? $user->organization_id,
            'branch_id' => $data['branch_id'],
            'officer_id' => $user->id,
            'client_number' => $data['client_number'],
            'client_type' => $data['client_type'],
            'first_name' => $data['first_name'],
            'middle_name' => $data['middle_name'] ?? null,
            'last_name' => $data['last_name'],
            'phone' => $data['phone'],
            'email' => $data['email'] ?? null,
            'national_id' => $data['national_id'] ?? null,
            'address' => $data['address'] ?? null,
            'business_name' => $data['business_name'] ?? null,
            'kyc_status' => $data['kyc_status'],
            'status' => $data['status'],
            'registered_at' => $data['registered_at'] ?? today()->toDateString(),
            'kyc_documents' => [
                'identity_confirmed' => false,
                'address_confirmed' => false,
                'photo_captured' => false,
                'signature_captured' => false,
                'next_of_kin_confirmed' => false,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function importClientWithDefaultAccounts(array $data, User $user): Client
    {
        $client = Client::create($this->clientPayload($data, $user));
        $client->loadMissing('branch');

        $savingsAccount = $this->ensureImportedClientSavingsAccount($client, $user);

        $this->createImportedDraftLoan($client, $savingsAccount, $data, $user);

        return $client;
    }

    private function ensureImportedClientSavingsAccount(Client $client, User $user): Account
    {
        $existing = $client->accounts()
            ->whereHas('accountType', fn ($query) => $query->where('category', 'savings'))
            ->first();

        if ($existing) {
            return $existing;
        }

        $type = AccountType::query()->firstOrCreate(
            ['code' => 'SAV'],
            [
                'organization_id' => $client->organization_id ?? $user->organization_id,
                'name' => 'Savings Account',
                'category' => 'savings',
                'minimum_balance' => 0,
                'is_active' => true,
            ],
        );

        return Account::create([
            'organization_id' => $client->organization_id ?? $user->organization_id,
            'client_id' => $client->id,
            'branch_id' => $client->branch_id,
            'account_type_id' => $type->id,
            'account_number' => $this->numbers->accountNumber($client->branch, $type),
            'account_name' => trim(($client->full_name ?: $client->business_name ?: $client->client_number).' Savings Account'),
            'status' => 'active',
            'balance' => 0,
            'opened_at' => $client->registered_at?->toDateString() ?? today()->toDateString(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function createImportedDraftLoan(Client $client, Account $savingsAccount, array $data, User $user): Loan
    {
        $product = LoanProduct::query()
            ->where('code', strtoupper((string) ($data['loan_code'] ?? 'WEEKLY20')))
            ->firstOrFail();
        $applicationDate = Carbon::parse($data['registered_at'] ?? today()->toDateString());
        $requestedAmount = (float) $data['loan_amount'];
        $installments = max(1, (int) ($data['installments'] ?? 4));

        $loan = Loan::create([
            'organization_id' => $client->organization_id ?? $user->organization_id,
            'client_id' => $client->id,
            'branch_id' => $client->branch_id,
            'loan_product_id' => $product->id,
            'loan_type' => 'standard',
            'savings_account_id' => $savingsAccount->id,
            'officer_id' => $client->officer_id ?: $user->id,
            'created_by' => $user->id,
            'loan_number' => $this->numbers->loanNumber($client->branch),
            'requested_amount' => $requestedAmount,
            'approved_amount' => null,
            'principal_balance' => 0,
            'interest_balance' => 0,
            'fees_balance' => 0,
            'penalty_balance' => 0,
            'interest_rate' => $product->annual_interest_rate,
            'term_periods' => $installments,
            'repayment_frequency' => $product->repayment_frequency,
            'purpose' => 'Imported loan application draft',
            'savings_balance_at_application' => (float) $savingsAccount->balance,
            'required_savings_amount' => round($requestedAmount * ((float) $product->required_savings_percentage / 100), 2),
            'application_date' => $applicationDate->toDateString(),
            'status' => 'draft',
        ]);

        $this->writeImportedPreviewSchedule($loan->refresh()->load('product'), $requestedAmount, $applicationDate);

        return $loan->refresh();
    }

    private function writeImportedPreviewSchedule(Loan $loan, float $principal, Carbon $applicationDate): void
    {
        $rows = $this->loanSchedules->preview(
            $principal,
            $this->periodicRate((float) $loan->interest_rate, $loan->repayment_frequency),
            max(1, (int) $loan->term_periods),
            $loan->product?->interest_method ?: 'reducing_balance_equal_installments',
            $applicationDate,
            $loan->repayment_frequency,
        );

        foreach ($rows as $row) {
            LoanSchedule::create([
                'loan_id' => $loan->id,
                'installment_number' => $row['installment'],
                'due_date' => $row['due_date'],
                'principal_due' => $row['principal'],
                'interest_due' => $row['interest'],
                'total_due' => $row['total'],
                'outstanding_amount' => $row['total'],
                'balance_due' => $row['total'],
                'status' => 'preview',
            ]);
        }

        $loan->update(['interest_balance' => round(collect($rows)->sum('interest'), 2)]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function savingsAccountPayload(array $data, User $user): array
    {
        $client = Client::where('client_number', $data['client_number'])->firstOrFail();
        $product = SavingsProduct::where('code', $data['product_code'])->firstOrFail();

        return [
            'organization_id' => $user->organization_id ?? $client->organization_id,
            'branch_id' => $data['branch_id'],
            'client_id' => $client->id,
            'savings_product_id' => $product->id,
            'created_by' => $user->id,
            'account_number' => $data['account_number'],
            'opening_date' => $data['opening_date'],
            'current_balance' => $data['current_balance'],
            'available_balance' => $data['available_balance'],
            'status' => $data['status'],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function postOpeningBalance(ImportRow $row, array $data, User $user): SavingsTransaction
    {
        $account = SavingsAccount::where('account_number', $data['account_number'])->lockForUpdate()->firstOrFail();
        $before = (float) $account->current_balance;
        $after = (float) $data['balance'];

        $account->update([
            'current_balance' => $after,
            'available_balance' => $after,
        ]);

        $transaction = SavingsTransaction::create([
            'organization_id' => $account->organization_id,
            'branch_id' => $account->branch_id,
            'client_id' => $account->client_id,
            'savings_account_id' => $account->id,
            'teller_id' => $user->id,
            'reference' => 'IMP-OB-'.Str::upper(Str::random(12)),
            'type' => 'opening_balance',
            'amount' => $after,
            'charges' => 0,
            'balance_before' => $before,
            'balance_after' => $after,
            'payment_method' => 'migration',
            'narration' => $data['narration'] ?? 'Opening balance import',
            'transaction_date' => $data['transaction_date'],
            'value_date' => $data['transaction_date'],
            'status' => 'successful',
        ]);

        $row->update(['rollback_data' => ['account_id' => $account->id, 'previous_balance' => $before]]);

        return $transaction;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function loanPayload(array $data, User $user): array
    {
        $client = Client::where('client_number', $data['client_number'])->firstOrFail();
        $product = LoanProduct::where('code', $data['product_code'])->firstOrFail();

        return [
            'organization_id' => $user->organization_id ?? $client->organization_id,
            'client_id' => $client->id,
            'branch_id' => $data['branch_id'],
            'loan_product_id' => $product->id,
            'officer_id' => $user->id,
            'loan_number' => $data['loan_number'],
            'requested_amount' => $data['requested_amount'],
            'approved_amount' => $data['approved_amount'],
            'principal_balance' => $data['principal_balance'],
            'interest_balance' => $data['interest_balance'],
            'fees_balance' => $data['fees_balance'],
            'penalty_balance' => $data['penalty_balance'],
            'interest_rate' => $data['interest_rate'],
            'term_periods' => $data['term_periods'],
            'repayment_frequency' => $data['repayment_frequency'],
            'purpose' => $data['purpose'] ?? 'Legacy loan migration',
            'status' => $data['status'],
            'application_date' => $data['application_date'],
            'approved_at' => $data['approved_at'] ?? null,
            'disbursed_at' => $data['disbursed_at'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function schedulePayload(array $data): array
    {
        $loan = Loan::where('loan_number', $data['loan_number'])->firstOrFail();

        return [
            'organization_id' => $loan->organization_id,
            'loan_id' => $loan->id,
            'installment_number' => $data['installment_number'],
            'due_date' => $data['due_date'],
            'principal_due' => $data['principal_due'],
            'interest_due' => $data['interest_due'],
            'fees_due' => $data['fees_due'],
            'penalty_due' => $data['penalty_due'],
            'total_due' => $data['total_due'],
            'principal_paid' => $data['principal_paid'],
            'interest_paid' => $data['interest_paid'],
            'fees_paid' => $data['fees_paid'],
            'penalty_paid' => $data['penalty_paid'],
            'total_paid' => $data['total_paid'],
            'amount_paid' => $data['total_paid'],
            'outstanding_amount' => $data['outstanding_amount'],
            'balance_due' => $data['outstanding_amount'],
            'status' => $data['status'],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function chartAccountPayload(array $data, User $user): array
    {
        $branch = filled($data['branch_id'] ?? null) ? Branch::query()->find($data['branch_id']) : null;

        return [
            'organization_id' => $branch?->organization_id ?? $user->organization_id,
            'branch_id' => $data['branch_id'] ?? null,
            'parent_id' => filled($data['parent_code'] ?? null) ? ChartAccount::where('code', $data['parent_code'])->value('id') : null,
            'code' => $data['code'],
            'name' => $data['name'],
            'type' => $data['type'],
            'normal_balance' => $data['normal_balance'],
            'is_cash_account' => $data['is_cash_account'],
            'is_system' => $data['is_system'],
            'is_active' => $data['is_active'],
        ];
    }

    private function rollbackRow(ImportRow $row): bool
    {
        if ($row->record_type === (new SavingsTransaction)->getMorphClass()) {
            $transaction = SavingsTransaction::find($row->record_id);
            $rollback = $row->rollback_data ?? [];

            if (! $transaction || $transaction->status !== 'successful' || empty($rollback['account_id'])) {
                return false;
            }

            $account = SavingsAccount::lockForUpdate()->find($rollback['account_id']);

            if (! $account || (float) $account->current_balance !== (float) $transaction->balance_after) {
                return false;
            }

            $account->update([
                'current_balance' => $rollback['previous_balance'],
                'available_balance' => $rollback['previous_balance'],
            ]);
            $transaction->update(['status' => 'reversed', 'reversal_reason' => 'Rolled back from import batch.']);

            return true;
        }

        $model = $this->modelForRecord($row);

        if (! $model) {
            return false;
        }

        if ($model instanceof Client) {
            return $this->rollbackImportedClient($model);
        }

        if ($model instanceof SavingsAccount && $model->transactions()->exists()) {
            return false;
        }

        if ($model instanceof Loan && ($model->repayments()->exists() || $model->disbursements()->exists())) {
            return false;
        }

        if ($model instanceof ChartAccount && ($model->children()->exists() || $model->journalEntries()->exists())) {
            return false;
        }

        if ($model instanceof Loan) {
            $model->update(['status' => 'cancelled']);

            return true;
        }

        if ($model instanceof LoanSchedule) {
            $model->update(['status' => 'cancelled']);

            return true;
        }

        $model->delete();

        return true;
    }

    private function rollbackImportedClient(Client $client): bool
    {
        $client->loadMissing(['accounts.transactions', 'loans.repayments', 'loans.disbursements']);

        foreach ($client->loans as $loan) {
            if (! in_array($loan->status, ['draft', 'cancelled'], true) || $loan->repayments->isNotEmpty() || $loan->disbursements->isNotEmpty()) {
                return false;
            }
        }

        foreach ($client->accounts as $account) {
            if ($account->transactions->isNotEmpty() || (float) $account->balance !== 0.0) {
                return false;
            }
        }

        foreach ($client->loans as $loan) {
            $loan->schedules()->delete();
            $loan->delete();
        }

        $client->accounts()->delete();
        $client->delete();

        return true;
    }

    private function modelForRecord(ImportRow $row): ?Model
    {
        $classes = [Client::class, SavingsAccount::class, Loan::class, LoanSchedule::class, ChartAccount::class];

        foreach ($classes as $class) {
            $model = new $class;

            if ($row->record_type === $model->getMorphClass()) {
                return $class::find($row->record_id);
            }
        }

        return null;
    }

    private function refreshCounts(ImportBatch $batch): void
    {
        $counts = $batch->rows()
            ->select('status', DB::raw('count(*) as total'))
            ->groupBy('status')
            ->pluck('total', 'status');

        $batch->update([
            'total_rows' => (int) $counts->sum(),
            'valid_rows' => (int) ($counts['valid'] ?? 0),
            'invalid_rows' => (int) ($counts['invalid'] ?? 0),
            'imported_rows' => (int) ($counts['imported'] ?? 0),
            'failed_rows' => (int) ($counts['failed'] ?? 0),
            'summary' => $counts->all(),
            'status' => ($counts['invalid'] ?? 0) > 0 ? 'invalid' : ($batch->status === 'invalid' ? 'preview' : $batch->status),
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, string>  $fields
     * @return array<string, array<int, string>>
     */
    private function required(array $data, array $fields): array
    {
        $errors = [];

        foreach ($fields as $field) {
            if (! filled($data[$field] ?? null)) {
                $errors[$field][] = str($field)->headline().' is required.';
            }
        }

        return $errors;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, array<int, string>>  $errors
     */
    private function resolveBranchCode(array &$data, User $user, ?Branch $fallback, ?int $organizationId, array &$errors): void
    {
        $branch = filled($data['branch_code'] ?? null)
            ? Branch::query()
                ->when($organizationId, fn ($query) => $query->where('organization_id', $organizationId))
                ->where('code', $data['branch_code'])
                ->first()
            : $fallback;

        if (! $branch) {
            $errors['branch_code'][] = 'Branch code was not found.';

            return;
        }

        if (! $user->canAccessBranch($branch->id, $branch->organization_id)) {
            $errors['branch_code'][] = 'Branch is outside your access.';
        }

        $data['branch_id'] = $branch->id;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, array<int, string>>  $errors
     */
    private function resolveOptionalBranchCode(array &$data, User $user, ?Branch $fallback, ?int $organizationId, array &$errors): void
    {
        if (! filled($data['branch_code'] ?? null) && ! $fallback) {
            return;
        }

        $this->resolveBranchCode($data, $user, $fallback, $organizationId, $errors);
    }

    private function resolveOrganization(User $user, ?int $organizationId): ?Organization
    {
        if (! $organizationId) {
            return $user->organization_id ? Organization::query()->find($user->organization_id) : null;
        }

        if (! $user->canAccessOrganization($organizationId)) {
            throw new InvalidArgumentException('You cannot import data for another organization.');
        }

        return Organization::query()->findOrFail($organizationId);
    }

    private function resolveBranch(User $user, ?int $branchId, ?Organization $organization): ?Branch
    {
        $branch = $branchId ? Branch::query()->findOrFail($branchId) : (ApiSecurity::hasGlobalBranchAccess($user) ? null : $user->branch);

        if ($branch && ! $user->canAccessBranch($branch->id, $branch->organization_id)) {
            throw new InvalidArgumentException('You cannot import data for another branch.');
        }

        if ($branch && $organization && (int) $branch->organization_id !== (int) $organization->id) {
            throw new InvalidArgumentException('The selected branch does not belong to the selected organization.');
        }

        return $branch;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, array<int, string>>  $errors
     */
    private function dateField(array &$data, string $field, array &$errors, ?string $default = null): void
    {
        if (! filled($data[$field] ?? null)) {
            if ($default !== null) {
                $data[$field] = $default;
            }

            return;
        }

        try {
            $value = $data[$field];

            if (is_numeric($value)) {
                $data[$field] = Carbon::create(1899, 12, 30)->addDays((int) $value)->toDateString();

                return;
            }

            $data[$field] = Carbon::parse((string) $value)->toDateString();
        } catch (Throwable) {
            $errors[$field][] = str($field)->headline().' must be a valid date.';
        }
    }

    private function money(mixed $value): float
    {
        return round((float) str_replace([',', 'UGX', 'ugx', ' '], '', (string) $value), 2);
    }

    private function bool(mixed $value): bool
    {
        return in_array(strtolower((string) $value), ['1', 'true', 'yes', 'y', 'active'], true);
    }

    private function periodicRate(float $annualRate, string $frequency): float
    {
        return match ($frequency) {
            'daily' => $annualRate / 365,
            'weekly' => $annualRate / 52,
            'bi-weekly' => $annualRate / 26,
            'quarterly' => $annualRate / 4,
            default => $annualRate / 12,
        };
    }

    private function guardType(string $type): void
    {
        if (! array_key_exists($type, $this->types())) {
            throw new InvalidArgumentException('Unsupported import type.');
        }
    }
}
