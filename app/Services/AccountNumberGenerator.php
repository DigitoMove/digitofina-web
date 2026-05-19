<?php

namespace App\Services;

use App\Models\Account;
use App\Models\AccountType;
use App\Models\Branch;
use App\Models\Client;
use App\Models\Loan;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class AccountNumberGenerator
{
    public function clientNumber(Branch $branch): string
    {
        return $this->nextNumber(
            Client::query(),
            'client_number',
            $branch->code.'-C',
            6,
            fn (Builder $query): Builder => $query->where('branch_id', $branch->id),
        );
    }

    public function accountNumber(Branch $branch, AccountType $type): string
    {
        $prefix = $branch->code.'-'.$type->code.'-';

        return $this->nextNumber(
            Account::query(),
            'account_number',
            $prefix,
            7,
            fn (Builder $query): Builder => $query->where('branch_id', $branch->id)
                ->where('account_type_id', $type->id),
        );
    }

    public function loanNumber(Branch $branch): string
    {
        return $this->nextNumber(
            Loan::query(),
            'loan_number',
            $branch->code.'-LN-',
            7,
            fn (Builder $query): Builder => $query->where('branch_id', $branch->id),
        );
    }

    /**
     * @param  Builder<Model>  $query
     */
    private function nextNumber(Builder $query, string $column, string $prefix, int $padLength, callable $scope): string
    {
        /** @var Builder<Model> $scopedQuery */
        $scopedQuery = $scope($query);
        $latestNumber = (clone $scopedQuery)
            ->where($column, 'like', $prefix.'%')
            ->orderByDesc($column)
            ->value($column);

        $sequence = $this->sequenceFromNumber((string) $latestNumber, $prefix) + 1;

        do {
            $candidate = $prefix.str_pad((string) $sequence, $padLength, '0', STR_PAD_LEFT);
            $exists = (clone $scopedQuery)->where($column, $candidate)->exists();
            $sequence++;
        } while ($exists);

        return $candidate;
    }

    private function sequenceFromNumber(string $number, string $prefix): int
    {
        if ($number === '' || ! str_starts_with($number, $prefix)) {
            return 0;
        }

        $suffix = substr($number, strlen($prefix));

        return ctype_digit($suffix) ? (int) $suffix : 0;
    }
}
