<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreDataImportRequest;
use App\Http\Requests\UpdateImportRowRequest;
use App\Models\Branch;
use App\Models\ImportBatch;
use App\Models\ImportRow;
use App\Models\Organization;
use App\Services\DataImportService;
use App\Support\ApiSecurity;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use InvalidArgumentException;
use RuntimeException;

class DataImportController extends Controller
{
    public function index(DataImportService $imports): View
    {
        Gate::authorize('viewAny', ImportBatch::class);

        $user = request()->user();
        $batches = ImportBatch::query()
            ->with(['branch.organization', 'creator', 'organization'])
            ->when(! $user->hasGlobalOrganizationAccess() && $user->organization_id, fn ($query) => $query->where('organization_id', $user->organization_id))
            ->when(! ApiSecurity::hasGlobalBranchAccess($user) && $user->branch_id, fn ($query) => $query->where('branch_id', $user->branch_id))
            ->latest()
            ->paginate(12);
        $branches = Branch::query()
            ->with('organization')
            ->when(! $user->hasGlobalOrganizationAccess() && $user->organization_id, fn ($query) => $query->where('organization_id', $user->organization_id))
            ->when(! ApiSecurity::hasGlobalBranchAccess($user) && $user->branch_id, fn ($query) => $query->whereKey($user->branch_id))
            ->orderBy('name')
            ->get();

        return view('imports.index', [
            'batches' => $batches,
            'branches' => $branches,
            'organizations' => $user->hasGlobalOrganizationAccess()
                ? Organization::query()->orderBy('name')->get()
                : Organization::query()->whereKey($user->organization_id)->get(),
            'types' => $imports->types(),
        ]);
    }

    public function store(StoreDataImportRequest $request, DataImportService $imports): RedirectResponse
    {
        Gate::authorize('create', ImportBatch::class);

        try {
            $batch = $imports->createBatch(
                $request->user(),
                $request->file('file'),
                $request->string('type')->toString(),
                $request->integer('branch_id') ?: null,
                $request->integer('organization_id') ?: null,
            );
        } catch (InvalidArgumentException|RuntimeException $exception) {
            return back()->withErrors(['file' => $exception->getMessage()])->withInput();
        }

        return redirect()->route('imports.show', $batch)->with('status', 'Import preview is ready. Review errors before committing.');
    }

    public function show(ImportBatch $import, DataImportService $imports): View
    {
        Gate::authorize('view', $import);

        $import->load(['branch', 'creator', 'committer', 'rollbackUser']);
        $rows = $import->rows()->orderBy('row_number')->paginate(50);

        return view('imports.show', [
            'batch' => $import,
            'rows' => $rows,
            'types' => $imports->types(),
            'columns' => $imports->types()[$import->type]['columns'] ?? [],
        ]);
    }

    public function updateRow(UpdateImportRowRequest $request, ImportBatch $import, ImportRow $row, DataImportService $imports): RedirectResponse
    {
        Gate::authorize('update', $import);
        abort_unless((int) $row->import_batch_id === (int) $import->id, 404);

        try {
            $imports->updateRow($row, $request->validated('normalized_data'), $request->user());
        } catch (InvalidArgumentException $exception) {
            return back()->withErrors(['row' => $exception->getMessage()]);
        }

        return back()->with('status', "Row {$row->row_number} revalidated.");
    }

    public function commit(ImportBatch $import, DataImportService $imports): RedirectResponse
    {
        Gate::authorize('commit', $import);

        try {
            $imports->commit($import, request()->user());
        } catch (InvalidArgumentException $exception) {
            return back()->withErrors(['commit' => $exception->getMessage()]);
        }

        return back()->with('status', 'Import committed. Review row statuses for any failed records.');
    }

    public function rollback(ImportBatch $import, DataImportService $imports): RedirectResponse
    {
        Gate::authorize('rollback', $import);

        try {
            $imports->rollback($import, request()->user());
        } catch (InvalidArgumentException $exception) {
            return back()->withErrors(['rollback' => $exception->getMessage()]);
        }

        return back()->with('status', 'Safe import rows were rolled back.');
    }

    public function template(string $type, DataImportService $imports): Response
    {
        Gate::authorize('viewAny', ImportBatch::class);

        return response($imports->templateCsv($type), 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="'.$type.'-template.csv"',
        ]);
    }
}
