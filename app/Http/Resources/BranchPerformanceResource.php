<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BranchPerformanceResource extends JsonResource
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
            'name' => $this->name,
            'code' => $this->code,
            'status' => $this->status,
            'clients_count' => (int) ($this->clients_count ?? 0),
            'savings_balance' => (float) ($this->savings_accounts_sum_current_balance ?? 0),
        ];
    }
}
