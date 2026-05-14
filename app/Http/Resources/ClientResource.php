<?php

namespace App\Http\Resources;

use App\Support\ApiQuery;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ClientResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $data = [
            'id' => $this->id,
            'client_number' => $this->client_number,
            'client_type' => $this->client_type,
            'full_name' => $this->full_name,
            'first_name' => $this->first_name,
            'middle_name' => $this->middle_name,
            'last_name' => $this->last_name,
            'phone' => $this->phone,
            'email' => $this->email,
            'national_id' => $this->national_id,
            'kyc_status' => $this->kyc_status,
            'risk_rating' => $this->risk_rating,
            'status' => $this->status,
            'branch' => $this->whenLoaded('branch', fn () => [
                'id' => $this->branch->id,
                'name' => $this->branch->name,
                'code' => $this->branch->code,
            ]),
            'officer' => $this->whenLoaded('officer', fn () => [
                'id' => $this->officer?->id,
                'name' => $this->officer?->name,
            ]),
            'registered_at' => $this->registered_at?->toDateString(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];

        if (! ApiQuery::wantsDetailedResource($request)) {
            return $data;
        }

        return $data + [
            'gender' => $this->gender,
            'date_of_birth' => $this->date_of_birth?->toDateString(),
            'nationality' => $this->nationality,
            'alternative_phone' => $this->alternative_phone,
            'occupation' => $this->occupation,
            'employer_name' => $this->employer_name,
            'marital_status' => $this->marital_status,
            'next_of_kin_name' => $this->next_of_kin_name,
            'next_of_kin_phone' => $this->next_of_kin_phone,
            'address' => $this->address,
            'village' => $this->village,
            'parish' => $this->parish,
            'sub_county' => $this->sub_county,
            'district' => $this->district,
            'region' => $this->region,
            'gps_latitude' => $this->gps_latitude !== null ? (float) $this->gps_latitude : null,
            'gps_longitude' => $this->gps_longitude !== null ? (float) $this->gps_longitude : null,
            'employment_status' => $this->employment_status,
            'business_name' => $this->business_name,
            'business_details' => $this->business_details,
            'kyc_documents' => $this->kyc_documents,
            'closed_at' => $this->closed_at?->toIso8601String(),
        ];
    }
}
