<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Services\ModuleService;
use App\Support\ModuleCatalog;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['organization_id', 'branch_id', 'role_id', 'name', 'email', 'phone', 'position', 'is_active', 'status', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'two_factor_enabled' => 'boolean',
            'two_factor_confirmed_at' => 'datetime',
            'last_login_at' => 'datetime',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function roleFinancialLimit(): HasOne
    {
        return $this->hasOneThrough(RoleFinancialLimit::class, Role::class, 'id', 'role_id', 'role_id', 'id');
    }

    public function drawerSessions(): HasMany
    {
        return $this->hasMany(DrawerSession::class);
    }

    public function deviceTokens(): HasMany
    {
        return $this->hasMany(DeviceToken::class);
    }

    public function client(): HasOne
    {
        return $this->hasOne(Client::class);
    }

    public function hasRole(string $slug): bool
    {
        return $this->role?->slug === $slug;
    }

    public function isMember(): bool
    {
        return $this->hasRole('member');
    }

    public function isTellerCashier(): bool
    {
        return in_array($this->role?->slug, ['teller-cashier', 'cashier', 'teller'], true);
    }

    public function hasGlobalOrganizationAccess(): bool
    {
        return $this->hasRole('super-admin');
    }

    public function hasOrganizationBranchAccess(): bool
    {
        return $this->hasGlobalOrganizationAccess()
            || $this->hasRole('organization-admin')
            || $this->canPerform('manage-organization')
            || $this->canPerform('manage-branches');
    }

    public function canAccessOrganization(?int $organizationId): bool
    {
        return $organizationId === null
            || $this->hasGlobalOrganizationAccess()
            || $this->organization_id === null
            || (int) $this->organization_id === $organizationId;
    }

    public function canAccessBranch(?int $branchId, ?int $organizationId = null): bool
    {
        if (! $this->canAccessOrganization($organizationId)) {
            return false;
        }

        return $branchId === null
            || $this->hasOrganizationBranchAccess()
            || $this->branch_id === null
            || (int) $this->branch_id === $branchId;
    }

    public function canPerform(string $permission): bool
    {
        if ($this->hasRole('super-admin')) {
            return true;
        }

        $module = ModuleCatalog::moduleForPermission($permission);

        if ($module && ! $this->canAccessModule($module)) {
            return false;
        }

        $hasRolePermission = $this->role?->permissions()->where('slug', $permission)->exists() ?? false;

        if (! $hasRolePermission) {
            return false;
        }

        if (request()?->is('api/*') && $this->currentAccessToken()) {
            return $this->tokenCan($permission) || $this->tokenCan('*');
        }

        return true;
    }

    public function canAccessModule(string $slug): bool
    {
        if ($this->hasRole('super-admin') || ! $this->organization) {
            return true;
        }

        return app(ModuleService::class)->isEnabled($this->organization, $slug);
    }
}
