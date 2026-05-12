<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreLoanCollateralRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canPerform('manage-loans') ?? false;
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
            'description' => ['required', 'string'],
            'estimated_value' => ['required', 'numeric', 'min:0'],
            'forced_sale_value' => ['nullable', 'numeric', 'min:0'],
            'ownership_proof' => ['nullable', 'string', 'max:255'],
            'valuation_date' => ['nullable', 'date'],
            'status' => ['required', Rule::in(['pending_verification', 'verified', 'rejected'])],
        ];
    }
}
