<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Organization;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\DataWipeService;
use App\Services\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use InvalidArgumentException;

class DataWipeController extends Controller
{
    public function index(Request $request, DataWipeService $wipes, TenantContext $tenant): View
    {
        abort_unless($this->canWipeData($request), 403);

        return view('data-wipe.index', $this->viewData($request, $wipes, $tenant));
    }

    public function store(Request $request, DataWipeService $wipes, TenantContext $tenant, AuditLogger $auditLogger): View|RedirectResponse
    {
        abort_unless($this->canWipeData($request), 403);

        $categoryKeys = array_keys($wipes->categories());
        $data = $request->validate([
            'action' => ['required', Rule::in(['preview', 'wipe'])],
            'scope_type' => ['required', Rule::in(['organization', 'branch', 'user'])],
            'organization_id' => ['nullable', 'integer'],
            'branch_id' => ['nullable', 'integer'],
            'user_id' => ['nullable', 'integer'],
            'categories' => ['required', 'array', 'min:1'],
            'categories.*' => ['required', Rule::in($categoryKeys)],
            'confirmation' => ['nullable', 'string'],
        ]);
        $targetId = $this->targetId($data);
        $this->validateUserBranchTarget($data);

        try {
            $preview = $wipes->preview($data['scope_type'], $targetId, $data['categories'], $request->user());
        } catch (InvalidArgumentException $exception) {
            return back()->withErrors(['data_wipe' => $exception->getMessage()])->withInput();
        }

        if ($data['action'] === 'preview') {
            return view('data-wipe.index', $this->viewData($request, $wipes, $tenant, $preview));
        }

        if (($data['confirmation'] ?? '') !== 'WIPE') {
            return back()->withErrors(['confirmation' => 'Type WIPE to confirm this destructive reset.'])->withInput();
        }

        $result = $wipes->wipe($data['scope_type'], $targetId, $data['categories'], $request->user());
        $auditLogger->record('data_wipe.executed', null, [
            'scope' => $result['context'],
            'categories' => $result['categories'],
            'tables' => $result['tables'],
            'total' => $result['total'],
        ]);

        return redirect()->route('data-wipe.index')->with('status', 'Data wipe completed. '.$result['total'].' row'.($result['total'] === 1 ? '' : 's').' removed.');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function targetId(array $data): int
    {
        $field = match ($data['scope_type']) {
            'organization' => 'organization_id',
            'branch' => 'branch_id',
            'user' => 'user_id',
        };

        if (empty($data[$field])) {
            throw ValidationException::withMessages([
                $field => 'Select the target to wipe.',
            ]);
        }

        return (int) $data[$field];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function validateUserBranchTarget(array $data): void
    {
        if ($data['scope_type'] !== 'user') {
            return;
        }

        if (empty($data['branch_id'])) {
            throw ValidationException::withMessages([
                'branch_id' => 'Select the branch for the user target.',
            ]);
        }

        $targetUser = User::query()
            ->withoutGlobalScope('tenant')
            ->find((int) $data['user_id']);

        if (! $targetUser || (int) $targetUser->branch_id !== (int) $data['branch_id']) {
            throw ValidationException::withMessages([
                'user_id' => 'Select a user who belongs to the selected branch.',
            ]);
        }
    }

    private function canWipeData(Request $request): bool
    {
        return $request->user()->hasRole('super-admin')
            || $request->user()->hasRole('organization-admin');
    }

    /**
     * @param  array<string, mixed>|null  $preview
     * @return array<string, mixed>
     */
    private function viewData(Request $request, DataWipeService $wipes, TenantContext $tenant, ?array $preview = null): array
    {
        $user = $request->user();
        $organizationId = $tenant->id() ?: $user->organization_id;

        $organizations = $user->hasRole('super-admin')
            ? Organization::query()->orderBy('name')->get()
            : Organization::query()->whereKey($organizationId)->get();

        $branches = Branch::query()
            ->withoutGlobalScope('tenant')
            ->when($organizationId, fn (Builder $query, int $organizationId): Builder => $query->where('organization_id', $organizationId))
            ->orderBy('name')
            ->get();

        $users = User::query()
            ->with(['branch', 'role'])
            ->when($organizationId, fn (Builder $query, int $organizationId): Builder => $query->where('organization_id', $organizationId))
            ->whereKeyNot($user->id)
            ->orderBy('name')
            ->get();

        return [
            'categories' => $wipes->categories(),
            'defaultClearCategories' => $wipes->defaultClearCategories(),
            'organizations' => $organizations,
            'branches' => $branches,
            'users' => $users,
            'preview' => $preview,
        ];
    }
}
