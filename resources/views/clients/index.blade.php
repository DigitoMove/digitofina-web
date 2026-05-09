@extends('layouts.app')
@section('content')
<div class="mb-5 flex flex-wrap items-center justify-between gap-3"><h1 class="text-2xl font-semibold">Clients</h1><a href="{{ route('clients.create') }}" class="rounded bg-blue-700 px-4 py-2 text-sm font-semibold text-white">Register Client</a></div>
<form class="mb-4"><input name="search" value="{{ request('search') }}" class="w-full rounded border-slate-300" placeholder="Global client search"></form>
<div class="overflow-hidden rounded border border-slate-200 bg-white shadow-sm"><table class="w-full text-left text-sm"><thead class="bg-slate-50 text-slate-600"><tr><th class="p-3">Client</th><th>Phone</th><th>Branch</th><th>Status</th><th></th></tr></thead><tbody>@foreach($clients as $client)<tr class="border-t"><td class="p-3 font-medium">{{ $client->client_number }}<div class="text-slate-500">{{ $client->full_name }}</div></td><td>{{ $client->phone }}</td><td>{{ $client->branch->name }}</td><td>{{ ucfirst($client->status) }}</td><td><a class="text-blue-700" href="{{ route('clients.show', $client) }}">View</a></td></tr>@endforeach</tbody></table></div>
<div class="mt-4">{{ $clients->links() }}</div>
@endsection
