@extends('layouts.app')

@section('content')
<div class="mb-5 flex flex-wrap items-center justify-between gap-3">
    <div>
        <h1 class="text-2xl font-semibold text-slate-950">{{ $isCashier ? "Today's Collection Calls" : 'Due Repayments' }}</h1>
        <p class="text-sm text-slate-500">Amounts due on {{ $date->format('d M Y') }} plus every unpaid installment from earlier dates.</p>
    </div>
    <a href="{{ route('dashboard') }}" class="rounded border px-4 py-2 text-sm font-semibold">Back to Dashboard</a>
</div>

<div class="mb-4 grid gap-3 sm:grid-cols-3">
    <div class="rounded-lg border border-violet-200 bg-violet-50 p-4">
        <div class="text-xs font-semibold uppercase tracking-wide text-violet-700">Due on selected date</div>
        <div class="mt-2 text-xl font-semibold text-violet-950">UGX {{ number_format($todayAmount, 2) }}</div>
        <div class="text-sm text-violet-700">{{ $todayInstallmentCount }} installment{{ $todayInstallmentCount === 1 ? '' : 's' }}</div>
    </div>
    <div class="rounded-lg border border-red-200 bg-red-50 p-4">
        <div class="text-xs font-semibold uppercase tracking-wide text-red-700">Earlier arrears</div>
        <div class="mt-2 text-xl font-semibold text-red-950">UGX {{ number_format($arrearsAmount, 2) }}</div>
        <div class="text-sm text-red-700">{{ $arrearsInstallmentCount }} overdue installment{{ $arrearsInstallmentCount === 1 ? '' : 's' }}</div>
    </div>
    <div class="rounded-lg border border-slate-300 bg-slate-900 p-4 text-white">
        <div class="text-xs font-semibold uppercase tracking-wide text-slate-300">Total to collect</div>
        <div class="mt-2 text-xl font-semibold">UGX {{ number_format($totalAmount, 2) }}</div>
        <div class="text-sm text-slate-300">{{ $scheduledCount }} open installment{{ $scheduledCount === 1 ? '' : 's' }}</div>
    </div>
</div>

<form class="mb-4 flex flex-wrap items-end gap-3 rounded border bg-white p-4">
    @unless($isCashier)
        <label class="text-sm font-medium text-slate-700">Repayment date
            <input name="date" value="{{ $date->toDateString() }}" type="date" class="mt-1 rounded border-slate-300">
        </label>
    @endunless
    @if($canFilterBranch)
        <label class="text-sm font-medium text-slate-700">Branch
            <select name="branch_id" class="mt-1 rounded border-slate-300">
                <option value="">All branches</option>
                @foreach($branches as $branch)
                    <option value="{{ $branch->id }}" @selected((int) $selectedBranchId === (int) $branch->id)>{{ $branch->name }}</option>
                @endforeach
            </select>
        </label>
    @endif
    @unless($isCashier)
        <button class="rounded bg-slate-900 px-4 py-2 text-sm font-semibold text-white">View Date</button>
    @endunless
    <div class="ml-auto text-sm text-slate-500"><span class="font-semibold text-slate-950">{{ $loans->total() }}</span> loan{{ $loans->total() === 1 ? '' : 's' }} requiring collection</div>
</form>

<div class="overflow-x-auto rounded border bg-white">
    <table class="w-full min-w-[68rem] text-left text-sm">
        <thead class="bg-slate-50">
            <tr>
                <th class="p-3">Client</th>
                <th>Contact</th>
                <th>Loan</th>
                <th>Product</th>
                <th>Officer</th>
                <th class="text-right">Due today</th>
                <th class="text-right">Earlier arrears</th>
                <th class="pr-3 text-right">Amount to pay</th>
            </tr>
        </thead>
        <tbody>
            @forelse($loans as $loan)
                @php
                    $dueAmount = (float) ($loan->due_installments_amount ?? 0);
                    $arrearsLoanAmount = (float) ($loan->arrears_installments_amount ?? 0);
                    $openAmount = (float) ($loan->open_installments_amount ?? 0);
                @endphp
                <tr class="border-t align-top">
                    <td class="p-3 font-medium text-slate-950">{{ $loan->client->full_name }}</td>
                    <td>
                        <a href="tel:{{ $loan->client->phone }}" class="font-semibold text-brand-700">{{ $loan->client->phone }}</a>
                        @if($loan->client->alternative_phone)
                            <div><a href="tel:{{ $loan->client->alternative_phone }}" class="text-xs font-medium text-slate-500 hover:text-brand-700">{{ $loan->client->alternative_phone }}</a></div>
                        @endif
                    </td>
                    <td>
                        @if($isCashier)
                            <span class="font-semibold text-slate-950">{{ $loan->loan_number }}</span>
                        @else
                            <a class="font-semibold text-blue-700" href="{{ route('loans.show', $loan) }}">{{ $loan->loan_number }}</a>
                        @endif
                        <div class="mt-1 text-xs text-slate-500">{{ (int) $loan->open_installments_count }} open installment{{ (int) $loan->open_installments_count === 1 ? '' : 's' }}</div>
                    </td>
                    <td>{{ $loan->product->name }}</td>
                    <td>
                        @if($loan->officer)
                            <span class="font-semibold text-slate-950">{{ $loan->officer->name }}</span>
                            <div class="mt-1 text-xs text-slate-500">{{ $loan->officer->phone ?? $loan->officer->email }}</div>
                            @if((int) $loan->officer_id !== (int) auth()->id())
                                <a href="{{ route('chat.index', ['recipient_id' => $loan->officer_id, 'client_id' => $loan->client_id, 'loan_id' => $loan->id]) }}" class="mt-1 inline-flex text-xs font-semibold text-brand-700">Chat reminder</a>
                            @endif
                        @else
                            <span class="text-slate-500">Unassigned</span>
                        @endif
                    </td>
                    <td class="text-right">
                        <div class="font-semibold whitespace-nowrap text-violet-800">UGX {{ number_format($dueAmount, 2) }}</div>
                        <div class="text-xs text-slate-500">{{ (int) $loan->due_installments_count }} installment{{ (int) $loan->due_installments_count === 1 ? '' : 's' }}</div>
                    </td>
                    <td class="text-right">
                        <div class="font-semibold whitespace-nowrap {{ $arrearsLoanAmount > 0 ? 'text-red-700' : 'text-slate-500' }}">UGX {{ number_format($arrearsLoanAmount, 2) }}</div>
                        <div class="text-xs text-slate-500">{{ (int) $loan->arrears_installments_count }} overdue</div>
                    </td>
                    <td class="pr-3 text-right">
                        <div class="font-semibold whitespace-nowrap text-slate-950">UGX {{ number_format($openAmount, 2) }}</div>
                        @if($arrearsLoanAmount > 0)
                            <span class="mt-1 inline-flex rounded bg-red-50 px-2 py-1 text-xs font-semibold text-red-700">Includes arrears</span>
                        @else
                            <span class="mt-1 inline-flex rounded bg-slate-100 px-2 py-1 text-xs font-semibold text-slate-700">Current due only</span>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="8" class="p-6 text-center text-slate-500">No repayments or arrears are due for this date.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>

<div class="mt-4">{{ $loans->links() }}</div>
@endsection
