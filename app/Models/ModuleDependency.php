<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ModuleDependency extends Model
{
    protected $guarded = [];

    public function module(): BelongsTo
    {
        return $this->belongsTo(Module::class);
    }

    public function dependency(): BelongsTo
    {
        return $this->belongsTo(Module::class, 'depends_on_module_id');
    }
}
