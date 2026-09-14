@extends('layouts.app')

@section('content')
<div class="mb-6 flex flex-wrap items-start justify-between gap-4">
    <div>
        <h1 class="text-2xl font-semibold text-slate-950">Settings</h1>
        <p class="text-sm text-slate-500">Core operating defaults for currency, numbering, approvals, repayments, SMS, and mobile money.</p>
        @if($selectedOrganization)
            <p class="mt-2 text-sm font-medium text-slate-700">Editing {{ $selectedOrganization->name }} settings.</p>
        @else
            <p class="mt-2 text-sm font-medium text-slate-700">Editing platform default settings.</p>
        @endif
    </div>
    @if(auth()->user()->hasRole('super-admin'))
        <div class="max-w-xl rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
            <div class="font-semibold">Super Admin scope</div>
            <div>{{ $isSuperAdminGlobal ? 'Global view edits platform defaults used only when a company has not set its own value. Switch into a company to edit that company directly.' : 'You are switched into this company.' }}</div>
            <a href="{{ route('organizations.index') }}" class="mt-2 inline-flex font-semibold text-amber-950 underline">Manage company scope</a>
        </div>
    @endif
</div>

@if($settings->isEmpty())
    <div class="rounded-lg border border-slate-200 bg-white p-6 text-sm text-slate-600">No settings are available yet.</div>
@else
<form method="POST" action="{{ route('settings.update') }}" class="grid gap-5" data-df-tabs>
    @csrf
    @method('PUT')
    <div class="df-tablist" role="tablist">
        @foreach ($settings as $group => $items)
            @php($tabId = str($group)->slug()->toString())
            <button type="button" class="df-tab" data-df-tab-target="{{ $tabId }}">{{ str($group)->headline() }}</button>
        @endforeach
    </div>
    @foreach ($settings as $group => $items)
        @php($tabId = str($group)->slug()->toString())
        <section class="rounded border border-slate-200 bg-white p-5 shadow-sm" data-df-tab-panel="{{ $tabId }}">
            <h2 class="text-lg font-semibold text-slate-950">{{ str($group)->headline() }}</h2>
            <div class="mt-4 grid gap-4 md:grid-cols-2">
                @foreach ($items as $setting)
                    <label class="block text-sm font-medium text-slate-700">{{ $setting->label ?? str($setting->key)->headline() }}
                        @if($setting->type === 'json')
                            <textarea name="settings[{{ $setting->key }}]" rows="8" class="mt-1 w-full rounded border-slate-300 font-mono text-xs">{{ old('settings.'.$setting->key, $setting->value) }}</textarea>
                        @elseif($setting->type === 'boolean')
                            @php($settingValue = old('settings.'.$setting->key, $setting->value))
                            <input type="hidden" name="settings[{{ $setting->key }}]" value="0">
                            <span class="mt-1 flex items-center gap-2 rounded border border-slate-200 px-3 py-2 font-normal text-slate-700">
                                <input type="checkbox" name="settings[{{ $setting->key }}]" value="1" class="rounded border-slate-300" @checked(filter_var($settingValue, FILTER_VALIDATE_BOOLEAN))>
                                <span>Enabled</span>
                            </span>
                        @else
                            <input name="settings[{{ $setting->key }}]" value="{{ old('settings.'.$setting->key, $setting->value) }}" @if($setting->type === 'money') type="number" min="0" step="100" @elseif($setting->type === 'number') type="number" min="0" step="0.0001" @endif class="mt-1 w-full rounded border-slate-300">
                        @endif
                        @if($setting->key === 'loan_application_fee_ranges')
                            <span class="mt-2 block text-xs font-normal text-slate-600">Mix fixed and percentage ranges. Omit fee_type for a fixed UGX amount. For 1% of the requested amount, use fee_type: "percentage" and fee: 0.01. A null max means no upper limit.</span>
                            <code class="mt-1 block whitespace-pre-wrap text-xs">[{"min":100,"max":500000,"fee":10000},
{"min":500100,"max":950000,"fee":20000},
{"min":950100,"max":null,"fee_type":"percentage","fee":0.01}]</code>
                            <span class="mt-1 block text-xs font-normal text-slate-500">Percentage fees are rounded to the nearest UGX 100 (half rounds up) at disbursement. Company ranges override platform defaults; a special fee saved on the loan overrides these ranges.</span>
                        @endif
                        @if ($setting->description)
                            <span class="mt-1 block text-xs font-normal text-slate-500">{{ $setting->description }}</span>
                        @endif
                    </label>
                @endforeach
            </div>
        </section>
    @endforeach
    <div class="flex justify-end"><button class="df-action-primary">Save Settings</button></div>
</form>
@endif
@endsection
