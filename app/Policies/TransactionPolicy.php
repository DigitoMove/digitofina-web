<?php

namespace App\Policies;

use App\Models\Transaction;
use App\Models\User;

class TransactionPolicy
{
    public function create(User $user): bool
    {
        return $user->canPerform('post-transactions');
    }

    public function reverse(User $user, Transaction $transaction): bool
    {
        return $user->canPerform('approve-reversals') && $transaction->status === 'posted';
    }
}
