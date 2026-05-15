<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateImportRowRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canPerform('import-data') || $this->user()?->canPerform('manage-settings');
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'normalized_data' => ['required', 'array'],
            'normalized_data.*' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
