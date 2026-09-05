@props([
    'href' => null,
    'label',
    'icon' => 'view',
    'tone' => 'default',
    'type' => 'button',
])

@php
    $path = match ($icon) {
        'edit' => 'M4 20h4l10.5-10.5a2.8 2.8 0 0 0-4-4L4 16v4z M13.5 6.5l4 4',
        'delete' => 'M4 7h16 M9 7V4h6v3 M7 7l1 13h8l1-13 M10 11v5 M14 11v5',
        'login' => 'M10 5H6a2 2 0 0 0-2 2v10a2 2 0 0 0 2 2h4 M14 8l4 4-4 4 M18 12H9',
        'settings' => 'M12 8a4 4 0 1 0 0 8 4 4 0 0 0 0-8z M4 12h2 M18 12h2 M12 4v2 M12 18v2',
        'open' => 'M7 17 17 7 M9 7h8v8',
        default => 'M2.5 12s3.5-6 9.5-6 9.5 6 9.5 6-3.5 6-9.5 6S2.5 12 2.5 12z M12 9a3 3 0 1 0 0 6 3 3 0 0 0 0-6z',
    };
    $classes = $tone === 'danger'
        ? 'inline-flex size-9 shrink-0 items-center justify-center rounded-lg border border-red-200 bg-white text-red-700 shadow-sm hover:bg-red-50 focus:outline-none focus:ring-4 focus:ring-red-100'
        : 'inline-flex size-9 shrink-0 items-center justify-center rounded-lg border border-slate-200 bg-white text-slate-700 shadow-sm hover:border-brand-300 hover:bg-brand-50 hover:text-brand-900 focus:outline-none focus:ring-4 focus:ring-brand-100';
@endphp

@if($href)
    <a href="{{ $href }}" aria-label="{{ $label }}" title="{{ $label }}" {{ $attributes->class($classes) }}>
        <svg class="size-4" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" viewBox="0 0 24 24" aria-hidden="true"><path d="{{ $path }}" /></svg>
        <span class="sr-only">{{ $label }}</span>
    </a>
@else
    <button type="{{ $type }}" aria-label="{{ $label }}" title="{{ $label }}" {{ $attributes->class($classes) }}>
        <svg class="size-4" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" viewBox="0 0 24 24" aria-hidden="true"><path d="{{ $path }}" /></svg>
        <span class="sr-only">{{ $label }}</span>
    </button>
@endif
