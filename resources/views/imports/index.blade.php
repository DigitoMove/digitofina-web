@extends('layouts.app')

@section('content')
    <div class="space-y-6">
        <div class="flex flex-col justify-between gap-4 lg:flex-row lg:items-end">
            <div>
                <p class="text-sm font-semibold uppercase tracking-wide text-brand-700">Migration desk</p>
                <h1 class="mt-1 text-2xl font-semibold tracking-tight text-slate-950">Data import tools</h1>
                <p class="mt-2 max-w-3xl text-sm text-slate-600">Preview, validate, correct, and commit operational data from Excel or CSV while keeping a full import history.</p>
            </div>
        </div>

        <div class="grid gap-5 xl:grid-cols-[minmax(0,1fr)_22rem]">
            <section class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                <div class="flex items-center justify-between gap-4">
                    <div>
                        <h2 class="text-lg font-semibold text-slate-950">Start a new import</h2>
                        <p class="mt-1 text-sm text-slate-500">Upload CSV or XLSX files. The system validates rows before anything is saved.</p>
                    </div>
                </div>

                <form method="POST" action="{{ route('imports.store') }}" enctype="multipart/form-data" class="mt-5 grid gap-4 md:grid-cols-2">
                    @csrf
                    @if(auth()->user()->hasGlobalOrganizationAccess())
                        <label class="block">
                            <span class="text-sm font-medium text-slate-700">Organization</span>
                            <select name="organization_id" class="mt-2 h-12 w-full rounded-xl border border-slate-200 bg-white px-4 text-sm text-slate-900 shadow-sm transition hover:border-brand-300 focus:border-brand-500 focus:ring-4 focus:ring-brand-100">
                                <option value="">Any organization</option>
                                @foreach ($organizations as $organization)
                                    <option value="{{ $organization->id }}" @selected((string) old('organization_id') === (string) $organization->id)>{{ $organization->name }}</option>
                                @endforeach
                            </select>
                        </label>
                    @endif

                    <label class="block">
                        <span class="text-sm font-medium text-slate-700">Import type <x-required-badge /></span>
                        <select name="type" class="mt-2 h-12 w-full rounded-xl border border-slate-200 bg-white px-4 text-sm text-slate-900 shadow-sm transition hover:border-brand-300 focus:border-brand-500 focus:ring-4 focus:ring-brand-100" required>
                            @foreach ($types as $key => $type)
                                <option value="{{ $key }}" @selected(old('type') === $key)>{{ $type['label'] }}</option>
                            @endforeach
                        </select>
                    </label>

                    <label class="block">
                        <span class="text-sm font-medium text-slate-700">Branch</span>
                        <select name="branch_id" class="mt-2 h-12 w-full rounded-xl border border-slate-200 bg-white px-4 text-sm text-slate-900 shadow-sm transition hover:border-brand-300 focus:border-brand-500 focus:ring-4 focus:ring-brand-100">
                            <option value="">{{ auth()->user()->hasOrganizationBranchAccess() ? 'Use branch codes from file' : 'Use my branch' }}</option>
                            @foreach ($branches as $branch)
                                <option value="{{ $branch->id }}" @selected((string) old('branch_id') === (string) $branch->id)>{{ $branch->organization?->name ? $branch->organization->name.' · ' : '' }}{{ $branch->code }} · {{ $branch->name }}</option>
                            @endforeach
                        </select>
                    </label>

                    <label class="block md:col-span-2">
                        <span class="text-sm font-medium text-slate-700">Excel or CSV file <x-required-badge /></span>
                        <input type="file" name="file" accept=".csv,.txt,.xlsx" class="mt-2 h-12 w-full rounded-xl border border-dashed border-slate-300 bg-slate-50 px-4 py-2 text-sm text-slate-700 shadow-sm transition file:mr-4 file:rounded-lg file:border-0 file:bg-slate-900 file:px-3 file:py-2 file:text-sm file:font-semibold file:text-white hover:border-brand-300 focus:border-brand-500 focus:ring-4 focus:ring-brand-100" required>
                    </label>

                    <div class="md:col-span-2">
                        <button class="rounded-xl bg-slate-950 px-5 py-3 text-sm font-semibold text-white shadow-sm transition hover:-translate-y-0.5 hover:bg-brand-800 focus:ring-4 focus:ring-brand-100">Upload and preview</button>
                    </div>
                </form>
            </section>

            <section class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                <h2 class="text-lg font-semibold text-slate-950">Templates</h2>
                <p class="mt-1 text-sm text-slate-500">Download headers before preparing a spreadsheet.</p>
                <div class="mt-4 grid gap-2">
                    @foreach ($types as $key => $type)
                        <a href="{{ route('imports.template', $key) }}" class="flex items-center justify-between rounded-lg border border-slate-200 px-3 py-2 text-sm font-medium text-slate-700 transition hover:border-brand-200 hover:bg-brand-50 hover:text-brand-900">
                            <span>{{ $type['label'] }}</span>
                            <span class="text-xs text-slate-400">CSV</span>
                        </a>
                    @endforeach
                </div>
            </section>
        </div>

        <section class="rounded-xl border border-slate-200 bg-white shadow-sm">
            <div class="border-b border-slate-200 px-5 py-4">
                <h2 class="text-lg font-semibold text-slate-950">Import history</h2>
            </div>
            <div class="overflow-x-auto px-4 py-3">
                <table class="min-w-full border-separate border-spacing-x-3 border-spacing-y-2 text-left text-sm">
                    <thead class="text-xs uppercase tracking-wide text-slate-500">
                        <tr>
                            <th class="px-3 py-2">File</th>
                            <th class="px-3 py-2">Type</th>
                            <th class="px-3 py-2">Branch</th>
                            <th class="px-3 py-2">Rows</th>
                            <th class="px-3 py-2">Status</th>
                            <th class="px-3 py-2">Uploaded</th>
                            <th class="px-3 py-2"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($batches as $batch)
                            <tr class="rounded-lg bg-slate-50 transition hover:bg-brand-50/70">
                                <td class="px-3 py-3 font-medium text-slate-900">{{ $batch->original_filename }}</td>
                                <td class="px-3 py-3 text-slate-600">{{ $types[$batch->type]['label'] ?? str($batch->type)->headline() }}</td>
                                <td class="px-3 py-3 text-slate-600">{{ $batch->organization?->name ? $batch->organization->name.' · ' : '' }}{{ $batch->branch?->name ?? 'File branches' }}</td>
                                <td class="px-3 py-3 text-slate-600">{{ number_format($batch->total_rows) }} rows</td>
                                <td class="px-3 py-3">
                                    <span class="rounded-full px-3 py-1 text-xs font-semibold {{ $batch->status === 'imported' ? 'bg-emerald-100 text-emerald-700' : ($batch->status === 'invalid' || $batch->status === 'failed' ? 'bg-red-100 text-red-700' : 'bg-amber-100 text-amber-700') }}">{{ str($batch->status)->headline() }}</span>
                                </td>
                                <td class="px-3 py-3 text-slate-600">{{ $batch->created_at->format('d M Y H:i') }}</td>
                                <td class="px-3 py-3 text-right"><x-icon-action :href="route('imports.show', $batch)" label="Open import" icon="open" /></td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="px-3 py-8 text-center text-sm text-slate-500">No imports yet.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="border-t border-slate-200 px-5 py-4">{{ $batches->links() }}</div>
        </section>
    </div>
@endsection
