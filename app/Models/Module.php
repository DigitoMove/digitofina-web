<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Module extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'is_core' => 'boolean',
        ];
    }

    public function organizationModules(): HasMany
    {
        return $this->hasMany(OrganizationModule::class);
    }

    public function organizations(): BelongsToMany
    {
        return $this->belongsToMany(Organization::class, 'organization_modules')
            ->withPivot(['is_enabled', 'settings', 'enabled_by', 'enabled_at', 'disabled_by', 'disabled_at'])
            ->withTimestamps();
    }

    public function dependencies(): BelongsToMany
    {
        return $this->belongsToMany(Module::class, 'module_dependencies', 'module_id', 'depends_on_module_id')->withTimestamps();
    }

    public function dependents(): BelongsToMany
    {
        return $this->belongsToMany(Module::class, 'module_dependencies', 'depends_on_module_id', 'module_id')->withTimestamps();
    }

    public function packages(): BelongsToMany
    {
        return $this->belongsToMany(Package::class, 'package_modules')->withTimestamps();
    }
}
