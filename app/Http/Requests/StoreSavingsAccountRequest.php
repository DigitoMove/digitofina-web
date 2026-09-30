<?php

namespace App\Http\Requests;

use App\Support\CurrencyDenomination;
use Illuminate\Foundation\Http\FormRequest;

class StoreSavingsAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canPerform('manage-accounts') ?? false;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'status' => $this->input('status', 'active'),
        ]);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'client_id' => ['required', 'exists:clients,id'],
            'branch_id' => ['required', 'exists:branches,id'],
            'is_organization_wide' => ['sometimes', 'boolean'],
            'savings_product_id' => ['required', 'exists:savings_products,id'],
            'savings_type' => ['nullable', 'in:ordinary,target,fixed,group,emergency,child_student,business'],
            'opening_date' => ['required', 'date'],
            'status' => ['nullable', 'in:pending,active,frozen,dormant,closed'],
            'opening_deposit' => ['nullable', 'numeric', 'min:0', CurrencyDenomination::validationRule()],
        ];
    }
}
