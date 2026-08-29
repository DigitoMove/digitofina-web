<?php

namespace App\Models;

use Database\Factories\OrganizationHostingFeeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrganizationHostingFee extends Model
{
    /** @use HasFactory<OrganizationHostingFeeFactory> */
    use HasFactory;

    protected $fillable = [
        'organization_id', 'commercial_quote_id', 'period_starts_on', 'period_ends_on', 'due_on',
        'amount', 'currency', 'status', 'provider', 'provider_reference', 'recorded_by',
        'paid_at', 'waived_at', 'notes',
    ];

    protected $attributes = [
        'amount' => 200000,
        'currency' => 'UGX',
        'status' => 'due',
    ];

    protected function casts(): array
    {
        return [
            'period_starts_on' => 'date',
            'period_ends_on' => 'date',
            'due_on' => 'date',
            'amount' => 'decimal:2',
            'paid_at' => 'datetime',
            'waived_at' => 'datetime',
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

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
