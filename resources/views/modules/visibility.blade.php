@extends('layouts.app')

@section('content')
<div class="mb-5">
    <h1 class="df-title">Module Visibility</h1>
    <p class="df-muted">{{ $organization->name }} can currently access {{ count($enabledSlugs) }} module(s).</p>
</div>

<section class="df-panel overflow-hidden">
    <div class="divide-y divide-slate-100">
        @foreach($modules as $module)
            @php($enabled = in_array($module->slug, $enabledSlugs, true))
            <div class="flex flex-wrap items-center justify-between gap-3 p-5">
                <div>
                    <h2 class="font-semibold text-slate-950">{{ $module->name }}</h2>
                    <p class="mt-1 text-sm text-slate-600">{{ $module->description }}</p>
                </div>
                <span class="rounded-full px-3 py-1 text-sm font-semibold {{ $enabled ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-600' }}">{{ $enabled ? 'Enabled' : 'Disabled' }}</span>
            </div>
        @endforeach
    </div>
</section>
@endsection
