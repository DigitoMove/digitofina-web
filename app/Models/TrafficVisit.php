<?php

namespace App\Models;

use Database\Factories\TrafficVisitFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TrafficVisit extends Model
{
    /** @use HasFactory<TrafficVisitFactory> */
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['is_demo' => 'boolean', 'started_at' => 'datetime', 'last_seen_at' => 'datetime'];
    }
}
