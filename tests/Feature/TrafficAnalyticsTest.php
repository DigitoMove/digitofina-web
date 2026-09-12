<?php

use App\Models\Organization;
use App\Models\Role;
use App\Models\TrafficVisit;
use App\Models\User;
use App\Services\TrafficAnalyticsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['traffic.enabled' => true, 'tenancy.root_domain' => 'example.com']);
    Route::middleware(['web', 'tenant'])->get('/traffic-test', fn () => response('<html><head></head><body>Page</body></html>'))->name('traffic.test');
});

it('records anonymous html views, groups their host and restarts idle visits', function () {
    $this->withSession(['traffic_visitor' => 'browser-a']);
    $this->get('http://demo.example.com/traffic-test')->assertOk()->assertSee('df-traffic');
    $this->get('http://demo.example.com/traffic-test')->assertOk();
    expect(TrafficVisit::count())->toBe(1);
    $visit = TrafficVisit::firstOrFail();
    expect($visit->page_views)->toBe(2)->and($visit->domain)->toBe('example.com')->and($visit->subdomain)->toBe('demo');
    $this->travel(31)->minutes();
    $this->get('http://demo.example.com/traffic-test')->assertOk();
    expect(TrafficVisit::count())->toBe(2);
});

it('counts duration only for the owning browser and hostname without duplicate-tab inflation', function () {
    $this->withSession(['traffic_visitor' => 'browser-a']);
    $this->get('/traffic-test')->assertOk();
    $visit = TrafficVisit::firstOrFail();
    $this->travel(30)->seconds();
    $this->postJson('/traffic/heartbeat', ['visit_id' => $visit->id])->assertNoContent();
    $this->postJson('/traffic/heartbeat', ['visit_id' => $visit->id])->assertNoContent();
    expect($visit->fresh()->active_seconds)->toBe(30)->and($visit->fresh()->page_views)->toBe(1);
    $this->travel(30)->seconds();
    $this->withSession(['traffic_visitor' => 'browser-b'])->postJson('/traffic/heartbeat', ['visit_id' => $visit->id])->assertNoContent();
    expect($visit->fresh()->active_seconds)->toBe(30);
    $this->withSession(['traffic_visitor' => 'browser-a'])->postJson('http://other.example.com/traffic/heartbeat', ['visit_id' => $visit->id])->assertNoContent();
    expect($visit->fresh()->active_seconds)->toBe(30);
});

it('attributes demo tenants and excludes bots and disabled collection', function () {
    $demo = Organization::create(['name' => 'Demo tenant', 'slug' => 'demo', 'status' => 'active', 'is_demo' => true]);
    $this->get('http://demo.example.com/traffic-test')->assertOk();
    expect(TrafficVisit::first()->is_demo)->toBeTrue()->and(TrafficVisit::first()->organization_id)->toBe($demo->id);
    $this->withHeader('User-Agent', 'Googlebot')->get('/traffic-test')->assertOk();
    expect(TrafficVisit::count())->toBe(1);
    config(['traffic.enabled' => false]);
    $this->withHeader('User-Agent', 'Browser')->get('/traffic-test')->assertOk();
    expect(TrafficVisit::count())->toBe(1);
});

it('aggregates real visits with filters, zero days and organization boundaries', function () {
    $own = Organization::create(['name' => 'Own', 'slug' => 'own']);
    $other = Organization::create(['name' => 'Other', 'slug' => 'other']);
    TrafficVisit::factory()->create(['organization_id' => $own->id, 'is_demo' => true, 'active_seconds' => 60, 'page_views' => 4]);
    TrafficVisit::factory()->create(['organization_id' => $own->id, 'is_demo' => true, 'active_seconds' => 0]);
    TrafficVisit::factory()->create(['organization_id' => $other->id, 'active_seconds' => 900]);
    $service = app(TrafficAnalyticsService::class);
    $report = $service->dashboard(Request::create('/dashboard', 'GET', ['traffic_days' => 7, 'traffic_kind' => 'demo']), $own->id);
    expect((int) $report['totals']['visits'])->toBe(2)
        ->and((float) $report['totals']['duration'])->toBe(30.0)
        ->and((int) $report['totals']['views'])->toBe(5)
        ->and($report['trend'])->toHaveCount(7)
        ->and($report['trend']->first()['visits'])->toBe(0)
        ->and($report['hosts']->total())->toBe(1);
    $empty = $service->dashboard(Request::create('/dashboard', 'GET', ['traffic_host' => 'missing.example.com']));
    expect((int) $empty['totals']['visits'])->toBe(0);
});

it('renders the super admin charts and preserves dashboard operations', function () {
    $role = Role::firstOrCreate(['slug' => 'super-admin'], ['name' => 'Super Admin']);
    $admin = User::factory()->create(['role_id' => $role->id, 'organization_id' => null, 'branch_id' => null, 'status' => 'active', 'is_active' => true]);
    TrafficVisit::factory()->create();
    $this->actingAs($admin)->get('/dashboard?traffic_days=7')->assertOk()
        ->assertSee('Traffic & engagement', false)->assertSee('Daily visits')
        ->assertSee('Domain performance')->assertSee('demo.example.com')->assertSee('New Organization');
    $this->getJson('/dashboard?traffic_days=1000')->assertUnprocessable();
});

it('preserves multi-label custom domains without guessing public suffixes', function () {
    config(['traffic.root_domains' => ['example.co.ug']]);
    $service = app(TrafficAnalyticsService::class);
    expect($service->domainParts('demo.example.co.ug'))->toBe(['example.co.ug', 'demo'])
        ->and($service->domainParts('custom.co.ug'))->toBe(['custom.co.ug', null]);
});
