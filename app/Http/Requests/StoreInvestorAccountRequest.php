<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreInvestorAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return ($this->user()?->canPerform('manage-accounts') || $this->user()?->canPerform('manage-investments')) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'client_id' => ['required', 'exists:clients,id'],
            'branch_id' => ['nullable', 'exists:branches,id'],
            'assigned_officer_id' => ['nullable', 'exists:users,id'],
            'status' => ['sometimes', Rule::in(['active', 'suspended', 'closed'])],
        ];
    }
}
