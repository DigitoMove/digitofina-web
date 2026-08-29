<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateNylonPayCredentialRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->hasRole('super-admin') ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'api_key' => ['nullable', 'string', 'starts_with:npk_', 'max:1000'],
            'api_secret' => ['nullable', 'string', 'starts_with:nps_', 'max:1000'],
            'webhook_secret' => ['nullable', 'string', 'min:16', 'max:1000'],
            'base_url' => ['required', 'url:https', 'starts_with:https://api.nylonpay.nilesquad.com/', 'max:500'],
        ];
    }
}
