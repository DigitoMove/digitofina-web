<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class MergeLedgerAccountsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasAnyRole(['super-admin', 'organization-admin']) ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'source_account_ids' => ['required', 'array', 'min:1'],
            'source_account_ids.*' => ['required', 'integer', 'distinct', 'exists:chart_accounts,id'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
