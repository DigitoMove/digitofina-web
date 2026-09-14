@extends('layouts.app')

@section('content')
<div class="mb-6">
    <p class="text-sm font-semibold uppercase tracking-wide text-brand-700">{{ $organization->name }}</p>
    <h1 class="mt-1 text-2xl font-semibold text-slate-950">Company Mobile Money</h1>
    <p class="mt-1 text-sm text-slate-500">These company credentials receive this organization’s client loan repayments and savings deposits only. They are never used for company quote payments.</p>
</div>

<section class="df-panel max-w-4xl p-5">
    <div class="rounded-xl border {{ $configured ? 'border-emerald-200 bg-emerald-50' : 'border-amber-200 bg-amber-50' }} p-4 text-sm">
        <div class="font-semibold">{{ $configured ? 'Client collections are configured' : 'Credentials are required' }}</div>
        @if($webhookUrl)<div class="mt-2 break-all"><span class="font-semibold">Webhook URL:</span> {{ $webhookUrl }}</div>@endif
    </div>
    <form method="POST" action="{{ route('organization.nylon-pay.update') }}" class="mt-5 grid gap-4 md:grid-cols-2" autocomplete="off">
        @csrf
        @method('PUT')
        <p class="text-sm text-slate-600 md:col-span-2">Secrets are encrypted and never displayed again. Leave an existing secret blank to keep it unchanged. Configure platform Mobile Money credentials separately for DigitoFina quote billing.</p>
        <label class="text-sm font-semibold text-slate-700">API key<input name="api_key" type="password" autocomplete="new-password" class="mt-1" placeholder="{{ $credential?->masked('api_key') ?: 'npk_live_...' }}"></label>
        <label class="text-sm font-semibold text-slate-700">API secret<input name="api_secret" type="password" autocomplete="new-password" class="mt-1" placeholder="{{ $credential?->masked('api_secret') ?: 'nps_live_...' }}"></label>
        <label class="text-sm font-semibold text-slate-700">Webhook secret<input name="webhook_secret" type="password" autocomplete="new-password" class="mt-1" placeholder="{{ $credential?->masked('webhook_secret') ?: 'Webhook signing secret' }}"></label>
        <label class="text-sm font-semibold text-slate-700">API base URL<input name="base_url" type="url" value="{{ old('base_url', $credential?->base_url ?: config('services.nylon_pay.base_url')) }}" class="mt-1" required></label>
        <div class="md:col-span-2"><button class="df-action-primary">Save company Mobile Money credentials</button></div>
    </form>
</section>
@endsection
