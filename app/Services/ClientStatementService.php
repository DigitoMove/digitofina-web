<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Client;
use App\Models\InvestmentAccount;
use App\Models\Loan;
use App\Models\SavingsAccount;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class ClientStatementService
{
    /** @return Collection<string, Account|SavingsAccount|InvestmentAccount|Loan> */
    public function accounts(Client $client): Collection
    {
        $client->loadMissing(['organization', 'branch', 'accounts.accountType', 'savingsAccounts.product', 'investmentAccounts.plan', 'loans.product']);
        $enabledModules = auth()->user()?->hasRole('super-admin') || ! $client->organization
            ? ['savings', 'investments', 'loans']
            : app(ModuleService::class)->enabledSlugs($client->organization);

        return collect([
            'account' => $client->accounts->filter(fn (Account $account): bool => $account->accountType?->category !== 'loan' && ($account->accountType?->category === 'savings' ? in_array('savings', $enabledModules, true) : count(array_intersect(['savings', 'investments', 'loans'], $enabledModules)) > 0)),
            'savings' => in_array('savings', $enabledModules, true) ? $client->savingsAccounts : collect(),
            'investment' => in_array('investments', $enabledModules, true) ? $client->investmentAccounts : collect(),
            'loan' => in_array('loans', $enabledModules, true) ? $client->loans : collect(),
        ])->flatMap(fn (Collection $accounts, string $type): array => $accounts
            ->mapWithKeys(fn ($account): array => [$type.':'.$account->id => $account])->all());
    }

    public function label(Account|SavingsAccount|InvestmentAccount|Loan $account): string
    {
        $product = match (true) {
            $account instanceof Account => $account->accountType?->name,
            $account instanceof InvestmentAccount => $account->plan?->name,
            $account instanceof Loan => 'Loan',
            default => $account->product?->name,
        };

        return implode(' · ', array_filter([$account->account_number ?? $account->investment_number ?? $account->loan_number, $product]));
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>|null
     */
    public function statement(Client $client, array $filters = []): ?array
    {
        $accounts = $this->accounts($client);
        $key = $filters['account'] ?? $accounts->keys()->first();
        abort_if($key && ! $accounts->has($key), 404);
        $account = $accounts->get($key);

        if (! $account) {
            return null;
        }

        $account->loadMissing('branch');
        $currency = $client->organization?->currency ?: 'UGX';
        $isLoan = $account instanceof Loan;
        $events = $isLoan ? $this->repayments($account) : $this->movements($account);
        $from = $filters['date_from'] ?? null;
        $to = $filters['date_to'] ?? null;
        $selected = $events->filter(fn (array $row): bool => (! $from || $row['date']->toDateString() >= $from) && (! $to || $row['date']->toDateString() <= $to))->values();
        $balance = $isLoan
            ? (float) $account->principal_balance + (float) $account->interest_balance + (float) $account->fees_balance + (float) $account->penalty_balance
            : (float) ($account->balance ?? $account->current_balance);
        $prior = $from ? $events->last(fn (array $row): bool => $row['date']->toDateString() < $from) : null;
        $opening = $prior['balance'] ?? $selected->first()['before'] ?? $events->first()['before'] ?? $balance;
        $closing = $selected->last()['balance'] ?? $opening;
        $rows = $selected->map(fn (array $row): array => collect($row)->except(['before', 'order'])->all());

        if (! $isLoan) {
            $rows->prepend(['date' => null, 'transaction_type' => '', 'description' => 'Opening balance', 'debit' => null, 'credit' => null, 'balance' => $opening]);
            $rows->push(['date' => null, 'transaction_type' => '', 'description' => 'Closing balance', 'debit' => null, 'credit' => null, 'balance' => $closing]);
        }

        $metadata = [
            'Account Name' => $client->full_name,
            'Account Number' => $account->account_number ?? $account->investment_number ?? $account->loan_number,
            'Account Branch' => $account->branch?->name ?? $client->branch?->name ?? '—',
            'Account Status' => Str::headline($account->status),
            'Currency' => $currency === 'UGX' ? 'Uganda Shillings (UGX)' : $currency,
            $isLoan ? 'Current Outstanding' : 'Current Balance' => number_format($balance, 2),
            'Period' => ($from ?: 'Beginning').' to '.($to ?: 'Latest transaction'),
        ];

        if ($isLoan) {
            $metadata += [
                'Principal / Interest' => number_format((float) $account->principal_balance, 2).' / '.number_format((float) $account->interest_balance, 2),
                'Fees / Penalties' => number_format((float) $account->fees_balance, 2).' / '.number_format((float) $account->penalty_balance, 2),
            ];
        }

        return [
            'key' => $key,
            'title' => $isLoan ? 'Loan Repayment Statement' : 'Account Statement',
            'heading' => $isLoan ? 'Confirmed loan repayments' : 'Account transactions',
            'organization' => $client->organization,
            'branch' => $account->branch ?? $client->branch,
            'metadata' => $metadata,
            'columns' => $isLoan ? ['date', 'description', 'amount', 'principal_paid', 'interest_paid', 'fees_paid', 'penalty_paid'] : ['date', 'transaction_type', 'description', 'debit', 'credit', 'balance'],
            'rows' => $rows->all(),
            'opening' => $isLoan ? null : $opening,
            'closing' => $isLoan ? null : $closing,
            'debits' => $selected->sum('debit'),
            'credits' => $selected->sum('credit'),
            'currency' => $currency,
            'count' => $selected->count(),
            'note' => $isLoan
                ? 'Only confirmed repayments are included; reversed payments are excluded. Outstanding amounts show the current position, including charges, and are not a historical closing balance.'
                : 'Debit is money out; credit is money in. Balances reflect recorded postings. Current balance may include transactions outside the selected period.',
        ];
    }

    /** @return Collection<int, array<string, mixed>> */
    private function repayments(Loan $loan): Collection
    {
        return $loan->repayments()->where('status', 'successful')->whereNull('reversed_at')->orderBy('paid_at')->orderBy('id')->get()
            ->map(fn ($repayment): array => [
                'date' => $repayment->paid_at ?? $repayment->created_at,
                'description' => 'Repayment · '.$repayment->reference,
                'amount' => (float) $repayment->amount,
                'principal_paid' => (float) $repayment->principal_paid,
                'interest_paid' => (float) $repayment->interest_paid,
                'fees_paid' => (float) $repayment->fees_paid,
                'penalty_paid' => (float) $repayment->penalty_paid,
            ]);
    }

    /** @return Collection<int, array<string, mixed>> */
    private function movements(Account|SavingsAccount|InvestmentAccount $account): Collection
    {
        $legacy = $account instanceof Account;
        $transactions = $account->transactions()
            ->when($legacy, fn ($query) => $query->with('type'))
            ->whereIn('status', $legacy ? ['posted', 'reversed'] : ['successful', 'reversed'])
            ->get();
        $events = collect();

        foreach ($transactions as $transaction) {
            $type = $legacy ? ($transaction->type?->name ?? 'Transaction') : Str::headline($transaction->type);
            $after = (float) $transaction->balance_after;
            if ($legacy) {
                $debit = in_array($transaction->type?->direction, ['debit', 'withdrawal'], true);
                $delta = ($debit ? -1 : 1) * (float) $transaction->amount;
                if ($transaction->reversal_of_id) {
                    $delta *= -1;
                    $type = 'Reversal';
                }
                $before = $after - $delta;
            } else {
                $before = (float) $transaction->balance_before;
                $delta = round($after - $before, 2);
            }
            $date = $legacy ? ($transaction->posted_at ?? $transaction->created_at) : ($transaction->transaction_date ?? $transaction->created_at)->copy()->setTimeFrom($transaction->created_at);
            $description = trim(($transaction->description ?? $transaction->narration ?? '').' · '.$transaction->reference, ' ·');
            if ((float) $transaction->charges > 0) {
                $description .= '; Charges '.number_format((float) $transaction->charges, 2);
            }
            $events->push([
                'date' => $date, 'order' => $transaction->id,
                'transaction_type' => $type, 'description' => $description,
                'debit' => $delta < 0 ? abs($delta) : null, 'credit' => $delta > 0 ? $delta : null,
                'before' => $before, 'balance' => $after,
            ]);
            if (! $legacy && $transaction->status === 'reversed') {
                $events->push([
                    'date' => Carbon::parse($transaction->reversed_at ?? $transaction->updated_at), 'order' => $transaction->id,
                    'transaction_type' => 'Reversal', 'description' => 'Reversal of '.$transaction->reference,
                    'debit' => $delta > 0 ? $delta : null, 'credit' => $delta < 0 ? abs($delta) : null,
                    'before' => $after, 'balance' => $before,
                ]);
            }
        }

        return $events->sortBy([['date', 'asc'], ['order', 'asc']])->values();
    }
}
