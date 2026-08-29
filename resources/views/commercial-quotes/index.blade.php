@extends('layouts.app')

@section('content')
<div class="mb-6 flex flex-wrap items-start justify-between gap-4">
    <div>
        <h1 class="text-2xl font-semibold text-slate-950">Billing & Quotes</h1>
        <p class="mt-1 text-sm text-slate-500">Request, issue, and pay DigitoFina subscription or one-time licence quotes.</p>
    </div>
    @if($isSuperAdmin)
        <div class="max-w-xl rounded-xl border border-violet-200 bg-violet-50 px-4 py-3 text-sm text-violet-950">
            <div class="font-semibold">Nylon Pay {{ $nylonPayConfigured ? 'is configured' : 'needs configuration' }}</div>
            <div class="mt-1 break-all text-xs">Webhook: {{ $webhookUrl }}</div>
        </div>
    @endif
</div>

@if(request('payment_return'))
    <div class="mb-5 rounded-xl border border-sky-200 bg-sky-50 px-4 py-3 text-sm text-sky-900">
        Payment processing has returned to DigitoFina. The quote will become paid after Nylon Pay sends a verified confirmation.
    </div>
@endif

@error('payment')
    <div class="mb-5 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">{{ $message }}</div>
@enderror

@if($isSuperAdmin)
    <details class="df-panel mb-6 overflow-hidden" @if(! $nylonPayConfigured) open @endif>
        <summary class="cursor-pointer px-5 py-4 font-semibold text-slate-950">Nylon Pay credentials</summary>
        <form method="POST" action="{{ route('nylon-pay.settings.update') }}" class="grid gap-4 border-t border-slate-200 p-5 md:grid-cols-2" autocomplete="off">
            @csrf
            @method('PUT')
            <p class="text-sm text-slate-600 md:col-span-2">Credentials are encrypted at rest and never shown again. Leave a secret blank to keep its current value.</p>
            <label class="text-sm font-semibold text-slate-700">API key
                <input name="api_key" type="password" autocomplete="new-password" class="mt-1" placeholder="{{ $nylonPayCredential?->masked('api_key') ?: 'npk_live_...' }}">
            </label>
            <label class="text-sm font-semibold text-slate-700">API secret
                <input name="api_secret" type="password" autocomplete="new-password" class="mt-1" placeholder="{{ $nylonPayCredential?->masked('api_secret') ?: 'nps_live_...' }}">
            </label>
            <label class="text-sm font-semibold text-slate-700">Webhook secret
                <input name="webhook_secret" type="password" autocomplete="new-password" class="mt-1" placeholder="{{ $nylonPayCredential?->masked('webhook_secret') ?: 'Webhook signing secret' }}">
            </label>
            <label class="text-sm font-semibold text-slate-700">API base URL
                <input name="base_url" type="url" value="{{ old('base_url', $nylonPayCredential?->base_url ?: config('services.nylon_pay.base_url')) }}" class="mt-1" required>
            </label>
            <div class="md:col-span-2">
                <button class="df-action-primary">Save encrypted credentials</button>
            </div>
        </form>
    </details>
@endif

@unless($isSuperAdmin)
    <section class="df-panel mb-6 p-5">
        <h2 class="text-lg font-semibold text-slate-950">Request a quote</h2>
        <p class="mt-1 text-sm text-slate-500">Tell the Super Admin what your organization needs. You will be notified when the priced quote is ready.</p>
        <form method="POST" action="{{ route('commercial-quotes.store') }}" class="mt-4 grid gap-4 md:grid-cols-2" autocomplete="off">
            @csrf
            <label class="text-sm font-semibold text-slate-700">Pricing type <x-required-badge />
                <select name="billing_type" class="mt-1" required>
                    <option value="subscription" @selected(old('billing_type') === 'subscription')>Monthly subscription</option>
                    <option value="one_time" @selected(old('billing_type') === 'one_time')>One-time licence</option>
                </select>
            </label>
            <label class="text-sm font-semibold text-slate-700">Subscription months
                <input name="subscription_months" type="number" min="1" max="36" value="{{ old('subscription_months', 1) }}" class="mt-1">
                <span class="mt-1 block text-xs font-normal text-slate-500">Ignored for a one-time licence.</span>
            </label>
            <label class="text-sm font-semibold text-slate-700 md:col-span-2">What should the quote cover? <x-required-badge />
                <textarea name="requested_scope" rows="5" class="mt-1" required placeholder="Branches, expected users, portfolio size, onboarding, training, integrations, and any special requirements.">{{ old('requested_scope') }}</textarea>
            </label>
            <div class="md:col-span-2"><button class="df-action-primary">Send quote request</button></div>
        </form>
    </section>
@endunless

<div class="grid gap-5">
    @forelse($quotes as $quote)
        <article class="df-panel overflow-hidden">
            <div class="flex flex-wrap items-start justify-between gap-3 border-b border-slate-200 px-5 py-4">
                <div>
                    <div class="flex flex-wrap items-center gap-2">
                        <h2 class="font-semibold text-slate-950">{{ $quote->quote_number }}</h2>
                        <span class="df-badge">{{ str($quote->status)->headline() }}</span>
                    </div>
                    <p class="mt-1 text-sm text-slate-500">{{ $quote->organization->name }} · Requested {{ $quote->created_at->format('d M Y, H:i') }}</p>
                </div>
                @if($quote->amount !== null)
                    <div class="text-right">
                        <div class="text-xl font-bold text-slate-950">{{ $quote->currency }} {{ number_format((float) $quote->amount) }}</div>
                        <div class="text-xs text-slate-500">{{ $quote->billing_type === 'subscription' ? ($quote->subscription_months.' month subscription') : 'One-time licence' }}</div>
                    </div>
                @endif
            </div>
            <div class="grid gap-5 p-5 lg:grid-cols-[1fr_1.2fr]">
                <div>
                    <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Requested scope</div>
                    <p class="mt-2 whitespace-pre-line text-sm leading-6 text-slate-700">{{ $quote->requested_scope }}</p>
                    @if($quote->terms)
                        <div class="mt-4 text-xs font-semibold uppercase tracking-wide text-slate-500">Quote terms</div>
                        <p class="mt-2 whitespace-pre-line text-sm leading-6 text-slate-700">{{ $quote->terms }}</p>
                    @endif
                    @if($quote->valid_until)
                        <p class="mt-3 text-xs font-medium text-slate-500">Valid through {{ $quote->valid_until->format('d M Y') }}</p>
                    @endif
                </div>

                @if($isSuperAdmin && in_array($quote->status, ['requested', 'issued'], true))
                    <form method="POST" action="{{ route('commercial-quotes.issue', $quote) }}" class="grid gap-3 rounded-xl border border-slate-200 bg-slate-50 p-4 md:grid-cols-2">
                        @csrf
                        @method('PUT')
                        <label class="text-sm font-semibold text-slate-700">Pricing type
                            <select name="billing_type" class="mt-1" required>
                                <option value="subscription" @selected($quote->billing_type === 'subscription')>Subscription</option>
                                <option value="one_time" @selected($quote->billing_type === 'one_time')>One-time licence</option>
                            </select>
                        </label>
                        <label class="text-sm font-semibold text-slate-700">Months
                            <input name="subscription_months" type="number" min="1" max="36" value="{{ $quote->subscription_months ?: 1 }}" class="mt-1">
                        </label>
                        <label class="text-sm font-semibold text-slate-700">Amount (UGX)
                            <input name="amount" type="number" min="500" step="100" value="{{ old('amount', $quote->amount) }}" class="mt-1" required>
                        </label>
                        <label class="text-sm font-semibold text-slate-700">Valid until
                            <input name="valid_until" type="date" min="{{ today()->toDateString() }}" value="{{ old('valid_until', $quote->valid_until?->toDateString() ?: today()->addDays(14)->toDateString()) }}" class="mt-1" required>
                        </label>
                        <input type="hidden" name="currency" value="UGX">
                        <label class="text-sm font-semibold text-slate-700 md:col-span-2">Terms
                            <textarea name="terms" rows="4" class="mt-1" placeholder="Onboarding, support, renewal, training, and implementation terms.">{{ old('terms', $quote->terms) }}</textarea>
                        </label>
                        <div class="md:col-span-2"><button class="df-action-primary">{{ $quote->status === 'issued' ? 'Update quote' : 'Issue quote' }}</button></div>
                    </form>
                @elseif(! $isSuperAdmin && $quote->isPayable())
                    <div class="flex flex-col justify-center rounded-xl border border-emerald-200 bg-emerald-50 p-5 text-emerald-950">
                        <div class="font-semibold">This quote is ready for payment.</div>
                        <p class="mt-1 text-sm">Nylon Pay will open a secure hosted checkout. DigitoFina activates your paid access only after the signed webhook is verified.</p>
                        <form method="POST" action="{{ route('commercial-quotes.pay', $quote) }}" class="mt-4">
                            @csrf
                            <button class="df-action-primary">Pay securely with Nylon Pay</button>
                        </form>
                    </div>
                @elseif($quote->status === 'paid')
                    <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-5 text-sm text-emerald-950">
                        <div class="font-semibold">Payment confirmed {{ $quote->paid_at?->format('d M Y, H:i') }}</div>
                        @if($quote->subscription?->current_period_ends_at)
                            <div class="mt-1">Current subscription period ends {{ $quote->subscription->current_period_ends_at->format('d M Y') }}.</div>
                        @else
                            <div class="mt-1">One-time licence activated.</div>
                        @endif
                    </div>
                @else
                    <div class="rounded-xl border border-slate-200 bg-slate-50 p-5 text-sm text-slate-600">
                        {{ $quote->status === 'requested' ? 'Waiting for the Super Admin to prepare the price.' : 'Payment status: '.str($quote->nylon_payment_status ?: $quote->status)->headline() }}
                    </div>
                @endif
            </div>
        </article>
    @empty
        <div class="df-panel p-8 text-center text-sm text-slate-500">No quote requests yet.</div>
    @endforelse
</div>

<div class="mt-5">{{ $quotes->links() }}</div>
@endsection
