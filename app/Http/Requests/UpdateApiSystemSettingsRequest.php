<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateApiSystemSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canPerform('manage-settings') ?? false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'settings' => ['required', 'array', 'min:1'],
            'settings.*' => ['nullable', 'string', 'max:10000'],
        ];
    }
}
