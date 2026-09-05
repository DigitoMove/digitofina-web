<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OperationalRun extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['started_at' => 'datetime', 'finished_at' => 'datetime', 'summary' => 'array'];
    }
}
