<?php

namespace App\Http\Controllers;

use App\Http\Requests\ClientStatementRequest;
use App\Models\Client;
use App\Services\ClientStatementService;
use App\Services\ReportDocumentFormatter;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class ClientStatementController extends Controller
{
    public function member(ClientStatementRequest $request, ClientStatementService $statements): View|Response
    {
        $client = Client::query()->where('user_id', $request->user()->id)->firstOrFail();

        return $this->show($request, $client, $statements);
    }

    public function show(ClientStatementRequest $request, Client $client, ClientStatementService $statements): View|Response
    {
        Gate::authorize('view', $client);
        $filters = $request->validated();
        $statement = $statements->statement($client, $filters);

        if ($format = $filters['format'] ?? null) {
            abort_unless($statement, 404);
            $filename = Str::slug($statement['metadata']['Account Number']).'-statement.'.$format;
            $mime = match ($format) {
                'pdf' => 'application/pdf',
                'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                default => 'text/csv; charset=UTF-8',
            };

            return response()->streamDownload(function () use ($statement, $format): void {
                echo app(ReportDocumentFormatter::class)->renderClientStatement($statement, $format);
            }, $filename, ['Content-Type' => $mime, 'Cache-Control' => 'private, no-store']);
        }

        return view('clients.statements', [
            'client' => $client,
            'statement' => $statement,
            'statementAccounts' => $statements->accounts($client)->map(fn ($account): string => $statements->label($account)),
            'statementAction' => $request->user()->isMember() ? route('member.statements') : route('clients.statements', $client),
        ]);
    }
}
