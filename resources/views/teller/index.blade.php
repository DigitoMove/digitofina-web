@extends('layouts.app')
@section('content')
<div class="mb-4 flex flex-wrap items-center justify-between gap-3">
    <div><h1 class="text-2xl font-semibold">Teller Drawer</h1><p class="text-sm text-slate-500">Currency: Uganda Shilling - UGX</p></div>
    <div class="rounded border bg-white px-4 py-3 text-sm shadow-sm"><span class="font-semibold">Drawer Name:</span> {{ $drawer?->name ?? 'No open drawer' }} <span class="ml-4 font-semibold">Balance:</span> {{ number_format($drawer?->current_balance ?? 0, 2) }} UGX</div>
</div>

@unless($session)
<form method="POST" action="{{ route('teller.open') }}" class="mb-5 grid gap-3 rounded border border-amber-200 bg-amber-50 p-4 md:grid-cols-[1fr_180px_auto]">@csrf<select name="teller_drawer_id" class="rounded border-slate-300">@foreach($drawers as $openDrawer)<option value="{{ $openDrawer->id }}">{{ $openDrawer->code }} {{ $openDrawer->name }}</option>@endforeach</select><input name="opening_balance" type="number" step="0.01" class="rounded border-slate-300" placeholder="Opening balance"><button class="rounded bg-amber-700 px-4 py-2 text-sm font-semibold text-white">Open Drawer</button></form>
@endunless

<div class="grid gap-5 xl:grid-cols-[1fr_260px]">
    <div class="grid gap-5">
        <section class="rounded border bg-white p-5 shadow-sm">
            <h2 class="mb-4 font-semibold">Account Information</h2>
            <form method="GET" action="{{ route('teller.index') }}" class="grid gap-4 md:grid-cols-[220px_1fr_auto]">
                <select name="account_type_id" class="rounded border-slate-300"><option>Account Type</option>@foreach($accountTypes as $type)<option value="{{ $type->id }}">{{ $type->name }}</option>@endforeach</select>
                <input name="account_number" value="{{ request('account_number') }}" class="rounded border-slate-300" placeholder="Account Number">
                <button class="rounded bg-blue-700 px-4 py-2 text-sm font-semibold text-white">Search</button>
            </form>
            <div class="mt-4 rounded border border-slate-200 bg-slate-50 p-4 text-sm"><span class="font-semibold">Account Name:</span> {{ $account?->account_name ?? 'Search an active account' }} @if($account)<span class="ml-4 text-slate-500">{{ number_format($account->balance, 2) }} UGX</span>@endif</div>
        </section>

        <section class="rounded border bg-white p-5 shadow-sm">
            <h2 class="mb-4 font-semibold">Transaction Details</h2>
            <form method="POST" action="{{ route('teller.post') }}" class="grid gap-4 md:grid-cols-2">@csrf
                <input type="hidden" name="account_number" value="{{ $account?->account_number ?? request('account_number') }}">
                <label class="text-sm font-medium">Transaction Type<select name="transaction_type_id" class="mt-1 w-full rounded border-slate-300">@foreach($transactionTypes as $type)<option value="{{ $type->id }}">{{ $type->name }}</option>@endforeach</select></label>
                <label class="text-sm font-medium">Cheque No<input name="cheque_number" class="mt-1 w-full rounded border-slate-300"></label>
                <label class="text-sm font-medium">Amount<input name="amount" type="number" step="0.01" class="mt-1 w-full rounded border-slate-300"></label>
                <label class="text-sm font-medium md:col-span-2">Transaction Description<textarea name="description" class="mt-1 w-full rounded border-slate-300"></textarea></label>
                <button @disabled(!$session) class="rounded bg-blue-700 px-4 py-2 text-sm font-semibold text-white disabled:bg-slate-400">Post Transaction</button>
            </form>
        </section>
    </div>

    <aside class="rounded border bg-white p-4 shadow-sm">
        <h2 class="mb-3 font-semibold">Drawer Menu</h2>
        <div class="grid gap-2 text-sm">
            @foreach(['Account Details','View Signature','View Transactions','FX Teller','Cash From Vault','Cash To Vault'] as $button)
                <button class="rounded border border-slate-300 px-3 py-2 text-left font-medium hover:bg-slate-50">{{ $button }}</button>
            @endforeach
            <form method="POST" action="{{ route('teller.close') }}" class="mt-2 grid gap-2">@csrf<input name="closing_balance" type="number" step="0.01" value="{{ $drawer?->current_balance }}" class="rounded border-slate-300"><button @disabled(!$session) class="rounded bg-red-700 px-3 py-2 text-left font-semibold text-white disabled:bg-slate-400">Close Drawer</button></form>
        </div>
    </aside>
</div>
@endsection
