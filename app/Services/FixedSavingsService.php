<?php

namespace App\Services;

use App\Models\Client;
use App\Models\FixedSavingsAccount;
use App\Models\User;
use App\Support\CurrencyDenomination;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class FixedSavingsService
{
    public function __construct(
        private AuditLogger $auditLogger,
        private JournalEntryService $journalEntryService,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function open(array $data, User $user): FixedSavingsAccount
    {
        $principal = (float) $data['principal_amount'];

        if ($principal <= 0) {
            throw new InvalidArgumentException('Principal amount must be greater than zero.');
        }

        CurrencyDenomination::assertValid($principal, 'Principal amount');
        $client = Client::findOrFail($data['client_id']);

        if ($client->status !== 'active') {
            throw new InvalidArgumentException('Client must be active before opening fixed savings.');
        }

        return DB::transaction(function () use ($data, $user, $principal, $client): FixedSavingsAccount {
            $startDate = CarbonImmutable::parse($data['start_date'] ?? today()->toDateString());
            $maturityDate = CarbonImmutable::parse($data['maturity_date']);
            $interestRate = (float) ($data['interest_rate'] ?? 0);
            $expectedInterest = $this->expectedInterest($principal, $interestRate, $startDate, $maturityDate);

            $fixed = FixedSavingsAccount::create([
                'organization_id' => $client->organization_id ?: $user->organization_id,
                'client_id' => $client->id,
                'branch_id' => $data['branch_id'] ?? $client->branch_id,
                'savings_account_id' => $data['savings_account_id'] ?? null,
                'created_by' => $user->id,
                'account_number' => $this->accountNumber((int) ($data['branch_id'] ?? $client->branch_id)),
                'principal_amount' => $principal,
                'start_date' => $startDate->toDateString(),
                'maturity_date' => $maturityDate->toDateString(),
                'interest_rate' => $interestRate,
                'expected_interest' => $expectedInterest,
                'actual_interest' => 0,
                'early_withdrawal_allowed' => (bool) ($data['early_withdrawal_allowed'] ?? false),
                'early_withdrawal_penalty' => (float) ($data['early_withdrawal_penalty'] ?? 0),
                'status' => 'active',
            ]);

            $this->journalEntryService->post([
                ['account_code' => '1000', 'debit' => $principal, 'narration' => 'Fixed savings cash received'],
                ['account_code' => '2100', 'credit' => $principal, 'narration' => 'Fixed savings liability increased'],
            ], "Fixed savings opened {$fixed->account_number}", $user, $fixed->branch_id);
            $this->auditLogger->record('savings.fixed_opened', $fixed, $fixed->toArray());

            return $fixed;
        });
    }

    public function markMatured(): int
    {
        return DB::transaction(function (): int {
            $accounts = FixedSavingsAccount::query()
                ->where('status', 'active')
                ->whereDate('maturity_date', '<=', today())
                ->lockForUpdate()
                ->get();

            foreach ($accounts as $account) {
                $account->update(['status' => 'matured', 'actual_interest' => $account->expected_interest]);
                $this->auditLogger->record('savings.fixed_matured', $account, ['maturity_date' => $account->maturity_date?->toDateString()]);
            }

            return $accounts->count();
        });
    }

    private function expectedInterest(float $principal, float $rate, CarbonImmutable $startDate, CarbonImmutable $maturityDate): float
    {
        $years = max(1 / 365, $startDate->diffInDays($maturityDate) / 365);

        return round($principal * ($rate / 100) * $years, 2);
    }

    private function accountNumber(int $branchId): string
    {
        return str_pad((string) $branchId, 4, '0', STR_PAD_LEFT).'-FS-'.Str::padLeft((string) (FixedSavingsAccount::count() + 1), 7, '0');
    }
}
