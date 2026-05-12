<?php

namespace App\Services;

use App\Models\MobileMoneyTransaction;
use App\Models\PaymentRequest;

class MobileMoneyReconciliationService
{
    public function markMatched(MobileMoneyTransaction $transaction, PaymentRequest $request): MobileMoneyTransaction
    {
        $transaction->update([
            'payment_request_id' => $request->id,
            'status' => (float) $transaction->amount === (float) $request->amount ? 'successful' : 'suspicious',
            'reconciled_at' => now(),
        ]);

        return $transaction->refresh();
    }
}
