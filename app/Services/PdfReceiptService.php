<?php

namespace App\Services;

use App\Models\SavingsTransaction;

class PdfReceiptService
{
    /**
     * @return array{receipt_number:string,client_id:int,account_number:string,type:string,amount:string,balance_after:string}
     */
    public function savingsReceiptData(SavingsTransaction $transaction): array
    {
        $transaction->loadMissing('account');

        return [
            'receipt_number' => $transaction->reference,
            'client_id' => $transaction->client_id,
            'account_number' => $transaction->account->account_number,
            'type' => $transaction->type,
            'amount' => $transaction->amount,
            'balance_after' => $transaction->balance_after,
        ];
    }
}
