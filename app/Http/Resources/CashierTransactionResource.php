<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CashierTransactionResource extends JsonResource
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
            'reference' => $this->reference,
            'amount' => $this->amount,
            'charges' => $this->charges,
            'balance_after' => $this->balance_after,
            'status' => $this->status,
            'description' => $this->description,
            'cheque_number' => $this->cheque_number,
            'posted_at' => $this->posted_at?->toIso8601String(),
            'type' => $this->type?->name,
            'account' => $this->account?->only(['account_number', 'account_name']),
            'cashier' => $this->user?->name,
            'movements' => DrawerMovementResource::collection($this->whenLoaded('drawerMovements')),
        ];
    }
}
