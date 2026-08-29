<?php

namespace App\Models;

use Database\Factories\CommercialQuoteFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class CommercialQuote extends Model
{
    /** @use HasFactory<CommercialQuoteFactory> */
    use HasFactory;

    protected $fillable = [
        'organization_id',
        'requested_by',
        'issued_by',
        'quote_number',
        'billing_type',
        'subscription_months',
        'amount',
        'currency',
        'requested_scope',
        'terms',
        'status',
        'valid_until',
        'issued_at',
        'payment_initiated_at',
        'paid_at',
        'nylon_reference',
        'nylon_invoice_id',
        'nylon_checkout_url',
        'nylon_payment_status',
        'payment_metadata',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'subscription_months' => 'integer',
            'valid_until' => 'date',
            'issued_at' => 'datetime',
            'payment_initiated_at' => 'datetime',
            'paid_at' => 'datetime',
            'payment_metadata' => 'encrypted:array',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function issuer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    public function subscription(): HasOne
    {
        return $this->hasOne(OrganizationSubscription::class);
    }

    public function hostingFee(): HasOne
    {
        return $this->hasOne(OrganizationHostingFee::class);
    }

    public function isPayable(): bool
    {
        return in_array($this->status, ['issued', 'payment_pending'], true)
            && $this->amount !== null
            && ($this->valid_until === null || $this->valid_until->isToday() || $this->valid_until->isFuture());
    }
}
