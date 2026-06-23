<?php

namespace App\Http\Requests;

use App\Support\CurrencyDenomination;
use Illuminate\Foundation\Http\FormRequest;

class StoreDrawerTransferRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->hasRole('super-admin') === true
            || $this->user()?->hasRole('organization-admin') === true
            || $this->user()?->canPerform('manage-organization') === true
            || ($this->user()?->canPerform('manage-drawers') === true && $this->user()?->canPerform('manage-users') === true);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'source_drawer_session_id' => ['required', 'integer', 'exists:drawer_sessions,id', 'different:destination_drawer_session_id'],
            'destination_drawer_session_id' => ['required', 'integer', 'exists:drawer_sessions,id'],
            'amount' => ['required', 'numeric', 'min:'.CurrencyDenomination::MINIMUM_UNIT, CurrencyDenomination::validationRule()],
            'charges' => ['nullable', 'numeric', 'min:0', CurrencyDenomination::validationRule()],
            'transaction_date' => ['required', 'date', 'before_or_equal:today'],
            'description' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
