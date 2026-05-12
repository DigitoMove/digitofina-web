<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SavingsAccountResource extends JsonResource
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
            'account_number' => $this->account_number,
            'current_balance' => (float) $this->current_balance,
            'available_balance' => (float) $this->available_balance,
            'status' => $this->status,
            'opening_date' => $this->opening_date?->toDateString(),
            'client' => $this->whenLoaded('client', fn () => [
                'id' => $this->client->id,
                'client_number' => $this->client->client_number,
                'full_name' => $this->client->full_name,
            ]),
            'product' => $this->whenLoaded('product', fn () => [
                'id' => $this->product->id,
                'name' => $this->product->name,
                'code' => $this->product->code,
            ]),
            'branch' => $this->whenLoaded('branch', fn () => [
                'id' => $this->branch->id,
                'name' => $this->branch->name,
                'code' => $this->branch->code,
            ]),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
