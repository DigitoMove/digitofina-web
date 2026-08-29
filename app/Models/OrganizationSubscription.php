<?php

namespace App\Models;

use Database\Factories\OrganizationSubscriptionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrganizationSubscription extends Model
{
    /** @use HasFactory<OrganizationSubscriptionFactory> */
    use HasFactory;

    protected $fillable = [
        'organization_id',
        'commercial_quote_id',
        'billing_type',
        'status',
        'activation_source',
        'payment_status',
        'amount',
        'currency',
        'provider',
        'provider_reference',
        'assigned_by',
        'notes',
        'starts_at',
        'current_period_ends_at',
        'activated_at',
        'cancelled_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'starts_at' => 'datetime',
            'current_period_ends_at' => 'datetime',
            'activated_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function quote(): BelongsTo
    {
        return $this->belongsTo(CommercialQuote::class, 'commercial_quote_id');
    }

    public function assigner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }
}
