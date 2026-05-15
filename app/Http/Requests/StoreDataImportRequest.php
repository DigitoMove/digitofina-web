<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDataImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canPerform('import-data') || $this->user()?->canPerform('manage-settings');
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'type' => ['required', 'string', Rule::in(['clients', 'savings_accounts', 'opening_balances', 'existing_loans', 'repayment_schedules', 'chart_accounts'])],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'file' => ['required', 'file', 'mimes:csv,txt,xlsx', 'max:10240'],
        ];
    }
}
