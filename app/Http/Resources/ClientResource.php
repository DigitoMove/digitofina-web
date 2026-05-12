<?php

namespace App\Http\Resources;

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
        return [
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
        ];
    }
}
