<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreLoanGuarantorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canPerform('manage-loans') ?? false;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'status' => $this->input('status', 'pending'),
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
            'type' => ['required', Rule::in(['existing_client', 'external'])],
            'guarantor_client_id' => ['nullable', 'required_if:type,existing_client', Rule::exists('clients', 'id')],
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:40'],
            'national_id' => ['nullable', 'string', 'max:80'],
            'address' => ['nullable', 'string'],
            'relationship' => ['nullable', 'string', 'max:255'],
            'guaranteed_amount' => ['required', 'numeric', 'min:1'],
            'status' => ['nullable', Rule::in(['pending', 'accepted', 'rejected'])],
        ];
    }
}
