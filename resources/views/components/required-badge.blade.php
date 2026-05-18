@props(['label' => 'Required'])

<span {{ $attributes->merge(['class' => 'ml-1 rounded bg-red-50 px-1.5 py-0.5 text-[10px] font-semibold uppercase text-red-700']) }}>{{ $label }}</span>
