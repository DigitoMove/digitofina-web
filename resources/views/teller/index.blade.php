@extends('layouts.app')

@section('content')
<div class="mb-5 flex flex-wrap items-end justify-between gap-3">
    <div>
        <h1 class="df-title">Teller Drawer</h1>
        <p class="df-muted">Currency: Uganda Shilling - UGX</p>
    </div>
    <div class="df-panel flex flex-wrap items-center gap-4 px-4 py-3 text-sm">
        <span><span class="font-semibold">Drawer:</span> {{ $drawer?->name ?? 'No open drawer' }}</span>
        <span><span class="font-semibold">Balance:</span> {{ number_format($drawer?->current_balance ?? 0, 2) }} UGX</span>
    </div>
</div>

<div data-df-steps class="grid gap-5">
    <div class="grid gap-3 md:grid-cols-4">
        <button type="button" class="df-step" data-df-step="open"><span class="grid h-6 w-6 place-items-center rounded-full bg-white text-xs">1</span> Open Drawer</button>
        <button type="button" class="df-step" data-df-step="search"><span class="grid h-6 w-6 place-items-center rounded-full bg-white text-xs">2</span> Find Account</button>
        <button type="button" class="df-step" data-df-step="post"><span class="grid h-6 w-6 place-items-center rounded-full bg-white text-xs">3</span> Post Transaction</button>
        <button type="button" class="df-step" data-df-step="close"><span class="grid h-6 w-6 place-items-center rounded-full bg-white text-xs">4</span> Close & Reconcile</button>
    </div>

    <section data-df-step-panel="open" class="df-panel p-5">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h2 class="font-semibold text-slate-950">Drawer Opening</h2>
                <p class="df-muted">Start the cash day by selecting a drawer and confirming opening cash.</p>
            </div>
            <span class="df-badge {{ $session ? 'border-forest-500/20 bg-forest-50 text-forest-700' : 'border-sun-400/20 bg-sun-50 text-sun-600' }}">{{ $session ? 'Open session' : 'No session' }}</span>
        </div>
        @unless($session)
            <form method="POST" action="{{ route('teller.open') }}" class="mt-5 grid gap-3 md:grid-cols-[1fr_180px_auto]">
                @csrf
                <select name="teller_drawer_id">
                    @foreach($drawers as $openDrawer)
                        <option value="{{ $openDrawer->id }}">{{ $openDrawer->code }} {{ $openDrawer->name }}</option>
                    @endforeach
                </select>
                <input name="opening_balance" type="number" step="0.01" placeholder="Opening balance">
                <button class="df-action-primary">Open Drawer</button>
            </form>
        @else
            <div class="mt-5 df-soft-panel p-4 text-sm text-slate-700">Drawer is ready for deposits, withdrawals, and repayments. Continue to account search.</div>
            <button type="button" class="df-action-primary mt-4" data-df-step-next>Continue</button>
        @endunless
    </section>

    <section data-df-step-panel="search" class="df-panel p-5">
        <h2 class="font-semibold text-slate-950">Account Information</h2>
        <form method="GET" action="{{ route('teller.index') }}" class="mt-4 grid gap-4 md:grid-cols-[220px_1fr_auto]">
            <select name="account_type_id">
                <option>Account Type</option>
                @foreach($accountTypes as $type)
                    <option value="{{ $type->id }}">{{ $type->name }}</option>
                @endforeach
            </select>
            <input name="account_number" value="{{ request('account_number') }}" placeholder="Account Number">
            <button class="df-action-primary">Search</button>
        </form>
        <div class="mt-4 df-soft-panel flex flex-wrap items-center justify-between gap-3 p-4 text-sm">
            <span><span class="font-semibold">Account Name:</span> {{ $account?->account_name ?? 'Search an active account' }}</span>
            @if($account)
                <span class="font-semibold text-brand-800">{{ number_format($account->balance, 2) }} UGX</span>
            @endif
        </div>
        <div class="mt-4 flex gap-2">
            <button type="button" class="df-action-secondary" data-df-step-back>Back</button>
            <button type="button" class="df-action-primary" data-df-step-next>Continue</button>
        </div>
    </section>

    <section data-df-step-panel="post" class="df-panel p-5">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h2 class="font-semibold text-slate-950">Transaction Details</h2>
                <p class="df-muted">Post a cash transaction after confirming the drawer and account.</p>
            </div>
            <span class="df-badge">Maker controlled</span>
        </div>
        <form method="POST" action="{{ route('teller.post') }}" class="mt-4 grid gap-4 md:grid-cols-2">
            @csrf
            <input type="hidden" name="account_number" value="{{ $account?->account_number ?? request('account_number') }}">
            <label class="text-sm font-semibold text-slate-700">Transaction Type
                <select name="transaction_type_id" class="mt-1">
                    @foreach($transactionTypes as $type)
                        <option value="{{ $type->id }}">{{ $type->name }}</option>
                    @endforeach
                </select>
            </label>
            <label class="text-sm font-semibold text-slate-700">Cheque No
                <input name="cheque_number" class="mt-1">
            </label>
            <label class="text-sm font-semibold text-slate-700">Amount
                <input name="amount" type="number" step="0.01" class="mt-1">
            </label>
            <label class="text-sm font-semibold text-slate-700 md:col-span-2">Transaction Description
                <textarea name="description" class="mt-1"></textarea>
            </label>
            <div class="flex flex-wrap gap-2">
                <button type="button" class="df-action-secondary" data-df-step-back>Back</button>
                <button @disabled(!$session) class="df-action-primary disabled:pointer-events-none disabled:bg-slate-400">Post Transaction</button>
            </div>
        </form>
    </section>

    <section data-df-step-panel="close" class="grid gap-5 xl:grid-cols-[1fr_280px]">
        <div class="df-panel p-5">
            <h2 class="font-semibold text-slate-950">Close & Reconcile</h2>
            <p class="df-muted">Confirm counted cash before ending the drawer session.</p>
            <form method="POST" action="{{ route('teller.close') }}" class="mt-5 grid gap-3 md:grid-cols-[220px_auto]">
                @csrf
                <input name="closing_balance" type="number" step="0.01" value="{{ $drawer?->current_balance }}" placeholder="Closing balance">
                <button @disabled(!$session) class="df-action-danger disabled:pointer-events-none disabled:bg-slate-400">Close Drawer</button>
            </form>
        </div>

        <aside class="df-panel p-4">
            <h2 class="mb-3 font-semibold text-slate-950">Drawer Menu</h2>
            <div class="grid gap-2 text-sm">
                @foreach(['Account Details','View Signature','View Transactions','FX Teller','Cash From Vault','Cash To Vault'] as $button)
                    <button type="button" class="df-action-secondary justify-start">{{ $button }}</button>
                @endforeach
            </div>
        </aside>
    </section>
</div>
@endsection
