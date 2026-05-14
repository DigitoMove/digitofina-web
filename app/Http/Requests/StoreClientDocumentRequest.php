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
            'local_uuid' => ['nullable', 'uuid'],
            'content_hash' => ['nullable', 'string', 'max:128'],
            'image_width' => ['nullable', 'integer', 'min:1'],
            'image_height' => ['nullable', 'integer', 'min:1'],
            'captured_at' => ['nullable', 'date'],
            'compression_metadata' => ['nullable', 'array'],
            'compression_metadata.original_size' => ['nullable', 'integer', 'min:1'],
            'compression_metadata.compressed_size' => ['nullable', 'integer', 'min:1'],
            'compression_metadata.quality' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
