<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreClientGroupRequest extends FormRequest
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
            'branch_id' => ['required', 'exists:branches,id'],
            'group_number' => ['required', 'string', 'max:50', Rule::unique('client_groups', 'group_number')],
            'name' => ['required', 'string', 'max:191'],
            'registration_number' => ['nullable', 'string', 'max:191'],
            'phone' => ['nullable', 'string', 'max:40'],
            'address' => ['nullable', 'string'],
            'meeting_day' => ['nullable', 'string', 'max:30'],
            'chairperson_client_id' => ['nullable', 'exists:clients,id'],
            'secretary_client_id' => ['nullable', 'exists:clients,id'],
            'treasurer_client_id' => ['nullable', 'exists:clients,id'],
            'member_ids' => ['array'],
            'member_ids.*' => ['integer', 'exists:clients,id'],
            'status' => ['required', 'in:active,inactive,suspended,closed'],
        ];
    }
}
