<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SyncClientsRequest extends FormRequest
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
            'client_batch_id' => ['nullable', 'string', 'max:120'],
            'device_name' => ['nullable', 'string', 'max:191'],
            'records' => ['required', 'array', 'min:1', 'max:100'],
            'records.*.action' => ['required', Rule::in(['create', 'update'])],
            'records.*.local_temp_id' => ['required', 'string', 'max:120'],
            'records.*.server_id' => ['nullable', 'integer'],
            'records.*.server_updated_at' => ['nullable', 'date'],
            'records.*.client_updated_at' => ['required', 'date'],
            'records.*.payload' => ['required', 'array'],
            'records.*.payload.branch_id' => ['required', 'integer', 'exists:branches,id'],
            'records.*.payload.client_type' => ['required', 'in:individual,group,business,institution'],
            'records.*.payload.first_name' => ['required_unless:records.*.payload.client_type,group,business,institution', 'nullable', 'string', 'max:191'],
            'records.*.payload.middle_name' => ['nullable', 'string', 'max:191'],
            'records.*.payload.last_name' => ['required_unless:records.*.payload.client_type,group,business,institution', 'nullable', 'string', 'max:191'],
            'records.*.payload.phone' => ['required', 'string', 'max:40'],
            'records.*.payload.email' => ['nullable', 'email', 'max:191'],
            'records.*.payload.national_id' => ['nullable', 'string', 'max:80'],
            'records.*.payload.gender' => ['nullable', 'in:male,female,other'],
            'records.*.payload.date_of_birth' => ['nullable', 'date', 'before:today'],
            'records.*.payload.address' => ['nullable', 'string'],
            'records.*.payload.village' => ['nullable', 'string', 'max:191'],
            'records.*.payload.parish' => ['nullable', 'string', 'max:191'],
            'records.*.payload.sub_county' => ['nullable', 'string', 'max:191'],
            'records.*.payload.district' => ['nullable', 'string', 'max:191'],
            'records.*.payload.region' => ['nullable', 'string', 'max:191'],
            'records.*.payload.gps_latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'records.*.payload.gps_longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'records.*.payload.kyc_status' => ['nullable', 'in:pending,verified,rejected'],
            'records.*.payload.risk_rating' => ['nullable', 'in:low,medium,high'],
            'records.*.payload.status' => ['nullable', 'in:pending,active,dormant,suspended,blacklisted,closed'],
        ];
    }
}
