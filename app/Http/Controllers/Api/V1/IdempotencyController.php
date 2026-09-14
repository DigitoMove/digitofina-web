<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\IdempotencyKey;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class IdempotencyController extends Controller
{
    public function show(Request $request, string $key): JsonResponse
    {
        $record = IdempotencyKey::query()
            ->where('key', $key)
            ->where('user_id', $request->user()->id)
            ->latest('id')
            ->first();

        if (! $record) {
            return ApiResponse::error('Idempotency operation was not found.', status: 404);
        }

        return ApiResponse::success([
            'idempotency_key' => $record->key,
            'endpoint' => $record->endpoint,
            'status' => $record->status,
            'completed' => $record->status === 'completed',
            'response_status' => $record->response_status,
            'response' => $record->response_body,
            'created_at' => $record->created_at?->toIso8601String(),
            'updated_at' => $record->updated_at?->toIso8601String(),
        ], 'Idempotency operation retrieved.');
    }
}
