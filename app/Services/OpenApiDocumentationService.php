<?php

namespace App\Services;

use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as RouteFacade;
use Illuminate\Support\Str;

class OpenApiDocumentationService
{
    /**
     * @return array<string, mixed>
     */
    public function document(): array
    {
        return [
            'openapi' => '3.1.0',
            'info' => [
                'title' => 'DigitoFina API',
                'summary' => 'Versioned REST API for SACCO, microfinance, and core banking operations.',
                'version' => '1.0.0',
            ],
            'servers' => [
                ['url' => url('/api/v1'), 'description' => 'DigitoFina API v1'],
            ],
            'security' => [['sanctumBearer' => []]],
            'tags' => $this->tags(),
            'paths' => $this->paths(),
            'components' => $this->components(),
        ];
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function tags(): array
    {
        return collect(['Auth', 'Sync', 'Clients', 'Savings', 'Loans', 'Financial Controls', 'Accounting', 'Reports', 'Settings', 'Payments', 'Notifications', 'Webhooks'])
            ->map(fn (string $tag): array => ['name' => $tag])
            ->all();
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function paths(): array
    {
        $paths = [];

        foreach (RouteFacade::getRoutes() as $route) {
            if (! $this->isApiV1Route($route)) {
                continue;
            }

            foreach ($this->methods($route) as $method) {
                $path = '/'.ltrim(Str::after($route->uri(), 'api/v1'), '/');
                $paths[$path][strtolower($method)] = $this->operation($route, $method, $path);
            }
        }

        ksort($paths);

        return $paths;
    }

    private function isApiV1Route(Route $route): bool
    {
        return Str::startsWith((string) $route->getName(), 'api.v1.')
            && Str::startsWith($route->uri(), 'api/v1');
    }

    /**
     * @return array<int, string>
     */
    private function methods(Route $route): array
    {
        return collect($route->methods())
            ->reject(fn (string $method): bool => in_array($method, ['HEAD', 'OPTIONS'], true))
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function operation(Route $route, string $method, string $path): array
    {
        $name = (string) $route->getName();
        $isWrite = in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true);
        $permissions = $this->permissions($name, $method);
        $middleware = $route->gatherMiddleware();
        $idempotent = collect($middleware)->contains(fn (string $middleware): bool => str_contains($middleware, 'idempotent.financial'));

        return array_filter([
            'tags' => [$this->tag($path)],
            'summary' => $this->summary($name, $method),
            'operationId' => str_replace('.', '_', $name).'_'.strtolower($method),
            'description' => $this->description($name, $permissions, $idempotent),
            'security' => $this->isPublic($name) ? [] : [['sanctumBearer' => []]],
            'parameters' => $this->parameters($route, $method, $idempotent),
            'requestBody' => $isWrite ? $this->requestBody($name) : null,
            'responses' => $this->responses($method, $name),
            'x-required-permissions' => $permissions,
            'x-validation-rules' => $this->validationRules($name),
            'x-idempotency-required' => $idempotent,
            'x-audit-logged' => $isWrite,
            'x-rate-limited' => collect($middleware)->contains(fn (string $middleware): bool => str_contains($middleware, 'throttle')),
        ], fn ($value): bool => $value !== null && $value !== []);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function parameters(Route $route, string $method, bool $idempotent): array
    {
        $parameters = collect($route->parameterNames())->map(fn (string $parameter): array => [
            'name' => $parameter,
            'in' => 'path',
            'required' => true,
            'schema' => ['type' => 'integer'],
            'description' => Str::of($parameter)->headline()->toString().' identifier.',
        ]);

        if ($method === 'GET') {
            $parameters = $parameters->merge([
                ['name' => 'per_page', 'in' => 'query', 'schema' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 200], 'description' => 'Page size for mobile-friendly pagination.'],
                ['name' => 'search', 'in' => 'query', 'schema' => ['type' => 'string'], 'description' => 'Search text where supported.'],
                ['name' => 'sort', 'in' => 'query', 'schema' => ['type' => 'string'], 'description' => 'Sort column. Prefix with - for descending order.'],
                ['name' => 'updated_since', 'in' => 'query', 'schema' => ['type' => 'string', 'format' => 'date-time'], 'description' => 'Return records updated after this timestamp for offline sync.'],
                ['name' => 'view', 'in' => 'query', 'schema' => ['type' => 'string', 'enum' => ['list', 'detail']], 'description' => 'Use list for lightweight mobile payloads or detail for full records.'],
            ]);
        }

        if ($idempotent) {
            $parameters->push([
                'name' => 'Idempotency-Key',
                'in' => 'header',
                'required' => false,
                'schema' => ['type' => 'string'],
                'description' => 'Unique key for retry-safe financial operations. If omitted, the server generates one.',
            ]);
        }

        return $parameters->values()->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function requestBody(string $name): array
    {
        $example = $this->requestExample($name);

        return [
            'required' => true,
            'content' => [
                $this->isUpload($name) ? 'multipart/form-data' : 'application/json' => [
                    'schema' => ['type' => 'object'],
                    'example' => $example,
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function responses(string $method, string $name): array
    {
        $successStatus = $method === 'POST' && ! Str::contains($name, ['approve', 'reject', 'read', 'refresh', 'logout']) ? '201' : '200';

        if (Str::contains($name, ['maker-checker', 'approve', 'disburse', 'write-off', 'waive', 'withdraw', 'journal-entries.store'])) {
            $successStatus = '200';
        }

        return [
            $successStatus => [
                'description' => 'Successful response using DigitoFina standard JSON envelope.',
                'content' => [
                    'application/json' => [
                        'schema' => ['$ref' => '#/components/schemas/SuccessEnvelope'],
                        'example' => [
                            'success' => true,
                            'message' => 'Operation completed successfully',
                            'data' => ['id' => 1],
                            'meta' => ['server_time' => now()->toIso8601String(), 'api_version' => 'v1'],
                        ],
                    ],
                ],
            ],
            '401' => ['$ref' => '#/components/responses/Unauthenticated'],
            '403' => ['$ref' => '#/components/responses/Forbidden'],
            '422' => ['$ref' => '#/components/responses/ValidationFailed'],
        ];
    }

    private function tag(string $path): string
    {
        return match (true) {
            str_contains($path, '/auth') => 'Auth',
            str_contains($path, '/sync') => 'Sync',
            str_contains($path, '/clients') => 'Clients',
            str_contains($path, '/savings') => 'Savings',
            str_contains($path, '/loans') => 'Loans',
            str_contains($path, '/financial-controls') => 'Financial Controls',
            str_contains($path, '/accounting') => 'Accounting',
            str_contains($path, '/reports') => 'Reports',
            str_contains($path, '/settings') => 'Settings',
            str_contains($path, '/payments') => 'Payments',
            str_contains($path, '/notifications') => 'Notifications',
            str_contains($path, '/webhooks') => 'Webhooks',
            default => 'DigitoFina',
        };
    }

    private function summary(string $name, string $method): string
    {
        return Str::of($name)
            ->replace('api.v1.', '')
            ->replace('.', ' ')
            ->headline()
            ->prepend($method.' ')
            ->toString();
    }

    /**
     * @param  array<int, string>  $permissions
     */
    private function description(string $name, array $permissions, bool $idempotent): string
    {
        $parts = ['Standard response envelope is always returned.'];

        if ($permissions !== []) {
            $parts[] = 'Required permission(s): '.implode(', ', $permissions).'.';
        }

        if ($idempotent) {
            $parts[] = 'Financial operation protected by idempotency keys.';
        }

        if (! $this->isPublic($name)) {
            $parts[] = 'Organization and branch isolation is enforced server-side.';
        }

        return implode(' ', $parts);
    }

    /**
     * @return array<int, string>
     */
    private function permissions(string $name, string $method): array
    {
        return match (true) {
            str_contains($name, 'clients.documents') => ['manage-clients'],
            str_contains($name, 'clients') && $method === 'GET' => ['manage-clients', 'view-reports'],
            str_contains($name, 'clients') => ['manage-clients'],
            str_contains($name, 'savings.accounts.deposit'),
            str_contains($name, 'savings-accounts.deposit') => ['post-transactions'],
            str_contains($name, 'withdraw') => ['post-transactions'],
            str_contains($name, 'reverse') => ['request-reversals'],
            str_contains($name, 'savings') && $method === 'GET' => ['manage-accounts', 'view-reports'],
            str_contains($name, 'loans.approve') => ['approve-loans'],
            str_contains($name, 'loans.disburse') => ['disburse-loans'],
            str_contains($name, 'loans.repayments') => ['post-repayments'],
            str_contains($name, 'loans.write-off') => ['write-off-loans'],
            str_contains($name, 'loans.penalties.waive') => ['waive-penalties'],
            str_contains($name, 'loans') && $method === 'GET' => ['manage-loans', 'view-reports'],
            str_contains($name, 'loans') => ['manage-loans'],
            str_contains($name, 'financial-controls') => ['approve-maker-checker', 'view-approvals'],
            str_contains($name, 'accounting.journal-entries.store') => ['post-journal-entries'],
            str_contains($name, 'accounting') => ['manage-accounting', 'view-reports'],
            str_contains($name, 'reports.exports') => ['export-reports'],
            str_contains($name, 'reports') => ['view-reports'],
            str_contains($name, 'settings') => ['manage-settings'],
            str_contains($name, 'payments') => ['manage-accounts'],
            str_contains($name, 'notifications.sms.send') => ['manage-settings'],
            str_contains($name, 'webhooks.events.retry') => ['manage-settings'],
            str_contains($name, 'sync') => ['manage-clients'],
            default => [],
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function requestExample(string $name): array
    {
        return match (true) {
            str_contains($name, 'auth.login') => ['login' => 'admin@digitofina.test', 'password' => 'password', 'device_name' => 'Flutter Android'],
            str_contains($name, 'clients.documents.store') => ['type' => 'national_id_front', 'title' => 'National ID Front', 'document' => '<binary>'],
            str_ends_with($name, 'clients.store') => ['branch_id' => 1, 'client_type' => 'individual', 'first_name' => 'Amina', 'last_name' => 'Nakato', 'phone' => '+256701111222', 'kyc_status' => 'pending', 'status' => 'active'],
            str_contains($name, 'deposit') => ['amount' => 25000, 'payment_method' => 'cash', 'narration' => 'Counter deposit'],
            str_contains($name, 'withdraw') => ['amount' => 10000, 'payment_method' => 'cash', 'narration' => 'Member withdrawal'],
            str_contains($name, 'savings.transfers') || str_contains($name, 'savings-transfers') => ['from_savings_account_id' => 1, 'to_savings_account_id' => 2, 'amount' => 50000, 'narration' => 'Member transfer'],
            str_contains($name, 'loans.repayments') => ['amount' => 75000, 'payment_method' => 'cash', 'reference' => 'RCPT-001', 'payment_date' => today()->toDateString()],
            str_contains($name, 'loans.approve') => ['approved_amount' => 1000000, 'comments' => 'Approved by branch committee'],
            str_contains($name, 'loans.disburse') => ['payment_method' => 'mobile_money', 'reference' => 'MM-123456', 'disbursement_date' => today()->toDateString()],
            str_contains($name, 'accounting.journal-entries.store') => ['date' => today()->toDateString(), 'description' => 'Manual journal', 'lines' => [['chart_account_id' => 1, 'debit' => 1000, 'credit' => 0], ['chart_account_id' => 2, 'debit' => 0, 'credit' => 1000]]],
            str_contains($name, 'webhooks') => ['transaction_id' => 'MM123', 'amount' => 25000, 'status' => 'successful'],
            str_contains($name, 'sync.clients.push') => ['client_batch_id' => 'phone-001-batch-001', 'records' => [['action' => 'create', 'local_temp_id' => 'local-001', 'client_updated_at' => now()->toIso8601String(), 'payload' => ['branch_id' => 1, 'client_type' => 'individual', 'first_name' => 'Sarah', 'last_name' => 'Auma', 'phone' => '+256700900001']]]],
            default => ['example' => 'See x-validation-rules for expected fields.'],
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function validationRules(string $name): array
    {
        return match (true) {
            str_contains($name, 'auth.login') => ['login' => 'required email_or_phone', 'password' => 'required string', 'device_name' => 'nullable string'],
            str_ends_with($name, 'clients.store') || str_ends_with($name, 'clients.update') => ['branch_id' => 'required exists:branches,id', 'client_type' => 'required in:individual,group,business,institution', 'phone' => 'required string'],
            str_contains($name, 'clients.documents.store') => ['type' => 'required string', 'document' => 'required file private_storage'],
            str_contains($name, 'deposit') || str_contains($name, 'withdraw') => ['amount' => 'required numeric min:1', 'payment_method' => 'required in:cash,mobile_money,bank,cheque,internal_transfer', 'narration' => 'nullable string'],
            str_contains($name, 'savings.transfers') || str_contains($name, 'savings-transfers') => ['from_savings_account_id' => 'required exists:savings_accounts,id', 'to_savings_account_id' => 'required exists:savings_accounts,id different', 'amount' => 'required numeric min:1'],
            str_contains($name, 'loans.repayments') => ['amount' => 'required numeric min:1', 'payment_method' => 'required string', 'payment_date' => 'nullable date'],
            str_contains($name, 'settings') => ['settings' => 'required array'],
            str_contains($name, 'sync.clients.push') => ['records' => 'required array max:100', 'records.*.local_temp_id' => 'required string', 'records.*.client_updated_at' => 'required date', 'records.*.payload.branch_id' => 'required exists:branches,id'],
            default => ['See Form Request or controller validation for exact rule set.'],
        };
    }

    private function isPublic(string $name): bool
    {
        return str_contains($name, 'auth.login')
            || str_contains($name, 'auth.forgot-password')
            || str_contains($name, 'payments.callback')
            || str_contains($name, 'webhooks.store')
            || str_contains($name, 'docs.openapi');
    }

    private function isUpload(string $name): bool
    {
        return str_contains($name, 'documents.store') || str_contains($name, 'collaterals');
    }

    /**
     * @return array<string, mixed>
     */
    private function components(): array
    {
        return [
            'securitySchemes' => [
                'sanctumBearer' => [
                    'type' => 'http',
                    'scheme' => 'bearer',
                    'bearerFormat' => 'Sanctum token',
                ],
            ],
            'schemas' => [
                'SuccessEnvelope' => [
                    'type' => 'object',
                    'required' => ['success', 'message', 'data', 'meta'],
                    'properties' => [
                        'success' => ['type' => 'boolean', 'example' => true],
                        'message' => ['type' => 'string'],
                        'data' => ['type' => ['object', 'array']],
                        'meta' => ['type' => 'object'],
                    ],
                ],
                'ErrorEnvelope' => [
                    'type' => 'object',
                    'required' => ['success', 'message', 'errors'],
                    'properties' => [
                        'success' => ['type' => 'boolean', 'example' => false],
                        'message' => ['type' => 'string', 'example' => 'Validation failed'],
                        'errors' => ['type' => 'object'],
                    ],
                ],
            ],
            'responses' => [
                'Unauthenticated' => [
                    'description' => 'Token missing or invalid.',
                    'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/ErrorEnvelope']]],
                ],
                'Forbidden' => [
                    'description' => 'Permission, organization, or branch access denied.',
                    'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/ErrorEnvelope']]],
                ],
                'ValidationFailed' => [
                    'description' => 'Validation failed.',
                    'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/ErrorEnvelope']]],
                ],
            ],
        ];
    }
}
