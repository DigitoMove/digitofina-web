<?php

namespace App\Http\Controllers;

use App\Models\Transaction;
use Illuminate\View\View;

class ReceiptController extends Controller
{
    public function show(Transaction $transaction): View
    {
        return view('receipts.show', ['transaction' => $transaction->load(['account.client', 'type', 'user'])]);
    }
}
