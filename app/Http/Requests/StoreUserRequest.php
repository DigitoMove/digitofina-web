<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canPerform('manage-users') ?? false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'branch_id' => ['required', 'exists:branches,id'],
            'role_id' => ['required', 'exists:roles,id'],
            'name' => ['required', 'string', 'max:191'],
            'email' => ['required', 'email', 'max:191', 'unique:users,email'],
            'phone' => ['required', 'string', 'max:40', 'unique:users,phone', 'unique:clients,phone'],
            'position' => ['nullable', 'string', 'max:191'],
            'status' => ['required', 'in:active,suspended,disabled'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ];
    }
}
