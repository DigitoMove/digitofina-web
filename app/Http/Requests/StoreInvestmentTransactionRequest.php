<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreInvestmentTransactionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canPerform('post-transactions') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'amount' => ['required', 'numeric', 'gt:0'],
            'payment_method' => ['sometimes', 'in:cash,mobile_money,bank,internal_transfer'],
            'narration' => ['nullable', 'string', 'max:1000'],
            'transaction_date' => ['nullable', 'date'],
            'idempotency_key' => ['nullable', 'string', 'max:120'],
        ];
    }
}
