<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class VerifyClientDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canPerform('verify-kyc') || $this->user()?->canPerform('manage-clients');
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'verification_status' => ['required', 'in:verified,rejected'],
        ];
    }
}
