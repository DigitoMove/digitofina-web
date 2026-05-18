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
    </div>
    <button class="mt-6 rounded bg-blue-700 px-4 py-2 text-sm font-semibold text-white">Open account</button>
</form>
@endsection
