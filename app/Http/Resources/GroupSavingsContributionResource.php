<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GroupSavingsContributionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'amount' => (float) $this->amount,
            'balance_before' => (float) $this->balance_before,
            'balance_after' => (float) $this->balance_after,
            'contribution_date' => $this->contribution_date?->toDateString(),
            'payment_method' => $this->payment_method,
            'status' => $this->status,
            'client' => $this->whenLoaded('client', fn () => [
                'id' => $this->client->id,
                'client_number' => $this->client->client_number,
                'full_name' => $this->client->full_name,
            ]),
        ];
    }
}
