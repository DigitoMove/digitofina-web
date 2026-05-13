<?php

use App\Support\ApiResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

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
        $exceptions->shouldRenderJsonWhen(fn (Request $request, Throwable $exception): bool => $request->is('api/*') || $request->expectsJson());

        $exceptions->render(function (ValidationException $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return ApiResponse::error('Validation failed', $exception->errors(), 422);
        });

        $exceptions->render(function (AuthenticationException $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return ApiResponse::error('Unauthenticated.', [], 401);
        });

        $exceptions->render(function (AuthorizationException $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return ApiResponse::error($exception->getMessage() ?: 'This action is unauthorized.', [], 403);
        });

        $exceptions->render(function (ModelNotFoundException $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return ApiResponse::error('Resource not found.', [], 404);
        });

        $exceptions->render(function (HttpExceptionInterface $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            $status = $exception->getStatusCode();
            $message = $exception->getMessage() ?: match ($status) {
                401 => 'Unauthenticated.',
                403 => 'This action is unauthorized.',
                404 => 'Resource not found.',
                405 => 'Method not allowed.',
                422 => 'Validation failed',
                default => 'Request failed.',
            };

            return ApiResponse::error($message, [], $status);
        });

        $exceptions->render(function (Throwable $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return ApiResponse::error('Server error.', [], 500);
        });
    })->create();
