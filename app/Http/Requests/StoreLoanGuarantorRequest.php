<?php

namespace App\Http\Requests;

use App\Models\Loan;
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
        $loan = $this->route('loan');
        $guaranteedAmount = $loan instanceof Loan
            ? ((float) $loan->approved_amount > 0 ? (float) $loan->approved_amount : (float) $loan->requested_amount)
            : $this->input('guaranteed_amount');

        $this->merge([
            'status' => $this->input('status', 'pending'),
            'guaranteed_amount' => $guaranteedAmount,
            'type' => $this->input('type', $this->filled('guarantor_client_id') ? 'existing_client' : 'external'),
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
            'guarantor_client_id' => ['nullable', Rule::exists('clients', 'id')],
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:40'],
            'national_id' => ['nullable', 'string', 'max:80'],
            'address' => ['required', 'string'],
            'relationship' => ['nullable', 'string', 'max:255'],
            'guaranteed_amount' => ['required', 'numeric', 'min:1'],
            'status' => ['nullable', Rule::in(['pending', 'accepted', 'rejected'])],
        ];
    }
}
