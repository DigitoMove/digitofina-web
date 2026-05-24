@extends('layouts.app')

@section('content')
<div class="mb-5 flex flex-wrap items-end justify-between gap-3">
    <div>
        <div class="text-sm font-semibold text-slate-500">{{ $definition['category'] }}</div>
        <h1 class="df-title">{{ $definition['title'] }}</h1>
        <p class="df-muted">
            {{ $definition['usesDates'] ? $from->format('d M Y').' to '.$to->format('d M Y') : 'Point-in-time report' }}
            · {{ str($detail)->headline() }}
        </p>
    </div>
    <div class="flex flex-wrap gap-2">
        <a href="{{ route('reports.index') }}" class="rounded border px-4 py-2 text-sm font-semibold">All Reports</a>
        <button type="button" onclick="window.print()" class="df-action-secondary">Print</button>
    </div>
</div>

@if(! empty($summary))
    <div class="mb-5 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        @foreach($summary as $label => $value)
            <div class="df-panel p-4">
                <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ $label }}</div>
                <div class="mt-2 text-xl font-semibold text-slate-950">{{ $value }}</div>
            </div>
        @endforeach
    </div>
@endif

<div class="df-panel overflow-hidden">
    <div class="flex flex-wrap items-start justify-between gap-3 border-b border-slate-200 px-5 py-4">
        <div>
            <h2 class="font-semibold text-slate-950">Generated Results</h2>
            <p class="text-sm text-slate-500">{{ $rows->count() }} row{{ $rows->count() === 1 ? '' : 's' }} shown.</p>
        </div>
        @if(in_array($definition['kind'], ['transactions', 'teller_transactions'], true))
            <form method="GET" action="{{ route('reports.show', $definition['slug']) }}" class="flex flex-wrap items-center justify-end gap-2">
                <input type="hidden" name="from" value="{{ request('from') }}">
                <input type="hidden" name="to" value="{{ request('to') }}">
                <input type="hidden" name="branch_id" value="{{ $branchId }}">
                <input type="hidden" name="detail" value="{{ $detail }}">
                <input name="search" value="{{ $search }}" class="min-w-64 rounded border-slate-300 text-sm" placeholder="Search description, reference, client, or account">
                <button class="df-action-secondary">Search</button>
                @if($search !== '')
                    <a href="{{ route('reports.show', ['report' => $definition['slug'], 'from' => request('from'), 'to' => request('to'), 'branch_id' => $branchId, 'detail' => $detail]) }}" class="rounded border border-slate-200 px-3 py-2 text-sm font-semibold text-slate-600 hover:bg-slate-50">Clear</a>
                @endif
            </form>
        @endif
    </div>
    <div class="overflow-auto">
        <table class="w-full text-left text-sm">
            <thead class="bg-slate-50 text-slate-600">
                <tr>
                    @foreach($columns as $column)
                        <th class="whitespace-nowrap px-4 py-3">{{ $column }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @forelse($rows as $row)
                    <tr class="border-t border-slate-200 {{ ($row['_is_arrears'] ?? false) ? 'font-semibold text-red-700' : '' }} {{ ($row['_is_total'] ?? false) ? 'bg-slate-50 font-semibold text-slate-950' : '' }}">
                        @foreach($columns as $column)
                            @php
                                $cellLink = $row['_links'][$column] ?? null;
                            @endphp
                            <td class="whitespace-nowrap px-4 py-3">
                                @if($cellLink)
                                    <a href="{{ $cellLink }}" class="font-semibold text-blue-700 hover:text-blue-900 hover:underline">{{ $row[$column] ?? '' }}</a>
                                @else
                                    {{ $row[$column] ?? '' }}
                                @endif
                            </td>
                        @endforeach
                    </tr>
                @empty
                    <tr>
                        <td colspan="{{ count($columns) }}" class="px-4 py-10 text-center text-slate-500">No records found for this report and filter set.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
