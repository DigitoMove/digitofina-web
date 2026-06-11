<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ClientDirectoryController extends Controller
{
    public function index(Request $request): View
    {
        abort_if($request->user()->isMember(), 403);

        $search = trim((string) $request->query('search', ''));
        $clients = $this->visibleClientQuery($request->user())
            ->with(['branch', 'organization', 'officer.role', 'officer.branch'])
            ->withCount(['accounts', 'loans'])
            ->when($search !== '', fn (Builder $query): Builder => $this->applySearch($query, $search))
            ->latest('registered_at')
            ->latest('id')
            ->paginate(15)
            ->withQueryString();

        return view('client-directory.index', [
            'clients' => $clients,
            'search' => $search,
        ]);
    }

    public function show(Request $request, int|string $client): View
    {
        abort_if($request->user()->isMember(), 403);

        $client = $this->visibleClientQuery($request->user())
            ->with([
                'branch',
                'organization',
                'officer.role',
                'officer.branch',
                'accounts.accountType',
                'savingsAccounts.product',
                'loans.product',
                'loans.officer',
                'loans.branch',
            ])
            ->whereKey($client)
            ->firstOrFail();

        return view('client-directory.show', [
            'client' => $client,
        ]);
    }

    /**
     * @return Builder<Client>
     */
    private function visibleClientQuery(User $actor): Builder
    {
        $organizationId = $actor->organization_id ?: $actor->branch?->organization_id;

        return Client::withoutGlobalScope('tenant')
            ->when(! $actor->hasRole('super-admin') && $organizationId, fn (Builder $query): Builder => $query->where('organization_id', $organizationId))
            ->when(! $actor->hasRole('super-admin') && ! $organizationId && $actor->branch_id, fn (Builder $query): Builder => $query->where('branch_id', $actor->branch_id))
            ->when(! $actor->hasRole('super-admin') && ! $organizationId && ! $actor->branch_id, fn (Builder $query): Builder => $query->whereRaw('1 = 0'));
    }

    /**
     * @param  Builder<Client>  $query
     * @return Builder<Client>
     */
    private function applySearch(Builder $query, string $search): Builder
    {
        return $query->where(function (Builder $query) use ($search): void {
            $query
                ->whereAny(['client_number', 'first_name', 'middle_name', 'last_name', 'business_name', 'phone', 'alternative_phone', 'national_id', 'email'], 'like', "%{$search}%")
                ->orWhereHas('branch', fn (Builder $branchQuery): Builder => $branchQuery->where('name', 'like', "%{$search}%"))
                ->orWhereHas('officer', fn (Builder $officerQuery): Builder => $officerQuery->where('name', 'like', "%{$search}%"))
                ->orWhereHas('accounts', fn (Builder $accountQuery): Builder => $accountQuery->where('account_number', 'like', "%{$search}%"));
        });
    }
}
