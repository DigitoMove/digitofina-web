<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreSavingsAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canPerform('manage-accounts') ?? false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'client_id' => ['required', 'exists:clients,id'],
            'branch_id' => ['required', 'exists:branches,id'],
            'savings_product_id' => ['required', 'exists:savings_products,id'],
            'opening_date' => ['required', 'date'],
            'status' => ['required', 'in:pending,active,frozen,dormant,closed'],
            'opening_deposit' => ['nullable', 'numeric', 'min:0'],
        ];
    }
}
