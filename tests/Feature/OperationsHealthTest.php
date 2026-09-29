<?php

use App\Jobs\RunBackupJob;
use App\Models\OperationalRun;
use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use App\Services\OperationsMonitor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

it('records successful and failed execution and notifies system administrators without exposing exception secrets', function () {
    $role = Role::create(['name' => 'Super Admin', 'slug' => 'super-admin']);
    $admin = User::factory()->create(['role_id' => $role->id, 'status' => 'active']);
    $monitor = app(OperationsMonitor::class);
    expect($monitor->run('penalties', fn (): array => ['checked' => 5]))->toBe(['checked' => 5]);
    expect(OperationalRun::first()->status)->toBe('succeeded');
    expect(fn () => $monitor->run('backup', fn (): array => throw new RuntimeException('secret-connection-string')))->toThrow(RuntimeException::class);
    expect(OperationalRun::latest('id')->first()->failure)->toBe('RuntimeException')
        ->and($admin->notifications()->count())->toBe(1)
        ->and(json_encode($admin->notifications()->first()->data))->not->toContain('secret-connection-string');
});

it('fails a missing backup provider instead of producing a misleading manifest', function () {
    Storage::fake('local');
    Artisan::shouldReceive('all')->once()->andReturn([]);
    expect(fn () => (new RunBackupJob)->handle())->toThrow(RuntimeException::class, 'manifest is not a backup');
    expect(OperationalRun::sole()->status)->toBe('failed')->and(Storage::disk('local')->allFiles())->toBe([]);
});

it('records backup success only on a zero command exit code', function (int $exitCode) {
    Artisan::shouldReceive('all')->once()->andReturn(['backup:run' => new stdClass]);
    Artisan::shouldReceive('call')->with('backup:run', ['--no-interaction' => true])->once()->andReturn($exitCode);
    if ($exitCode === 0) {
        (new RunBackupJob)->handle();
        expect(OperationalRun::sole()->status)->toBe('succeeded')->and(OperationalRun::sole()->summary['restore_verified'])->toBeFalse();
    } else {
        expect(fn () => (new RunBackupJob)->handle())->toThrow(RuntimeException::class, 'failure exit code');
        expect(OperationalRun::sole()->status)->toBe('failed');
    }
})->with([0, 1]);

it('distinguishes unverified overdue and stalled tasks and reuses the heartbeat record', function () {
    $this->travelTo(now()->setTime(12, 0));
    $monitor = app(OperationsMonitor::class);
    expect(collect($monitor->dailyStatuses())->pluck('status')->unique()->all())->toBe(['unverified']);
    $monitor->heartbeat();
    $this->travel(2)->minutes();
    $monitor->heartbeat();
    expect(OperationalRun::where('task', 'scheduler-heartbeat')->count())->toBe(1);
    OperationalRun::create(['task' => 'penalties', 'status' => 'succeeded', 'started_at' => now()->subDays(2), 'finished_at' => now()->subDays(2)]);
    OperationalRun::create(['task' => 'interest', 'status' => 'running', 'started_at' => now()->subHour()]);
    $statuses = collect($monitor->dailyStatuses())->keyBy('task');
    expect($statuses['penalties']['status'])->toBe('overdue')->and($statuses['interest']['status'])->toBe('stalled');
});

it('restricts operations access and hides global and other organization execution details', function () {
    $organization = Organization::create(['name' => 'Health Org', 'slug' => 'health-org', 'status' => 'active']);
    $other = Organization::create(['name' => 'Other Health Org', 'slug' => 'other-health-org', 'status' => 'active']);
    $role = Role::create(['name' => 'Organization Admin', 'slug' => 'organization-admin']);
    $admin = User::factory()->create(['role_id' => $role->id, 'organization_id' => $organization->id, 'status' => 'active', 'is_active' => true]);
    $monitor = app(OperationsMonitor::class);
    $monitor->run('own-organization-task', fn (): array => [], $organization->id);
    $monitor->run('other-private-task', fn (): array => [], $other->id);
    $monitor->run('global-private-task', fn (): array => []);
    $this->actingAs($admin)->get(route('operations-health.index'))->assertOk()->assertSee('Own Organization Task')->assertDontSee('Other Private Task')->assertDontSee('Global Private Task')->assertDontSee('Failed queue jobs:');
    $memberRole = Role::create(['name' => 'Member', 'slug' => 'member']);
    $admin->update(['role_id' => $memberRole->id]);
    $this->actingAs($admin->fresh())->get(route('operations-health.index'))->assertRedirect(route('dashboard'))->assertSessionHas('warning');
});

it('shows system health and explicit backup warnings to super administrators', function () {
    $role = Role::create(['name' => 'Super Admin', 'slug' => 'super-admin']);
    $admin = User::factory()->create(['role_id' => $role->id, 'status' => 'active', 'is_active' => true]);
    app(OperationsMonitor::class)->heartbeat();
    $this->actingAs($admin)->get(route('operations-health.index'))->assertOk()->assertSee('Recent heartbeat received')->assertSee('Backup command available')->assertSee('Run backup now')->assertSee('Not verified');
});

it('allows only a global super administrator to run a manual backup', function () {
    $role = Role::create(['name' => 'Super Admin', 'slug' => 'super-admin']);
    $admin = User::factory()->create(['role_id' => $role->id, 'status' => 'active', 'is_active' => true]);
    $job = Mockery::mock(RunBackupJob::class);
    $job->shouldReceive('handle')->once();
    $this->app->instance(RunBackupJob::class, $job);

    $this->actingAs($admin)->post(route('operations-health.backup'))
        ->assertRedirect()
        ->assertSessionHas('status', 'Database backup completed and was sent to the configured recipient emails.');

    $organization = Organization::create(['name' => 'Private Org', 'slug' => 'private-org', 'status' => 'active']);
    $organizationRole = Role::create(['name' => 'Organization Admin', 'slug' => 'organization-admin']);
    $organizationAdmin = User::factory()->create(['role_id' => $organizationRole->id, 'organization_id' => $organization->id, 'status' => 'active', 'is_active' => true]);
    $this->actingAs($organizationAdmin)->post(route('operations-health.backup'))
        ->assertRedirect(route('dashboard'))
        ->assertSessionHas('warning');
});

it('returns an unhealthy read-only command result when the scheduler has not reported', function () {
    $this->artisan('operations:check', ['--json' => true])->expectsOutputToContain('"healthy": false')->assertFailed();
    expect(OperationalRun::count())->toBe(0);
});
