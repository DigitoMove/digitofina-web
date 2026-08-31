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

    <section class="df-panel mb-6 p-5">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <p class="text-xs font-semibold uppercase tracking-wide text-violet-700">Super Admin action</p>
                <h2 class="mt-1 text-lg font-semibold text-slate-950">Create and send a quote</h2>
                <p class="mt-1 text-sm text-slate-500">Choose an organization, set the agreed price and terms, then send the payable quote directly to its administrators.</p>
            </div>
            <span class="rounded-lg bg-violet-100 px-3 py-2 text-xs font-semibold text-violet-800">No request required</span>
        </div>
        <form method="POST" action="{{ route('commercial-quotes.organizations.store') }}" class="mt-4 grid gap-4 md:grid-cols-2 xl:grid-cols-4" autocomplete="off">
            @csrf
            <label class="text-sm font-semibold text-slate-700">Organization <x-required-badge />
                <select name="organization_id" class="mt-1" required>
                    <option value="">Select organization</option>
                    @foreach($organizations as $organization)<option value="{{ $organization->id }}" @selected((int) old('organization_id') === $organization->id)>{{ $organization->name }}</option>@endforeach
                </select>
            </label>
            <label class="text-sm font-semibold text-slate-700">Pricing type <x-required-badge />
                <select name="billing_type" class="mt-1" required>
                    <option value="subscription" @selected(old('billing_type') === 'subscription')>Monthly subscription</option>
                    <option value="one_time" @selected(old('billing_type') === 'one_time')>One-time licence</option>
                </select>
            </label>
            <label class="text-sm font-semibold text-slate-700">Amount (UGX) <x-required-badge /><input name="amount" type="number" min="500" step="100" value="{{ old('amount', 200000) }}" class="mt-1" required></label>
            <label class="text-sm font-semibold text-slate-700">Subscription months<input name="subscription_months" type="number" min="1" max="36" value="{{ old('subscription_months', 1) }}" class="mt-1"><span class="mt-1 block text-xs font-normal text-slate-500">Ignored for a one-time licence.</span></label>
            <label class="text-sm font-semibold text-slate-700">Valid until <x-required-badge /><input name="valid_until" type="date" min="{{ today()->toDateString() }}" value="{{ old('valid_until', today()->addDays(14)->toDateString()) }}" class="mt-1" required></label>
            <input type="hidden" name="currency" value="UGX">
            <label class="text-sm font-semibold text-slate-700 md:col-span-2 xl:col-span-3">Scope and requirements <x-required-badge /><textarea name="requested_scope" rows="3" class="mt-1" required placeholder="Branches, users, onboarding, training, integrations, and implementation requirements.">{{ old('requested_scope') }}</textarea></label>
            <label class="text-sm font-semibold text-slate-700 md:col-span-2 xl:col-span-4">Agreed terms<textarea name="terms" rows="3" class="mt-1" placeholder="Payment terms, onboarding, support, renewal, training, and implementation terms.">{{ old('terms') }}</textarea></label>
            <div class="md:col-span-2 xl:col-span-4"><button class="df-action-primary">Create and send quote</button></div>
        </form>
    </section>

    <details class="df-panel mb-6 overflow-hidden">
        <summary class="cursor-pointer px-5 py-4 font-semibold text-slate-950">Activate a plan without creating a quote</summary>
        <div class="border-t border-slate-200 p-5">
            <p class="text-sm text-slate-500">Use this only when access was granted administratively or payment was already received outside DigitoFina.</p>
            <form method="POST" action="{{ route('organization-subscriptions.store') }}" class="mt-4 grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                @csrf
                <label class="text-sm font-semibold text-slate-700">Organization <x-required-badge />
                    <select name="organization_id" class="mt-1" required><option value="">Select organization</option>@foreach($organizations as $organization)<option value="{{ $organization->id }}">{{ $organization->name }}</option>@endforeach</select>
                </label>
                <label class="text-sm font-semibold text-slate-700">Plan <x-required-badge /><select name="billing_type" class="mt-1" required><option value="subscription">Monthly subscription</option><option value="one_time">One-time licence</option></select></label>
                <label class="text-sm font-semibold text-slate-700">Basis <x-required-badge /><select name="assignment_basis" class="mt-1" required><option value="assigned">Assigned by DigitoFina</option><option value="paid">Already paid externally</option></select></label>
                <label class="text-sm font-semibold text-slate-700">Amount (UGX) <x-required-badge /><input name="amount" type="number" min="0" step="100" value="200000" class="mt-1" required></label>
                <label class="text-sm font-semibold text-slate-700">Subscription months<input name="subscription_months" type="number" min="1" max="120" value="1" class="mt-1"></label>
                <label class="text-sm font-semibold text-slate-700">Starts on <x-required-badge /><input name="starts_at" type="date" value="{{ today()->toDateString() }}" class="mt-1" required></label>
                <label class="text-sm font-semibold text-slate-700 md:col-span-2">Notes<textarea name="notes" rows="2" class="mt-1" placeholder="Plan terms, payment reference, or reason for assignment."></textarea></label>
                <div class="md:col-span-2 xl:col-span-4"><button class="df-action-primary">Assign and activate plan</button></div>
            </form>
        </div>
    </details>
@endif

@if($billingOrganization)
    <section class="mb-6 grid gap-5 lg:grid-cols-2">
        <article class="df-panel p-5">
            <p class="text-xs font-semibold uppercase tracking-wide text-brand-700">Current DigitoFina plan</p>
            @if($currentSubscription)
                @php
                    $planSource = match($currentSubscription->activation_source) {
                        'admin_assignment' => 'Assigned by DigitoFina',
                        'manual_payment' => 'Paid externally',
                        default => 'Paid via Nylon Pay',
                    };
                @endphp
                <div class="mt-3 flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h2 class="text-xl font-semibold text-slate-950">{{ $currentSubscription->billing_type === 'one_time' ? 'One-time licence' : 'Monthly subscription' }}</h2>
                        <p class="mt-1 text-sm text-slate-500">{{ $planSource }} · {{ str($currentSubscription->status)->headline() }}</p>
                    </div>
                    <span class="rounded-lg bg-emerald-100 px-3 py-2 text-sm font-semibold text-emerald-800">{{ str($currentSubscription->payment_status)->headline() }}</span>
                </div>
                <dl class="mt-5 grid grid-cols-2 gap-3 text-sm">
                    <div class="df-soft-panel p-3"><dt class="text-slate-500">Plan amount</dt><dd class="mt-1 font-semibold">UGX {{ number_format((float) $currentSubscription->amount) }}</dd></div>
                    <div class="df-soft-panel p-3"><dt class="text-slate-500">Started</dt><dd class="mt-1 font-semibold">{{ $currentSubscription->starts_at->format('d M Y') }}</dd></div>
                    <div class="df-soft-panel col-span-2 p-3"><dt class="text-slate-500">Access period</dt><dd class="mt-1 font-semibold">{{ $currentSubscription->current_period_ends_at ? 'Through '.$currentSubscription->current_period_ends_at->format('d M Y') : 'Permanent one-time licence' }}</dd></div>
                </dl>
            @else
                <p class="mt-3 text-sm text-amber-800">No paid or assigned plan is active for this organization yet.</p>
            @endif
        </article>

        <article class="df-panel p-5">
            <p class="text-xs font-semibold uppercase tracking-wide text-violet-700">Required annual hosting</p>
            <div class="mt-3 flex flex-wrap items-start justify-between gap-3">
                <div><h2 class="text-xl font-semibold text-slate-950">UGX {{ number_format((float) $hostingFee->amount) }} per year</h2><p class="mt-1 text-sm text-slate-500">{{ $hostingFee->period_starts_on->format('d M Y') }} – {{ $hostingFee->period_ends_on->format('d M Y') }}</p></div>
                <span class="rounded-lg {{ $hostingFee->status === 'paid' ? 'bg-emerald-100 text-emerald-800' : 'bg-amber-100 text-amber-900' }} px-3 py-2 text-sm font-semibold">{{ str($hostingFee->status)->headline() }}</span>
            </div>
            <p class="mt-4 text-sm text-slate-600">Due {{ $hostingFee->due_on->format('d M Y') }}. This charge covers hosting, infrastructure, backups and service availability.</p>
            @if($hostingFee->status === 'paid')
                <p class="mt-3 text-sm font-semibold text-emerald-700">Paid {{ $hostingFee->paid_at?->format('d M Y, H:i') }}</p>
            @elseif(! $isSuperAdmin && $hostingFee->quote?->isPayable())
                <form method="POST" action="{{ route('commercial-quotes.pay', $hostingFee->quote) }}" class="mt-4">@csrf<button class="df-action-primary">Pay annual hosting with Nylon Pay</button></form>
            @elseif($isSuperAdmin)
                <form method="POST" action="{{ route('organization-hosting-fees.paid', $hostingFee) }}" class="mt-4 grid gap-3 sm:grid-cols-[1fr_auto]">
                    @csrf @method('PUT')
                    <input name="provider_reference" placeholder="External payment reference (optional)">
                    <button class="df-action-primary">Mark hosting paid</button>
                </form>
            @endif
        </article>
    </section>
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

<section class="mb-4">
    <h2 class="text-lg font-semibold text-slate-950">{{ $isSuperAdmin ? 'Organization quote requests and sent quotes' : 'Your quote requests and responses' }}</h2>
    <p class="mt-1 text-sm text-slate-500">{{ $isSuperAdmin ? 'Hosting fees and direct plan assignments are managed separately and do not appear here.' : 'Track requests, agreed pricing, payment, and activation here.' }}</p>
</section>

<div class="grid gap-5">
    @forelse($quotes as $quote)
        <article class="df-panel overflow-hidden">
            <div class="flex flex-wrap items-start justify-between gap-3 border-b border-slate-200 px-5 py-4">
                <div>
                    <div class="flex flex-wrap items-center gap-2">
                        <h2 class="font-semibold text-slate-950">{{ $quote->quote_number }}</h2>
                        <span class="df-badge">{{ str($quote->status)->headline() }}</span>
                    </div>
                    <p class="mt-1 text-sm text-slate-500">{{ $quote->organization->name }} · {{ $quote->requester ? 'Requested by '.$quote->requester->name : 'Created by DigitoFina' }} · {{ $quote->created_at->format('d M Y, H:i') }}</p>
                </div>
                @if($quote->amount !== null)
                    <div class="text-right">
                        <div class="text-xl font-bold text-slate-950">{{ $quote->currency }} {{ number_format((float) $quote->amount) }}</div>
                        <div class="text-xs text-slate-500">{{ match($quote->billing_type) { 'subscription' => $quote->subscription_months.' month subscription', 'hosting' => 'Annual hosting fee', default => 'One-time licence' } }}</div>
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
                        <label class="text-sm font-semibold text-slate-700">Agreed amount (UGX)
                            <input name="amount" type="number" min="500" step="100" value="{{ old('amount', $quote->amount) }}" class="mt-1" required>
                        </label>
                        <label class="text-sm font-semibold text-slate-700">Valid until
                            <input name="valid_until" type="date" min="{{ today()->toDateString() }}" value="{{ old('valid_until', $quote->valid_until?->toDateString() ?: today()->addDays(14)->toDateString()) }}" class="mt-1" required>
                        </label>
                        <input type="hidden" name="currency" value="UGX">
                        <label class="text-sm font-semibold text-slate-700 md:col-span-2">Terms
                            <textarea name="terms" rows="4" class="mt-1" placeholder="Onboarding, support, renewal, training, and implementation terms.">{{ old('terms', $quote->terms) }}</textarea>
                        </label>
                        <div class="md:col-span-2"><button class="df-action-primary">{{ $quote->status === 'issued' ? 'Update and resend quote' : 'Set agreed price and send quote' }}</button></div>
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
                        @if($quote->billing_type === 'hosting')
                            <div class="mt-1">Annual hosting fee confirmed for {{ $quote->hostingFee?->period_starts_on?->format('d M Y') }} – {{ $quote->hostingFee?->period_ends_on?->format('d M Y') }}.</div>
                        @elseif($quote->subscription?->current_period_ends_at)
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
