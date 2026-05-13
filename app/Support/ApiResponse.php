<?php

namespace App\Support;

use App\Http\Resources\ApiDataResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;
use stdClass;

class ApiResponse
{
    /**
     * @param  array<string, mixed>  $meta
     */
    public static function resource(JsonResource $resource, string $message = 'Operation completed successfully', int $status = 200, array $meta = []): JsonResponse
    {
        return self::success($resource->resolve(request()), $message, $status, $meta);
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    public static function collection(AnonymousResourceCollection $resource, string $message = 'Operation completed successfully', int $status = 200, array $meta = []): JsonResponse
    {
        $payload = $resource->toResponse(request())->getData(true);

        if (isset($payload['meta']) || isset($payload['links'])) {
            $meta = array_filter([
                'pagination' => $payload['meta'] ?? null,
                'links' => $payload['links'] ?? null,
            ]) + $meta;
        }

        return self::success($payload['data'] ?? [], $message, $status, $meta);
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    public static function data(mixed $data = null, string $message = 'Operation completed successfully', int $status = 200, array $meta = []): JsonResponse
    {
        if ($data === null) {
            return self::success(new stdClass, $message, $status, $meta);
        }

        return self::resource(new ApiDataResource($data), $message, $status, $meta);
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    public static function success(mixed $data = null, string $message = 'Operation completed successfully', int $status = 200, array $meta = []): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $data ?? new stdClass,
            'meta' => $meta === [] ? new stdClass : $meta,
        ], $status);
    }

    /**
     * @param  array<string, mixed>  $errors
     * @param  array<string, mixed>  $meta
     */
    public static function error(string $message, array $errors = [], int $status = 400, array $meta = []): JsonResponse
    {
        $payload = [
            'success' => false,
            'message' => $message,
            'errors' => $errors === [] ? new stdClass : $errors,
        ];

        if ($meta !== []) {
            $payload['meta'] = $meta;
        }

        return response()->json($payload, $status);
    }
}
