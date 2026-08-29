<?php

namespace App\Http\Requests;

use App\Models\LoanProduct;
use App\Support\CurrencyDenomination;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreMemberLoanApplicationRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $client = $this->user()?->client;

        return $this->user()?->isMember()
            && $client?->status === 'active'
            && $client?->kyc_status === 'verified';
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'loan_product_id' => ['required', Rule::exists('loan_products', 'id')->where(fn ($query) => $query->where('organization_id', $this->user()?->organization_id)->where('status', 'active')->where('is_active', true))],
            'requested_amount' => ['required', 'numeric', 'min:'.CurrencyDenomination::MINIMUM_UNIT, CurrencyDenomination::validationRule()],
            'term_periods' => ['required', 'integer', 'min:1', 'max:730'],
            'repayment_frequency' => ['required', Rule::in(['daily', 'weekly', 'monthly'])],
            'purpose' => ['required', 'string', 'max:255'],
            'source_of_repayment' => ['nullable', 'string', 'max:255'],
        ];
    }

    /** @return array<int, callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $product = LoanProduct::query()->find($this->integer('loan_product_id'));

            if (! $product) {
                return;
            }

            if ((float) $this->input('requested_amount') < (float) $product->min_amount || (float) $this->input('requested_amount') > (float) $product->max_amount) {
                $validator->errors()->add('requested_amount', 'The amount is outside this product’s limits.');
            }

            if ($this->integer('term_periods') < (int) $product->min_term || $this->integer('term_periods') > (int) $product->max_term) {
                $validator->errors()->add('term_periods', 'The term is outside this product’s limits.');
            }
        }];
    }
}
