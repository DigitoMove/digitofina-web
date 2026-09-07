<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminRecordResource extends JsonResource
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
            'title' => $this->name ?? $this->full_name ?? $this->account_number ?? $this->loan_number ?? $this->quote_number ?? $this->investment_number ?? $this->reference ?? $this->device_name ?? 'Record',
            'status' => $this->status,
            'fields' => array_filter($this->resource->only([
                'name', 'email', 'phone', 'status', 'slug', 'code', 'currency',
                'address', 'description', 'billing_type', 'price', 'client_number',
                'account_number', 'balance', 'loan_number', 'principal_balance',
                'quote_number', 'amount', 'position', 'last_login_at',
                'device_name', 'platform', 'ip_address', 'last_route',
                'first_seen_at', 'last_seen_at', 'created_at',
                'reference', 'charges', 'balance_after', 'posted_at',
                'investment_number', 'principal_amount', 'current_balance', 'maturity_date',
            ]), fn (mixed $value): bool => $value !== null && $value !== ''),
            'organization' => $this->whenLoaded('organization', fn (): ?array => $this->organization?->only(['id', 'name'])),
            'role' => $this->whenLoaded('role', fn (): ?array => $this->role?->only(['id', 'name', 'slug'])),
            'branch' => $this->whenLoaded('branch', fn (): ?array => $this->branch?->only(['id', 'name'])),
            'user' => $this->whenLoaded('user', fn (): ?array => $this->user?->only(['id', 'name'])),
            'package' => $this->whenLoaded('package', fn (): ?array => $this->package?->only(['id', 'name'])),
            'permissions' => $this->whenLoaded('permissions', fn () => $this->permissions->pluck('name')->values()),
        ];
    }
}
