<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreClientDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canPerform('manage-clients') ?? false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'type' => ['required', 'in:profile_photo,national_id_front,national_id_back,signature,loan_application,guarantor_form,collateral_document,agreement,receipt,bank_slip,mobile_money_proof,other'],
            'title' => ['required', 'string', 'max:191'],
            'document' => ['required', 'file', 'mimes:jpg,jpeg,png,pdf,webp', 'max:5120'],
        ];
    }
}
