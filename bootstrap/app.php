<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

spl_autoload_register(static function (string $class): void {
    if (! str_starts_with($class, 'Laravel\\Sanctum\\')) {
        return;
    }

    $relativeClass = substr($class, strlen('Laravel\\Sanctum\\'));
    $path = dirname(__DIR__).'/vendor/laravel/sanctum/src/'.str_replace('\\', '/', $relativeClass).'.php';

    if (is_file($path)) {
        require $path;
    }
});

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: realpath(__DIR__.'/../routes/api.php') ?: null,
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
