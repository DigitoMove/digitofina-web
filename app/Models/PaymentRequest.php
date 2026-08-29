<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class PaymentRequest extends Model
{
    protected $fillable = [
        'organization_id',
        'payment_provider_id',
        'payable_type',
        'payable_id',
        'reference',
        'external_reference',
        'checkout_url',
        'payer_phone',
        'amount',
        'currency',
        'status',
        'metadata',
        'expires_at',
        'posted_at',
        'posted_transaction_type',
        'posted_transaction_id',
        'posting_error',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'metadata' => 'array',
            'expires_at' => 'datetime',
            'posted_at' => 'datetime',
        ];
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(PaymentProvider::class, 'payment_provider_id');
    }

    public function payable(): MorphTo
    {
        return $this->morphTo();
    }

    public function postedTransaction(): MorphTo
    {
        return $this->morphTo();
    }
}
