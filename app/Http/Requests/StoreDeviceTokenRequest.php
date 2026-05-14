<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDeviceTokenRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'device_id' => ['required', 'string', 'max:191'],
            'platform' => ['required', Rule::in(['android', 'ios', 'web'])],
            'provider' => ['nullable', Rule::in(['fcm', 'apns', 'webpush'])],
            'push_token' => ['required', 'string', 'max:4096'],
            'device_name' => ['nullable', 'string', 'max:191'],
            'device_model' => ['nullable', 'string', 'max:191'],
            'os_version' => ['nullable', 'string', 'max:191'],
            'app_version' => ['nullable', 'string', 'max:50'],
            'locale' => ['nullable', 'string', 'max:20'],
            'timezone' => ['nullable', 'string', 'max:80'],
            'capabilities' => ['nullable', 'array'],
            'capabilities.*' => ['string', 'max:80'],
        ];
    }
}
