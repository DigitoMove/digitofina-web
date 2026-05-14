<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreSavingsTransferRequest extends FormRequest
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
            'from_savings_account_id' => ['required', 'exists:savings_accounts,id'],
            'to_savings_account_id' => ['required', 'different:from_savings_account_id', 'exists:savings_accounts,id'],
            'amount' => ['required', 'numeric', 'min:1'],
            'narration' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
