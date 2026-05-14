<?php

namespace App\Http\Middleware;

use App\Services\AuditLogger;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Symfony\Component\HttpFoundation\Response;

class AuditApiMutation
{
    public function __construct(private AuditLogger $auditLogger) {}

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->shouldAudit($request)) {
            return $next($request);
        }

        $auditable = $this->routeModel($request);
        $oldValues = $auditable ? $this->snapshot($auditable) : [];

        $response = $next($request);

        if (! $response->isSuccessful() || $response->headers->get('Idempotency-Replayed') === 'true') {
            return $response;
        }

        $freshAuditable = $this->freshModel($auditable);
        $responseData = $this->responseData($response);

        $this->auditLogger->recordApiMutation(
            request: $request,
            action: $this->action($request),
            auditable: $freshAuditable,
            newValues: $this->newValues($request, $freshAuditable, $responseData),
            oldValues: $oldValues,
            responseStatus: $response->getStatusCode(),
            recordType: $this->recordType($request, $responseData),
            recordId: $this->recordId($responseData),
        );

        return $response;
    }

    private function shouldAudit(Request $request): bool
    {
        return $request->is('api/*') && in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true);
    }

    private function routeModel(Request $request): ?Model
    {
        foreach ($request->route()?->parameters() ?? [] as $parameter) {
            if ($parameter instanceof Model) {
                return $parameter;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(Model $model): array
    {
        return $this->sanitize($model->getAttributes());
    }

    private function freshModel(?Model $model): ?Model
    {
        if (! $model) {
            return null;
        }

        return $model->exists ? $model->fresh() ?? $model : $model;
    }

    /**
     * @return array<string, mixed>
     */
    private function responseData(Response $response): array
    {
        if (! $response instanceof JsonResponse) {
            return [];
        }

        $payload = $response->getData(true);

        return is_array($payload) ? $payload : [];
    }

    /**
     * @param  array<string, mixed>  $responseData
     * @return array<string, mixed>
     */
    private function newValues(Request $request, ?Model $model, array $responseData): array
    {
        if ($model) {
            return $this->sanitize($model->getAttributes());
        }

        $data = $responseData['data'] ?? [];
        $values = is_array($data) ? $this->sanitize($data) : [];

        return $values + [
            '_request' => $this->requestPayload($request),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function requestPayload(Request $request): array
    {
        $payload = $request->except([
            'password',
            'password_confirmation',
            'current_password',
            'token',
            'access_token',
            'push_token',
            'document',
        ]);

        foreach ($request->allFiles() as $key => $file) {
            $payload[$key] = $this->fileMetadata($file);
        }

        return $this->sanitize($payload);
    }

    /**
     * @return array<string, mixed>|array<int, array<string, mixed>>
     */
    private function fileMetadata(UploadedFile|array $file): array
    {
        if (is_array($file)) {
            return array_map(fn (UploadedFile $item): array => $this->fileMetadata($item), $file);
        }

        return [
            'name' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType(),
            'size' => $file->getSize(),
        ];
    }

    private function action(Request $request): string
    {
        return 'api.'.str($request->route()?->getName() ?: $request->path())
            ->replace('api.v1.', 'v1.')
            ->replace('/', '.')
            ->toString();
    }

    /**
     * @param  array<string, mixed>  $responseData
     */
    private function recordType(Request $request, array $responseData): ?string
    {
        $data = $responseData['data'] ?? null;

        if (is_array($data) && isset($data['type'])) {
            return (string) $data['type'];
        }

        $routeName = (string) $request->route()?->getName();
        $resource = str($routeName)
            ->replace('api.v1.', '')
            ->before('.store')
            ->before('.update')
            ->before('.destroy')
            ->before('.')
            ->singular()
            ->toString();

        return $resource !== '' ? $resource : $routeName;
    }

    /**
     * @param  array<string, mixed>  $responseData
     */
    private function recordId(array $responseData): ?int
    {
        $data = $responseData['data'] ?? null;

        if (is_array($data) && isset($data['id']) && is_numeric($data['id'])) {
            return (int) $data['id'];
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function sanitize(array $values): array
    {
        $sanitized = Arr::except($values, [
            'password',
            'remember_token',
            'current_password',
            'password_confirmation',
            'token',
            'access_token',
            'push_token',
        ]);

        foreach ($sanitized as $key => $value) {
            if (is_array($value)) {
                $sanitized[$key] = $this->sanitize($value);
            }
        }

        return $sanitized;
    }
}
