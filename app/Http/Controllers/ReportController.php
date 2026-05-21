<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Services\ReportCatalogService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ReportController extends Controller
{
    public function index(Request $request, ReportCatalogService $reports): View
    {
        abort_if($request->user()->isMember(), 403);

        return view('reports.index', [
            'groups' => $reports->grouped($request->user()),
            'branches' => Branch::query()->orderBy('name')->get(),
            'canChooseBranch' => $request->user()->canPerform('manage-branches'),
            'defaultFrom' => today()->toDateString(),
            'defaultTo' => now()->toDateString(),
        ]);
    }

    public function show(Request $request, string $report, ReportCatalogService $reports): View
    {
        abort_if($request->user()->isMember(), 403);

        $data = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'detail' => ['nullable', 'in:brief,detailed'],
        ]);

        if (! $request->user()->canPerform('manage-branches') && isset($data['branch_id'])) {
            abort_unless((int) $data['branch_id'] === (int) $request->user()->branch_id, 403);
        }

        return view('reports.show', $reports->generate($report, $data, $request->user()) + [
            'branches' => Branch::query()->orderBy('name')->get(),
            'canChooseBranch' => $request->user()->canPerform('manage-branches'),
        ]);
    }
}
