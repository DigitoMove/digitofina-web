<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreClientNoteRequest extends FormRequest
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
            'category' => ['required', 'in:general,kyc,risk,collections,field_visit'],
            'note' => ['required', 'string', 'max:2000'],
        ];
    }
}
