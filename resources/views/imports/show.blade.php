@extends('layouts.app')

@section('content')
    <div class="space-y-6">
        <div class="flex flex-col justify-between gap-4 xl:flex-row xl:items-end">
            <div>
                <a href="{{ route('imports.index') }}" class="text-sm font-semibold text-brand-700 hover:text-brand-900">← Data imports</a>
                <h1 class="mt-2 text-2xl font-semibold tracking-tight text-slate-950">{{ $types[$batch->type]['label'] ?? str($batch->type)->headline() }} preview</h1>
                <p class="mt-2 text-sm text-slate-600">{{ $batch->original_filename }} · {{ $batch->branch?->name ?? 'All branches' }} · uploaded {{ $batch->created_at->format('d M Y H:i') }}</p>
            </div>
            <div class="flex flex-wrap gap-2">
                @can('commit', $batch)
                    <form method="POST" action="{{ route('imports.commit', $batch) }}" onsubmit="return confirm('Commit all valid rows in this import?')">
                        @csrf
                        <button class="rounded-xl bg-slate-950 px-4 py-3 text-sm font-semibold text-white shadow-sm transition hover:-translate-y-0.5 hover:bg-brand-800 disabled:cursor-not-allowed disabled:opacity-50" @disabled($batch->invalid_rows > 0)>Commit import</button>
                    </form>
                @endcan
                @can('rollback', $batch)
                    <form method="POST" action="{{ route('imports.rollback', $batch) }}" onsubmit="return confirm('Rollback safe rows from this import batch?')">
                        @csrf
                        <button class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm font-semibold text-red-700 transition hover:bg-red-100">Rollback safe rows</button>
                    </form>
                @endcan
            </div>
        </div>

        <div class="grid gap-4 md:grid-cols-5">
            @foreach ([['Total', $batch->total_rows, 'bg-slate-100 text-slate-700'], ['Valid', $batch->valid_rows, 'bg-emerald-100 text-emerald-700'], ['Invalid', $batch->invalid_rows, 'bg-red-100 text-red-700'], ['Imported', $batch->imported_rows, 'bg-brand-100 text-brand-800'], ['Failed', $batch->failed_rows, 'bg-amber-100 text-amber-700']] as [$label, $value, $class])
                <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                    <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ $label }}</div>
                    <div class="mt-2 inline-flex rounded-full px-3 py-1 text-xl font-semibold {{ $class }}">{{ number_format($value) }}</div>
                </div>
            @endforeach
        </div>

        <section class="rounded-xl border border-slate-200 bg-white shadow-sm">
            <div class="flex flex-col gap-2 border-b border-slate-200 px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h2 class="text-lg font-semibold text-slate-950">Rows and corrections</h2>
                    <p class="mt-1 text-sm text-slate-500">Invalid rows can be edited inline and revalidated before commit.</p>
                </div>
                <span class="rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-600">{{ str($batch->status)->headline() }}</span>
            </div>

            <div class="overflow-x-auto px-4 py-4">
                <table class="min-w-full border-separate border-spacing-x-3 border-spacing-y-2 text-left text-sm">
                    <thead class="text-xs uppercase tracking-wide text-slate-500">
                        <tr>
                            <th class="px-3 py-2">Row</th>
                            <th class="px-3 py-2">Status</th>
                            @foreach ($columns as $column)
                                <th class="min-w-40 px-3 py-2">{{ str($column)->headline() }}</th>
                            @endforeach
                            <th class="min-w-56 px-3 py-2">Errors</th>
                            <th class="px-3 py-2"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $row)
                            @php($editable = $batch->isEditable() && $row->status !== 'imported' && $row->status !== 'rolled_back')
                            <tr class="bg-slate-50 align-top transition hover:bg-brand-50/70">
                                <td class="px-3 py-3 font-semibold text-slate-900">
                                    @if ($editable)
                                        <form id="row-form-{{ $row->id }}" method="POST" action="{{ route('imports.rows.update', [$batch, $row]) }}">
                                            @csrf
                                            @method('PUT')
                                        </form>
                                    @endif
                                    {{ $row->row_number }}
                                </td>
                                <td class="px-3 py-3">
                                    <span class="rounded-full px-3 py-1 text-xs font-semibold {{ $row->status === 'imported' ? 'bg-emerald-100 text-emerald-700' : ($row->status === 'invalid' || $row->status === 'failed' ? 'bg-red-100 text-red-700' : 'bg-slate-200 text-slate-700') }}">{{ str($row->status)->headline() }}</span>
                                </td>
                                @foreach ($columns as $column)
                                    <td class="px-3 py-3">
                                        @if ($editable)
                                            <input form="row-form-{{ $row->id }}" name="normalized_data[{{ $column }}]" value="{{ old("normalized_data.$column", $row->normalized_data[$column] ?? $row->raw_data[$column] ?? '') }}" class="h-11 w-44 rounded-xl border border-slate-200 bg-white px-3 text-sm text-slate-900 shadow-sm transition hover:border-brand-300 focus:border-brand-500 focus:ring-4 focus:ring-brand-100">
                                        @else
                                            <span class="whitespace-nowrap text-slate-700">{{ $row->normalized_data[$column] ?? $row->raw_data[$column] ?? '—' }}</span>
                                        @endif
                                    </td>
                                @endforeach
                                <td class="px-3 py-3 text-xs text-red-700">
                                    @if ($row->validation_errors)
                                        <div class="space-y-1">
                                            @foreach ($row->validation_errors as $field => $messages)
                                                <div><span class="font-semibold">{{ str($field)->headline() }}:</span> {{ implode(' ', $messages) }}</div>
                                            @endforeach
                                        </div>
                                    @else
                                        <span class="text-slate-400">{{ $row->message ?: 'No errors' }}</span>
                                    @endif
                                </td>
                                <td class="px-3 py-3 text-right">
                                    @if ($editable)
                                        <button form="row-form-{{ $row->id }}" class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-xs font-semibold text-slate-700 transition hover:border-brand-200 hover:text-brand-800">Revalidate</button>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="border-t border-slate-200 px-5 py-4">{{ $rows->links() }}</div>
        </section>
    </div>
@endsection
