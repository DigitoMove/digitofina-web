<?php

namespace App\Http\Requests;

use App\Support\CurrencyDenomination;
use Illuminate\Foundation\Http\FormRequest;

class StoreJournalEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canPerform('post-journal-entries') || $this->user()?->canPerform('manage-accounting');
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'description' => ['required', 'string', 'max:1000'],
            'branch_id' => ['nullable', 'exists:branches,id'],
            'lines' => ['required', 'array', 'min:2'],
            'lines.*.account_code' => ['required', 'string', 'exists:chart_accounts,code'],
            'lines.*.debit' => ['nullable', 'numeric', 'min:0', CurrencyDenomination::validationRule()],
            'lines.*.credit' => ['nullable', 'numeric', 'min:0', CurrencyDenomination::validationRule()],
            'lines.*.narration' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
