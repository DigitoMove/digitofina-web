@php
    $preview = $preview ?? false;
    $downloadRoute = auth()->user()->isMember() ? route('member.statements') : route('clients.statements', $client);
    $statementQuery = array_filter(['account' => $statement['key'] ?? null, 'date_from' => request('date_from'), 'date_to' => request('date_to')]);
    $statementRows = collect($statement['rows'] ?? []);
    $statementPage = new \Illuminate\Pagination\LengthAwarePaginator($statementRows->forPage(max(1, request()->integer('page', 1)), 25)->values(), $statementRows->count(), 25, max(1, request()->integer('page', 1)), ['path' => $statementAction, 'query' => request()->except('page')]);
    $displayRows = $preview ? $statementRows->take(8) : $statementPage;
    $company = $statement['organization'] ?? $client->organization;
    $logoPath = $company?->logo ?: $company?->logo_path;
    $companyLogo = $logoPath ? app(\App\Services\ReportDocumentFormatter::class)->logoDataUri($company) : null;
@endphp
<section id="account-statement" class="df-panel min-w-0 overflow-hidden">
    <header class="flex flex-wrap items-start justify-between gap-5 border-b border-slate-200 p-5">
        <div class="flex min-w-0 items-center gap-4">
            @if($companyLogo)<img src="{{ $companyLogo }}" alt="{{ $company->name }} logo" class="h-16 w-20 shrink-0 object-contain">@endif
            <div class="min-w-0">
                <p class="text-xs font-semibold uppercase tracking-wider text-brand-700">{{ $company?->name ?? config('app.name') }}</p>
                <h2 class="mt-1 text-xl font-semibold text-slate-950">Accounts & statements</h2>
                <p class="mt-1 text-sm text-slate-500">{{ $client->full_name }}</p>
            </div>
        </div>
        @if($statement)
            <div class="df-compact-grid grid w-full grid-cols-3 gap-2 sm:w-auto" aria-label="Download statement">
                @foreach(['csv' => 'CSV', 'xlsx' => 'Excel', 'pdf' => 'PDF'] as $format => $label)
                    <a class="{{ $format === 'pdf' ? 'df-action-primary' : 'df-action-secondary' }}" href="{{ $downloadRoute.'?'.http_build_query($statementQuery + ['format' => $format]) }}">{{ $label }}</a>
                @endforeach
            </div>
        @endif
    </header>
    @if($statementAccounts->isNotEmpty())
        <form method="GET" action="{{ $statementAction }}#account-statement" class="grid items-end gap-3 border-b border-slate-200 bg-slate-50 p-5 sm:grid-cols-2 2xl:grid-cols-[minmax(0,2fr)_1fr_1fr_auto]">
            <label class="grid min-w-0 gap-1 text-xs font-semibold text-slate-600">Account
                <select name="account" class="w-full min-w-0 text-sm">@foreach($statementAccounts as $key => $label)<option value="{{ $key }}" @selected(($statement['key'] ?? null) === $key)>{{ $label }}</option>@endforeach</select>
            </label>
            <label class="grid min-w-0 gap-1 text-xs font-semibold text-slate-600">From<input class="w-full min-w-0 text-sm" type="date" name="date_from" value="{{ request('date_from') }}"></label>
            <label class="grid min-w-0 gap-1 text-xs font-semibold text-slate-600">To<input class="w-full min-w-0 text-sm" type="date" name="date_to" value="{{ request('date_to') }}"></label>
            <button class="df-action-secondary">View statement</button>
        </form>
    @endif
    @if($statement)
        <dl class="grid gap-4 p-5 text-sm sm:grid-cols-2 2xl:grid-cols-3">
            @foreach($statement['metadata'] as $label => $value)
                <div class="min-w-0"><dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ $label }}</dt><dd class="mt-1 break-words font-semibold text-slate-900">{{ $value }}</dd></div>
            @endforeach
        </dl>
        <div class="border-y border-slate-200 bg-slate-50 px-5 py-3">
            <h3 class="font-semibold text-slate-900">{{ $statement['title'] }}</h3>
            <p class="mt-1 text-xs leading-relaxed text-slate-500">{{ $statement['note'] }}</p>
        </div>
        <div>
            <table class="w-full text-left text-sm">
                <thead class="bg-slate-50"><tr>
                    @if($statement['opening'] !== null)
                        <th>Date & type</th><th>Description</th><th>Money in / out</th><th>Balance</th>
                    @else
                        <th>Date & reference</th><th>Payment</th><th>Principal / interest</th><th>Fees / penalties</th>
                    @endif
                </tr></thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse($displayRows as $row)
                        <tr>
                            @if($statement['opening'] !== null)
                                <td data-label="Date & type"><div><p class="font-medium">{{ $row['date']?->format('d M Y') ?? '—' }}</p><p class="mt-1 text-xs text-slate-500">{{ $row['transaction_type'] }}</p></div></td>
                                <td data-label="Description">{{ $row['description'] }}</td>
                                <td data-label="Money in / out" class="text-right tabular-nums"><div>
                                    @if($row['credit'] !== null)<p class="font-semibold text-emerald-700">+{{ number_format($row['credit'], 2) }}<span class="block text-xs font-normal">Credit</span></p>@endif
                                    @if($row['debit'] !== null)<p class="font-semibold text-rose-700">−{{ number_format($row['debit'], 2) }}<span class="block text-xs font-normal">Debit</span></p>@endif
                                    @if($row['credit'] === null && $row['debit'] === null) — @endif
                                </div></td>
                                <td data-label="Balance" class="text-right font-semibold tabular-nums">{{ number_format($row['balance'], 2) }}</td>
                            @else
                                <td data-label="Date & reference"><div><p class="font-medium">{{ $row['date']->format('d M Y') }}</p><p class="mt-1 text-xs text-slate-500">{{ $row['description'] }}</p></div></td>
                                <td data-label="Payment" class="text-right font-semibold tabular-nums">{{ number_format($row['amount'], 2) }}</td>
                                <td data-label="Principal / interest" class="text-right tabular-nums"><div><p>{{ number_format($row['principal_paid'], 2) }} <span class="text-xs text-slate-500">principal</span></p><p class="mt-1">{{ number_format($row['interest_paid'], 2) }} <span class="text-xs text-slate-500">interest</span></p></div></td>
                                <td data-label="Fees / penalties" class="text-right tabular-nums"><div><p>{{ number_format($row['fees_paid'], 2) }} <span class="text-xs text-slate-500">fees</span></p><p class="mt-1">{{ number_format($row['penalty_paid'], 2) }} <span class="text-xs text-slate-500">penalties</span></p></div></td>
                            @endif
                        </tr>
                    @empty
                        <tr><td colspan="4">No confirmed transactions in this period.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <footer class="grid gap-3 border-t border-slate-200 p-5 text-xs text-slate-500">
            @if($preview)
                <a class="font-semibold text-brand-700" href="{{ $downloadRoute.'?'.http_build_query($statementQuery) }}">Open full statement · {{ number_format($statement['count']) }} transactions →</a>
            @else
                {{ $statementPage->links() }}
            @endif
            <p>Downloads include all transactions in the selected period, across every page.</p>
            <p>{{ collect([$company?->address, $company?->phone, $company?->email, $company?->website])->filter()->implode(' · ') }}</p>
            <p>Generated {{ now()->format('d M Y H:i T') }}</p>
        </footer>
    @else
        <p class="p-6 text-sm text-slate-500">No accounts are available for statements yet.</p>
    @endif
</section>
