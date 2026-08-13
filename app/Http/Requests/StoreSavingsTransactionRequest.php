<?php

namespace App\Http\Requests;

use App\Support\CurrencyDenomination;
use Illuminate\Foundation\Http\FormRequest;

class StoreSavingsTransactionRequest extends FormRequest
{
    public function authorize(): bool
    {
        if ($this->routeIs('savings-transactions.withdrawal.store', 'api.v1.savings.accounts.withdraw', 'api.v1.savings-accounts.withdraw')) {
            return $this->user() !== null && ! $this->user()->isMember();
        }

        return $this->user()?->canPerform('post-transactions') ?? false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'amount' => ['required', 'numeric', 'min:'.CurrencyDenomination::MINIMUM_UNIT, CurrencyDenomination::validationRule()],
            'payment_method' => ['required', 'in:cash,mobile_money,bank,cheque,internal_transfer'],
            'narration' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
