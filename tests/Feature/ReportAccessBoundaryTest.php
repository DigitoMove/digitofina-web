<?php

use App\Http\Middleware\ResolveTenant;
use App\Http\Middleware\TrackSiteTraffic;
use App\Jobs\GenerateReportExport;
use App\Models\Branch;
use App\Models\MakerCheckerRequest;
use App\Models\Module;
use App\Models\Organization;
use App\Models\OrganizationModule;
use App\Models\Permission;
use App\Models\ReportExport;
use App\Models\Role;
use App\Models\Transaction;
use App\Models\TransactionType;
use App\Models\User;
use App\Services\FinancialControlService;
use App\Services\ModuleService;
use App\Services\ReportCatalogService;
use App\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutMiddleware(TrackSiteTraffic::class);
    $this->organization = Organization::create(['name' => 'Own organization', 'slug' => 'own', 'status' => 'active']);
    $this->foreignOrganization = Organization::create(['name' => 'Foreign organization', 'slug' => 'foreign', 'status' => 'active']);
    foreach ([$this->organization, $this->foreignOrganization] as $organization) {
        app(ModuleService::class)->syncCatalog();
        app(ModuleService::class)->syncDefaults($organization);
        foreach (Module::all() as $module) {
            OrganizationModule::updateOrCreate(['organization_id' => $organization->id, 'module_id' => $module->id], ['is_enabled' => true, 'enabled_at' => now()]);
        }
        app(ModuleService::class)->clearCache($organization);
    }
    $this->branch = Branch::create(['organization_id' => $this->organization->id, 'name' => 'Own branch', 'code' => 'OWN']);
    $this->siblingBranch = Branch::create(['organization_id' => $this->organization->id, 'name' => 'Sibling branch', 'code' => 'SIBLING']);
    $this->foreignBranch = Branch::create(['organization_id' => $this->foreignOrganization->id, 'name' => 'Foreign branch', 'code' => 'FOREIGN']);
    $this->admin = boundaryUser($this->organization, null, 'organization-admin');
    $this->staff = boundaryUser($this->organization, $this->branch, 'branch-manager');
    $this->superAdmin = boundaryUser(null, null, 'super-admin');
    $type = TransactionType::create(['name' => 'Deposit', 'code' => 'BOUNDARY', 'direction' => 'credit']);
    foreach (['own' => $this->branch, 'sibling' => $this->siblingBranch, 'foreign' => $this->foreignBranch] as $key => $branch) {
        $transaction = Transaction::create([
            'organization_id' => $branch->organization_id, 'branch_id' => $branch->id,
            'transaction_type_id' => $type->id, 'user_id' => $this->admin->id,
            'reference' => 'BOUNDARY-'.strtoupper($key), 'amount' => 100, 'posted_at' => now(),
        ]);
        $this->{$key.'Request'} = MakerCheckerRequest::create([
            'organization_id' => $branch->organization_id, 'branch_id' => $branch->id,
            'subject_type' => Transaction::class, 'subject_id' => $transaction->id,
            'requested_by' => $this->admin->id, 'action' => 'transaction_reversal',
            'required_permission' => 'approve-reversals', 'reason' => 'Reversal '.$key, 'status' => 'pending',
        ]);
    }
});

it('isolates reversal rows and pagination across statuses and searches', function (string $status, string $search) {
    MakerCheckerRequest::query()->update(['status' => $status]);
    $this->actingAs($this->admin)->get(route('transaction-reversal-requests.index', compact('status', 'search')))
        ->assertSuccessful()
        ->assertViewHas('requests', fn ($requests): bool => $requests->total() === 2
            && $requests->pluck('id')->sort()->values()->all() === [$this->ownRequest->id, $this->siblingRequest->id])
        ->assertDontSee('Reversal foreign');
})->with(['pending', 'approved', 'rejected'])->with(['', 'Reversal']);

it('limits branch staff even with audit and override permissions', function () {
    $this->actingAs($this->staff)->get(route('transaction-reversal-requests.index'))
        ->assertSuccessful()
        ->assertViewHas('requests', fn ($requests): bool => $requests->pluck('id')->all() === [$this->ownRequest->id]);
});

it('prevents direct foreign reversal approvals and rejections', function (string $action) {
    $this->actingAs($this->admin)->post(route('transactions.reversal-requests.'.$action, $this->foreignRequest), ['comments' => 'Not authorized'])
        ->assertNotFound();
    expect(MakerCheckerRequest::withoutGlobalScopes()->findOrFail($this->foreignRequest->id)->status)->toBe('pending');
})->with(['approve', 'reject']);

it('prevents service callers from checking another organization or branch', function (string $action) {
    $this->actingAs($this->staff);
    $controls = app(FinancialControlService::class);
    foreach ([$this->siblingRequest, $this->foreignRequest] as $request) {
        expect(fn () => $controls->{$action}($request, $this->staff, 'Not authorized'))
            ->toThrow(HttpException::class);
        expect(MakerCheckerRequest::withoutGlobalScopes()->findOrFail($request->id)->status)->toBe('pending');
    }
})->with(['approveMakerChecker', 'rejectMakerChecker']);

it('preserves global super admin access and selected organization isolation', function () {
    $this->actingAs($this->superAdmin)->get(route('transaction-reversal-requests.index'))
        ->assertSuccessful()->assertViewHas('requests', fn ($requests): bool => $requests->total() === 3);
    $this->withSession(['super_admin_organization_id' => $this->organization->id])
        ->get(route('transaction-reversal-requests.index'))
        ->assertSuccessful()->assertViewHas('requests', fn ($requests): bool => $requests->total() === 2);
});

it('uses the impersonated user organization and branch on the web', function (string $target) {
    $user = $this->{$target};
    $this->actingAs($this->superAdmin)->withSession(['super_admin_organization_id' => $this->foreignOrganization->id])
        ->post(route('users.impersonate', $user))->assertRedirect(route('dashboard'));
    $this->get(route('transaction-reversal-requests.index'))->assertSuccessful()
        ->assertViewHas('requests', fn ($requests): bool => $requests->total() === ($target === 'staff' ? 1 : 2))
        ->assertDontSee('Reversal foreign');
})->with(['admin', 'staff']);

it('uses the impersonated user organization and branch for API reports', function () {
    Sanctum::actingAs($this->superAdmin, ['*']);
    $this->withHeader('X-Impersonate-User', (string) $this->staff->id)
        ->getJson('/api/v1/reports/branch-performance')->assertSuccessful()
        ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $this->branch->id);
});

it('rejects branch filter overrides at the report service boundary', function () {
    $this->actingAs($this->staff);
    foreach ([$this->siblingBranch, $this->foreignBranch] as $branch) {
        expect(fn () => app(ReportCatalogService::class)->generate('loan-disbursements', ['branch_id' => $branch->id], $this->staff))
            ->toThrow(HttpException::class);
    }
});

it('limits report filter options to the users branch', function () {
    $this->actingAs($this->staff)->get(route('reports.index'))->assertSuccessful()
        ->assertViewHas('branches', fn ($branches): bool => $branches->pluck('id')->all() === [$this->branch->id])
        ->assertViewHas('staffUsers', fn ($users): bool => $users->pluck('id')->all() === [$this->staff->id]);
});

it('keeps queued branch performance exports scoped without a logged in user', function () {
    Storage::fake('local');
    $export = ReportExport::create([
        'organization_id' => $this->organization->id, 'branch_id' => $this->branch->id,
        'requested_by' => $this->staff->id, 'report_type' => 'branch_performance',
        'format' => 'csv', 'status' => 'queued',
    ]);
    Auth::forgetGuards();
    app(TenantContext::class)->clear();
    (new GenerateReportExport($export))->handle();
    expect(Storage::disk('local')->get($export->fresh()->path))->toContain('Own branch')
        ->not->toContain('Sibling branch')->not->toContain('Foreign branch');
});

function boundaryUser(?Organization $organization, ?Branch $branch, string $roleSlug): User
{
    $role = Role::firstOrCreate(['slug' => $roleSlug], ['name' => $roleSlug]);
    foreach (['view-reports', 'export-reports', 'approve-reversals', 'override-maker-checker', 'audit-system'] as $slug) {
        $permission = Permission::firstOrCreate(['slug' => $slug], ['name' => $slug]);
        $role->permissions()->syncWithoutDetaching([$permission->id]);
    }

    return User::factory()->create([
        'organization_id' => $organization?->id, 'branch_id' => $branch?->id,
        'role_id' => $role->id, 'status' => 'active', 'is_active' => true,
    ]);
}

it('keeps transaction rows out of every catalog report across tenant and branch boundaries', function (string $target) {
    $user = $this->{$target};
    $this->actingAs($user);
    $reports = app(ReportCatalogService::class);
    foreach ($reports->definitions($user) as $definition) {
        $report = $reports->generate($definition['slug'], [], $user);
        $rows = json_encode($report['rows']->all(), JSON_THROW_ON_ERROR);
        expect($rows, $definition['slug'])->not->toContain('BOUNDARY-FOREIGN')->not->toContain('Foreign branch');
        if ($target === 'staff') {
            expect($rows, $definition['slug'])->not->toContain('BOUNDARY-SIBLING')->not->toContain('Sibling branch');
        }
    }
})->with(['admin', 'staff']);

it('scopes every API report to the same data when a super admin acts as branch staff', function () {
    $paths = ['dashboard', 'clients', 'savings', 'loans', 'arrears', 'tellers', 'branch-performance', 'investments', 'savings-analytics'];
    $baseline = [];
    Sanctum::actingAs($this->staff, ['*']);
    foreach ($paths as $path) {
        $baseline[$path] = $this->getJson('/api/v1/reports/'.$path)->assertSuccessful()->json('data');
    }
    Sanctum::actingAs($this->superAdmin, ['*']);
    $this->withHeader('X-Impersonate-User', (string) $this->staff->id);
    foreach ($paths as $path) {
        Sanctum::actingAs($this->superAdmin, ['*']);
        $response = $this->getJson('/api/v1/reports/'.$path)->assertSuccessful();
        expect($response->json('data'), $path)->toBe($baseline[$path]);
    }
});

it('does not turn a super admin organization fallback into global API report access', function () {
    $this->superAdmin->update(['organization_id' => $this->organization->id]);
    Sanctum::actingAs($this->superAdmin, ['*']);
    $this->getJson('/api/v1/reports/branch-performance')->assertSuccessful()->assertJsonCount(2, 'data');
});

it('rejects foreign organization headers while impersonating', function () {
    Sanctum::actingAs($this->superAdmin, ['*']);
    $this->withHeaders([
        'X-Impersonate-User' => (string) $this->staff->id,
        'X-Organization-Id' => (string) $this->foreignOrganization->id,
    ])->getJson('/api/v1/reports/dashboard')->assertForbidden();
});

it('scopes super admin model queries on an organization host', function () {
    config(['tenancy.root_domain' => 'tenant.test', 'tenancy.central_domains' => ['central.test']]);
    $this->actingAs($this->superAdmin);
    $request = Request::create('https://own.tenant.test/reports');
    $request->setUserResolver(fn (): User => $this->superAdmin);
    app(ResolveTenant::class)->handle($request, function (): Response {
        expect(app(TenantContext::class)->id())->toBe($this->organization->id)
            ->and(app(TenantContext::class)->isScoped())->toBeTrue()
            ->and(MakerCheckerRequest::count())->toBe(2);

        return response('ok');
    });
});
