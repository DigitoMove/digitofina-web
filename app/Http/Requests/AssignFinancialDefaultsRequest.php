<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AssignFinancialDefaultsRequest extends FormRequest
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
            'loan_product_template_ids' => ['nullable', 'array', 'required_without:chart_account_template_ids'],
            'loan_product_template_ids.*' => ['integer', 'distinct', Rule::exists('loan_product_templates', 'id')->where('is_active', true)],
            'chart_account_template_ids' => ['nullable', 'array', 'required_without:loan_product_template_ids'],
            'chart_account_template_ids.*' => ['integer', 'distinct', Rule::exists('chart_account_templates', 'id')->where('is_active', true)],
        ];
    }
}
