@extends('layouts.app')

@section('content')
<div class="mb-6">
    <h1 class="text-2xl font-semibold text-slate-950">System Settings</h1>
    <p class="text-sm text-slate-500">Core operating defaults for currency, numbering, approvals, repayments, SMS, and mobile money.</p>
</div>

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
                        <input name="settings[{{ $setting->key }}]" value="{{ old('settings.'.$setting->key, $setting->value) }}" class="mt-1 w-full rounded border-slate-300">
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
@endsection
