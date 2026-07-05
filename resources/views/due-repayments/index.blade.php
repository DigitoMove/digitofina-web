@extends('layouts.app')

@section('content')
<div class="mb-5 flex flex-wrap items-center justify-between gap-3">
    <div>
        <h1 class="text-2xl font-semibold text-slate-950">{{ $isCashier ? "Today's Collection Calls" : 'Due Repayments' }}</h1>
        <p class="text-sm text-slate-500">Amounts due on {{ $date->format('d M Y') }}.</p>
    </div>
    <a href="{{ route('dashboard') }}" class="rounded border px-4 py-2 text-sm font-semibold">Back to Dashboard</a>
</div>

<div class="mb-4 grid gap-3 sm:grid-cols-2">
    <div class="rounded-lg border border-violet-200 bg-violet-50 p-4">
        <div class="text-xs font-semibold uppercase tracking-wide text-violet-700">Due on selected date</div>
        <div class="mt-2 text-xl font-semibold text-violet-950">UGX {{ number_format($todayAmount, 2) }}</div>
        <div class="text-sm text-violet-700">{{ $todayInstallmentCount }} installment{{ $todayInstallmentCount === 1 ? '' : 's' }}</div>
    </div>
    <div class="rounded-lg border border-slate-300 bg-slate-900 p-4 text-white">
        <div class="text-xs font-semibold uppercase tracking-wide text-slate-300">Total due today</div>
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
    <div class="ml-auto text-sm text-slate-500"><span class="font-semibold text-slate-950">{{ $demands->total() }}</span> client{{ $demands->total() === 1 ? '' : 's' }} requiring collection</div>
</form>

<div class="overflow-x-auto rounded border bg-white">
    <table class="w-full min-w-[68rem] text-left text-sm">
        <thead class="bg-slate-50">
            <tr>
                <th class="p-3">Client</th>
                <th>Contact</th>
                <th>Loan breakdown</th>
                <th>Officer</th>
                <th class="text-right">Due today</th>
                <th class="pr-3 text-right">Amount to pay</th>
            </tr>
        </thead>
        <tbody>
            @forelse($demands as $demand)
                @php
                    $client = $demand['client'];
                    $primaryLoan = $demand['primary_loan'];
                    $dueAmount = (float) $demand['due_today_amount'];
                    $openAmount = (float) $demand['total_amount'];
                    $loanCount = $demand['loans']->count();
                @endphp
                <tr class="border-t align-top">
                    <td class="p-3 font-medium text-slate-950">
                        {{ $client->full_name }}
                        <div class="mt-1 text-xs text-slate-500">{{ $loanCount }} loan{{ $loanCount === 1 ? '' : 's' }} included</div>
                    </td>
                    <td>
                        <a href="tel:{{ $client->phone }}" class="font-semibold text-brand-700">{{ $client->phone }}</a>
                        @if($client->alternative_phone)
                            <div><a href="tel:{{ $client->alternative_phone }}" class="text-xs font-medium text-slate-500 hover:text-brand-700">{{ $client->alternative_phone }}</a></div>
                        @endif
                    </td>
                    <td>
                        <div class="space-y-2">
                            @foreach($demand['loans'] as $loanDemand)
                                @php($loan = $loanDemand['loan'])
                                <div class="rounded bg-slate-50 p-2">
                                    @if($isCashier)
                                        <span class="font-semibold text-slate-950">{{ $loan->loan_number }}</span>
                                    @else
                                        <a class="font-semibold text-blue-700" href="{{ route('loans.show', $loan) }}">{{ $loan->loan_number }}</a>
                                    @endif
                                    <div class="mt-1 text-xs text-slate-500">
                                        {{ $loan->product->name }} ·
                                        Due UGX {{ number_format((float) $loanDemand['due_today_amount'], 2) }}
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </td>
                    <td>
                        @if($primaryLoan?->officer)
                            <span class="font-semibold text-slate-950">{{ $primaryLoan->officer->name }}</span>
                            <div class="mt-1 text-xs text-slate-500">{{ $primaryLoan->officer->phone ?? $primaryLoan->officer->email }}</div>
                            @if((int) $primaryLoan->officer_id !== (int) auth()->id())
                                <a href="{{ route('chat.index', ['recipient_id' => $primaryLoan->officer_id, 'client_id' => $client->id, 'loan_id' => $primaryLoan->id]) }}" class="mt-1 inline-flex text-xs font-semibold text-brand-700">Chat reminder</a>
                            @endif
                        @else
                            <span class="text-slate-500">Unassigned</span>
                        @endif
                    </td>
                    <td class="text-right">
                        <div class="font-semibold whitespace-nowrap text-violet-800">UGX {{ number_format($dueAmount, 2) }}</div>
                        <div class="text-xs text-slate-500">{{ (int) $demand['due_installments_count'] }} installment{{ (int) $demand['due_installments_count'] === 1 ? '' : 's' }}</div>
                    </td>
                    <td class="pr-3 text-right">
                        <div class="font-semibold whitespace-nowrap text-slate-950">UGX {{ number_format($openAmount, 2) }}</div>
                    </td>
                </tr>
            @empty
                <tr><td colspan="6" class="p-6 text-center text-slate-500">No clients have installments due on this date.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>

<div class="mt-4">{{ $demands->links() }}</div>
@endsection
