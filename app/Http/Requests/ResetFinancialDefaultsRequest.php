<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ResetFinancialDefaultsRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->hasRole('super-admin') === true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'resource' => ['required', Rule::in(['loan_products', 'chart_accounts'])],
            'confirmation' => ['required', 'string', Rule::in(['RESET '.$this->route('organization')?->slug])],
        ];

    }

    public function messages(): array
    {
        return [
            'confirmation.in' => 'Type the exact reset phrase shown for this organization.',
        ];
    }
}
