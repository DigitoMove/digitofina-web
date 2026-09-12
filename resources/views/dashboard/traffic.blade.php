@php
    $t = $traffic['totals'];
    $visits = (int) ($t['visits'] ?? 0);
    $peak = max(1, (int) $traffic['trend']->max('visits'));
    $points = $traffic['trend']->values()->map(fn ($row, $i) => round(20 + $i * 860 / max(1, $traffic['days'] - 1), 1).','.round(170 - $row['visits'] / $peak * 140, 1))->join(' ');
    $duration = static fn ($seconds) => (int) floor($seconds / 60).'m '.(int) ($seconds % 60).'s';
@endphp
<section class="mt-8 space-y-5" id="traffic-domains" aria-label="Website traffic analytics">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div><h2 class="text-xl font-semibold text-slate-950">Traffic & engagement</h2><p class="mt-1 text-sm text-slate-500">Live sites, custom domains, subdomains and demos in one view.</p></div>
        <form method="GET" action="{{ route('dashboard') }}#traffic-domains" class="flex w-full flex-wrap items-end gap-2 sm:w-auto">
            <label class="text-xs font-medium text-slate-600">Period<select name="traffic_days" class="mt-1 block rounded-lg border border-slate-300 bg-white p-2 text-sm">@foreach([7,30,90] as $days)<option value="{{ $days }}" @selected($traffic['days'] === $days)>Last {{ $days }} days</option>@endforeach</select></label>
            <label class="min-w-0 flex-1 text-xs font-medium text-slate-600 sm:flex-none">Hostname<select name="traffic_host" class="mt-1 block w-full max-w-64 rounded-lg border border-slate-300 bg-white p-2 text-sm"><option value="">All hosts</option>@foreach($traffic['known_hosts'] as $host)<option value="{{ $host }}" @selected($traffic['host'] === $host)>{{ $host }}</option>@endforeach</select></label>
            <label class="text-xs font-medium text-slate-600">Environment<select name="traffic_kind" class="mt-1 block rounded-lg border border-slate-300 bg-white p-2 text-sm">@foreach(['all'=>'All','live'=>'Live','demo'=>'Demo'] as $key=>$label)<option value="{{ $key }}" @selected($traffic['kind'] === $key)>{{ $label }}</option>@endforeach</select></label>
            <button class="df-action-primary">Apply</button>
        </form>
    </div>
    @unless($traffic['ready'])<div class="rounded-xl bg-amber-50 p-4 text-sm text-amber-900">Traffic monitoring will start after the database migration is deployed.</div>@endunless
    <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        @foreach([
            ['Visits', number_format($visits), 'Previous period: '.number_format($traffic['previous']), 'border-purple-500'],
            ['Page views', number_format($t['views'] ?? 0), number_format($t['visitors'] ?? 0).' distinct browser sessions', 'border-sky-500'],
            ['Avg. active duration', $duration((float) ($t['duration'] ?? 0)), 'Visible, recently active tabs', 'border-emerald-500'],
            ['Online visitors', number_format($traffic['online']), 'Activity within the last 5 minutes', 'border-amber-500'],
        ] as [$label, $value, $note, $color])
            <div class="rounded-xl border border-slate-200 border-t-4 {{ $color }} bg-white p-5 shadow-sm"><p class="text-sm font-medium text-slate-500">{{ $label }}</p><p class="mt-2 break-words text-3xl font-semibold tracking-tight text-slate-950">{{ $value }}</p><p class="mt-3 text-xs text-slate-500">{{ $note }}</p></div>
        @endforeach
    </div>
    <div class="grid gap-5 lg:grid-cols-3">
        <div class="min-w-0 rounded-xl border border-slate-200 bg-white p-5 shadow-sm lg:col-span-2">
            <div class="flex items-center justify-between gap-2"><h3 class="font-semibold text-slate-950">Daily visits</h3><span class="rounded-full bg-purple-50 px-3 py-1 text-xs font-medium text-purple-700">{{ $traffic['days'] }} days</span></div>
            <p class="mt-1 text-xs text-slate-500">Peak: {{ number_format($peak === 1 && !$visits ? 0 : $peak) }} visits / day</p>
            <svg viewBox="0 0 900 200" class="mt-4 w-full" role="img" aria-label="Daily traffic trend over {{ $traffic['days'] }} days">
                <defs><linearGradient id="traffic-fill" x1="0" x2="0" y1="0" y2="1"><stop offset="0%" stop-color="#7e22ce" stop-opacity=".2"/><stop offset="100%" stop-color="#7e22ce" stop-opacity="0"/></linearGradient></defs>
                @foreach([30,100,170] as $y)<line x1="20" y1="{{ $y }}" x2="880" y2="{{ $y }}" stroke="#e2e8f0" stroke-dasharray="4 4"/>@endforeach
                <polygon points="20,170 {{ $points }} 880,170" fill="url(#traffic-fill)"/>
                <polyline points="{{ $points }}" fill="none" stroke="#7e22ce" stroke-width="3" stroke-linejoin="round"/>
                @foreach($traffic['trend'] as $i=>$day)<circle cx="{{ 20 + $i * 860 / max(1, $traffic['days'] - 1) }}" cy="{{ 170 - $day['visits'] / $peak * 140 }}" r="3" fill="#7e22ce"><title>{{ $day['date'] }}: {{ $day['visits'] }} visits, {{ $day['views'] }} page views</title></circle>@endforeach
            </svg>
            <div class="flex justify-between text-xs text-slate-500"><span>{{ $traffic['trend']->first()['date'] }}</span><span>{{ $traffic['trend']->last()['date'] }}</span></div>
            @if(!$visits)<p class="mt-3 text-sm text-slate-500">No visits recorded for these filters yet.</p>@endif
            <details class="mt-4 text-sm"><summary class="cursor-pointer font-medium text-purple-700">View daily figures</summary><div class="mt-3 max-h-64 overflow-auto"><table class="w-full text-left text-sm"><thead><tr><th class="p-2">Date</th><th>Visits</th><th>Page views</th></tr></thead><tbody>@foreach($traffic['trend'] as $day)<tr class="border-t border-slate-100"><td class="p-2">{{ $day['date'] }}</td><td>{{ $day['visits'] }}</td><td>{{ $day['views'] }}</td></tr>@endforeach</tbody></table></div></details>
        </div>
        <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
            <h3 class="font-semibold text-slate-950">Audience & environments</h3>
            @foreach(['guest'=>'Guests', 'staff'=>'Staff', 'client'=>'Clients'] as $key=>$label)
                @php($count = (int) ($traffic['breakdown'][$key] ?? 0))
                <div class="mt-5"><div class="mb-2 flex justify-between text-sm"><span>{{ $label }}</span><span class="font-semibold">{{ number_format($count) }}</span></div><div class="h-2 overflow-hidden rounded-full bg-slate-100"><div class="h-full rounded-full bg-sky-500" style="width: {{ $count / max(1, $visits) * 100 }}%"></div></div></div>
            @endforeach
            <div class="mt-6 grid grid-cols-2 gap-3 border-t border-slate-100 pt-5"><div class="rounded-xl bg-purple-50 p-3"><p class="text-xs text-purple-700">Demo visits</p><p class="mt-1 text-xl font-semibold text-purple-900">{{ number_format($t['demos'] ?? 0) }}</p></div><div class="rounded-xl bg-emerald-50 p-3"><p class="text-xs text-emerald-700">Live visits</p><p class="mt-1 text-xl font-semibold text-emerald-900">{{ number_format($visits - ($t['demos'] ?? 0)) }}</p></div></div>
        </div>
    </div>
    <div class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
        <div class="border-b border-slate-100 p-5"><h3 class="font-semibold text-slate-950">Domain performance</h3><p class="mt-1 text-xs text-slate-500">{{ $t['hosts'] ?? 0 }} hosts with traffic · exact hostname and configured parent domain together.</p></div>
        <div class="overflow-x-auto"><table class="w-full text-left text-sm"><thead class="bg-slate-50 text-xs uppercase text-slate-500"><tr><th class="p-4">Host / domain</th><th class="p-4">Organization</th><th class="p-4">Type</th><th class="p-4 text-right">Visits</th><th class="p-4 text-right">Views</th><th class="p-4 text-right">Avg. duration</th></tr></thead><tbody>
            @forelse($traffic['hosts'] ?? [] as $row)
                <tr class="border-t border-slate-100 hover:bg-slate-50"><td class="p-4"><a class="break-all font-semibold text-purple-700" href="{{ route('dashboard', ['traffic_days'=>$traffic['days'], 'traffic_host'=>$row->host, 'traffic_kind'=>$traffic['kind']]) }}#traffic-domains">{{ $row->host }}</a><p class="mt-1 text-xs text-slate-500">Domain: {{ $row->domain }} @if($row->subdomain) · Subdomain: {{ $row->subdomain }} @endif</p></td><td class="p-4">{{ $traffic['organizations'][$row->organization_id] ?? 'Public / central' }}</td><td class="p-4"><span class="rounded-full bg-slate-100 px-2 py-1 text-xs">{{ $row->is_demo ? 'Demo' : 'Live' }}</span></td><td class="p-4 text-right font-semibold">{{ number_format($row->visits) }}</td><td class="p-4 text-right">{{ number_format($row->views) }}</td><td class="whitespace-nowrap p-4 text-right">{{ $duration((float) $row->duration) }}</td></tr>
            @empty<tr><td colspan="6" class="p-8 text-center text-slate-500">No traffic recorded yet. Known domains are available in the hostname filter.</td></tr>@endforelse
        </tbody></table></div>
        @if($traffic['hosts'])<div class="p-4">{{ $traffic['hosts']->links() }}</div>@endif
    </div>
    <p class="text-xs leading-5 text-slate-500">Traffic covers pages served by this installation and its shared database. A visit restarts after 30 minutes of inactivity or a change of user type or organization. Average duration includes zero-duration visits; visible tabs report activity every 30 seconds and pause after two minutes without interaction. Known bots and API requests are excluded. Domain groups use configured roots; unconfigured custom hosts retain their full hostname.</p>
</section>
