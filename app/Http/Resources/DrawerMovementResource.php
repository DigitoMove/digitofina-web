<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DrawerMovementResource extends JsonResource
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
            'type' => $this->type,
            'amount' => (float) $this->amount,
            'balance_after' => (float) $this->balance_after,
            'reference' => $this->reference,
            'description' => $this->description,
            'drawer' => $this->whenLoaded('session', fn () => $this->session?->drawer ? [
                'id' => $this->session->drawer->id,
                'name' => $this->session->drawer->name,
                'code' => $this->session->drawer->code,
            ] : null),
            'teller' => $this->whenLoaded('session', fn () => $this->session?->user ? [
                'id' => $this->session->user->id,
                'name' => $this->session->user->name,
            ] : null),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
