<?php

namespace App\Services;

use App\Models\Account;
use App\Models\AccountType;
use App\Models\Branch;
use App\Models\Client;
use App\Models\Loan;

class AccountNumberGenerator
{
    public function clientNumber(Branch $branch): string
    {
        return $branch->code.'-C'.str_pad((string) (Client::count() + 1), 6, '0', STR_PAD_LEFT);
    }

    public function accountNumber(Branch $branch, AccountType $type): string
    {
        return $branch->code.'-'.$type->code.'-'.str_pad((string) (Account::count() + 1), 7, '0', STR_PAD_LEFT);
    }

    public function loanNumber(Branch $branch): string
    {
        return $branch->code.'-LN-'.str_pad((string) (Loan::count() + 1), 7, '0', STR_PAD_LEFT);
    }
}
