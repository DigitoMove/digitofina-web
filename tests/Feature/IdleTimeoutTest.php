<?php

use App\Http\Middleware\EnforceIdleTimeout;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

uses(RefreshDatabase::class);

it('logs out a web user after the configured idle period', function () {
    $user = User::factory()->create();
    Auth::login($user);
    $request = Request::create('/protected');
    $request->setUserResolver(fn (): User => $user);
    $session = app('session')->driver();
    $session->start();
    $session->put('auth.idle_last_activity_at', now()->subSeconds(901));
    $request->setLaravelSession($session);

    $response = app(EnforceIdleTimeout::class)->handle($request, fn (Request $request) => response('ok'));

    expect($response->getStatusCode())->toBe(302)
        ->and(Auth::check())->toBeFalse();
});

it('refreshes the idle timer for active web users', function () {
    $user = User::factory()->create();
    Auth::login($user);
    $request = Request::create('/protected');
    $request->setUserResolver(fn (): User => $user);
    $session = app('session')->driver();
    $session->start();
    $session->put('auth.idle_last_activity_at', now()->subMinutes(5));
    $request->setLaravelSession($session);

    $response = app(EnforceIdleTimeout::class)->handle($request, fn (Request $request) => response('ok'));

    expect($response->getContent())->toBe('ok')
        ->and($session->get('auth.idle_last_activity_at'))->not->toBeNull()
        ->and(Auth::check())->toBeTrue();
});
