@extends('layouts.app')

@section('content')
<div class="mb-5 flex flex-wrap items-end justify-between gap-3">
    <div>
        <h1 class="df-title">Module Management</h1>
        <p class="df-muted">Control which business modules each organization can access.</p>
    </div>
</div>

<div class="grid gap-5 xl:grid-cols-[22rem_1fr]">
    <aside class="df-panel p-5">
        <form method="GET" action="{{ route('modules.index') }}" class="grid gap-3">
            <label class="text-sm font-semibold text-slate-700">Organization
                <select name="organization_id" class="mt-1 w-full rounded border-slate-300" onchange="this.form.submit()">
                    @foreach($organizations as $item)
                        <option value="{{ $item->id }}" @selected($organization->is($item))>{{ $item->name }}</option>
                    @endforeach
                </select>
            </label>
        </form>

        @if($organization->exists)
            <div class="mt-5 rounded-lg border border-slate-200 bg-slate-50 p-4 text-sm">
                <div class="font-semibold text-slate-900">{{ $organization->name }}</div>
                <div class="mt-1 text-slate-600">Package: {{ $organization->package?->name ?? 'Manual setup' }}</div>
                <div class="mt-1 text-slate-600">{{ count($enabledSlugs) }} module(s) enabled</div>
            </div>

            <form method="POST" action="{{ route('modules.package.assign', $organization) }}" class="mt-5 grid gap-3">
                @csrf
                <label class="text-sm font-semibold text-slate-700">Assign Package
                    <select name="package_id" class="mt-1 w-full rounded border-slate-300" required>
                        @foreach($packages as $package)
                            <option value="{{ $package->id }}" @selected($organization->package_id === $package->id)>{{ $package->name }}</option>
                        @endforeach
                    </select>
                </label>
                <button class="df-action-secondary">Apply Package</button>
            </form>
        @endif
    </aside>

    <section class="df-panel overflow-hidden">
        <div class="border-b border-slate-100 px-5 py-4">
            <h2 class="font-semibold text-slate-950">Available Modules</h2>
        </div>
        <div class="divide-y divide-slate-100">
            @foreach($modules as $module)
                @php
                    $setting = $module->getRelation('organizationSetting');
                    $enabled = (bool) $setting->is_enabled;
                    $dependencies = $module->dependencies->pluck('name')->join(', ');
                    $dependents = $module->dependents->pluck('name')->join(', ');
                @endphp
                <div class="grid gap-4 p-5 lg:grid-cols-[1fr_auto]">
                    <div>
                        <div class="flex flex-wrap items-center gap-2">
                            <h3 class="font-semibold text-slate-950">{{ $module->name }}</h3>
                            <span class="rounded-full px-2 py-0.5 text-xs font-semibold {{ $enabled ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-600' }}">{{ $enabled ? 'Enabled' : 'Disabled' }}</span>
                            @if($module->is_core)
                                <span class="rounded-full bg-brand-50 px-2 py-0.5 text-xs font-semibold text-brand-700">Core</span>
                            @endif
                        </div>
                        <p class="mt-1 text-sm text-slate-600">{{ $module->description }}</p>
                        @if($dependencies)
                            <p class="mt-2 text-xs text-slate-500">Depends on: {{ $dependencies }}</p>
                        @endif
                        @if($dependents)
                            <p class="mt-1 text-xs text-amber-700">Required by: {{ $dependents }}</p>
                        @endif
                    </div>
                    <form method="POST" action="{{ route('modules.toggle', [$organization, $module]) }}" class="grid min-w-52 gap-2">
                        @csrf
                        <input type="hidden" name="action" value="{{ $enabled ? 'disable' : 'enable' }}">
                        <input name="reason" class="rounded border-slate-300 text-sm" placeholder="Reason">
                        <button class="{{ $enabled ? 'rounded-lg bg-red-700 px-3 py-2 text-sm font-semibold text-white hover:bg-red-800' : 'df-action-primary' }}" @disabled($module->is_core && $enabled)>
                            {{ $enabled ? 'Disable' : 'Enable' }}
                        </button>
                    </form>
                </div>
            @endforeach
        </div>
    </section>
</div>
@endsection
