<?php

namespace App\Http\Requests;

use App\Models\Loan;
use App\Support\CurrencyDenomination;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreLoanCollateralRequest extends FormRequest
{
    public function authorize(): bool
    {
        $loan = $this->route('loan');

        return $loan instanceof Loan
            && ($this->user()?->canPerform('manage-loans')
                || $this->user()?->canManageCreatedLoanApplication($loan));
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'status' => $this->user()?->canPerform('manage-loans') ? $this->input('status', 'pending_verification') : 'pending_verification',
        ]);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'type' => ['required', Rule::in(['land', 'vehicle', 'electronics', 'household_item', 'business_asset', 'livestock', 'salary', 'other'])],
            'description' => ['nullable', 'string'],
            'estimated_value' => ['required', 'numeric', 'min:'.CurrencyDenomination::MINIMUM_UNIT, CurrencyDenomination::validationRule()],
            'forced_sale_value' => ['nullable', 'numeric', 'min:'.CurrencyDenomination::MINIMUM_UNIT, CurrencyDenomination::validationRule()],
            'ownership_proof' => ['nullable', 'string', 'max:255'],
            'valuation_date' => ['nullable', 'date'],
            'status' => ['nullable', Rule::in(['pending_verification', 'verified', 'rejected'])],
        ];
    }
}
