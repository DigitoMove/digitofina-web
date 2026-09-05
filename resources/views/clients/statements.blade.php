@extends('layouts.app')

@section('content')
<div class="mb-5 flex flex-wrap items-end justify-between gap-3">
    <div>
        <p class="text-sm font-semibold text-brand-700">{{ $client->full_name }} · {{ $client->client_number }}</p>
        <h1 class="df-title">{{ auth()->user()->isMember() ? 'My statements' : 'Client statements' }}</h1>
        <p class="df-muted">Choose an account and period to view or download its statement.</p>
    </div>
    <a class="df-action-secondary" href="{{ auth()->user()->isMember() ? route('member.dashboard') : route('clients.show', $client) }}">Back to dashboard</a>
</div>
@include('clients.partials.statement', ['preview' => false])
@endsection
