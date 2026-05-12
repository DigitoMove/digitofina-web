<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBranchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canPerform('manage-branches') ?? false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:30', Rule::unique('branches', 'code')],
            'name' => ['required', 'string', 'max:191'],
            'manager_user_id' => ['nullable', 'exists:users,id'],
            'phone' => ['nullable', 'string', 'max:40'],
            'address' => ['nullable', 'string', 'max:191'],
            'status' => ['required', 'in:active,inactive,suspended'],
        ];
    }
}
