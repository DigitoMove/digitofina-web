@extends('layouts.app')

@php
    $oldScope = old('scope_type', request('scope_type', 'organization'));
    $oldTarget = old('target_id', request('target_id'));
    $oldCategories = old('categories', request('categories', []));
@endphp

@section('content')
<div class="mb-5 flex flex-wrap items-start justify-between gap-3">
    <div>
        <div class="text-sm font-semibold text-red-600">Administration</div>
        <h1 class="df-title">Data Reset</h1>
        <p class="df-muted">Preview and wipe scoped organization, branch, or user data without leaving dependent records behind.</p>
    </div>
    <div class="max-w-xl rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
        <div class="font-semibold">Destructive action</div>
        <div>Preview first, review the affected tables, then type WIPE to execute. Deleted rows cannot be restored from this screen.</div>
    </div>
</div>

@if($errors->has('data_wipe'))
    <div class="mb-4 rounded border border-red-200 bg-red-50 p-3 text-sm text-red-700">{{ $errors->first('data_wipe') }}</div>
@endif

<form method="POST" action="{{ route('data-wipe.store') }}" class="grid gap-5">
    @csrf
    <section class="df-panel p-5">
        <div class="grid gap-4 lg:grid-cols-3">
            <label class="text-sm font-semibold text-slate-700">Scope <x-required-badge />
                <select name="scope_type" class="mt-1" required>
                    <option value="organization" @selected($oldScope === 'organization')>Organization</option>
                    <option value="branch" @selected($oldScope === 'branch')>Branch</option>
                    <option value="user" @selected($oldScope === 'user')>User entries</option>
                </select>
            </label>

            <label class="text-sm font-semibold text-slate-700">Organization target
                <select name="target_id" class="mt-1" data-wipe-target="organization">
                    @foreach($organizations as $organization)
                        <option value="{{ $organization->id }}" @selected((int) $oldTarget === $organization->id)>{{ $organization->name }}</option>
                    @endforeach
                </select>
            </label>

            <label class="text-sm font-semibold text-slate-700">Branch target
                <select name="target_id" class="mt-1" data-wipe-target="branch" disabled>
                    @foreach($branches as $branch)
                        <option value="{{ $branch->id }}" @selected((int) $oldTarget === $branch->id)>{{ $branch->name }}</option>
                    @endforeach
                </select>
            </label>

            <label class="text-sm font-semibold text-slate-700">User target
                <select name="target_id" class="mt-1" data-wipe-target="user" disabled>
                    @foreach($users as $staff)
                        <option value="{{ $staff->id }}" @selected((int) $oldTarget === $staff->id)>{{ $staff->name }} · {{ $staff->role?->name ?? 'No role' }} · {{ $staff->branch?->name ?? 'No branch' }}</option>
                    @endforeach
                </select>
            </label>
        </div>
    </section>

    <section class="df-panel overflow-hidden">
        <div class="border-b border-slate-200 px-5 py-4">
            <h2 class="font-semibold text-slate-950">Things To Wipe</h2>
            <p class="text-sm text-slate-500">Dependent rows are included automatically when they reference selected data.</p>
        </div>
        <div class="grid gap-3 p-5 md:grid-cols-2 xl:grid-cols-3">
            @foreach($categories as $key => $category)
                <label class="rounded-lg border border-slate-200 bg-white p-4 text-sm shadow-sm transition hover:border-brand-200 hover:bg-brand-50/40">
                    <span class="flex items-start gap-3">
                        <input type="checkbox" name="categories[]" value="{{ $key }}" class="mt-1" @checked(in_array($key, $oldCategories, true))>
                        <span>
                            <span class="block font-semibold text-slate-950">{{ $category['label'] }}</span>
                            <span class="mt-1 block leading-5 text-slate-500">{{ $category['description'] }}</span>
                        </span>
                    </span>
                </label>
            @endforeach
        </div>
    </section>

    @if($preview)
        <section class="df-panel overflow-hidden">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 px-5 py-4">
                <div>
                    <h2 class="font-semibold text-slate-950">Preview For {{ $preview['context']['label'] }}</h2>
                    <p class="text-sm text-slate-500">{{ $preview['total'] }} row{{ $preview['total'] === 1 ? '' : 's' }} will be removed across {{ count($preview['tables']) }} table{{ count($preview['tables']) === 1 ? '' : 's' }}.</p>
                </div>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="bg-slate-50 text-slate-600">
                        <tr><th class="p-3">Table</th><th>Category</th><th class="pr-3 text-right">Rows</th></tr>
                    </thead>
                    <tbody>
                        @forelse($preview['tables'] as $table => $details)
                            <tr class="border-t border-slate-200">
                                <td class="p-3 font-semibold text-slate-950">{{ $details['label'] }} <span class="font-normal text-slate-500">({{ $table }})</span></td>
                                <td>{{ $details['category'] }}</td>
                                <td class="pr-3 text-right font-semibold">{{ number_format($details['count']) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="3" class="p-8 text-center text-slate-500">No rows match this selection.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    @endif

    <section class="df-panel p-5">
        <label class="block text-sm font-semibold text-slate-700">Confirmation
            <input name="confirmation" value="{{ old('confirmation') }}" class="mt-1" placeholder="Type WIPE before executing">
        </label>
        <div class="mt-4 flex flex-wrap justify-end gap-2">
            <button name="action" value="preview" class="df-action-secondary">Preview Wipe</button>
            <button name="action" value="wipe" class="df-action-danger" data-confirm-title="Execute data wipe" data-confirm-message="This will permanently delete the previewed scoped data and dependent rows. Continue?" data-confirm-label="Execute wipe" data-confirm-tone="danger">Execute Wipe</button>
        </div>
    </section>
</form>

@endsection
