@props([
    'label',
    'value',
    'icon' => 'grid',
    'href' => null,
    'tone' => 'neutral',   // neutral | brand | success | warning
    'hint' => null,
])

@php
    $tones = [
        'neutral' => 'bg-muted text-fg-muted',
        'brand'   => 'bg-brand-50 text-brand-700 dark:bg-brand-500/15 dark:text-brand-300',
        'success' => 'bg-success-soft text-success-fg',
        'warning' => 'bg-warning-soft text-warning-fg',
    ];

    $tag = $href ? 'a' : 'div';
@endphp

<{{ $tag }}
    @if ($href) href="{{ $href }}" @endif
    {{ $attributes->merge([
        'class' => 'group card p-4 flex flex-col gap-3 '
            . ($href ? 'transition duration-200 ease-out hover:border-brand-300 hover:shadow-md motion-safe:hover:-translate-y-0.5' : ''),
    ]) }}
>
    <div class="flex items-start justify-between gap-2">
        <span class="grid place-items-center w-9 h-9 rounded-xl {{ $tones[$tone] }}">
            <x-icon :name="$icon" class="w-[18px] h-[18px]" />
        </span>

        @if ($href)
            <x-icon
                name="chevron-left"
                class="w-4 h-4 text-fg-subtle opacity-0 transition group-hover:opacity-100 motion-safe:group-hover:-translate-x-0.5"
            />
        @endif
    </div>

    <div>
        <div class="text-2xl font-bold text-fg tnum leading-none">{{ $value }}</div>
        <div class="mt-1.5 text-xs font-medium text-fg-muted">{{ $label }}</div>
        @if ($hint)
            <div class="mt-1 text-[11px] text-fg-subtle">{{ $hint }}</div>
        @endif
    </div>
</{{ $tag }}>
