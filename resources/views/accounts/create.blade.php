@extends('layouts.app')

@section('content')
<h1 class="mb-5 text-2xl font-semibold">Open Account</h1>
<form method="POST" action="{{ route('accounts.store') }}" class="rounded border bg-white p-5">
    @csrf
    <div class="grid gap-4 md:grid-cols-2">
        <label class="text-sm font-medium">Client <x-required-badge />
            <select name="client_id" class="mt-1 w-full rounded border-slate-300" required>
                @foreach($clients as $client)
                    <option value="{{ $client->id }}" @selected($selectedClient == $client->id)>{{ $client->client_number }} - {{ $client->full_name }}</option>
                @endforeach
            </select>
        </label>
        <label class="text-sm font-medium">Branch <x-required-badge />
            <select name="branch_id" class="mt-1 w-full rounded border-slate-300" required>
                @foreach($branches as $branch)
                    <option value="{{ $branch->id }}">{{ $branch->name }}</option>
                @endforeach
            </select>
        </label>
        <label class="text-sm font-medium">Account type <x-required-badge />
            <select name="account_type_id" class="mt-1 w-full rounded border-slate-300" required>
                @foreach($types as $type)
                    <option value="{{ $type->id }}">{{ $type->name }}</option>
                @endforeach
            </select>
        </label>
        <label class="text-sm font-medium">Account name <x-required-badge />
            <input name="account_name" class="mt-1 w-full rounded border-slate-300" required>
        </label>
        @if($canMarkOrganizationWide)
            <label class="flex items-start gap-3 rounded border border-slate-200 p-3 text-sm font-medium md:col-span-2">
                <input type="hidden" name="is_organization_wide" value="0">
                <input type="checkbox" name="is_organization_wide" value="1" class="mt-1">
                <span><span class="block">Available across all organization branches</span><span class="font-normal text-slate-500">Authorized cashiers can deposit and withdraw this savings account from any branch.</span></span>
            </label>
        @endif
    </div>
    <button class="mt-6 rounded bg-blue-700 px-4 py-2 text-sm font-semibold text-white">Open account</button>
</form>
@endsection
