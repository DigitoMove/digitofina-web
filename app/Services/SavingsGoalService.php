<?php

namespace App\Services;

use App\Models\Client;
use App\Models\SavingsAccount;
use App\Models\SavingsGoal;
use App\Models\SavingsGoalTransaction;
use App\Models\User;
use App\Support\CurrencyDenomination;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class SavingsGoalService
{
    public function __construct(
        private AuditLogger $auditLogger,
        private SavingsTransactionService $savingsTransactions,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, User $user): SavingsGoal
    {
        $client = Client::findOrFail($data['client_id']);

        if ($client->status !== 'active') {
            throw new InvalidArgumentException('Client must be active before creating a savings goal.');
        }

        $goal = SavingsGoal::create([
            'organization_id' => $client->organization_id ?: $user->organization_id,
            'client_id' => $client->id,
            'branch_id' => $data['branch_id'] ?? $client->branch_id,
            'savings_account_id' => $data['savings_account_id'] ?? null,
            'created_by' => $user->id,
            'updated_by' => $user->id,
            'name' => $data['name'] ?? $data['goal_name'],
            'goal_name' => $data['goal_name'] ?? $data['name'],
            'target_amount' => $data['target_amount'],
            'current_amount' => $data['current_amount'] ?? $data['current_saved_amount'] ?? 0,
            'current_saved_amount' => $data['current_saved_amount'] ?? 0,
            'start_date' => $data['start_date'] ?? today()->toDateString(),
            'target_date' => $data['target_date'],
            'contribution_frequency' => $data['contribution_frequency'] ?? 'monthly',
            'expected_contribution_amount' => $data['expected_contribution_amount'] ?? 0,
            'status' => $data['status'] ?? 'active',
        ]);

        $this->auditLogger->record('savings.goal_created', $goal, $goal->toArray());

        return $goal;
    }

    public function contribute(SavingsGoal $goal, float $amount, User $user, string $paymentMethod = 'cash', ?string $narration = null): SavingsGoalTransaction
    {
        return $this->post($goal, 'contribution', $amount, $user, $paymentMethod, $narration);
    }

    public function withdraw(SavingsGoal $goal, float $amount, User $user, string $paymentMethod = 'cash', ?string $narration = null): SavingsGoalTransaction
    {
        return $this->post($goal, 'withdrawal', $amount, $user, $paymentMethod, $narration);
    }

    public function transferToMainSavings(SavingsGoal $goal, float $amount, User $user, ?string $narration = null): SavingsGoalTransaction
    {
        return $this->post($goal, 'transfer_to_main_savings', $amount, $user, 'internal_transfer', $narration);
    }

    public function close(SavingsGoal $goal, User $user, ?string $narration = null): SavingsGoalTransaction
    {
        return DB::transaction(function () use ($goal, $user, $narration): SavingsGoalTransaction {
            $goal = SavingsGoal::query()->whereKey($goal->id)->lockForUpdate()->firstOrFail();

            if (! in_array($goal->status, ['active', 'achieved'], true)) {
                throw new InvalidArgumentException('Only active or achieved goals can be closed.');
            }

            $balance = (float) $goal->current_saved_amount;
            $goal->update(['current_amount' => 0, 'current_saved_amount' => 0, 'status' => 'cancelled']);

            return $this->createTransaction($goal, $user, 'goal_closure', $balance, $balance, 0, 'internal_transfer', $narration);
        });
    }

    private function post(SavingsGoal $goal, string $type, float $amount, User $user, string $paymentMethod, ?string $narration): SavingsGoalTransaction
    {
        if ($amount <= 0) {
            throw new InvalidArgumentException('Amount must be greater than zero.');
        }

        CurrencyDenomination::assertValid($amount);

        return DB::transaction(function () use ($goal, $type, $amount, $user, $paymentMethod, $narration): SavingsGoalTransaction {
            $goal = SavingsGoal::query()->whereKey($goal->id)->lockForUpdate()->firstOrFail();

            if ($goal->status !== 'active') {
                throw new InvalidArgumentException('Only active savings goals can receive transactions.');
            }

            $before = (float) $goal->current_saved_amount;
            $after = $type === 'contribution' ? $before + $amount : $before - $amount;

            if ($after < 0) {
                throw new InvalidArgumentException('Savings goal transaction exceeds the saved goal amount.');
            }

            if ($goal->savings_account_id) {
                $account = SavingsAccount::findOrFail($goal->savings_account_id);

                if ($type === 'contribution') {
                    $this->savingsTransactions->deposit($account, $amount, $user, $paymentMethod, $narration ?: "Savings goal contribution: {$goal->name}");
                } elseif ($type === 'withdrawal') {
                    $this->savingsTransactions->withdraw($account, $amount, $user, $paymentMethod, $narration ?: "Savings goal withdrawal: {$goal->name}");
                }
            }

            $goal->update([
                'current_amount' => $after,
                'current_saved_amount' => $after,
                'status' => $after >= (float) $goal->target_amount ? 'achieved' : 'active',
            ]);

            return $this->createTransaction($goal, $user, $type, $amount, $before, $after, $paymentMethod, $narration);
        });
    }

    private function createTransaction(SavingsGoal $goal, User $user, string $type, float $amount, float $before, float $after, string $paymentMethod, ?string $narration): SavingsGoalTransaction
    {
        $transaction = SavingsGoalTransaction::create([
            'organization_id' => $goal->organization_id,
            'savings_goal_id' => $goal->id,
            'savings_account_id' => $goal->savings_account_id,
            'created_by' => $user->id,
            'reference' => 'SG'.now()->format('ymdHis').Str::upper(Str::random(5)),
            'type' => $type,
            'amount' => $amount,
            'balance_before' => $before,
            'balance_after' => $after,
            'payment_method' => $paymentMethod,
            'narration' => $narration,
            'transaction_date' => today()->toDateString(),
            'status' => 'successful',
        ]);

        $this->auditLogger->record("savings.goal_{$type}", $transaction, ['amount' => $amount]);

        return $transaction;
    }
}
