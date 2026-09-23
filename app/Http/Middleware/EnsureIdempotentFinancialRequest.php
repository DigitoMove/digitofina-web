<?php

namespace App\Http\Middleware;

use App\Models\IdempotencyKey;
use App\Support\ApiResponse;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class EnsureIdempotentFinancialRequest
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->header('Idempotency-Key') ?: $request->input('idempotency_key') ?: (string) Str::uuid();
        $endpoint = $request->method().' '.$request->route()?->getName();
        $requestHash = hash('sha256', $request->method().'|'.$request->path().'|'.json_encode($request->except('idempotency_key'), JSON_THROW_ON_ERROR));
        $user = $request->user();

        $response = DB::transaction(function () use ($request, $next, $key, $endpoint, $requestHash, $user): Response {
            $record = IdempotencyKey::query()
                ->where('key', $key)
                ->where('endpoint', $endpoint)
                ->where('user_id', $user?->id)
                ->lockForUpdate()
                ->first();

            if (! $record) {
                if ($request->header('Idempotency-Verify-Only') === 'true') {
                    return ApiResponse::error('No matching financial operation exists.', ['verification' => ['not_found']], 404);
                }
                $record = IdempotencyKey::create([
                    'key' => $key,
                    'endpoint' => $endpoint,
                    'user_id' => $user?->id,
                    'organization_id' => $user?->organization_id,
                    'request_hash' => $requestHash,
                    'status' => 'processing',
                ]);
                $record = IdempotencyKey::query()->whereKey($record->id)->lockForUpdate()->firstOrFail();
            }

            if ($record->request_hash !== $requestHash) {
                return ApiResponse::error('Idempotency key was already used for a different request.', [
                    'idempotency_key' => ['Use a new idempotency key for this request payload.'],
                ], 409);
            }

            if ($record->status === 'completed' && $record->response_body !== null) {
                return response()->json($record->response_body, $record->response_status ?? 200)
                    ->header('Idempotency-Key', $record->key)
                    ->header('Idempotency-Replayed', 'true');
            }

            if ($request->header('Idempotency-Verify-Only') === 'true') {
                return ApiResponse::error('The financial operation has not completed. Please verify again later.', [], 409);
            }

            $response = $next($request);

            if ($response instanceof JsonResponse && $response->isSuccessful()) {
                $record->update([
                    'response_body' => $response->getData(true),
                    'response_status' => $response->getStatusCode(),
                    'status' => 'completed',
                ]);
            }

            return $response;
        }, 3);

        return $response
            ->header('Idempotency-Key', $key)
            ->header('Idempotency-Replayed', $response->headers->get('Idempotency-Replayed', 'false'));
    }
}
