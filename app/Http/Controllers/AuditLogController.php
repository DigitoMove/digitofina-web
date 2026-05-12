<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class AuditLogController extends Controller
{
    public function index(Request $request): View
    {
        Gate::authorize('viewAny', AuditLog::class);

        $logs = AuditLog::query()
            ->with('user')
            ->when($request->action, fn ($query, string $action) => $query->where('action', 'like', "%{$action}%"))
            ->when($request->module, fn ($query, string $module) => $query->where('module', $module))
            ->when($request->user_id, fn ($query, string $userId) => $query->where('user_id', $userId))
            ->latest()
            ->paginate(20)
            ->withQueryString();

        return view('audit-logs.index', compact('logs'));
    }

    public function show(AuditLog $auditLog): View
    {
        Gate::authorize('view', $auditLog);

        return view('audit-logs.show', ['log' => $auditLog->load('user')]);
    }
}
