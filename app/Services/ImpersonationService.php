<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Organization;
use App\Models\User;
use App\Support\RoleCatalog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ImpersonationService
{
    public const IMPERSONATOR_SESSION_KEY = 'impersonator_user_id';

    private const PREVIOUS_ORGANIZATION_SESSION_KEY = 'impersonator_previous_organization_id';

    private const RETURN_CLIENT_SESSION_KEY = 'impersonator_return_client_id';

    public function __construct(
        private AuditLogger $auditLogger,
        private TenantContext $tenant,
    ) {}

    public function startUser(Request $request, User $target): void
    {
        $impersonator = $request->user();
        $this->authorizeStaffTarget($impersonator, $target);

        $this->start($request, $impersonator, $target);
    }

    public function switchUser(Request $request, User $target): void
    {
        $impersonator = $this->switchingAdministrator($request);
        $this->authorizeStaffTarget($impersonator, $target);
        $this->assertActiveTarget($target);

        $this->stop($request);
        $request->setUserResolver(fn (): User => Auth::user());
        $this->startUser($request, $target);
    }

    public function switchingAdministrator(Request $request): User
    {
        abort_unless($this->isActive($request), 403, 'No impersonated user session is active.');

        $impersonator = User::query()->with('role')->findOrFail($request->session()->get(self::IMPERSONATOR_SESSION_KEY));
        abort_unless($impersonator->hasRole(RoleCatalog::SUPER_ADMIN_SLUG), 403, 'Only a Super Admin can switch impersonated users.');
        abort_unless($impersonator->is_active && $impersonator->status === 'active', 403, 'The administrator account is no longer active.');

        return $impersonator;
    }

    public function startClient(Request $request, Client $client): void
    {
        $impersonator = $request->user();
        $client->loadMissing(['organization', 'user.role']);
        $member = $client->user;

        abort_unless($impersonator->hasAnyRole([RoleCatalog::SUPER_ADMIN_SLUG, 'organization-admin']), 403);
        abort_if(
            $impersonator->hasRole('organization-admin')
                && (int) $impersonator->organization_id !== (int) $client->organization_id,
            403,
        );
        abort_if(
            $impersonator->hasRole(RoleCatalog::SUPER_ADMIN_SLUG)
                && $this->tenant->isScoped()
                && (int) $this->tenant->id() !== (int) $client->organization_id,
            403,
            'Select the client organization before starting the support session.',
        );
        abort_unless($client->status === 'active', 422, 'Only an active client can be viewed in the client portal.');
        abort_unless($member?->isMember(), 422, 'Issue client portal access before starting a support session.');
        abort_unless((int) $member->organization_id === (int) $client->organization_id, 422, 'The client portal account is not linked to the correct organization.');

        $this->start($request, $impersonator, $member, $client);
    }

    /**
     * @return array{impersonator: User, return_client_id: int|null}
     */
    public function stop(Request $request): array
    {
        $impersonatorId = $request->session()->get(self::IMPERSONATOR_SESSION_KEY);
        abort_unless($impersonatorId, 403, 'No impersonated user session is active.');

        $impersonator = User::query()->with('role')->findOrFail($impersonatorId);
        abort_unless($impersonator->hasAnyRole([RoleCatalog::SUPER_ADMIN_SLUG, 'organization-admin']), 403);
        abort_unless($impersonator->is_active && $impersonator->status === 'active', 403, 'The administrator account is no longer active.');

        $impersonatedUser = $request->user();
        $previousOrganizationId = $request->session()->get(self::PREVIOUS_ORGANIZATION_SESSION_KEY);
        $returnClientId = $request->session()->get(self::RETURN_CLIENT_SESSION_KEY);

        $this->auditLogger->record('auth.impersonation.stopped', $impersonatedUser, [
            'impersonator_user_id' => $impersonator->id,
            'impersonated_user_id' => $impersonatedUser->id,
            'return_client_id' => $returnClientId,
        ], actor: $impersonator);

        Auth::login($impersonator);
        $request->session()->regenerate();
        $request->session()->forget([
            self::IMPERSONATOR_SESSION_KEY,
            self::PREVIOUS_ORGANIZATION_SESSION_KEY,
            self::RETURN_CLIENT_SESSION_KEY,
            'super_admin_organization_id',
        ]);

        if ($impersonator->hasRole(RoleCatalog::SUPER_ADMIN_SLUG)
            && $previousOrganizationId
            && Organization::query()->whereKey($previousOrganizationId)->where('status', 'active')->exists()) {
            $request->session()->put('super_admin_organization_id', $previousOrganizationId);
        }

        $restoredOrganizationId = $request->session()->get('super_admin_organization_id');
        $this->tenant->set(
            $restoredOrganizationId ? Organization::query()->find($restoredOrganizationId) : $impersonator->organization,
            (bool) $restoredOrganizationId,
        );

        return [
            'impersonator' => $impersonator,
            'return_client_id' => $returnClientId ? (int) $returnClientId : null,
        ];
    }

    public function isActive(Request $request): bool
    {
        return $request->hasSession() && $request->session()->has(self::IMPERSONATOR_SESSION_KEY);
    }

    private function start(Request $request, User $impersonator, User $target, ?Client $client = null): void
    {
        abort_if($this->isActive($request), 409, 'Stop the current support session before acting as another user.');
        $this->assertActiveTarget($target);

        $previousOrganizationId = $request->session()->get('super_admin_organization_id');
        $this->auditLogger->record('auth.impersonation.started', $client ?: $target, [
            'impersonator_user_id' => $impersonator->id,
            'impersonated_user_id' => $target->id,
            'client_id' => $client?->id,
            'support_session' => $client !== null,
        ], actor: $impersonator);

        Auth::login($target);
        $request->session()->regenerate();
        $request->session()->put(self::IMPERSONATOR_SESSION_KEY, $impersonator->id);
        $request->session()->put(self::PREVIOUS_ORGANIZATION_SESSION_KEY, $previousOrganizationId);
        $request->session()->forget('super_admin_organization_id');
        $this->tenant->set($target->organization);

        if ($client) {
            $request->session()->put(self::RETURN_CLIENT_SESSION_KEY, $client->id);
        }
    }

    private function authorizeStaffTarget(User $impersonator, User $target): void
    {
        abort_unless($impersonator->hasAnyRole([RoleCatalog::SUPER_ADMIN_SLUG, 'organization-admin']), 403);
        abort_unless($impersonator->hasGlobalOrganizationAccess() || (int) $impersonator->organization_id === (int) $target->organization_id, 403);
        abort_if($target->hasRole(RoleCatalog::SUPER_ADMIN_SLUG), 422, 'You cannot act as another Super Admin.');
        abort_if($target->isMember(), 422, 'Open the client profile to start a client support session.');
    }

    private function assertActiveTarget(User $target): void
    {
        abort_unless($target->is_active && $target->status === 'active', 422, 'You can only act as an active user.');
        abort_unless($target->organization && $target->organization->status === 'active', 422, 'The selected user must belong to an active organization.');
        abort_if($target->organization->isDemoExpired(), 422, 'The selected user belongs to an expired demo organization.');
    }
}
