<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreSavingsTransactionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canPerform('post-transactions') ?? false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'amount' => ['required', 'numeric', 'min:1'],
            'payment_method' => ['required', 'in:cash,mobile_money,bank,cheque,internal_transfer'],
            'narration' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
