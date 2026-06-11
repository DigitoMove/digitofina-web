@extends('layouts.app')

@section('content')
<div class="mb-5 flex flex-wrap items-end justify-between gap-3">
    <div>
        <h1 class="text-2xl font-semibold text-slate-950">Clients</h1>
        <p class="text-sm text-slate-500">Client registry, KYC status, risk ratings, branch ownership, and officer assignment.</p>
    </div>
    <div class="flex gap-2">
        <a href="{{ route('client-groups.index') }}" class="rounded border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700">Groups</a>
        @can('create', \App\Models\Client::class)
            <a href="{{ route('clients.create') }}" class="rounded bg-blue-700 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-800">Register Client</a>
        @endcan
    </div>
</div>

<form class="mb-4 grid gap-3 lg:grid-cols-[1fr_11rem_11rem_12rem_12rem_auto]">
    <input name="search" value="{{ request('search') }}" class="rounded border-slate-300" placeholder="Search name, client number, phone or national ID">
    <select name="client_type" class="rounded border-slate-300"><option value="">All types</option>@foreach(['individual', 'group', 'business', 'institution'] as $type)<option value="{{ $type }}" @selected(request('client_type') === $type)>{{ str($type)->headline() }}</option>@endforeach</select>
    <select name="status" class="rounded border-slate-300"><option value="">All status</option>@foreach(['pending','active','dormant','suspended','blacklisted','closed'] as $status)<option value="{{ $status }}" @selected(request('status') === $status)>{{ str($status)->headline() }}</option>@endforeach</select>
    @if($canViewAllBranches)
        <select name="branch_id" class="rounded border-slate-300"><option value="">All branches</option>@foreach($branches as $branch)<option value="{{ $branch->id }}" @selected(request('branch_id') == $branch->id)>{{ $branch->name }}</option>@endforeach</select>
    @else
        <select class="rounded border-slate-300" disabled>@foreach($branches as $branch)<option>{{ $branch->name }}</option>@endforeach</select>
    @endif
    <select name="officer_id" class="rounded border-slate-300"><option value="">All officers</option>@foreach($officers as $officer)<option value="{{ $officer->id }}" @selected(request('officer_id') == $officer->id)>{{ $officer->name }}</option>@endforeach</select>
    <button class="rounded bg-slate-800 px-4 py-2 text-sm font-semibold text-white">Filter</button>
</form>

<div class="overflow-hidden rounded border border-slate-200 bg-white shadow-sm">
    <table class="w-full text-left text-sm">
        <thead class="bg-slate-50 text-slate-600"><tr><th class="p-3">Client</th><th>Phone</th><th>Branch</th><th>KYC</th><th>Risk</th><th>Status</th><th></th></tr></thead>
        <tbody>
            @forelse($clients as $client)
                <tr class="border-t">
                    <td class="p-3 font-medium text-slate-950">{{ $client->client_number }}<div class="text-slate-500">{{ $client->full_name ?: $client->business_name }} · {{ str($client->client_type)->headline() }}</div></td>
                    <td>{{ $client->phone }}</td>
                    <td>{{ $client->branch->name }}</td>
                    <td><span class="rounded px-2 py-1 text-xs font-semibold {{ $client->kyc_status === 'verified' ? 'bg-emerald-50 text-emerald-700' : 'bg-amber-50 text-amber-700' }}">{{ str($client->kyc_status)->headline() }}</span></td>
                    <td>{{ $client->risk_rating ? str($client->risk_rating)->headline() : 'Unrated' }}</td>
                    <td><span class="rounded bg-slate-100 px-2 py-1 text-xs font-semibold">{{ str($client->status)->headline() }}</span></td>
                    <td>
                        {{-- @can('view', $client) --}}
                            <a class="font-medium text-blue-700" href="{{ route('clients.show', $client) }}">View</a>
                        {{-- @endcan --}}
                    </td>
                </tr>
            @empty
                <tr>
                    <td class="p-6 text-center text-slate-500" colspan="7">
                        {{ $hasClientSearch || $canBrowseClientList ? 'No clients found for this search.' : 'Search for a client by name, client number, phone, national ID, or use a filter to show branch results.' }}
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>
@if($hasClientSearch || $canBrowseClientList)
    <div class="mt-4">{{ $clients->links() }}</div>
@endif
@endsection
