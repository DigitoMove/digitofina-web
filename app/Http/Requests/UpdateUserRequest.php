<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canPerform('manage-users') ?? false;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'status' => $this->input('status', $this->route('user')?->status ?? 'active'),
        ]);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $user = $this->route('user');

        return [
            'branch_id' => ['required', 'exists:branches,id'],
            'role_id' => ['required', 'exists:roles,id'],
            'name' => ['required', 'string', 'max:191'],
            'email' => ['required', 'email', 'max:191', Rule::unique('users', 'email')->ignore($user)],
            'phone' => ['required', 'string', 'max:40', Rule::unique('users', 'phone')->ignore($user)],
            'position' => ['nullable', 'string', 'max:191'],
            'status' => ['nullable', 'in:active,suspended,disabled'],
            'password' => ['nullable', 'confirmed', Password::defaults()],
        ];
    }
}
